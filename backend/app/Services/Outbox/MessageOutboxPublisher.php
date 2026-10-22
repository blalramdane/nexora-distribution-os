<?php

namespace App\Services\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writes a durable domain event on the caller's current database connection.
 * Call this from inside the posting transaction so document and event either
 * commit together or roll back together.
 */
final class MessageOutboxPublisher
{
    public function publish(
        string $organizationId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $data,
        ?string $dedupeKey = null,
        ?string $channel = null,
        ?string $destination = null,
    ): string {
        $channel ??= (string) config('nexora.outbox.channel', 'log');
        $destination ??= (string) config('nexora.outbox.destination', 'nexora-domain-events');
        if (! in_array($channel, ['log', 'webhook'], true)) {
            throw ValidationException::withMessages(['outbox.channel' => ['قناة الـ Outbox غير مدعومة.']]);
        }
        if (trim($eventType) === '' || trim($aggregateType) === '' || trim($destination) === '') {
            throw ValidationException::withMessages(['outbox' => ['نوع الحدث والمصدر والوجهة مطلوبة.']]);
        }

        $dedupeKey ??= $aggregateType.':'.$aggregateId.':'.$eventType;
        if (strlen($dedupeKey) > 255) {
            $dedupeKey = hash('sha256', $dedupeKey);
        }

        $existingId = DB::table('message_outbox')
            ->where('organization_id', $organizationId)
            ->where('dedupe_key', $dedupeKey)
            ->value('id');
        if ($existingId) {
            return (string) $existingId;
        }

        $id = (string) Str::ulid();
        $payload = [
            'event_id' => $id,
            'event_type' => $eventType,
            'organization_id' => $organizationId,
            'aggregate' => ['type' => $aggregateType, 'id' => $aggregateId],
            'occurred_at' => now()->toISOString(),
            'data' => $data,
        ];

        // insertOrIgnore makes concurrent publishers with the same tenant-scoped
        // dedupe key converge to the persisted row rather than duplicate events.
        DB::table('message_outbox')->insertOrIgnore([
            'id' => $id,
            'organization_id' => $organizationId,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'dedupe_key' => $dedupeKey,
            'channel' => $channel,
            'destination' => mb_substr($destination, 0, 255),
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'available_at' => now(),
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storedId = DB::table('message_outbox')
            ->where('organization_id', $organizationId)
            ->where('dedupe_key', $dedupeKey)
            ->value('id');
        if (! $storedId) {
            throw new \RuntimeException('The transactional outbox event could not be persisted.');
        }

        return (string) $storedId;
    }
}
