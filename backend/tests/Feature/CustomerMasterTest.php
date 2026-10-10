<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_creation_is_tenant_scoped_and_can_persist_primary_address(): void
    {
        $org = Organization::query()->create([
            'name' => 'Customer Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $org->id,
            'name' => 'Customer Tester',
            'email' => 'customer-'.$org->id.'@nexora.test',
            'phone' => '01000000004',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $foreignOrg = Organization::query()->create([
            'name' => 'Foreign Customer Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $foreignCustomer = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $foreignCustomer,
            'organization_id' => $foreignOrg->id,
            'code' => 'FOREIGN-001',
            'name' => 'عميل خارجي',
            'normalized_name' => 'عميل خارجي',
            'credit_limit' => 0,
            'payment_terms_days' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonMissing(['id' => $foreignCustomer]);

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'عميل الزرقا',
            'phone' => '01012345678',
            'code' => 'CUS-001',
            'credit_limit' => 15000,
            'payment_terms_days' => 30,
            'address_text' => 'شارع البحر - الزرقا',
            'latitude' => 31.2087,
            'longitude' => 31.6356,
        ])->assertCreated();

        $customerId = $response->json('id');

        $this->assertSame('CUS-001', $response->json('code'));
        $this->assertSame('عميل الزرقا', $response->json('name'));
        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'organization_id' => $org->id,
            'code' => 'CUS-001',
            'credit_limit' => 15000,
            'payment_terms_days' => 30,
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'organization_id' => $org->id,
            'customer_id' => $customerId,
            'is_primary' => true,
            'latitude' => 31.2087,
            'longitude' => 31.6356,
        ]);

        $this->getJson('/api/v1/customers?q=الزرقا')
            ->assertOk()
            ->assertJsonFragment(['id' => $customerId])
            ->assertJsonMissing(['id' => $foreignCustomer]);
    }
}
