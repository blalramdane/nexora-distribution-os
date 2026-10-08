<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
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

        Sanctum::actingAs($user);

        $references = $this->getJson('/api/v1/catalog/references')->assertOk()->json();

        $this->assertCount(1, $references['units']);
        $this->assertSame('قطعة', $references['units'][0]['name_ar']);
        $this->assertCount(1, $references['categories']);
        $this->assertSame('أدوات', $references['categories'][0]['name_ar']);

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
    }
}
