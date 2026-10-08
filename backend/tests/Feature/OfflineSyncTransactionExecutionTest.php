<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncTransactionExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_sale_is_posted_once_and_retry_replays_without_duplicate_business_mutation(): void
    {
        [$organization, $user, $deviceUuid, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 10,
            'reserved_quantity_base' => 0,
            'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $operationUuid = (string) Str::uuid();
        $idempotencyKey = 'offline-sale-' . Str::uuid();

        $payload = [
            'operation_uuid' => $operationUuid,
            'operation_type' => 'POST /sales',
            'schema_version' => 1,
            'idempotency_key' => $idempotencyKey,
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'path' => '/sales',
                'body' => [
                    'customer_id' => $customerId,
                    'location_id' => $locationId,
                    'items' => [[
                        'product_id' => $productId,
                        'quantity' => 1,
                        'conversion_factor' => 1,
                        'unit_price' => 100,
                        'unit_id' => $unitId,
                    ]],
                    'paid_amount' => 30,
                ],
            ],
        ];

        $first = $this->actingAs($user)
            ->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload);

        $first->assertStatus(202)
            ->assertJson([
                'status' => 'accepted',
                'operation_uuid' => $operationUuid,
                'authoritative' => true,
            ]);

        $invoiceId = $first->json('result.id');

        $this->assertNotEmpty($invoiceId);
        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoiceId,
            'organization_id' => $organization->id,
            'customer_id' => $customerId,
            'idempotency_key' => $idempotencyKey,
            'status' => 'posted',
        ]);

        $this->assertSame('9.000000', number_format((float) DB::table('stock_balances')
            ->where('organization_id', $organization->id)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->value('quantity_base'), 6, '.', ''));

        $second = $this->actingAs($user)
            ->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload);

        $second->assertStatus(200)
            ->assertJson([
                'status' => 'accepted',
                'operation_uuid' => $operationUuid,
                'sync_operation_id' => $first->json('sync_operation_id'),
            ]);

        $this->assertSame(1, DB::table('sales_invoices')
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());

        $this->assertSame('9.000000', number_format((float) DB::table('stock_balances')
            ->where('organization_id', $organization->id)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->value('quantity_base'), 6, '.', ''));

        $this->assertSame(1, DB::table('sync_operations')
            ->where('organization_id', $organization->id)
            ->where('operation_uuid', $operationUuid)
            ->count());
    }

    public function test_failed_offline_transaction_is_authoritatively_rejected_and_does_not_mutate_stock(): void
    {
        [$organization, $user, $deviceUuid, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 1,
            'reserved_quantity_base' => 0,
            'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $operationUuid = (string) Str::uuid();
        $idempotencyKey = 'offline-invalid-sale-' . Str::uuid();

        $payload = [
            'operation_uuid' => $operationUuid,
            'operation_type' => 'POST /sales',
            'schema_version' => 1,
            'idempotency_key' => $idempotencyKey,
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'path' => '/sales',
                'body' => [
                    'customer_id' => $customerId,
                    'location_id' => $locationId,
                    'items' => [[
                        'product_id' => $productId,
                        'quantity' => 5,
                        'conversion_factor' => 1,
                        'unit_price' => 100,
                        'unit_id' => $unitId,
                    ]],
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'rejected',
                'operation_uuid' => $operationUuid,
                'authoritative' => true,
            ]);

        $this->assertDatabaseHas('sync_operations', [
            'organization_id' => $organization->id,
            'operation_uuid' => $operationUuid,
            'status' => 'rejected',
            'rejection_code' => 'validation_error',
        ]);

        $this->assertSame(0, DB::table('sales_invoices')
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());

        $this->assertSame('1.000000', number_format((float) DB::table('stock_balances')
            ->where('organization_id', $organization->id)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->value('quantity_base'), 6, '.', ''));
    }

    private function foundation(): array
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA Offline Transaction Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Offline Transaction User',
            'email' => 'offline-transaction-' . $organization->id . '@nexora.test',
            'phone' => '01000000004',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId,
            'organization_id' => $organization->id,
            'code' => 'PCS',
            'name_ar' => 'قطعة',
            'precision' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId,
            'organization_id' => $organization->id,
            'base_unit_id' => $unitId,
            'sku' => 'OFFLINE-' . $organization->id,
            'name_ar' => 'Offline Test Product',
            'default_cost' => 40,
            'default_piece_price' => 100,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'organization_id' => $organization->id,
            'code' => 'OFF-CUS-01',
            'name' => 'Offline Test Customer',
            'normalized_name' => 'offline test customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organization->id,
            'code' => 'OFF-WH-01',
            'name' => 'Offline Test Warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceUuid = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'device_uuid' => $deviceUuid,
            'name' => 'Offline Test Device',
            'platform' => 'web',
            'app_version' => 'test',
            'status' => 'active',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organization, $user, $deviceUuid, $unitId, $productId, $customerId, $locationId];
    }
}
