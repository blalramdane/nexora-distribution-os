<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class SalesCostingTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_paid_sale_fails_cleanly_when_legacy_preview_cannot_trace_issue_receipt(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Legacy POS Preview', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Legacy POS Tester',
            'email' => 'legacy-pos-' . Str::uuid() . '@nexora.test', 'phone' => '01000000014',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        Sanctum::actingAs($user);

        Schema::table('payments', static function (Blueprint $table): void {
            $table->dropUnique('payments_source_document_uq');
            $table->dropColumn(['source_document_type', 'source_document_id']);
        });

        $this->postJson('/api/v1/sales', [
            'customer_id' => (string) Str::ulid(),
            'location_id' => (string) Str::ulid(),
            'paid_amount' => 20,
            'financial_account_id' => (string) Str::ulid(),
            'payment_method_id' => (string) Str::ulid(),
            'idempotency_key' => 'legacy-preview-paid-sale-' . Str::uuid(),
            'items' => [[
                'product_id' => (string) Str::ulid(), 'quantity' => 1, 'unit_price' => 20,
            ]],
        ])->assertStatus(503);

        $this->assertSame(0, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('payments')->where('organization_id', $organization->id)->count());
    }

    public function test_sale_uses_server_packaging_conversions_and_preserves_duplicate_product_line_units(): void
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA Sales Cost Test', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Sales Cost Tester',
            'email' => 'sales-cost-' . Str::uuid() . '@nexora.test', 'phone' => '01000000013',
            'password' => 'secret-password', 'status' => 'active',
        ]);

        $pieceUnit = (string) Str::ulid();
        $cartonUnit = (string) Str::ulid();
        $bundleUnit = (string) Str::ulid();
        DB::table('units')->insert([
            ['id' => $pieceUnit, 'organization_id' => $organization->id, 'code' => 'PCS-SALE', 'name_ar' => 'Piece', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $cartonUnit, 'organization_id' => $organization->id, 'code' => 'CTN-SALE', 'name_ar' => 'Carton', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $bundleUnit, 'organization_id' => $organization->id, 'code' => 'BND-SALE', 'name_ar' => 'Bundle', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $pieceUnit,
            'sku' => 'SALE-COST-001', 'name_ar' => 'Sale conversion test product',
            'default_cost' => 2, 'default_piece_price' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cartonPackaging = (string) Str::ulid();
        $bundlePackaging = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            ['id' => $cartonPackaging, 'organization_id' => $organization->id, 'product_id' => $productId, 'unit_id' => $cartonUnit, 'name_ar' => 'Carton 10', 'conversion_to_base' => 10, 'sale_price' => 100, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $bundlePackaging, 'organization_id' => $organization->id, 'product_id' => $productId, 'unit_id' => $bundleUnit, 'name_ar' => 'Bundle 5', 'conversion_to_base' => 5, 'sale_price' => 60, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $organization->id, 'code' => 'SALE-COST-CUS',
            'name' => 'Sales Cost Customer', 'normalized_name' => 'sales cost customer',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $organization->id, 'code' => 'SALE-COST-WH',
            'name' => 'Sales Cost Warehouse', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'product_id' => $productId, 'location_id' => $locationId,
            'quantity_base' => 2000, 'reserved_quantity_base' => 0, 'average_cost' => 2,
            'updated_at' => now(),
        ]);

        $sale = app(TransactionPostingService::class)->postSale($organization->id, [
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'created_by' => $user->id,
            'idempotency_key' => 'sale-conversion-' . Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'packaging_id' => $cartonPackaging, 'unit_id' => $cartonUnit, 'quantity' => 1, 'conversion_factor' => 1, 'unit_price' => 100],
                ['product_id' => $productId, 'packaging_id' => $bundlePackaging, 'unit_id' => $bundleUnit, 'quantity' => 1, 'conversion_factor' => 999, 'unit_price' => 60],
                ['product_id' => $productId, 'unit_id' => $pieceUnit, 'quantity' => 1, 'conversion_factor' => 999, 'unit_price' => 4],
            ],
        ]);

        $items = DB::table('sales_invoice_items')
            ->where('organization_id', $organization->id)
            ->where('sales_invoice_id', $sale['id'])
            ->orderBy('created_at')
            ->get();
        $balance = DB::table('stock_balances')
            ->where('organization_id', $organization->id)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $this->assertCount(3, $items);
        $this->assertSame([$cartonPackaging, $bundlePackaging, null], $items->pluck('packaging_id')->all());
        $this->assertSame([$cartonUnit, $bundleUnit, $pieceUnit], $items->pluck('entered_unit_id')->all());
        $this->assertSame(['10.000000', '5.000000', '1.000000'], $items->pluck('conversion_factor_snapshot')->map(fn ($value) => number_format((float) $value, 6, '.', ''))->all());
        $this->assertSame(['10.000000', '5.000000', '1.000000'], $items->pluck('quantity_base')->map(fn ($value) => number_format((float) $value, 6, '.', ''))->all());
        $this->assertSame('1984.000000', number_format((float) $balance->quantity_base, 6, '.', ''));
        $this->assertSame(3, DB::table('stock_movements')->where('organization_id', $organization->id)->where('source_document_type', 'sales_invoice')->where('source_document_id', $sale['id'])->count());

        try {
            app(TransactionPostingService::class)->postSale($organization->id, [
                'customer_id' => $customerId,
                'location_id' => $locationId,
                'created_by' => $user->id,
                'idempotency_key' => 'sale-overdraw-' . Str::uuid(),
                'items' => [
                    ['product_id' => $productId, 'unit_id' => $pieceUnit, 'quantity' => 1000, 'conversion_factor' => 1, 'unit_price' => 1],
                    ['product_id' => $productId, 'unit_id' => $pieceUnit, 'quantity' => 1000, 'conversion_factor' => 1, 'unit_price' => 1],
                ],
            ]);
            $this->fail('Repeated lines must be validated against their combined stock requirement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame(1, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame('1984.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame(3, DB::table('stock_movements')->where('organization_id', $organization->id)->where('source_document_type', 'sales_invoice')->count());

        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign Sales Packaging Tenant', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $foreignUnit = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $foreignUnit, 'organization_id' => $foreignOrganization->id, 'code' => 'FOREIGN-SALE-UNIT',
            'name_ar' => 'Foreign unit', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignProduct = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $foreignProduct, 'organization_id' => $foreignOrganization->id, 'base_unit_id' => $foreignUnit,
            'sku' => 'FOREIGN-SALE-PRODUCT', 'name_ar' => 'Foreign sale product', 'default_cost' => 1,
            'default_piece_price' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignPackaging = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            'id' => $foreignPackaging, 'organization_id' => $foreignOrganization->id, 'product_id' => $foreignProduct,
            'unit_id' => $foreignUnit, 'name_ar' => 'Foreign sale pack', 'conversion_to_base' => 99,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(TransactionPostingService::class)->postSale($organization->id, [
                'customer_id' => $customerId, 'location_id' => $locationId, 'created_by' => $user->id,
                'idempotency_key' => 'foreign-sale-packaging-' . Str::uuid(),
                'items' => [['product_id' => $productId, 'packaging_id' => $foreignPackaging, 'unit_id' => $foreignUnit, 'quantity' => 1, 'conversion_factor' => 1, 'unit_price' => 99]],
            ]);
            $this->fail('A sale cannot reference packaging owned by another tenant.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertSame(1, DB::table('sales_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame('1984.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));

        $cartonInvoiceItem = $items->first(fn ($item) => $item->packaging_id === $cartonPackaging);
        try {
            app(TransactionPostingService::class)->postSalesReturn($organization->id, [
                'customer_id' => $customerId, 'location_id' => $locationId,
                'original_sales_invoice_id' => $sale['id'], 'created_by' => $user->id,
                'idempotency_key' => 'sale-return-excess-discount-' . Str::uuid(), 'discount' => 101,
                'items' => [['product_id' => $productId, 'original_sales_invoice_item_id' => $cartonInvoiceItem->id, 'quantity' => 1, 'conversion_factor' => 1, 'unit_price' => 1]],
            ]);
            $this->fail('Sales return discount cannot exceed its line subtotal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discount', $exception->errors());
        }
        $this->assertSame(0, DB::table('sales_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame('1984.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));

        $return = app(TransactionPostingService::class)->postSalesReturn($organization->id, [
            'customer_id' => $customerId, 'location_id' => $locationId,
            'original_sales_invoice_id' => $sale['id'], 'created_by' => $user->id,
            'idempotency_key' => 'sale-return-source-package-' . Str::uuid(),
            'items' => [[
                'product_id' => $productId, 'original_sales_invoice_item_id' => $cartonInvoiceItem->id,
                'packaging_id' => $foreignPackaging, 'quantity' => 1, 'conversion_factor' => 0.1, 'unit_price' => 1,
            ]],
        ]);
        $returnItem = DB::table('sales_return_items')->where('sales_return_id', $return['id'])->first();
        $this->assertSame($cartonPackaging, $returnItem->packaging_id);
        $this->assertSame('10.000000', number_format((float) $returnItem->conversion_factor_snapshot, 6, '.', ''));
        $this->assertSame('10.000000', number_format((float) $returnItem->quantity_base, 6, '.', ''));
        $this->assertSame('1994.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));
    }
}
