<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class InventoryTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_stock_adjustment_rejects_foreign_product_and_location_without_mutation(): void
    {
        [$orgA, $userA] = $this->organizationUser('Inventory Tenant A');
        [$orgB] = $this->organizationUser('Inventory Tenant B');
        $unitA = $this->unit($orgA->id, 'UNIT-A');
        $unitB = $this->unit($orgB->id, 'UNIT-B');
        $productA = $this->product($orgA->id, $unitA, 'SKU-A');
        $productB = $this->product($orgB->id, $unitB, 'SKU-B');
        $locationA = $this->location($orgA->id, 'WH-A');
        $locationB = $this->location($orgB->id, 'WH-B');

        $base = ['quantity_delta' => 3, 'reason' => 'Tenant isolation test'];
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/inventory/adjust', [...$base, 'product_id' => $productB, 'location_id' => $locationA, 'idempotency_key' => 'foreign-product-'.Str::uuid()])
            ->assertNotFound();

        $this->postJson('/api/v1/inventory/adjust', [...$base, 'product_id' => $productA, 'location_id' => $locationB, 'idempotency_key' => 'foreign-location-'.Str::uuid()])
            ->assertNotFound();

        $this->getJson('/api/v1/inventory?location_id='.$locationB.'&product_id='.$productB)
            ->assertOk()->assertJsonPath('data', []);

        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('stock_balances')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('idempotency_keys')->where('organization_id', $orgA->id)->where('operation_type', 'stock.adjust')->count());
    }

    public function test_inventory_list_searches_product_and_location_fields_and_validates_pagination(): void
    {
        [$organization, $user] = $this->organizationUser('Inventory Search Tenant');
        $unitId = $this->unit($organization->id, 'PCS');
        $matchingProduct = $this->product($organization->id, $unitId, 'SKU-LAMP-01');
        $otherProduct = $this->product($organization->id, $unitId, 'SKU-FAN-02');
        $warehouseId = $this->location($organization->id, 'WH-DAMIETTA');

        $this->actingAs($user, 'sanctum');
        foreach ([[$matchingProduct, 'stock-lamp'], [$otherProduct, 'stock-fan']] as [$productId, $key]) {
            $this->postJson('/api/v1/inventory/adjust', [
                'product_id' => $productId,
                'location_id' => $warehouseId,
                'quantity_delta' => 3,
                'reason' => 'Inventory search fixture',
                'idempotency_key' => $key,
            ])->assertCreated();
        }

        $this->getJson('/api/v1/inventory?search=LAMP&per_page=25')
            ->assertOk()
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $matchingProduct);

        $this->getJson('/api/v1/inventory?search=DAMIETTA')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/inventory?stock_status=low&low_stock_threshold=3')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/inventory?stock_status=available&low_stock_threshold=3')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/inventory?stock_status=out')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/inventory?per_page=201')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/inventory?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_inventory_summary_is_not_limited_to_the_visible_page(): void
    {
        [$organization, $user] = $this->organizationUser('Inventory Summary Tenant');
        $unitId = $this->unit($organization->id, 'SUMMARY-PCS');
        $firstProduct = $this->product($organization->id, $unitId, 'SUMMARY-001');
        $secondProduct = $this->product($organization->id, $unitId, 'SUMMARY-002');
        $warehouseId = $this->location($organization->id, 'SUMMARY-WH');
        $this->actingAs($user, 'sanctum');

        foreach ([[$firstProduct, 3, 'summary-a'], [$secondProduct, 8, 'summary-b']] as [$productId, $quantity, $key]) {
            $this->postJson('/api/v1/inventory/adjust', [
                'product_id' => $productId, 'location_id' => $warehouseId,
                'quantity_delta' => $quantity, 'reason' => 'Summary fixture', 'idempotency_key' => $key,
            ])->assertCreated();
        }

        $balanceDiagnostics = DB::table('stock_balances')->where('organization_id', $organization->id)
            ->orderBy('quantity_base')->get(['product_id', 'location_id', 'quantity_base', 'reserved_quantity_base'])
            ->map(fn ($row) => ['quantity' => (float) $row->quantity_base, 'reserved' => (float) $row->reserved_quantity_base])->all();

        $this->getJson('/api/v1/inventory?per_page=1&low_stock_threshold=5')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data');

        $summary = $this->getJson('/api/v1/inventory/summary?low_stock_threshold=5')->assertOk()->json();
        $this->assertSame(2, $summary['balance_rows'], json_encode($summary));
        $this->assertSame(2, $summary['active_products'], json_encode($summary));
        $this->assertSame(11, $summary['quantity_units'], json_encode($summary));
        $this->assertSame(1, $summary['low_balance_rows'], json_encode(['summary'=>$summary,'balances'=>$balanceDiagnostics]));
        $this->assertSame(0, $summary['zero_balance_rows'], json_encode($summary));
    }

    public function test_stock_transfer_is_atomic_idempotent_and_keeps_quantity_and_source_layers(): void
    {
        [$organization, $user] = $this->organizationUser('Inventory Transfer Tenant');
        $unitId = $this->unit($organization->id, 'TRANSFER-PCS');
        $productId = $this->product($organization->id, $unitId, 'TRANSFER-SKU');
        $sourceId = $this->location($organization->id, 'TRANSFER-FROM');
        $destinationId = $this->location($organization->id, 'TRANSFER-TO');
        $headers = ['Authorization' => 'Bearer '.$user->createToken('inventory-transfer-test')->plainTextToken];

        $this->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
            'product_id' => $productId, 'location_id' => $sourceId,
            'quantity_delta' => 10, 'reason' => 'Opening transfer test stock', 'idempotency_key' => 'transfer-seed-'.Str::uuid(),
        ])->assertCreated();

        $payload = [
            'from_location_id' => $sourceId, 'to_location_id' => $destinationId,
            'reason' => 'إعادة توزيع مخزون', 'idempotency_key' => 'transfer-once-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'quantity_base' => 4]],
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/inventory/transfers', $payload)
            ->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('items.0.quantity_base', 4)->json();
        $this->withHeaders($headers)->postJson('/api/v1/inventory/transfers', $payload)
            ->assertCreated()->assertJsonPath('id', $first['id'])->assertJsonPath('replayed', true);

        $source = DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $sourceId)->first();
        $destination = DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $destinationId)->first();
        $this->assertSame('6.000000', number_format((float) $source->quantity_base, 6, '.', ''));
        $this->assertSame('4.000000', number_format((float) $destination->quantity_base, 6, '.', ''));
        $this->assertSame('10.000000', number_format((float) $source->quantity_base + (float) $destination->quantity_base, 6, '.', ''));
        $this->assertSame(2, DB::table('stock_movements')->where('organization_id', $organization->id)->where('source_document_type', 'stock_transfer')->where('source_document_id', $first['id'])->count());
        $this->assertSame(1, DB::table('stock_transfer_items')->where('organization_id', $organization->id)->where('stock_transfer_id', $first['id'])->count());

        $this->withHeaders($headers)->getJson('/api/v1/inventory/movements?search=TRANSFER-SKU&per_page=50')
            ->assertOk()->assertJsonPath('total', 3);

        $this->withHeaders($headers)->postJson('/api/v1/inventory/transfers', [
            ...$payload, 'idempotency_key' => 'transfer-insufficient-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'quantity_base' => 100]],
        ])->assertUnprocessable();
        $this->assertSame('6.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $sourceId)->value('quantity_base'), 6, '.', ''));
    }

    private function organizationUser(string $name): array
    {
        $org = Organization::query()->create([
            'name' => $name, 'default_currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $org->id, 'name' => $name.' Manager',
            'email' => Str::uuid().'@nexora.test', 'phone' => '01000000000',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        return [$org, $user];
    }

    private function unit(string $orgId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => $code,
            'name_ar' => $code, 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function product(string $orgId, string $unitId, string $sku): string
    {
        $id = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $id, 'organization_id' => $orgId, 'base_unit_id' => $unitId,
            'sku' => $sku, 'name_ar' => $sku, 'default_cost' => 1,
            'default_piece_price' => 2, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function location(string $orgId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => $code,
            'name' => $code, 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
