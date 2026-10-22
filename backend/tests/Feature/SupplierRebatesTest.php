<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class SupplierRebatesTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_lagging_preview_without_rebate_tables_returns_empty_history_and_blocks_writes_cleanly(): void
    {
        [, $user, $supplierId] = $this->makeSupplierFoundation();
        Sanctum::actingAs($user);

        Schema::dropIfExists('supplier_rebate_invoices');
        Schema::dropIfExists('supplier_rebates');

        $this->getJson('/api/v1/suppliers/'.$supplierId.'/rebates')->assertOk()->assertExactJson([]);
        $this->postJson('/api/v1/suppliers/'.$supplierId.'/rebates', [
            'period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'rate_percent' => 5,
        ])->assertStatus(503);
    }

    public function test_supplier_rebate_calculates_once_from_posted_purchase_invoice_totals_and_keeps_audit_snapshots(): void
    {
        [$organization, $user, $supplierId] = $this->makeSupplierFoundation();
        Sanctum::actingAs($user);

        $this->makePurchaseInvoice($organization->id, $supplierId, 'REBATE-PI-001', '2026-10-02', 1000, 'posted');
        $this->makePurchaseInvoice($organization->id, $supplierId, 'REBATE-PI-002', '2026-10-19', 500, 'posted');
        $this->makePurchaseInvoice($organization->id, $supplierId, 'REBATE-PI-DRAFT', '2026-10-12', 900, 'draft');
        $firstInvoiceId = (string) DB::table('purchase_invoices')->where('organization_id', $organization->id)->where('document_number', 'REBATE-PI-001')->value('id');
        $locationId = (string) DB::table('locations')->where('organization_id', $organization->id)->value('id');
        DB::table('purchase_returns')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'supplier_id' => $supplierId,
            'location_id' => $locationId, 'original_purchase_invoice_id' => $firstInvoiceId,
            'document_number' => 'REBATE-RETURN-001', 'status' => 'posted', 'return_date' => '2026-10-14',
            'posted_at' => now(), 'subtotal' => 100, 'discount' => 0, 'tax' => 0, 'total' => 100,
            'payable_amount' => 100, 'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/suppliers/'.$supplierId.'/rebates', [
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-20',
            'rate_percent' => 5,
            'notes' => 'اختبار خصم المورد',
        ])->assertCreated()
            ->assertJsonPath('invoice_count', 2)
            ->assertJsonPath('calculation_base', 1400)
            ->assertJsonPath('rate_percent', 5)
            ->assertJsonPath('rebate_amount', 70)
            ->assertJsonPath('status', 'accrued');

        $rebateId = $response->json('id');
        $this->assertSame(2, DB::table('supplier_rebate_invoices')->where('supplier_rebate_id', $rebateId)->count());
        $this->assertSame(1500.0, (float) DB::table('supplier_rebate_invoices')->where('supplier_rebate_id', $rebateId)->sum('invoice_total_snapshot'));
        $this->assertSame(100.0, (float) DB::table('supplier_rebate_invoices')->where('supplier_rebate_id', $rebateId)->sum('return_total_snapshot'));
        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->where('party_type', 'supplier')->where('party_id', $supplierId)->count());
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $organization->id)->count());

        // A retry over the same period cannot apply a second rebate to the same invoices.
        $this->postJson('/api/v1/suppliers/'.$supplierId.'/rebates', [
            'period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'rate_percent' => 5,
        ])->assertUnprocessable();

        $rows = $this->getJson('/api/v1/suppliers/'.$supplierId.'/rebates')->assertOk()->assertJsonCount(1)->json();
        $this->assertSame($response->json('document_number'), $rows[0]['document_number']);
        $this->assertSame(['REBATE-PI-001', 'REBATE-PI-002'], $rows[0]['invoice_numbers']);
    }

    public function test_supplier_rebate_api_is_tenant_scoped_and_rejects_mixed_currency_periods(): void
    {
        [$organization, $user, $supplierId] = $this->makeSupplierFoundation();
        Sanctum::actingAs($user);
        $this->makePurchaseInvoice($organization->id, $supplierId, 'REBATE-EGP', '2026-10-04', 100, 'posted', 'EGP');
        $this->makePurchaseInvoice($organization->id, $supplierId, 'REBATE-USD', '2026-10-05', 100, 'posted', 'USD');

        $this->postJson('/api/v1/suppliers/'.$supplierId.'/rebates', [
            'period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'rate_percent' => 5,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('supplier_rebates', 0);

        $foreignSupplierId = (string) Str::ulid();
        $otherOrganization = Organization::query()->create([
            'name' => 'Foreign rebate tenant', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        DB::table('suppliers')->insert([
            'id' => $foreignSupplierId, 'organization_id' => $otherOrganization->id,
            'code' => 'FOREIGN-REBATE', 'name' => 'Foreign Supplier', 'normalized_name' => 'foreign supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/suppliers/'.$foreignSupplierId.'/rebates')->assertNotFound();
        $this->postJson('/api/v1/suppliers/'.$foreignSupplierId.'/rebates', [
            'period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'rate_percent' => 5,
        ])->assertNotFound();

        if (DB::getDriverName() === 'mysql') {
            try {
                DB::table('supplier_rebates')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                    'supplier_id' => $foreignSupplierId, 'document_number' => 'CROSS-TENANT-REBATE',
                    'period_from' => '2026-10-01', 'period_to' => '2026-10-20', 'currency' => 'EGP',
                    'invoice_count' => 0, 'calculation_base' => 0, 'rate_percent' => 5, 'rebate_amount' => 0,
                    'status' => 'accrued', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->fail('MySQL must reject a rebate linked to a supplier from another organization.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertDatabaseCount('supplier_rebates', 0);
            }
        }
    }

    private function makeSupplierFoundation(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Supplier rebate test', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Rebate Manager',
            'email' => 'supplier-rebate-'.Str::uuid().'@nexora.test', 'phone' => '01000000041',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $organization->id,
            'code' => 'REBATE-WH', 'name' => 'Rebate Warehouse', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id,
            'code' => 'REBATE-SUP', 'name' => 'Rebate Supplier', 'normalized_name' => 'rebate supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$organization, $user, $supplierId, $locationId];
    }

    private function makePurchaseInvoice(string $organizationId, string $supplierId, string $number, string $date, float $total, string $status, string $currency = 'EGP'): void
    {
        $locationId = (string) DB::table('locations')->where('organization_id', $organizationId)->value('id');
        DB::table('purchase_invoices')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'supplier_id' => $supplierId,
            'location_id' => $locationId, 'document_number' => $number, 'supplier_invoice_number' => $number,
            'status' => $status, 'invoice_date' => $date, 'posted_at' => $status === 'posted' ? now() : null,
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'payable_amount' => $total, 'paid_amount' => 0, 'currency' => $currency,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
