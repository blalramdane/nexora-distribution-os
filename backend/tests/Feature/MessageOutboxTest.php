<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Outbox\MessageOutboxProcessor;
use App\Services\Outbox\MessageOutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessageOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_event_is_durable_in_the_callers_transaction_and_rolls_back_with_it(): void
    {
        $organization = $this->organization();
        $publisher = app(MessageOutboxPublisher::class);

        try {
            DB::transaction(function () use ($publisher, $organization): void {
                $publisher->publish($organization->id, 'test.transaction.rolled_back', 'test_document', (string) Str::ulid(), ['value' => 1]);
                throw new \RuntimeException('Force outer posting transaction rollback.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Force outer posting transaction rollback.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('message_outbox')->where('organization_id', $organization->id)->count());
    }

    public function test_publisher_deduplicates_repeated_event_identity_within_a_tenant(): void
    {
        $organization = $this->organization();
        $publisher = app(MessageOutboxPublisher::class);
        $aggregateId = (string) Str::ulid();

        $first = $publisher->publish($organization->id, 'sales.invoice.posted', 'sales_invoice', $aggregateId, ['total' => 125]);
        $second = $publisher->publish($organization->id, 'sales.invoice.posted', 'sales_invoice', $aggregateId, ['total' => 125]);

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('message_outbox')->where('organization_id', $organization->id)
            ->where('dedupe_key', 'sales_invoice:'.$aggregateId.':sales.invoice.posted')->count());
    }

    public function test_worker_delivers_local_log_events_and_persists_attempt_history(): void
    {
        $organization = $this->organization();
        $id = app(MessageOutboxPublisher::class)->publish(
            $organization->id, 'sales.invoice.posted', 'sales_invoice', (string) Str::ulid(),
            ['document_number' => 'SI-TEST', 'total' => 200], null, 'log', 'test-log',
        );

        $result = app(MessageOutboxProcessor::class)->processBatch(10);

        $this->assertSame(['claimed' => 1, 'sent' => 1, 'retried' => 0, 'failed' => 0], $result);
        $this->assertDatabaseHas('message_outbox', [
            'organization_id' => $organization->id, 'id' => $id,
            'status' => 'sent', 'attempt_count' => 1, 'claimed_by' => null,
        ]);
        $this->assertDatabaseHas('outbox_attempts', [
            'organization_id' => $organization->id, 'outbox_id' => $id,
            'attempt_number' => 1, 'status' => 'sent', 'provider_reference' => 'log:'.$id,
        ]);
    }

    public function test_webhook_delivery_is_signed_and_carries_a_stable_idempotency_key(): void
    {
        Config::set('nexora.outbox.webhook_url', 'https://hooks.example.test/nexora');
        Config::set('nexora.outbox.webhook_secret', 'test-only-outbox-secret');
        Http::fake(['https://hooks.example.test/*' => Http::response(['accepted' => true], 202, ['X-Request-ID' => 'hook-123'])]);

        $organization = $this->organization();
        $id = app(MessageOutboxPublisher::class)->publish(
            $organization->id, 'finance.payment.posted', 'payment', (string) Str::ulid(),
            ['amount' => '10.0000', 'currency' => 'EGP'], null, 'webhook', 'tenant-domain-events',
        );

        $result = app(MessageOutboxProcessor::class)->processBatch(10);

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('message_outbox', ['id' => $id, 'status' => 'sent']);
        Http::assertSent(function (Request $request) use ($id): bool {
            $timestamp = (string) $request->header('X-NEXORA-Timestamp')[0];
            $expectedSignature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'test-only-outbox-secret');
            return $request->url() === 'https://hooks.example.test/nexora'
                && $request->header('Idempotency-Key') === [$id]
                && $request->header('X-NEXORA-Event-ID') === [$id]
                && $request->header('X-NEXORA-Signature') === [$expectedSignature]
                && str_contains($request->body(), 'finance.payment.posted');
        });
        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_id' => $id, 'status' => 'sent', 'provider_reference' => 'hook-123',
        ]);
    }

    public function test_missing_webhook_configuration_is_retried_with_backoff_not_marked_sent(): void
    {
        Config::set('nexora.outbox.webhook_url', null);
        Config::set('nexora.outbox.webhook_secret', null);
        $organization = $this->organization();
        $id = app(MessageOutboxPublisher::class)->publish(
            $organization->id, 'sales.invoice.posted', 'sales_invoice', (string) Str::ulid(),
            ['total' => 50], null, 'webhook', 'tenant-domain-events',
        );

        $result = app(MessageOutboxProcessor::class)->processBatch(10);

        $this->assertSame(1, $result['retried']);
        $this->assertDatabaseHas('message_outbox', [
            'id' => $id, 'status' => 'pending', 'attempt_count' => 1,
            'last_error' => 'Outbox webhook endpoint and signing secret are not configured.',
        ]);
        $outbox = DB::table('message_outbox')->where('id', $id)->first();
        $this->assertGreaterThan(now()->subSecond()->timestamp, strtotime((string) $outbox->available_at));
        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_id' => $id, 'attempt_number' => 1, 'status' => 'retry',
        ]);
    }

    public function test_exhausted_delivery_moves_to_failed_and_stale_claims_are_recoverable(): void
    {
        $organization = $this->organization();
        $publisher = app(MessageOutboxPublisher::class);
        $exhaustedId = $publisher->publish($organization->id, 'test.exhausted', 'test_document', (string) Str::ulid(), [], null, 'webhook', 'test');
        DB::table('message_outbox')->where('id', $exhaustedId)->update(['attempt_count' => 7]);
        Config::set('nexora.outbox.webhook_url', null);
        Config::set('nexora.outbox.webhook_secret', null);

        $failed = app(MessageOutboxProcessor::class)->processBatch(10);
        $this->assertSame(1, $failed['failed']);
        $this->assertDatabaseHas('message_outbox', ['id' => $exhaustedId, 'status' => 'failed', 'attempt_count' => 8]);

        $staleId = $publisher->publish($organization->id, 'test.stale_claim', 'test_document', (string) Str::ulid(), [], null, 'log', 'test');
        DB::table('message_outbox')->where('id', $staleId)->update([
            'status' => 'processing', 'attempt_count' => 1,
            'claimed_at' => now()->subMinutes(10), 'claimed_by' => 'abandoned-worker',
        ]);
        DB::table('outbox_attempts')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'outbox_id' => $staleId,
            'attempt_number' => 1, 'started_at' => now()->subMinutes(11), 'finished_at' => null,
            'status' => 'processing', 'provider_reference' => null, 'error' => null,
            'created_at' => now()->subMinutes(11), 'updated_at' => now()->subMinutes(11),
        ]);
        $recovered = app(MessageOutboxProcessor::class)->processBatch(10);
        $this->assertSame(1, $recovered['sent']);
        $this->assertDatabaseHas('message_outbox', ['id' => $staleId, 'status' => 'sent', 'attempt_count' => 2]);
        $this->assertDatabaseHas('outbox_attempts', ['outbox_id' => $staleId, 'attempt_number' => 1, 'status' => 'abandoned']);
        $this->assertDatabaseHas('outbox_attempts', ['outbox_id' => $staleId, 'attempt_number' => 2, 'status' => 'sent']);
    }

    private function organization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Outbox '.Str::random(8), 'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'country_code' => 'EG', 'status' => 'active',
        ]);
    }
}
