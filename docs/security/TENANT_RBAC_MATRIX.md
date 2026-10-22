# Tenant isolation and RBAC matrix

**Last reviewed:** 2026-10-13  
**Owner:** NEXORA Distribution OS engineering  
**Status:** NDX-050 in progress — this is a live checklist, not a release approval.

## Rules

- Authenticated API routes must pass `auth:sanctum` and `tenant` middleware. `ResolveTenant` rejects missing tenant context, inactive users and inactive organizations.
- Resource permissions are independently enforced by `permission:*`; `RequirePermission` only accepts a role belonging to the user's organization.
- Every resource query and every referenced ID must be scoped to the authenticated `organization_id`. Shared/global reference data is an explicit exception, not a general cross-tenant exception.
- A green code-review result is not the same as a negative tenant test. Record the distinction below.

## Route and evidence matrix

| Resource / routes | Permission | Tenant enforcement observed | Evidence / remaining work | Status |
|---|---|---|---|---|
| Auth/session: `/auth/me`, `/auth/logout` | Authenticated + tenant middleware | `ResolveTenant` validates user and organization active state | `PermissionEnforcementTest`: inactive user and inactive organization both receive 403 | TESTED |
| Dashboard: `/dashboard` | `dashboard.view` | Every aggregate query filters by current org | `ReferenceTenantIsolationTest` inserts another tenant's posted sale and stock balance and verifies sales, inventory units/value, receivables and payables remain zero. | TESTED — core aggregates |
| Products/catalog: `GET /products`, `GET /catalog/references`, `POST /products` | `products.view`, `products.manage` | Product/category/accounting references use org filters; units may be global (`organization_id IS NULL`) or tenant-owned | `ProductCatalogTest` verifies own alias search works while foreign alias search returns no foreign product, and foreign category/unit references are rejected. Barcode/packaging CRUD endpoints do not currently exist; purchase packaging is tested separately. | TESTED — current routes |
| Customers: `GET/POST /customers`, `/field/customers` | `customers.view`, `customers.manage` | Customer list/create use current org; field creation derives tenant from authenticated user and validates coordinate ranges | `CustomerMasterTest` checks tenant-scoped creation and primary address persistence on both routes, foreign list isolation, duplicate code, and invalid coordinates without creating a customer. | TESTED — current routes |
| Suppliers: `GET/POST /suppliers` | `suppliers.view`, `suppliers.manage` | Supplier list/create use current org; purchase posting validates supplier ownership in service | `ReferenceTenantIsolationTest` verifies foreign supplier list isolation; `PurchaseTenantIsolationTest` rejects foreign supplier posting. | TESTED — current routes |
| Locations/inventory: `GET /locations`, `GET /inventory`, `POST /inventory/adjust` | `inventory.view`, `inventory.adjust` | Inventory list filters by `sb.organization_id`; posting service validates product/location | `InventoryTenantIsolationTest` verifies foreign product/location adjustment and filtered inventory query return no cross-tenant data. `LocationMasterTest`, schema and operational integrity tests also pass. | TESTED — key reads/writes |
| Purchasing: `POST /purchases`, history, invoice items | `purchases.post` | Controller filters history/invoice by org; posting service validates supplier/product/location/unit/packaging | `PurchaseTenantIsolationTest` rejects foreign supplier, location, product, unit and packaging IDs with no invoice/line/stock/ledger/idempotency mutation; foreign invoice history/detail reads are hidden. `PurchaseCostingTest` proves server packaging conversion wins over a deliberately incorrect client factor. | TESTED — current routes |
| Sales/invoices: `POST /sales`, history, invoice items | `sales.post`, `sales.view` | Posting service validates customer/product/location; reads scope invoice and related lines by org | `SalesReadTenantIsolationTest` verifies foreign customer filters for invoice list, history and open invoices return empty, and foreign invoice detail returns 404; service/idempotency/accounting tests and browser business flow also pass. | TESTED — current reads |
| Payments/allocations: `POST /payments` | `payments.record` | Posting service validates account, party, payment method, trip and each allocation against org or approved shared reference scope | `PaymentTenantIsolationTest` rejects foreign party, account, payment method, invoice allocation and trip; asserts no payment, ledger or idempotency rows are committed. | TESTED — covered references |
| Expenses: `GET/POST /expenses` | `expenses.view`, `expenses.post` | Controller scopes expense/account/trip/vehicle joins and foreign trip filters; posting service validates account/trip/vehicle and idempotency | `ExpenseTenantIsolationTest` verifies empty foreign list, foreign trip filter 404, foreign account rejected with no posting; `PermissionEnforcementTest` verifies split permissions; Playwright verifies replay/settlement | TESTED — focused paths |
| Trips/routes/vehicles: `/trips`, `/vehicles`, `/trips/{id}/stock`, customers, route, optimize, reorder | `trips.view`, `trips.manage` | `TripController` scopes trip/customer/vehicle reads and references by org | `TripTenantIsolationTest` verifies foreign trip create rejection, list isolation, and 404 for foreign stock/route/assign/optimize/reorder subroutes; Playwright distribution flow passes. | TESTED — current routes |
| Vehicle loading: `POST /trip-loads` | `inventory.adjust` | `TransactionPostingService` validates trip, vehicle, source location and products | `TripTenantIsolationTest` verifies foreign trip load returns validation failure and leaves no tenant-A load. `DistributionOperationalIntegrityTest` and Playwright cover successful stock-conserving load. | TESTED — foreign trip guard |
| Settlement: `POST /trip-settlements` | `payments.record` | Service validates trip and every closing product against the current organization; absent movement rows are treated as zero movement | `TripTenantIsolationTest` verifies foreign trip and foreign closing product rejection, successful zero-movement settlement, and duplicate settlement rejection without duplicate rows. `TransactionAccountingTest` and Playwright cover cash/stock reconciliation. | TESTED — current guards |
| Offline devices/operations: `/sync/device`, `/sync/operations` | `field.sync` | Device UUID lookup is scoped to authenticated organization; user binding, revoked status, and operation idempotency are enforced | `OfflineSyncIntegrityTest` verifies same-tenant device ownership, revoked device rejection, foreign-tenant device UUID rejection before sync-operation creation, operation UUID payload conflict, and replay without duplicate visit. | TESTED — focused guards |
| Field workflow: `/field/today`, `/field/visits` | `field.visit` | Trip must belong to the current tenant and assigned representative; customer must be assigned to that trip and belong to the same tenant; completed/cancelled trips cannot accept visits | `OfflineSyncIntegrityTest` verifies `/field/today` hides another tenant's active trip, direct and offline visit rejection for completed trips, foreign customer rejection, invalid coordinate bounds, and no extra visit mutation. | TESTED — focused tenant guards |

## Current test results

- `ExpenseTenantIsolationTest`: 2 passed / 9 assertions.
- `PermissionEnforcementTest`: 7 passed / 19 assertions, including foreign-role, inactive-user and inactive-organization checks.
- `PaymentTenantIsolationTest`: 1 passed / 19 assertions, including foreign party/account/payment method/invoice allocation/trip rejection and no partial financial posting.
- `SalesReadTenantIsolationTest`: 1 passed / 7 assertions; foreign invoice is not visible in list/detail/history/open-invoice views.
- `TripTenantIsolationTest`: 3 passed / 36 assertions; foreign trip and route/stock/customer/reorder/optimize/load/settlement endpoints are hidden or rejected without tenant-A mutation; duplicate product IDs in closing counts are rejected before settlement rows are created, and completed trips cannot be settled twice.
- `InventoryTenantIsolationTest`: 1 passed / 7 assertions; foreign product/location adjustments create no stock or idempotency records, and foreign inventory filters return no rows.
- `PurchaseTenantIsolationTest`: 1 passed / 11 assertions; foreign supplier/location/product writes rejected without any purchase/stock/ledger/idempotency mutation, and foreign purchase invoice list/detail reads are hidden.
- `PurchaseTenantIsolationTest`: 1 passed / 17 assertions; foreign supplier/location/product/unit/packaging writes rejected without any purchase/stock/ledger/idempotency mutation, and foreign purchase invoice list/detail reads are hidden.
- `ProductCatalogTest`: 1 passed / 24 assertions; product aliases resolve for the tenant that owns the product while a foreign tenant alias cannot surface its product.
- `PurchaseCostingTest`: 1 passed / 8 assertions; packaging conversion is taken from the server-owned packaging record, and base-unit conversion remains 1 even when the request sends an incorrect conversion factor.
- `ReferenceTenantIsolationTest`: 2 passed / 12 assertions; supplier and finance references remain tenant-scoped, only global payment methods are shared, and dashboard aggregates exclude another tenant's sale/stock/balances.
- `OfflineSyncIntegrityTest`: 18 passed / 89 assertions; device ownership/revocation/foreign-tenant checks, schema-version and operation-key conflict handling, replay bound to original user/device, stable rejected replay errors, sanitized unexpected errors, field-today tenant isolation, completed-trip rejection, foreign customer rejection, and coordinate validation.
- `CustomerMasterTest`: 1 passed / 23 assertions; both customer-creation routes persist tenant-owned customers/primary addresses, reject duplicate codes and invalid coordinates, and keep foreign customers out of reads.
- `SalesCostingTest`: 1 passed / 21 assertions; sale rejects foreign packaging references and preserves server-owned conversion/package snapshots for repeated product lines; sales returns keep packaging from the source invoice line and reject excess return discounts without mutation.
- `PurchaseReturnCostingTest`: 1 passed / 10 assertions; purchase return preserves original invoice packaging/base-cost snapshots, ignores a foreign tenant packaging ID, and rejects excess return discounts without stock mutation.
- `TransactionAccountingTest`: 18 passed / 90 assertions; repeated invoice allocations cannot exceed outstanding balance after purchase returns, currency mismatches reject without mutation, unsupported tax/purchase discount posting fails closed, returns against adjusted legacy invoices reject, and returns cannot push receivable/payable below zero.
- Full SQLite suite latest result is recorded in `docs/EXECUTION_LOG.md`; three composite tenant-FK tests require MySQL 8.4 and are skipped on SQLite by design.

## Exit criteria for NDX-050

1. Every endpoint group above has at least one explicit tenant boundary test at the API boundary, not only a direct service test.
2. Foreign identifiers never reveal another tenant's record: use consistent 404/422 policy and document it.
3. Cross-tenant writes leave no expense, invoice, stock movement, payment, ledger entry, balance projection or idempotency result behind.
4. Inactive user, inactive organization, cross-tenant role, and revoked device checks are all tested.
5. Review is complete only after the matrix is updated with actual test names and results; untested routes remain PARTIAL.
