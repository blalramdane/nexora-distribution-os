<?php

namespace App\Services\Outbox;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OutboxDeliveryService
{
    /** @return array{provider_reference:?string} */
    public function deliver(object $message): array
    {
        $payload = json_decode((string) $message->payload_json, true, 512, JSON_THROW_ON_ERROR);

        if ($message->channel === 'log') {
            // Do not emit business payloads (which may include financial/contact data) to logs.
            Log::info('NEXORA outbox event delivered locally', [
                'outbox_id' => $message->id,
                'organization_id' => $message->organization_id,
                'event_type' => $message->event_type,
                'aggregate_type' => $message->aggregate_type,
                'aggregate_id' => $message->aggregate_id,
            ]);

            return ['provider_reference' => 'log:'.$message->id];
        }

        if ($message->channel !== 'webhook') {
            throw new PermanentOutboxDeliveryException('Unsupported outbox channel.');
        }

        $url = trim((string) config('nexora.outbox.webhook_url', ''));
        $secret = (string) config('nexora.outbox.webhook_secret', '');
        if ($url === '' || $secret === '') {
            throw new \RuntimeException('Outbox webhook endpoint and signing secret are not configured.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (! isset($parts['host']) || ($scheme !== 'https' && ! ($scheme === 'http' && $isLoopback)) || isset($parts['user']) || isset($parts['pass'])) {
            throw new PermanentOutboxDeliveryException('Outbox webhook must use HTTPS (HTTP is allowed only for loopback tests).');
        }

        $body = json_encode([
            'id' => $message->id,
            'destination' => $message->destination,
            'event' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $response = Http::connectTimeout(3)->timeout(10)->withHeaders([
            'Content-Type' => 'application/json',
            'X-NEXORA-Event-ID' => (string) $message->id,
            'X-NEXORA-Timestamp' => $timestamp,
            'X-NEXORA-Signature' => 'sha256='.$signature,
            // Consumers must deduplicate this value: outbox delivery is at-least-once.
            'Idempotency-Key' => (string) $message->id,
        ])->withBody($body, 'application/json')->post($url);

        if ($response->status() === 408 || $response->status() === 425 || $response->status() === 429 || $response->serverError()) {
            throw new \RuntimeException('Outbox webhook returned a retryable HTTP '.$response->status().'.');
        }
        if ($response->failed()) {
            throw new PermanentOutboxDeliveryException('Outbox webhook rejected the event with HTTP '.$response->status().'.');
        }

        return ['provider_reference' => mb_substr((string) ($response->header('X-Request-ID') ?: 'http:'.$response->status()), 0, 255)];
    }
}
