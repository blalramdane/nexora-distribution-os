<?php

namespace App\Services\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MessageOutboxProcessor
{
    public function __construct(private readonly OutboxDeliveryService $delivery)
    {
    }

    /** @return array{claimed:int,sent:int,retried:int,failed:int} */
    public function processBatch(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $workerId = (string) Str::ulid();
        $now = now();
        $staleBefore = $now->copy()->subSeconds(max(30, (int) config('nexora.outbox.lease_seconds', 300)));

        $messages = DB::transaction(function () use ($limit, $workerId, $now, $staleBefore): array {
            $rows = DB::table('message_outbox')
                ->where(function ($query) use ($now, $staleBefore): void {
                    $query->where(function ($pending) use ($now): void {
                        $pending->where('status', 'pending')->where('available_at', '<=', $now);
                    })->orWhere(function ($expired) use ($staleBefore): void {
                        $expired->where('status', 'processing')->where('claimed_at', '<=', $staleBefore);
                    });
                })
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $claimed = [];
            foreach ($rows as $row) {
                $attemptNumber = (int) $row->attempt_count + 1;
                if ($row->status === 'processing') {
                    DB::table('outbox_attempts')->where('organization_id', $row->organization_id)
                        ->where('outbox_id', $row->id)->where('status', 'processing')
                        ->update([
                            'finished_at' => $now,
                            'status' => 'abandoned',
                            'error' => 'Worker lease expired; the message was reclaimed for another attempt.',
                            'updated_at' => $now,
                        ]);
                }
                DB::table('message_outbox')->where('id', $row->id)->where('organization_id', $row->organization_id)->update([
                    'status' => 'processing',
                    'attempt_count' => $attemptNumber,
                    'claimed_at' => $now,
                    'claimed_by' => $workerId,
                    'updated_at' => $now,
                ]);

                $attemptId = (string) Str::ulid();
                DB::table('outbox_attempts')->insert([
                    'id' => $attemptId,
                    'organization_id' => $row->organization_id,
                    'outbox_id' => $row->id,
                    'attempt_number' => $attemptNumber,
                    'started_at' => $now,
                    'finished_at' => null,
                    'status' => 'processing',
                    'provider_reference' => null,
                    'error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row->attempt_count = $attemptNumber;
                $row->attempt_id = $attemptId;
                $row->claimed_by = $workerId;
                $claimed[] = $row;
            }

            return $claimed;
        }, attempts: 3);

        $result = ['claimed' => count($messages), 'sent' => 0, 'retried' => 0, 'failed' => 0];
        foreach ($messages as $message) {
            try {
                $delivery = $this->delivery->deliver($message);
                $completed = $this->finishSuccess($message, $workerId, $delivery['provider_reference'] ?? null);
                if ($completed) $result['sent']++;
            } catch (PermanentOutboxDeliveryException $exception) {
                if ($this->finishFailure($message, $workerId, $exception, true)) $result['failed']++;
            } catch (\Throwable $exception) {
                $terminal = (int) $message->attempt_count >= max(1, (int) config('nexora.outbox.max_attempts', 8));
                if ($this->finishFailure($message, $workerId, $exception, $terminal)) {
                    $result[$terminal ? 'failed' : 'retried']++;
                }
            }
        }

        return $result;
    }

    private function finishSuccess(object $message, string $workerId, ?string $providerReference): bool
    {
        return DB::transaction(function () use ($message, $workerId, $providerReference): bool {
            $current = DB::table('message_outbox')->where('id', $message->id)
                ->where('organization_id', $message->organization_id)
                ->where('status', 'processing')->where('claimed_by', $workerId)
                ->lockForUpdate()->first();
            if (! $current) return false;

            $now = now();
            DB::table('message_outbox')->where('id', $message->id)->update([
                'status' => 'sent', 'sent_at' => $now, 'last_error' => null,
                'claimed_at' => null, 'claimed_by' => null, 'updated_at' => $now,
            ]);
            DB::table('outbox_attempts')->where('organization_id', $message->organization_id)
                ->where('outbox_id', $message->id)->where('attempt_number', $message->attempt_count)
                ->update(['finished_at' => $now, 'status' => 'sent', 'provider_reference' => $providerReference, 'updated_at' => $now]);

            return true;
        }, attempts: 3);
    }

    private function finishFailure(object $message, string $workerId, \Throwable $exception, bool $terminal): bool
    {
        return DB::transaction(function () use ($message, $workerId, $exception, $terminal): bool {
            $current = DB::table('message_outbox')->where('id', $message->id)
                ->where('organization_id', $message->organization_id)
                ->where('status', 'processing')->where('claimed_by', $workerId)
                ->lockForUpdate()->first();
            if (! $current) return false;

            $now = now();
            $attemptNumber = (int) $message->attempt_count;
            $base = max(1, (int) config('nexora.outbox.retry_base_seconds', 15));
            $maxDelay = max($base, (int) config('nexora.outbox.retry_max_seconds', 3600));
            $delay = min($maxDelay, $base * (2 ** min(12, max(0, $attemptNumber - 1))));
            // Do not persist stack traces, credentials or request bodies in delivery errors.
            $safeError = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $exception->getMessage()) ?: 'Outbox delivery failed.', 0, 1000);

            DB::table('message_outbox')->where('id', $message->id)->update([
                'status' => $terminal ? 'failed' : 'pending',
                'available_at' => $terminal ? $now : $now->copy()->addSeconds($delay),
                'last_error' => $safeError,
                'claimed_at' => null, 'claimed_by' => null, 'updated_at' => $now,
            ]);
            DB::table('outbox_attempts')->where('organization_id', $message->organization_id)
                ->where('outbox_id', $message->id)->where('attempt_number', $attemptNumber)
                ->update(['finished_at' => $now, 'status' => $terminal ? 'failed' : 'retry', 'error' => $safeError, 'updated_at' => $now]);

            return true;
        }, attempts: 3);
    }
}
