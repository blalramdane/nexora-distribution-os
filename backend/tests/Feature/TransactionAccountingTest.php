<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_settlement_replays_same_request_without_settling_twice(): void
    {
        [$organization, $user, , , , $locationId] = $this->foundation();

        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organization->id,
            'location_id' => $locationId,
            'code' => 'SETTLE-IDEMPOTENT-VEHICLE',
            'name' => 'Settlement Idempotency Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $organization->id,
            'trip_number' => 'SETTLE-IDEMPOTENT-TRIP',
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'status' => 'loaded',
            'trip_date' => now()->toDateString(),
            'origin_location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'trip_id' => $tripId,
            'opening_cash' => 0,
            'actual_cash' => 0,
            'closing_items' => [],
            'settled_by' => $user->id,
            'idempotency_key' => 'trip-settlement-retry-'.$tripId,
        ];

        $service = app(TransactionPostingService::class);
        $first = $service->settleTrip($organization->id, $payload);
        $retry = $service->settleTrip($organization->id, $payload);

        $this->assertSame($first['id'], $retry['id']);
        $this->assertEquals($first['expected_cash'], $retry['expected_cash']);
        $this->assertEquals($first['cash_variance'], $retry['cash_variance']);
        $this->assertDatabaseCount('trip_settlements', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);

        try {
            $service->settleTrip($organization->id, [...$payload, 'actual_cash' => 1]);
            $this->fail('A settlement idempotency key must not be reused with a changed payload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('trip_settlements', 1);
    }

    public function test_trip_settlement_records_nonzero_variances_without_implicitly_posting_adjustments(): void
    {
        [$organization, $user, , $productId, , $locationId] = $this->foundation();

        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organization->id,
            'location_id' => $locationId,
            'code' => 'SETTLE-VARIANCE-VEHICLE',
            'name' => 'Settlement Variance Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $organization->id,
            'trip_number' => 'SETTLE-VARIANCE-TRIP',
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
            'quantity_base' => 5,
            'reserved_quantity_base' => 0,
            'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $movementCountBefore = DB::table('stock_movements')->where('organization_id', $organization->id)->count();
        $ledgerCountBefore = DB::table('ledger_entries')->where('organization_id', $organization->id)->count();

        $settlement = app(TransactionPostingService::class)->settleTrip($organization->id, [
            'trip_id' => $tripId,
            'opening_cash' => 25,
            'actual_cash' => 31,
            'closing_items' => [['product_id' => $productId, 'quantity_base' => 3]],
            'settled_by' => $user->id,
            'idempotency_key' => 'trip-settlement-variance-'.$tripId,
        ]);

        $this->assertSame('25.0000', number_format((float) $settlement['expected_cash'], 4, '.', ''));
        $this->assertSame('31.0000', number_format((float) $settlement['actual_cash'], 4, '.', ''));
        $this->assertSame('6.0000', number_format((float) $settlement['cash_variance'], 4, '.', ''));
        $this->assertSame('-80.0000', number_format((float) $settlement['stock_variance_value'], 4, '.', ''));
        $this->assertSame('snapshot', $settlement['opening_stock_basis']);

        $this->assertDatabaseHas('trip_settlements', [
            'id' => $settlement['id'],
            'organization_id' => $organization->id,
            'trip_id' => $tripId,
            'expected_cash' => '25.0000',
            'actual_cash' => '31.0000',
            'cash_variance' => '6.0000',
            'stock_variance_value' => '-80.0000',
        ]);
        $this->assertDatabaseHas('trip_settlement_lines', [
            'organization_id' => $organization->id,
            'trip_settlement_id' => $settlement['id'],
            'product_id' => $productId,
            'opening_quantity_base' => '5.000000',
            'expected_closing_quantity_base' => '5.000000',
            'actual_closing_quantity_base' => '3.000000',
            'variance_quantity_base' => '-2.000000',
        ]);
        $this->assertDatabaseHas('trip_stock_opening_snapshot_items', [
            'organization_id' => $organization->id,
            'trip_id' => $tripId,
            'product_id' => $productId,
            'opening_quantity_base' => '5.000000',
        ]);

        // The variance is retained for review. Until the owner approves
        // variance-posting policy, settlement must not silently mutate stock or GL.
        $this->assertSame($movementCountBefore, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertSame($ledgerCountBefore, DB::table('ledger_entries')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('stock_balances', [
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => '5.000000',
        ]);
        $this->assertDatabaseHas('trips', ['id' => $tripId, 'status' => 'completed']);
    }

    public function test_trip_settlement_reconciles_carried_over_stock_and_items_not_reloaded_this_trip(): void
    {
        [$organization, $user, $unitId, $productId, , $warehouseId] = $this->foundation();
        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $vehicleLocationId, 'organization_id' => $organization->id,
            'code' => 'OPENING-VEHICLE-LOC', 'name' => 'Opening stock vehicle', 'type' => 'vehicle',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $secondProductId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $secondProductId, 'organization_id' => $organization->id, 'base_unit_id' => $unitId,
            'sku' => 'SKU-CARRY-OVER-'.$organization->id, 'name_ar' => 'صنف مرحل من رحلة سابقة',
            'default_cost' => 20, 'default_piece_price' => 50, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $vehicleLocationId,
            'code' => 'OPENING-VEHICLE', 'name' => 'Opening Stock Vehicle', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId, 'organization_id' => $organization->id, 'trip_number' => 'OPENING-'.$organization->id,
            'vehicle_id' => $vehicleId, 'rep_user_id' => $user->id, 'status' => 'planned',
            'trip_date' => now()->toDateString(), 'origin_location_id' => $warehouseId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$productId, $warehouseId, 3, 40], [$productId, $vehicleLocationId, 5, 40], [$secondProductId, $vehicleLocationId, 2, 20]] as [$pid, $locationId, $quantity, $cost]) {
            DB::table('stock_balances')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'product_id' => $pid, 'location_id' => $locationId, 'quantity_base' => $quantity,
                'reserved_quantity_base' => 0, 'average_cost' => $cost, 'updated_at' => now(),
            ]);
        }

        $service = app(TransactionPostingService::class);
        $load = $service->postTripLoad($organization->id, [
            'trip_id' => $tripId, 'from_location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity_base' => 3]],
            'idempotency_key' => 'opening-snapshot-load-'.$tripId, 'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('trip_stock_opening_snapshots', [
            'organization_id' => $organization->id, 'trip_id' => $tripId,
            'vehicle_id' => $vehicleId, 'location_id' => $vehicleLocationId,
        ]);
        $this->assertDatabaseHas('trip_stock_opening_snapshot_items', [
            'organization_id' => $organization->id, 'trip_id' => $tripId,
            'product_id' => $productId, 'opening_quantity_base' => '5.000000',
        ]);
        $this->assertDatabaseHas('trip_stock_opening_snapshot_items', [
            'organization_id' => $organization->id, 'trip_id' => $tripId,
            'product_id' => $secondProductId, 'opening_quantity_base' => '2.000000',
        ]);

        $movementCountBefore = DB::table('stock_movements')->where('organization_id', $organization->id)->count();
        $ledgerCountBefore = DB::table('ledger_entries')->where('organization_id', $organization->id)->count();
        $settlement = $service->settleTrip($organization->id, [
            'trip_id' => $tripId, 'opening_cash' => 0, 'actual_cash' => 0,
            'closing_items' => [
                ['product_id' => $productId, 'quantity_base' => 8],
                ['product_id' => $secondProductId, 'quantity_base' => 2],
            ],
            'settled_by' => $user->id, 'idempotency_key' => 'opening-snapshot-settle-'.$tripId,
        ]);

        $this->assertSame('snapshot', $settlement['opening_stock_basis']);
        $this->assertSame('0.0000', number_format((float) $settlement['stock_variance_value'], 4, '.', ''));
        $this->assertDatabaseHas('trip_settlement_lines', [
            'organization_id' => $organization->id, 'trip_settlement_id' => $settlement['id'],
            'product_id' => $productId, 'opening_quantity_base' => '5.000000',
            'loaded_quantity_base' => '3.000000', 'expected_closing_quantity_base' => '8.000000',
            'actual_closing_quantity_base' => '8.000000', 'variance_quantity_base' => '0.000000',
        ]);
        $this->assertDatabaseHas('trip_settlement_lines', [
            'organization_id' => $organization->id, 'trip_settlement_id' => $settlement['id'],
            'product_id' => $secondProductId, 'opening_quantity_base' => '2.000000',
            'loaded_quantity_base' => '0.000000', 'expected_closing_quantity_base' => '2.000000',
            'actual_closing_quantity_base' => '2.000000', 'variance_quantity_base' => '0.000000',
        ]);

        // Closing count remains review-only; reconciliation must not silently post adjustments.
        $this->assertSame($movementCountBefore, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertSame($ledgerCountBefore, DB::table('ledger_entries')->where('organization_id', $organization->id)->count());
        $this->assertSame('8.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $vehicleLocationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame('2.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $secondProductId)->where('location_id', $vehicleLocationId)->value('quantity_base'), 6, '.', ''));
        $this->assertNotEmpty($load['id']);
    }

    public function test_legacy_trip_with_prior_movements_does_not_guess_an_opening_snapshot(): void
    {
        [$organization, $user, , $productId, , $locationId] = $this->foundation();
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $locationId,
            'code' => 'LEGACY-OPENING', 'name' => 'Legacy Opening Vehicle', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId, 'organization_id' => $organization->id, 'trip_number' => 'LEGACY-'.$organization->id,
            'vehicle_id' => $vehicleId, 'rep_user_id' => $user->id, 'status' => 'loaded',
            'trip_date' => now()->toDateString(), 'origin_location_id' => $locationId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId, 'quantity_base' => 4,
            'reserved_quantity_base' => 0, 'average_cost' => 40, 'updated_at' => now(),
        ]);
        DB::table('stock_movements')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'transaction_uuid' => (string) Str::uuid(), 'product_id' => $productId, 'location_id' => $locationId,
            'movement_type' => 'vehicle_load', 'quantity_base' => 4, 'unit_cost' => 40,
            'source_document_type' => 'trip_load', 'source_document_id' => (string) Str::ulid(),
            'occurred_at' => now(), 'posted_at' => now(), 'trip_id' => $tripId,
            'reference' => 'LEGACY-LOAD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $settlement = app(TransactionPostingService::class)->settleTrip($organization->id, [
            'trip_id' => $tripId, 'opening_cash' => 0, 'actual_cash' => 0,
            'closing_items' => [['product_id' => $productId, 'quantity_base' => 4]],
            'settled_by' => $user->id, 'idempotency_key' => 'legacy-opening-settle-'.$tripId,
        ]);

        $this->assertSame('legacy_zero', $settlement['opening_stock_basis']);
        $this->assertStringContainsString('لم يتم تخمين مخزون البداية', $settlement['opening_stock_note']);
        $this->assertDatabaseMissing('trip_stock_opening_snapshots', [
            'organization_id' => $organization->id, 'trip_id' => $tripId,
        ]);
        $this->assertDatabaseHas('trip_settlements', [
            'id' => $settlement['id'], 'opening_stock_basis' => 'legacy_zero',
        ]);
        $this->assertDatabaseHas('trip_settlement_lines', [
            'organization_id' => $organization->id, 'trip_settlement_id' => $settlement['id'],
            'product_id' => $productId, 'opening_quantity_base' => '0.000000',
            'expected_closing_quantity_base' => '4.000000', 'variance_quantity_base' => '0.000000',
        ]);
    }

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
            ...$this->salePaymentFields($organization->id),
            'created_by' => $user->id,
        ]);

        $invoiceId = $result['id'];

        $accounts = DB::table('ledger_accounts')
            ->where('organization_id', $organization->id)
            ->pluck('id', 'code');

        $financialCashAccountId = DB::table('ledger_accounts')
            ->where('organization_id', $organization->id)
            ->where('code', 'FA-ACCOUNTING-TEST-CASH')
            ->value('id');
        $paymentId = DB::table('payments')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'sale_at_issue')
            ->where('source_document_id', $invoiceId)
            ->value('id');
        $cash = DB::table('ledger_entries')
            ->where('organization_id', $organization->id)
            ->where('account_id', $financialCashAccountId)
            ->where('source_document_type', 'payment')
            ->where('source_document_id', $paymentId)
            ->sum('debit');

        $ar = DB::table('ledger_entries')
            ->where('organization_id', $organization->id)
            ->where('account_id', $accounts['1200'])
            ->where('source_document_type', 'sales_invoice')
            ->where('source_document_id', $invoiceId)
            ->sum('debit');
        $paymentArCredit = DB::table('ledger_entries')
            ->where('organization_id', $organization->id)
            ->where('account_id', $accounts['1200'])
            ->where('source_document_type', 'payment')
            ->where('source_document_id', $paymentId)
            ->sum('credit');

        $this->assertSame('30.0000', number_format((float) $cash, 4, '.', ''));
        $this->assertSame('100.0000', number_format((float) $ar, 4, '.', ''));
        $this->assertSame('30.0000', number_format((float) $paymentArCredit, 4, '.', ''));
        $this->assertDatabaseHas('sales_invoices', [
            'id' => $invoiceId,
            'paid_amount' => '30.0000',
            'balance_due' => '70.0000',
        ]);
    }

    public function test_payment_rejects_duplicate_allocations_that_exceed_one_invoice_balance(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 5, 'reserved_quantity_base' => 0, 'average_cost' => 40,
            'updated_at' => now(),
        ]);
        $service = app(TransactionPostingService::class);
        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'conversion_factor' => 1, 'unit_price' => 100, 'unit_id' => $unitId]],
            'paid_amount' => 0, 'created_by' => $user->id,
        ]);

        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId, 'organization_id' => $organization->id, 'code' => 'DUP-ALLOC-CASH',
            'name' => 'Duplicate allocation test cash', 'type' => 'cash', 'currency' => 'EGP',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $methodId, 'organization_id' => $organization->id, 'code' => 'DUP-ALLOC-METHOD',
            'name_ar' => 'Duplicate allocation method', 'requires_reference' => false,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $service->postPayment($organization->id, [
                'party_type' => 'customer', 'party_id' => $customerId, 'direction' => 'inbound',
                'amount' => 120, 'financial_account_id' => $accountId, 'payment_method_id' => $methodId,
                'allocations' => [
                    ['document_type' => 'sales_invoice', 'document_id' => $sale['id'], 'amount' => 60],
                    ['document_type' => 'sales_invoice', 'document_id' => $sale['id'], 'amount' => 60],
                ],
                'idempotency_key' => 'duplicate-allocation-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('Repeated allocations cannot exceed the invoice outstanding balance in aggregate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
        }

        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('payment_allocations')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('sales_invoices', ['id' => $sale['id'], 'paid_amount' => '0.0000', 'balance_due' => '100.0000']);
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $organization->id)->where('source_document_type', 'payment')->count());
    }

    public function test_sales_and_purchase_discount_cannot_exceed_subtotal_or_mutate_stock(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 5, 'reserved_quantity_base' => 0, 'average_cost' => 40,
            'updated_at' => now(),
        ]);
        $service = app(TransactionPostingService::class);

        try {
            $service->postSale($organization->id, [
                'customer_id' => $customerId, 'location_id' => $locationId, 'created_by' => $user->id,
                'idempotency_key' => 'sale-excess-discount-' . Str::uuid(), 'discount' => 101,
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
            ]);
            $this->fail('Sale discount cannot exceed the sale subtotal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discount', $exception->errors());
        }

        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'SUP-DISC-TEST',
            'name' => 'Discount test supplier', 'normalized_name' => 'discount test supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $service->postPurchase($organization->id, [
                'supplier_id' => $supplierId, 'location_id' => $locationId, 'created_by' => $user->id,
                'idempotency_key' => 'purchase-excess-discount-' . Str::uuid(), 'discount' => 101,
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 100]],
            ]);
            $this->fail('Purchase discount cannot exceed the purchase subtotal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discount', $exception->errors());
        }

        $this->assertSame(0, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('purchase_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame('5.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $organization->id)->count());
    }

    public function test_non_base_currency_documents_are_rejected_before_any_posting(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 5, 'reserved_quantity_base' => 0, 'average_cost' => 40,
            'updated_at' => now(),
        ]);
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'SUP-CURRENCY',
            'name' => 'Currency test supplier', 'normalized_name' => 'currency test supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(TransactionPostingService::class);

        foreach ([
            'sale' => fn () => $service->postSale($organization->id, [
                'customer_id' => $customerId, 'location_id' => $locationId, 'currency' => 'USD',
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
                'idempotency_key' => 'sale-currency-' . Str::uuid(), 'created_by' => $user->id,
            ]),
            'purchase' => fn () => $service->postPurchase($organization->id, [
                'supplier_id' => $supplierId, 'location_id' => $locationId, 'currency' => 'USD',
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 40]],
                'idempotency_key' => 'purchase-currency-' . Str::uuid(), 'created_by' => $user->id,
            ]),
            'payment' => fn () => $service->postPayment($organization->id, [
                'party_type' => 'customer', 'party_id' => $customerId, 'direction' => 'inbound',
                'amount' => 10, 'currency' => 'USD', 'idempotency_key' => 'payment-currency-' . Str::uuid(),
            ]),
        ] as $type => $post) {
            try {
                $post();
                $this->fail("A {$type} in a currency other than the organization base currency must be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('currency', $exception->errors());
            }
        }

        $this->assertSame(0, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('purchase_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $organization->id)->count());
        $this->assertSame('5.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
    }

    public function test_payment_rejects_financial_account_in_a_different_currency(): void
    {
        [$organization, $user, , , $customerId] = $this->foundation();
        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId, 'organization_id' => $organization->id, 'code' => 'USD-CASH',
            'name' => 'USD cash account', 'type' => 'cash', 'currency' => 'USD',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $methodId, 'organization_id' => $organization->id, 'code' => 'CURRENCY-CASH',
            'name_ar' => 'Currency test method', 'requires_reference' => false,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(TransactionPostingService::class)->postPayment($organization->id, [
                'party_type' => 'customer', 'party_id' => $customerId, 'direction' => 'inbound',
                'amount' => 10, 'financial_account_id' => $accountId, 'payment_method_id' => $methodId,
                'idempotency_key' => 'foreign-account-currency-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('A payment cannot post into a financial account with a different currency.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_account_id', $exception->errors());
        }

        try {
            app(TransactionPostingService::class)->postExpense($organization->id, [
                'category' => 'Currency mismatch test', 'amount' => 10,
                'financial_account_id' => $accountId,
                'idempotency_key' => 'foreign-expense-account-currency-' . Str::uuid(),
                'created_by' => $user->id,
            ]);
            $this->fail('An expense cannot post from a financial account with a different currency.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_account_id', $exception->errors());
        }

        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('expenses')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $organization->id)->count());
    }

    public function test_unsupported_tax_and_purchase_discount_fail_closed_without_mutation(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 5, 'reserved_quantity_base' => 0, 'average_cost' => 40,
            'updated_at' => now(),
        ]);
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'SUP-ADJUSTMENTS',
            'name' => 'Adjustment policy supplier', 'normalized_name' => 'adjustment policy supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(TransactionPostingService::class);

        try {
            $service->postSale($organization->id, [
                'customer_id' => $customerId, 'location_id' => $locationId, 'tax' => 5,
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
                'idempotency_key' => 'sale-tax-policy-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('Sales tax must not post until a tax liability policy exists.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tax', $exception->errors());
        }

        try {
            $service->postPurchase($organization->id, [
                'supplier_id' => $supplierId, 'location_id' => $locationId, 'discount' => 10,
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 40]],
                'idempotency_key' => 'purchase-discount-policy-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('Purchase discount must not post until inventory valuation policy exists.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discount', $exception->errors());
        }

        try {
            $service->postPurchase($organization->id, [
                'supplier_id' => $supplierId, 'location_id' => $locationId, 'tax' => 5,
                'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 40]],
                'idempotency_key' => 'purchase-tax-policy-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('Purchase tax must not post until a tax-account policy exists.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tax', $exception->errors());
        }

        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 40]],
            'idempotency_key' => 'purchase-adjustment-source-' . Str::uuid(), 'created_by' => $user->id,
        ]);
        $purchaseItemId = DB::table('purchase_invoice_items')->where('purchase_invoice_id', $purchase['id'])->value('id');

        foreach ([['discount' => 1, 'field' => 'discount'], ['tax' => 1, 'field' => 'tax']] as $index => $adjustment) {
            $field = $adjustment['field'];
            try {
                $service->postPurchaseReturn($organization->id, [
                    'supplier_id' => $supplierId, 'location_id' => $locationId,
                    'original_purchase_invoice_id' => $purchase['id'], $field => $adjustment[$field],
                    'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 40, 'original_purchase_invoice_item_id' => $purchaseItemId]],
                    'idempotency_key' => 'purchase-return-adjustment-policy-' . $index . '-' . Str::uuid(), 'created_by' => $user->id,
                ]);
                $this->fail("Purchase return {$field} must not post until accounting policy exists.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertSame(0, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(1, DB::table('purchase_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('purchase_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame('6.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame(1, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertTransactionBalanced($organization->id, 'purchase_invoice', $purchase['id']);
    }

    public function test_returns_from_legacy_documents_with_header_adjustments_fail_closed(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 5, 'reserved_quantity_base' => 0, 'average_cost' => 40,
            'updated_at' => now(),
        ]);
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'SUP-LEGACY-ADJ',
            'name' => 'Legacy adjustment supplier', 'normalized_name' => 'legacy adjustment supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(TransactionPostingService::class);
        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId, 'location_id' => $locationId, 'discount' => 10,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
            'idempotency_key' => 'legacy-discounted-sale-' . Str::uuid(), 'created_by' => $user->id,
        ]);
        $saleItemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $sale['id'])->value('id');

        try {
            $service->postSalesReturn($organization->id, [
                'customer_id' => $customerId, 'location_id' => $locationId,
                'original_sales_invoice_id' => $sale['id'],
                'items' => [['product_id' => $productId, 'original_sales_invoice_item_id' => $saleItemId, 'quantity' => 1, 'unit_price' => 100]],
                'idempotency_key' => 'legacy-discounted-sale-return-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('A return must not credit the gross line price when the source invoice has a header discount.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('original_sales_invoice_id', $exception->errors());
        }

        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 40]],
            'idempotency_key' => 'legacy-adjusted-purchase-' . Str::uuid(), 'created_by' => $user->id,
        ]);
        $purchaseItemId = DB::table('purchase_invoice_items')->where('purchase_invoice_id', $purchase['id'])->value('id');
        DB::table('purchase_invoices')->where('id', $purchase['id'])->update(['discount' => 5]);

        try {
            $service->postPurchaseReturn($organization->id, [
                'supplier_id' => $supplierId, 'location_id' => $locationId,
                'original_purchase_invoice_id' => $purchase['id'],
                'items' => [['product_id' => $productId, 'original_purchase_invoice_item_id' => $purchaseItemId, 'quantity' => 1]],
                'idempotency_key' => 'legacy-adjusted-purchase-return-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('A return must not post against a legacy purchase invoice with a header discount.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('original_purchase_invoice_id', $exception->errors());
        }

        $this->assertSame(0, DB::table('sales_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('purchase_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame('5.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame(2, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
    }

    public function test_returns_exceeding_unpaid_document_balance_fail_closed(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 5, 'reserved_quantity_base' => 0, 'average_cost' => 40,
            'updated_at' => now(),
        ]);
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'SUP-PAID-RET',
            'name' => 'Paid invoice return supplier', 'normalized_name' => 'paid invoice return supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(TransactionPostingService::class);

        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
            'paid_amount' => 100, ...$this->salePaymentFields($organization->id),
            'idempotency_key' => 'fully-paid-sale-' . Str::uuid(), 'created_by' => $user->id,
        ]);
        $saleItemId = DB::table('sales_invoice_items')->where('sales_invoice_id', $sale['id'])->value('id');

        try {
            $service->postSalesReturn($organization->id, [
                'customer_id' => $customerId, 'location_id' => $locationId,
                'original_sales_invoice_id' => $sale['id'],
                'items' => [['product_id' => $productId, 'original_sales_invoice_item_id' => $saleItemId, 'quantity' => 1]],
                'idempotency_key' => 'fully-paid-sale-return-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('A return must not create negative AR before customer credit/refund policy exists.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('original_sales_invoice_id', $exception->errors());
        }

        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_cost' => 40]],
            'idempotency_key' => 'fully-paid-purchase-' . Str::uuid(), 'created_by' => $user->id,
        ]);
        $purchaseItemId = DB::table('purchase_invoice_items')->where('purchase_invoice_id', $purchase['id'])->value('id');

        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId, 'organization_id' => $organization->id, 'code' => 'PAID-RET-CASH',
            'name' => 'Paid return test cash', 'type' => 'cash', 'currency' => 'EGP',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $methodId, 'organization_id' => $organization->id, 'code' => 'PAID-RET-METHOD',
            'name_ar' => 'Paid return method', 'requires_reference' => false,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service->postPayment($organization->id, [
            'party_type' => 'supplier', 'party_id' => $supplierId, 'direction' => 'outbound',
            'amount' => 40, 'financial_account_id' => $accountId, 'payment_method_id' => $methodId,
            'allocations' => [['document_type' => 'purchase_invoice', 'document_id' => $purchase['id'], 'amount' => 40]],
            'idempotency_key' => 'fully-paid-purchase-payment-' . Str::uuid(), 'created_by' => $user->id,
        ]);

        try {
            $service->postPurchaseReturn($organization->id, [
                'supplier_id' => $supplierId, 'location_id' => $locationId,
                'original_purchase_invoice_id' => $purchase['id'],
                'items' => [['product_id' => $productId, 'original_purchase_invoice_item_id' => $purchaseItemId, 'quantity' => 1]],
                'idempotency_key' => 'fully-paid-purchase-return-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('A return must not create negative AP before supplier credit/refund policy exists.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('original_purchase_invoice_id', $exception->errors());
        }

        $this->assertSame(0, DB::table('sales_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('purchase_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame('5.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame(2, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('sales_invoices', ['id' => $sale['id'], 'balance_due' => '0.0000']);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $purchase['id'], 'paid_amount' => '40.0000']);
    }

    public function test_supplier_payment_allocation_uses_net_payable_after_purchase_returns(): void
    {
        [$organization, $user, $unitId, $productId, , $locationId] = $this->foundation();
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'SUP-NET-AP',
            'name' => 'Net payable supplier', 'normalized_name' => 'net payable supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(TransactionPostingService::class);
        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 2, 'unit_id' => $unitId, 'unit_cost' => 40]],
            'idempotency_key' => 'net-ap-purchase-' . Str::uuid(), 'created_by' => $user->id,
        ]);
        $purchaseItemId = DB::table('purchase_invoice_items')->where('purchase_invoice_id', $purchase['id'])->value('id');
        $service->postPurchaseReturn($organization->id, [
            'supplier_id' => $supplierId, 'location_id' => $locationId,
            'original_purchase_invoice_id' => $purchase['id'],
            'items' => [['product_id' => $productId, 'original_purchase_invoice_item_id' => $purchaseItemId, 'quantity' => 1]],
            'idempotency_key' => 'net-ap-return-' . Str::uuid(), 'created_by' => $user->id,
        ]);

        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId, 'organization_id' => $organization->id, 'code' => 'NET-AP-CASH',
            'name' => 'Net AP test cash', 'type' => 'cash', 'currency' => 'EGP',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $methodId, 'organization_id' => $organization->id, 'code' => 'NET-AP-METHOD',
            'name_ar' => 'Net AP test method', 'requires_reference' => false,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $service->postPayment($organization->id, [
                'party_type' => 'supplier', 'party_id' => $supplierId, 'direction' => 'outbound',
                'amount' => 50, 'financial_account_id' => $accountId, 'payment_method_id' => $methodId,
                'allocations' => [['document_type' => 'purchase_invoice', 'document_id' => $purchase['id'], 'amount' => 50]],
                'idempotency_key' => 'net-ap-overpay-' . Str::uuid(), 'created_by' => $user->id,
            ]);
            $this->fail('Supplier allocation must not exceed invoice total minus payments and posted purchase returns.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
        }

        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('payment_allocations')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('purchase_invoices', ['id' => $purchase['id'], 'paid_amount' => '0.0000']);
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $organization->id)->where('source_document_type', 'payment')->count());
        $this->assertSame('1.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
    }

    public function test_multi_invoice_payment_locks_documents_in_canonical_order(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 10, 'reserved_quantity_base' => 0, 'average_cost' => 40,
            'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $firstSale = $service->postSale($organization->id, [
            'customer_id' => $customerId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
            'paid_amount' => 0, 'idempotency_key' => 'lock-order-sale-a-'.$organization->id, 'created_by' => $user->id,
        ]);
        $secondSale = $service->postSale($organization->id, [
            'customer_id' => $customerId, 'location_id' => $locationId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
            'paid_amount' => 0, 'idempotency_key' => 'lock-order-sale-b-'.$organization->id, 'created_by' => $user->id,
        ]);

        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId, 'organization_id' => $organization->id, 'code' => 'LOCK-ORDER-CASH',
            'name' => 'Lock order cash', 'type' => 'cash', 'currency' => 'EGP', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $methodId, 'organization_id' => $organization->id, 'code' => 'LOCK-ORDER-METHOD',
            'name_ar' => 'Lock order method', 'requires_reference' => false, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoiceIds = [$firstSale['id'], $secondSale['id']];
        $canonicalIds = $invoiceIds;
        sort($canonicalIds, SORT_STRING);
        $lockedInvoiceIds = [];
        DB::listen(function (QueryExecuted $query) use (&$lockedInvoiceIds, $invoiceIds): void {
            if (! str_contains(strtolower($query->sql), 'sales_invoices')
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            foreach ($query->bindings as $binding) {
                $candidate = (string) $binding;
                if (in_array($candidate, $invoiceIds, true)) {
                    $lockedInvoiceIds[] = $candidate;
                    break;
                }
            }
        });

        $payment = $service->postPayment($organization->id, [
            'party_type' => 'customer', 'party_id' => $customerId, 'direction' => 'inbound',
            'amount' => 100, 'financial_account_id' => $accountId, 'payment_method_id' => $methodId,
            // Deliberately submit the invoices in reverse order; the lock acquisition order stays stable.
            'allocations' => [
                ['document_type' => 'sales_invoice', 'document_id' => $invoiceIds[1], 'amount' => 50],
                ['document_type' => 'sales_invoice', 'document_id' => $invoiceIds[0], 'amount' => 50],
            ],
            'idempotency_key' => 'lock-order-payment-'.$organization->id, 'created_by' => $user->id,
        ]);

        $this->assertSame($canonicalIds, array_slice($lockedInvoiceIds, 0, 2));
        $this->assertSame('50.0000', number_format((float) DB::table('sales_invoices')->where('id', $firstSale['id'])->value('paid_amount'), 4, '.', ''));
        $this->assertSame('50.0000', number_format((float) DB::table('sales_invoices')->where('id', $secondSale['id'])->value('paid_amount'), 4, '.', ''));
        $this->assertTransactionBalanced($organization->id, 'payment', $payment['id']);
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
            ...$this->salePaymentFields($organization->id),
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
            ...$this->salePaymentFields($organization->id),
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
            ...$this->salePaymentFields($organization->id),
            'created_by' => $user->id,
        ]);

        $saleIssueReceipt = DB::table('payments')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'sale_at_issue')
            ->where('source_document_id', $sale['id'])
            ->first();
        $this->assertNotNull($saleIssueReceipt, 'Amount collected at sale must be retained as a receipt.');
        $this->assertSame($tripId, $saleIssueReceipt->trip_id);
        $this->assertSame('50.0000', number_format((float) $saleIssueReceipt->amount, 4, '.', ''));
        $cashAtIssueQuery = DB::table('payments as p')
            ->join('financial_accounts as fa', function ($join): void {
                $join->on('fa.id', '=', 'p.financial_account_id')->on('fa.organization_id', '=', 'p.organization_id');
            })
            ->join('payment_methods as pm', function ($join) use ($organization): void {
                $join->on('pm.id', '=', 'p.payment_method_id')->where(function ($query) use ($organization): void {
                    $query->whereNull('pm.organization_id')->orWhere('pm.organization_id', $organization->id);
                });
            })
            ->where('p.organization_id', $organization->id)->where('p.trip_id', $tripId)
            ->where('p.source_document_type', 'sale_at_issue')->where('p.status', 'posted')
            ->whereRaw("LOWER(fa.type) = 'cash'")
            ->where(function ($query): void {
                $query->whereRaw("LOWER(pm.code) IN ('cash', 'cash_on_delivery', 'cash_payment')")
                    ->orWhereRaw("LOWER(pm.code) LIKE '%cash%'");
            })->sum('p.amount');
        $this->assertSame('50.0000', number_format((float) $cashAtIssueQuery, 4, '.', ''));

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

    public function test_trip_cash_settlement_excludes_bank_transfers_recorded_at_sale_and_later(): void
    {
        [$organization, $user, $unitId, $productId, $customerId, $locationId] = $this->foundation();

        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $locationId,
            'code' => 'NONCASH-SETTLE-VAN', 'name' => 'Non-cash settlement vehicle', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId, 'organization_id' => $organization->id, 'trip_number' => 'NONCASH-SETTLE-TRIP',
            'vehicle_id' => $vehicleId, 'rep_user_id' => $user->id, 'status' => 'loaded',
            'trip_date' => now()->toDateString(), 'origin_location_id' => $locationId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'product_id' => $productId,
            'location_id' => $locationId, 'quantity_base' => 10, 'reserved_quantity_base' => 0,
            'average_cost' => 40, 'updated_at' => now(),
        ]);

        $bankAccountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $bankAccountId, 'organization_id' => $organization->id, 'code' => 'BANK-NONCASH-TEST',
            'name' => 'Test bank account', 'type' => 'bank', 'currency' => 'EGP', 'opening_balance' => 0,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bankTransferMethodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $bankTransferMethodId, 'organization_id' => $organization->id, 'code' => 'bank_transfer',
            'name_ar' => 'تحويل بنكي اختبار', 'name_en' => 'Test bank transfer', 'requires_reference' => true,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $sale = $service->postSale($organization->id, [
            'customer_id' => $customerId, 'location_id' => $locationId, 'trip_id' => $tripId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'unit_id' => $unitId, 'unit_price' => 100]],
            'paid_amount' => 50, 'financial_account_id' => $bankAccountId,
            'payment_method_id' => $bankTransferMethodId, 'payment_reference' => 'BANK-ISSUE-TEST',
            'idempotency_key' => 'noncash-sale-issue-'.$tripId, 'created_by' => $user->id,
        ]);

        $service->postPayment($organization->id, [
            'party_type' => 'customer', 'party_id' => $customerId, 'direction' => 'inbound',
            'amount' => 30, 'financial_account_id' => $bankAccountId, 'payment_method_id' => $bankTransferMethodId,
            'reference' => 'BANK-LATER-TEST',
            'allocations' => [['document_type' => 'sales_invoice', 'document_id' => $sale['id'], 'amount' => 30]],
            'idempotency_key' => 'noncash-later-collection-'.$tripId, 'created_by' => $user->id,
        ]);

        $settlement = $service->settleTrip($organization->id, [
            'trip_id' => $tripId, 'opening_cash' => 0, 'actual_cash' => 0,
            'closing_items' => [['product_id' => $productId, 'quantity_base' => 9]],
            'settled_by' => $user->id, 'idempotency_key' => 'noncash-settlement-'.$tripId,
        ]);

        $this->assertSame('0.0000', number_format((float) $settlement['expected_cash'], 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $settlement['cash_variance'], 4, '.', ''));
        // Invoice paid_amount includes the later allocated 30 bank transfer,
        // while the cash custody total must still exclude both bank receipts.
        $this->assertDatabaseHas('sales_invoices', ['id' => $sale['id'], 'paid_amount' => '80.0000', 'balance_due' => '20.0000']);
        $this->assertSame(2, DB::table('payments')->where('organization_id', $organization->id)->where('party_id', $customerId)->count());
        $this->assertDatabaseHas('payments', ['source_document_type' => 'sale_at_issue', 'source_document_id' => $sale['id'], 'amount' => '50.0000']);
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
            ...$this->salePaymentFields($organization->id),
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

        DB::table('financial_accounts')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'code' => 'ACCOUNTING-TEST-CASH',
            'name' => 'Accounting test cash',
            'type' => 'cash',
            'currency' => 'EGP',
            'opening_balance' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_methods')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'code' => 'ACCOUNTING-TEST-CASH',
            'name_ar' => 'نقدي اختبار',
            'name_en' => 'Accounting test cash',
            'requires_reference' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organization, $user, $unitId, $productId, $customerId, $locationId];
    }

    private function salePaymentFields(string $organizationId): array
    {
        return [
            'financial_account_id' => DB::table('financial_accounts')
                ->where('organization_id', $organizationId)->where('code', 'ACCOUNTING-TEST-CASH')->value('id'),
            'payment_method_id' => DB::table('payment_methods')
                ->where('organization_id', $organizationId)->where('code', 'ACCOUNTING-TEST-CASH')->value('id'),
        ];
    }
}
