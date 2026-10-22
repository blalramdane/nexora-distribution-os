<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_health_response_has_browser_security_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)')
            ->assertHeader('X-RateLimit-Limit', '60');
    }

    public function test_validation_error_responses_keep_api_security_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)')
            ->assertHeader('X-RateLimit-Limit', '5');
    }

    public function test_authenticated_api_uses_a_per_user_rate_limit(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Rate Limit Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Rate Limit User',
            'email' => 'rate-limit@nexora.test',
            'phone' => '01000001234',
            'password' => 'test-password',
            'status' => 'active',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.12'])
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '180')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_login_route_keeps_its_stricter_five_attempts_per_minute_middleware(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/auth/login', 'POST'));

        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
    }
}
