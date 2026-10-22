# Changelog — NEXORA Distribution OS

All notable changes to this project are documented in this file.

## [Unreleased]

### Added
- `Dockerfile` — multi-stage Docker build for Laravel 13 + PHP 8.3 backend and Next.js 16 frontend.
- `docker-compose.yml` — full local stack: MySQL 8.4, backend API, frontend.
- `Makefile` — development and CI helper targets.
- Protective guard in `frontend/app/sales/page.tsx`: verifies `r.id` exists before setting `savedInvoiceId` and showing the "عرض الفاتورة المسجلة" link.
- `TASKS_REMAINING_AR.md` — remaining task tracker with phases A–D.

### Changed
- None yet.

### Deprecated
- None.

### Removed
- None.

### Fixed
- None yet.

### Security
- None yet.

## [Local Alpha] — 2026-10-10

### Added
- Laravel 13 / PHP 8.3 backend foundation with Sanctum authentication.
- Tenant middleware and RBAC permission enforcement on all `/api/v1` routes.
- 12 database migrations covering identity, reference data, catalog, parties, locations, transactions, commercial documents, distribution, sync, projections.
- API health endpoint `GET /v1/health` and login `POST /v1/auth/login` with rate limiting.
- 41 API routes for dashboard, sales, purchases, inventory, payments, expenses, returns, trips, field visits, sync.
- Next.js 16 / React 19 Arabic RTL frontend with App Router pages.
- Playwright E2E suite (4 tests) covering login, CRUD, purchase-to-sale flow, trip settlement, returns, offline sync, PWA manifest.

### Fixed
- `LocationController` filter corrected from nonexistent `locations.active` to `status=active`.
- API bootstrap returns JSON `401` for unauthenticated `/api/*` instead of redirecting to undefined web login.
- Shared payment method tenant FK removed (migration 12) to allow global payment methods with `organization_id = NULL`.
- Sales derivation now uses server-authoritative packaging conversion, not client-supplied factors.
- Purchase returns use original invoice packaging and base-unit cost snapshots.
- Duplicate payment allocations accumulated per invoice before balance comparison.
- Currency mismatch guard: documents rejected if currency differs from organization base currency.
- Header tax/discount fail-closed guard until accounting policy is approved.
- Return balance guard: returns cannot exceed remaining receivable/payable.
- Supplier allocation now subtracts posted purchase returns from net payable.
- Offline sync replay hardening: schema version, operation UUID, user/device, idempotency key binding.

### Removed
- None.

## [Unreleased — Planned]

- Complete Eloquent models for all 15+ tables (currently only 4 models exist).
- Missing API CRUD: update/delete/show for customers, suppliers, products; warehouse management; user/role management; audit log endpoint; reports endpoints.
- Transactional outbox and queue workers for reliable sync.
- PWA service worker (`sw.js`) for offline-first field operations.
- OpenAPI specification (`docs/api/openapi.yaml`).
- MySQL 8.4.11 CI gate verification on current working tree.
- Egypt tax/e-invoice/e-receipt research and ADR (NDX-170).
