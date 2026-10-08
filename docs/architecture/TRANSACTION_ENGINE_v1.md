# NEXORA Distribution OS — Transaction Engine v1

## Posting pipeline
Command → Validate → Calculate → Post Atomically → Project → Emit Event

## Lifecycle
Draft → Validated → Posted → Reversed/Returned

Only Posted documents affect official ledgers.

## Transaction header
transaction_uuid, organization_id, document_number, type, status, occurred_at, posted_at, created_by, device_id, source, idempotency_key, reference.

## Atomicity
All core effects of a posting happen in one database transaction. Failure rolls back the whole posting.

## Sale
A posted sale creates:
- Inventory OUT from source location.
- Revenue effect.
- COGS using a unit_cost_snapshot.
- Customer receivable.
- Payment effects for cash/bank/etc.
- Trip sales effect when applicable.
- Audit record.

## Purchase
Creates stock receipt into a location and supplier payable, plus payment effects when paid.

## Returns
Prefer linking to the original document. A sales return moves stock back to a valid location, reverses revenue/COGS and creates customer credit or refund effect.

## Payments
Payment is a first-class transaction. It moves money through a financial account and credits the customer/supplier ledger. Payment allocations determine which documents are settled.

## Vehicle load
Warehouse → Vehicle. Load references the generated stock movements.

## Settlement
Settlement reconciles:
- opening stock + loads + returns - sales - transfers - adjustments = closing stock
- opening cash + collections - expenses = closing cash
Settlement never rewrites historical sales.

## Idempotency
Every retryable mutation has a unique organization_id + idempotency_key constraint. A retry returns the original result instead of creating a duplicate.

## Offline
Field device creates a local operation UUID and idempotency key. Local state is marked pending_sync. Server validates authoritatively. Conflicts are explicit and never silently overwritten.

## Reversal
Posted transactions are not edited or deleted. Corrections use reversal, return or explicit adjustment transactions.

## Outbox
Core transaction commits first. External side effects (WhatsApp, webhooks, notifications) are emitted through a durable outbox after the transaction is committed.

## Invariants
No sale without stock effect; no purchase without receipt; no payment without financial account; no stock balance change without movement; no duplicate idempotency key; no posted hard delete; offline ACK references the exact operation UUID.
