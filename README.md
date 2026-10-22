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

## Current implementation status

The repository contains an implemented **local-alpha foundation**, not a production-ready release. Current implementation includes Laravel API authentication and tenant context, product/customer/supplier master data, purchases and sales posting, stock movements and balances, payments and returns, trip/vehicle loading and settlement, route ordering, and a field-operation sync foundation. The Next.js Arabic RTL frontend includes pages for these workflows.

Latest local verification recorded on 2026-10-20 (this is **local-only** and does not constitute release approval):

- Backend feature suite on isolated/local SQLite: **140 passed, 1460 assertions; 3 MySQL-only composite-FK tests skipped by design**. The MySQL 8.4.11 release gate has not been rerun against this current tree in this session.
- Frontend TypeScript check (`npm run lint`): passed; unit tests: **27/27 passed**; production Next.js build: passed.
- Playwright browser E2E: **11/11 passed**, covering Local Alpha login and operational CRUD, purchase OCR posting without duplicate stock, null-field OCR draft review, offline field sync, field portal and saved customer pricing, PWA shell fallback, and operational/receivables PDF + Excel downloads.
- The trip detailed report and operational/receivables report exports are available as PDF and Excel; Excel exports use Arabic/RTL worksheets.
- The field portal now reads a customer-specific saved price by product and sale unit through an assignment-scoped endpoint. The value remains editable before issuing the invoice.
- A Service Worker caches only the UI shell and static assets; API/auth responses are intentionally not cached. This is not a claim that all financial operations are safe offline on a physical rep device.
- Local frontend responds at `http://127.0.0.1:3017`.
- Local API health responds at `http://127.0.0.1:8017/api/v1/health`.
- Local development uses a project-specific SQLite database; E2E used a separate migrated SQLite copy with a backup. The original local-preview database was not used for browser tests and its hash was checked after the backend gate.
- **Release remains blocked:** Docker Engine could not be started here, so Compose was validated but not built/run; MySQL 8.4 current-tree migration/test gate, backup/restore drill, production-data reconciliation, physical-device offline acceptance, external security/load review, owner-approved finance/tax policies, HTTPS deployment, secrets and a real public URL remain outstanding.

Do not describe this version as production-ready until the release gates in `TASK_LOOP.md` are met. See `PROJECT_STATUS.md` for the active task and remaining gaps.

## Local development

Prerequisites: Windows, Node.js/npm, Composer, and PHP 8.3+. The current local setup has a project-scoped PHP runtime in `.tools/php83/` and uses SQLite to avoid touching any other local project database.

Frontend:

```powershell
cd frontend
npm ci
npm run dev -- --hostname 127.0.0.1 --port 3017
```

Backend (in a second terminal; use the repository-scoped PHP runtime if it exists):

```powershell
cd backend
..\.tools\php83\php.exe -c ..\.tools\php83\php.ini artisan serve --host=127.0.0.1 --port=8017
```

For the first local setup, copy `backend/.env.example` to `backend/.env`, set `APP_ENV=local`, set `DB_CONNECTION=sqlite`, and point `DB_DATABASE` to an absolute path ending in `backend/database/distribution-local.sqlite`. Then run `artisan key:generate`, `artisan migrate --seed`, and `artisan nexora:local-alpha`. The last command creates a local-only admin and starter records; its ignored `.local-alpha-credentials.txt` file contains the generated login details. It must never be committed or reused outside this machine.

The frontend must have `frontend/.env.local` set to `NEXT_PUBLIC_API_URL=http://127.0.0.1:8017/api/v1`. Never point this local environment at another project's database or reuse its credentials. Local start/stop helper scripts are in `tools/` and refuse to take over occupied ports.

### Isolated Docker Compose environment (local smoke/pilot preparation only)

The Compose environment creates its own MySQL 8.4 database and a separate API/UI runtime. It does not use `backend/database/distribution-local.sqlite`, and it intentionally uses host ports **8081 (API), 3001 (UI), and 3307 (MySQL)** so it does not take over the local development ports or a different project's `8000` endpoint. This is a reproducible local container setup, not production approval; run a fresh-tree MySQL migration/seed/rollback/test gate and the remaining release checks before production use.

1. Ensure Docker Desktop is running. Copy `docker.env.example` to the ignored root `.env` and set two different strong MySQL passwords. Generate an application key with `cd backend; ..\\.tools\\php83\\php.exe -c ..\\.tools\\php83\\php.ini artisan key:generate --show`, then paste the result into root `.env` as `APP_KEY`.
2. Set `APP_URL=http://localhost:8081` and `NEXT_PUBLIC_API_URL=http://localhost:8081/api/v1` for local access, or set those values to the externally reachable URLs before building for a remote test environment. `NEXT_PUBLIC_API_URL` is embedded in the browser assets during the frontend build, so changing it only at container runtime is not enough; rebuild the frontend after changing it.
3. Validate the configuration without starting containers using `docker compose config --quiet`, then build with `docker compose build`. Neither command changes the running local SQLite app.
4. Start the isolated services with `docker compose up -d`, then initialize the disposable Compose database with `docker compose exec backend php artisan migrate --seed --force`. The Compose stack has its own named MySQL and uploaded-file volumes; never point it at production or import the real distributor's live data while testing.
5. The UI is available at `http://localhost:3001`; API health is `http://localhost:8081/api/v1/health`. Stop the stack with `docker compose down`. The named volumes are retained; remove them only after verifying they contain no data that must be retained.

No `APP_KEY`, password, `.env`, or local SQLite file is baked into the image. The compose file intentionally fails validation if the required secrets are empty rather than silently selecting a shared default password.

Browser smoke tests run on isolated port `3027` and never reuse another running server:

```powershell
cd frontend
npm run test:e2e
```

The local Alpha browser tests read credentials from the ignored `../.local-alpha-credentials.txt` file (or from `NEXORA_E2E_ORG`, `NEXORA_E2E_LOGIN`, and `NEXORA_E2E_PASSWORD` overrides). The E2E workflow writes clearly labeled demo master data, purchase/sale/payment, field visit, and trip-settlement/return records to local demo SQLite; those records are retained for auditability. In the trip test, five units are received, three loaded, one sold, one returned to the customer, and one returned to the supplier; final demo stock is two units at the vehicle and two at the warehouse. Treat all E2E records as demo data.

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
