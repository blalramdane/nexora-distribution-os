<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class PaymentTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_payment_endpoint_rejects_foreign_party_account_and_method_without_posting(): void
    {
        [$orgA, $userA] = $this->organizationUser('Payment Tenant A');
        [$orgB, $userB] = $this->organizationUser('Payment Tenant B');

        $customerA = $this->customer($orgA->id, 'A');
        $customerB = $this->customer($orgB->id, 'B');
        $accountA = $this->account($orgA->id, 'CASH-A');
        $accountB = $this->account($orgB->id, 'CASH-B');
        $methodA = $this->method($orgA->id, 'METHOD-A');
        $methodB = $this->method($orgB->id, 'METHOD-B');
        $foreignLocation = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $foreignLocation, 'organization_id' => $orgB->id,
            'code' => 'PAY-FOREIGN-WH', 'name' => 'Foreign payment warehouse',
            'type' => 'warehouse', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignInvoice = (string) Str::ulid();
        DB::table('sales_invoices')->insert([
            'id' => $foreignInvoice, 'organization_id' => $orgB->id, 'customer_id' => $customerB,
            'source_location_id' => $foreignLocation, 'document_number' => 'SI-FOREIGN-PAYMENT',
            'status' => 'posted', 'invoice_date' => now()->toDateString(), 'subtotal' => 5,
            'discount' => 0, 'tax' => 0, 'total' => 5, 'paid_amount' => 0, 'balance_due' => 5,
            'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $originLocationId = $this->location($orgB->id, 'PAY-TRIP-ORIGIN', 'warehouse');
        $vehicleLocationId = $this->location($orgB->id, 'PAY-TRIP-STOCK', 'vehicle');
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $orgB->id, 'location_id' => $vehicleLocationId,
            'code' => 'PAY-TRIP-VEHICLE', 'plate_number' => 'PAY-TRIP', 'name' => 'Payment tenant vehicle',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $trip = $this->actingAs($userB, 'sanctum')->postJson('/api/v1/trips', [
            'vehicle_id' => $vehicleId, 'rep_user_id' => $userB->id,
            'origin_location_id' => $originLocationId, 'trip_date' => now()->toDateString(),
        ])->assertCreated()->json();

        $base = [
            'party_type' => 'customer', 'party_id' => $customerA,
            'financial_account_id' => $accountA, 'payment_method_id' => $methodA,
            'direction' => 'inbound', 'amount' => 5, 'payment_date' => now()->toDateString(),
        ];

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/payments', [...$base, 'party_id' => $customerB, 'idempotency_key' => 'foreign-party-'.Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['party_id']);

        $this->postJson('/api/v1/payments', [...$base, 'financial_account_id' => $accountB, 'idempotency_key' => 'foreign-account-'.Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['financial_account_id']);

        $this->postJson('/api/v1/payments', [...$base, 'payment_method_id' => $methodB, 'idempotency_key' => 'foreign-method-'.Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_method_id']);

        $this->postJson('/api/v1/payments', [
            ...$base,
            'allocations' => [['document_type' => 'sales_invoice', 'document_id' => $foreignInvoice, 'amount' => 5]],
            'idempotency_key' => 'foreign-invoice-'.Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['allocations']);

        $this->postJson('/api/v1/payments', [
            ...$base, 'trip_id' => $trip['id'], 'idempotency_key' => 'foreign-trip-'.Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['trip_id']);

        $this->assertSame(0, DB::table('payments')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, DB::table('idempotency_keys')->where('organization_id', $orgA->id)->where('operation_type', 'payment.post')->count());
    }

    private function organizationUser(string $name): array
    {
        $org = Organization::query()->create([
            'name' => $name, 'default_currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $org->id, 'name' => $name.' Manager',
            'email' => Str::uuid().'@nexora.test', 'phone' => '01000000000',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        return [$org, $user];
    }

    private function customer(string $orgId, string $suffix): string
    {
        $id = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => 'CUS-'.$suffix,
            'name' => 'Customer '.$suffix, 'normalized_name' => 'customer '.$suffix,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function account(string $orgId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => $code, 'name' => $code,
            'type' => 'cash', 'currency' => 'EGP', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function method(string $orgId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => $code, 'name_ar' => $code,
            'requires_reference' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function location(string $orgId, string $code, string $type): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $orgId, 'code' => $code,
            'name' => $code, 'type' => $type, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
