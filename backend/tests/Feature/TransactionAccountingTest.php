<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_sale_splits_cash_and_receivable(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 10,
            'reserved_quantity_base' => 0,
            'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $result = app(TransactionPostingService::class)->postSale($organization->id, [
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'unit_id' => $unitId,
            ]],
            'paid_amount' => 30,
            'created_by' => $user->id,
        ]);

        $invoiceId = $result['id'];

        $accounts = DB::table('ledger_accounts')
            ->where('organization_id', $organization->id)
            ->pluck('id', 'code');

        $cash = DB::table('ledger_entries')
            ->where('organization_id', $organization->id)
            ->where('account_id', $accounts['1000'])
            ->where('source_document_type', 'sales_invoice')
            ->where('source_document_id', $invoiceId)
            ->sum('debit');

        $ar = DB::table('ledger_entries')
            ->where('organization_id', $organization->id)
            ->where('account_id', $accounts['1200'])
            ->where('source_document_type', 'sales_invoice')
            ->where('source_document_id', $invoiceId)
            ->sum('debit');

        $this->assertSame('30.0000', number_format((float) $cash, 4, '.', ''));
        $this->assertSame('70.0000', number_format((float) $ar, 4, '.', ''));
        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoiceId,
            'paid_amount' => '30.0000',
            'balance_due' => '70.0000',
        ]);
    }

    public function test_trip_settlement_does_not_double_count_later_customer_collection(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organization->id,
            'location_id' => $locationId,
            'code' => 'V-01',
            'plate_number' => 'EG-01',
            'name' => 'Test Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $organization->id,
            'trip_number' => 'TR-000001',
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'status' => 'loaded',
            'trip_date' => now()->toDateString(),
            'origin_location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 10,
            'reserved_quantity_base' => 0,
            'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);

        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'trip_id' => $tripId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'unit_id' => $unitId,
            ]],
            'paid_amount' => 50,
            'created_by' => $user->id,
        ]);

        $financialAccountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $financialAccountId,
            'organization_id' => $organization->id,
            'code' => 'BANK-01',
            'name' => 'Test Cash',
            'type' => 'cash',
            'currency' => 'EGP',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentMethodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $paymentMethodId,
            'organization_id' => $organization->id,
            'code' => 'cash',
            'name_ar' => 'نقدي',
            'requires_reference' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service->postPayment($organization->id, [
            'party_type' => 'customer',
            'party_id' => $customerId,
            'direction' => 'inbound',
            'amount' => 30,
            'financial_account_id' => $financialAccountId,
            'payment_method_id' => $paymentMethodId,
            'trip_id' => $tripId,
            'allocations' => [[
                'document_type' => 'sales_invoice',
                'document_id' => $sale['id'],
                'amount' => 30,
            ]],
            'created_by' => $user->id,
        ]);

        $settlement = $service->settleTrip($organization->id, [
            'trip_id' => $tripId,
            'opening_cash' => 0,
            'actual_cash' => 80,
            'closing_items' => [],
            'settled_by' => $user->id,
        ]);

        $this->assertSame('80.0000', number_format((float) $settlement['expected_cash'], 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $settlement['cash_variance'], 4, '.', ''));
    }

    private function foundation(): array
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA Accounting Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Test User',
            'email' => 'transaction-test-'.$organization->id.'@nexora.test',
            'phone' => '01000000001',
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
            'sku' => 'SKU-'.$organization->id,
            'name_ar' => 'Test Product',
            'default_cost' => 40,
            'default_piece_price' => 100,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'organization_id' => $organization->id,
            'code' => 'CUS-01',
            'name' => 'Test Customer',
            'normalized_name' => 'test customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organization->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organization, $user, $unitId, $productId, $customerId, $locationId];
    }
}
