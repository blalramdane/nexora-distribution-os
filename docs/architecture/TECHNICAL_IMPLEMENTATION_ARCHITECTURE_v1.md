# NEXORA Distribution OS — Technical Implementation Architecture v1

Status: Approved implementation contract for TASK-002.
Scope: Production foundation architecture; no feature implementation in TASK-001.

## 1. Architecture Decision Summary

NEXORA Distribution OS will use:

- Backend: Laravel 13 / PHP 8.3+ as a modular monolith.
- Database: MySQL 8.4 LTS.
- Admin Web: React + TypeScript + Vite, RTL-first, API-driven.
- Field: React + TypeScript PWA with IndexedDB and service worker.
- Async: Redis-backed queues.
- External side effects: durable transactional outbox.
- Primary consistency model: server-authoritative transactional core.
- Offline model: local operation queue + scoped read model + idempotent server synchronization.
- Deployment: Docker-based reproducible environments; CI validates every merge.
- Observability: structured logs, correlation IDs, audit events, metrics, health checks.

The architecture preserves the existing product contracts: transaction engine, append-oriented stock ledger, customer/supplier ledgers, vehicle-as-location, trip settlement, offline-first field work, tenant isolation, idempotency, auditability, and minimal-click UX.

## 2. Repository Shape

Target repository:

    /
    ├── backend/
    │   ├── app/
    │   │   ├── Domain/
    │   │   │   ├── Identity/
    │   │   │   ├── Catalog/
    │   │   │   ├── Geography/
    │   │   │   ├── Parties/
    │   │   │   ├── Locations/
    │   │   │   ├── Purchasing/
    │   │   │   ├── Sales/
    │   │   │   ├── Finance/
    │   │   │   ├── Inventory/
    │   │   │   ├── Distribution/
    │   │   │   ├── Communication/
    │   │   │   ├── Sync/
    │   │   │   └── Audit/
    │   │   ├── Application/
    │   │   │   ├── Commands/
    │   │   │   ├── Queries/
    │   │   │   └── DTOs/
    │   │   └── Infrastructure/
    │   │       ├── Persistence/
    │   │       ├── Queue/
    │   │       ├── Outbox/
    │   │       ├── Observability/
    │   │       └── Integrations/
    │   ├── routes/
    │   ├── database/
    │   └── tests/
    ├── admin/
    ├── field/
    ├── docs/
    ├── infra/
    └── .github/workflows/

The exact directory layout may evolve only through an ADR or explicit architecture review.

## 3. Backend

### 3.1 Framework

Lock production development to Laravel 13 with PHP >= 8.3. Laravel 13 is the current major release and requires PHP 8.3; its published security support extends to Q1 2028.

Do not start implementation on Laravel 11 or 12 unless a documented compatibility blocker is discovered.

### 3.2 Modular monolith

Each domain owns its business rules. Cross-domain communication uses application services and domain events, not direct controller-to-controller calls.

Core modules:

| Module | Responsibility |
|---|---|
| Identity | organizations, users, roles, permissions, devices, sessions |
| Catalog | products, categories, units, barcodes, aliases, supplier mappings |
| Geography | governorates, centers, cities/areas |
| Parties | customers, suppliers, addresses, location events |
| Locations | warehouses, vehicles, generic stock locations |
| Purchasing | purchase invoices/returns and supplier-side posting |
| Sales | sales invoices/returns and customer-side posting |
| Finance | payments, allocations, accounts, expenses, balances |
| Inventory | stock movements, balances, transfers, stocktake, costing |
| Distribution | trips, loads, visits, collections, returns, settlement |
| Communication | message intents, templates, delivery status |
| Sync | device operations, synchronization, conflicts |
| Audit | security/business audit trail |

Controllers are transport adapters only. They validate transport input, authorize, call an application command/query, and serialize a response.

### 3.3 Command / Query conventions

Mutations use explicit commands, e.g.:
- PostPurchaseInvoice
- PostSaleInvoice
- PostSalesReturn
- RecordPayment
- AllocatePayment
- TransferStock
- LoadVehicle
- CreateTrip
- SettleTrip

Queries are side-effect free and may use read projections optimized for UI.

Controllers must not implement financial or stock calculations.

### 3.4 Validation and authorization

Validation has three layers:
1. Transport validation: shape, required fields, formats.
2. Domain validation: business invariants.
3. Transaction-time validation: current stock/balance/concurrency state inside the database transaction.

Authorization is server-side and deny-by-default. Organization/tenant scope is mandatory in repository/query boundaries; a client-provided organization ID is never trusted.

### 3.5 Transaction boundaries

A posted business command executes in one database transaction whenever its effects must be atomic.

Typical sale:
validate → lock relevant stock/account rows → create invoice/items → create stock movements → create ledger effects → allocate payments → write outbox/audit → commit.

Do not publish external messages directly inside the transaction.

### 3.6 Idempotency

All retryable mutations accept Idempotency-Key: client-generated stable key.

Uniqueness scope:
organization_id + idempotency_key + operation_type.

The server stores request fingerprint and resulting status/response reference. Replaying the same key with the same fingerprint returns the original result. Reusing a key with a different fingerprint is rejected.

Offline clients additionally send:
- operation UUID
- device UUID
- client-created timestamp
- client schema/version
- local sequence number where useful

### 3.7 Events and outbox

Domain events describe committed facts, such as SalePosted, PaymentRecorded and TripSettled.

The outbox stores external side effects in the same transaction as the business fact. A worker later delivers WhatsApp, secure-link notifications, exports, integrations or analytics events.

External providers are never required for core posting success.

### 3.8 Queues

Use Redis queues for:
- outbox delivery
- WhatsApp delivery
- document generation
- Excel exports
- imports
- heavy reports
- notification fan-out

Jobs must be retry-safe and idempotent. Failed-job handling is mandatory for production.

### 3.9 Error model and observability

API errors use stable machine-readable codes, human-safe Arabic messages, and validation details where appropriate.

Never expose stack traces, SQL, secrets, internal class names or provider credentials.

Every request receives a correlation ID. Logs include correlation ID, organization ID, actor ID, device ID where available, command type, outcome, duration and safe entity identifiers.

## 4. Database

### 4.1 Version and engine

Target MySQL 8.4 LTS and InnoDB for transactional tables.

### 4.2 Keys

- Internal primary keys: UUID/ULID, represented consistently across the application.
- Public identifiers must not expose sequential internal IDs where enumeration would be harmful.
- Every tenant-owned table contains organization_id directly unless documented otherwise.
- Foreign keys are enforced for core relational integrity.
- Cascading deletes are prohibited for posted financial/inventory records.

### 4.3 Money and quantities

- Money: DECIMAL, never floating point.
- Default money precision: DECIMAL(19,4).
- Quantity: DECIMAL(19,6).
- Currency is explicit on monetary documents/accounts where multi-currency is enabled.
- Tax amounts/rates are explicit fields.

### 4.4 Time

- Store timestamps in UTC.
- Organization has an explicit IANA timezone, defaulting to Africa/Cairo for Egypt tenants.
- UI renders in organization/user timezone.
- Client timestamps are metadata, never authoritative for posting order.

### 4.5 Deletes

- Posted transactions: immutable; correct by reversal/return/adjustment.
- Master data: soft delete only where historical references require retention.
- Reference data: deactivation preferred over deletion.
- Physical deletion is restricted to non-business technical records under retention policy.

### 4.6 Audit

Audit records capture actor, organization, device, action, entity type/id, safe before/after summary, correlation ID, IP/session metadata where applicable, and timestamp.

Sensitive actions include permission changes, device revocation, posted-document reversal, stock adjustments, balance adjustments, sensitive exports and configuration changes.

### 4.7 Indexing

Required patterns:
- tenant + business date
- tenant + status
- tenant + foreign key
- tenant + normalized searchable name
- SKU/barcode unique within tenant where applicable
- ledger/account + date
- stock location + product
- trip + customer
- sync device + operation UUID
- outbox status + next_attempt_at

Avoid redundant indexes and validate query plans against realistic data volumes.

### 4.8 Projections

Authoritative facts:
- posted documents
- stock movements
- payment allocations
- ledger entries
- trip settlement records

Derived projections:
- stock balances
- customer balance summary
- supplier balance summary
- dashboard aggregates
- search indexes where needed

Every projection has a deterministic rebuild path from authoritative records.

### 4.9 Concurrency

Use row-level locking for scarce mutable resources such as stock availability, payment allocation capacity and settlement state.

Use optimistic version checks for user-facing editable aggregates where appropriate.

Frontend checks are never authoritative for stock or balance correctness.

## 5. API Contract

Base path: /api/v1.

### 5.1 Authentication

Use short-lived access sessions/tokens appropriate to the web/PWA architecture plus revocable device/session records. Authentication is paired with organization and permission context.

Field devices receive a device identity and can be revoked remotely.

### 5.2 Resources

Use plural nouns:
- /products
- /customers
- /suppliers
- /sales
- /purchases
- /payments
- /trips
- /stock-movements

Business commands use explicit action endpoints where a plain REST update would hide semantics, for example POST /sales/{id}/post.

### 5.3 List conventions

Support:
- page, per_page
- search
- explicit filters
- sort with allow-listed fields
- stable default ordering
- cursor pagination for high-volume feeds where needed

Never accept arbitrary SQL fragments or unchecked sort/filter expressions.

### 5.4 Error envelope

Canonical shape:

    {
      "error": {
        "code": "STOCK_INSUFFICIENT",
        "message": "الرصيد المتاح لا يكفي لإتمام العملية.",
        "details": {},
        "correlation_id": "01..."
      }
    }

Validation errors use the same envelope with field-level details.

### 5.5 Headers

Requests may include:
- Authorization
- Accept-Language
- X-Correlation-Id
- Idempotency-Key
- device/session headers for field synchronization

The server generates a correlation ID if missing and returns it.

### 5.6 Sync endpoints

Reserved under /api/v1/sync:
- device registration/status
- pull changes since server cursor
- push operation batch
- operation result/status
- conflict retrieval/resolution where explicitly permitted

Sync is not generic database replication; it exchanges domain operations and scoped projections.

## 6. Admin Web

### 6.1 Stack

React + TypeScript + Vite.

Use:
- React Router
- TanStack Query for server state/cache
- React Hook Form + schema validation for complex forms
- small consistent component system
- Lucide or equivalent icons
- RTL-first styling and Arabic localization

Do not duplicate backend business rules in the frontend.

### 6.2 UX architecture

Admin focuses on high-volume operations:
- command palette / global search
- keyboard navigation
- quick actions
- dense but readable tables
- Excel-like purchase/sales entry
- debounced search/autocomplete
- smart defaults
- bulk operations

All critical actions expose loading, success, error, empty and permission-denied states.

### 6.3 Permissions

UI hides unavailable actions for clarity, but API remains authoritative.

Permission checks are capability-based, not merely role-name checks.

## 7. Field PWA

### 7.1 App shell

The PWA must load its core shell and previously synchronized trip-scoped data while offline.

Service worker responsibilities:
- app-shell caching
- static asset caching
- controlled update/version lifecycle

Do not cache private API responses blindly in generic HTTP caches.

### 7.2 IndexedDB

Logical stores:
- app_meta
- devices
- products
- customers
- customer_addresses
- trip
- trip_load
- local_drafts
- operation_queue
- operation_results
- sync_cursor
- sync_conflicts

Local data is scoped to authenticated user/device/trip and has explicit expiration/revocation behavior.

### 7.3 Local transaction model

The field app records a business operation locally first when offline:

create operation UUID → validate against local snapshot → append immutable local operation → update local projection optimistically → queue for sync.

The local projection is explicitly marked pending and is not presented as server-posted truth.

### 7.4 Sync lifecycle

offline → queued → syncing → accepted | rejected | conflict → projected.

Push uses batches with stable operation UUIDs. Server processes each operation idempotently.

Pull uses a server cursor and returns only data authorized for that device/user.

ACK contains exact operation UUID and server transaction/document identifiers.

### 7.5 Conflicts

Conflicts are classified:
- Safe retry: transient/network.
- Duplicate: operation already accepted; return original result.
- Business rejection: current stock/permission/state invalid.
- Concurrency conflict: server state changed.
- Security/device revoked: stop sync and require re-authentication.

Do not silently merge financial transactions.

### 7.6 Device identity and security

Each device has a server-issued device ID and revocation state.

Local sensitive data must be minimized. Credentials/tokens use platform-secure storage where available; IndexedDB stores business data, not long-lived secrets.

On logout/revocation, private local data is invalidated and removed according to device security policy.

## 8. Infrastructure

### Local

Docker Compose profile with backend, MySQL 8.4, Redis, admin and field. Add mail/test service as needed.

### Staging

Dedicated database, Redis, object storage, queue workers, monitoring and isolated secrets. Deploy from CI artifacts.

### Production

Minimum topology:
- HTTPS reverse proxy/load balancer
- Laravel application instances
- queue workers
- MySQL primary with tested backup strategy
- Redis
- object storage
- monitoring/error tracking

Redis is never the source of truth.

### Backups

- automated encrypted database backups
- point-in-time recovery where supported
- object-storage versioning/retention
- restore drills before production sign-off

### Secrets

Secrets live in environment/secret-management infrastructure, never source control. Rotate credentials and revoke compromised devices/tokens.

### CI/CD

CI gates:
1. dependency install
2. lint/format
3. static analysis/type checking
4. unit/integration tests
5. database migration test
6. frontend build
7. production artifact validation
8. security/dependency audit where available

Deployment is immutable and versioned. Database migrations must be backward-compatible during rolling deploys.

## 9. Observability

Every request/job gets:
- correlation ID
- duration
- outcome
- actor/organization/device context where safe
- operation/command type

Metrics:
- API latency/error rate
- queue depth/failures
- sync success/rejection/conflict rates
- outbox backlog
- inventory posting failures
- trip settlement failures
- database health
- active/last-sync devices

Alerts focus on actionable failures.

## 10. Testing Strategy

### Unit
Pure domain calculations, validators, policies, mappers and costing rules.

### Feature / integration
Full command posting against a real test database for purchase, sale, return, payment/allocation, stock transfer, vehicle load and trip settlement.

### Invariant tests
- every posted sale has corresponding stock/financial effects
- no negative vehicle stock under MVP policy
- payment allocation never exceeds available payment/document balance
- stock balance equals movement sum
- customer/supplier balances reconcile
- idempotent replay creates no duplicate posting

### Concurrency
Test two clients posting against the same stock/customer/account simultaneously.

### Sync
Test offline create/reconnect/accept, duplicate replay, stale stock, revoked device, out-of-order batch, partial batch failure and cursor recovery.

### E2E
Critical path:
purchase → warehouse receipt → vehicle load → trip → sale → collection → return → settlement → reports.

### Load/simulation
Generate realistic datasets of approximately 1,000 SKUs, many customers, many invoices and multi-vehicle trips, then simulate concurrent field operations.

### Security
Test tenant isolation, permission boundaries, session/device revocation, ID enumeration resistance, unsafe filters, upload/export access and audit integrity.

## 11. Delivery Rules

TASK-002 must turn this architecture into:
1. database specification and migration order
2. API contract detail
3. Laravel application skeleton
4. testing harness

No production feature may bypass the architecture.

Architecture changes after implementation begins require an ADR or explicit review when they alter a core invariant.

## 12. Technology Decision Notes

Technology choices are intentionally conservative. The product differentiator is the transaction/distribution model, not framework novelty.

Laravel 13/PHP 8.3+ is selected because it is the current supported Laravel major and aligns with the existing PHP/Laravel direction. MySQL 8.4 LTS is selected to reduce operational churn for a transaction-heavy SaaS.

## 13. Acceptance Checklist

- [x] Backend architecture
- [x] Module boundaries
- [x] DB rules
- [x] API conventions
- [x] Admin architecture
- [x] Field PWA/offline model
- [x] Infrastructure
- [x] Testing
- [x] Security/observability
- [x] No TASK-001 decision TODOs remain


## 4.10 Product Packaging and Unit Conversion

The schema and transaction implementation must support multiple commercial units for one product.

Canonical model:
- Product has a base stock unit.
- Packaging definitions represent a higher unit such as carton/package.
- Each packaging definition stores pieces-per-unit (conversion factor), unit label, optional barcode, and active/effective metadata.
- Transaction lines snapshot entered unit, entered quantity, conversion factor, and normalized base quantity.
- Stock movements store normalized base quantity.
- Carton and piece barcodes can resolve to the same product while retaining the identified unit.
- Historical transactions are immutable and keep their conversion snapshot even if packaging master data changes later.

The minimum supported scenario is:
**1 carton = N pieces; sale/purchase/return can be carton, piece, or mixed.**

Example: 3 cartons + 4 pieces with a 50-piece carton posts 154 base pieces.

The database specification in TASK-002 must define packaging tables, constraints, indexes, conversion precision, barcode uniqueness, historical snapshots, and migration/seed behavior.
