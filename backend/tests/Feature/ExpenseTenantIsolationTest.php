<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class ExpenseTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_expense_list_and_trip_filter_do_not_expose_another_tenant(): void
    {
        [$organizationA, $userA] = $this->organizationUser('Expense Isolation A');
        [$organizationB, $userB] = $this->organizationUser('Expense Isolation B');

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organizationB->id,
            'code' => 'EXP-FOREIGN-WH',
            'name' => 'Foreign expense warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $vehicleLocationId,
            'organization_id' => $organizationB->id,
            'code' => 'EXP-FOREIGN-VAN-STOCK',
            'name' => 'Foreign vehicle stock',
            'type' => 'vehicle',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organizationB->id,
            'location_id' => $vehicleLocationId,
            'code' => 'EXP-FOREIGN-VAN',
            'plate_number' => 'EXP-FOREIGN',
            'name' => 'Foreign expense vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $trip = $this->actingAs($userB, 'sanctum')
            ->postJson('/api/v1/trips', [
                'vehicle_id' => $vehicleId,
                'rep_user_id' => $userB->id,
                'origin_location_id' => $locationId,
                'trip_date' => now()->toDateString(),
            ])
            ->assertCreated()
            ->json();

        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId,
            'organization_id' => $organizationB->id,
            'code' => 'EXP-FOREIGN-CASH',
            'name' => 'Foreign cash account',
            'type' => 'cash',
            'currency' => 'EGP',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('expenses')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationB->id,
            'category' => 'Foreign tenant fuel',
            'amount' => '99.0000',
            'financial_account_id' => $accountId,
            'expense_date' => now()->toDateString(),
            'trip_id' => $trip['id'],
            'vehicle_id' => $vehicleId,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertExactJson([]);

        $this->getJson('/api/v1/expenses?trip_id='.$trip['id'])
            ->assertNotFound();
    }

    public function test_expense_post_rejects_a_financial_account_owned_by_another_tenant(): void
    {
        [$organizationA, $userA] = $this->organizationUser('Expense Posting A');
        [$organizationB] = $this->organizationUser('Expense Posting B');

        $foreignAccountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $foreignAccountId,
            'organization_id' => $organizationB->id,
            'code' => 'EXP-FOREIGN-ACCOUNT',
            'name' => 'Another tenant account',
            'type' => 'cash',
            'currency' => 'EGP',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => 'Fuel',
                'amount' => 25,
                'financial_account_id' => $foreignAccountId,
                'expense_date' => now()->toDateString(),
                'idempotency_key' => 'foreign-expense-account-'.Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['financial_account_id']);

        $this->assertSame(0, DB::table('expenses')->where('organization_id', $organizationA->id)->count());
        $this->assertSame(0, DB::table('ledger_entries')->where('organization_id', $organizationA->id)->where('source_document_type', 'expense')->count());
    }

    /** @return array{Organization, User} */
    private function organizationUser(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => $name.' Manager',
            'email' => Str::uuid().'@nexora.test',
            'phone' => '01000000000',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        return [$organization, $user];
    }
}
