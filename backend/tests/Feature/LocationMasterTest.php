<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class LocationMasterTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_locations_endpoint_returns_active_organization_locations(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Location API Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Location Reader',
            'email' => 'location-reader-'.Str::uuid().'@nexora.test',
            'phone' => '01000000019',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organization->id,
            'code' => 'LOC-API-1',
            'name' => 'مخزن اختبار API',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonFragment(['id' => $locationId, 'code' => 'LOC-API-1']);
    }
}
