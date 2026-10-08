# Project Status — NEXORA Distribution OS

## Current phase
Phase 0 — Product / Domain / UX Definition.

## Completed
- Product vision and positioning
- Distribution workflow
- Module map
- Roles direction
- Master data and geography model
- Warehouse/Vehicle location model
- Inventory ledger
- Customer/Supplier ledgers
- Purchase/Sales/Payment/Return rules
- Trip/Load/Settlement
- Offline/idempotency direction
- UX architecture
- MVP boundaries

## Not implemented
- Laravel application
- Database migrations
- API
- Admin frontend
- Field PWA
- IndexedDB sync engine
- Automated test suite
- CI/CD
- Deployment environments
- Observability
- WhatsApp integration
- Map provider integration

## Remaining P0 decisions
- Exact Laravel/PHP versions
- Final migration order
- API contract
- Error code catalog
- Sync protocol
- Event/outbox contract
- Security threat model
- Costing policy approval
- Tax/VAT rules and invoice requirements
- Document numbering
- Negative-stock policy
- Adjustment/approval thresholds
- Credit-limit policy
- Real workflow validation with representative data

## Important
The old system is currently unavailable. Do not block development on it. Later, create a migration adapter and reconcile opening balances, inventory and historical transactions.
