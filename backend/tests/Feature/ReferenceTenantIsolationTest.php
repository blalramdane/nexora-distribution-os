<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class ReferenceTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_supplier_and_finance_references_are_tenant_scoped_with_only_approved_global_methods_shared(): void
    {
        [$orgA, $userA] = $this->organizationUser('Reference Tenant A');
        [$orgB] = $this->organizationUser('Reference Tenant B');

        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $orgB->id, 'code' => 'REF-FOREIGN-SUP',
            'name' => 'Foreign supplier', 'normalized_name' => 'foreign supplier', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId, 'organization_id' => $orgB->id, 'code' => 'REF-FOREIGN-CASH',
            'name' => 'Foreign cash', 'type' => 'cash', 'currency' => 'EGP', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignMethodId = $this->paymentMethod($orgB->id, 'REF-FOREIGN-METHOD');
        $globalMethodId = $this->paymentMethod(null, 'REF-GLOBAL-METHOD');

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertOk()->assertExactJson([]);

        $this->getJson('/api/v1/finance/references')
            ->assertOk()
            ->assertJsonMissing(['id' => $accountId])
            ->assertJsonMissing(['id' => $foreignMethodId])
            ->assertJsonFragment(['id' => $globalMethodId]);
    }

    public function test_dashboard_aggregates_exclude_another_tenants_sales_and_inventory(): void
    {
        [$orgA, $userA] = $this->organizationUser('Dashboard Tenant A');
        [$orgB] = $this->organizationUser('Dashboard Tenant B');

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $orgB->id, 'code' => 'DASH-FOREIGN-CUS',
            'name' => 'Foreign dashboard customer', 'normalized_name' => 'foreign dashboard customer',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $orgB->id, 'code' => 'DASH-FOREIGN-WH',
            'name' => 'Foreign dashboard warehouse', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId, 'organization_id' => $orgB->id, 'code' => 'DASH-FOREIGN-UNIT',
            'name_ar' => 'Dashboard unit', 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $orgB->id, 'base_unit_id' => $unitId,
            'sku' => 'DASH-FOREIGN-SKU', 'name_ar' => 'Foreign dashboard product',
            'default_cost' => 12, 'default_piece_price' => 20, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $orgB->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 17, 'reserved_quantity_base' => 0, 'average_cost' => 12,
            'updated_at' => now(),
        ]);
        DB::table('sales_invoices')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $orgB->id, 'customer_id' => $customerId,
            'source_location_id' => $locationId, 'document_number' => 'SI-DASH-FOREIGN',
            'status' => 'posted', 'invoice_date' => now()->toDateString(), 'subtotal' => 340,
            'discount' => 0, 'tax' => 0, 'total' => 340, 'paid_amount' => 0, 'balance_due' => 340,
            'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($userA, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('sales', 0)
            ->assertJsonPath('inventory_units', 0)
            ->assertJsonPath('inventory_value', 0)
            ->assertJsonPath('receivables', 0)
            ->assertJsonPath('payables', 0);
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

    private function paymentMethod(?string $orgId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => $code, 'name_ar' => $code,
            'requires_reference' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
