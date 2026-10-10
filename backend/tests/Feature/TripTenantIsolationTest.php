<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class TripTenantIsolationTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_trip_cannot_be_created_using_another_organizations_vehicle(): void
    {
        $organizationA = $this->organization('Trip Owner A');
        $userA = User::query()->create([
            'organization_id' => $organizationA->id,
            'name' => 'Trip Manager A',
            'email' => 'trip-a-'.Str::uuid().'@nexora.test',
            'phone' => '01000000011',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($userA);

        $organizationB = $this->organization('Trip Owner B');
        $userB = User::query()->create([
            'organization_id' => $organizationB->id,
            'name' => 'Trip Manager B',
            'email' => 'trip-b-'.Str::uuid().'@nexora.test',
            'phone' => '01000000012',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organizationB->id,
            'code' => 'FOREIGN-TRIP-WH',
            'name' => 'Foreign warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organizationB->id,
            'location_id' => $locationId,
            'code' => 'FOREIGN-TRIP-VAN',
            'plate_number' => 'FOREIGN-TRIP',
            'name' => 'Foreign vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/trips', [
                'vehicle_id' => $vehicleId,
                'rep_user_id' => $userB->id,
                'origin_location_id' => $locationId,
                'trip_date' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id']);

        $this->assertDatabaseCount('trips', 0);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
    }
}
