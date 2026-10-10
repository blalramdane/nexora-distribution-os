<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_global_payment_method_can_be_used_for_an_allocated_customer_collection(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 5,
            'reserved_quantity_base' => 0,
            'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'unit_id' => $unitId,
            ]],
            'paid_amount' => 0,
            'idempotency_key' => 'global-method-sale-'.$organization->id,
            'created_by' => $user->id,
        ]);

        $financialAccountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $financialAccountId,
            'organization_id' => $organization->id,
            'code' => 'CASH-GLOBAL-TEST',
            'name' => 'Global method collection test',
            'type' => 'cash',
            'currency' => 'EGP',
            'opening_balance' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentMethodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $paymentMethodId,
            'organization_id' => null,
            'code' => 'GLOBAL-CASH',
            'name_ar' => 'نقدي مشترك',
            'requires_reference' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payment = $service->postPayment($organization->id, [
            'party_type' => 'customer',
            'party_id' => $customerId,
            'direction' => 'inbound',
            'amount' => 100,
            'financial_account_id' => $financialAccountId,
            'payment_method_id' => $paymentMethodId,
            'allocations' => [[
                'document_type' => 'sales_invoice',
                'document_id' => $sale['id'],
                'amount' => 100,
            ]],
            'idempotency_key' => 'global-method-payment-'.$organization->id,
            'created_by' => $user->id,
        ]);

        $this->assertSame('posted', $payment['status']);
        $this->assertDatabaseHas('payments', [
            'id' => $payment['id'],
            'organization_id' => $organization->id,
            'payment_method_id' => $paymentMethodId,
        ]);
        $this->assertDatabaseHas('sales_invoices', [
            'id' => $sale['id'],
            'paid_amount' => '100.0000',
            'balance_due' => '0.0000',
        ]);
    }

    public function test_trip_sale_is_rejected_when_using_the_warehouse_instead_of_vehicle_location(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $warehouseId] = $this->foundation();

        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $vehicleLocationId,
            'organization_id' => $organization->id,
            'code' => 'VEHICLE-STOCK-01',
            'name' => 'Vehicle Stock Location',
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
            'code' => 'VEHICLE-01',
            'name' => 'Test Delivery Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $organization->id,
            'trip_number' => 'TRIP-VEHICLE-01',
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'status' => 'loaded',
            'trip_date' => now()->toDateString(),
            'origin_location_id' => $warehouseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(TransactionPostingService::class)->postSale($organization->id, [
                'customer_id' => $customerId,
                'location_id' => $warehouseId,
                'trip_id' => $tripId,
                'items' => [[
                    'product_id' => $productId,
                    'quantity' => 1,
                    'conversion_factor' => 1,
                    'unit_price' => 100,
                    'unit_id' => $unitId,
                ]],
                'paid_amount' => 0,
                'idempotency_key' => 'wrong-trip-location-'.$tripId,
                'created_by' => $user->id,
            ]);

            $this->fail('A trip sale must not post against the warehouse location.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('location_id', $exception->errors());
        }

        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sales_return_rejects_an_invoice_owned_by_another_customer(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => 5,
            'reserved_quantity_base' => 0,
            'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $otherCustomerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $otherCustomerId,
            'organization_id' => $organization->id,
            'code' => 'CUS-OTHER',
            'name' => 'Other Test Customer',
            'normalized_name' => 'other test customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'unit_id' => $unitId,
            ]],
            'paid_amount' => 100,
            'idempotency_key' => 'sales-return-foreign-customer-'.$organization->id,
            'created_by' => $user->id,
        ]);
        $saleItemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $sale['id'])->value('id');

        try {
            $service->postSalesReturn($organization->id, [
                'customer_id' => $otherCustomerId,
                'location_id' => $locationId,
                'original_sales_invoice_id' => $sale['id'],
                'items' => [[
                    'product_id' => $productId,
                    'quantity' => 1,
                    'conversion_factor' => 1,
                    'unit_price' => 100,
                    'original_sales_invoice_item_id' => $saleItemId,
                ]],
                'idempotency_key' => 'foreign-customer-return-'.$organization->id,
                'created_by' => $user->id,
            ]);

            $this->fail('A sales return must not reference another customer’s invoice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('original_sales_invoice_id', $exception->errors());
        }

        $this->assertDatabaseCount('sales_returns', 0);
        $this->assertSame(
            '4.000000',
            number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', '')
        );
    }

    public function test_sales_return_cannot_over_return_a_line_repeated_twice_in_one_request(): void
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

        $service = app(TransactionPostingService::class);
        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 2,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'unit_id' => $unitId,
            ]],
            'paid_amount' => 200,
            'idempotency_key' => 'duplicate-line-return-sale-'.$organization->id,
            'created_by' => $user->id,
        ]);
        $saleItemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $sale['id'])->value('id');

        try {
            $service->postSalesReturn($organization->id, [
                'customer_id' => $customerId,
                'location_id' => $locationId,
                'original_sales_invoice_id' => $sale['id'],
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 1.5,
                        'conversion_factor' => 1,
                        'unit_price' => 100,
                        'original_sales_invoice_item_id' => $saleItemId,
                    ],
                    [
                        'product_id' => $productId,
                        'quantity' => 1,
                        'conversion_factor' => 1,
                        'unit_price' => 100,
                        'original_sales_invoice_item_id' => $saleItemId,
                    ],
                ],
                'idempotency_key' => 'duplicate-line-return-'.$organization->id,
                'created_by' => $user->id,
            ]);

            $this->fail('The total quantity returned from one original line must not exceed its sold quantity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseCount('sales_returns', 0);
        $this->assertDatabaseCount('sales_return_items', 0);
        $this->assertSame(
            '8.000000',
            number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', '')
        );
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

    public function test_sales_return_reconciles_invoice_stock_and_balances_ledger(): void
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

        $service = app(TransactionPostingService::class);
        $sale = $service->postSale($organization->id, [
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

        $saleItemId = DB::table('sales_invoice_items')
            ->where('sales_invoice_id', $sale['id'])
            ->value('id');

        $return = $service->postSalesReturn($organization->id, [
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'original_sales_invoice_id' => $sale['id'],
            'items' => [[
                'product_id' => $productId,
                'quantity' => 0.5,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'original_sales_invoice_item_id' => $saleItemId,
            ]],
            'created_by' => $user->id,
        ]);

        $this->assertTransactionBalanced($organization->id, 'sales_invoice', $sale['id']);
        $this->assertTransactionBalanced($organization->id, 'sales_return', $return['id']);

        $this->assertDatabaseHas('sales_invoices', [
            'id' => $sale['id'],
            'paid_amount' => '30.0000',
            'balance_due' => '20.0000',
        ]);

        $this->assertSame(
            '9.500000',
            number_format((float) DB::table('stock_balances')
                ->where('organization_id', $organization->id)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->value('quantity_base'), 6, '.', '')
        );
    }

    public function test_purchase_return_reconciles_supplier_balance_stock_and_ledger(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'organization_id' => $organization->id,
            'code' => 'SUP-01',
            'name' => 'Test Supplier',
            'normalized_name' => 'test supplier',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'conversion_factor' => 1,
                'unit_cost' => 100,
                'unit_id' => $unitId,
            ]],
            'created_by' => $user->id,
        ]);

        $purchaseItemId = DB::table('purchase_invoice_items')
            ->where('purchase_invoice_id', $purchase['id'])
            ->value('id');

        $return = $service->postPurchaseReturn($organization->id, [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'original_purchase_invoice_id' => $purchase['id'],
            'items' => [[
                'product_id' => $productId,
                'quantity' => 0.5,
                'conversion_factor' => 1,
                'unit_cost' => 100,
                'original_purchase_invoice_item_id' => $purchaseItemId,
            ]],
            'created_by' => $user->id,
        ]);

        $this->assertTransactionBalanced($organization->id, 'purchase_invoice', $purchase['id']);
        $this->assertTransactionBalanced($organization->id, 'purchase_return', $return['id']);

        $summary = DB::table('supplier_balance_summaries')
            ->where('organization_id', $organization->id)
            ->where('supplier_id', $supplierId)
            ->first();

        $this->assertSame('50.0000', number_format((float) $summary->outstanding, 4, '.', ''));
        $this->assertSame(
            '0.500000',
            number_format((float) DB::table('stock_balances')
                ->where('organization_id', $organization->id)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->value('quantity_base'), 6, '.', '')
        );
    }

    public function test_purchase_return_cannot_exceed_the_original_received_quantity_across_repeated_lines(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'organization_id' => $organization->id,
            'code' => 'SUP-OVERRETURN',
            'name' => 'Supplier Over-return Test',
            'normalized_name' => 'supplier over-return test',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 2,
                'conversion_factor' => 1,
                'unit_cost' => 50,
                'unit_id' => $unitId,
            ]],
            'created_by' => $user->id,
        ]);

        $purchaseItemId = DB::table('purchase_invoice_items')
            ->where('purchase_invoice_id', $purchase['id'])
            ->value('id');

        try {
            $service->postPurchaseReturn($organization->id, [
                'supplier_id' => $supplierId,
                'location_id' => $locationId,
                'original_purchase_invoice_id' => $purchase['id'],
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 1.5,
                        'conversion_factor' => 1,
                        'unit_cost' => 50,
                        'original_purchase_invoice_item_id' => $purchaseItemId,
                    ],
                    [
                        'product_id' => $productId,
                        'quantity' => 1,
                        'conversion_factor' => 1,
                        'unit_cost' => 50,
                        'original_purchase_invoice_item_id' => $purchaseItemId,
                    ],
                ],
                'idempotency_key' => 'purchase-over-return-'.$organization->id,
                'created_by' => $user->id,
            ]);

            $this->fail('The total returned quantity must not exceed the original received quantity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertDatabaseCount('purchase_return_items', 0);
        $this->assertSame(
            '2.000000',
            number_format((float) DB::table('stock_balances')
                ->where('organization_id', $organization->id)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->value('quantity_base'), 6, '.', '')
        );
    }

    public function test_purchase_return_uses_original_conversion_snapshot_instead_of_client_value(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'organization_id' => $organization->id,
            'code' => 'SUP-CONVERSION',
            'name' => 'Supplier Conversion Test',
            'normalized_name' => 'supplier conversion test',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 2,
                'conversion_factor' => 1,
                'unit_cost' => 50,
                'unit_id' => $unitId,
            ]],
            'created_by' => $user->id,
        ]);
        $purchaseItemId = DB::table('purchase_invoice_items')->where('purchase_invoice_id', $purchase['id'])->value('id');

        try {
            $service->postPurchaseReturn($organization->id, [
                'supplier_id' => $supplierId,
                'location_id' => $locationId,
                'original_purchase_invoice_id' => $purchase['id'],
                'items' => [[
                    'product_id' => $productId,
                    'quantity' => 3,
                    'conversion_factor' => 0.1,
                    'unit_cost' => 1,
                    'original_purchase_invoice_item_id' => $purchaseItemId,
                ]],
                'idempotency_key' => 'purchase-return-conversion-'.$organization->id,
                'created_by' => $user->id,
            ]);

            $this->fail('The client must not reduce the conversion factor to return more entered units than received.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertSame(
            '2.000000',
            number_format((float) DB::table('stock_balances')
                ->where('organization_id', $organization->id)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->value('quantity_base'), 6, '.', '')
        );
    }

    private function assertTransactionBalanced(string $organizationId, string $sourceType, string $sourceId): void
    {
        $totals = DB::table('ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId)
            ->selectRaw('COALESCE(SUM(debit), 0) as debits, COALESCE(SUM(credit), 0) as credits')
            ->first();

        $this->assertSame(
            number_format((float) $totals->debits, 4, '.', ''),
            number_format((float) $totals->credits, 4, '.', ''),
            "Unbalanced ledger transaction: {$sourceType}:{$sourceId}"
        );
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
