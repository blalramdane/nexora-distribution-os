<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class CustomerProductPricesTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_customer_prices_are_saved_per_unit_updated_without_duplicates_and_tenant_scoped(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Customer price test', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => 'Price manager',
            'email' => 'customer-prices-' . Str::uuid() . '@nexora.test', 'phone' => '01000000031',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        Sanctum::actingAs($user);

        $baseUnit = (string) Str::ulid();
        $packageUnit = (string) Str::ulid();
        DB::table('units')->insert([
            ['id' => $baseUnit, 'organization_id' => $organization->id, 'code' => 'PRICE-PCS', 'name_ar' => 'قطعة', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $packageUnit, 'organization_id' => $organization->id, 'code' => 'PRICE-CTN', 'name_ar' => 'كرتونة', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $baseUnit,
            'sku' => 'PRICE-001', 'name_ar' => 'صنف بسعر خاص', 'default_cost' => 5,
            'default_piece_price' => 12, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $packagingId = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            'id' => $packagingId, 'organization_id' => $organization->id, 'product_id' => $productId,
            'unit_id' => $packageUnit, 'name_ar' => 'كرتونة 12 قطعة', 'conversion_to_base' => 12,
            'sale_price' => 130, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $organization->id, 'code' => 'PRICE-CUS',
            'name' => 'عميل قائمة أسعار', 'normalized_name' => 'عميل قائمة أسعار',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/customers/' . $customerId . '/prices')->assertOk()->assertExactJson([]);
        $basePrice = $this->putJson('/api/v1/customers/' . $customerId . '/prices', [
            'product_id' => $productId, 'packaging_id' => null, 'unit_price' => 11.5,
        ])->assertOk()->assertJsonPath('product_id', $productId)->assertJsonPath('unit_key', 'base');
        $this->assertEquals(11.5, (float) $basePrice->json('unit_price'));
        $updatedBase = $this->putJson('/api/v1/customers/' . $customerId . '/prices', [
            'product_id' => $productId, 'packaging_id' => null, 'unit_price' => 13,
        ])->assertOk();
        $this->assertEquals(13, (float) $updatedBase->json('unit_price'));
        $packagePrice = $this->putJson('/api/v1/customers/' . $customerId . '/prices', [
            'product_id' => $productId, 'packaging_id' => $packagingId, 'unit_price' => 145,
        ])->assertOk()->assertJsonPath('packaging_id', $packagingId);
        $this->assertEquals(145, (float) $packagePrice->json('unit_price'));

        $this->getJson('/api/v1/customers/' . $customerId . '/prices')->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['product_id' => $productId, 'unit_key' => 'base'])
            ->assertJsonFragment(['product_id' => $productId, 'packaging_id' => $packagingId, 'unit_key' => $packagingId]);
        $this->assertEquals(13, (float) DB::table('customer_product_prices')->where('organization_id', $organization->id)->where('customer_id', $customerId)->where('unit_key', 'base')->value('unit_price'));
        $this->assertEquals(145, (float) DB::table('customer_product_prices')->where('organization_id', $organization->id)->where('customer_id', $customerId)->where('unit_key', $packagingId)->value('unit_price'));
        $this->assertSame(2, DB::table('customer_product_prices')->where('organization_id', $organization->id)->where('customer_id', $customerId)->count());

        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign price tenant', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $foreignCustomerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $foreignCustomerId, 'organization_id' => $foreignOrganization->id, 'code' => 'FOREIGN-PRICE-CUS',
            'name' => 'Foreign price customer', 'normalized_name' => 'foreign price customer',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignUnit = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $foreignUnit, 'organization_id' => $foreignOrganization->id, 'code' => 'FOREIGN-PRICE-PCS',
            'name_ar' => 'Foreign piece', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignProductId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $foreignProductId, 'organization_id' => $foreignOrganization->id, 'base_unit_id' => $foreignUnit,
            'sku' => 'FOREIGN-PRICE-001', 'name_ar' => 'Foreign price product', 'default_cost' => 1,
            'default_piece_price' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/customers/' . $foreignCustomerId . '/prices')->assertNotFound();
        $this->putJson('/api/v1/customers/' . $customerId . '/prices', [
            'product_id' => $foreignProductId, 'unit_price' => 50,
        ])->assertNotFound();
        $this->assertSame(2, DB::table('customer_product_prices')->where('organization_id', $organization->id)->count());

        // Regression: a preview DB missing the optional color column must still
        // return saved prices; if the later price table itself is missing, fall
        // back cleanly to default product prices instead of a SQL 500.
        Schema::table('products', static function (Blueprint $table): void {
            $table->dropColumn('color');
        });
        $this->getJson('/api/v1/customers/' . $customerId . '/prices')->assertOk()->assertJsonCount(2);
        Schema::dropIfExists('customer_product_prices');
        $this->getJson('/api/v1/customers/' . $customerId . '/prices')->assertOk()->assertExactJson([]);
    }
}

