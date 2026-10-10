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
    }
}
