<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_catalog_references_and_product_creation_are_tenant_scoped(): void
    {
        $org = Organization::query()->create([
            'name' => 'Catalog Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $org->id,
            'name' => 'Catalog Tester',
            'email' => 'catalog-'.$org->id.'@nexora.test',
            'phone' => '01000000003',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $unit = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unit,
            'organization_id' => $org->id,
            'code' => 'PCS',
            'name_ar' => 'قطعة',
            'precision' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = (string) Str::ulid();
        DB::table('categories')->insert([
            'id' => $category,
            'organization_id' => $org->id,
            'code' => 'TOOLS',
            'name_ar' => 'أدوات',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignOrg = Organization::query()->create([
            'name' => 'Foreign Catalog Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $foreignUnit = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $foreignUnit,
            'organization_id' => $foreignOrg->id,
            'code' => 'BOX',
            'name_ar' => 'كرتونة',
            'precision' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignCategory = (string) Str::ulid();
        DB::table('categories')->insert([
            'id' => $foreignCategory,
            'organization_id' => $foreignOrg->id,
            'code' => 'FOREIGN',
            'name_ar' => 'تصنيف خاص',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $references = $this->getJson('/api/v1/catalog/references')->assertOk()->json();

        $this->assertTrue(collect($references['units'])->contains('id', $unit));
        $this->assertFalse(collect($references['units'])->contains('id', $foreignUnit));
        $this->assertCount(1, $references['categories']);
        $this->assertSame('أدوات', $references['categories'][0]['name_ar']);
        $this->assertFalse(collect($references['categories'])->contains('id', $foreignCategory));

        $response = $this->postJson('/api/v1/products', [
            'sku' => 'CAT-001',
            'name_ar' => 'مفك كهرباء',
            'name_en' => 'Electric Screwdriver',
            'category_id' => $category,
            'base_unit_id' => $unit,
            'brand' => 'NEXORA',
            'default_cost' => 75,
            'default_piece_price' => 110,
        ])->assertCreated();

        $this->assertSame('CAT-001', $response->json('sku'));
        $this->assertSame('مفك كهرباء', $response->json('name_ar'));
        $this->assertSame($unit, $response->json('base_unit_id'));
        $this->assertDatabaseHas('products', [
            'organization_id' => $org->id,
            'sku' => 'CAT-001',
            'name_ar' => 'مفك كهرباء',
        ]);

        $ownProductId = (string) $response->json('id');
        DB::table('product_aliases')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'product_id' => $ownProductId, 'alias' => 'مفك بحث خاص',
            'normalized_alias' => 'own-search-alias', 'source' => 'test', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignProductId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $foreignProductId, 'organization_id' => $foreignOrg->id,
            'base_unit_id' => $foreignUnit, 'sku' => 'FOREIGN-ALIAS-SKU',
            'name_ar' => 'منتج خارجي', 'default_cost' => 1, 'default_piece_price' => 2,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_aliases')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $foreignOrg->id,
            'product_id' => $foreignProductId, 'alias' => 'foreign alias only',
            'normalized_alias' => 'foreign-search-alias', 'source' => 'test', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/products?q=own-search-alias')
            ->assertOk()->assertJsonFragment(['id' => $ownProductId]);
        $this->getJson('/api/v1/products?q=foreign-search-alias')
            ->assertOk()->assertExactJson([]);

        $this->postJson('/api/v1/products', [
            'sku' => 'CAT-002',
            'name_ar' => 'محاولة تصنيف خارجي',
            'category_id' => $foreignCategory,
            'base_unit_id' => $unit,
        ])->assertUnprocessable()->assertJsonValidationErrors(['category_id']);

        $this->postJson('/api/v1/products', [
            'sku' => 'CAT-003',
            'name_ar' => 'محاولة وحدة خارجية',
            'category_id' => $category,
            'base_unit_id' => $foreignUnit,
        ])->assertUnprocessable()->assertJsonValidationErrors(['base_unit_id']);

        $this->postJson('/api/v1/products', [
            'sku' => 'CAT-001',
            'name_ar' => 'SKU مكرر',
            'category_id' => $category,
            'base_unit_id' => $unit,
        ])->assertUnprocessable()->assertJsonValidationErrors(['sku']);

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $org->id,
            'code' => 'POS-WH',
            'name' => 'مخزن الكاشير',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $org->id,
            'product_id' => $ownProductId,
            'location_id' => $locationId,
            'quantity_base' => 12,
            'reserved_quantity_base' => 0,
            'average_cost' => 75,
            'updated_at' => now(),
        ]);

        // Regression: the persistent local preview can lag behind the source
        // migrations. POS must remain usable when this optional column is absent.
        Schema::table('products', static function (Blueprint $table): void {
            $table->dropColumn('color');
        });

        $this->getJson('/api/v1/catalog/pos?location_id='.$locationId.'&category_id='.$category.'&q=CAT-001')
            ->assertOk()
            ->assertJsonPath('products.total', 1)
            ->assertJsonPath('products.data.0.id', $ownProductId)
            ->assertJsonPath('products.data.0.category_id', $category)
            ->assertJsonPath('products.data.0.quantity_base', 12)
            ->assertJsonPath('categories.0.id', $category);

        $this->getJson('/api/v1/catalog/pos?q=FOREIGN-ALIAS-SKU')
            ->assertOk()
            ->assertJsonPath('products.total', 0);
    }

    public function test_product_uses_carton_conversion_opening_stock_and_multiple_supplier_codes_without_duplicate_product(): void
    {
        $org = Organization::query()->create([
            'name' => 'Carton Inventory Test', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $org->id, 'name' => 'Carton Tester',
            'email' => 'carton-'.$org->id.'@nexora.test', 'phone' => '01000000031',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $pieceUnit = (string) Str::ulid();
        $cartonUnit = (string) Str::ulid();
        foreach ([[$pieceUnit, 'PCS', 'قطعة'], [$cartonUnit, 'CTN', 'كرتونة']] as [$unitId, $code, $name]) {
            DB::table('units')->insert([
                'id' => $unitId, 'organization_id' => $org->id, 'code' => $code,
                'name_ar' => $name, 'precision' => 0, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $warehouseId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $warehouseId, 'organization_id' => $org->id, 'code' => 'CARTON-WH',
            'name' => 'مخزن اختبار الكراتين', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $supplierIds = [];
        foreach ([['SUP-CARTON-A', 'مورد أول'], ['SUP-CARTON-B', 'مورد ثان']] as [$code, $name]) {
            $supplierId = (string) Str::ulid();
            DB::table('suppliers')->insert([
                'id' => $supplierId, 'organization_id' => $org->id, 'code' => $code,
                'name' => $name, 'normalized_name' => mb_strtolower($name), 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $supplierIds[] = $supplierId;
        }

        Sanctum::actingAs($user);
        $category = $this->postJson('/api/v1/categories', ['name_ar' => 'أجهزة المطبخ'])
            ->assertCreated()->json();
        $this->assertSame('أجهزة المطبخ', $category['name_ar']);

        $product = $this->postJson('/api/v1/products', [
            'sku' => '000123', 'name_ar' => 'كبة 8 لتر', 'category_id' => $category['id'],
            'base_unit_id' => $pieceUnit, 'carton_unit_id' => $cartonUnit,
            'pieces_per_carton' => 2, 'carton_purchase_price' => 300,
            'default_piece_price' => 200, 'supplier_id' => $supplierIds[0],
            'supplier_sku' => 'A-KEBBA-889', 'initial_cartons' => 40,
            'initial_pieces' => 0, 'initial_location_id' => $warehouseId,
        ])->assertCreated()->json();

        $this->assertSame('000123', $product['sku']);
        $this->assertSame('كبة 8 لتر', $product['name_ar']);
        $this->assertEquals(150.0, (float) $product['default_cost']);
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'organization_id' => $org->id, 'name_en' => null]);

        $pack = DB::table('product_packagings')->where('organization_id', $org->id)->where('product_id', $product['id'])->first();
        $this->assertNotNull($pack);
        $this->assertEquals(2.0, (float) $pack->conversion_to_base);
        $this->assertEquals(300.0, (float) $pack->purchase_price);
        $this->assertSame(1, (int) $pack->is_default_purchase_unit);
        $this->assertDatabaseHas('stock_balances', [
            'organization_id' => $org->id, 'product_id' => $product['id'],
            'location_id' => $warehouseId, 'quantity_base' => 80,
        ]);
        $this->assertSame(80.0, (float) DB::table('stock_movements')->where('organization_id', $org->id)->where('product_id', $product['id'])->sum('quantity_base'));
        $this->assertDatabaseHas('supplier_products', [
            'organization_id' => $org->id, 'supplier_id' => $supplierIds[0],
            'product_id' => $product['id'], 'supplier_sku' => 'A-KEBBA-889',
            'preferred_packaging_id' => $pack->id, 'last_cost' => 150,
        ]);

        $this->postJson('/api/v1/products/'.$product['id'].'/suppliers', [
            'supplier_id' => $supplierIds[1], 'supplier_sku' => 'B-KEBBA-77',
            'carton_purchase_price' => 320,
        ])->assertCreated()->assertJsonPath('supplier_sku', 'B-KEBBA-77');

        $this->assertSame(1, DB::table('products')->where('organization_id', $org->id)->where('name_ar', 'كبة 8 لتر')->count());
        $this->assertSame(2, DB::table('supplier_products')->where('organization_id', $org->id)->where('product_id', $product['id'])->count());
        $this->assertDatabaseHas('supplier_products', [
            'organization_id' => $org->id, 'supplier_id' => $supplierIds[1],
            'product_id' => $product['id'], 'supplier_sku' => 'B-KEBBA-77',
            'preferred_packaging_id' => $pack->id, 'last_cost' => 160,
        ]);
        $this->getJson('/api/v1/products?q=B-KEBBA-77')->assertOk()->assertJsonFragment(['id' => $product['id']]);
        $this->getJson('/api/v1/catalog/pos?q=B-KEBBA-77')->assertOk()->assertJsonPath('products.total', 1)->assertJsonPath('products.data.0.id', $product['id']);
        // A second supplier's carton price must not overwrite the first supplier's link or stock unit conversion.
        $this->assertEquals(300.0, (float) DB::table('product_packagings')->where('id', $pack->id)->value('purchase_price'));
        $this->assertSame(80.0, (float) DB::table('stock_balances')->where('organization_id', $org->id)->where('product_id', $product['id'])->where('location_id', $warehouseId)->value('quantity_base'));

        $productDetails = $this->getJson('/api/v1/products/'.$product['id'])->assertOk()->json();
        $this->assertSame('000123', $productDetails['sku']);
        $this->assertEquals(2.0, (float) $productDetails['purchase_packaging']['pieces_per_carton']);
        $this->putJson('/api/v1/products/'.$product['id'], ['base_unit_id' => $cartonUnit])
            ->assertUnprocessable()->assertJsonValidationErrors(['base_unit_id']);

        // Product editing versions a changed carton conversion so historical
        // invoice references keep the previous package definition intact.
        $this->putJson('/api/v1/products/'.$product['id'], [
            'sku' => '000124', 'name_ar' => 'كبة 8 لتر سوكاني', 'brand' => 'NEXORA',
            'category_id' => $category['id'], 'base_unit_id' => $pieceUnit,
            'carton_unit_id' => $cartonUnit, 'pieces_per_carton' => 3,
            'carton_purchase_price' => 330, 'default_piece_price' => 220,
        ])->assertOk()->assertJsonPath('product.sku', '000124')->assertJsonPath('product.name_ar', 'كبة 8 لتر سوكاني');

        $updatedProduct = DB::table('products')->where('organization_id', $org->id)->where('id', $product['id'])->first();
        $this->assertSame('000124', $updatedProduct->sku);
        $this->assertEquals(110.0, (float) $updatedProduct->default_cost);
        $this->assertSame(80.0, (float) DB::table('stock_balances')->where('organization_id', $org->id)->where('product_id', $product['id'])->where('location_id', $warehouseId)->value('quantity_base'));
        $this->assertDatabaseHas('product_packagings', ['id' => $pack->id, 'active' => 0, 'conversion_to_base' => 2]);
        $newPack = DB::table('product_packagings')->where('organization_id', $org->id)->where('product_id', $product['id'])->where('active', true)->where('is_default_purchase_unit', true)->first();
        $this->assertNotNull($newPack);
        $this->assertNotSame($pack->id, $newPack->id);
        $this->assertEquals(3.0, (float) $newPack->conversion_to_base);
        $this->assertEquals(330.0, (float) $newPack->purchase_price);
        $this->assertEquals(660.0, (float) $newPack->sale_price);
        $this->assertDatabaseHas('supplier_products', ['organization_id' => $org->id, 'product_id' => $product['id'], 'supplier_id' => $supplierIds[0], 'preferred_packaging_id' => $newPack->id]);

        // Editing only the per-piece selling price updates active package prices
        // too, so POS carton pricing cannot keep an obsolete explicit sale price.
        $this->putJson('/api/v1/products/'.$product['id'], ['default_piece_price' => 240])
            ->assertOk()->assertJsonPath('product.default_piece_price', 240);
        $this->assertEquals(240.0, (float) DB::table('products')->where('id', $product['id'])->value('default_piece_price'));
        $this->assertEquals(720.0, (float) DB::table('product_packagings')->where('id', $newPack->id)->value('sale_price'));
        $this->assertSame(80.0, (float) DB::table('stock_balances')->where('organization_id', $org->id)->where('product_id', $product['id'])->where('location_id', $warehouseId)->value('quantity_base'));

        $this->postJson('/api/v1/products/'.$product['id'].'/suppliers', [
            'supplier_id' => $supplierIds[1], 'supplier_sku' => 'B-KEBBA-77',
            'carton_purchase_price' => 360,
        ])->assertCreated()->assertJsonPath('supplier_sku', 'B-KEBBA-77');
        // A per-supplier carton cost does not overwrite the catalog carton cost.
        $this->assertEquals(330.0, (float) DB::table('product_packagings')->where('id', $newPack->id)->value('purchase_price'));

        $this->deleteJson('/api/v1/products/'.$product['id'])->assertOk()->assertJsonPath('action', 'deactivated');
        $this->assertDatabaseHas('products', ['organization_id' => $org->id, 'id' => $product['id'], 'active' => 0]);
        $this->getJson('/api/v1/products?catalog_status=inactive')->assertOk()->assertJsonFragment(['id' => $product['id'], 'active' => 0]);
        $this->postJson('/api/v1/products/'.$product['id'].'/restore', [])->assertOk()->assertJsonPath('action', 'restored');
        $this->assertDatabaseHas('products', ['organization_id' => $org->id, 'id' => $product['id'], 'active' => 1]);
    }

    public function test_unused_product_can_be_permanently_deleted_but_not_a_product_with_stock_history(): void
    {
        $org = Organization::query()->create([
            'name' => 'Safe Delete Catalog Test', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $org->id, 'name' => 'Safe Delete Tester',
            'email' => 'safe-delete-'.$org->id.'@nexora.test', 'phone' => '01000000032',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        $unitId = (string) Str::ulid();
        $cartonUnitId = (string) Str::ulid();
        foreach ([[$unitId, 'DEL-PCS', 'قطعة'], [$cartonUnitId, 'DEL-CTN', 'كرتونة']] as [$id, $code, $name]) {
            DB::table('units')->insert(['id' => $id, 'organization_id' => $org->id, 'code' => $code, 'name_ar' => $name, 'precision' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        Sanctum::actingAs($user);

        $unused = $this->postJson('/api/v1/products', [
            'sku' => 'DELETE-UNUSED-1', 'name_ar' => 'صنف تجريبي بدون حركة',
            'base_unit_id' => $unitId, 'carton_unit_id' => $cartonUnitId,
            'pieces_per_carton' => 2, 'carton_purchase_price' => 200,
        ])->assertCreated()->json();
        $this->assertSame(1, DB::table('product_packagings')->where('product_id', $unused['id'])->count());
        $this->deleteJson('/api/v1/products/'.$unused['id'])->assertOk()->assertJsonPath('action', 'deleted');
        $this->assertDatabaseMissing('products', ['organization_id' => $org->id, 'id' => $unused['id']]);
        $this->assertSame(0, DB::table('product_packagings')->where('product_id', $unused['id'])->count());

        $withStock = $this->postJson('/api/v1/products', [
            'sku' => 'DELETE-HISTORY-1', 'name_ar' => 'صنف له حركة مخزون',
            'base_unit_id' => $unitId, 'carton_unit_id' => $cartonUnitId,
            'pieces_per_carton' => 2, 'carton_purchase_price' => 200,
        ])->assertCreated()->json();
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert(['id' => $locationId, 'organization_id' => $org->id, 'code' => 'DEL-WH', 'name' => 'مخزن حذف آمن', 'type' => 'warehouse', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_balances')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'product_id' => $withStock['id'], 'location_id' => $locationId, 'quantity_base' => 0, 'reserved_quantity_base' => 0, 'updated_at' => now()]);

        $this->deleteJson('/api/v1/products/'.$withStock['id'])->assertOk()->assertJsonPath('action', 'deactivated');
        $this->assertDatabaseHas('products', ['organization_id' => $org->id, 'id' => $withStock['id'], 'active' => 0]);
        $this->assertDatabaseHas('stock_balances', ['organization_id' => $org->id, 'product_id' => $withStock['id'], 'location_id' => $locationId]);
    }
}
