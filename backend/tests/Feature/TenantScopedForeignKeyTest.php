<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantScopedForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Composite tenant foreign keys are enforced and verified on the target MySQL 8.4 engine.');
        }
    }

    public function test_database_rejects_trip_customer_assignment_across_organizations(): void
    {
        [$orgA, $orgB] = $this->organizations();
        $locationId = $this->location($orgA->id, 'A-WH');
        $vehicleLocationId = $this->location($orgA->id, 'A-VAN');
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $orgA->id,
            'location_id' => $vehicleLocationId,
            'code' => 'A-V1',
            'name' => 'Org A Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $userId,
            'organization_id' => $orgA->id,
            'name' => 'Org A Rep',
            'email' => 'rep-a-' . $orgA->id . '@nexora.test',
            'password' => bcrypt('test-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $orgA->id,
            'trip_number' => 'A-TRIP-1',
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $userId,
            'status' => 'planned',
            'trip_date' => now()->toDateString(),
            'origin_location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'organization_id' => $orgB->id,
            'code' => 'B-CUS-1',
            'name' => 'Org B Customer',
            'normalized_name' => 'org b customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('trip_customers')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $orgA->id,
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'sequence' => 1,
            'planned' => true,
            'visit_status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_stock_balance_for_another_organizations_location(): void
    {
        [$orgA, $orgB] = $this->organizations();
        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId,
            'organization_id' => $orgA->id,
            'code' => 'PCS-A',
            'name_ar' => 'قطعة',
            'precision' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId,
            'organization_id' => $orgA->id,
            'base_unit_id' => $unitId,
            'sku' => 'A-PROD-1',
            'name_ar' => 'Org A Product',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignLocationId = $this->location($orgB->id, 'B-WH');

        $this->expectException(QueryException::class);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $orgA->id,
            'product_id' => $productId,
            'location_id' => $foreignLocationId,
            'quantity_base' => 1,
            'reserved_quantity_base' => 0,
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_sales_invoice_for_another_organizations_customer(): void
    {
        [$orgA, $orgB] = $this->organizations();
        $sourceLocationId = $this->location($orgA->id, 'A-SALES-WH');
        $foreignCustomerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $foreignCustomerId,
            'organization_id' => $orgB->id,
            'code' => 'B-SALES-CUS',
            'name' => 'Foreign Sales Customer',
            'normalized_name' => 'foreign sales customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('sales_invoices')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $orgA->id,
            'customer_id' => $foreignCustomerId,
            'source_location_id' => $sourceLocationId,
            'document_number' => 'A-SALES-INVALID-1',
            'status' => 'draft',
            'invoice_date' => now()->toDateString(),
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance_due' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function organizations(): array
    {
        return [
            Organization::query()->create(['name' => 'Tenant A', 'default_currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active']),
            Organization::query()->create(['name' => 'Tenant B', 'default_currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active']),
        ];
    }

    private function location(string $organizationId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'code' => $code,
            'name' => $code,
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
