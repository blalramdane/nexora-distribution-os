<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DistributionOperationalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_load_is_idempotent_and_conserves_stock_between_source_and_vehicle(): void
    {
        [$organization, $user, $productId, $sourceLocationId, $vehicleLocationId, $vehicleId, $tripId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $sourceLocationId,
            'quantity_base' => 10,
            'reserved_quantity_base' => 0,
            'average_cost' => 50,
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $payload = [
            'trip_id' => $tripId,
            'from_location_id' => $sourceLocationId,
            'idempotency_key' => 'load-once-' . $tripId,
            'items' => [[
                'product_id' => $productId,
                'quantity_base' => 4,
            ]],
            'created_by' => $user->id,
        ];

        $first = $service->postTripLoad($organization->id, $payload);
        $second = $service->postTripLoad($organization->id, $payload);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, DB::table('trip_loads')->where('organization_id', $organization->id)->where('trip_id', $tripId)->count());
        $this->assertSame(2, DB::table('stock_movements')->where('organization_id', $organization->id)->where('trip_id', $tripId)->count());

        $sourceQty = DB::table('stock_balances')
            ->where('organization_id', $organization->id)
            ->where('product_id', $productId)
            ->where('location_id', $sourceLocationId)
            ->value('quantity_base');

        $vehicleQty = DB::table('stock_balances')
            ->where('organization_id', $organization->id)
            ->where('product_id', $productId)
            ->where('location_id', $vehicleLocationId)
            ->value('quantity_base');

        $this->assertSame('6.000000', number_format((float) $sourceQty, 6, '.', ''));
        $this->assertSame('4.000000', number_format((float) $vehicleQty, 6, '.', ''));
        $this->assertSame('10.000000', number_format((float) $sourceQty + (float) $vehicleQty, 6, '.', ''));
    }

    public function test_one_trip_can_carry_multiple_customers_in_explicit_visit_order(): void
    {
        [$organization, $user, $productId, $sourceLocationId, $vehicleLocationId, $vehicleId, $tripId] = $this->foundation();

        $customers = [];
        foreach (['Customer A', 'Customer B', 'Customer C'] as $index => $name) {
            $customerId = (string) Str::ulid();
            DB::table('customers')->insert([
                'id' => $customerId,
                'organization_id' => $organization->id,
                'code' => 'CUS-' . ($index + 1),
                'name' => $name,
                'normalized_name' => strtolower($name),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $customers[] = $customerId;
        }

        foreach ($customers as $index => $customerId) {
            DB::table('trip_customers')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organization->id,
                'trip_id' => $tripId,
                'customer_id' => $customerId,
                'sequence' => $index + 1,
                'planned' => true,
                'visit_status' => 'planned',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rows = DB::table('trip_customers')
            ->where('organization_id', $organization->id)
            ->where('trip_id', $tripId)
            ->orderBy('sequence')
            ->pluck('customer_id')
            ->values();

        $this->assertCount(3, $rows);
        $this->assertSame($customers, $rows->all());
    }

    private function foundation(): array
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA Distribution Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Distribution Test User',
            'email' => 'distribution-test-' . $organization->id . '@nexora.test',
            'phone' => '01000000002',
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
            'sku' => 'DIST-' . $organization->id,
            'name_ar' => 'Distribution Test Product',
            'default_cost' => 50,
            'default_piece_price' => 100,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sourceLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $sourceLocationId,
            'organization_id' => $organization->id,
            'code' => 'WH-01',
            'name' => 'Warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $vehicleLocationId,
            'organization_id' => $organization->id,
            'code' => 'VEH-01',
            'name' => 'Vehicle Stock',
            'type' => 'vehicle',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organization->id,
            'location_id' => $vehicleLocationId,
            'code' => 'V-01',
            'plate_number' => 'EG-DIST-01',
            'name' => 'Test Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $organization->id,
            'trip_number' => 'TR-' . substr($tripId, -6),
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'status' => 'planned',
            'trip_date' => now()->toDateString(),
            'origin_location_id' => $sourceLocationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organization, $user, $productId, $sourceLocationId, $vehicleLocationId, $vehicleId, $tripId];
    }
}
