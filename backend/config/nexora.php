<?php

return [
    'outbox' => [
        // Local development records a delivery marker without making external calls.
        // Set NEXORA_OUTBOX_CHANNEL=webhook only after configuring a trusted HTTPS endpoint.
        'channel' => env('NEXORA_OUTBOX_CHANNEL', 'log'),
        'destination' => env('NEXORA_OUTBOX_DESTINATION', 'nexora-domain-events'),
        'webhook_url' => env('NEXORA_OUTBOX_WEBHOOK_URL'),
        'webhook_secret' => env('NEXORA_OUTBOX_WEBHOOK_SECRET'),
        'max_attempts' => (int) env('NEXORA_OUTBOX_MAX_ATTEMPTS', 8),
        'lease_seconds' => (int) env('NEXORA_OUTBOX_LEASE_SECONDS', 300),
        'retry_base_seconds' => (int) env('NEXORA_OUTBOX_RETRY_BASE_SECONDS', 15),
        'retry_max_seconds' => (int) env('NEXORA_OUTBOX_RETRY_MAX_SECONDS', 3600),
    ],
];
