<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class PurchaseTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_purchase_rejects_foreign_supplier_location_and_product_without_posting(): void
    {
        [$orgA, $userA] = $this->organizationUser('Purchase Tenant A');
        [$orgB] = $this->organizationUser('Purchase Tenant B');

        $unitA = $this->unit($orgA->id, 'PUR-UNIT-A');
        $unitB = $this->unit($orgB->id, 'PUR-UNIT-B');
        $productA = $this->product($orgA->id, $unitA, 'PUR-SKU-A');
        $productB = $this->product($orgB->id, $unitB, 'PUR-SKU-B');
        $foreignPackaging = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            'id' => $foreignPackaging, 'organization_id' => $orgB->id, 'product_id' => $productB,
            'unit_id' => $unitB, 'name_ar' => 'Foreign carton', 'conversion_to_base' => 24,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierA = $this->supplier($orgA->id, 'PUR-SUP-A');
        $supplierB = $this->supplier($orgB->id, 'PUR-SUP-B');
        $locationA = $this->location($orgA->id, 'PUR-WH-A');
        $locationB = $this->location($orgB->id, 'PUR-WH-B');

        $base = [
            'supplier_id' => $supplierA,
            'location_id' => $locationA,
            'items' => [['product_id' => $productA, 'quantity' => 1, 'unit_cost' => 10]],
        ];

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/purchases', [...$base, 'supplier_id' => $supplierB, 'idempotency_key' => 'foreign-supplier-'.Str::uuid()])
            ->assertNotFound();

        $this->postJson('/api/v1/purchases', [...$base, 'location_id' => $locationB, 'idempotency_key' => 'foreign-location-'.Str::uuid()])
            ->assertNotFound();

        $this->postJson('/api/v1/purchases', [...$base, 'items' => [['product_id' => $productB, 'quantity' => 1, 'unit_cost' => 10]], 'idempotency_key' => 'foreign-product-'.Str::uuid()])
            ->assertNotFound();

        $this->postJson('/api/v1/purchases', [...$base, 'items' => [[
            'product_id' => $productA, 'quantity' => 1, 'unit_cost' => 10,
            'packaging_id' => $foreignPackaging, 'unit_id' => $unitB, 'conversion_factor' => 24,
        ]], 'idempotency_key' => 'foreign-packaging-'.Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['items']);

        $this->postJson('/api/v1/purchases', [...$base, 'items' => [[
            'product_id' => $productA, 'quantity' => 1, 'unit_cost' => 10,
            'unit_id' => $unitB, 'conversion_factor' => 24,
        ]], 'idempotency_key' => 'foreign-unit-'.Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['items']);

        $foreignInvoiceId = (string) Str::ulid();
        DB::table('purchase_invoices')->insert([
            'id' => $foreignInvoiceId, 'organization_id' => $orgB->id,
            'supplier_id' => $supplierB, 'location_id' => $locationB,
            'document_number' => 'PI-FOREIGN-READ', 'status' => 'posted',
            'invoice_date' => now()->toDateString(), 'subtotal' => 10, 'discount' => 0,
            'tax' => 0, 'total' => 10, 'paid_amount' => 0, 'currency' => 'EGP',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/purchases/history?supplier_id='.$supplierB)
            ->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/purchases/invoices/'.$foreignInvoiceId.'/items')->assertNotFound();

        $this->assertSame(0, DB::table('purchase_invoices')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('purchase_invoice_items')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('idempotency_keys')->where('organization_id', $orgA->id)->where('operation_type', 'purchase.post')->count());
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

    private function supplier(string $orgId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => $code,
            'name' => $code, 'normalized_name' => strtolower($code), 'active' => true,
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
