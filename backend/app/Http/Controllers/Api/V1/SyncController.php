<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SyncController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'operation_uuid' => ['required', 'uuid'],
            'operation_type' => ['required', 'string', 'max:128'],
            'schema_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'client_created_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);

        $organizationId = $request->user()->organization_id;
        $deviceUuid = $request->header('X-Device-UUID');

        if (!$deviceUuid) {
            throw ValidationException::withMessages([
                'device_uuid' => ['X-Device-UUID is required for offline sync.'],
            ]);
        }

        $device = DB::table('devices')
            ->where('organization_id', $organizationId)
            ->where('device_uuid', $deviceUuid)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if (!$device) {
            throw ValidationException::withMessages([
                'device_uuid' => ['The device is not registered or has been revoked.'],
            ]);
        }

        if ($device->user_id && $device->user_id !== $request->user()->id) {
            throw ValidationException::withMessages([
                'device_uuid' => ['The device is assigned to another user.'],
            ]);
        }

        $payloadJson = json_encode(
            $data['payload'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $payloadHash = hash('sha256', $payloadJson);

        return DB::transaction(function () use ($data, $organizationId, $device, $request, $payloadHash) {
            $existing = DB::table('sync_operations')
                ->where('organization_id', $organizationId)
                ->where('operation_uuid', $data['operation_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (!hash_equals($existing->payload_hash, $payloadHash)
                    || $existing->operation_type !== $data['operation_type']
                    || $existing->idempotency_key !== $data['idempotency_key']) {
                    throw ValidationException::withMessages([
                        'operation_uuid' => ['The operation UUID was reused with different data.'],
                    ]);
                }

                return $this->acknowledge($existing);
            }

            $sameKey = DB::table('sync_operations')
                ->where('organization_id', $organizationId)
                ->where('operation_type', $data['operation_type'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($sameKey) {
                if (!hash_equals($sameKey->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['The idempotency key was reused with different data.'],
                    ]);
                }

                return $this->acknowledge($sameKey);
            }

            $id = (string) Str::ulid();
            DB::table('sync_operations')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'operation_uuid' => $data['operation_uuid'],
                'device_id' => $device->id,
                'user_id' => $request->user()->id,
                'operation_type' => $data['operation_type'],
                'schema_version' => $data['schema_version'],
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $payloadHash,
                'payload_reference' => null,
                'client_created_at' => $data['client_created_at'],
                'received_at' => now(),
                'status' => 'accepted',
                'server_transaction_uuid' => null,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('devices')
                ->where('id', $device->id)
                ->update(['last_seen_at' => now(), 'updated_at' => now()]);

            return response()->json([
                'status' => 'accepted',
                'operation_uuid' => $data['operation_uuid'],
                'sync_operation_id' => $id,
                'server_transaction_uuid' => null,
                'authoritative' => true,
            ], 202);
        });
    }

    private function acknowledge(object $operation)
    {
        return response()->json([
            'status' => $operation->status,
            'operation_uuid' => $operation->operation_uuid,
            'sync_operation_id' => $operation->id,
            'server_transaction_uuid' => $operation->server_transaction_uuid,
            'rejection_code' => $operation->rejection_code,
            'authoritative' => true,
        ], 200);
    }
}
