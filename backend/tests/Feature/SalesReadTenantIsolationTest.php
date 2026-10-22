<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class SalesReadTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_sales_invoice_list_and_detail_do_not_expose_another_tenant(): void
    {
        [$orgA, $userA] = $this->organizationUser('Sales Read A');
        [$orgB] = $this->organizationUser('Sales Read B');

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $orgB->id, 'code' => 'SALES-FOREIGN-CUS',
            'name' => 'Foreign sales customer', 'normalized_name' => 'foreign sales customer',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $orgB->id, 'code' => 'SALES-FOREIGN-WH',
            'name' => 'Foreign sales warehouse', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (string) Str::ulid();
        DB::table('sales_invoices')->insert([
            'id' => $invoiceId, 'organization_id' => $orgB->id, 'customer_id' => $customerId,
            'source_location_id' => $locationId, 'document_number' => 'SI-FOREIGN-READ',
            'status' => 'posted', 'invoice_date' => now()->toDateString(), 'subtotal' => 25,
            'discount' => 0, 'tax' => 0, 'total' => 25, 'paid_amount' => 0, 'balance_due' => 25,
            'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/sales/invoices?customer_id='.$customerId)
            ->assertOk()
            ->assertExactJson([]);

        $this->getJson('/api/v1/sales/history?customer_id='.$customerId)
            ->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/sales/invoices?customer_id='.$customerId)
            ->assertOk()->assertExactJson([]);

        $this->getJson('/api/v1/sales/invoices/'.$invoiceId.'/items')->assertNotFound();
    }

    public function test_open_receivables_are_tenant_scoped_and_reduce_linked_returns_once(): void
    {
        [$organization, $user] = $this->organizationUser('Receivables Tenant A');
        [$foreignOrganization] = $this->organizationUser('Receivables Tenant B');
        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $organization->id, 'code' => 'REC-CUS-A',
            'name' => 'عميل مديونية', 'normalized_name' => 'عميل مديونية', 'phone' => '01000000000',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId, 'organization_id' => $organization->id, 'code' => 'REC-WH-A',
            'name' => 'Receivables WH A', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (string) Str::ulid();
        DB::table('sales_invoices')->insert([
            'id' => $invoiceId, 'organization_id' => $organization->id, 'customer_id' => $customerId,
            'source_location_id' => $locationId, 'document_number' => 'SI-REC-100',
            'status' => 'posted', 'invoice_date' => now()->toDateString(), 'posted_at' => now(),
            'subtotal' => 100, 'discount' => 0, 'tax' => 0, 'total' => 100,
            'paid_amount' => 25, 'balance_due' => 50, 'currency' => 'EGP',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_returns')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'customer_id' => $customerId,
            'source_location_id' => $locationId, 'original_sales_invoice_id' => $invoiceId,
            'document_number' => 'SR-REC-25', 'status' => 'posted', 'return_date' => now()->toDateString(),
            'subtotal' => 25, 'discount' => 0, 'tax' => 0, 'total' => 25, 'currency' => 'EGP',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignCustomerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $foreignCustomerId, 'organization_id' => $foreignOrganization->id, 'code' => 'REC-CUS-B',
            'name' => 'Foreign receivables customer', 'normalized_name' => 'foreign receivables customer',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $foreignLocationId, 'organization_id' => $foreignOrganization->id, 'code' => 'REC-WH-B',
            'name' => 'Receivables WH B', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_invoices')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $foreignOrganization->id, 'customer_id' => $foreignCustomerId,
            'source_location_id' => $foreignLocationId, 'document_number' => 'SI-REC-FOREIGN',
            'status' => 'posted', 'invoice_date' => now()->toDateString(), 'subtotal' => 900,
            'discount' => 0, 'tax' => 0, 'total' => 900, 'paid_amount' => 0, 'balance_due' => 900,
            'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales/open-balances?q=REC-CUS-A&per_page=10')
            ->assertOk()
            ->assertJsonPath('total_receivable', 50)
            ->assertJsonPath('open_invoice_count', 1)
            ->assertJsonPath('customer_count', 1)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('invoices.0.id', $invoiceId)
            ->assertJsonPath('invoices.0.remaining_receivable', 50)
            ->assertJsonPath('invoices.0.returned_total', 25);

        $this->getJson('/api/v1/sales/open-balances?q=Foreign%20receivables')->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('total_receivable', 50);
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
}
