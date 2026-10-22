<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class InvoiceRegisterTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_unified_register_lists_sales_and_purchase_invoices_and_filters_without_cross_tenant_data(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Invoice register test', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Register Reader',
            'email' => 'invoice-register-'.Str::uuid().'@nexora.test', 'phone' => '01000000051',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        Sanctum::actingAs($user);

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $organization->id, 'code' => 'REG-WH',
            'name' => 'Register Warehouse', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'REG-SUP',
            'name' => 'Register Supplier', 'normalized_name' => 'register supplier', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $organization->id, 'code' => 'REG-CUS',
            'name' => 'Register Customer', 'normalized_name' => 'register customer', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('purchase_invoices')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'supplier_id' => $supplierId,
            'location_id' => $locationId, 'document_number' => 'REG-PI-001', 'supplier_invoice_number' => 'SUP-445',
            'status' => 'posted', 'invoice_date' => '2026-10-10', 'posted_at' => now(), 'subtotal' => 100,
            'discount' => 0, 'tax' => 0, 'total' => 100, 'payable_amount' => 100, 'paid_amount' => 25,
            'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_invoices')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'customer_id' => $customerId,
            'source_location_id' => $locationId, 'trip_id' => null, 'document_number' => 'REG-SI-001',
            'status' => 'posted', 'invoice_date' => '2026-10-11', 'posted_at' => now(), 'subtotal' => 200,
            'discount' => 0, 'tax' => 0, 'total' => 200, 'paid_amount' => 120, 'balance_due' => 80,
            'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/invoice-register?type=all&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()->assertJsonPath('summary.invoice_count', 2)->assertJsonPath('pagination.total', 2);
        $rows = collect($response->json('invoices'))->keyBy('document_type');
        $this->assertSame('REG-SI-001', $rows->get('sales')['document_number']);
        $this->assertSame(80.0, (float) $rows->get('sales')['remaining_amount']);
        $this->assertSame('REG-PI-001', $rows->get('purchases')['document_number']);
        $this->assertSame(75.0, (float) $rows->get('purchases')['remaining_amount']);
        $this->assertSame('SUP-445', $rows->get('purchases')['external_number']);
        $this->assertSame(80.0, (float) $response->json('summary.by_currency.0.customer_receivables_total'));
        $this->assertSame(75.0, (float) $response->json('summary.by_currency.0.supplier_payables_total'));

        $this->getJson('/api/v1/invoice-register?type=sales&q=Register%20Customer')
            ->assertOk()->assertJsonPath('summary.invoice_count', 1)->assertJsonPath('invoices.0.document_type', 'sales');
        $this->getJson('/api/v1/invoice-register?type=purchases')
            ->assertOk()->assertJsonPath('summary.invoice_count', 1)->assertJsonPath('invoices.0.document_type', 'purchases');
    }
}
