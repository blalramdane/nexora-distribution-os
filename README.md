# NEXORA Distribution OS

NEXORA Distribution OS is a vertical SaaS operating system for field distribution businesses, starting with electrical tools / home-appliance distribution in Egypt.

## Core workflow

**Supplier → Warehouse → Vehicle → Trip → Route → Customer → Sale → Collection → Return → Settlement**

## Product principles

- Vehicle is a first-class Mobile Warehouse.
- Trip is a first-class business object.
- Inventory is ledger-based.
- Transactions are atomic, auditable, and idempotent.
- Field operations are Offline-first.
- Customer and Supplier ledgers are connected to operational transactions.
- Minimal-click data entry is a product requirement.
- WhatsApp is a communication layer, not the source of truth.
- AI is added after reliable transactional data exists.
- The legacy system is a future migration source, not an architecture dependency.

## Current status

Planning and domain design are complete through UX architecture. Production implementation has **not** started yet.

### Source of Truth

1. `docs/architecture/MASTER_BLUEPRINT_v1.md`
2. `docs/architecture/DOMAIN_MODEL_ERD_v1.md`
3. `docs/architecture/TRANSACTION_ENGINE_v1.md`
4. `docs/architecture/UX_SCREEN_ARCHITECTURE_v1.md`

### NEXORA context

The `docs/brain/` directory contains the current NEXORA company/product/growth context used while designing this vertical.

## Next engineering gate

Before production feature coding:

**Technical Implementation Architecture v1**

It must lock:
- repository structure
- Laravel modules
- database migrations
- API contracts
- services/commands
- events/outbox
- PWA architecture
- IndexedDB schema
- sync protocol
- testing strategy
- CI/CD
- security
- observability

## Product roadmap

### MVP
- Catalog
- Customers / Suppliers
- Warehouses / Vehicles
- Purchases / Returns
- Sales / Returns
- Payments / Allocations
- Inventory ledger
- Customer/Supplier ledgers
- Vehicle loading
- Trips / Settlement
- Field PWA
- Offline sync
- Basic maps
- PDF invoices
- Dashboard / reports
- Audit log

### Later
- Official WhatsApp API
- Route optimization
- Smart Load
- Customer intelligence
- Forecasting
- Profit intelligence
- Advanced vertical templates

## Architecture direction

**Modular Monolith + Transaction Engine + Ledger Projections + Offline Sync**

The system should be implemented as a reusable NEXORA core plus Distribution-specific modules, not as an isolated one-off ERP.
