<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_accepts_operation_and_returns_authoritative_ack(): void
    {
        [$organization, $user, $deviceUuid] = $this->foundation();

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'sync-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['trip_id' => 'trip-test', 'customer_id' => 'customer-test'],
        ];

        $response = $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $payload);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'accepted',
                'operation_uuid' => $payload['operation_uuid'],
                'authoritative' => true,
            ]);

        $this->assertDatabaseHas('sync_operations', [
            'organization_id' => $organization->id,
            'operation_uuid' => $payload['operation_uuid'],
            'operation_type' => 'field.visit',
            'idempotency_key' => $payload['idempotency_key'],
            'status' => 'accepted',
        ]);
    }

    public function test_sync_retry_returns_same_ack_without_creating_duplicate_operation(): void
    {
        [$organization, $user, $deviceUuid] = $this->foundation();

        $payload = [
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'retry-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['trip_id' => 'trip-test', 'customer_id' => 'customer-test'],
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
    }

    public function test_sync_rejects_reuse_of_operation_uuid_with_different_payload(): void
    {
        [, $user, $deviceUuid] = $this->foundation();

        $uuid = (string) Str::uuid();
        $base = [
            'operation_uuid' => $uuid,
            'operation_type' => 'field.visit',
            'schema_version' => 1,
            'idempotency_key' => 'conflict-' . Str::uuid(),
            'client_created_at' => now()->toIso8601String(),
            'payload' => ['trip_id' => 'trip-test', 'customer_id' => 'customer-a'],
        ];

        $this->actingAs($user)->withHeader('X-Device-UUID', $deviceUuid)
            ->postJson('/api/v1/sync/operations', $base)->assertStatus(202);

        $changed = $base;
        $changed['payload']['customer_id'] = 'customer-b';

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
