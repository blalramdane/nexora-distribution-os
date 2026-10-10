<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\GrantsTestPermissions;

class OfflineSyncIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use GrantsTestPermissions;

    public function test_authenticated_user_can_register_a_new_field_device(): void
    {
        [$organization, $user] = $this->foundation();
        $deviceUuid = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/api/v1/sync/device', [
            'device_uuid' => $deviceUuid,
            'name' => 'Field Browser',
            'platform' => 'web',
            'app_version' => '0.1.0',
        ]);

        $response->assertOk()->assertJson([
            'registered' => true,
            'device_uuid' => $deviceUuid,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('devices', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'device_uuid' => $deviceUuid,
            'status' => 'active',
        ]);
    }

    public function test_device_registration_is_idempotent_for_the_same_user(): void
    {
        [, $user] = $this->foundation();
        $deviceUuid = (string) Str::uuid();
        $payload = ['device_uuid' => $deviceUuid, 'platform' => 'web'];

        $this->actingAs($user)->postJson('/api/v1/sync/device', $payload)->assertOk();
        $this->actingAs($user)->postJson('/api/v1/sync/device', $payload)->assertOk();

        $this->assertSame(1, DB::table('devices')->where('device_uuid', $deviceUuid)->count());
    }

    public function test_device_registration_cannot_take_over_another_users_device(): void
    {
        [, $firstUser, $deviceUuid] = $this->foundation();
        $secondUser = User::query()->create([
            'organization_id' => $firstUser->organization_id,
            'name' => 'Second Sync User',
            'email' => 'second-sync-' . Str::uuid() . '@nexora.test',
            'phone' => '010' . random_int(10000000, 99999999),
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($secondUser);

        $this->actingAs($secondUser)->postJson('/api/v1/sync/device', [
            'device_uuid' => $deviceUuid,
            'platform' => 'web',
        ])->assertStatus(422)->assertJsonValidationErrors(['device_uuid']);

        $this->assertDatabaseHas('devices', [
            'device_uuid' => $deviceUuid,
            'user_id' => $firstUser->id,
        ]);
    }

    public function test_revoked_device_cannot_be_reactivated_by_registration(): void
    {
        [, $user, $deviceUuid] = $this->foundation();
        DB::table('devices')->where('device_uuid', $deviceUuid)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/v1/sync/device', [
            'device_uuid' => $deviceUuid,
            'platform' => 'web',
        ])->assertStatus(422)->assertJsonValidationErrors(['device_uuid']);

        $this->assertDatabaseHas('devices', [
            'device_uuid' => $deviceUuid,
            'status' => 'revoked',
        ]);
    }

    public function test_sync_persists_authoritative_field_visit_before_acknowledging(): void
    {
        [$organization, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'sync-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'path' => '/field/visits',
                'body' => [
                    'trip_id' => $tripId,
                    'customer_id' => $customerId,
                    'status' => 'visited',
                    'notes' => 'Offline visit synced',
                ],
            ],
        ];

        $response = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'accepted',
                'operation_uuid' => $payload['operation_uuid'],
                'result_reference' => 'customer_visit:' . DB::table('customer_visits')->value('id'),
                'authoritative' => true,
            ]);

        $this->assertDatabaseHas('sync_operations', [
            'organization_id' => $organization->id,
            'operation_uuid' => $payload['operation_uuid'],
            'operation_type' => 'field.visit',
            'idempotency_key' => $payload['idempotency_key'],
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('customer_visits', [
            'organization_id' => $organization->id,
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'user_id' => $user->id,
            'status' => 'visited',
            'notes' => 'Offline visit synced',
        ]);
        $this->assertDatabaseHas('trip_customers', [
            'organization_id' => $organization->id,
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'visit_status' => 'visited',
        ]);
    }

    public function test_sync_retry_returns_same_ack_without_duplicate_visit_or_operation(): void
    {
        [$organization, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'retry-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'path' => '/field/visits',
                'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited'],
            ],
        ];

        $first = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertStatus(202)->json();

        $second = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertStatus(200)->json();

        $this->assertSame($first['operation_uuid'], $second['operation_uuid']);
        $this->assertSame($first['sync_operation_id'], $second['sync_operation_id']);
        $this->assertSame(1, DB::table('sync_operations')
            ->where('organization_id', $organization->id)
            ->where('operation_uuid', $payload['operation_uuid'])
            ->count());
        $this->assertSame(1, DB::table('customer_visits')
            ->where('organization_id', $organization->id)
            ->where('trip_id', $tripId)
            ->where('customer_id', $customerId)
            ->count());
    }

    public function test_sync_rejects_reuse_of_operation_uuid_with_different_payload(): void
    {
        [, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();

        $uuid = (string) Str::uuid();
        $base = [
            'operation_uuid' => $uuid,
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'conflict-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'path' => '/field/visits',
                'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited'],
            ],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $base)->assertStatus(202);

        $changed = $base;
        $changed['payload']['body']['notes'] = 'changed payload';

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $changed)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation_uuid']);
    }

    public function test_sync_rejects_revoked_device(): void
    {
        [, $user, $deviceUuid] = $this->foundation();
        DB::table('devices')->where('device_uuid', $deviceUuid)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'revoked-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['trip_id' => 'trip-test'],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_uuid']);
    }

    private function fieldVisitFoundation(): array
    {
        [$organization, $user, $deviceUuid] = $this->foundation();

        $locationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'organization_id' => $organization->id,
            'code' => 'FIELD-WH-' . substr($locationId, -6),
            'name' => 'Field Test Location',
            'type' => 'warehouse',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $organization->id,
            'location_id' => $locationId,
            'code' => 'FIELD-V-' . substr($vehicleId, -6),
            'plate_number' => 'FIELD-' . substr($vehicleId, -6),
            'name' => 'Field Test Vehicle',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId,
            'organization_id' => $organization->id,
            'trip_number' => 'FIELD-TR-' . substr($tripId, -6),
            'vehicle_id' => $vehicleId,
            'rep_user_id' => $user->id,
            'status' => 'active',
            'trip_date' => now()->toDateString(),
            'origin_location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'organization_id' => $organization->id,
            'code' => 'FIELD-C-' . substr($customerId, -6),
            'name' => 'Field Visit Customer',
            'normalized_name' => 'field visit customer',
            'phone' => null,
            'address_text' => 'Test address',
            'credit_limit' => 0,
            'payment_terms_days' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('trip_customers')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'sequence' => 1,
            'planned' => true,
            'visit_status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organization, $user, $deviceUuid, $tripId, $customerId];
    }

    private function foundation(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Offline Sync Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Sync Test User',
            'email' => 'sync-test-' . $organization->id . '@nexora.test',
            'phone' => '01000000003',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($user);

        $deviceUuid = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'device_uuid' => $deviceUuid,
            'name' => 'Test Field Device',
            'platform' => 'web',
            'app_version' => 'test',
            'status' => 'active',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organization, $user, $deviceUuid];
    }
}
