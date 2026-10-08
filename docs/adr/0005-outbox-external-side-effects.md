# ADR-0005 — Transactional Outbox for External Side Effects

## Context

Posting a sale must not fail because WhatsApp, PDF generation, maps or another external provider is unavailable. External effects must also not be lost after a successful commit.

## Decision

Write an outbox record in the same database transaction as the business fact. A worker claims and delivers the outbox item after commit with retries, backoff, idempotency and failure visibility.

External providers are adapters behind interfaces. Core posting never performs a synchronous provider call.

## Alternatives

- Provider call inside transaction: rejected because latency/failure would compromise core posting.
- Queue before commit: rejected because the worker could run before commit.
- Best-effort fire-and-forget: rejected because successful business events could be lost.

## Consequences

Positive: durable delivery, provider-independent core transactions, retry visibility.
Trade-offs: eventual consistency for external effects and outbox retention/cleanup.
