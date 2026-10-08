# Project Status — NEXORA Distribution OS

## Current phase
Phase 0 — Architecture Foundation complete; preparing TASK-002 Database Schema & Migration Specification.

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
- Technical implementation architecture v1
- ADR set: modular monolith, transaction ledger, offline sync, vehicle-as-location, transactional outbox
- Backend/admin/field/infrastructure/testing contracts

## Not implemented
- Laravel application
- Database migrations
- API
- Admin frontend
- Field PWA
- IndexedDB sync engine
- Automated production test suite
- CI/CD
- Deployment environments
- Observability
- WhatsApp integration
- Map provider integration

## Remaining P0 decisions / specifications
- Database specification and migration order
- Detailed API contract and error catalog
- Detailed sync protocol
- Costing policy approval
- Tax/VAT rules and invoice requirements
- Document numbering
- Negative-stock policy confirmation
- Adjustment/approval thresholds
- Credit-limit policy
- Real workflow validation with representative data

## Architecture locks
- Laravel 13 / PHP 8.3+
- MySQL 8.4 LTS
- Modular monolith
- Transaction/ledger source of truth
- Vehicle as first-class stock Location
- Operation-based offline sync
- Transactional outbox for external side effects
- React + TypeScript + Vite for Admin Web
- React + TypeScript PWA for Field

## Important
The old system is currently unavailable. Do not block development on it. Later, create a migration adapter and reconcile opening balances, inventory and historical transactions.

## Next active task
TASK-002 — Database Schema & Migration Specification.
