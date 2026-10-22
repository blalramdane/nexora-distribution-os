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

class EndToEndDistributionGateTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_distribution_flow_reconciles_stock_cash_and_customer_balance_end_to_end(): void
    {
        [$organization, $user, $supplierId, $customerId, $productId, $unitId, $warehouseId, $vehicleId, $financialAccountId, $paymentMethodId] = $this->foundation();

        DB::table('supplier_products')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'supplier_id' => $supplierId, 'product_id' => $productId,
            'supplier_sku' => 'E2E-VENDOR-SKU', 'supplier_name' => 'E2E Supplier Product',
            'preferred_packaging_id' => null, 'last_cost' => 50, 'last_purchase_at' => now(),
            'settlement_method' => 'sold_goods', 'settlement_unit_price' => 55,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $purchasePayload = [
            'supplier_id' => $supplierId,
            'location_id' => $warehouseId,
            'items' => [[
                'product_id' => $productId,
                'quantity' => 10,
                'conversion_factor' => 1,
                'unit_cost' => 50,
                'unit_id' => $unitId,
            ]],
            'idempotency_key' => 'e2e-purchase-001',
        ];

        $purchase = $this->postJson('/api/v1/purchases', $purchasePayload)
            ->assertCreated()
            ->json();

        $this->assertSame('500.0000', number_format((float) $purchase['total'], 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $purchase['payable_amount'], 4, '.', ''));
        $this->assertSame('10.000000', $this->stock($organization->id, $productId, $warehouseId));
        $purchaseItem = DB::table('purchase_invoice_items')->where('organization_id', $organization->id)->where('purchase_invoice_id', $purchase['id'])->first();
        $this->assertSame('sold_goods', $purchaseItem->settlement_method_snapshot);
        $this->assertSame('0.0000', number_format((float) $purchaseItem->payable_amount, 4, '.', ''));

        // A policy update applies to future invoices only; the posted invoice keeps its snapshot.
        $this->putJson('/api/v1/suppliers/'.$supplierId.'/products/'.$productId.'/settlement', [
            'settlement_method' => 'purchase_debt',
        ])->assertOk()->assertJsonPath('effective_for_future_purchases_only', true);
        $this->assertSame('sold_goods', DB::table('purchase_invoice_items')->where('id', $purchaseItem->id)->value('settlement_method_snapshot'));

        $adjustmentPayload = [
            'product_id' => $productId,
            'location_id' => $warehouseId,
            'quantity_delta' => 1,
            'reason' => 'Count correction',
            'idempotency_key' => 'e2e-adjustment-001',
        ];

        $adjustment = $this->postJson('/api/v1/inventory/adjust', $adjustmentPayload)
            ->assertCreated()
            ->json();
        $adjustmentReplay = $this->postJson('/api/v1/inventory/adjust', $adjustmentPayload)
            ->assertCreated()
            ->json();

        $this->assertSame($adjustment['id'], $adjustmentReplay['id']);
        $this->assertSame('11.000000', $this->stock($organization->id, $productId, $warehouseId));
        $this->assertSame(1, DB::table('stock_movements')->where('organization_id', $organization->id)->where('source_document_type', 'stock_adjustment')->where('source_document_id', $adjustment['id'])->count());

        $reversal = $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $productId,
            'location_id' => $warehouseId,
            'quantity_delta' => -1,
            'reason' => 'Correction reversal',
            'idempotency_key' => 'e2e-adjustment-002',
        ])->assertCreated()->json();

        $this->assertSame('-1.000000', number_format((float) $reversal['quantity_delta'], 6, '.', ''));
        $this->assertSame('10.000000', $this->stock($organization->id, $productId, $warehouseId));

        $catalogSku = (string) DB::table('products')->where('organization_id', $organization->id)->where('id', $productId)->value('sku');
        $catalog = $this->getJson('/api/v1/products?q='.urlencode($catalogSku))->assertOk()->json();
        $catalogProduct = collect($catalog)->firstWhere('id', $productId);
        $this->assertNotNull($catalogProduct);
        $this->assertSame(10.0, (float) $catalogProduct['quantity_base']);
        $lowStockCatalog = $this->getJson('/api/v1/products?q='.urlencode($catalogSku).'&location_id='.$warehouseId.'&stock_status=low&low_stock_threshold=11')->assertOk()->json();
        $this->assertSame($productId, collect($lowStockCatalog)->first()['id'] ?? null);

        $trip = $this->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'origin_location_id' => $warehouseId,
            'trip_date' => now()->toDateString(),
            'display_name' => 'رحلة اختبار صباحية',
        ])->assertCreated()->json();
        $this->assertSame('رحلة اختبار صباحية', $trip['display_name']);

        $renamedTrip = $this->patchJson('/api/v1/trips/'.$trip['id'], [
            'display_name' => 'توزيع السبت — الزرقا',
        ])->assertOk()->assertJsonPath('display_name', 'توزيع السبت — الزرقا');
        $this->assertSame('توزيع السبت — الزرقا', DB::table('trips')->where('organization_id', $organization->id)->where('id', $trip['id'])->value('display_name'));

        $customerAssignment = $this->postJson('/api/v1/trips/'.$trip['id'].'/customers', [
            'customer_id' => $customerId,
            'sequence' => 1,
        ])->assertOk()->json();

        $this->assertSame('assigned', $customerAssignment['status']);
        $this->assertDatabaseHas('trip_customers', [
            'organization_id' => $organization->id,
            'trip_id' => $trip['id'],
            'customer_id' => $customerId,
            'sequence' => 1,
        ]);

        $secondCustomerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $secondCustomerId,
            'organization_id' => $organization->id,
            'code' => 'CUS-E2E-2',
            'name' => 'E2E Customer 2',
            'normalized_name' => 'e2e customer 2',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondAssignment = $this->postJson('/api/v1/trips/'.$trip['id'].'/customers', [
            'customer_id' => $secondCustomerId,
        ])->assertOk()->json();

        $this->assertSame('assigned', $secondAssignment['status']);
        $this->assertSame(2, $secondAssignment['sequence']);
        $this->assertDatabaseHas('trip_customers', [
            'organization_id' => $organization->id,
            'trip_id' => $trip['id'],
            'customer_id' => $secondCustomerId,
            'sequence' => 2,
        ]);

        $loadPayload = [
            'trip_id' => $trip['id'],
            'from_location_id' => $warehouseId,
            'items' => [[
                'product_id' => $productId,
                'quantity_base' => 10,
            ]],
            'idempotency_key' => 'e2e-load-001',
        ];

        $load = $this->postJson('/api/v1/trip-loads', $loadPayload)
            ->assertCreated()
            ->json();

        $loadReplay = $this->postJson('/api/v1/trip-loads', $loadPayload)
            ->assertCreated()
            ->json();

        $this->assertSame($load['id'], $loadReplay['id']);
        $this->assertSame('0.000000', $this->stock($organization->id, $productId, $warehouseId));
        $this->assertSame(
            '10.000000',
            $this->stock($organization->id, $productId, DB::table('vehicles')->where('id', $vehicleId)->value('location_id'))
        );

        $salePayload = [
            'customer_id' => $customerId,
            'location_id' => DB::table('vehicles')->where('id', $vehicleId)->value('location_id'),
            'trip_id' => $trip['id'],
            'items' => [[
                'product_id' => $productId,
                'quantity' => 2,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'unit_id' => $unitId,
            ]],
            'paid_amount' => 80,
            'financial_account_id' => $financialAccountId,
            'payment_method_id' => $paymentMethodId,
            'payment_reference' => 'E2E-SALE-CASH-001',
            'idempotency_key' => 'e2e-sale-001',
        ];

        $sale = $this->postJson('/api/v1/sales', $salePayload)
            ->assertCreated()
            ->json();

        $saleIssueReceipt = DB::table('payments')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'sale_at_issue')
            ->where('source_document_id', $sale['id'])
            ->first();
        $this->assertNotNull($saleIssueReceipt, 'Cash collected while issuing a sale must create a real receipt.');
        $this->assertSame($trip['id'], $saleIssueReceipt->trip_id);
        $this->assertSame($financialAccountId, $saleIssueReceipt->financial_account_id);
        $this->assertSame($paymentMethodId, $saleIssueReceipt->payment_method_id);

        $saleReplay = $this->postJson('/api/v1/sales', $salePayload)
            ->assertCreated()
            ->json();

        $this->assertSame($sale['id'], $saleReplay['id']);
        $this->assertSame('200.0000', number_format((float) $sale['total'], 4, '.', ''));
        $this->assertSame('80.0000', number_format((float) $sale['paid_amount'], 4, '.', ''));
        $this->assertSame('120.0000', number_format((float) $sale['balance_due'], 4, '.', ''));
        $this->assertSame(
            '8.000000',
            $this->stock($organization->id, $productId, DB::table('vehicles')->where('id', $vehicleId)->value('location_id'))
        );

        $payment = $this->postJson('/api/v1/payments', [
            'party_type' => 'customer',
            'party_id' => $customerId,
            'financial_account_id' => $financialAccountId,
            'payment_method_id' => $paymentMethodId,
            'direction' => 'inbound',
            'amount' => 120,
            'trip_id' => $trip['id'],
            'allocations' => [[
                'document_type' => 'sales_invoice',
                'document_id' => $sale['id'],
                'amount' => 120,
            ]],
            'idempotency_key' => 'e2e-payment-001',
        ])->assertCreated()->json();

        $this->assertSame('120.0000', number_format((float) $payment['amount'], 4, '.', ''));

        $expensePayload = [
            'category' => 'Fuel',
            'amount' => 15,
            'financial_account_id' => $financialAccountId,
            'expense_date' => now()->toDateString(),
            'trip_id' => $trip['id'],
            'idempotency_key' => 'e2e-expense-001',
        ];

        $expense = $this->postJson('/api/v1/expenses', $expensePayload)
            ->assertCreated()
            ->json();
        $expenseReplay = $this->postJson('/api/v1/expenses', $expensePayload)
            ->assertCreated()
            ->json();

        $this->assertSame($expense['id'], $expenseReplay['id']);
        $this->assertSame('15.0000', number_format((float) $expense['amount'], 4, '.', ''));
        $this->assertSame(1, DB::table('expenses')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->count());
        $this->assertSame(1, DB::table('trip_expenses')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->where('expense_id', $expense['id'])->count());

        $this->getJson('/api/v1/expenses?trip_id='.$trip['id'])
            ->assertOk()
            ->assertJsonFragment(['id' => $expense['id'], 'category' => 'Fuel']);

        $profit = $this->getJson('/api/v1/trips/'.$trip['id'].'/profit')->assertOk();
        $this->assertSame(200.0, (float) $profit->json('sales_total'));
        $this->assertSame(100.0, (float) $profit->json('cost_of_goods_sold'));
        $this->assertSame(15.0, (float) $profit->json('trip_expenses'));
        $this->assertSame(85.0, (float) $profit->json('net_trip_profit'));

        $detailedTripReport = $this->getJson('/api/v1/trips/'.$trip['id'].'/report')->assertOk();
        $this->assertSame(500.0, (float) $detailedTripReport->json('summary.loaded_value'));
        $this->assertSame(200.0, (float) $detailedTripReport->json('summary.sales_total'));
        $this->assertSame(80.0, (float) $detailedTripReport->json('summary.sales_cash'));
        $this->assertSame(120.0, (float) $detailedTripReport->json('summary.credit_at_sale'));
        $this->assertSame(120.0, (float) $detailedTripReport->json('summary.collections_after_sale'));
        $this->assertSame(0.0, (float) $detailedTripReport->json('summary.receivables_open_on_trip_invoices'));
        $this->assertSame(185.0, (float) $detailedTripReport->json('summary.expected_cash'));

        $ownerWithdrawalPayload = [
            'transaction_type' => 'owner_withdrawal',
            'amount' => 25,
            'source_is_general_cash' => true,
            'transaction_date' => now()->toDateString(),
            'beneficiary_type' => 'owner',
            'beneficiary_name' => 'E2E Owner',
            'category' => 'Owner drawings',
            'reference' => 'OWNER-DRAW-E2E-01',
            'idempotency_key' => 'e2e-cashbox-owner-draw-001',
        ];
        $ownerWithdrawal = $this->postJson('/api/v1/cashbox', $ownerWithdrawalPayload)->assertCreated()->json();
        $ownerWithdrawalReplay = $this->postJson('/api/v1/cashbox', $ownerWithdrawalPayload)->assertCreated()->json();
        $this->assertSame($ownerWithdrawal['id'], $ownerWithdrawalReplay['id']);
        $this->assertSame('outbound', $ownerWithdrawal['direction']);
        $this->assertSame(1, DB::table('cashbox_transactions')->where('organization_id', $organization->id)->where('idempotency_key', 'e2e-cashbox-owner-draw-001')->count());

        $cashboxReport = $this->getJson('/api/v1/cashbox?date_from='.now()->toDateString().'&date_to='.now()->toDateString())->assertOk();
        $this->assertSame(80.0, (float) $cashboxReport->json('summary.sales_cash'));
        $this->assertSame(120.0, (float) $cashboxReport->json('summary.customer_collections'));
        $this->assertSame(25.0, (float) $cashboxReport->json('summary.owner_withdrawals'));
        $this->assertSame(15.0, (float) $cashboxReport->json('summary.expenses'));
        $this->assertSame(160.0, (float) $cashboxReport->json('summary.net_cash_flow'));
        $this->assertTrue((bool) $cashboxReport->json('notes.credit_sales_are_receivables_not_cash'));

        $bankAccountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $bankAccountId, 'organization_id' => $organization->id, 'code' => 'E2E-CASHBOX-BANK',
            'name' => 'E2E Cashbox Bank', 'type' => 'bank', 'currency' => 'EGP', 'opening_balance' => 0,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $transferPayload = [
            'transaction_type' => 'account_transfer',
            'amount' => 10,
            'source_is_general_cash' => true,
            'destination_financial_account_id' => $bankAccountId,
            'transaction_date' => now()->toDateString(),
            'category' => 'Cash deposited to bank',
            'reference' => 'E2E-CASH-DEPOSIT-01',
            'idempotency_key' => 'e2e-cashbox-transfer-001',
        ];
        $transfer = $this->postJson('/api/v1/cashbox', $transferPayload)->assertCreated()->json();
        $transferReplay = $this->postJson('/api/v1/cashbox', $transferPayload)->assertCreated()->json();
        $this->assertSame($transfer['id'], $transferReplay['id']);
        $this->assertSame('transfer', $transfer['direction']);
        $this->assertSame(1, DB::table('cashbox_transactions')->where('organization_id', $organization->id)->where('idempotency_key', 'e2e-cashbox-transfer-001')->count());

        $salaryPayload = [
            'transaction_type' => 'salary_payment',
            'amount' => 40,
            'source_is_general_cash' => true,
            'transaction_date' => now()->toDateString(),
            'beneficiary_type' => 'driver',
            'beneficiary_name' => 'E2E Driver',
            'category' => 'Driver salary',
            'reference' => 'E2E-SALARY-01',
            'idempotency_key' => 'e2e-cashbox-salary-001',
        ];
        $salary = $this->postJson('/api/v1/cashbox', $salaryPayload)->assertCreated()->json();
        $this->assertSame('salary_payment', $salary['transaction_type']);
        $this->assertSame('E2E Driver', $salary['beneficiary_name']);
        $this->assertSame(2, DB::table('ledger_entries')->where('organization_id', $organization->id)->where('source_document_type', 'cashbox_transaction')->where('source_document_id', $salary['id'])->count());
        $salaryDebits = (float) DB::table('ledger_entries')->where('organization_id', $organization->id)->where('source_document_type', 'cashbox_transaction')->where('source_document_id', $salary['id'])->sum('debit');
        $salaryCredits = (float) DB::table('ledger_entries')->where('organization_id', $organization->id)->where('source_document_type', 'cashbox_transaction')->where('source_document_id', $salary['id'])->sum('credit');
        $this->assertSame(40.0, $salaryDebits);
        $this->assertSame(40.0, $salaryCredits);

        $accountReport = $this->getJson('/api/v1/cashbox?date_from='.now()->toDateString().'&date_to='.now()->toDateString().'&financial_account_id='.$bankAccountId)->assertOk();
        $this->assertSame(10.0, (float) collect($accountReport->json('movements'))->where('id', 'cashbox-'.$transfer['id'])->first()['amount']);

        $this->postJson('/api/v1/expenses', array_merge($expensePayload, ['amount' => 16]))
            ->assertUnprocessable();
        $this->assertSame(1, DB::table('expenses')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->count());

        $supplierPreview = $this->getJson('/api/v1/trips/'.$trip['id'].'/supplier-settlement')->assertOk()->json();
        $supplierPreviewRow = collect($supplierPreview['suppliers'])->firstWhere('supplier_id', $supplierId);
        $this->assertNotNull($supplierPreviewRow, 'Trip sell-through must be attributed to the source purchase supplier.');
        $this->assertSame(2.0, (float) $supplierPreviewRow['sold_quantity_base']);
        $this->assertSame(2.0, (float) $supplierPreviewRow['net_quantity_base']);
        $this->assertSame(110.0, (float) $supplierPreviewRow['settlement_amount']);

        $settlementPayload = [
            'trip_id' => $trip['id'],
            'idempotency_key' => 'e2e-settlement-'.$trip['id'],
            'opening_cash' => 0,
            'actual_cash' => 185,
            'closing_items' => [[
                'product_id' => $productId,
                'quantity_base' => 8,
            ]],
        ];
        $settlement = $this->postJson('/api/v1/trip-settlements', $settlementPayload)->assertCreated()->json();
        $settlementReplay = $this->postJson('/api/v1/trip-settlements', $settlementPayload)->assertCreated()->json();
        $this->assertSame($settlement['id'], $settlementReplay['id']);
        $this->assertSame(110.0, (float) collect($settlement['supplier_settlements'])->firstWhere('supplier_id', $supplierId)['settlement_amount']);
        $this->assertSame(110.0, (float) collect($settlementReplay['supplier_settlements'])->firstWhere('supplier_id', $supplierId)['settlement_amount']);
        $this->assertSame(100.0, (float) collect($settlement['supplier_settlements'])->firstWhere('supplier_id', $supplierId)['deferred_source_cost']);
        $this->assertSame(1, DB::table('trip_supplier_settlements')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->where('supplier_id', $supplierId)->count());
        $savedSupplierPreview = $this->getJson('/api/v1/trips/'.$trip['id'].'/supplier-settlement')->assertOk()->json();
        $this->assertTrue($savedSupplierPreview['snapshot']);
        $supplierSettlementId = collect($settlement['supplier_settlements'])->firstWhere('supplier_id', $supplierId)['id'];

        $supplierPayment = $this->postJson('/api/v1/payments', [
            'party_type' => 'supplier',
            'party_id' => $supplierId,
            'financial_account_id' => $financialAccountId,
            'payment_method_id' => $paymentMethodId,
            'direction' => 'outbound',
            'amount' => 40,
            'trip_supplier_settlement_id' => $supplierSettlementId,
            'reference' => 'TRIP-SETTLEMENT-PARTIAL',
            'idempotency_key' => 'e2e-supplier-settlement-payment-001',
        ])->assertCreated()->json();
        $this->assertSame('40.0000', number_format((float) $supplierPayment['amount'], 4, '.', ''));
        $this->assertSame($supplierSettlementId, DB::table('payments')->where('organization_id', $organization->id)->where('id', $supplierPayment['id'])->value('trip_supplier_settlement_id'));

        $supplierTripSettlements = $this->getJson('/api/v1/suppliers/'.$supplierId.'/trip-settlements')->assertOk()->json();
        $supplierTripRow = collect($supplierTripSettlements)->firstWhere('id', $supplierSettlementId);
        $this->assertSame(40.0, (float) $supplierTripRow['paid_amount']);
        $this->assertSame(70.0, (float) $supplierTripRow['outstanding_amount']);

        // Historical source-cost rows are audit-only after adopting the new
        // supplier policy, because the original purchase invoice may already
        // contain this liability. They must never be selectable for a second payment.
        $legacySupplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $legacySupplierId, 'organization_id' => $organization->id,
            'code' => 'SUP-LEGACY-E2E', 'name' => 'E2E Legacy Supplier',
            'normalized_name' => 'e2e legacy supplier', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacySupplierSettlementId = (string) Str::ulid();
        DB::table('trip_supplier_settlements')->insert([
            'id' => $legacySupplierSettlementId, 'organization_id' => $organization->id,
            'trip_id' => $trip['id'], 'trip_settlement_id' => $settlement['id'],
            'supplier_id' => $legacySupplierId, 'supplier_name_snapshot' => 'E2E Legacy Supplier',
            'sold_quantity_base' => 1, 'returned_quantity_base' => 0, 'net_quantity_base' => 1,
            'sold_source_cost' => 50, 'returned_source_cost' => 0, 'settlement_amount' => 50,
            'deferred_source_cost' => 0, 'settlement_basis' => 'legacy_source_cost',
            'items_json' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/payments', [
            'party_type' => 'supplier', 'party_id' => $legacySupplierId,
            'financial_account_id' => $financialAccountId, 'payment_method_id' => $paymentMethodId,
            'direction' => 'outbound', 'amount' => 10,
            'trip_supplier_settlement_id' => $legacySupplierSettlementId,
            'idempotency_key' => 'e2e-legacy-supplier-settlement-payment-rejected',
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->where('trip_supplier_settlement_id', $legacySupplierSettlementId)->count());

        $this->postJson('/api/v1/payments', [
            'party_type' => 'supplier',
            'party_id' => $supplierId,
            'financial_account_id' => $financialAccountId,
            'payment_method_id' => $paymentMethodId,
            'direction' => 'outbound',
            'amount' => 75,
            'trip_supplier_settlement_id' => $supplierSettlementId,
            'idempotency_key' => 'e2e-supplier-settlement-overpayment-rejected',
        ])->assertUnprocessable();
        $this->assertSame(1, DB::table('payments')->where('organization_id', $organization->id)->where('trip_supplier_settlement_id', $supplierSettlementId)->count());

        $this->assertSame('185.0000', number_format((float) $settlement['expected_cash'], 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $settlement['cash_variance'], 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $settlement['stock_variance_value'], 4, '.', ''));

        $this->assertTransactionBalanced($organization->id, 'expense', $expense['id']);
        $this->postJson('/api/v1/expenses', array_merge($expensePayload, ['idempotency_key' => 'e2e-expense-after-close']))
            ->assertUnprocessable();
        $this->assertSame(1, DB::table('expenses')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->count());

        $customerSummary = DB::table('customer_balance_summaries')
            ->where('organization_id', $organization->id)
            ->where('customer_id', $customerId)
            ->first();

        $this->assertSame('200.0000', number_format((float) $customerSummary->total_sales, 4, '.', ''));
        $this->assertSame('200.0000', number_format((float) $customerSummary->total_paid, 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $customerSummary->outstanding, 4, '.', ''));

        $this->assertSame('completed', DB::table('trips')->where('id', $trip['id'])->value('status'));
        $this->assertSame(1, DB::table('sales_invoices')->where('organization_id', $organization->id)->where('id', $sale['id'])->count());
        $this->assertSame(1, DB::table('payments')->where('organization_id', $organization->id)->where('id', $payment['id'])->count());

        $this->assertTransactionBalanced($organization->id, 'purchase_invoice', $purchase['id']);
        $this->assertTransactionBalanced($organization->id, 'sales_invoice', $sale['id']);
        $this->assertTransactionBalanced($organization->id, 'payment', $payment['id']);
        $this->assertStockBalancesReconcileToMovements($organization->id);
        $this->assertEveryLedgerSourceIsBalanced($organization->id);

        $sourceTrace = $this->getJson('/api/v1/purchases/source-trace')->assertOk()->json();
        $purchaseTrace = collect($sourceTrace['invoices'])->firstWhere('purchase_invoice_id', $purchase['id']);
        $this->assertNotNull($purchaseTrace, 'A posted purchase must remain available in the supplier source trace.');
        $this->assertSame(0.0, (float) $purchaseTrace['remaining_payable']);
        $sourceLine = collect($purchaseTrace['items'])->firstWhere('product_id', $productId);
        $this->assertNotNull($sourceLine);
        $this->assertSame(2.0, (float) $sourceLine['net_sold_quantity_base']);
        $this->assertSame(7.0, (float) $sourceLine['remaining_stock_quantity_base']);
        $this->assertSame(1, count($sourceLine['remaining_by_location']));
        $this->assertCount(1, $sourceLine['sales']);
        $this->assertSame($sale['id'], $sourceLine['sales'][0]['sales_invoice_id']);
        $this->assertSame($sale['document_number'], $sourceLine['sales'][0]['document_number']);
        $this->assertSame($trip['trip_number'], $sourceLine['sales'][0]['trip_number']);
        $this->assertSame(2.0, (float) $sourceLine['sales'][0]['net_quantity_base']);
        $this->assertSame(200.0, (float) $sourceLine['sales'][0]['net_sales_value']);

        // Archiving a trip hides it from active operations while retaining its
        // accounting/inventory history, and the operation is reversible.
        $this->deleteJson('/api/v1/trips/'.$trip['id'])
            ->assertOk()->assertJsonPath('status', 'archived');
        $this->assertDatabaseHas('trips', ['organization_id' => $organization->id, 'id' => $trip['id']]);
        $this->assertDatabaseHas('sales_invoices', ['organization_id' => $organization->id, 'id' => $sale['id']]);
        $this->postJson('/api/v1/trips/'.$trip['id'].'/restore')
            ->assertOk()->assertJsonPath('status', 'active');
        $emptyTrip = $this->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'origin_location_id' => $warehouseId,
            'display_name' => 'رحلة تجريبية فارغة',
        ])->assertCreated()->json();
        $secondEmptyTrip = $this->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'origin_location_id' => $warehouseId,
            'display_name' => 'رحلة تجريبية فارغة أخرى',
        ])->assertCreated()->json();
        $this->deleteJson('/api/v1/trips/cleanup-empty', ['confirm' => true])
            ->assertOk()->assertJsonPath('deleted_count', 2)->assertJsonPath('skipped_count', 0);
        $this->assertDatabaseMissing('trips', ['organization_id' => $organization->id, 'id' => $emptyTrip['id']]);
        $this->assertDatabaseMissing('trips', ['organization_id' => $organization->id, 'id' => $secondEmptyTrip['id']]);

        $this->deleteJson('/api/v1/trips/archive-all', ['confirm' => true])
            ->assertOk()->assertJsonPath('archived_count', 1);
        $this->getJson('/api/v1/trips')->assertOk()->assertExactJson([]);
        $archived = $this->getJson('/api/v1/trips?archived_only=true')->assertOk()->json();
        $this->assertCount(1, $archived);
        $this->assertSame($trip['id'], $archived[0]['id']);
        $this->assertDatabaseHas('sales_invoices', ['organization_id' => $organization->id, 'id' => $sale['id']]);
        $this->postJson('/api/v1/trips/'.$trip['id'].'/restore')->assertOk();
        $this->getJson('/api/v1/trips')->assertOk()->assertJsonCount(1);
    }

    public function test_fifo_source_trace_survives_vehicle_transfer_sale_and_sales_return(): void
    {
        [$organization, $user, $supplierId, $customerId, $productId, $unitId, $warehouseId, $vehicleId] = array_slice($this->foundation(), 0, 8);
        Sanctum::actingAs($user);

        $firstPurchase = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity' => 5, 'conversion_factor' => 1, 'unit_cost' => 40, 'unit_id' => $unitId]],
            'idempotency_key' => 'fifo-source-first-purchase',
        ])->assertCreated()->json();
        $secondPurchase = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity' => 5, 'conversion_factor' => 1, 'unit_cost' => 60, 'unit_id' => $unitId]],
            'idempotency_key' => 'fifo-source-second-purchase',
        ])->assertCreated()->json();

        $firstPurchaseItemId = (string) DB::table('purchase_invoice_items')->where('organization_id', $organization->id)->where('purchase_invoice_id', $firstPurchase['id'])->value('id');
        $secondPurchaseItemId = (string) DB::table('purchase_invoice_items')->where('organization_id', $organization->id)->where('purchase_invoice_id', $secondPurchase['id'])->value('id');

        $trip = $this->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'origin_location_id' => $warehouseId,
            'trip_date' => now()->toDateString(),
        ])->assertCreated()->json();
        $load = $this->postJson('/api/v1/trip-loads', [
            'trip_id' => $trip['id'],
            'from_location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity_base' => 7]],
            'idempotency_key' => 'fifo-source-load-seven',
        ])->assertCreated()->json();

        $transferQuantities = DB::table('stock_source_allocations')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'trip_load')
            ->where('source_document_id', $load['id'])
            ->where('allocation_type', 'transfer')
            ->select('source_purchase_invoice_item_id')
            ->selectRaw('SUM(quantity_base) as quantity_base')
            ->groupBy('source_purchase_invoice_item_id')
            ->pluck('quantity_base', 'source_purchase_invoice_item_id');
        $this->assertSame('5.000000', number_format((float) $transferQuantities[$firstPurchaseItemId], 6, '.', ''));
        $this->assertSame('2.000000', number_format((float) $transferQuantities[$secondPurchaseItemId], 6, '.', ''));

        $vehicleLocationId = (string) DB::table('vehicles')->where('organization_id', $organization->id)->where('id', $vehicleId)->value('location_id');
        $sale = $this->postJson('/api/v1/sales', [
            'customer_id' => $customerId,
            'location_id' => $vehicleLocationId,
            'trip_id' => $trip['id'],
            'items' => [['product_id' => $productId, 'quantity' => 2, 'conversion_factor' => 1, 'unit_price' => 100, 'unit_id' => $unitId]],
            'idempotency_key' => 'fifo-source-sale-one',
        ])->assertCreated()->json();
        $saleItemId = (string) DB::table('sales_invoice_items')->where('organization_id', $organization->id)->where('sales_invoice_id', $sale['id'])->value('id');
        $saleAllocation = DB::table('stock_source_allocations')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'sales_invoice')
            ->where('source_document_id', $sale['id'])
            ->where('source_document_item_id', $saleItemId)
            ->where('allocation_type', 'sale')
            ->first();
        $this->assertNotNull($saleAllocation);
        $this->assertSame($firstPurchaseItemId, $saleAllocation->source_purchase_invoice_item_id);
        $this->assertSame('2.000000', number_format((float) $saleAllocation->quantity_base, 6, '.', ''));

        $returnPayload = [
            'customer_id' => $customerId,
            'location_id' => $vehicleLocationId,
            'original_sales_invoice_id' => $sale['id'],
            'items' => [['product_id' => $productId, 'quantity' => 1, 'conversion_factor' => 1, 'unit_price' => 100, 'original_sales_invoice_item_id' => $saleItemId]],
            'idempotency_key' => 'fifo-source-sales-return-one',
        ];
        $return = $this->postJson('/api/v1/returns/sales', $returnPayload)->assertCreated()->json();

        $returnAllocation = DB::table('stock_source_allocations')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'sales_return')
            ->where('source_document_id', $return['id'])
            ->where('allocation_type', 'sales_return')
            ->first();
        $this->assertNotNull($returnAllocation);
        $this->assertSame($saleAllocation->id, $returnAllocation->reverses_allocation_id);
        $this->assertSame($firstPurchaseItemId, $returnAllocation->source_purchase_invoice_item_id);
        $this->assertSame('1.000000', number_format((float) $returnAllocation->quantity_base, 6, '.', ''));

        $secondReturnPayload = array_merge($returnPayload, ['idempotency_key' => 'fifo-source-sales-return-two']);
        $secondReturn = $this->postJson('/api/v1/returns/sales', $secondReturnPayload)->assertCreated()->json();
        $secondReturnAllocation = DB::table('stock_source_allocations')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'sales_return')
            ->where('source_document_id', $secondReturn['id'])
            ->where('allocation_type', 'sales_return')
            ->first();
        $this->assertNotNull($secondReturnAllocation);
        $this->assertSame($saleAllocation->id, $secondReturnAllocation->reverses_allocation_id);
        $this->assertSame($firstPurchaseItemId, $secondReturnAllocation->source_purchase_invoice_item_id);
        $this->assertSame('1.000000', number_format((float) $secondReturnAllocation->quantity_base, 6, '.', ''));
        $this->assertSame('0.0000', number_format((float) DB::table('sales_invoices')->where('organization_id', $organization->id)->where('id', $sale['id'])->value('balance_due'), 4, '.', ''));

        $trace = $this->getJson('/api/v1/purchases/source-trace')->assertOk()->json();
        $firstLine = collect(collect($trace['invoices'])->firstWhere('purchase_invoice_id', $firstPurchase['id'])['items'])->firstWhere('purchase_invoice_item_id', $firstPurchaseItemId);
        $secondLine = collect(collect($trace['invoices'])->firstWhere('purchase_invoice_id', $secondPurchase['id'])['items'])->firstWhere('purchase_invoice_item_id', $secondPurchaseItemId);
        $filteredTrace = $this->getJson('/api/v1/purchases/source-trace?q='.$firstPurchase['document_number'].'&per_page=10')->assertOk()->json();
        $this->assertSame(1, $filteredTrace['pagination']['total']);
        $this->assertSame($firstPurchase['id'], $filteredTrace['invoices'][0]['purchase_invoice_id']);
        $this->assertSame(0.0, (float) $firstLine['net_sold_quantity_base']);
        $this->assertSame(5.0, (float) $firstLine['remaining_stock_quantity_base']);
        $this->assertSame(5.0, (float) $secondLine['remaining_stock_quantity_base']);
        $this->assertSame(0.0, (float) $firstLine['sales'][0]['net_quantity_base']);
        $this->assertSame(200.0, (float) $firstLine['sales'][0]['sourced_sales_value']);
        $this->assertSame(0.0, (float) $firstLine['sales'][0]['net_sales_value']);

        $this->assertStockBalancesReconcileToMovements($organization->id);
        $this->assertEveryLedgerSourceIsBalanced($organization->id);
    }

    private function stock(string $organizationId, string $productId, string $locationId): string
    {
        return number_format(
            (float) (DB::table('stock_balances')
                ->where('organization_id', $organizationId)
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->value('quantity_base') ?? 0),
            6,
            '.',
            ''
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

    private function assertStockBalancesReconcileToMovements(string $organizationId): void
    {
        $balances = DB::table('stock_balances')
            ->where('organization_id', $organizationId)
            ->get(['product_id', 'location_id', 'quantity_base'])
            ->mapWithKeys(fn ($row) => [$row->product_id.'|'.$row->location_id => (string) $row->quantity_base]);

        $movements = DB::table('stock_movements')
            ->where('organization_id', $organizationId)
            ->select('product_id', 'location_id')
            ->selectRaw('COALESCE(SUM(quantity_base), 0) as quantity_base')
            ->groupBy('product_id', 'location_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->product_id.'|'.$row->location_id => (string) $row->quantity_base]);

        foreach ($balances->keys()->merge($movements->keys())->unique() as $key) {
            $balance = number_format((float) ($balances->get($key) ?? 0), 6, '.', '');
            $movementTotal = number_format((float) ($movements->get($key) ?? 0), 6, '.', '');
            $this->assertSame($movementTotal, $balance, "Stock balance differs from movement ledger for {$key}");
        }
    }

    private function assertEveryLedgerSourceIsBalanced(string $organizationId): void
    {
        $transactions = DB::table('ledger_entries')
            ->where('organization_id', $organizationId)
            ->select('transaction_uuid')
            ->selectRaw('COALESCE(SUM(debit), 0) as debits, COALESCE(SUM(credit), 0) as credits')
            ->groupBy('transaction_uuid')
            ->get();

        foreach ($transactions as $transaction) {
            $this->assertSame(
                number_format((float) $transaction->debits, 4, '.', ''),
                number_format((float) $transaction->credits, 4, '.', ''),
                "Unbalanced ledger transaction UUID: {$transaction->transaction_uuid}"
            );
        }

        $sources = DB::table('ledger_entries')
            ->where('organization_id', $organizationId)
            ->select('source_document_type', 'source_document_id')
            ->selectRaw('COALESCE(SUM(debit), 0) as debits, COALESCE(SUM(credit), 0) as credits')
            ->groupBy('source_document_type', 'source_document_id')
            ->get();

        $this->assertNotEmpty($sources, 'The distribution flow should produce ledger entries.');
        foreach ($sources as $source) {
            $this->assertSame(
                number_format((float) $source->debits, 4, '.', ''),
                number_format((float) $source->credits, 4, '.', ''),
                "Unbalanced ledger source: {$source->source_document_type}:{$source->source_document_id}"
            );
        }

        // Checking only existing ledger rows misses the case where a posted
        // business document accidentally produced no journal entry at all.
        $requiredSources = [
            ['purchase_invoices', 'purchase_invoice'],
            ['sales_invoices', 'sales_invoice'],
            ['purchase_returns', 'purchase_return'],
            ['sales_returns', 'sales_return'],
            ['payments', 'payment'],
            ['expenses', 'expense'],
            ['cashbox_transactions', 'cashbox_transaction'],
        ];

        foreach ($requiredSources as [$table, $sourceType]) {
            $missing = DB::table($table.' as d')
                ->where('d.organization_id', $organizationId)
                ->where('d.status', 'posted')
                ->whereNotExists(function ($query) use ($sourceType): void {
                    $query->select(DB::raw(1))
                        ->from('ledger_entries as l')
                        ->whereColumn('l.organization_id', 'd.organization_id')
                        ->whereColumn('l.source_document_id', 'd.id')
                        ->where('l.source_document_type', $sourceType);
                })
                ->count();

            $this->assertSame(0, $missing, "Posted {$table} documents are missing source-linked ledger entries.");
        }
    }

    public function test_sale_fails_closed_when_source_layers_exceed_the_authoritative_stock_balance(): void
    {
        [$organization, $user, $supplierId, $customerId, $productId, $unitId, $warehouseId] = array_slice($this->foundation(), 0, 7);
        Sanctum::actingAs($user);

        $purchase = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity' => 5, 'conversion_factor' => 1, 'unit_cost' => 40, 'unit_id' => $unitId]],
            'idempotency_key' => 'source-layer-guard-purchase',
        ])->assertCreated()->json();

        $layer = DB::table('stock_source_layers')
            ->where('organization_id', $organization->id)
            ->where('source_purchase_invoice_id', $purchase['id'])
            ->first();
        $this->assertNotNull($layer);
        DB::table('stock_source_layers')->where('organization_id', $organization->id)->where('id', $layer->id)
            ->update(['quantity_remaining_base' => '6.000000']);

        $movementCount = DB::table('stock_movements')->where('organization_id', $organization->id)->count();
        $allocationCount = DB::table('stock_source_allocations')->where('organization_id', $organization->id)->count();
        $salePayload = [
            'customer_id' => $customerId,
            'location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity' => 1, 'conversion_factor' => 1, 'unit_price' => 100, 'unit_id' => $unitId]],
            'idempotency_key' => 'source-layer-guard-sale',
        ];

        $this->postJson('/api/v1/sales', $salePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('inventory');

        $this->assertSame('5.000000', $this->stock($organization->id, $productId, $warehouseId));
        $this->assertSame($movementCount, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertSame($allocationCount, DB::table('stock_source_allocations')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
    }

    public function test_sales_return_fails_closed_when_source_allocations_do_not_cover_the_return_quantity(): void
    {
        [$organization, $user, $supplierId, $customerId, $productId, $unitId, $warehouseId] = array_slice($this->foundation(), 0, 7);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity' => 5, 'conversion_factor' => 1, 'unit_cost' => 40, 'unit_id' => $unitId]],
            'idempotency_key' => 'source-return-guard-purchase',
        ])->assertCreated();

        $sale = $this->postJson('/api/v1/sales', [
            'customer_id' => $customerId,
            'location_id' => $warehouseId,
            'items' => [['product_id' => $productId, 'quantity' => 2, 'conversion_factor' => 1, 'unit_price' => 100, 'unit_id' => $unitId]],
            'idempotency_key' => 'source-return-guard-sale',
        ])->assertCreated()->json();
        $saleItemId = (string) DB::table('sales_invoice_items')
            ->where('organization_id', $organization->id)
            ->where('sales_invoice_id', $sale['id'])
            ->value('id');
        $saleAllocation = DB::table('stock_source_allocations')
            ->where('organization_id', $organization->id)
            ->where('source_document_type', 'sales_invoice')
            ->where('source_document_id', $sale['id'])
            ->where('source_document_item_id', $saleItemId)
            ->where('allocation_type', 'sale')
            ->first();

        $this->assertNotNull($saleAllocation);
        // Simulate a damaged source trace without changing the authoritative stock movement/balance.
        DB::table('stock_source_allocations')
            ->where('organization_id', $organization->id)
            ->where('id', $saleAllocation->id)
            ->update(['quantity_base' => '1.000000']);

        $before = [
            'stock' => $this->stock($organization->id, $productId, $warehouseId),
            'movements' => DB::table('stock_movements')->where('organization_id', $organization->id)->count(),
            'allocations' => DB::table('stock_source_allocations')->where('organization_id', $organization->id)->count(),
            'layers' => DB::table('stock_source_layers')->where('organization_id', $organization->id)->count(),
            'ledger' => DB::table('ledger_entries')->where('organization_id', $organization->id)->count(),
            'balance_due' => (string) DB::table('sales_invoices')->where('organization_id', $organization->id)->where('id', $sale['id'])->value('balance_due'),
        ];

        $this->postJson('/api/v1/returns/sales', [
            'customer_id' => $customerId,
            'location_id' => $warehouseId,
            'original_sales_invoice_id' => $sale['id'],
            'items' => [[
                'product_id' => $productId,
                'quantity' => 2,
                'conversion_factor' => 1,
                'unit_price' => 100,
                'original_sales_invoice_item_id' => $saleItemId,
            ]],
            'idempotency_key' => 'source-return-guard-return',
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(0, DB::table('sales_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('sales_return_items')->where('organization_id', $organization->id)->count());
        $this->assertSame($before['stock'], $this->stock($organization->id, $productId, $warehouseId));
        $this->assertSame($before['movements'], DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertSame($before['allocations'], DB::table('stock_source_allocations')->where('organization_id', $organization->id)->count());
        $this->assertSame($before['layers'], DB::table('stock_source_layers')->where('organization_id', $organization->id)->count());
        $this->assertSame($before['ledger'], DB::table('ledger_entries')->where('organization_id', $organization->id)->count());
        $this->assertSame($before['balance_due'], (string) DB::table('sales_invoices')->where('organization_id', $organization->id)->where('id', $sale['id'])->value('balance_due'));
    }

    private function foundation(): array
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA E2E Distribution',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'E2E Distribution User',
            'email' => 'e2e-'.$organization->id.'@nexora.test',
            'phone' => '01000000004',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

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
            'sku' => 'E2E-'.$organization->id,
            'name_ar' => 'E2E Product',
            'default_cost' => 50,
            'default_piece_price' => 100,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'organization_id' => $organization->id,
            'code' => 'SUP-E2E',
            'name' => 'E2E Supplier',
            'normalized_name' => 'e2e supplier',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'organization_id' => $organization->id,
            'code' => 'CUS-E2E',
            'name' => 'E2E Customer',
            'normalized_name' => 'e2e customer',
            'credit_enabled' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $warehouseId,
            'organization_id' => $organization->id,
            'code' => 'WH-E2E',
            'name' => 'E2E Warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $vehicleLocationId,
            'organization_id' => $organization->id,
            'code' => 'VEH-E2E',
            'name' => 'E2E Vehicle Stock',
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
            'code' => 'V-E2E',
            'plate_number' => 'EG-E2E-01',
            'name' => 'E2E Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $financialAccountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $financialAccountId,
            'organization_id' => $organization->id,
            'code' => 'CASH-E2E',
            'name' => 'E2E Cash',
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
            'code' => 'cash-e2e',
            'name_ar' => 'نقدي E2E',
            'requires_reference' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            $organization,
            $user,
            $supplierId,
            $customerId,
            $productId,
            $unitId,
            $warehouseId,
            $vehicleId,
            $financialAccountId,
            $paymentMethodId,
        ];
    }
}
