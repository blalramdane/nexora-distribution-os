<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldRepAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_a_field_rep_with_only_the_field_permission_set(): void
    {
        $organization = $this->organization('Field Account Owner');
        $manager = $this->user($organization, 'Field Account Manager');
        $managerRole = $this->role($organization, 'Manager', 'manager', ['trips.manage', 'trips.view']);
        $manager->roles()->attach($managerRole->id, ['organization_id' => $organization->id]);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/field/reps', [
            'name' => 'مندوب تجربة',
            'phone' => '01090001111',
            'email' => 'field-rep-test@example.test',
            'password' => 'FieldRepTest!2026',
        ])->assertCreated()
            ->assertJsonPath('roles.0.key', 'field_rep')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('field_path', '/field');

        $rep = User::query()->where('id', $response->json('id'))->firstOrFail();
        $this->assertTrue(Hash::check('FieldRepTest!2026', $rep->password));
        $this->assertSame('field_rep', $rep->roles()->firstOrFail()->key);

        $permissionKeys = $rep->roles()->with('permissions')->get()->flatMap(
            static fn (Role $role) => $role->permissions->pluck('key')
        )->unique()->sort()->values()->all();
        $this->assertSame(['expenses.post', 'field.sync', 'field.visit', 'payments.record', 'sales.post'], $permissionKeys);

        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/trips')->assertForbidden();
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/today')->assertOk()->assertJsonPath('trip', null);
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/field/reps')->assertOk()->assertJsonFragment(['id' => $rep->id]);
    }

    public function test_legacy_field_rep_product_permission_cannot_open_general_cost_catalogs(): void
    {
        $organization = $this->organization('Legacy Field Catalog Access');
        $rep = $this->user($organization, 'Legacy Field Catalog Rep');
        $this->role($organization, 'Legacy Field Representative', 'field_rep', [
            'field.visit', 'field.sync', 'products.view',
        ])->users()->attach($rep->id, ['organization_id' => $organization->id]);

        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/products')->assertForbidden();
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/catalog/pos')->assertForbidden();
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/catalog/references')->assertForbidden();
    }

    public function test_field_rep_only_sees_their_assigned_trip_and_cannot_sell_to_an_unassigned_customer(): void
    {
        $organization = $this->organization('Field Trip Scope');
        $rep = $this->user($organization, 'Field Rep One');
        $this->role($organization, 'Field Representative', 'field_rep', [
            'field.visit', 'field.sync', 'sales.post', 'payments.record', 'expenses.post',
        ])->users()->attach($rep->id, ['organization_id' => $organization->id]);
        $otherRep = $this->user($organization, 'Field Rep Two');

        $warehouse = $this->location($organization, 'FIELD-WH', 'Warehouse', 'warehouse');
        $vehicleLocation = $this->location($organization, 'FIELD-VAN', 'Vehicle stock', 'vehicle');
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $vehicleLocation,
            'code' => 'FIELD-VAN', 'name' => 'Field van', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ownTrip = $this->trip($organization, $rep, $vehicleId, $warehouse, 'FIELD-TRIP-OWN');
        $otherTrip = $this->trip($organization, $otherRep, $vehicleId, $warehouse, 'FIELD-TRIP-OTHER');

        $customer = (string) Str::ulid();
        $unassignedCustomer = (string) Str::ulid();
        foreach ([[$customer, 'CUS-ASSIGNED'], [$unassignedCustomer, 'CUS-OTHER']] as [$customerId, $code]) {
            DB::table('customers')->insert([
                'id' => $customerId, 'organization_id' => $organization->id, 'code' => $code,
                'name' => $code, 'normalized_name' => strtolower($code), 'credit_enabled' => true, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('trip_customers')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'trip_id' => $ownTrip,
            'customer_id' => $customer, 'sequence' => 1, 'planned' => true, 'visit_status' => 'planned',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/today')
            ->assertOk()->assertJsonPath('trip.id', $ownTrip)
            ->assertJsonMissingPath('stock.0.average_cost')
            ->assertJsonMissingPath('stock.0.default_cost')
            ->assertJsonMissingPath('stock.0.default_piece_price')
            ->assertJsonMissing(['id' => $otherTrip]);
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/products')->assertForbidden();
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/catalog/pos')->assertForbidden();

        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId, 'organization_id' => $organization->id, 'code' => 'FIELD-PCS',
            'name_ar' => 'قطعة', 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $unitId,
            'sku' => 'FIELD-SKU', 'name_ar' => 'صنف ميداني', 'default_cost' => 10,
            'default_piece_price' => 20, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/sales', [
            'customer_id' => $unassignedCustomer,
            'location_id' => $vehicleLocation,
            'trip_id' => $ownTrip,
            'idempotency_key' => 'field-unassigned-customer-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 20, 'unit_id' => $unitId]],
        ])->assertNotFound();

        $this->postJson('/api/v1/payments', [
            'party_type' => 'supplier', 'party_id' => (string) Str::ulid(),
            'financial_account_id' => (string) Str::ulid(), 'payment_method_id' => (string) Str::ulid(),
            'direction' => 'outbound', 'amount' => 1, 'trip_id' => $ownTrip,
            'idempotency_key' => 'field-reject-outbound-'.Str::uuid(),
        ])->assertForbidden();

        $this->postJson('/api/v1/payments', [
            'party_type' => 'customer', 'party_id' => $customer,
            'financial_account_id' => (string) Str::ulid(), 'payment_method_id' => (string) Str::ulid(),
            'direction' => 'inbound', 'amount' => 1.5, 'trip_id' => $ownTrip,
            'idempotency_key' => 'field-reject-fractional-payment-'.Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/v1/expenses', [
            'category' => 'وقود', 'amount' => 150.5,
            'financial_account_id' => (string) Str::ulid(), 'trip_id' => $ownTrip,
            'vehicle_id' => $vehicleId, 'idempotency_key' => 'field-reject-fractional-expense-'.Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->postJson('/api/v1/trip-settlements', [
            'trip_id' => $ownTrip,
            'actual_cash' => 0,
            'closing_items' => [],
            'idempotency_key' => 'field-must-not-close-trip-'.Str::uuid(),
        ])->assertForbidden();
        $this->postJson('/api/v1/expenses', [
            'category' => 'وقود', 'amount' => 150, 'financial_account_id' => (string) Str::ulid(),
            'trip_id' => $otherTrip, 'vehicle_id' => $vehicleId, 'idempotency_key' => 'field-expense-other-trip-'.Str::uuid(),
        ])->assertForbidden();
        $this->postJson('/api/v1/field/stocktake', [
            'trip_id' => $otherTrip,
            'idempotency_key' => 'field-stocktake-other-trip-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'counted_quantity_base' => 1]],
        ])->assertNotFound();

        $this->assertSame(0, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('trip_settlements')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('expenses')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('field_stock_counts')->where('organization_id', $organization->id)->count());
    }

    public function test_field_rep_can_search_vehicle_stock_and_submit_an_idempotent_stock_count_for_their_trip(): void
    {
        $organization = $this->organization('Field Stocktake Scope');
        $rep = $this->user($organization, 'Field Stocktake Rep');
        $this->role($organization, 'Field Representative', 'field_rep', [
            'field.visit', 'field.sync', 'sales.post', 'payments.record', 'expenses.post',
        ])->users()->attach($rep->id, ['organization_id' => $organization->id]);

        $warehouse = $this->location($organization, 'COUNT-WH', 'Warehouse', 'warehouse');
        $vehicleLocation = $this->location($organization, 'COUNT-VAN', 'Vehicle stock', 'vehicle');
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $vehicleLocation,
            'code' => 'COUNT-VAN', 'name' => 'Count vehicle', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripId = $this->trip($organization, $rep, $vehicleId, $warehouse, 'FIELD-STOCK-COUNT');
        $otherRep = $this->user($organization, 'Other Field Stocktake Rep');
        $otherTrip = $this->trip($organization, $otherRep, $vehicleId, $warehouse, 'FIELD-STOCK-COUNT-OTHER');

        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId, 'organization_id' => $organization->id, 'code' => 'COUNT-PCS',
            'name_ar' => 'قطعة', 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $unitId,
            'sku' => 'FIELD-COUNT-001', 'name_ar' => 'صنف اختبار الجرد', 'name_en' => 'Field stocktake sample',
            'default_cost' => 10, 'default_piece_price' => 20, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'product_id' => $productId,
            'location_id' => $vehicleLocation, 'quantity_base' => 7, 'reserved_quantity_base' => 0,
            'average_cost' => 10, 'updated_at' => now(),
        ]);

        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/catalog?trip_id='.$tripId.'&q=FIELD-COUNT-001')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $productId)
            ->assertJsonPath('data.0.quantity_base', 7)->assertJsonPath('data.0.base_unit_name', 'قطعة')
            ->assertJsonMissingPath('data.0.default_piece_price')->assertJsonMissingPath('data.0.default_cost')
            ->assertJsonMissingPath('data.0.packagings.0.sale_price')->assertJsonMissingPath('data.0.packagings.0.purchase_price');

        $payload = [
            'trip_id' => $tripId,
            'idempotency_key' => 'field-count-'.Str::uuid(),
            'notes' => 'عدد فعلي للتجربة',
            'items' => [['product_id' => $productId, 'counted_quantity_base' => 5]],
        ];

        $first = $this->actingAs($rep, 'sanctum')->postJson('/api/v1/field/stocktake', $payload)
            ->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('item_count', 1)
            ->assertJsonPath('variance_items_count', 1)->assertJsonPath('items.0.expected_quantity_base', 7)
            ->assertJsonPath('items.0.counted_quantity_base', 5)->assertJsonPath('items.0.variance_quantity_base', -2);
        $stockCountId = $first->json('id');

        $this->actingAs($rep, 'sanctum')->postJson('/api/v1/field/stocktake', $payload)
            ->assertOk()->assertJsonPath('id', $stockCountId)->assertJsonPath('variance_items_count', 1);

        $changedPayload = $payload;
        $changedPayload['items'][0]['counted_quantity_base'] = 6;
        $this->actingAs($rep, 'sanctum')->postJson('/api/v1/field/stocktake', $changedPayload)->assertUnprocessable();

        $this->assertSame(1, DB::table('field_stock_counts')->where('organization_id', $organization->id)->where('trip_id', $tripId)->count());
        $this->assertEquals(7.0, (float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('location_id', $vehicleLocation)->where('product_id', $productId)->value('quantity_base'));
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/stocktake?trip_id='.$tripId)
            ->assertOk()->assertJsonPath('items.0.expected_quantity_base', 7)->assertJsonPath('history.0.id', $stockCountId);

        $firstCustomerId = (string) Str::ulid();
        $secondCustomerId = (string) Str::ulid();
        foreach ([[$firstCustomerId, 'PRICE-CUSTOMER-001'], [$secondCustomerId, 'PRICE-CUSTOMER-002']] as [$customerId, $code]) {
            DB::table('customers')->insert([
                'id' => $customerId, 'organization_id' => $organization->id, 'code' => $code,
                'name' => $code, 'normalized_name' => strtolower($code), 'credit_enabled' => true, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('trip_customers')->insert([
            ['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'trip_id' => $tripId, 'customer_id' => $firstCustomerId, 'sequence' => 1, 'planned' => true, 'visit_status' => 'checked_in', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'trip_id' => $tripId, 'customer_id' => $secondCustomerId, 'sequence' => 2, 'planned' => true, 'visit_status' => 'planned', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'trip_id' => $otherTrip, 'customer_id' => $firstCustomerId, 'sequence' => 1, 'planned' => true, 'visit_status' => 'planned', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('customer_product_prices')->insert([
            ['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'customer_id' => $firstCustomerId, 'product_id' => $productId, 'packaging_id' => null, 'unit_key' => 'base', 'unit_price' => 80, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'customer_id' => $secondCustomerId, 'product_id' => $productId, 'packaging_id' => null, 'unit_key' => 'base', 'unit_price' => 110, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $firstCustomerPrices = $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/customer-prices?trip_id='.$tripId.'&customer_id='.$firstCustomerId)
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.product_id', $productId)
            ->assertJsonPath('0.unit_key', 'base')->assertJsonMissingPath('0.default_cost');
        $this->assertEquals(80.0, (float) $firstCustomerPrices->json('0.unit_price'));
        $secondCustomerPrices = $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/customer-prices?trip_id='.$tripId.'&customer_id='.$secondCustomerId)
            ->assertOk();
        $this->assertEquals(110.0, (float) $secondCustomerPrices->json('0.unit_price'));
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/customer-prices?trip_id='.$otherTrip.'&customer_id='.$firstCustomerId)->assertNotFound();
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/customer-prices?trip_id='.$tripId.'&customer_id='.(string) Str::ulid())->assertNotFound();

        $firstSale = $this->actingAs($rep, 'sanctum')->postJson('/api/v1/sales', [
            'customer_id' => $firstCustomerId, 'location_id' => $vehicleLocation, 'trip_id' => $tripId,
            'idempotency_key' => 'field-sale-customer-price-80-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'unit_id' => $unitId, 'quantity' => 1, 'unit_price' => 80]],
        ])->assertCreated();
        $secondSale = $this->actingAs($rep, 'sanctum')->postJson('/api/v1/sales', [
            'customer_id' => $secondCustomerId, 'location_id' => $vehicleLocation, 'trip_id' => $tripId,
            'idempotency_key' => 'field-sale-customer-price-110-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'unit_id' => $unitId, 'quantity' => 1, 'unit_price' => 110]],
        ])->assertCreated();

        $this->assertNotSame($firstSale->json('id'), $secondSale->json('id'));
        // MySQL returns DECIMAL as a fixed-scale string while SQLite may return
        // an integer for whole values. Compare numeric snapshots across drivers.
        $this->assertEquals(80.0, (float) DB::table('sales_invoice_items')->where('organization_id', $organization->id)->where('sales_invoice_id', $firstSale->json('id'))->value('unit_price_entered'));
        $this->assertEquals(110.0, (float) DB::table('sales_invoice_items')->where('organization_id', $organization->id)->where('sales_invoice_id', $secondSale->json('id'))->value('unit_price_entered'));
        $this->assertEquals(10.0, (float) DB::table('sales_invoice_items')->where('organization_id', $organization->id)->where('sales_invoice_id', $firstSale->json('id'))->value('unit_cost_snapshot'));
        $this->assertEquals(10.0, (float) DB::table('sales_invoice_items')->where('organization_id', $organization->id)->where('sales_invoice_id', $secondSale->json('id'))->value('unit_cost_snapshot'));

        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/field/today')
            ->assertOk()->assertJsonMissingPath('stock.0.average_cost')->assertJsonMissingPath('stock.0.default_cost')->assertJsonMissingPath('stock.0.default_piece_price');

        $this->actingAs($rep, 'sanctum')->postJson('/api/v1/sales', [
            'customer_id' => $firstCustomerId, 'location_id' => $vehicleLocation, 'trip_id' => $tripId,
            'idempotency_key' => 'field-sale-fractional-price-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'unit_id' => $unitId, 'quantity' => 1, 'unit_price' => 99.5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_price']);

        $this->actingAs($rep, 'sanctum')->postJson('/api/v1/sales', [
            'customer_id' => $firstCustomerId, 'location_id' => $vehicleLocation, 'trip_id' => $tripId,
            'idempotency_key' => 'field-sale-fractional-quantity-'.Str::uuid(),
            'items' => [['product_id' => $productId, 'unit_id' => $unitId, 'quantity' => 1.5, 'unit_price' => 99]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertSame(2, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertEquals(5.0, (float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('location_id', $vehicleLocation)->where('product_id', $productId)->value('quantity_base'));
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'default_currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'country_code' => 'EG', 'status' => 'active',
        ]);
    }

    private function user(Organization $organization, string $name): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::uuid().'@nexora.test',
            'phone' => '01'.random_int(100000000, 999999999),
            'password' => 'TestAccountPassword',
            'status' => 'active',
        ]);
    }

    private function role(Organization $organization, string $name, string $key, array $permissionKeys): Role
    {
        $role = Role::query()->create(['organization_id' => $organization->id, 'name' => $name, 'key' => $key]);
        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key], ['id' => (string) Str::ulid(), 'name' => $key],
            );
            $role->permissions()->attach($permission->id);
        }
        return $role;
    }

    private function location(Organization $organization, string $code, string $name, string $type): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $organization->id, 'code' => $code, 'name' => $name,
            'type' => $type, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function trip(Organization $organization, User $rep, string $vehicleId, string $warehouseId, string $number): string
    {
        $id = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $id, 'organization_id' => $organization->id, 'trip_number' => $number,
            'vehicle_id' => $vehicleId, 'rep_user_id' => $rep->id, 'status' => 'loaded',
            'trip_date' => now()->toDateString(), 'origin_location_id' => $warehouseId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
