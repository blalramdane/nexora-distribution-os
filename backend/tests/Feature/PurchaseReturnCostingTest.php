<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseReturnCostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_return_uses_original_packaging_cost_and_never_persists_client_packaging(): void
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA Purchase Return Cost', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Purchase Return Tester',
            'email' => 'purchase-return-cost-' . Str::uuid() . '@nexora.test', 'phone' => '01000000014',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        $pieceUnit = (string) Str::ulid();
        $cartonUnit = (string) Str::ulid();
        DB::table('units')->insert([
            ['id' => $pieceUnit, 'organization_id' => $organization->id, 'code' => 'PCS-PR', 'name_ar' => 'Piece', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $cartonUnit, 'organization_id' => $organization->id, 'code' => 'CTN-PR', 'name_ar' => 'Carton', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $pieceUnit,
            'sku' => 'PUR-RETURN-001', 'name_ar' => 'Purchase return carton product',
            'default_cost' => 10, 'default_piece_price' => 20, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $packagingId = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            'id' => $packagingId, 'organization_id' => $organization->id, 'product_id' => $productId,
            'unit_id' => $cartonUnit, 'name_ar' => 'Carton 10', 'conversion_to_base' => 10,
            'purchase_price' => 100, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'SUP-PR-COST',
            'name' => 'Purchase return supplier', 'normalized_name' => 'purchase return supplier',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $organization->id, 'code' => 'WH-PR-COST',
            'name' => 'Purchase return warehouse', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(TransactionPostingService::class);
        $purchase = $service->postPurchase($organization->id, [
            'supplier_id' => $supplierId, 'location_id' => $locationId, 'created_by' => $user->id,
            'idempotency_key' => 'purchase-return-cost-' . Str::uuid(),
            'items' => [
                ['product_id' => $productId, 'packaging_id' => $packagingId, 'unit_id' => $cartonUnit, 'quantity' => 1, 'conversion_factor' => 1, 'unit_cost' => 100],
                ['product_id' => $productId, 'packaging_id' => $packagingId, 'unit_id' => $cartonUnit, 'quantity' => 1, 'conversion_factor' => 999, 'unit_cost' => 100],
            ],
        ]);
        $purchaseItems = DB::table('purchase_invoice_items')->where('purchase_invoice_id', $purchase['id'])->orderBy('id')->get();

        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign Packaging Tenant', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $foreignUnit = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $foreignUnit, 'organization_id' => $foreignOrganization->id, 'code' => 'FOREIGN-CTN',
            'name_ar' => 'Foreign carton', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignProduct = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $foreignProduct, 'organization_id' => $foreignOrganization->id, 'base_unit_id' => $foreignUnit,
            'sku' => 'FOREIGN-PUR-RETURN', 'name_ar' => 'Foreign product', 'default_cost' => 1,
            'default_piece_price' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignPackaging = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            'id' => $foreignPackaging, 'organization_id' => $foreignOrganization->id, 'product_id' => $foreignProduct,
            'unit_id' => $foreignUnit, 'name_ar' => 'Foreign pack', 'conversion_to_base' => 99,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $service->postPurchaseReturn($organization->id, [
                'supplier_id' => $supplierId, 'location_id' => $locationId,
                'original_purchase_invoice_id' => $purchase['id'], 'created_by' => $user->id,
                'idempotency_key' => 'purchase-return-excess-discount-' . Str::uuid(), 'discount' => 201,
                'items' => $purchaseItems->map(fn ($item) => [
                    'product_id' => $productId, 'original_purchase_invoice_item_id' => $item->id,
                    'packaging_id' => $foreignPackaging, 'quantity' => 1, 'conversion_factor' => 0.1, 'unit_cost' => 1,
                ])->all(),
            ]);
            $this->fail('Purchase return discount cannot exceed its line subtotal.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('discount', $exception->errors());
        }
        $this->assertSame(0, DB::table('purchase_returns')->where('organization_id', $organization->id)->count());
        $this->assertSame('20.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->value('quantity_base'), 6, '.', ''));

        $return = $service->postPurchaseReturn($organization->id, [
            'supplier_id' => $supplierId, 'location_id' => $locationId,
            'original_purchase_invoice_id' => $purchase['id'], 'created_by' => $user->id,
            'idempotency_key' => 'purchase-return-post-' . Str::uuid(),
            'items' => $purchaseItems->map(fn ($item) => [
                'product_id' => $productId, 'original_purchase_invoice_item_id' => $item->id,
                'packaging_id' => $foreignPackaging, 'quantity' => 1, 'conversion_factor' => 0.1, 'unit_cost' => 1,
            ])->all(),
        ]);

        $returnItems = DB::table('purchase_return_items')->where('purchase_return_id', $return['id'])->orderBy('id')->get();
        $balance = DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $productId)->where('location_id', $locationId)->first();
        $inventoryAccount = DB::table('ledger_accounts')->where('organization_id', $organization->id)->where('code', '1300')->value('id');
        $inventoryCredit = DB::table('ledger_entries')->where('organization_id', $organization->id)->where('source_document_type', 'purchase_return')->where('source_document_id', $return['id'])->where('account_id', $inventoryAccount)->sum('credit');

        $this->assertCount(2, $returnItems);
        $this->assertSame([$packagingId, $packagingId], $returnItems->pluck('packaging_id')->all());
        $this->assertSame(['10.000000', '10.000000'], $returnItems->pluck('conversion_factor_snapshot')->map(fn ($value) => number_format((float) $value, 6, '.', ''))->all());
        $this->assertSame(['10.0000', '10.0000'], $returnItems->pluck('unit_cost_base')->map(fn ($value) => number_format((float) $value, 4, '.', ''))->all());
        $this->assertSame(['100.0000', '100.0000'], $returnItems->pluck('line_total')->map(fn ($value) => number_format((float) $value, 4, '.', ''))->all());
        $this->assertSame('0.000000', number_format((float) $balance->quantity_base, 6, '.', ''));
        $this->assertSame('200.0000', number_format((float) $inventoryCredit, 4, '.', ''));
    }
}
