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
        $this->assertSame('10.000000', $this->stock($organization->id, $productId, $warehouseId));

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

        $trip = $this->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'origin_location_id' => $warehouseId,
            'trip_date' => now()->toDateString(),
        ])->assertCreated()->json();

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
            'idempotency_key' => 'e2e-sale-001',
        ];

        $sale = $this->postJson('/api/v1/sales', $salePayload)
            ->assertCreated()
            ->json();

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

        $expense = $this->postJson('/api/v1/expenses', $expensePayload)->assertCreated()->json();
        $expenseReplay = $this->postJson('/api/v1/expenses', $expensePayload)->assertCreated()->json();
        $this->assertSame($expense['id'], $expenseReplay['id']);
        $this->assertSame('15.0000', number_format((float) $expense['amount'], 4, '.', ''));
        $this->assertSame(1, DB::table('expenses')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->count());
        $this->assertSame(1, DB::table('trip_expenses')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->where('expense_id', $expense['id'])->count());
        $this->getJson('/api/v1/expenses?trip_id='.$trip['id'])->assertOk()->assertJsonFragment(['id' => $expense['id'], 'category' => 'Fuel']);
        $this->postJson('/api/v1/expenses', array_merge($expensePayload, ['amount' => 16]))->assertUnprocessable();
        $this->assertSame(1, DB::table('expenses')->where('organization_id', $organization->id)->where('trip_id', $trip['id'])->count());

        $settlement = $this->postJson('/api/v1/trip-settlements', [
            'trip_id' => $trip['id'],
            'opening_cash' => 0,
            'actual_cash' => 185,
            'closing_items' => [[
                'product_id' => $productId,
                'quantity_base' => 8,
            ]],
        ])->assertCreated()->json();

        $this->assertSame('185.0000', number_format((float) $settlement['expected_cash'], 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $settlement['cash_variance'], 4, '.', ''));
        $this->assertSame('0.0000', number_format((float) $settlement['stock_variance_value'], 4, '.', ''));
        $this->assertTransactionBalanced($organization->id, 'expense', $expense['id']);
        $this->postJson('/api/v1/expenses', array_merge($expensePayload, ['idempotency_key' => 'e2e-expense-after-close']))->assertUnprocessable();
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
