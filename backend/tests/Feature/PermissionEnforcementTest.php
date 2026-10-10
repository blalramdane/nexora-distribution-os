<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_without_permission_cannot_read_products(): void
    {
        $user = $this->userInOrganization('Permission Test A');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('roles', [])
            ->assertJsonPath('permissions', []);

        $this->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_user_with_permission_on_own_organization_can_read_products(): void
    {
        $user = $this->userInOrganization('Permission Test B');
        $this->grant($user, 'products.view');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products')
            ->assertOk();

        $this->postJson('/api/v1/products', [])->assertForbidden();
    }

    public function test_role_from_another_organization_does_not_grant_permission(): void
    {
        $user = $this->userInOrganization('Permission Test C');
        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign Role Organization',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
        $permission = Permission::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'products.view',
            'name' => 'View products',
        ]);
        $foreignRole = Role::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign manager',
            'key' => 'manager',
        ]);
        $foreignRole->permissions()->attach($permission->id);

        if (DB::getDriverName() === 'mysql') {
            $this->expectException(QueryException::class);
        }
        $user->roles()->attach($foreignRole->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('roles', [])
            ->assertJsonPath('permissions', []);

        $this->getJson('/api/v1/products')
            ->assertForbidden();
    }

    public function test_field_sync_permission_alone_cannot_post_a_sale_offline(): void
    {
        $user = $this->userInOrganization('Sync Permission Test');
        $this->grant($user, 'field.sync');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sync/operations', [
                'operation_uuid' => (string) Str::uuid(),
                'operation_type' => 'POST /sales',
                'schema_version' => 1,
                'idempotency_key' => 'unauthorized-offline-sale',
                'client_created_at' => now()->toIso8601String(),
                'payload' => ['body' => []],
            ])
            ->assertForbidden();
    }

    public function test_expense_list_and_post_require_separate_permissions(): void
    {
        $user = $this->userInOrganization('Expense Permission Test');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/expenses')->assertForbidden();
        $this->postJson('/api/v1/expenses', [])->assertForbidden();
        $this->grant($user, 'expenses.view');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/expenses')->assertOk()->assertExactJson([]);
        $this->postJson('/api/v1/expenses', [])->assertForbidden();
        $postPermission = Permission::query()->firstOrCreate(['key' => 'expenses.post'], ['id' => (string) Str::ulid(), 'name' => 'Post expenses']);
        $user->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$postPermission->id]);
        $this->postJson('/api/v1/expenses', [])->assertUnprocessable();
    }

    private function userInOrganization(string $name): User
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        return User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Permission Tester',
            'email' => Str::uuid().'@nexora.test',
            'phone' => '01000000000',
            'password' => 'test-password',
            'status' => 'active',
        ]);
    }

    private function grant(User $user, string $permissionKey): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['id' => (string) Str::ulid(), 'name' => $permissionKey],
        );
        $role = Role::query()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Test role',
            'key' => 'test-role-'.$user->id,
        ]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
    }
}
