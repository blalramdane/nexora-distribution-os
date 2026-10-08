# NEXORA Distribution OS — Autonomous Engineering Task Loop

> This file is the execution contract between NEXORA AI and the coding agent working on this repository.

## Mission

Build **NEXORA Distribution OS** as a production-grade vertical SaaS for field distribution businesses.

Do not build a generic ERP clone. Build the operating system around:

**Supplier → Warehouse → Vehicle → Trip → Route → Customer → Sale → Collection → Return → Settlement**

The final system must feel like a serious commercial product: fast, reliable, Arabic RTL, mobile-first, offline-capable, auditable, secure, scalable, and maintainable.

---

# 1. Agent Operating Contract

When this file assigns a task:

1. Read `README.md`.
2. Read `PROJECT_STATUS.md`.
3. Read all files under `docs/architecture/`.
4. Read `docs/context/NEXORA_DISTRIBUTION_CONTEXT.md`.
5. Inspect the existing repository before changing architecture.
6. Implement the task completely.
7. Do not stop at scaffolding.
8. Run relevant tests.
9. Run build/type/static checks.
10. Verify the actual behavior.
11. Update this file with:
   - Status
   - What changed
   - Files changed
   - Tests executed
   - Build result
   - Verification result
   - Remaining issues
   - Next recommended task
12. Update `PROJECT_STATUS.md` when project state changes.
13. Commit work with a clear conventional commit message.

## Non-negotiable engineering rules

- Never bypass the Transaction Engine for posted inventory/financial operations.
- Never mutate stock balances without stock movements.
- Never hard-delete posted transactions.
- Retryable mutations must be idempotent.
- Server is authoritative after synchronization.
- Preserve domain contracts.
- Do not introduce unnecessary dependencies.
- Do not rewrite working architecture without evidence.
- Keep tenant isolation enforced server-side.
- Sensitive actions must be audited.
- No fake "live" state when a device is offline; show last sync.
- No feature is complete without tests and verification.

---

# 2. Product Quality Bar

The system should target:

### UX
- Arabic RTL first-class support.
- Mobile-first.
- Desktop keyboard-first where appropriate.
- Minimal clicks/taps.
- Fast search/autocomplete.
- Smart defaults.
- Excel-like bulk entry for high-volume operations.
- Clear loading, empty, offline, syncing, conflict and error states.
- No unnecessary modal chains.

### Reliability
- Atomic business transactions.
- Idempotency.
- Optimistic/concurrency protection where required.
- Durable outbox for external side effects.
- Audit trail.
- Rebuildable projections.

### Security
- Strong authentication.
- Authorization by role/permission.
- Tenant isolation.
- Device/session management.
- Secure document access.
- No secrets in source control.
- Validation on server.

### Performance
- Indexed queries.
- Paginated large lists.
- Debounced search.
- Avoid N+1.
- Efficient mobile payloads.
- Offline datasets scoped to assigned user/trip.

---

# 3. Master Delivery Roadmap

## PHASE 0 — Foundation
- [x] Product blueprint
- [x] Domain model
- [x] Transaction engine specification
- [x] UX architecture
- [x] Repository governance
- [ ] Technical implementation architecture
- [ ] ADR set
- [ ] Database specification
- [ ] API contract
- [ ] Sync protocol

## PHASE 1 — Backend Foundation
- [ ] Laravel application
- [ ] Database
- [ ] Tenant model
- [ ] Auth/session
- [ ] Roles/permissions
- [ ] Audit
- [ ] Idempotency
- [ ] Core transaction infrastructure
- [ ] Outbox/events
- [ ] API foundation

## PHASE 2 — Master Data
- [ ] Products/categories/units
- [ ] Barcodes/aliases
- [ ] Supplier-product mappings
- [ ] Customers
- [ ] Suppliers
- [ ] Egypt geography
- [ ] Warehouses
- [ ] Vehicles
- [ ] Financial accounts/payment methods

## PHASE 3 — Inventory
- [ ] Stock movements
- [ ] Stock balances
- [ ] Transfers
- [ ] Stocktake
- [ ] Costing
- [ ] Inventory valuation
- [ ] Vehicle stock

## PHASE 4 — Purchasing
- [ ] Purchase invoices
- [ ] Bulk/grid entry
- [ ] Supplier pricing history
- [ ] Purchase returns
- [ ] Supplier ledger
- [ ] Payment allocations

## PHASE 5 — Sales & Finance
- [ ] Sales invoices
- [ ] Cash/credit/mixed payment
- [ ] Sales returns
- [ ] Customer ledger
- [ ] Receivables
- [ ] Expenses
- [ ] Profit/COGS

## PHASE 6 — Distribution
- [ ] Trips
- [ ] Vehicle loading
- [ ] Customer assignment
- [ ] Visits
- [ ] Collections
- [ ] Returns
- [ ] Trip expenses
- [ ] Settlement
- [ ] Variance handling

## PHASE 7 — Field PWA
- [ ] PWA shell
- [ ] Authentication/device
- [ ] IndexedDB
- [ ] Offline read model
- [ ] Local transactions
- [ ] Sync queue
- [ ] Conflict handling
- [ ] Mobile sale
- [ ] Collection
- [ ] Return
- [ ] Customer creation
- [ ] Location capture

## PHASE 8 — Admin Web
- [ ] Dashboard
- [ ] Global search
- [ ] Command palette
- [ ] Tables/filters
- [ ] Customer 360
- [ ] Supplier 360
- [ ] Inventory
- [ ] Trips
- [ ] Reports
- [ ] Settings

## PHASE 9 — Integrations
- [ ] PDF documents
- [ ] Excel exports/imports
- [ ] Secure document links
- [ ] Official WhatsApp API
- [ ] Maps provider abstraction
- [ ] Navigation
- [ ] Route optimization

## PHASE 10 — Intelligence
- [ ] Smart Load
- [ ] Customer intelligence
- [ ] Route intelligence
- [ ] Profit intelligence
- [ ] Forecasting
- [ ] AI assistant

## PHASE 11 — Production
- [ ] CI/CD
- [ ] Staging
- [ ] Production
- [ ] Backups
- [ ] Monitoring
- [ ] Error tracking
- [ ] Security review
- [ ] Load testing
- [ ] E2E critical paths
- [ ] Release checklist
- [ ] Migration tooling
- [ ] Pilot deployment

---

# 4. CURRENT ACTIVE TASK

## TASK ID
`TASK-001`

## Title
Technical Implementation Architecture v1

## Priority
P0 — BLOCKING FOUNDATION

## Objective

Convert the existing product/domain blueprint into a concrete implementation architecture that another engineer can use to start production development without guessing.

## Deliverables

Create/update:

`docs/architecture/TECHNICAL_IMPLEMENTATION_ARCHITECTURE_v1.md`

It must define:

### Backend
- Laravel/PHP version recommendation and rationale.
- Modular monolith structure.
- Domain/Application/Infrastructure responsibilities.
- Module boundaries.
- Service/command/query conventions.
- Validation strategy.
- Authorization strategy.
- Transaction boundaries.
- Idempotency implementation.
- Domain events.
- Outbox.
- Queue strategy.
- Error handling.
- Logging/observability.

### Database
- MySQL version target.
- UUID strategy.
- Primary/foreign keys.
- Money/decimal policy.
- Timestamp/timezone policy.
- Soft delete policy.
- Audit strategy.
- Indexing strategy.
- Projection/rebuild strategy.
- Transaction isolation/concurrency strategy.

### API
- `/api/v1` conventions.
- Authentication.
- Pagination.
- Filtering/sorting/search.
- Error envelope.
- Idempotency header.
- Request correlation ID.
- Resource naming.
- Versioning policy.

### Admin Web
Recommend and justify the frontend architecture.
Define:
- routing
- server/client data strategy
- forms
- tables
- command palette
- RTL/i18n
- permissions
- error/loading states

### Field PWA
Define:
- app shell
- service worker
- IndexedDB schema
- local transaction model
- operation queue
- sync lifecycle
- conflict model
- retry behavior
- device identity
- local data security
- online/offline UX

### Infrastructure
Define:
- local Docker setup
- staging
- production
- database
- Redis
- queue workers
- object storage
- backups
- secrets
- CI/CD
- monitoring

### Testing
Define:
- unit
- integration
- feature
- transaction invariant
- concurrency
- sync
- E2E
- load/simulation
- security

### ADRs

Create:
`docs/adr/0001-modular-monolith.md`
`docs/adr/0002-transaction-ledger.md`
`docs/adr/0003-offline-sync.md`
`docs/adr/0004-vehicle-as-location.md`
`docs/adr/0005-outbox-external-side-effects.md`

Each ADR must contain:
- Context
- Decision
- Alternatives
- Consequences

## Acceptance Criteria

TASK-001 is complete only when:

- Architecture document exists and is internally consistent with all existing architecture docs.
- Every major technology choice has rationale.
- Backend module boundaries are explicit.
- Database rules are explicit.
- API conventions are explicit.
- Offline/sync architecture is explicit.
- Security and observability are explicit.
- ADRs exist.
- Documentation has no unresolved "TODO" for decisions that this task is responsible for.
- Documentation can be used as the implementation contract for TASK-002.

## Verification

Run documentation consistency checks and any repository validation available.

Do not start full production feature implementation during TASK-001.

## Status

**COMPLETE — PENDING NEXORA AI REVIEW**

## Agent report

### Status
TASK-001 implementation complete.

### Changed
Created the production implementation architecture contract and all five required ADRs. Locked the primary technology choices and implementation boundaries without starting production feature coding.

### Files changed
- docs/architecture/TECHNICAL_IMPLEMENTATION_ARCHITECTURE_v1.md
- docs/adr/0001-modular-monolith.md
- docs/adr/0002-transaction-ledger.md
- docs/adr/0003-offline-sync.md
- docs/adr/0004-vehicle-as-location.md
- docs/adr/0005-outbox-external-side-effects.md
- docs/architecture/IMPLEMENTATION_ARCHITECTURE_TODO.md
- PROJECT_STATUS.md
- TASK_LOOP.md

### Tests
Documentation verification performed by re-fetching all six TASK-001 deliverables from the default branch and checking that the files exist and are readable. No application test suite was run because no production application exists yet.

### Build
Not applicable to TASK-001; this was a documentation-only architecture gate.

### Verification
Verified:
- Architecture file exists.
- All five ADRs exist.
- Backend, database, API, Admin Web, Field PWA, infrastructure, testing, security and observability are covered.
- Core decisions match the existing blueprint/domain/transaction/UX contracts.
- No TASK-001 decision is left as an unresolved implementation TODO.
- TASK-002 is explicitly defined as the next gate.

### Remaining issues
TASK-002 must define the exact database schema, migration order, constraints, indexes, ledger tables, sync tables and seed/reference strategy. Business policy decisions that affect schema (tax/VAT, numbering, costing, negative stock, approvals and credit limits) must be explicitly represented rather than guessed.

### Next task
TASK-002 — Database Schema & Migration Specification.

### TASK-002 additional mandatory requirement
The schema specification must model real distribution packaging:
- canonical base stock unit
- carton/package units
- pieces-per-carton conversion
- carton and piece barcodes
- carton/piece/mixed purchase, sale and return quantities
- transaction-line conversion snapshots
- base-unit inventory movements and costing
- historical immutability when packaging definitions change

---

# 5. Task Completion Protocol

## Agent report

### Status
Pending implementation.

### Changed
Pending.

### Files changed
Pending.

### Tests
Pending.

### Build
Pending.

### Verification
Pending.

### Remaining issues
Pending.

### Next task
After TASK-001 passes review: TASK-002 — Database Schema & Migration Specification.

---

# 5. Task Completion Protocol

When the current task is complete, replace the "Agent report" section with real evidence.

Then move:

`[ ] TASK-X` → `[x] TASK-X`

and activate exactly ONE next task.

Never mark a task complete without evidence.

If blocked, write:
- BLOCKED
- exact blocker
- evidence
- smallest required resolution

Do not hide blockers.

---

# 6. Definition of Done — Whole Product

The project is NOT done when the UI exists.

The whole product is done only when:

1. Core transactions are correct.
2. Inventory reconciles.
3. Customer/supplier ledgers reconcile.
4. Vehicle stock reconciles.
5. Trip settlement reconciles.
6. Offline operations sync safely.
7. Duplicate operations are prevented.
8. Permissions are enforced.
9. Audit trail works.
10. Critical workflows have E2E tests.
11. Reports reconcile against transaction data.
12. Backups are tested.
13. Production deployment is reproducible.
14. Security review passes.
15. Pilot users can complete real workflows efficiently.

---

# 7. NEXORA AI Review Loop

After every agent completion:

**Agent implementation → Evidence → NEXORA AI review → Fix/approve → Next task**

The agent must never assume that "code written" means "task complete".

NEXORA AI should review:
- architecture consistency
- business-rule correctness
- security
- performance
- UX
- test coverage
- regressions
- scope discipline

If the task is incomplete, the next instruction is a correction task, not the next roadmap task.
