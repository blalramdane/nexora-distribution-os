<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SyncController extends Controller
{
    public function store(Request $request, TransactionPostingService $posting)
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

        return DB::transaction(function () use ($data, $organizationId, $device, $request, $payloadHash, $posting) {
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
                'status' => 'received',
                'server_transaction_uuid' => null,
                'rejection_code' => null,
                'rejection_details' => null,
                'processed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                if ($data['operation_type'] === 'field.visit') {
                    $result = ['operation_type' => 'field.visit', 'status' => 'accepted'];
                } else {
                    $result = $this->dispatchTransaction(
                    $organizationId,
                    $request->user()->id,
                    $device->id,
                    $data,
                    $posting
                    );
                }

                $reference = $this->resultReference($data['operation_type'], $result);

                DB::table('sync_operations')
                    ->where('id', $id)
                    ->update([
                        'status' => 'accepted',
                        'payload_reference' => $reference,
                        'processed_at' => now(),
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
                    'result_reference' => $reference,
                    'result' => $result,
                    'authoritative' => true,
                ], 202);
            } catch (Throwable $e) {
                $code = $e instanceof ValidationException ? 'validation_error' : 'transaction_error';
                $errors = $e instanceof ValidationException
                    ? $e->errors()
                    : ['sync' => [$e->getMessage()]];

                DB::table('sync_operations')
                    ->where('id', $id)
                    ->update([
                        'status' => 'rejected',
                        'rejection_code' => $code,
                        'rejection_details' => json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return response()->json([
                    'status' => 'rejected',
                    'operation_uuid' => $data['operation_uuid'],
                    'sync_operation_id' => $id,
                    'server_transaction_uuid' => null,
                    'rejection_code' => $code,
                    'errors' => $errors,
                    'authoritative' => true,
                ], 422);
            }
        });
    }

    private function dispatchTransaction(
        string $organizationId,
        string $userId,
        string $deviceId,
        array $sync,
        TransactionPostingService $posting
    ): array {
        $body = $sync['payload']['body'] ?? null;

        if (!is_array($body)) {
            throw ValidationException::withMessages([
                'payload.body' => ['Offline transaction payload must contain a body object.'],
            ]);
        }

        $body['idempotency_key'] = $sync['idempotency_key'];
        $body['created_by'] = $userId;
        $body['device_id'] = $deviceId;

        return match ($sync['operation_type']) {
            'POST /sales' => $posting->postSale($organizationId, $body),
            'POST /payments' => $posting->postPayment($organizationId, $body),
            'POST /returns/sales' => $posting->postSalesReturn($organizationId, $body),
            'POST /returns/purchases' => $posting->postPurchaseReturn($organizationId, $body),
            'POST /purchases' => $posting->postPurchase($organizationId, $body),
            'POST /trip-loads' => $posting->postTripLoad($organizationId, $body),
            default => throw ValidationException::withMessages([
                'operation_type' => ['Unsupported offline transaction type.'],
            ]),
        };
    }

    private function resultReference(string $operationType, array $result): ?string
    {
        if (!isset($result['id'])) return null;

        return match ($operationType) {
            'POST /sales' => 'sales_invoice:' . $result['id'],
            'POST /payments' => 'payment:' . $result['id'],
            'POST /returns/sales' => 'sales_return:' . $result['id'],
            'POST /returns/purchases' => 'purchase_return:' . $result['id'],
            'POST /purchases' => 'purchase_invoice:' . $result['id'],
            'POST /trip-loads' => 'trip_load:' . $result['id'],
            default => null,
        };
    }

    private function acknowledge(object $operation)
    {
        return response()->json([
            'status' => $operation->status,
            'operation_uuid' => $operation->operation_uuid,
            'sync_operation_id' => $operation->id,
            'server_transaction_uuid' => $operation->server_transaction_uuid,
            'rejection_code' => $operation->rejection_code,
            'result_reference' => $operation->payload_reference,
            'authoritative' => true,
        ], 200);
    }
}
