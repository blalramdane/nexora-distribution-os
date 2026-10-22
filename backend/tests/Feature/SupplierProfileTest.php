<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class SupplierProfileTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_supplier_profile_returns_history_summary_and_rejects_foreign_tenant(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Supplier Profile Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Supplier Profile Tester',
            'email' => 'supplier-profile-'.$organization->id.'@nexora.test',
            'phone' => '01000000009',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId,
            'organization_id' => $organization->id,
            'code' => 'SUP-PROFILE-001',
            'name' => 'مورد تجربة الملف',
            'normalized_name' => 'مورد تجربة الملف',
            'phone' => '01011112222',
            'address' => 'دمياط',
            'tax_identifier' => null,
            'credit_terms_days' => 30,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign Supplier Profile Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
        $foreignSupplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $foreignSupplierId,
            'organization_id' => $foreignOrganization->id,
            'code' => 'SUP-FOREIGN-001',
            'name' => 'Foreign supplier',
            'normalized_name' => 'foreign supplier',
            'credit_terms_days' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/suppliers/'.$supplierId.'/360')
            ->assertOk()
            ->assertJsonPath('supplier.id', $supplierId)
            ->assertJsonPath('supplier.name', 'مورد تجربة الملف')
            ->assertJsonPath('summary.invoice_count', 0)
            ->assertJsonPath('summary.open_balance', 0)
            ->assertJsonPath('invoices.data', [])
            ->assertJsonPath('payments.data', [])
            ->assertJsonPath('returns.data', []);

        $this->deleteJson('/api/v1/suppliers/'.$supplierId)
            ->assertOk()
            ->assertJsonPath('status', 'archived');
        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/v1/suppliers?status=inactive')
            ->assertOk()->assertJsonPath('0.active', 0)->assertJsonPath('0.status', 'inactive');
        $this->postJson('/api/v1/suppliers/'.$supplierId.'/restore')
            ->assertOk()->assertJsonPath('status', 'active');
        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(1);

        $this->getJson('/api/v1/suppliers/'.$foreignSupplierId.'/360')->assertNotFound();
    }
}
