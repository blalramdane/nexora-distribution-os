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

## Batch 1 review
**APPROVED**

Evidence:
- CI run: 37723730138
- Result: SUCCESS
- Commit: d2d9aa34eaab706c24a964491d48228dabed9775
- Composer install: PASS
- Laravel bootstrap: PASS
- Empty DB migrations: PASS
- Schema foundation tests: PASS

Corrections verified during Batch 1:
- composer.json JSON escaping
- Laravel bootstrap namespaces
- bootstrap/cache directory
- permission ULID seeding
- schema test ULID namespace/reference

## Current Batch
**BATCH 2 — Database Core**

## Current Task
**TASK-004 — Complete database schema, constraints, indexes and repeatable reference-data seeders**

## Status
**ACTIVE / IMPLEMENTATION IN PROGRESS**

## Latest local verification — 2026-10-10
- `backend`: `php artisan test --no-ansi` — **56 passed, 343 assertions** on the project's isolated SQLite database; 3 MySQL-only composite-FK tests skip on SQLite by design.
- Isolated migration lifecycle probe — **PASS**: fresh migrate, seed twice, full rollback, migrate again, final seed. Temporary probe database removed.
- Frontend TypeScript check and production build passed. Playwright E2E: **4 passed**, including browser login, UI CRUD for product/supplier/customer, purchase receipt, field visit check-in/completion, loading three units to a vehicle, trip-linked sale, allocated collection, trip settlement (cash and stock variance zero), sales return against the original invoice, and purchase return against its supplier invoice. Final demo stock is two units at the warehouse and two at the vehicle. Offline field queue/reconnect and PWA validation pass too. E2E uses isolated port 3027; clearly labeled demo records remain in local SQLite.
- Root cause fixed: location listing queried a non-existent `locations.active` column; the real schema exposes `locations.status`. `LocationMasterTest` now protects this endpoint. Laravel API exception configuration also prevents browser-style unauthenticated API requests from redirecting to an undefined web `login` route.
- Latest MySQL 8.4.11 gate passed on code commit `29a7348` via isolated GitHub Actions run [38046598388](https://github.com/blalramdane/nexora-distribution-os/actions/runs/38046598388): fresh migration, seed twice, full rollback, migrate again, final seed, migrations check, and tests all passed. MySQL backend suite: **59 passed, 343 assertions**, including all three negative cross-tenant FK tests. No local MySQL service or available Docker Desktop engine is present; CI remains the authoritative schema gate.
- Fixed shared payment method posting: a composite tenant FK from `payments` to `payment_methods` conflicted with global methods where `organization_id` is null. Migration 10 no longer adds this invalid FK; migration 12 safely removes it from legacy databases based on parsed `PRAGMA foreign_key_list` metadata rather than brittle DDL-text matching. A legacy-schema probe confirmed both existing payment rows survive the repair and `PRAGMA foreign_key_check` is clean; local invoice/trip/payment row counts also remained unchanged.
- GitHub Actions on an isolated MySQL **8.4.11** service passed all database lifecycle gates and the full backend suite on commit `0c89ee7`: **51 passed, 317 assertions**.
- The MySQL suite includes three negative cross-tenant insertion tests (trip/customer assignment, stock/product-location mismatch, sales invoice/customer mismatch). The end-to-end distribution gate also passed in the same MySQL run.
- Migration 11 scopes the `user_roles` pivot to `organization_id`, backfills existing assignments, and refuses migration if user/role organizations disagree. `User::roles()` and `Role::users()` write/filter the organization key.
- Local SQLite suite: **48 passed, 317 assertions, 3 MySQL-only constraint tests skipped by design**. An isolated SQLite lifecycle including migration 11 passed fresh migration, repeated seeding, full rollback, re-migration, and final seed.
- Remaining schema exceptions: nullable `SET NULL` relations, global/shared units/geography, and polymorphic party/document/source references need explicit domain/deletion-policy decisions before adding safe composite constraints. Batch 2 stays active until those exceptions are documented and tested.

## Next correction task
1. Review/document the remaining nullable `SET NULL`, shared/global reference data, and polymorphic finance relations; add safe tenant checks and negative tests where the domain permits.
2. Implement and E2E-test trip expense posting and its effect on expected/actual cash settlement.
3. Extend browser E2E to UI-level replay/idempotency and offline conflict behavior.
4. Keep the MySQL 8.4.11 CI gate required for every future migration/schema change; do not start shared Docker/MySQL services on the developer workstation.
5. Re-run the full backend, browser E2E, TypeScript and production build gates and update this evidence section.

## Batch 2 gate
Do not activate Batch 3 until:
- all schema waves are implemented,
- migration chain succeeds on empty MySQL 8.4,
- seeders are repeatable,
- schema integrity tests pass,
- indexes/FKs/tenant boundaries are reviewed,
- packaging constraints are tested,
- idempotency/payment/return/sync/outbox constraints are tested,
- NEXORA AI review approves Batch 2.

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


---

# 26. CONTINUOUS EXECUTION MODE — USER REQUEST

The owner requested that development proceed as a continuous engineering loop so they can receive a usable local Alpha sooner.

## Required loop
Repeat this cycle without asking the owner for permission between ordinary engineering tasks:

1. Read the current execution state and inspect the actual repository.
2. Select only the current active task from this file.
3. Reproduce the issue or establish baseline evidence.
4. Implement the smallest complete change consistent with the architecture.
5. Add/update tests for success, failure, authorization, idempotency, and regression paths relevant to the change.
6. Run focused tests, full relevant suite, type/lint checks, and production build as applicable.
7. Review the diff for security, tenant boundaries, financial/inventory invariants, migrations, and backward compatibility.
8. Update status and record exact evidence.
9. NEXORA AI reviews the evidence.
10. If approved, advance to the next task and repeat. If rejected, stay in the same task and correct it.

## Stop conditions
Stop and ask the owner only when:
- an explicit business policy decision is required by Section 19,
- real credentials, paid services, or external spending are required,
- a destructive or irreversible action is proposed,
- repository evidence is insufficient and the missing evidence cannot be retrieved safely,
- a security or data-integrity risk cannot be resolved within the current task.

Do not stop merely to ask whether to continue, whether to run tests, or whether to implement the next approved task.

## Alpha-first delivery strategy
Prioritize a coherent, locally runnable Alpha over disconnected scaffolds:
1. Reproducible local setup and demo seed.
2. Authentication and tenant-safe session.
3. Catalog + customers/suppliers.
4. Warehouse inventory and purchase receipt.
5. Sales + payments + returns with ledger/stock reconciliation.
6. Vehicle loading + trips + visit/collection + settlement.
7. Offline sync with server-authoritative ACK and duplicate-delivery safety.
8. Operational dashboard and reports reconciled to posted transactions.

Do not skip the Transaction Engine or create a second mock business-data path to make the UI appear functional. Demo data may be seeded, but every displayed operational result must be clearly identified as demo data and real mutations must use the supported API.

## Delivery cadence
Aim to deliver the first coherent Alpha at the earliest safe checkpoint. A batch can be internally split into smaller reviewable tasks, but its acceptance gate remains mandatory. Report progress in terms of working flows and test evidence, not number of files or lines written.

---

# 27. EXECUTION HANDOFF — 2026-10-13

## Source of truth

- Detailed phase plan and task IDs: `EXECUTION_MASTER_PLAN.md`.
- Append-only session evidence: `docs/EXECUTION_LOG.md`.
- Open-source architecture comparisons: `docs/research/OPEN_SOURCE_REFERENCE_REVIEW_2026-10-13.md`.
- Project readiness / current blockers: `PROJECT_STATUS.md`.

## Active micro-task

**NDX-060 — Financial/stock invariant audit.**

NDX-000 handoff, NDX-010 expense replay protection, NDX-020 local regression gates and NDX-050 focused API tenant/RBAC matrix have passed their current local gates. NDX-050 covers expenses, payments, purchases, sales invoice reads/history filters, inventory, product aliases/packaging, supplier/finance references, dashboard aggregates, trip subroutes, trip load/settlement, field customers/visits/today, foreign-tenant devices, inactive users/organizations and cross-tenant roles. Latest full SQLite backend result after NDX-060 currency/adjustment/return/allocation guards, NDX-070 sync hardening and inventory/ledger reconciliation assertions: **89 passed, 638 assertions, 3 skipped**. Frontend lint and production E2E build passed; Playwright passed **4/4** after updating the test flow so the invoice retains a valid receivable balance before its customer return. MySQL-only tenant-FK cases remain skipped locally by design; current-tree MySQL CI is blocked because the relevant changes are uncommitted and publishing them is not authorized.

Next: continue NDX-060 with source-document ledger reconciliation, return amount/balance policy and concurrent payment allocation review. NDX-070 offline sync replay hardening is implemented and focused-tested: replay binds schema version, operation UUID, original user/device, and idempotency key; rejected replay preserves public errors and unexpected exceptions are sanitized. New verified accounting blockers: purchase header discount/tax can make purchase source ledger entries unbalanced, and non-EGP document/payment currencies are stored against hardcoded EGP ledger entries. FIN-POL-008/009 and the deletion-policy register need owner decisions. Do not treat the three skipped MySQL composite-FK tests as passed.

### Completed NDX-010 evidence

Observed risk: the API/service already replays an expense when the same organization + operation + idempotency key + request fingerprint is repeated. However, the trip page minted a fresh `crypto.randomUUID()` on every click. If the API committed the expense but its response was lost, a retry from the same form could post a second expense with a new key.

Change verified: keep a request fingerprint and key in a React ref. Reuse the key only for the identical payload; create a new key when the payload changes; clear it only after a successful response. The Playwright business-flow test simulates the API commit followed by a dropped browser response and asserts the retry uses the original key. It also verifies the trip expense reduces expected settlement cash once.

## Verification already observed

- PHP 8.3.35 pinned runtime: current full `php artisan test --no-ansi` passed (89 tests, 638 assertions; 3 MySQL-only composite-FK tests skipped on SQLite by design).
- `npm run lint` passed after the UI and E2E changes.
- Production frontend build passed with Next.js 16.4.0; Playwright full suite passed 4/4, including the committed-expense/lost-response replay path.
- `git diff --check` passed; Git emitted only expected LF→CRLF working-copy warnings.
- Machine-default PHP 8.2.12 is incompatible with Composer requirements. Use `backend/../.tools/php83/php.exe -c backend/../.tools/php83/php.ini` from the backend directory.
- Frontend verification after transaction posting and settlement validation changes: `npm run lint` passed, `npm run build:e2e` passed, and `npx playwright test --timeout=90000` passed 4/4 (42.8s).

## Next action

NDX-050 focused API tenant/RBAC coverage is done locally. NDX-060 remains in progress; NDX-070 offline sync replay hardening is implemented and verified. Full backend suite **89 passed / 638 assertions / 3 MySQL-only skips**. The end-to-end gate now compares all scenario stock balances with movement-ledger sums and checks every ledger source for balanced debits/credits. Currency mismatch is fail-closed against organization base currency. Tax posting, purchase discounts, returns from adjusted legacy invoices, returns exceeding remaining receivable/payable, and supplier allocations exceeding net payable after returns are guarded until accounting policy is approved; focused no-mutation tests pass. Do not mark Batch 2/TASK-004 approved: current-working-tree MySQL 8.4.11 lifecycle evidence plus owner decisions in `docs/security/DOMAIN_DELETION_EXCEPTION_REGISTER.md` and `docs/finance/FINANCIAL_POLICY_DECISION_REGISTER.md` remain outstanding.

## Mandatory continuation behavior

The next agent must inspect `git status --short --untracked-files=all`, read the latest execution-log entry, and verify the active task. Continue from the first unblocked task; do not ask whether to continue. If blocked, record the exact blocker, last passing command and next command. Never reset/clean/stage/commit/push or publish without the owner's explicit authorization.

## 2026-10-17 continuation verification

- Current checkout: `feature/local-e2e-crud-flow`, starting HEAD observed as `c59d62a`. Existing local changes and untracked files were preserved; NEXORA QR was not modified.
- UI posting retries now use `frontend/lib/idempotency.ts` across sales, purchases, payments/collections, both return types, stock adjustment, trip loading, trip expenses and trip settlement. Keys are reused for unchanged pending payloads and cleared after confirmed success.
- Trip settlement stores and replays the result for exact retries; reuse of a key with a different payload remains rejected. Sales-return posting now fails closed when existing source allocations do not cover the return quantity after prior returns, instead of silently attributing the shortfall to a legacy layer. Completely legacy invoice lines without source allocation rows retain the legacy fallback.
- Latest backend suite: **96 passed / 786 assertions / 3 skipped**. The skipped cases require MySQL 8.4 composite tenant-FK enforcement and are not passing evidence.
- Frontend `npm run lint` passed, `npm run test:unit` passed **6/6**, and `npm run test:e2e -- --reporter=line` passed **4/4** after an optimized Next.js build. Browser E2E used an isolated temporary SQLite DB plus array cache/session and synchronous queue; it did not use the MySQL database configured in local `.env`.
- `git diff --check` passed with expected LF→CRLF working-copy warnings. No reset, clean, commit, push, deployment, publication or production DB access was performed.
- Next: continue NDX-060 with the remaining transaction/stock-source invariant review. TASK-004 / Batch 2 remains blocked pending current-tree MySQL 8.4.11 lifecycle evidence through authorized CI and approved financial/deletion policy decisions. Do not mark Local Alpha production-ready.

## 2026-10-17 UI workflow redesign

- **Cashier POS:** Replaced the narrow sales form with a POS-style catalog grid, product categories, debounced server search, stock-at-selected-location, packaging/unit selection, cart quantities, discount/payment controls and invoice follow-up. The catalog API `/catalog/pos` is tenant-scoped and paginated (60 default / 100 max) with search over SKU, Arabic/English name, brand, aliases and barcodes.
- **Purchase receiving:** Reworked purchases to use the same product grid and server-side category/search workflow; supplier selection is on-demand, with a chosen receiving location, optional supplier invoice number, purchase unit/packaging, editable unit cost, calculated total and a single idempotent post. The test now verifies the quantity and unit-cost inputs in their actual UI order.
- **Customer 360:** Added tenant-scoped customer profile endpoint/page with contact phone, call/WhatsApp shortcuts, credit terms, all-time invoice totals/outstanding, collections/returns summary and paginated invoices/payments/returns/field visits. Invoice links open the original invoice view.
- **Supplier 360:** Added a tenant-scoped supplier profile with purchase/invoice/paid/return/open-balance summary, contact links, paginated purchase invoices/payments/returns and a purchase invoice details dialog. Original purchase invoices can be opened even when not on the current invoice page.
- **Inventory center:** Added server-side category/location/search/stock-status filters, pagination, current quantity/cost/value and a guided stock adjustment panel which records an explicit reason and disallows a negative balance.
- **Regression coverage:** Customer/supplier 360 are tested for same-tenant access and foreign-tenant 404; POS catalog tenant filtering/category/location stock and inventory status filters are covered by backend feature tests. Updated the local CRUD browser test for the new pages and fixed its quantity/cost order, inventory column text and actual purchase document prefix.
- **Validation:** Full backend suite **97 passed / 818 assertions / 3 skipped**. The 3 skipped tests require MySQL 8.4 composite tenant-FK enforcement. Frontend `npm run lint` passed, `npm run test:unit` **6/6 passed**, and `npm run build:e2e` succeeded with 21 routes generated. The focused full business-flow browser test passed **1/1 in 43.8 seconds** on an isolated temporary SQLite copy. No application test writes were made to the user's primary SQLite DB.
- **Safety/status:** `git diff --check` passed with expected LF→CRLF warnings. Only the two live local services on `3017/8017` were left running; Playwright test services on `3027/8018` were torn down. No commit/push/deploy or production DB operation was performed.
- **Remaining release gate:** These are improved Local Alpha workflows, not production approval. MySQL 8.4 lifecycle evidence plus the outstanding financial/deletion-policy owner decisions remain required for TASK-004 / Batch 2.

## 2026-10-17 — Whole-screen simplicity standard and operational workflows

- **Standing UX rule:** Created `docs/UX_OPERATING_STANDARD_AR.md` as the reusable standard for every subsequent screen redesign: one clear primary action, a visible next step, server-side search/pagination, correctly scoped financial totals, explicit error recovery, accessible Arabic/RTL/mobile layout and no silent posting.
- **Supplier payables:** `/payables` is now an invoice-level ledger, not a long supplier-card dump. The overall payable total is tenant-wide and stays stable while the user searches; each invoice has supplier and invoice links, amount/paid/returns/due, invoice detail modal and a source-trace shortcut. The API is search/pagination-backed. Fixed and covered a SQLite numeric-comparison issue so a 10 EGP open balance appears correctly.
- **Purchase OCR:** `/purchase-imports` now guides operators through five phases: upload, OCR, review supplier/location/header, match item quantities/costs and post. Advanced OCR actions/raw text are secondary. Posting is disabled when the final source invoice total is missing, lines are incomplete/invalid, or item total differs from the declared invoice total; the page explains what must be corrected.
- **Purchase source trace:** `/purchase-trace` filters at the server by supplier/system invoice/supplier invoice/item name/SKU and paginates the full result set rather than limiting it to the most recent 200 invoices. The purchase → item → stock locations/sales path is explicit; sales invoices are expandable, and the supplier profile/payables are linked. Legacy stock without trace remains unattributed instead of guessing.
- **Trip workflow:** `/trips` now presents four steps — vehicle load, customer visits, trip expenses, settlement/close — and only renders the active operation. Trip list search/status filters, customer search and server product search avoid lengthy selects and first-page assumptions.
- **Tests added/updated:** open-payable query+pagination regression, source trace search+pagination regression, browser test now follows the redesigned customer lookup/stepper and checks payables, source trace, OCR and trip step UI after the main stock/sales/returns flow.
- **Validation after latest source changes:** backend full suite **97 passed / 824 assertions / 3 skipped** (MySQL 8.4-only tenant composite-FK checks); frontend `npm run lint` PASS, unit tests **6/6 PASS**, `npm run build:e2e` PASS (21 routes), Playwright **4/4 PASS in 1.2 minutes** on an isolated SQLite backup. `git diff --check` PASS with standard LF→CRLF warnings. Test ports 3027/8018 were closed, and the existing local services on 3017/8017 remain running.
- **Next:** Apply the same UX standard to any further screenshots/screens instead of treating individual redesigns as one-offs. Keep production release blocked until MySQL 8.4 evidence and owner decisions in the finance/deletion policy registers are resolved.

## 2026-10-17 — New catalog products directly from purchase OCR

- **User goal:** Invoice OCR may contain goods the company has never cataloged. An unmatched line must not be silently dropped or forced onto a different existing SKU.
- **UI:** Each imported invoice line now has an explicit choice between `موجود بالكتالوج` and `صنف جديد`. New-product lines collect the supplier's invoice description, catalog Arabic name, optional internal SKU (server-generated if empty), supplier SKU/barcode, optional English name/brand/category, required base unit, optional default sale price, quantity and entered purchase unit cost.
- **Posting/API:** `PurchaseController::store` now accepts exactly one of an existing `product_id` or a validated `new_product` draft per line. `TransactionPostingService::postPurchase` creates the new `products` record, supplier-product link and supplier description alias, then posts the purchase invoice, inventory receipt, FIFO source layer and ledger effects in the same DB transaction. Unit/category/SKU validations are tenant-scoped. If any later item fails, the transaction rolls back the new product and all invoice/stock effects together.
- **Idempotency:** The existing purchase idempotency key continues to guard the complete posting. An identical retry replays the invoice instead of making another product or stock movement.
- **Safety:** OCR still requires human review and explicit final-total reconciliation. A new item is not created at OCR time or when switching the UI toggle; it is only persisted with the confirmed purchase posting. Primary SQLite and real upload storage were not used as writable test targets.
- **Regression test:** `PurchaseInvoiceImportTest::test_new_catalog_products_from_import_are_created_atomically_with_the_purchase_receipt` covers new product fields, supplier SKU relation, OCR alias, purchase-item snapshot, stock quantity, invalid later line rollback, foreign-tenant unit rejection and idempotent replay. The Playwright CRUD E2E seeds deterministic OCR only in a temporary SQLite copy, posts a new SKU from the UI and verifies its stock balance.
- **Final verification:** Full backend suite **98 passed / 859 assertions / 3 skipped**; the skips require MySQL 8.4 composite tenant-FK enforcement. `npm run lint` PASS; unit tests **6/6 PASS**; production E2E build generated **21 routes**; full Playwright suite **4/4 PASS (52.7 seconds)**, including creating a new product from an invoice and verifying quantity 2 in inventory. `git diff --check` PASS with expected CRLF conversion warnings.
- **Runtime:** Local app `http://127.0.0.1:3017/purchase-imports` and API health `http://127.0.0.1:8017/api/v1/health` remained available; test ports 3027/8018 were released. No commit, push, deploy, `.env` change or production database write was performed.

## 2026-10-17 — Prevent importing an internal sales invoice as supplier purchase

- **Reported blocker:** The `/purchase-imports` screen appeared stuck at step 3 while `SI000041.pdf` was selected. A read-only inspection of the saved OCR text showed this was a NEXORA sales invoice with a customer code and `TRIP-000041`, rather than an invoice received from a supplier. The prior total regex had also matched the generic `الإجمالي` table heading and the first quantity, saving `1.00` as a guessed invoice total.
- **UI guard:** The purchase-import screen now recognizes the combination of an internal `SI...pdf`, sales-invoice wording, trip reference and customer reference. It displays a prominent Arabic explanation, lets the operator choose another supplier file, links to the sales screen, and disables purchase-match/posting actions for that document. The posting handler also checks the type guard before submitting.
- **OCR parser:** Number extraction strips PDF directional marks. Total extraction now prefers explicit invoice-level labels such as `الإجمالي النهائي`, `إجمالي الفاتورة`, `Grand Total` and `Total Due` on the same line, rather than scanning across a generic table heading into the next row. If no credible summary label is present it leaves the total blank for manual review instead of inventing a value.
- **Regression coverage:** Added `frontend/lib/invoice-ocr.test.mjs` covering explicit totals vs table headers, refusing to invent a total from product quantity, RTL directional marks in invoice numbers, positive recognition of an internal NEXORA sales invoice and no false positive for a supplier purchase invoice. Playwright now asserts the actual `SI000041.pdf` record shows the warning and that its purchase-posting action is disabled, then continues the valid supplier-invoice workflow.
- **Validation:** Frontend lint PASS, unit tests **11/11 PASS**, optimized build PASS (21 routes), Playwright **4/4 PASS** on a temporary SQLite copy. `git diff --check` PASS with usual line-ending conversion warnings. Local API health remains `ok`; UI path returns HTTP 200; test ports 3027/8018 were released.
- **Safety:** The internal sales invoice was not posted again, and the user's primary SQLite database was inspected read-only only. No production changes, commits, pushes or deployment.

## 2026-10-17 — Purchase invoice review can continue when supplier/OCR data is missing

- **Second screenshot / exact source:** The selected `Professional Arabic-English Purchase Invoice.png` OCR text identifies El Araby / “شركة العربي للتجارة والتوزيع”, supplier reference `508-0001`, supplier invoice `PS-2024-001256`, date `2024-10-15`, and six SKU-like rows (`ELE-REF-001`, `ELE-WAS-002`, `ELE-TV-003`, `ELE-AIR-004`, `ELE-FAN-005`, `ELE-MIC-006`). The OCR had not linked a supplier record, produced no trusted invoice total, and had saved no line candidates, so the UI remained at step 3 without a convenient recovery action.
- **Supplier recovery in the same screen:** Added a supplier setup section for unmatched vendors: editable OCR-suggested legal name/reference, optional phone/address/tax number, and `تسجيل المورد وربطه بالفاتورة`. It creates only the supplier master record and saves its link to the import; it does not post purchase or change stock. If a vendor matches an existing record, the invoice uses it rather than creating another. The operator can also choose a known supplier from the existing dropdown.
- **SKU rows:** OCR now suggests line candidates only where a row contains an SKU-like item code and sufficient numeric fields. Exact internal SKU matches select an existing product; unmatched codes become `صنف جديد` lines with the vendor code/description carried over. Malformed/ambiguous costs are left blank for manual review (e.g. OCR text `2450000` is not silently read as a plausible price). Invoice rows and supplier details remain editable.
- **Step recovery / guardrails:** The primary action now tells the operator exactly whether to choose/create the supplier, enter a missing total, or review item lines; it scrolls to the relevant section. Posting remains disabled if supplier, total, item/unit, quantity, cost or total reconciliation are incomplete. The El Araby OCR text has a subtotal 208,850.00 and VAT line 27,977.05 but no trusted final-total label, so the final total remains a manual input rather than an invented value.
- **Separate local runtime defect fixed:** `frontend/lib/api.ts` and `.env.example` had defaulted to `http://localhost:8000/api/v1`, which is another project; its `/api/v1/purchase-imports`, `/suppliers`, `/products`, and `/locations` endpoints return 404. NEXORA Distribution OS uses port 8017. Changed the frontend fallback and `tools/start-local.ps1` to `http://127.0.0.1:8017/api/v1`, restarted only the verified Distribution OS frontend on 3017, and confirmed the fresh served chunk uses port 8017. Project API 8017 remains healthy; separate service on 8000 was left untouched.
- **PWA manifest conflict fixed:** Removed the duplicate `frontend/app/manifest.ts` route so `frontend/public/manifest.webmanifest` is the single `/manifest.webmanifest` source. After restart the route returns HTTP 200; the full PWA browser test passes.
- **Validation:** `npm run lint` PASS; `npm run test:unit` **13/13 PASS**, including supplier legal-name/code extraction, SKU row suggestions, malformed price safety, final-total/header mismatch and internal-sales classification. `npm run build:e2e` PASS (20 application routes; manifest is served from `public`). Full Playwright **4/4 PASS in 58.1 seconds** on isolated SQLite and isolated ports 3027/8018, including El Araby vendor setup, six OCR item rows, preventing SI invoice repost, new product posting and inventory balance.
- **Safety/runtime:** No supplier, invoice or stock posting was applied to the primary SQLite database; supplier creation was tested on a temporary SQLite copy. Port 8000 was not stopped or changed. Ports 3027/8018 were released; frontend 3017/API 8017 remain online. No `.env` edit, commit, push or deploy.

## 2026-10-17 — Period reports, API contract and baseline API protections

- **New reports API:** Added permission-protected `GET /api/v1/reports?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`. The server derives tenant context from the authenticated user, validates ordering and limits the date window to 367 included dates, and aggregates posted sales, purchase invoices, returns, collections, supplier payments, expenses and sales-item COGS. It returns a daily series and top ten items, plus operational gross/net profit.
- **Financial interpretation:** Cost snapshots from returned sales lines are treated as cost recovery on the return date. Net COGS is deliberately allowed to be negative when returns of goods sold in prior periods recover more cost than the selected period's new sales. Receivables, payables and inventory are labeled current snapshots, not historical as-of balances. Trip stock/cash variances are excluded from profit pending owner-approved accounting policy; profit is not represented as a final tax statement.
- **Reports UI:** Replaced the old dashboard snapshot-only `/reports` page with date-from/date-to filtering, 15 key metrics, a daily operations table and top-item table. The period is explicit, missing activity shows an empty state, errors are visible and report screen operations are read-only.
- **Regression coverage:** `ReportSummaryTest` covers sales/purchases/returns/payment/expense math, cost of returned items, one organization not leaking another's 50,000 EGP invoice, current balances/inventory, date validation/window length, report permission and returns-cost recovery that exceeds current-period COGS. Playwright `local-alpha-crud.spec.ts` now follows its live sale/purchase/return flow into Reports and verifies report response, dates, period totals and tables.
- **API docs:** Added `docs/api/openapi.yaml` (OpenAPI 3.1, 50 paths, 56 route/method operations). `OpenApiContractTest` compares every documented operation with the live Laravel route list and fails for missing/stale operations.
- **API hardening:** Added common API response headers (`nosniff`, frame denial, referrer policy, restrictive permissions policy) and Laravel's native API throttle `60|180` (60/minute for guests by IP, 180/minute per authenticated identity); the existing login route retains the stricter five-attempt-per-minute limit.
- **Verification:** Full Laravel suite **107 passed / 939 assertions / 3 skipped**; the skips require actual MySQL 8.4 composite tenant-FK enforcement. Frontend lint PASS, unit tests **13/13 PASS**, optimized build PASS (20 Next.js application routes plus public manifest), full Playwright **4/4 PASS in 57.2 seconds** on an isolated SQLite copy. OpenAPI contract, exact route comparison, and `git diff --check` PASS.
- **Safety/release gates:** All browser write flows ran against a temporary SQLite backup and isolated ports 3027/8018. Primary SQLite remained read-only for the test run; service on port 8000 and any other project were untouched. The local Distribution OS frontend/API remain at 3017/8017. No commit, push, deployment, production DB mutation, or paid infrastructure action. Finance/deletion policy owner decisions and MySQL 8.4 verification remain release blockers.

## 2026-10-18 — MySQL 8.4 tenant constraints and current-tree acceptance pass

- **Actual MySQL runtime:** Disposable MySQL `8.4.11` + new `nexora_test` schema. All migrations ran successfully, then the seed command passed twice in succession. These are current-tree local results, not a claim about a GitHub CI run.
- **Composite tenant constraints:** `TenantScopedForeignKeyTest` — **3/3 PASS on MySQL 8.4.11**. Cross-tenant trip/customer assignment, foreign tenant stock location, and a sales invoice tied to another organization's customer were rejected by database constraints.
- **Backend regression:** First full MySQL suite found one portability issue (`ProductCatalogTest` expected numeric `12`, MySQL returned decimal string `12.000000`). `CatalogController::posCatalog()` now normalizes `quantity_base` to a numeric float without dropping fractional quantities. Focused test **1/1 / 32 assertions PASS**; rerun full suite **115 passed / 984 assertions / 0 skipped**.
- **Frontend / browser:** `npm run lint` PASS; unit suite **20/20 PASS**; production `npm run build` PASS (20 app routes); current Playwright suite **6/6 PASS** on a newly copied SQLite database. Coverage includes offline visit sync, login/dashboard, full CRUD purchase/sale reconciliation, OCR purchase review/post/replay with stock quantity verification, nullable OCR fields, and PWA manifest.
- **Local UI recovery:** Docker engine went unreachable after the MySQL run and port 3317 stopped listening. The NEXORA UI/API were restarted separately using environment overrides and a new temporary copy of local SQLite; API health and `/purchase-imports` return HTTP 200. No `.env`, original SQLite or other project's port-8000 service was changed.
- **Lifecycle caveat / release status (superseded by the reconnected verification below):** The preceding note reflected Docker's outage before the lifecycle check. Subsequent isolated-session output confirmed the zero-table rollback check and re-migrate + repeat-seed steps completed. Current-tree full MySQL verification and remaining blockers are recorded below.

## 2026-10-18 — Reconnected verification follow-up

- **Branch / working tree:** `feature/local-e2e-crud-flow`; 57 tracked change entries + 115 untracked paths. No reset/clean/stage/commit/push/deploy; all prior changes preserved.
- **MySQL:** Docker Engine 28.5.1 restored. Created a new disposable no-volume MySQL 8.4.11 container at `127.0.0.1:3317` because the previous transient container no longer existed. On its empty `nexora_test` schema, all 16 migrations passed and two `db:seed` runs passed. The prior isolated reset/zero-table/re-migrate/seed cycle had completed before the earlier tunnel loss; reset was not repeated this time.
- **Backend:** full suite initially **115 passed / 984 assertions / 0 skipped**; after the NDX-060 variance regression it passed **116 passed / 994 assertions / 0 skipped** on MySQL 8.4.11. `TenantScopedForeignKeyTest` **3/3** passed on MySQL. New settlement variance test also passed focused **1/1 / 10 assertions**.
- **Frontend:** `npm run lint` PASS; `npm run test:unit` **20/20 PASS**; `npm run build` PASS (20 routes); `npm run test:e2e` **6/6 PASS** against an isolated temporary SQLite copy. `git diff --check` PASS with expected LF/CRLF warnings.
- **Release status:** Local current-tree database and quality gates have evidence, but Batch 2 / TASK-004 remains **NOT APPROVED FOR PRODUCTION** until the owner resolves `docs/finance/FINANCIAL_POLICY_DECISION_REGISTER.md` and `docs/security/DOMAIN_DELETION_EXCEPTION_REGISTER.md`. No current-tree GitHub CI workflow was initiated because the changes are uncommitted and publishing is not authorized.
- **Read-only audit result:** Cash/stock variances are persisted but not automatically posted to GL/stock; the new test protects this interim behavior until the owner approves FIN-POL-004/005. The settlement line currently writes `opening_quantity_base=0` and expected closing stock uses only current-trip movements, so residual vehicle stock carried over between trips is an unresolved data-lifecycle question.
- **Next action (keep NDX-060 active):** Trace how a trip should receive its authoritative opening vehicle stock and whether the app records that snapshot. Add a regression/reconciliation test before proposing an implementation. Do not infer a stock carry-over or variance-posting policy, or tax/discount/credit/refund/currency/retention/deletion behavior.

## 2026-10-18 — Field portal and assigned-trip preview

- **Flow delivered:** A shared authenticated `/field` PWA with personal field-rep login, organization-specific login link, role redirect, trip assignment by manager, private one-time credential copy, assigned-customer visit check-in, visit completion percentage, stock/sale, source-linked return, and inbound customer collection. The percentage is route progress—not commission.
- **Root cause repaired:** Adding `/field/reps` originally prevented trip selectors from loading because `whereHas('roles')` used a `User::roles()` relationship whose tenant pivot value was null on an unhydrated relation. Replaced the list query with explicit tenant-scoped joins and added a regression assertion that the manager receives the created representative in the list.
- **Guardrails:** Field reps cannot load administrative trip lists, sell to unassigned customers or from non-vehicle locations, post supplier/outbound payments, or close trips/final settlements. Offline transaction replay now applies the same trip/customer scope.
- **Preview account/data:** A preview-only account and trip were created inside the disposable SQLite copy, not in the primary database. Trip `TR-FIELD-50054085` has one test customer and 3 units loaded. Local frontend/API both return HTTP 200; preview remains loopback-only at ports 3017/8017.
- **Verification:** MySQL 8.4.11 suite **118 passed / 1015 assertions / 0 skipped**; field-rep focused tests + OpenAPI contract passed; lint PASS; unit tests **20/20 PASS**; complete Playwright **8/8 PASS** including full manager→rep→manager trip workflow; `git diff --check` PASS.
- **Safety:** Primary SQLite hash unchanged; no `.env` values modified; no reset/clean/stage/commit/push/deploy. 59 tracked change entries and 118 untracked paths remain preserved on `feature/local-e2e-crud-flow`.
- **Next action:** Owner can exercise the preview locally. Keep NDX-060 active for authoritative opening-stock/carry-over reconciliation; NDX-070/080 remain in progress for broader UI idempotency and offline/device lifecycle coverage. Public access, commission policy, tax, variance posting and unresolved retention/deletion rules are not approved.

## 2026-10-18 — Field workspace UX, expenses and vehicle stocktake

- **Problem observed:** The screenshot showed a very tall, narrow catalog list and a tiny sale-entry area with no strong separation between route work and vehicle operations.
- **Change:** Reworked `/field` into responsive four-tab workspace: **العملاء**, **الأصناف**, **المصروفات**, **جرد العربية**. Customer route is the landing view; it includes sequence, visit status, address/contact/map shortcuts, search, and check-in/complete/skip. Product browser server-searches the full assigned-vehicle catalog by Arabic/English name, SKU, brand, barcode and aliases, with pagination and packaging/unit-aware sale entry.
- **Expense guard:** Added `expenses.post` to the least-privilege field role. Expense form ties the amount/date/category/account/note to the active trip and exact assigned vehicle, lists recent posted trip expenses, and uses the existing stable idempotency key/offline queue; online/offline server paths reject expenses for another trip/vehicle or invalid tenant account.
- **Stock-count workflow:** New `field_stock_counts` migration + field stocktake GET/POST endpoint and UI show expected versus counted quantities, live differences, notes, previous count history and a clear review-only warning. Payload hash binds idempotency keys to trip/items/notes, so replaying the same submission returns the original report while reusing the key for different quantities is rejected. The endpoint validates every item belongs to the representative's vehicle stock and does not mutate stock/ledger.
- **Testing evidence:** Added a positive MySQL-backed test for vehicle catalog search and stock-count replay/conflict/stock-invariance, plus negative tests for foreign trip expense/count. MySQL 8.4.11 suite **119 passed / 1040 assertions / 0 skipped**. Lint PASS, unit suite **20/20 PASS**, production build PASS, full Playwright **9/9 PASS** including new mock-backed product search, expense, live stock variance and review-only flow. `git diff --check` PASS.
- **Local preview:** Migration and expense permission applied only to `%TEMP%\nexora-field-preview-9df85bf9a70f4a9e84b533097d2ac2d5.sqlite`. API/frontend on `127.0.0.1:8017/3017` return HTTP 200. Original SQLite SHA unchanged.
- **Next action:** Keep UX phase NDX-110 and offline/security follow-ups active for additional real-device, bulk count and interrupted-network cases. No commission rate or shortage/surplus posting rules inferred; variance remains review-only pending owner approval. No production publication/actions taken.

## 2026-10-18 — Customer-specific field sale prices and whole-number controls

- **Business rule:** Removed all automatic default/package price prefill in the field sale panel. Rep selects a product and sale unit, then enters the negotiated selling price for the current customer invoice. Per-customer variation is supported without changing purchase cost or any global product price.
- **Cost privacy:** Field trip/catalog responses omit `average_cost`, `default_cost`, `default_piece_price`, package `sale_price`, and `purchase_price`. Generic management cost catalogs (`/products`, `/catalog/pos`, `/catalog/references`) now return 403 to field roles including legacy roles that retain `products.view`; new field accounts no longer receive that permission. The sale service continues snapshotting server-authoritative unit cost for internal margin/costing.
- **Integer input:** Numeric mobile controls use text + numeric keyboard, no browser decimal steppers, and accept/normalize Arabic and Persian digits (e.g. `١٠٠` → `100`). Sale price, sale/return quantity, collection amount, trip expense amount and counted stock must be whole numbers. Backend posting services and offline-sync authorization reject fractional values, not just the frontend.
- **Regression tests:** MySQL-backed field test posts the same product at EGP 80 and EGP 110 to two customers, asserts the two invoice price snapshots and equal cost snapshots, rejects a fractional price/quantity, and verifies stock/invoice counts do not mutate on rejection. Legacy cost-catalog denial and fractional payment/expense rejection are included.
- **Verification:** MySQL 8.4.11 full suite **120/120, 1077 assertions, zero skips**. Frontend lint PASS, unit tests **20/20**, production build PASS, full Playwright **9/9 PASS** and focused field browser tests **3/3 PASS**, including Arabic numeral entry and request-body verification for the negotiated sale price.
- **Preview:** Synchronized the demo role to five least-privilege permissions only in the disposable SQLite clone; removed `products.view` there. Primary SQLite SHA is unchanged. Local endpoints remain loopback-only; no public or production changes.
- **Next:** Continue NDX-110 application-wide form/keyboard UX audit and NDX-070/080 retry/offline tests. Do not introduce customer price lists, fixed markups, commissions or automatic price policies without explicit owner requirements.

## 2026-10-19 — NDX-060 trip opening-stock carry-over reconciliation

- **Finding closed:** `settleTrip()` derived expected closing stock only from movements tagged to the current trip while writing `opening_quantity_base=0`; carry-over stock already inside the vehicle could therefore appear as a false surplus/shortage. `GET /trips/{id}/stock` also read the vehicle's current mutable stock rather than the actual closing count of a completed trip.
- **Snapshot contract:** Added migration `2026_10_19_000011_create_trip_opening_stock_snapshots.php` with tenant/vehicle/location-scoped immutable opening header and per-product quantity/cost rows. A read-only pre-trip stock preview does not freeze the snapshot. The first successful trip load, trip-linked sale/stock adjustment (including approved vehicle stocktake), or close of an otherwise untouched trip captures the snapshot inside the same transaction before its first trip-linked stock movement. Failed postings roll back the snapshot with the posting.
- **Reconciliation:** Expected closing quantity now uses `opening + loads + signed sales/returns/transfers/stock adjustments`. Settlement lines save the actual opening quantity and adjustment quantities. Carry-over products are included even when not loaded during the current trip. The closing count remains review-only: no automatic stock correction or GL adjustment was introduced.
- **Legacy data:** If a trip already has trip-linked stock movements but has no opening snapshot, the system does not infer a historical opening from today's balance. It records `opening_stock_basis=legacy_zero` and a visible warning. Newly tracked trips are `snapshot`-based. Completed-trip stock reads now return saved actual closing quantities; detailed reports show the opening rows and per-product expected/actual/variance reconciliation.
- **Payment concurrency follow-up:** `postPayment()` already locks invoice rows and any selected trip-supplier settlement before recalculating the outstanding amount. However, one payment allocated across multiple invoices previously acquired row locks in the order supplied by the UI. Two requests listing the same invoices in opposite orders could create an avoidable MySQL deadlock. Allocation requests are now sorted by `(document_type, document_id)` before locking reads; the same normalized list drives validation and persistence. A regression submits two invoices in reverse order, records invoice-read order, verifies both 50.00 allocations, and proves the payment ledger balances. This reduces lock-order conflicts but does not replace a real concurrent MySQL run.
- **Verification:** New carry-over/no-reload and legacy-no-guess regression cases pass, as does an API test proving GET preview is read-only and completed-trip stock comes from the saved close count even after the mutable vehicle balance changes. Full Backend on SQLite: **134 passed / 1326 assertions / 3 MySQL-only composite-FK tests skipped**. The canonical multi-invoice payment lock-order test is included. Frontend TypeScript/Lint passed, unit tests **22/22**, optimized Next.js build passed; PHP syntax and `git diff --check` passed.
- **Local preview / safety:** Created and hash-verified `backend/database/nexora-local-preview.sqlite.before-trip-opening-stock-20261019.bak`, SHA-256 `7087EA2D08C74371D061F35F00417ED617A04E8D324B4312E8054DAA9D2840CE`, then applied migration `2026_10_19_000011` to the stable local SQLite. Migration is `Ran`; `/api/v1/health`, `/login`, `/trips`, and `/inventory` returned HTTP 200. Existing 42 sales invoices, 50 purchase invoices, 216 stock movements, 33 payments, 18 expenses and 26 historical settlements were retained. There are 0 active / 45 archived demo trips, so opening snapshot tables remain empty; no synthetic opening, posting, or adjustment was written into preview data.
- **Next:** Keep NDX-060 active for the remaining source-document ledger review and real MySQL 8.4 concurrency evidence. The owner still needs authorized current-tree MySQL 8.4.11 validation and financial/deletion policy decisions. Do not infer shortage/surplus posting, tax, commission, retention, or deletion policy; do not mark production approval.
