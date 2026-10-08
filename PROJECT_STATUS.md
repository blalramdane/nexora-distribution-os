# Project Status — NEXORA Distribution OS

## Current phase
Phase 1 — Backend Foundation / Database Implementation.

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
- ADR set
- UI/UX Visual Baseline v1 — locked
- Product packaging model: carton/piece/mixed quantities
- Database Schema & Migration Specification v1

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

## Architecture locks
- Laravel 13 / PHP 8.3+
- MySQL 8.4 LTS
- Modular monolith
- Transaction/ledger source of truth
- Vehicle as first-class stock Location
- Operation-based offline sync
- Transactional outbox
- React + TypeScript + Vite Admin
- React + TypeScript Field PWA
- Locked UI/UX Visual Baseline
- Base-unit inventory with carton/piece packaging conversions

## Remaining P0 business policy decisions
- Tax/VAT rules and invoice requirements
- Final costing policy approval
- Document numbering policy details
- Negative-stock policy confirmation
- Adjustment/approval thresholds
- Credit-limit enforcement policy
- Real workflow validation with representative data

These must be represented explicitly in implementation/configuration and must not be guessed.

## Important
The old system is currently unavailable. Do not block development on it. Later, create a migration adapter and reconcile opening balances, inventory and historical transactions.

## Next active task
TASK-003 — Laravel Backend Foundation + Database Migrations + Schema Tests.
