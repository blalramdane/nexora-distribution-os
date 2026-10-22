<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class ReportSummaryTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_report_aggregates_posted_activity_and_current_balances_for_its_own_organization(): void
    {
        [$organization, $user] = $this->organizationUser('Report Tenant A');
        [$foreignOrganization] = $this->organizationUser('Report Tenant B');
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $unitId = $this->unit($organization->id, 'REPORT-PC-A');
        $productId = $this->product($organization->id, $unitId, 'REPORT-SKU-A');
        $customerId = $this->customer($organization->id, 'REPORT-CUS-A');
        $supplierId = $this->supplier($organization->id, 'REPORT-SUP-A');
        $locationId = $this->location($organization->id, 'REPORT-WH-A');

        $salesInvoiceId = (string) Str::ulid();
        DB::table('sales_invoices')->insert([
            'id' => $salesInvoiceId,
            'organization_id' => $organization->id,
            'customer_id' => $customerId,
            'source_location_id' => $locationId,
            'document_number' => 'SI-REPORT-TODAY',
            'status' => 'posted',
            'invoice_date' => $today,
            'posted_at' => now(),
            'subtotal' => 200,
            'discount' => 0,
            'tax' => 0,
            'total' => 200,
            'paid_amount' => 60,
            'balance_due' => 140,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $salesItemId = (string) Str::ulid();
        DB::table('sales_invoice_items')->insert([
            'id' => $salesItemId,
            'organization_id' => $organization->id,
            'sales_invoice_id' => $salesInvoiceId,
            'product_id' => $productId,
            'entered_unit_id' => $unitId,
            'entered_quantity' => 2,
            'conversion_factor_snapshot' => 1,
            'quantity_base' => 2,
            'unit_price_entered' => 100,
            'unit_price_base' => 100,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 200,
            'unit_cost_snapshot' => 40,
            'product_name_snapshot' => 'منتج التقرير',
            'sku_snapshot' => 'REPORT-SKU-A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_invoices')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'customer_id' => $customerId,
            'source_location_id' => $locationId,
            'document_number' => 'SI-REPORT-YESTERDAY',
            'status' => 'posted',
            'invoice_date' => $yesterday,
            'subtotal' => 900,
            'discount' => 0,
            'tax' => 0,
            'total' => 900,
            'paid_amount' => 0,
            'balance_due' => 900,
            'currency' => 'EGP',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $salesReturnId = (string) Str::ulid();
        DB::table('sales_returns')->insert([
            'id' => $salesReturnId,
            'organization_id' => $organization->id,
            'customer_id' => $customerId,
            'source_location_id' => $locationId,
            'original_sales_invoice_id' => $salesInvoiceId,
            'document_number' => 'SR-REPORT-TODAY',
            'status' => 'posted',
            'return_date' => $today,
            'posted_at' => now(),
            'subtotal' => 20,
            'discount' => 0,
            'tax' => 0,
            'total' => 20,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sales_return_items')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'sales_return_id' => $salesReturnId,
            'product_id' => $productId,
            'original_sales_invoice_item_id' => $salesItemId,
            'entered_quantity' => 1,
            'conversion_factor_snapshot' => 1,
            'quantity_base' => 1,
            'unit_price_base' => 20,
            'unit_cost_snapshot' => 40,
            'line_total' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_invoices')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'document_number' => 'PI-REPORT-TODAY',
            'status' => 'posted',
            'invoice_date' => $today,
            'posted_at' => now(),
            'subtotal' => 90,
            'discount' => 0,
            'tax' => 0,
            'total' => 90,
            'paid_amount' => 15,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_returns')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'document_number' => 'PR-REPORT-TODAY',
            'status' => 'posted',
            'return_date' => $today,
            'subtotal' => 30,
            'discount' => 0,
            'tax' => 0,
            'total' => 30,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId,
            'organization_id' => $organization->id,
            'code' => 'REPORT-CASH',
            'name' => 'Report cash account',
            'type' => 'cash',
            'currency' => 'EGP',
            'opening_balance' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $methodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $methodId,
            'organization_id' => $organization->id,
            'code' => 'REPORT-CASH',
            'name_ar' => 'نقدي التقرير',
            'requires_reference' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([['customer', $customerId, 'inbound', 60, 'REPORT-RECEIPT'], ['supplier', $supplierId, 'outbound', 15, 'REPORT-SUPPLIER-PAYMENT']] as [$partyType, $partyId, $direction, $amount, $reference]) {
            DB::table('payments')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organization->id,
                'party_type' => $partyType,
                'party_id' => $partyId,
                'financial_account_id' => $accountId,
                'payment_method_id' => $methodId,
                'direction' => $direction,
                'amount' => $amount,
                'currency' => 'EGP',
                'payment_date' => $today,
                'reference' => $reference,
                'status' => 'posted',
                'idempotency_key' => $reference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('expenses')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'category' => 'fuel',
            'amount' => 10,
            'financial_account_id' => $accountId,
            'expense_date' => $today,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customer_balance_summaries')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'customer_id' => $customerId, 'total_sales' => 200, 'total_returns' => 20,
            'total_paid' => 45, 'outstanding' => 135, 'updated_at' => now(),
        ]);
        DB::table('supplier_balance_summaries')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'supplier_id' => $supplierId, 'total_purchases' => 90, 'total_returns' => 30,
            'total_paid' => 15, 'outstanding' => 45, 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 50, 'reserved_quantity_base' => 0, 'average_cost' => 12,
            'updated_at' => now(),
        ]);

        $foreignCustomer = $this->customer($foreignOrganization->id, 'REPORT-CUS-B');
        $foreignLocation = $this->location($foreignOrganization->id, 'REPORT-WH-B');
        DB::table('sales_invoices')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $foreignOrganization->id,
            'customer_id' => $foreignCustomer,
            'source_location_id' => $foreignLocation,
            'document_number' => 'SI-REPORT-FOREIGN',
            'status' => 'posted',
            'invoice_date' => $today,
            'subtotal' => 50000,
            'discount' => 0,
            'tax' => 0,
            'total' => 50000,
            'paid_amount' => 0,
            'balance_due' => 50000,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports?date_from='.$today.'&date_to='.$today)
            ->assertOk()
            ->assertJsonPath('period.date_from', $today)
            ->assertJsonPath('period.date_to', $today)
            ->assertJsonPath('currency', 'EGP')
            ->assertJsonPath('summary.sales_total', 200)
            ->assertJsonPath('summary.sales_returns_total', 20)
            ->assertJsonPath('summary.net_sales', 180)
            ->assertJsonPath('summary.purchases_total', 90)
            ->assertJsonPath('summary.purchase_returns_total', 30)
            ->assertJsonPath('summary.net_purchases', 60)
            ->assertJsonPath('summary.collections', 60)
            ->assertJsonPath('summary.supplier_payments', 15)
            ->assertJsonPath('summary.expenses', 10)
            ->assertJsonPath('summary.cost_of_goods_sold', 80)
            ->assertJsonPath('summary.returned_cost', 40)
            ->assertJsonPath('summary.net_cost_of_goods_sold', 40)
            ->assertJsonPath('summary.gross_profit', 140)
            ->assertJsonPath('summary.net_profit', 130)
            ->assertJsonPath('summary.receivables_current', 135)
            ->assertJsonPath('summary.payables_current', 45)
            ->assertJsonPath('summary.inventory_units_current', 50)
            ->assertJsonPath('summary.inventory_value_current', 600)
            ->assertJsonPath('daily.0.sales', 200)
            ->assertJsonPath('daily.0.net_profit_before_variance', 130)
            ->assertJsonPath('top_products.0.sku', 'REPORT-SKU-A')
            ->assertJsonPath('top_products.0.sales_total', 200)
            ->assertJsonPath('notes.trip_cash_and_stock_variances_included_in_profit', false);

        $this->assertSame(1, count(DB::table('sales_invoices')->where('organization_id', $organization->id)->whereDate('invoice_date', $today)->get()));
    }

    public function test_report_validates_date_order_and_limits_the_requested_window(): void
    {
        [, $user] = $this->organizationUser('Report Date Validation');
        $today = now()->toDateString();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports?date_from='.$today.'&date_to='.now()->subDay()->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);

        $this->getJson('/api/v1/reports?date_from=2020-01-01&date_to='.$today)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_return_from_an_older_period_can_recover_more_cost_than_current_period_sales(): void
    {
        [$organization, $user] = $this->organizationUser('Report Cost Recovery');
        $today = now()->toDateString();
        $unitId = $this->unit($organization->id, 'REPORT-RECOVERY-PC');
        $productId = $this->product($organization->id, $unitId, 'REPORT-RECOVERY-SKU');
        $customerId = $this->customer($organization->id, 'REPORT-RECOVERY-CUS');
        $locationId = $this->location($organization->id, 'REPORT-RECOVERY-WH');
        $returnId = (string) Str::ulid();

        DB::table('sales_returns')->insert([
            'id' => $returnId,
            'organization_id' => $organization->id,
            'customer_id' => $customerId,
            'source_location_id' => $locationId,
            'original_sales_invoice_id' => null,
            'document_number' => 'SR-REPORT-OLDER-PERIOD',
            'status' => 'posted',
            'return_date' => $today,
            'subtotal' => 20,
            'discount' => 0,
            'tax' => 0,
            'total' => 20,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sales_return_items')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'sales_return_id' => $returnId,
            'product_id' => $productId,
            'original_sales_invoice_item_id' => null,
            'entered_quantity' => 1,
            'conversion_factor_snapshot' => 1,
            'quantity_base' => 1,
            'unit_price_base' => 20,
            'unit_cost_snapshot' => 40,
            'line_total' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports?date_from='.$today.'&date_to='.$today)
            ->assertOk()
            ->assertJsonPath('summary.sales_total', 0)
            ->assertJsonPath('summary.sales_returns_total', 20)
            ->assertJsonPath('summary.net_sales', -20)
            ->assertJsonPath('summary.cost_of_goods_sold', 0)
            ->assertJsonPath('summary.returned_cost', 40)
            ->assertJsonPath('summary.net_cost_of_goods_sold', -40)
            ->assertJsonPath('summary.gross_profit', 20)
            ->assertJsonPath('daily.0.net_cost_of_goods_sold', -40)
            ->assertJsonPath('daily.0.net_profit_before_variance', 20);
    }

    public function test_report_requires_reports_view_permission(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Report Permission Organization',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Report Restricted User',
            'email' => 'report-restricted-'.$organization->id.'@nexora.test',
            'phone' => '01000000111',
            'password' => 'test-password',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports')
            ->assertForbidden();
    }

    private function organizationUser(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => $name.' Manager',
            'email' => Str::uuid().'@nexora.test',
            'phone' => '01000000000',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        return [$organization, $user];
    }

    private function unit(string $organizationId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name_ar' => $code, 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function product(string $organizationId, string $unitId, string $sku): string
    {
        $id = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'base_unit_id' => $unitId,
            'sku' => $sku, 'name_ar' => 'منتج التقرير', 'default_cost' => 40,
            'default_piece_price' => 100, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function customer(string $organizationId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name' => $code, 'normalized_name' => strtolower($code),
            'credit_limit' => 0, 'payment_terms_days' => 0, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function supplier(string $organizationId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name' => $code, 'normalized_name' => strtolower($code), 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function location(string $organizationId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name' => $code, 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
