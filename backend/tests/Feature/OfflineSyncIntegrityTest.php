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

    public function test_offline_field_visit_rejects_a_completed_trip_without_creating_a_visit(): void
    {
        [$organization, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();
        DB::table('trips')->where('organization_id', $organization->id)->where('id', $tripId)
            ->update(['status' => 'completed', 'updated_at' => now()]);

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'completed-trip-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => [
                'path' => '/field/visits',
                'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited'],
            ],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonValidationErrors(['trip_id']);

        $this->assertSame(0, DB::table('customer_visits')->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('sync_operations', [
            'organization_id' => $organization->id,
            'operation_uuid' => $payload['operation_uuid'],
            'status' => 'rejected',
            'rejection_code' => 'validation_error',
        ]);
    }

    public function test_direct_field_visit_rejects_a_completed_trip_and_invalid_coordinates(): void
    {
        [$organization, $user, , $tripId, $customerId] = $this->fieldVisitFoundation();

        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign Field Visit Tenant', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $foreignCustomerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $foreignCustomerId, 'organization_id' => $foreignOrganization->id,
            'code' => 'FIELD-FOREIGN-CUS', 'name' => 'Foreign field customer',
            'normalized_name' => 'foreign field customer', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/v1/field/visits', [
            'trip_id' => $tripId,
            'customer_id' => $foreignCustomerId,
            'status' => 'visited',
        ])->assertNotFound();

        $this->actingAs($user)->postJson('/api/v1/field/visits', [
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'status' => 'visited',
            'latitude' => 31.2,
            'longitude' => 31.6,
        ])->assertOk();

        DB::table('trips')->where('organization_id', $organization->id)->where('id', $tripId)
            ->update(['status' => 'completed', 'updated_at' => now()]);

        $this->postJson('/api/v1/field/visits', [
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'status' => 'visited',
        ])->assertNotFound();

        $this->postJson('/api/v1/field/visits', [
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'status' => 'visited',
            'latitude' => 91,
            'longitude' => 31,
        ])->assertUnprocessable()->assertJsonValidationErrors(['latitude']);

        $this->assertSame(1, DB::table('customer_visits')->where('organization_id', $organization->id)->count());
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

    public function test_sync_rejects_reuse_of_operation_uuid_with_different_schema_version(): void
    {
        [, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();
        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'schema-version-op-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['path' => '/field/visits', 'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited']],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertStatus(202);

        $payload['schema_version'] = 2;
        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation_uuid']);

        $this->assertSame(1, DB::table('customer_visits')->where('organization_id', $user->organization_id)->count());
    }

    public function test_sync_rejects_reuse_of_idempotency_key_with_different_schema_version(): void
    {
        [, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();
        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'schema-version-key-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['path' => '/field/visits', 'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited']],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertStatus(202);

        $payload['operation_uuid'] = (string) Str::uuid();
        $payload['schema_version'] = 2;
        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertSame(1, DB::table('customer_visits')->where('organization_id', $user->organization_id)->count());
    }

    public function test_sync_rejects_a_new_operation_uuid_reusing_an_existing_idempotency_key(): void
    {
        [, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();
        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'duplicate-key-new-uuid-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['path' => '/field/visits', 'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited']],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertStatus(202);

        $payload['operation_uuid'] = (string) Str::uuid();
        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertSame(1, DB::table('customer_visits')->where('organization_id', $user->organization_id)->count());
        $this->assertSame(1, DB::table('sync_operations')->where('organization_id', $user->organization_id)->count());
    }

    public function test_sync_operation_replay_is_bound_to_the_original_user_and_device(): void
    {
        [$organization, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();
        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'actor-bound-replay-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['path' => '/field/visits', 'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited']],
        ];
        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertStatus(202);

        $otherUser = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Other Same-Tenant User',
            'email' => 'other-sync-' . Str::uuid() . '@nexora.test',
            'phone' => '010' . random_int(10000000, 99999999),
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        $this->grantTestPermissions($otherUser);
        $otherDeviceUuid = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'user_id' => $otherUser->id, 'device_uuid' => $otherDeviceUuid,
            'name' => 'Other user device', 'platform' => 'web', 'status' => 'active',
            'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($otherUser)->withHeader('X-Device-UUID', $otherDeviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation_uuid']);
        $this->assertSame(1, DB::table('customer_visits')->where('organization_id', $organization->id)->count());
    }

    public function test_replaying_a_rejected_sync_operation_returns_the_same_public_errors(): void
    {
        [, $user, $deviceUuid, $tripId, $customerId] = $this->fieldVisitFoundation();
        DB::table('trips')->where('id', $tripId)->update(['status' => 'completed', 'updated_at' => now()]);
        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'rejected-replay-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['path' => '/field/visits', 'body' => ['trip_id' => $tripId, 'customer_id' => $customerId, 'status' => 'visited']],
        ];

        $first = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertStatus(422)->json();
        $retry = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)->assertOk()->json();

        $this->assertSame('rejected', $retry['status']);
        $this->assertSame($first['rejection_code'], $retry['rejection_code']);
        $this->assertSame($first['errors'], $retry['errors']);
        $this->assertSame(0, DB::table('customer_visits')->where('organization_id', $user->organization_id)->count());
    }

    public function test_unexpected_sync_exception_does_not_expose_internal_error_details(): void
    {
        [$organization, $user, $deviceUuid] = $this->foundation();
        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'POST /sales',
            'schema_version' => 1,
            'idempotency_key' => 'internal-error-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['path' => '/sales', 'body' => ['customer_id' => (string) Str::ulid(), 'location_id' => (string) Str::ulid(), 'items' => []]],
        ];

        $response = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('rejection_code', 'transaction_error')
            ->assertJsonPath('errors.sync.0', 'The operation could not be processed. Please retry or contact support with the operation UUID.');

        $this->assertStringNotContainsString('SQL secret table', $response->getContent());
        $this->assertDatabaseHas('sync_operations', [
            'organization_id' => $organization->id,
            'operation_uuid' => $payload['operation_uuid'],
            'status' => 'rejected',
            'rejection_code' => 'transaction_error',
        ]);
        $storedErrors = DB::table('sync_operations')->where('operation_uuid', $payload['operation_uuid'])->value('rejection_details');
        $this->assertStringNotContainsString('SQL secret table', $storedErrors);
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

    public function test_sync_cannot_use_a_device_registered_to_another_tenant(): void
    {
        [, $user] = $this->foundation();
        $foreignOrganization = Organization::query()->create([
            'name' => 'Foreign Device Tenant', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $foreignUser = User::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign Device User',
            'email' => 'foreign-device-' . Str::uuid() . '@nexora.test',
            'phone' => '01000000009', 'password' => 'secret-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($foreignUser);
        $foreignDeviceUuid = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $foreignOrganization->id,
            'user_id' => $foreignUser->id, 'device_uuid' => $foreignDeviceUuid,
            'name' => 'Foreign tenant device', 'platform' => 'web', 'status' => 'active',
            'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'foreign-device-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['path' => '/field/visits', 'body' => []],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $foreignDeviceUuid)
            ->postJson('/api/v1/sync/operations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_uuid']);

        $this->assertSame(0, DB::table('sync_operations')->where('operation_uuid', $payload['operation_uuid'])->count());
    }

    public function test_field_today_does_not_expose_another_tenants_trip(): void
    {
        [, $userA] = $this->foundation();
        $organizationB = Organization::query()->create([
            'name' => 'Field Today Foreign Tenant', 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
        $userB = User::query()->create([
            'organization_id' => $organizationB->id,
            'name' => 'Foreign Field Rep',
            'email' => 'field-today-foreign-' . Str::uuid() . '@nexora.test',
            'phone' => '01000000010', 'password' => 'secret-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($userB);

        $warehouseId = (string) Str::ulid();
        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            ['id' => $warehouseId, 'organization_id' => $organizationB->id, 'code' => 'TODAY-WH', 'name' => 'Foreign warehouse', 'type' => 'warehouse', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $vehicleLocationId, 'organization_id' => $organizationB->id, 'code' => 'TODAY-VAN', 'name' => 'Foreign vehicle stock', 'type' => 'vehicle', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organizationB->id,
            'location_id' => $vehicleLocationId, 'code' => 'TODAY-VEHICLE',
            'plate_number' => 'TODAY-1', 'name' => 'Foreign today vehicle', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId, 'organization_id' => $organizationB->id, 'trip_number' => 'TODAY-TRIP',
            'vehicle_id' => $vehicleId, 'rep_user_id' => $userB->id, 'status' => 'active',
            'trip_date' => now()->toDateString(), 'origin_location_id' => $warehouseId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($userA)->getJson('/api/v1/field/today')
            ->assertOk()
            ->assertJsonPath('trip', null)
            ->assertJsonPath('customers', [])
            ->assertJsonPath('stock', [])
            ->assertDontSee($tripId)
            ->assertDontSee($vehicleId);
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
