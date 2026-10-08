<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_access_protected_endpoint(): void
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

        $response = $this->postJson('/api/v1/auth/login', [
            'organization_id' => $organization->id,
            'login' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()->assertJsonStructure(['token','token_type','user']);

        $token = $response->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }
}