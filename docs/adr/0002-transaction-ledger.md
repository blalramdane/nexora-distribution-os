# ADR-0002 — Transaction Ledger as Source of Truth

## Context

The product must reconcile warehouse stock, vehicle stock, customer balances, supplier balances, payments and trip settlements. Mutable totals alone cannot provide sufficient auditability.

## Decision

Use posted business transactions and append-oriented ledger records as authoritative facts. Stock movements and financial ledger effects are created atomically with posting. Balances and dashboards are projections that can be rebuilt.

Posted documents are immutable. Corrections use reversals, returns or controlled adjustments.

## Alternatives

- Mutable stock/balance columns as the only truth: rejected because reconciliation and audit become fragile.
- Full event sourcing of every field change: rejected because it adds complexity beyond the business need.

## Consequences

Positive: strong reconciliation, clear audit trail, deterministic projection rebuilds.
Trade-offs: more records and explicit posting logic.
