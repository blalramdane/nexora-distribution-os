# NEXORA Distribution OS — Autonomous Engineering Task Loop

> **Execution Contract:** This file is the single operational roadmap between NEXORA AI (reviewer/technical owner) and the coding agent. The agent executes exactly one active Batch/Task at a time. NEXORA AI reviews the evidence before the next Batch is unlocked.

## Mission

Build **NEXORA Distribution OS** as a production-grade vertical SaaS for electrical tools and home-appliance distribution in Egypt.

Core workflow:

**Supplier → Warehouse → Vehicle → Trip → Route → Customer → Sale → Collection → Return → Settlement**

Product principles:
- Transaction-first, not CRUD-first.
- Vehicle is a first-class mobile warehouse.
- Trip is a first-class business object.
- Inventory is an append-oriented ledger.
- Carton/piece/mixed selling is native.
- Offline field work is first-class.
- Server is authoritative after sync.
- Every retryable mutation is idempotent.
- Arabic RTL + mobile-first + desktop keyboard-first.
- Minimal input → maximum result.
- External side effects use durable outbox.
- AI comes after data correctness.

---

# 1. AGENT OPERATING CONTRACT

For every Batch:

1. Read `README.md`.
2. Read `PROJECT_STATUS.md`.
3. Read `TASK_LOOP.md`.
4. Read all relevant `docs/architecture/`.
5. Read `docs/context/NEXORA_DISTRIBUTION_CONTEXT.md`.
6. Read `docs/design/UI_UX_VISUAL_BASELINE_v1.md` before UI work.
7. Inspect the existing implementation before changing it.
8. Preserve existing architecture and contracts unless evidence requires a change.
9. Implement the **entire active Batch**, not a partial scaffold.
10. Run relevant unit/feature/integration/E2E tests.
11. Run build, type checks, lint/static analysis where applicable.
12. Test failure paths, authorization, idempotency and concurrency where relevant.
13. Verify actual behavior, not only compilation.
14. Update `PROJECT_STATUS.md`.
15. Update this file with a real evidence report.
16. Commit with a conventional commit message.
17. Stop after the Batch and wait for NEXORA AI review. Do not silently jump to the next Batch.

### NEXORA AI review is mandatory

**Agent implementation → Evidence → NEXORA AI review → Correction/Approve → Next Batch**

A Batch is **not complete** because code exists.

A Batch is complete only after:
- implementation exists,
- tests pass,
- build/validation passes,
- acceptance criteria pass,
- NEXORA AI review passes.

If review fails:
**Correction Task → Agent fixes → Evidence → NEXORA AI review again.**

---

# 2. NON-NEGOTIABLE ENGINEERING RULES

- Never bypass the Transaction Engine for posted inventory/financial operations.
- Never mutate stock balances without stock movements.
- Never hard-delete posted transactions.
- Retryable mutations must be idempotent.
- Server is authoritative after synchronization.
- Every tenant-owned operation is tenant-scoped.
- Sensitive actions are audited.
- No fake "live" state while offline; show last-sync state.
- Posted documents are immutable; correct them by reversal/return/adjustment.
- Historical lines preserve unit/conversion snapshots.
- Carton and piece quantities normalize to a product base unit.
- Vehicle inventory is represented by a stock Location.
- Vehicle stock cannot go negative under the MVP policy unless an explicitly approved business policy changes this.
- External side effects use outbox/queue patterns.
- No secrets in source control.
- No uncontrolled dependency sprawl.
- No rewrite of working architecture without evidence.
- No feature is complete without tests and verification.
- No UI screen may invent a new visual language; use the locked UI baseline.

---

# 3. PRODUCT QUALITY BAR

## UX
- Arabic RTL first-class.
- Mobile-first Field PWA.
- Desktop keyboard-first Admin.
- Minimal clicks/taps.
- Fast search/autocomplete.
- Smart defaults.
- Excel-like bulk entry.
- Barcode support.
- Clear online/offline/sync/conflict states.
- No unnecessary modal chains.
- Carton, piece and mixed quantity visible and understandable.

## Reliability
- Atomic business transactions.
- Idempotency.
- Optimistic/concurrency protection.
- Durable outbox.
- Audit trail.
- Rebuildable projections.
- Deterministic posting rules.

## Security
- Authentication.
- RBAC/permissions.
- Tenant isolation.
- Device/session management.
- Secure document access.
- Server-side validation.
- Rate limiting where appropriate.
- No sensitive secrets in logs/audits.

## Performance
- Indexed queries.
- Pagination.
- Debounced search.
- No N+1.
- Efficient mobile payloads.
- Offline datasets scoped to assigned user/trip.
- Query plans verified for high-volume screens.

---

# 4. BATCH MAP — 11 BATCHES

| Batch | Name | Main outcome | Gate |
|---|---|---|---|
| 1 | Backend Foundation | Laravel + CI + base identity | CI green |
| 2 | Database Core | Complete schema + migrations + seed data | Empty DB migration green |
| 3 | Security & Platform Core | Auth + tenant + RBAC + audit + API foundation | Security/authorization tests green |
| 4 | Transaction Engine | Atomic posting + ledger + idempotency + outbox | Invariant/concurrency tests green |
| 5 | Catalog & Inventory | Products + carton/piece + stock + costing | Inventory reconciliation green |
| 6 | Purchasing | Purchases + supplier ledger + returns + allocations | Purchase reconciliation green |
| 7 | Sales & Finance | Sales + customers + payments + returns + P&L | Sales/ledger reconciliation green |
| 8 | Distribution | Vehicles + trips + loading + visits + settlement | Trip settlement reconciliation green |
| 9 | Offline Field PWA | PWA + IndexedDB + operation sync + field workflows | Offline/sync/E2E green |
| 10 | Admin Web & Reports | Production Admin UI + dashboard + reports | UX/E2E/performance gate green |
| 11 | Integrations + Intelligence + Production | WhatsApp/maps/AI + hardening + pilot | Production release gate green |

**Important:** Batch count is fixed at 11. A Batch may contain many internal implementation tasks. A correction does not create a new roadmap Batch; it remains inside the active Batch until approved.

---

# 5. BATCH 1 — BACKEND FOUNDATION

## Objective
Create a reproducible Laravel backend foundation and CI pipeline.

## Scope
- Laravel 13 / PHP 8.3+.
- Backend application structure.
- Environment configuration.
- MySQL 8.4 target.
- Basic API routing.
- Organizations.
- Users/roles/permissions/devices foundation.
- Tenant middleware foundation.
- Seeder foundation.
- PHPUnit testing foundation.
- GitHub Actions.
- Basic health endpoint.
- Composer dependency lock.

## Required implementation
- `backend/` Laravel application.
- PSR-4 autoloading.
- Environment separation.
- Config validation.
- Base API response conventions.
- Database migration baseline.
- CI install + migration + test.

## Tests
- Application boots.
- Health endpoint.
- Empty database migrations.
- Identity tables exist.
- Seeder repeatability.
- Tenant foreign key exists.
- CI on PHP 8.3 + MySQL 8.4.

## Acceptance
- Clean checkout installs.
- Migrations run from empty DB.
- Seeders run repeatedly.
- Tests pass.
- CI is green.
- No secrets committed.

## Review focus
Architecture cleanliness, Laravel version correctness, CI reproducibility, tenant foundation, dependency discipline.

---

# 6. BATCH 2 — DATABASE CORE

## Objective
Implement the full database contract from `DATABASE_SCHEMA_SPEC_v1.md`.

## Scope
### Reference/master data
- Organizations.
- Egypt governorates.
- Centers.
- Cities/areas.
- Units.
- Categories.
- Payment methods.
- Financial accounts.

### Catalog
- Products.
- Product packaging.
- Product barcodes.
- Product aliases.
- Supplier-product mappings.

### Parties
- Suppliers.
- Customers.
- Customer addresses.
- Customer location events.

### Locations
- Locations.
- Warehouses.
- Vehicles.

### Transaction infrastructure
- Document sequences.
- Idempotency keys.
- Audit logs.
- Ledger accounts.
- Ledger entries.
- Stock movements.
- Stock balances.

### Commercial documents
- Purchase invoices/items.
- Purchase returns/items.
- Sales invoices/items.
- Sales returns/items.
- Payments.
- Payment allocations.
- Expenses.

### Distribution
- Trips.
- Trip customers.
- Trip loads/items.
- Customer visits.
- Trip expenses.
- Trip settlements/lines.

### Sync/external effects
- Sync operations.
- Sync conflicts.
- Message outbox.
- Outbox attempts.

### Projections
- Customer balance summaries.
- Supplier balance summaries.
- Dashboard daily summaries.

## Packaging requirements
The schema MUST support:
- one product with a base unit,
- carton/package conversion,
- piece barcode,
- carton barcode,
- piece price,
- carton price where configured,
- 1 carton = N pieces,
- 3 cartons + 4 pieces,
- historical conversion snapshots,
- normalized base quantity,
- multiple packaging levels in the future.

Example:
**1 carton = 50 pieces → 3 cartons + 4 pieces = 154 base pieces.**

## Tests
- FK integrity.
- Tenant isolation keys.
- SKU uniqueness per tenant.
- Barcode uniqueness per tenant.
- Packaging conversion > 0.
- One active default sale/purchase packaging.
- Vehicle/location relationship.
- Idempotency uniqueness.
- Payment allocation limits.
- Return limits.
- Sync operation uniqueness.
- Outbox references.
- Decimal precision.
- Migration order from empty DB.

## Acceptance
- Full migration chain succeeds on MySQL 8.4.
- Repeatable seed data.
- Schema tests green.
- No unresolved schema TODO.
- Database contract and code are consistent.

## Review focus
Data integrity, tenant isolation, indexes, FK semantics, packaging model, financial precision, migration safety.

---

# 7. BATCH 3 — SECURITY & PLATFORM CORE

## Objective
Turn the backend foundation into a secure multi-tenant API platform.

## Scope
### Authentication
- Login.
- Logout.
- Session/token lifecycle.
- Password reset foundation.
- Session revocation.
- Device registration.
- Device revoke.
- Last-seen tracking.

### Tenant isolation
- Server-derived organization context.
- Tenant-scoped repositories/queries.
- Cross-tenant access rejection.
- Tenant-aware policies.

### RBAC
Permissions for dashboard, sales, purchases, inventory, payments, reports, settings, users, trips, vehicles, customers and suppliers.

### API foundation
- `/api/v1`.
- JSON response contract.
- Error envelope.
- Validation errors.
- Correlation/request ID.
- Pagination.
- Filtering.
- Sorting.
- Search.
- Rate limiting.
- Idempotency header.
- API versioning.

### Audit
Audit authentication/security events, permission-sensitive changes, master-data changes, reversals, inventory adjustments, device actions and sensitive corrections.

## Tests
- Authentication.
- Authorization.
- Cross-tenant isolation.
- Revoked device/session.
- Permission matrix.
- Validation errors.
- Rate limiting.
- Idempotency header validation.
- Audit records.

## Acceptance
No authenticated user can read or mutate another tenant's data, including through indirect relationships.

## Review focus
Security, privilege escalation, tenant leakage, API contract consistency, audit completeness.

---

# 8. BATCH 4 — TRANSACTION ENGINE

## Objective
Implement the core business engine that makes NEXORA financially and operationally trustworthy.

## Scope
### Posting pipeline
**Command → Validate → Calculate → Post Atomically → Project → Emit Event**

### Lifecycle
Draft → Validated → Posted → Reversed/Returned.

### Core posting
- Purchase receipt.
- Sale.
- Sales return.
- Purchase return.
- Payment.
- Transfer/load.
- Expense.
- Stock adjustment.
- Trip settlement effects.

### Inventory effects
Every stock effect creates `stock_movements`.

### Financial effects
Every financial posting creates balanced `ledger_entries`.

### Idempotency
- Organization + operation type + idempotency key.
- Request fingerprint.
- Exact retry returns original result.
- Different payload with same key is rejected.

### Outbox
- Transactional outbox write.
- Worker processing.
- Retry/backoff.
- Failure/dead-letter state.
- Provider reference.

### Concurrency
- Row locks where needed.
- Optimistic versioning where appropriate.
- Prevent duplicate posting.
- Prevent overselling.
- Prevent double allocation.

## Invariants
- No sale without stock.
- No purchase without receipt.
- No payment without financial account.
- No duplicate operation.
- No negative vehicle stock.
- No over-return.
- No over-allocation.
- Debits/credits balance.
- Projection can be rebuilt.

## Tests
- Unit calculation tests.
- Feature posting tests.
- Transaction rollback tests.
- Idempotency retries.
- Concurrency tests.
- Oversell tests.
- Return limit tests.
- Payment allocation tests.
- Ledger balance tests.
- Stock projection rebuild tests.
- Outbox atomicity tests.

## Acceptance
Critical business transactions remain correct under retries, concurrent requests and failures.

## Review focus
Highest-risk core. NEXORA AI reviews business effects at contract level before approval.

---

# 9. BATCH 5 — CATALOG & INVENTORY

## Objective
Build the warehouse/inventory engine around real distribution behavior.

## Scope
### Catalog
- Products.
- Categories.
- Units.
- Brands.
- SKUs.
- Aliases.
- Piece/carton barcodes.
- Supplier-specific mappings.

### Packaging
Support piece, carton, mixed sale, mixed purchase, conversion, package price and base quantity.

### Inventory
- Main warehouse.
- Vehicle stock.
- Stock balances.
- Stock movements.
- Transfers.
- Vehicle load/unload.
- Stocktake.
- Adjustments.
- Reserved stock where required.
- Inventory valuation.
- Cost history.

### Costing
Implement the approved costing policy. If business policy is not approved, isolate it behind a strategy/configuration boundary and do not silently invent an accounting policy.

### Fast entry
- Product search.
- Barcode.
- Alias search.
- Keyboard navigation.
- Bulk entry.
- Excel import foundation.
- Smart defaults.

## Tests
- Carton/piece conversions.
- Mixed quantities.
- Stock movement correctness.
- Warehouse-to-vehicle transfer.
- Vehicle stock.
- Stocktake.
- Negative stock prevention.
- Cost calculations.
- Inventory valuation.
- Search performance.

## Acceptance
A product can be purchased in cartons, stored as base quantities, sold by carton or piece, returned correctly, and reconciled at warehouse/vehicle level.

## Review focus
Packaging, costing, inventory accuracy, speed of data entry, warehouse/vehicle reconciliation.

---

# 10. BATCH 6 — PURCHASING

## Objective
Implement the complete supplier-side purchasing workflow.

## Scope
- Supplier management.
- Purchase invoice.
- Multiple purchase invoices.
- Supplier price history.
- Supplier-product mapping.
- Purchase grid/Excel-like entry.
- Barcode matching.
- Unknown product review.
- Carton/piece purchase.
- Purchase payment.
- Partial payment.
- Payment allocation.
- Supplier statement.
- Purchase return.
- Supplier balance.
- Supplier ledger.
- Purchase export.

## UX requirement
A 30-item purchase must NOT require opening a product creation form for every row.

Expected workflow:
**Paste/import/search → auto-match → review unknowns → confirm → post.**

## Tests
- Purchase posting.
- Supplier payable.
- Partial payment.
- Multiple allocations.
- Purchase return.
- Supplier statement.
- Carton conversion.
- Unknown product import.
- Idempotent purchase retry.

## Acceptance
Supplier balance and inventory effects reconcile to every posted purchase/return/payment.

## Review focus
Bulk-entry speed, supplier ledger correctness, payment allocation, purchase returns.

---

# 11. BATCH 7 — SALES & FINANCE

## Objective
Implement the complete customer-facing commercial and financial engine.

## Scope
### Sales
- Customer selection.
- Fast sale.
- Product search/barcode.
- Piece/carton/mixed quantity.
- Cash sale.
- Credit sale.
- Mixed payment.
- Discounts.
- Taxes once approved.
- Invoice numbering.
- Invoice history.
- Secure invoice reference.

### Customers
- Minimal-click customer creation.
- Customer 360.
- Sales history.
- Product history.
- Returns.
- Payments.
- Statement.
- Outstanding balance.
- Credit limit.
- Payment terms.
- Location.

### Finance
- Cash.
- Bank transfer.
- Postal.
- Configured payment methods.
- Payment receipts.
- Payment allocations.
- Expenses.
- Receivables.
- COGS.
- Gross profit.
- Net profit.

### Returns
- Return from original invoice.
- Partial return.
- Returnable quantity enforcement.
- Stock return.
- Financial reversal.

## Tests
- Cash sale.
- Credit sale.
- Mixed payment.
- Customer balance.
- Collection.
- Return.
- COGS.
- Gross profit.
- Expense.
- Profit reconciliation.
- Duplicate sale prevention.

## Acceptance
Customer statement, inventory, revenue, receivables, COGS and profit reconcile from authoritative transactions.

## Review focus
Money correctness, customer experience, sales speed, returns, profit calculations.

---

# 12. BATCH 8 — DISTRIBUTION

## Objective
Turn vehicles and field trips into first-class operating workflows.

## Scope
### Vehicles
- Vehicle master.
- Driver/rep assignment.
- Vehicle location.
- Vehicle stock.
- Vehicle status.

### Trips
- Create trip.
- Assign vehicle.
- Assign rep.
- Select customers.
- Route order.
- Load vehicle.
- Warehouse → vehicle transfer.
- Planned customers.
- Visits.
- Sales.
- Collections.
- Returns.
- Expenses.
- Trip progress.

### Settlement
Reconcile:

**Opening Stock + Loads + Returns + Transfers ± Adjustments − Sales = Closing Stock**

and:

**Opening Cash + Collections − Expenses = Expected Cash**

Compare against actual closing values and calculate variance.

### Map
- Customer markers.
- Trip route.
- Filters.
- Navigation handoff.
- Last-known location status.
- No fake live state.

## Tests
- Trip creation.
- Load.
- Vehicle stock.
- Customer assignment.
- Visit.
- Sale on vehicle.
- Collection.
- Return.
- Expense.
- Settlement.
- Stock variance.
- Cash variance.

## Acceptance
A complete trip can start from warehouse load and end with a reconciled vehicle/cash settlement without rewriting historical transactions.

## Review focus
Operational realism, settlement math, vehicle stock, route usability.

---

# 13. BATCH 9 — OFFLINE FIELD PWA

## Objective
Deliver the field system as a reliable mobile-first PWA that works with weak/no connectivity.

## Scope
### PWA
- App shell.
- Installability.
- Service worker.
- Cache strategy.
- Update strategy.
- Authentication/device binding.

### IndexedDB
Local stores for assigned products, packaging, customers, trip, vehicle load, drafts, operation queue, sync state, conflicts and reference data.

### Offline operations
- View assigned products.
- View assigned customers.
- View trip/load.
- Sale.
- Collection.
- Return.
- Add customer.
- Capture location.
- Visit status.

### Sync
Lifecycle:
**Local operation → queued → uploading → accepted/rejected → acknowledged → projected**

Every operation has operation UUID, device ID, user ID, schema version, client timestamp, idempotency key and payload hash.

### Conflicts
Explicit conflict states. Never silently overwrite financial transactions.

### UX
Online / Unstable / Offline / Syncing / Synced / Conflict / Failed Retry.

## Tests
- Offline boot.
- Offline read.
- Offline sale.
- Offline collection.
- Offline return.
- Queue persistence.
- Retry.
- Duplicate delivery.
- Conflict.
- Device revoke.
- Server authority after sync.
- Network interruption during upload.
- Mobile E2E.

## Acceptance
A field rep can complete a realistic trip workflow without internet and synchronize later without duplicate or lost transactions.

## Review focus
Sync semantics, conflict safety, data scope and mobile UX.

---

# 14. BATCH 10 — ADMIN WEB & REPORTS

## Objective
Build the production Admin Web using the locked NEXORA visual system.

## Stack direction
React + TypeScript + Vite unless implementation evidence justifies another choice.

## Scope
### App shell
- RTL.
- Sidebar.
- Topbar.
- User/device status.
- Global search.
- Command palette.
- Notifications.
- Responsive behavior.

### Dashboard
Sales, collections, receivables, payables, inventory value, profit, vehicles, trips, customers, alerts, stock status and recent invoices.

### Modules
Sales, purchases, inventory, customers, suppliers, vehicles, trips, collections, returns, expenses, accounts, reports, users/settings.

### Customer 360
Identity, location, balance, sales, collections, returns, products, visits, statement and navigation.

### Supplier 360
Equivalent supplier-side view.

### Tables
Search, filters, sorting, pagination, bulk actions, sticky headers, export and keyboard navigation.

### Reports
Sales, purchases, inventory, stock movement, customer balance, supplier balance, collections, expenses, gross profit, net profit, vehicle performance, trip settlement and product performance.

## UX contract
Use `docs/design/UI_UX_VISUAL_BASELINE_v1.md`. Do not create a second design system.

## Tests
- Component tests.
- API integration.
- Permission-aware rendering.
- RTL.
- Responsive/mobile.
- Critical E2E.
- Table/search performance.
- Report reconciliation.

## Acceptance
Admin can run real business operations without database access and the UI is consistent with the locked NEXORA design.

## Review focus
UX, information hierarchy, speed, Arabic RTL, business usability, visual consistency and report correctness.

---

# 15. BATCH 11 — INTEGRATIONS, INTELLIGENCE & PRODUCTION

## Objective
Turn the system into a commercial production product.

## Phase A — Documents
- PDF invoice.
- PDF statement.
- Excel export.
- Excel import.
- Secure document links.
- Expiring/signed access where appropriate.

## Phase B — WhatsApp
Use the official WhatsApp Business/API path.

Flows:
- invoice.
- statement.
- payment receipt.
- payment reminder.
- secure document link.
- queued sending.
- retry/failure tracking.

Never use unofficial WhatsApp Web automation for production.

## Phase C — Maps
- Provider abstraction.
- Customer location.
- Trip map.
- Navigation.
- Route display.
- Optional route optimization integration.

The map provider must be replaceable without rewriting business logic.

## Phase D — Intelligence
Only after core data quality is proven.

### Smart Load
Recommend vehicle loading from history, trip, customer demand, seasonality, stock and expected sales.

### Customer Intelligence
Buying frequency, product affinity, inactive customer, overdue risk and opportunity signals.

### Route Intelligence
Route performance, visit efficiency, sales by route, collection by route and customer density.

### Profit Intelligence
Product margin, customer profitability, vehicle profitability and route profitability.

### Forecasting
Demand, stock risk, receivable risk and cash expectation.

### AI Assistant
Natural-language questions over authorized business data. AI must respect tenant boundaries and permissions.

## Phase E — Production
- CI/CD.
- Staging.
- Production.
- Docker/container strategy.
- Secrets management.
- Backups.
- Restore drills.
- Redis.
- Queue workers.
- Monitoring.
- Error tracking.
- Metrics.
- Alerting.
- Security review.
- Dependency audit.
- Rate limits.
- Load testing.
- E2E critical paths.
- Migration tooling.
- Legacy import adapter.
- Pilot deployment.
- Release checklist.

## Acceptance
Production can be deployed reproducibly, restored from backup, monitored, secured and piloted with real users.

## Review focus
Security, resilience, integrations, cost, observability, AI authorization and pilot readiness.

---

# 16. CROSS-BATCH TEST MATRIX

### Inventory
Purchase → Warehouse → Vehicle Load → Sale → Return → Settlement → Reconciliation.

### Customer
Sale → Receivable → Payment → Allocation → Return → Statement.

### Supplier
Purchase → Payable → Payment → Allocation → Return → Statement.

### Finance
Sale/Purchase/Payment/Expense → Ledger → Reports.

### Offline
Offline Sale → Queue → Retry → Server Post → ACK → Projection.

### Idempotency
Same operation delivered 1x, 2x, 10x must produce one business transaction.

### Tenant isolation
Tenant A cannot read/write Tenant B through any endpoint, ID, relationship, search, export or sync payload.

### Audit
Sensitive mutation → audit event with actor/device/correlation context.

---

# 17. REQUIRED EVIDENCE FORMAT

At the end of every Batch, the agent MUST report:

## Status
COMPLETE / BLOCKED / CORRECTION REQUIRED

## Changed
Concrete implementation summary.

## Files changed
Important paths.

## Tests
Exact commands + result counts.

## Build
Exact build/type/lint/static commands + result.

## Verification
Business scenarios actually verified.

## Security
Relevant security checks.

## Performance
Relevant performance/query checks.

## Evidence
Commit SHA and CI workflow/result where available.

## Remaining issues
Only real remaining issues.

## NEXORA AI review
PENDING REVIEW / APPROVED / CORRECTION REQUIRED.

## Next task
Exactly one next Batch only after approval.

---

# 18. CORRECTION PROTOCOL

If NEXORA AI finds any issue:

1. Do not activate the next Batch.
2. Keep current Batch active.
3. Record:
   - issue,
   - evidence,
   - root cause,
   - required fix,
   - required test.
4. Agent implements correction.
5. Agent reruns tests.
6. Agent reports new evidence.
7. NEXORA AI reviews again.

A correction is part of the current Batch.

---

# 19. BUSINESS POLICY GATES

Never silently guess:
- VAT/tax policy.
- Invoice legal requirements.
- Final costing method.
- Negative-stock exception policy.
- Inventory adjustment approval thresholds.
- Credit-limit enforcement.
- Payment approval thresholds.
- Document numbering rules.
- Data retention policy.
- WhatsApp template/compliance policy.

If a policy is not approved:
- isolate it behind a configuration/strategy boundary,
- use a safe documented default only where technically unavoidable,
- flag it clearly,
- do not encode an irreversible business assumption.

---

# 20. DESIGN LOCK

Before Admin Web or Field PWA work, read:

`docs/design/UI_UX_VISUAL_BASELINE_v1.md`

The visual baseline is **APPROVED / LOCKED**.

Required characteristics:
- Arabic RTL.
- Professional B2B SaaS.
- Navy navigation.
- Blue primary actions.
- Green success.
- Amber warning.
- Red critical.
- Compact information-rich cards.
- Strong tables.
- Clear status badges.
- Minimal clicks.
- Packaging visible.
- Carton/piece/mixed quantity clear.
- Mobile-first Field PWA.
- Keyboard-first Admin.
- No unrelated visual language.

A new visual system requires explicit NEXORA AI approval.

---

# 21. CURRENT EXECUTION STATE

## Current Batch
**BATCH 1 — Backend Foundation**

## Current Task
**TASK-003 — Laravel Backend Foundation + Database Migrations + Schema Tests**

## Status
**IMPLEMENTATION COMPLETE / CI VERIFICATION PENDING**

Current implementation contains:
- Laravel 13 / PHP 8.3+ foundation.
- Organization model.
- Tenant middleware foundation.
- Identity migrations.
- Roles/permissions/devices foundation.
- API health route.
- Seed foundation.
- PHPUnit foundation.
- GitHub Actions MySQL 8.4 CI.

Do not mark Batch 1 approved until CI evidence exists.

---

# 22. PROJECT STATUS RULE

`PROJECT_STATUS.md` must always reflect the latest **approved** state.

Do not mark:
- a Batch complete before NEXORA AI approval,
- a feature complete without tests,
- deployment ready without production checks.

---

# 23. DEFINITION OF DONE — WHOLE PRODUCT

NEXORA Distribution OS is done only when:

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
12. Backups are tested and restored successfully.
13. Production deployment is reproducible.
14. Security review passes.
15. Load/performance testing passes agreed thresholds.
16. Pilot users can complete real workflows efficiently.
17. Legacy migration can reconcile opening balances/inventory.
18. Monitoring and alerting are operational.
19. External integrations fail safely and retry.
20. NEXORA AI gives final release approval.

---

# 24. NEXORA AI REVIEW LOOP

**Agent implementation**
→ **Evidence**
→ **NEXORA AI technical/business/security/UX review**
→ **Correction OR Approval**
→ **Next Batch**

NEXORA AI reviews after **every Batch**, not only at the end.

Review dimensions:
- Architecture consistency.
- Business rules.
- Transaction correctness.
- Financial correctness.
- Inventory correctness.
- Security.
- Tenant isolation.
- Performance.
- UX.
- Mobile/offline behavior.
- Test quality.
- Regression risk.
- Scope discipline.
- Maintainability.

**The agent must never self-approve a Batch.**

---

# 25. FINAL RELEASE GATE

Before production release, NEXORA AI must verify:

### Functional
Purchase, sale, collection, return, transfer, vehicle load, trip, settlement, customer, supplier, inventory and reports.

### Packaging
Piece, carton, mixed, piece barcode, carton barcode, conversion, return and stock reconciliation.

### Reliability
Retry, offline, sync, conflict, duplicate prevention and concurrency.

### Security
Tenant isolation, RBAC, device revoke, audit, secrets and secure links.

### Production
CI/CD, backup, restore, monitoring, alerts, error tracking, load test and E2E.

Only then:
**RELEASE APPROVED.**
