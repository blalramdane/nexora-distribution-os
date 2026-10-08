# ADR-0001 — Modular Monolith

## Context

NEXORA has tightly coupled transactional domains: inventory, purchasing, sales, finance, vehicles and trips. Correctness requires atomic cross-domain posting, while the product is still establishing its core model.

## Decision

Use a modular monolith for the initial production architecture. Modules have explicit ownership and contracts, but run in one Laravel application and one transactional database.

Cross-module writes go through application commands/services and domain events. Controllers cannot call another controller or mutate another module's persistence directly.

## Alternatives

- Microservices: rejected now because they increase distributed-transaction, deployment, observability and offline-sync complexity before scale requires it.
- One flat Laravel app: rejected because it blurs domain ownership and makes future extraction harder.
- Separate backend per vertical: rejected because NEXORA needs a reusable core.

## Consequences

Positive: atomic transactions, simpler deployment, clear boundaries for future extraction.
Trade-offs: requires boundary discipline and dependency-leakage review.
