# ADR-0003 — Operation-Based Offline Sync

## Context

Field representatives must work with unreliable connectivity. Financial and inventory operations cannot depend on continuous connectivity, but the server must remain authoritative.

## Decision

Use operation-based offline synchronization. The field device stores immutable operation envelopes with stable UUIDs, queues them, and retries when online. The server validates and posts operations idempotently.

Read data is scoped to the assigned device/user/trip. The client maintains a cursor for server changes.

Financial conflicts are never silently merged.

## Alternatives

- Online-only: rejected because it breaks field distribution workflows.
- Full database replication: rejected because it makes authorization and conflict handling unsafe.
- CRDT-style automatic merging: rejected because financial transactions require explicit business semantics.

## Consequences

Positive: reliable field operation, duplicate-safe retries, explicit conflict handling.
Trade-offs: sync is a first-class subsystem and some operations may need user resolution.
