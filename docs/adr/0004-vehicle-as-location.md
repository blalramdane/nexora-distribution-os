# ADR-0004 — Vehicle as First-Class Stock Location

## Context

Vehicles carry sellable stock and behave as mobile warehouses during trips. Treating vehicles as a special sales object would duplicate inventory logic.

## Decision

Represent every warehouse and vehicle as a Location. Vehicle records reference a Location. Stock transfers between warehouse and vehicle use the same stock-movement engine as other location transfers.

Trips reference the vehicle/location and add route, customer, collection, return and settlement semantics.

## Alternatives

- Separate vehicle inventory tables: rejected because they duplicate stock logic.
- Vehicle only as a user attribute: rejected because inventory ownership must be explicit.

## Consequences

Positive: one inventory engine, exact vehicle reconciliation, reusable mobile-warehouse model.
Trade-offs: location model must support mobile state carefully.
