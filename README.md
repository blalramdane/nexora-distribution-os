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

The latest local verification recorded on 2026-10-10:

- Backend feature suite: **56 tests passed, 343 assertions**; 3 MySQL-only composite-FK tests are skipped on local SQLite by design.
- Latest MySQL 8.4.11 backend gate: **59 passed, 343 assertions**; fresh migration, repeatable seeding, full rollback, re-migration, and final seeding also passed on commit `29a7348` ([run details](https://github.com/blalramdane/nexora-distribution-os/actions/runs/38046598388)).
- Frontend production build: passed.
- Frontend TypeScript check (`npm run lint`): passed.
- Playwright browser E2E: **4 passed**, including Local Alpha login, UI creation of product/customer/supplier, purchase receipt, vehicle loading, trip-linked sale, allocated collection, trip settlement with zero cash/stock variance, invoice-linked sales and purchase returns, field visit check-in/completion, final warehouse stock reconciliation, offline field sync, and PWA installability.
- Local frontend responds at `http://127.0.0.1:3017`.
- Local API health responds at `http://127.0.0.1:8017/api/v1/health`.
- Local development uses a project-specific SQLite database. The production MySQL migration gate, security review, real-data pilot, backup/restore, and end-to-end browser tests are not yet approved.

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

The frontend must have `frontend/.env.local` set to `NEXT_PUBLIC_API_URL=http://127.0.0.1:8017/api/v1`. The backend's `CORS_ALLOWED_ORIGINS` must include the exact frontend origin (the local default covers ports 3017 on `localhost` and `127.0.0.1`); set an explicit trusted allowlist for production. Never point this local environment at another project's database or reuse its credentials. Local start/stop helper scripts are in `tools/` and refuse to take over occupied ports.

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
