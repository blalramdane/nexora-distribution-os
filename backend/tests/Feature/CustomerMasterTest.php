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

class CustomerMasterTest extends TestCase
{
    use GrantsTestPermissions;
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
        $this->grantTestPermissions($user);

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

        // Field-created customers must be attached to an active trip owned by
        // the authenticated representative. Keep this test aligned with the
        // production endpoint contract instead of weakening trip validation.
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $org->id,
            'code' => 'WH-CUSTOMER-TEST',
            'name' => 'Customer Test Vehicle Stock',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'location_id' => $locationId,
            'code' => 'V-CUSTOMER-TEST',
            'plate_number' => 'EG-CUSTOMER-TEST',
            'name' => 'Customer Test Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $org->id,
            'trip_number' => 'TR-CUSTOMER-TEST',
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'status' => 'planned',
            'trip_date' => now()->toDateString(),
            'origin_location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Legacy preview databases may not yet contain the optional credit flag.
        // Customer search/create and the sales form must still operate safely.
        Schema::table('customers', static function (Blueprint $table): void {
            $table->dropColumn('credit_enabled');
        });

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

        $this->postJson('/api/v1/customers', [
            'name' => 'عميل مكرر',
            'code' => 'CUS-001',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->postJson('/api/v1/customers', [
            'name' => 'إحداثيات خاطئة',
            'latitude' => 95,
            'longitude' => 31,
        ])->assertUnprocessable()->assertJsonValidationErrors(['latitude']);

        $this->postJson('/api/v1/field/customers', [
            'trip_id' => $tripId,
            'name' => 'Field customer with invalid coordinates',
            'latitude' => 95,
            'longitude' => 31,
        ])->assertUnprocessable()->assertJsonValidationErrors(['latitude']);
        $this->assertDatabaseMissing('customers', [
            'organization_id' => $org->id,
            'name' => 'Field customer with invalid coordinates',
        ]);

        $fieldCustomer = $this->postJson('/api/v1/field/customers', [
            'trip_id' => $tripId,
            'name' => 'Field customer tenant scoped',
            'phone' => '01012345679',
            'address_text' => 'الزرقا',
            'latitude' => 31.2,
            'longitude' => 31.6,
        ])->assertCreated()->json();
        $fieldCustomerId = $fieldCustomer['customer']['id'];
        $this->assertDatabaseHas('customers', [
            'id' => $fieldCustomerId,
            'organization_id' => $org->id,
            'name' => 'Field customer tenant scoped',
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'organization_id' => $org->id,
            'customer_id' => $fieldCustomerId,
            'is_primary' => true,
            'latitude' => 31.2,
            'longitude' => 31.6,
        ]);

        $this->getJson('/api/v1/customers?q=الزرقا')
            ->assertOk()
            ->assertJsonFragment(['id' => $customerId])
            ->assertJsonFragment(['id' => $customerId, 'credit_enabled' => 1])
            ->assertJsonMissing(['id' => $foreignCustomer]);

        $this->getJson('/api/v1/customers/'.$customerId.'/360')
            ->assertOk()
            ->assertJsonPath('customer.id', $customerId)
            ->assertJsonPath('customer.name', 'عميل الزرقا')
            ->assertJsonPath('summary.invoice_count', 0)
            ->assertJsonPath('summary.open_balance', 0)
            ->assertJsonPath('invoices.data', [])
            ->assertJsonPath('payments.data', [])
            ->assertJsonPath('returns.data', []);

        $this->getJson('/api/v1/customers/'.$foreignCustomer.'/360')
            ->assertNotFound();
    }
}
