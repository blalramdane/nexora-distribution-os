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
- 12 database migrations exist for identity, reference data, catalog/parties, locations, transaction infrastructure, commercial documents, distribution/sync/projections, Sanctum tokens, average cost, tenant-scoped foreign keys, tenant-scoped user roles, and shared-payment-method constraint repair.
- API exposes 36 routes under `/api/v1`, including an organization-scoped open-sales-invoice lookup for safe collection allocation, alongside master data and transaction workflows.
- Backend feature suite: **54 passed, 336 assertions** on isolated local SQLite; **3 MySQL-only composite-FK tests skipped by design** on SQLite.
- Fresh SQLite migration lifecycle probe: **PASS** — migrate from empty DB, seed twice, roll back all migrations, migrate again, and seed again; the temporary probe database was removed.
- MySQL 8.4 CI (MySQL **8.4.11**, commit `0c89ee7`): **all lifecycle steps passed** — fresh migration, seed twice, full rollback, re-migrate, final seed, and test run.
- MySQL backend suite: **51 passed, 317 assertions**, including 3 negative cross-tenant database constraint tests and the end-to-end distribution workflow.
- SQLite backend suite: **48 passed, 317 assertions; 3 MySQL-only constraint tests skipped by design**. SQLite intentionally skips these engine-specific constraints to avoid table rebuilds dropping pre-existing CHECK constraints; SQLite is not evidence for the MySQL security gate.
- User-role assignment pivot now carries `organization_id`; both Eloquent relationships automatically scope pivot writes/reads, and migration 11 backfills existing assignments while refusing to migrate pre-existing cross-tenant role assignments.
- Playwright browser E2E: **4 passed** on isolated port 3027. The business-flow test creates product/supplier/customer in the UI, purchases 5 units, loads 3 on a trip, sells 1 from vehicle stock, collects and allocates the remaining invoice balance, verifies trip settlement (expected/actual cash 25 EGP, cash variance 0, stock variance value 0) and final warehouse/vehicle balances of 2 units each, then returns one unit against the original sale invoice and confirms warehouse stock rises to 3. Offline field sync, Local Alpha login, and Arabic PWA checks also pass. Test data is labeled `E2E-` / `آلي` and stays in local demo SQLite.
- Fixed a confirmed payment-posting failure: the previous `payments` composite tenant FK incorrectly rejected global/shared payment methods (`organization_id = NULL`). Migration `2026_10_10_000012_fix_shared_payment_method_tenant_constraint` safely removes that invalid constraint from old SQLite/MySQL schemas, preserves existing payment rows, and verifies SQLite foreign keys after the upgrade. The current local database migrated without row-count changes or SQLite FK violations; a separate legacy-schema probe with two posted payments verified both rows survive the repair.
- Trip sales are now linked to the selected open trip and must use its vehicle stock location; trip collections validate same-tenant/open-trip status and can be allocated to the selected customer invoice.
- Fixed a confirmed master-data/API blocker: `LocationController` filtered by a nonexistent `locations.active` field while the schema uses `locations.status`; the endpoint now filters `status=active` and has a regression test.
- API bootstrap now returns JSON `401` for unauthenticated `/api/*` requests even when the client omits `Accept: application/json`, preventing Laravel from redirecting to the undefined web `login` route and returning `500`.
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
- Re-run the complete migration/seed/rollback/test workflow on the target MySQL 8.4 engine for migration 12 and the tenant-FK adjustment; the most recent MySQL 8.4.11 CI success predates these local schema changes.
- Expand cross-tenant and permission tests to all remaining resource types and review the final role matrix with the business owner.
- Verify all UI forms against API contracts, including loading/error states and session expiry.
- Extend browser E2E to cover purchase returns, trip expense handling, route visit completion, replay/idempotency at the UI boundary, and offline conflicts. The browser now verifies sales returns linked to original invoices and the core purchase → vehicle load → trip sale → allocated collection → settlement workflow.
- Finish offline PWA install/update behavior, conflict UI, and field-device lifecycle review.
- Audit ledger, costing, returns, tax/invoice, document numbering, and credit/negative-stock policies with the business owner.
- Production deployment, secrets, backups/restore drill, observability, rate limits, load tests, and security review.
- Validate with a real distribution company and reconcile opening balances before replacing any legacy workflow.

## Product readiness statement
**Local Alpha foundation only — not production-ready and not yet approved for live financial/inventory operations.** Do not market it as a finished system until the pilot and release gates pass.

## Environment limitation
On 2026-10-10, no local MySQL/MariaDB service was found and Docker CLI could not connect to the Docker Desktop engine. PHP's PDO MySQL driver is installed, but there is no MySQL server listening locally. An earlier MySQL 8.4.11 CI migration gate passed on commit `0c89ee7`; the latest local migration 12 and adjustment to migration 10 have not yet been verified on MySQL 8.4, so Batch 2 remains active pending a CI run on the current commit.

## Database review findings (2026-10-10)
- Confirmed: all 12 migrations passed the latest backend test run on SQLite. Migration 12 was tested against a copy of the pre-existing local SQLite DB and applied to the live local Alpha DB; existing invoice/trip/payment row counts were preserved and `PRAGMA foreign_key_check` returned no violations. Repeat the full fresh migrate/seed/rollback/re-migrate lifecycle and MySQL 8.4 gate before approval.
- Confirmed: the existing GitHub Actions backend workflow defines a MySQL 8.4 service and migration/seed/rollback/test steps; current local commits have not been pushed, so that workflow has not verified these commits.
- Follow-up integrity review: some nullable `SET NULL` relationships, global/shared reference data (such as units/geography), and polymorphic references (payment party, allocation document, ledger party/source) cannot be safely converted to composite tenant foreign keys without explicit domain/deletion-policy decisions. API-level checks cover key workflows; document and test these remaining exceptions before approving Batch 2.

## Important
The old system is currently unavailable. Do not block development on it. Later, create a migration adapter and reconcile opening balances, inventory and historical transactions.

## Next active task
TASK-004 validation gate — run the current migration 10/12 chain through isolated MySQL 8.4.11 CI, and close remaining domain/deletion-policy decisions for nullable `SET NULL`, shared reference data, and polymorphic finance references. The browser now verifies purchase → vehicle load → trip sale → invoice allocation/collection → settlement; next cover returns, trip expenses, route visit completion, and offline conflict handling. Keep Batch 2 active until the schema gate and NEXORA AI review pass.
