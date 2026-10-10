# Project Status — NEXORA Distribution OS

## Current phase
Phase 1 — Local Alpha implementation and Batch 2 database review.

## Batch gate status
- Batch 1 — Backend Foundation: **APPROVED**
- Batch 2 — Database Core: **IMPLEMENTED LOCALLY; PRODUCTION GATE NOT YET APPROVED**
- Batches 3–10: meaningful implementation exists across API/frontend, but each batch still needs its formal review and remaining acceptance checks.

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
- Laravel 13 / PHP 8.3+ backend foundation
- Organization and identity schema foundation
- Tenant middleware foundation
- API health endpoint
- Permission seeder foundation
- PHPUnit foundation
- GitHub Actions CI with MySQL 8.4

## Batch 1 verification
- Composer dependencies install successfully.
- Laravel bootstrap loads successfully.
- MySQL 8.4 service initializes successfully.
- Empty-database migrations pass.
- Schema foundation tests pass.
- Permission seeding is repeatable.
- CI run 37723730138 completed with SUCCESS on commit d2d9aa34eaab706c24a964491d48228dabed9775.

## Batch 1 review decision
**APPROVED by NEXORA AI review.**

Initial CI failures were reproduced from evidence and corrected: invalid composer JSON, invalid Laravel bootstrap namespaces, missing bootstrap/cache, missing permission ULIDs, and an incorrect test namespace reference.

## Batch 2 scope now active
Implement the complete database contract from docs/architecture/DATABASE_SCHEMA_SPEC_v1.md.

Required areas:
- Reference/master data
- Egypt geography
- Catalog and packaging
- Suppliers/customers
- Locations/warehouses/vehicles
- Document sequences
- Idempotency
- Audit
- Ledger accounts/entries
- Stock movements/balances
- Purchasing
- Sales
- Finance/payments/allocations/expenses
- Trips/load/visits/settlement
- Offline sync/conflicts
- Transactional outbox
- Rebuildable balance/dashboard projections

## Remaining P0 business policy decisions
These must remain configurable and must not be guessed:
- Tax/VAT rules and invoice requirements
- Final costing policy approval
- Document numbering policy details
- Negative-stock policy confirmation
- Adjustment/approval thresholds
- Credit-limit enforcement policy
- Real workflow validation with representative data

## Local implementation evidence (2026-10-10)
- 9 database migrations exist for identity, reference data, catalog/parties, locations, transaction infrastructure, commercial documents, distribution/sync/projections, Sanctum tokens, and average cost.
- API currently exposes 35 routes under `/api/v1`, including login, tenant-protected master data, purchase/sale/payment/return posting, stock adjustments, trip/load/route/settlement, and field sync operations.
- Backend feature suite: **48 passed, 317 assertions**.
- Fresh SQLite migration lifecycle probe: **PASS** — migrate from empty DB, seed twice, roll back all migrations, migrate again, and seed again; the temporary probe database was removed.
- Tenant composite-FK negative tests: **2 passed on MySQL 8.4 CI** for the initial constraint set. The expanded constraint set is awaiting its CI run. SQLite intentionally skips these engine-specific constraints to avoid table rebuilds dropping pre-existing CHECK constraints; SQLite is not evidence for this security gate.
- Playwright browser E2E: **3 passed** (local Alpha login and real dashboard/API, offline field visit queue/reconnect sync, and Arabic PWA manifest/icon validation). Tests use isolated port 3027 and never reuse an existing server.
- API routes now enforce role permissions across dashboard, catalog, customers, suppliers, inventory, purchases, sales, payments, returns, trips, field visits, and sync. Permissions are scoped to roles owned by the authenticated organization; offline sync additionally checks permission for the specific transaction type before device lookup or replay acknowledgement.
- Inactive user accounts are rejected by tenant middleware even if a token still exists. Trip creation rejects vehicles, representatives, and origin locations owned by another organization.
- Added automated tests for permission denial/grant, foreign-organization role isolation and response filtering, offline sync privilege escalation, and cross-tenant trip creation.
- Frontend production build: passed; TypeScript lint: passed.
- Local frontend `http://127.0.0.1:3017`; local API `http://127.0.0.1:8017`.
- Local database is isolated SQLite at `backend/database/distribution-local.sqlite`; it must not be treated as production data.
- `php artisan nexora:local-alpha` creates a local-only administrator and starter master data idempotently. It inserts no artificial stock; stock must come from a posted purchase. Local login details are kept in an ignored machine-local file.
- `tools/start-local.ps1` checks ports 8017/3017 and reuses healthy NEXORA services without taking over other processes. `tools/stop-local.ps1` targets only matching repository-specific commands.

## Remaining before a controlled pilot
- The local-only bootstrap and login path are implemented and verified; production-grade tenant onboarding and credential delivery still need implementation.
- Verify migrations and constraints on the target MySQL 8.4 engine, not SQLite alone.
- Expand cross-tenant and permission tests to all remaining resource types and review the final role matrix with the business owner.
- Verify all UI forms against API contracts, including loading/error states and session expiry.
- Complete browser E2E tests for purchase → stock → vehicle load → trip sales/collection → settlement and returns. Current browser E2E covers the offline field workflow and PWA manifest.
- Finish offline PWA install/update behavior, conflict UI, and field-device lifecycle review.
- Audit ledger, costing, returns, tax/invoice, document numbering, and credit/negative-stock policies with the business owner.
- Production deployment, secrets, backups/restore drill, observability, rate limits, load tests, and security review.
- Validate with a real distribution company and reconcile opening balances before replacing any legacy workflow.

## Product readiness statement
**Local Alpha foundation only — not production-ready and not yet approved for live financial/inventory operations.** Do not market it as a finished system until the pilot and release gates pass.

## Environment limitation
On 2026-10-10, no local MySQL/MariaDB service was found and Docker CLI could not connect to the Docker Desktop engine. PHP's PDO MySQL driver is installed, but there is no MySQL server listening locally. The MySQL 8.4 migration gate remains untested; do not infer MySQL compatibility from passing SQLite tests.

## Database review findings (2026-10-10)
- Confirmed: all 9 migrations pass a fresh SQLite lifecycle, including full rollback/re-migrate and repeatable reference-data seeding.
- Confirmed: the existing GitHub Actions backend workflow defines a MySQL 8.4 service and migration/seed/rollback/test steps; current local commits have not been pushed, so that workflow has not verified these commits.
- Follow-up integrity review: many tenant-owned relations still use single-column foreign keys (for example product → unit/category, document → party/location, trip → vehicle/representative). API-level tenant checks exist on key workflows, but the schema does not yet enforce organization equality for every such relation with composite tenant-scoped foreign keys. Complete this review before approving Batch 2; ordinary foreign-key existence alone is not proof of tenant isolation.

## Important
The old system is currently unavailable. Do not block development on it. Later, create a migration adapter and reconcile opening balances, inventory and historical transactions.

## Next active task
TASK-004 validation gate — obtain an isolated MySQL 8.4 test server without starting or modifying shared project services, run the full migration/seed/rollback/test workflow on the current commits, then close the composite tenant-foreign-key review with negative tests. Keep Batch 2 active until these gates pass and NEXORA AI reviews the evidence. Next local-Alpha priority: browser-based end-to-end verification of purchase → stock → vehicle load → route → sale → collection → settlement, followed by offline conflict handling review.
