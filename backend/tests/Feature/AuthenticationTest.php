<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_requests_without_accept_header_still_return_json_401(): void
    {
        $this->get('/api/v1/products')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json');
    }

    public function test_user_can_login_and_access_protected_endpoint_with_roles_and_permissions(): void
    {
        $organization = Organization::query()->create([
            'name' => 'NEXORA Demo',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Owner',
            'email' => 'owner@nexora.test',
            'phone' => '01000000000',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $permission = Permission::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'sales.post',
            'name' => 'Post sales',
        ]);

        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Owner',
            'key' => 'owner',
        ]);

        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $response = $this->postJson('/api/v1/auth/login', [
            'organization_id' => $organization->id,
            'login' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'organization_id', 'name', 'roles', 'permissions'],
            ])
            ->assertJsonPath('user.roles.0.key', 'owner')
            ->assertJsonPath('user.permissions.0.key', 'sales.post');

        $token = $response->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('roles.0.key', 'owner')
            ->assertJsonPath('permissions.0.key', 'sales.post');
    }
}
