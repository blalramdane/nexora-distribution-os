<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\GrantsTestPermissions;

class OfflineTransactionFieldGateTest extends TestCase
{
    use RefreshDatabase;
    use GrantsTestPermissions;

    public function test_offline_sale_executes_transaction_once_and_retry_replays_authoritative_result(): void
    {
        [$organization, $user, $deviceUuid, $productId, $customerId, $unitId, $locationId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 10,
            'reserved_quantity_base' => 0,
            'average_cost' => 50,
            'updated_at' => now(),
        ]);

        $operationUuid = (string) Str::uuid();
        $idempotencyKey = 'offline-sale-' . $operationUuid;
        $payload = [
            'operation_uuid' => $operationUuid,
            'operation_type' => 'POST /sales',
            'schema_version' => 1,
            'idempotency_key' => $idempotencyKey,
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'body' => [
                    'customer_id' => $customerId,
                    'location_id' => $locationId,
                    'items' => [[
                        'product_id' => $productId,
                        'quantity' => 2,
                        'conversion_factor' => 1,
                        'unit_price' => 100,
                        'unit_id' => $unitId,
                    ]],
                    'paid_amount' => 50,
                ],
            ],
        ];

        $first = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(202)
            ->json();

        $second = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(200)
            ->json();

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('sales_invoice:' . $first['result']['id'], $first['result_reference']);
        $this->assertSame($first['operation_uuid'], $second['operation_uuid']);
        $this->assertSame($first['sync_operation_id'], $second['sync_operation_id']);
        $this->assertSame($first['result_reference'], $second['result_reference']);

        $this->assertSame('8.000000', $this->stock($organization->id, $productId, $locationId));
        $this->assertSame(1, DB::table('sales_invoices')
            ->where('organization_id', $organization->id)
            ->where('id', $first['result']['id'])
            ->count());
        $this->assertSame(1, DB::table('sync_operations')
            ->where('organization_id', $organization->id)
            ->where('operation_uuid', $operationUuid)
            ->count());
    }

    public function test_offline_sale_with_insufficient_stock_is_authoritatively_rejected_without_mutation(): void
    {
        [$organization, $user, $deviceUuid, $productId, $customerId, $unitId, $locationId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 1,
            'reserved_quantity_base' => 0,
            'average_cost' => 50,
            'updated_at' => now(),
        ]);

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'POST /sales',
            'schema_version' => 1,
            'idempotency_key' => 'offline-reject-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'body' => [
                    'customer_id' => $customerId,
                    'location_id' => $locationId,
                    'items' => [[
                        'product_id' => $productId,
                        'quantity' => 2,
                        'conversion_factor' => 1,
                        'unit_price' => 100,
                        'unit_id' => $unitId,
                    ]],
                    'paid_amount' => 0,
                ],
            ],
        ];

        $response = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJson([
                'status' => 'rejected',
                'rejection_code' => 'validation_error',
                'authoritative' => true,
            ]);

        $this->assertNotEmpty($response->json('errors.items'));
        $this->assertSame('1.000000', $this->stock($organization->id, $productId, $locationId));
        $this->assertSame(0, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());

        $this->assertDatabaseHas('sync_operations', [
            'organization_id' => $organization->id,
            'operation_uuid' => $payload['operation_uuid'],
            'status' => 'rejected',
            'rejection_code' => 'validation_error',
        ]);
    }

    private function stock(string $organizationId, string $productId, string $locationId): string
    {
        return number_format(
            (float) (DB::table('stock_balances')
                ->where('organization_id', $organizationId)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->value('quantity_base') ?? 0),
            6,
            '.',
            ''
        );
    }

    private function foundation(): array
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA Offline Field Gate',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Offline Field User',
            'email' => 'offline-field-' . $organization->id . '@nexora.test',
            'phone' => '01000000005',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $deviceUuid = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'device_uuid' => $deviceUuid,
            'name' => 'Registered Field Device',
            'platform' => 'web',
            'app_version' => 'test',
            'status' => 'active',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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
            'name_ar' => 'Offline Product',
            'default_cost' => 50,
            'default_piece_price' => 100,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'organization_id' => $organization->id,
            'code' => 'CUS-OFFLINE',
            'name' => 'Offline Customer',
            'normalized_name' => 'offline customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organization->id,
            'code' => 'FIELD-OFFLINE',
            'name' => 'Field Vehicle',
            'type' => 'vehicle',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organization, $user, $deviceUuid, $productId, $customerId, $unitId, $locationId];
    }
}
