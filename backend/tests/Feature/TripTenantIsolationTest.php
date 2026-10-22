<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class TripTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_trip_cannot_be_created_using_another_organizations_vehicle(): void
    {
        $organizationA = $this->organization('Trip Owner A');
        $userA = User::query()->create([
            'organization_id' => $organizationA->id,
            'name' => 'Trip Manager A',
            'email' => 'trip-a-'.Str::uuid().'@nexora.test',
            'phone' => '01000000011',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($userA);

        $organizationB = $this->organization('Trip Owner B');
        $userB = User::query()->create([
            'organization_id' => $organizationB->id,
            'name' => 'Trip Manager B',
            'email' => 'trip-b-'.Str::uuid().'@nexora.test',
            'phone' => '01000000012',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organizationB->id,
            'code' => 'FOREIGN-TRIP-WH',
            'name' => 'Foreign warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organizationB->id,
            'location_id' => $locationId,
            'code' => 'FOREIGN-TRIP-VAN',
            'plate_number' => 'FOREIGN-TRIP',
            'name' => 'Foreign vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/trips', [
                'vehicle_id' => $vehicleId,
                'rep_user_id' => $userB->id,
                'origin_location_id' => $locationId,
                'trip_date' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id']);

        $this->assertDatabaseCount('trips', 0);
    }

    public function test_trip_lists_and_subroutes_do_not_expose_another_tenant_trip(): void
    {
        $organizationA = $this->organization('Trip Read Owner A');
        $userA = User::query()->create([
            'organization_id' => $organizationA->id, 'name' => 'Trip Read User A',
            'email' => 'trip-read-a-'.Str::uuid().'@nexora.test', 'phone' => '01000000021',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($userA);

        $organizationB = $this->organization('Trip Read Owner B');
        $userB = User::query()->create([
            'organization_id' => $organizationB->id, 'name' => 'Trip Read User B',
            'email' => 'trip-read-b-'.Str::uuid().'@nexora.test', 'phone' => '01000000022',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($userB);

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $organizationB->id,
            'code' => 'FOREIGN-READ-WH', 'name' => 'Foreign read warehouse',
            'type' => 'warehouse', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $vehicleLocationId, 'organization_id' => $organizationB->id,
            'code' => 'FOREIGN-READ-VAN-STOCK', 'name' => 'Foreign read vehicle stock',
            'type' => 'vehicle', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organizationB->id,
            'location_id' => $vehicleLocationId, 'code' => 'FOREIGN-READ-VAN',
            'plate_number' => 'FOREIGN-READ', 'name' => 'Foreign read vehicle',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $organizationA->id,
            'code' => 'TRIP-READ-CUS', 'name' => 'Trip read customer',
            'normalized_name' => 'trip read customer', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $trip = $this->actingAs($userB, 'sanctum')
            ->postJson('/api/v1/trips', [
                'vehicle_id' => $vehicleId, 'rep_user_id' => $userB->id,
                'origin_location_id' => $locationId, 'trip_date' => now()->toDateString(),
            ])->assertCreated()->json();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/trips')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/vehicles')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/trips/'.$trip['id'].'/route')->assertNotFound();
        $this->getJson('/api/v1/trips/'.$trip['id'].'/stock')->assertNotFound();
        $this->postJson('/api/v1/trips/'.$trip['id'].'/customers', ['customer_id' => $customerId])->assertNotFound();
        $this->postJson('/api/v1/trips/'.$trip['id'].'/route/optimize', [])->assertNotFound();
        $this->postJson('/api/v1/trips/'.$trip['id'].'/route/reorder', ['customer_ids' => [$customerId]])->assertNotFound();

        $this->postJson('/api/v1/trip-loads', [
            'trip_id' => $trip['id'], 'from_location_id' => $locationId,
            'idempotency_key' => 'foreign-trip-load-'.Str::uuid(),
            'items' => [['product_id' => $customerId, 'quantity_base' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['trip_id']);

        $this->postJson('/api/v1/trip-settlements', [
            'trip_id' => $trip['id'], 'actual_cash' => 0, 'idempotency_key' => 'foreign-settle-'.$trip['id'],
            'closing_items' => [['product_id' => $customerId, 'quantity_base' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['trip_id']);

        $this->assertSame(0, DB::table('trip_loads')->where('organization_id', $organizationA->id)->count());
        $this->assertSame(0, DB::table('trip_settlements')->where('organization_id', $organizationA->id)->count());
    }

    public function test_completed_trip_cannot_be_settled_twice(): void
    {
        $organization = $this->organization('Settlement Replay Tenant');
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Settlement Replay Manager',
            'email' => 'settlement-replay-'.Str::uuid().'@nexora.test', 'phone' => '01000000031',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $warehouseId = (string) Str::ulid();
        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            ['id' => $warehouseId, 'organization_id' => $organization->id, 'code' => 'SETTLE-WH', 'name' => 'Settlement warehouse', 'type' => 'warehouse', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $vehicleLocationId, 'organization_id' => $organization->id, 'code' => 'SETTLE-VAN-STOCK', 'name' => 'Settlement vehicle stock', 'type' => 'vehicle', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $vehicleLocationId,
            'code' => 'SETTLE-VAN', 'plate_number' => 'SETTLE-1', 'name' => 'Settlement vehicle',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId, 'organization_id' => $organization->id, 'code' => 'SETTLE-PCS',
            'name_ar' => 'قطعة', 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $unitId,
            'sku' => 'SETTLE-SKU', 'name_ar' => 'Settlement product', 'default_cost' => 1,
            'default_piece_price' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignOrganization = $this->organization('Foreign Settlement Tenant');
        $foreignUnitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $foreignUnitId, 'organization_id' => $foreignOrganization->id, 'code' => 'FOREIGN-SETTLE-PCS',
            'name_ar' => 'Foreign piece', 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignProductId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $foreignProductId, 'organization_id' => $foreignOrganization->id, 'base_unit_id' => $foreignUnitId,
            'sku' => 'FOREIGN-SETTLE-SKU', 'name_ar' => 'Foreign settlement product', 'default_cost' => 1,
            'default_piece_price' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $trip = $this->actingAs($user, 'sanctum')->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId, 'rep_user_id' => $user->id,
            'origin_location_id' => $warehouseId, 'trip_date' => now()->toDateString(),
        ])->assertCreated()->json();

        $settlementPayload = [
            'trip_id' => $trip['id'], 'actual_cash' => 0,
            'idempotency_key' => 'settlement-replay-'.$trip['id'],
            'closing_items' => [['product_id' => $productId, 'quantity_base' => 0]],
        ];

        $this->postJson('/api/v1/trip-settlements', [
            'trip_id' => $trip['id'], 'actual_cash' => 0,
            'idempotency_key' => 'foreign-product-settlement-'.$trip['id'],
            'closing_items' => [['product_id' => $foreignProductId, 'quantity_base' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['closing_items']);
        $this->assertSame(0, DB::table('trip_settlements')->where('organization_id', $organization->id)->count());

        $this->postJson('/api/v1/trip-settlements', [
            'trip_id' => $trip['id'], 'actual_cash' => 0,
            'idempotency_key' => 'duplicate-line-settlement-'.$trip['id'],
            'closing_items' => [
                ['product_id' => $productId, 'quantity_base' => 0],
                ['product_id' => $productId, 'quantity_base' => 1],
            ],
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('trip_settlements')->where('organization_id', $organization->id)->count());

        $this->postJson('/api/v1/trip-settlements', $settlementPayload)
            ->assertCreated()->assertJsonPath('status', 'settled');

        $this->postJson('/api/v1/trip-settlements', [...$settlementPayload, 'idempotency_key' => 'different-request-after-settle-'.$trip['id']])
            ->assertUnprocessable()->assertJsonValidationErrors(['trip_id']);

        $this->assertSame(1, DB::table('trip_settlements')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->count());
        $this->assertSame(1, DB::table('trip_settlement_lines')->where('organization_id', $organization->id)->count());
    }

    public function test_trip_stock_preview_includes_carry_over_and_completed_trip_uses_saved_closing_count(): void
    {
        $organization = $this->organization('Opening Stock API Tenant');
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Opening Stock Manager',
            'email' => 'opening-stock-'.Str::uuid().'@nexora.test', 'phone' => '01000000091',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $warehouseId = (string) Str::ulid();
        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            ['id' => $warehouseId, 'organization_id' => $organization->id, 'code' => 'OPEN-API-WH', 'name' => 'Opening API warehouse', 'type' => 'warehouse', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $vehicleLocationId, 'organization_id' => $organization->id, 'code' => 'OPEN-API-VAN', 'name' => 'Opening API vehicle stock', 'type' => 'vehicle', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $vehicleLocationId,
            'code' => 'OPEN-API-VEHICLE', 'plate_number' => 'OPEN-API-1', 'name' => 'Opening API vehicle',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId, 'organization_id' => $organization->id, 'code' => 'OPEN-API-PCS',
            'name_ar' => 'قطعة', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $unitId,
            'sku' => 'OPEN-API-SKU', 'name_ar' => 'Carry-over API product', 'default_cost' => 20,
            'default_piece_price' => 50, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $vehicleLocationId, 'quantity_base' => 5,
            'reserved_quantity_base' => 0, 'average_cost' => 20, 'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum');
        $trip = $this->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId, 'rep_user_id' => $user->id,
            'origin_location_id' => $warehouseId, 'trip_date' => now()->toDateString(),
        ])->assertCreated()->json();

        $this->getJson('/api/v1/trips/'.$trip['id'].'/stock')
            ->assertOk()->assertJsonPath('0.product_id', $productId)->assertJsonPath('0.quantity_base', 5);
        $this->assertSame(0, DB::table('trip_stock_opening_snapshots')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->count(), 'GET preview must not freeze an opening snapshot.');

        $this->postJson('/api/v1/trip-settlements', [
            'trip_id' => $trip['id'], 'actual_cash' => 0,
            'idempotency_key' => 'opening-api-settle-'.$trip['id'],
            'closing_items' => [['product_id' => $productId, 'quantity_base' => 5]],
        ])->assertCreated()->assertJsonPath('opening_stock_basis', 'snapshot');

        // Simulate later stock in the same vehicle location changing after this trip was closed.
        DB::table('stock_balances')->where('organization_id', $organization->id)
            ->where('product_id', $productId)->where('location_id', $vehicleLocationId)->update(['quantity_base' => 1, 'updated_at' => now()]);

        $this->getJson('/api/v1/trips/'.$trip['id'].'/stock')
            ->assertOk()->assertJsonPath('0.product_id', $productId)->assertJsonPath('0.quantity_base', 5);
        $this->getJson('/api/v1/trips/'.$trip['id'].'/report')
            ->assertOk()
            ->assertJsonPath('settlement.opening_stock_basis', 'snapshot')
            ->assertJsonPath('opening_items.0.product_id', $productId)
            ->assertJsonPath('opening_items.0.quantity_base', 5)
            ->assertJsonPath('stock_reconciliation.0.expected_closing_quantity_base', 5)
            ->assertJsonPath('stock_reconciliation.0.actual_closing_quantity_base', 5)
            ->assertJsonPath('stock_reconciliation.0.variance_quantity_base', 0);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
    }
}
