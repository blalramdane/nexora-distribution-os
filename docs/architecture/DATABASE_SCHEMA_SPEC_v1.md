# NEXORA Distribution OS — Database Schema & Migration Specification v1

## Status
APPROVED — implementation contract for TASK-002.

## 1. Database Contract

Target: MySQL 8.4 LTS, InnoDB, UTF-8/utf8mb4.

The database stores authoritative business facts and rebuildable projections. The transaction engine is the only supported path for posting inventory and financial effects.

### Authoritative facts
- posted document headers/items
- stock movements
- financial transactions and payment allocations
- customer/supplier ledger entries
- trip settlement records
- audit records
- sync operation results

### Projections
- stock_balances
- customer/supplier balance summaries
- dashboard aggregates
- search/read models

Projections must be rebuildable from authoritative records.

## 2. Global Conventions

### IDs
Use ULID/UUID application identifiers consistently. The exact Laravel storage representation is chosen once in implementation and used consistently across all tables.

Every tenant-owned table includes:
- id
- organization_id
- created_at
- updated_at

Technical records may omit organization_id only when explicitly global/reference data.

### Tenant isolation
Every tenant-scoped foreign key relationship must be validated so records from different organizations cannot be connected.

Recommended composite uniqueness pattern: UNIQUE (organization_id, business_key).

### Timestamps
- UTC in database.
- organization timezone controls business-date presentation.
- occurred_at represents business event time.
- created_at represents record creation time.
- posted_at represents posting time.

### Money
DECIMAL(19,4), never float.

### Quantity
DECIMAL(19,6), never float.

### Currency
Store ISO currency code on financial documents/accounts where needed. Egypt MVP defaults to EGP.

### Soft deletion
Do not soft-delete posted transactions. Use reversal/return/adjustment. Master data may use deleted_at only where historical references require it; deactivation is preferred.

### Statuses
Use explicit string/enumeration values documented at application level. Avoid database ENUM when future extensibility is important.

## 3. Migration Order

Migrations must run in this dependency order.

### Wave 1 — Tenant / Identity
1. organizations
2. users
3. roles
4. permissions
5. role_permissions
6. user_roles
7. devices
8. sessions

### Wave 2 — Reference Data
9. governorates
10. centers
11. cities_areas
12. units
13. categories
14. payment_methods
15. financial_accounts

### Wave 3 — Catalog / Parties
16. products
17. product_packagings
18. product_barcodes
19. product_aliases
20. suppliers
21. supplier_products
22. customers
23. customer_addresses
24. customer_location_events

### Wave 4 — Locations
25. locations
26. warehouses
27. vehicles

### Wave 5 — Transaction Foundation
28. document_sequences
29. idempotency_keys
30. audit_logs
31. ledger_accounts
32. ledger_entries
33. stock_movements
34. stock_balances

### Wave 6 — Purchasing
35. purchase_invoices
36. purchase_invoice_items
37. purchase_returns
38. purchase_return_items

### Wave 7 — Sales
39. sales_invoices
40. sales_invoice_items
41. sales_returns
42. sales_return_items

### Wave 8 — Finance
43. payments
44. payment_allocations
45. expenses
46. expense_allocations where required

### Wave 9 — Distribution
47. trips
48. trip_customers
49. trip_loads
50. trip_load_items
51. customer_visits
52. trip_expenses
53. trip_settlements
54. trip_settlement_lines

### Wave 10 — Sync / External Effects
55. sync_operations
56. sync_conflicts
57. message_outbox
58. outbox_attempts

### Wave 11 — Rebuildable Projections
59. customer_balance_summaries
60. supplier_balance_summaries
61. dashboard_daily_summaries
62. search/read projections where required

## 4. Identity and Tenant Tables

### organizations
Core tenant.

Key fields:
- id
- name
- legal_name
- default_currency
- timezone
- country_code
- status
- settings_json
- created_at
- updated_at

Indexes:
- unique legal/business identifier when introduced
- status

### users
- id
- organization_id
- name
- email
- phone
- password_hash / authentication identity
- status
- last_login_at

Indexes:
- organization_id + email
- organization_id + phone
- organization_id + status

### roles / permissions / role_permissions / user_roles
RBAC foundation. Permission keys are stable strings, e.g. sales.post, inventory.adjust, payments.record.

### devices
Field device identity.

Fields:
- id
- organization_id
- user_id
- device_uuid
- name
- platform
- app_version
- status
- last_seen_at
- revoked_at
- registered_at

Unique:
- organization_id + device_uuid

## 5. Geography

### governorates
- id
- code
- name_ar
- name_en
- active

### centers
- id
- governorate_id
- code
- name_ar
- name_en
- active

### cities_areas
- id
- center_id
- code
- name_ar
- name_en
- active

Use normalized master data; customer addresses reference geography IDs rather than arbitrary repeated text.

## 6. Catalog and Packaging

### units
Examples: قطعة, كرتونة, متر, كيلو.

Fields:
- id
- organization_id or global reference flag
- code
- name_ar
- name_en
- precision
- active

### categories
Hierarchical catalog categories.

Fields:
- id
- organization_id
- parent_id nullable
- name_ar
- name_en
- code
- active

### products
Core SKU.

Fields:
- id
- organization_id
- category_id
- base_unit_id
- sku
- name_ar
- name_en
- description
- brand
- default_cost
- default_piece_price
- tax_code nullable
- active

Unique:
- organization_id + sku

### product_packagings
First-class carton/package conversion.

Fields:
- id
- organization_id
- product_id
- unit_id
- name_ar
- conversion_to_base
- barcode_primary nullable
- sale_price nullable
- purchase_price nullable
- is_default_sale_unit
- is_default_purchase_unit
- active
- effective_from
- effective_to nullable

For the common case:
unit = كرتونة, conversion_to_base = 50.

Constraints:
- conversion_to_base > 0
- only one active default sale unit per product
- only one active default purchase unit per product where required

### product_barcodes
Fields:
- id
- organization_id
- product_id
- packaging_id nullable
- barcode
- barcode_type
- active

Unique:
- organization_id + barcode

This permits piece and carton barcodes to resolve to the same product while identifying the packaging level.

### product_aliases
Search/import aliases:
- id
- organization_id
- product_id
- alias
- normalized_alias
- source
- active

Index:
- organization_id + normalized_alias

### supplier_products
Supplier-specific catalog mapping.

Fields:
- id
- organization_id
- supplier_id
- product_id
- supplier_sku
- supplier_name
- preferred_packaging_id
- last_cost
- last_purchase_at
- active

Unique:
- organization_id + supplier_id + product_id

## 7. Parties

### suppliers
- id
- organization_id
- code
- name
- phone
- address
- tax_identifier nullable
- credit_terms_days
- active

### customers
- id
- organization_id
- code
- name
- phone
- alternate_phone
- governorate_id
- center_id
- city_area_id
- address_text
- credit_limit
- payment_terms_days
- status
- notes

Indexes:
- organization_id + normalized name
- organization_id + phone
- organization_id + geography IDs

### customer_addresses
Supports multiple addresses.

Fields:
- id
- organization_id
- customer_id
- label
- governorate_id
- center_id
- city_area_id
- address_text
- latitude
- longitude
- location_accuracy
- is_primary
- verified_at
- active

### customer_location_events
Audit location capture/change:
- id
- organization_id
- customer_id
- address_id
- latitude
- longitude
- accuracy
- captured_by
- device_id
- source
- captured_at
- verification_status

## 8. Locations / Warehouses / Vehicles

### locations
Generalized stock location.

Fields:
- id
- organization_id
- code
- name
- type
- status

Types:
- warehouse
- vehicle
- other_stock_location

### warehouses
- id
- organization_id
- location_id
- code
- name
- address
- active

### vehicles
- id
- organization_id
- location_id
- code
- plate_number
- name
- vehicle_type
- assigned_user_id nullable
- active

Constraint:
Every active vehicle has exactly one inventory Location.

## 9. Document Sequences

### document_sequences
Tenant-scoped numbering.

Fields:
- id
- organization_id
- document_type
- prefix
- next_number
- padding
- reset_policy
- active

Document numbers are generated server-side and are not trusted from offline clients. Offline operations use operation UUIDs until server posting assigns the official document number.

## 10. Idempotency

### idempotency_keys
Fields:
- id
- organization_id
- operation_type
- idempotency_key
- request_fingerprint
- status
- response_reference
- created_at
- completed_at

Unique:
organization_id + operation_type + idempotency_key

A different fingerprint with an existing key is rejected.

## 11. Inventory Ledger

### stock_movements
Authoritative inventory ledger.

Fields:
- id
- organization_id
- transaction_uuid
- product_id
- location_id
- movement_type
- quantity_base
- unit_cost
- source_document_type
- source_document_id
- occurred_at
- posted_at
- created_by
- device_id
- trip_id nullable
- reference

Quantity is always in product base unit.

Movement types include:
- purchase_receipt
- sale
- sales_return
- purchase_return
- transfer_in
- transfer_out
- stock_adjustment
- stocktake_adjustment
- vehicle_load
- vehicle_unload

Indexes:
- organization_id + product_id + location_id + occurred_at
- organization_id + source_document_type + source_document_id
- organization_id + transaction_uuid

### stock_balances
Rebuildable projection.

Key:
- organization_id
- product_id
- location_id
- quantity_base
- reserved_quantity_base
- updated_at

Unique:
organization_id + product_id + location_id

Stock availability is derived from authoritative movements/projection; it is never mutated independently.

## 12. Purchasing

### purchase_invoices
Header:
- id
- organization_id
- supplier_id
- location_id
- document_number
- supplier_invoice_number nullable
- status
- invoice_date
- posted_at
- subtotal
- discount
- tax
- total
- paid_amount
- currency
- notes
- created_by
- device_id
- idempotency_key

### purchase_invoice_items
- id
- organization_id
- purchase_invoice_id
- product_id
- packaging_id nullable
- entered_unit_id
- entered_quantity
- conversion_factor_snapshot
- quantity_base
- unit_cost_entered
- unit_cost_base
- discount
- tax
- line_total
- product_name_snapshot
- sku_snapshot

The conversion factor and product/unit labels are historical snapshots.

### purchase_returns / purchase_return_items
Same pattern, linked to original purchase line where possible.

Return quantity cannot exceed the eligible return quantity.

## 13. Sales

### sales_invoices
Header:
- id
- organization_id
- customer_id
- source_location_id
- trip_id nullable
- document_number
- status
- invoice_date
- posted_at
- subtotal
- discount
- tax
- total
- paid_amount
- balance_due
- currency
- notes
- created_by
- device_id
- idempotency_key

### sales_invoice_items
- id
- organization_id
- sales_invoice_id
- product_id
- packaging_id nullable
- entered_unit_id
- entered_quantity
- conversion_factor_snapshot
- quantity_base
- unit_price_entered
- unit_price_base
- discount
- tax
- line_total
- product_name_snapshot
- sku_snapshot

A mixed sale can be represented as separate lines when needed:
- 3 cartons
- 4 pieces

or as a structured quantity input normalized to 154 base pieces, while preserving the commercial entry snapshot.

### sales_returns / sales_return_items
Must reference original invoice/line where possible and enforce returnable quantity.

## 14. Finance

### payment_methods
Examples:
- cash
- bank_transfer
- postal
- cheque
- other

### financial_accounts
Examples:
- main cash
- bank account
- postal account

Fields:
- id
- organization_id
- code
- name
- type
- currency
- opening_balance
- active

### payments
First-class money transaction.

Fields:
- id
- organization_id
- party_type
- party_id
- financial_account_id
- payment_method_id
- direction
- amount
- currency
- payment_date
- reference
- status
- trip_id nullable
- created_by
- device_id
- idempotency_key

### payment_allocations
- id
- organization_id
- payment_id
- document_type
- document_id
- amount

Constraints:
- total allocations <= payment amount
- allocation cannot exceed outstanding document balance
- tenant consistency enforced

### expenses
- id
- organization_id
- category
- amount
- financial_account_id
- expense_date
- trip_id nullable
- vehicle_id nullable
- notes
- status
- created_by

## 15. Ledger

### ledger_accounts
Internal financial accounts for customer/supplier/cash/revenue/COGS/etc.

### ledger_entries
Authoritative financial effects.

Fields:
- id
- organization_id
- transaction_uuid
- account_id
- party_type nullable
- party_id nullable
- debit
- credit
- currency
- source_document_type
- source_document_id
- occurred_at
- posted_at

Invariant:
sum of debits and credits for every balanced posting must reconcile according to the accounting model adopted for the tenant.

Customer and supplier balance projections are derived from posted transaction effects.

## 16. Distribution

### trips
- id
- organization_id
- trip_number
- vehicle_id
- rep_user_id
- status
- trip_date
- started_at
- ended_at
- origin_location_id
- notes

### trip_customers
- id
- organization_id
- trip_id
- customer_id
- sequence
- planned
- visit_status

Unique:
trip_id + customer_id

### trip_loads
Load transaction header:
- id
- organization_id
- trip_id
- from_location_id
- to_vehicle_id
- status
- loaded_at
- created_by
- idempotency_key

### trip_load_items
- id
- organization_id
- trip_load_id
- product_id
- quantity_base
- source_stock_movement_id

Vehicle load is a real stock transfer and must create stock movements.

### customer_visits
- id
- organization_id
- trip_id
- customer_id
- user_id
- status
- check_in_at
- check_out_at
- latitude
- longitude
- notes

### trip_expenses
- id
- organization_id
- trip_id
- expense_id

### trip_settlements
- id
- organization_id
- trip_id
- status
- opening_stock_reference
- closing_stock_reference
- opening_cash
- expected_cash
- actual_cash
- stock_variance_value
- cash_variance
- settled_by
- settled_at

### trip_settlement_lines
Detailed product-level reconciliation:
- product_id
- opening_quantity_base
- loaded_quantity_base
- sold_quantity_base
- returned_quantity_base
- transferred_quantity_base
- adjustment_quantity_base
- expected_closing_quantity_base
- actual_closing_quantity_base
- variance_quantity_base

## 17. Sync

### sync_operations
Fields:
- id
- organization_id
- operation_uuid
- device_id
- user_id
- operation_type
- schema_version
- idempotency_key
- payload_hash
- payload_reference
- client_created_at
- received_at
- status
- server_transaction_uuid
- rejection_code
- rejection_details
- processed_at

Unique:
organization_id + operation_uuid
organization_id + operation_type + idempotency_key

### sync_conflicts
- id
- organization_id
- sync_operation_id
- conflict_type
- entity_type
- entity_id
- server_state_reference
- client_state_reference
- status
- resolution
- resolved_by
- resolved_at

Financial conflicts are explicit; no silent overwrite.

## 18. Communication / Outbox

### message_outbox
Durable external side-effect queue.

Fields:
- id
- organization_id
- event_type
- aggregate_type
- aggregate_id
- channel
- destination
- payload_json
- status
- available_at
- attempt_count
- last_error
- sent_at

### outbox_attempts
- id
- organization_id
- outbox_id
- attempt_number
- started_at
- finished_at
- status
- provider_reference
- error

## 19. Audit

### audit_logs
Fields:
- id
- organization_id
- actor_user_id
- device_id
- action
- entity_type
- entity_id
- correlation_id
- before_summary
- after_summary
- metadata_json
- ip_address
- user_agent
- created_at

Never store secrets or sensitive credentials in audit payloads.

## 20. Projection Tables

### customer_balance_summaries
Rebuildable:
- organization_id
- customer_id
- total_sales
- total_returns
- total_paid
- outstanding
- last_sale_at
- last_payment_at
- updated_at

### supplier_balance_summaries
Equivalent supplier-side projection.

### dashboard_daily_summaries
Optional performance projection for date-based dashboards. It must be rebuildable from authoritative records.

## 21. Critical Constraints

1. Every posted sale creates stock and financial effects atomically.
2. Every posted purchase creates stock receipt and supplier payable effects.
3. No stock balance changes without stock movements.
4. No posted transaction is hard deleted.
5. No retryable mutation can create a duplicate posting.
6. Cross-tenant references are rejected.
7. Vehicle inventory is represented through the Location model.
8. Vehicle stock cannot go negative under MVP policy.
9. Payment allocations cannot exceed payment/document limits.
10. Return quantities cannot exceed returnable quantities.
11. Packaging conversion must be positive.
12. Historical transaction lines retain packaging/conversion snapshots.
13. Projection tables are never the only source of truth.
14. Trip settlement cannot rewrite historical sales or payments.
15. External side effects are outbox-driven.

## 22. Required Index Families

Every large transactional table should have:
- tenant + business date
- tenant + status
- tenant + foreign key
- tenant + document number
- tenant + source document
- tenant + created_at where operational feeds need it

High-volume special indexes:
- stock: tenant + location + product
- product search: tenant + normalized SKU/name/alias
- barcode: tenant + barcode
- customer search: tenant + normalized name/phone
- sync: tenant + device + status
- outbox: tenant + status + available_at
- ledger: tenant + account + occurred_at
- trip customers: tenant + trip + sequence

Index design must be validated with EXPLAIN against realistic datasets before production.

## 23. Referential Integrity Rules

- Core financial/inventory foreign keys use RESTRICT/NO ACTION semantics for posted data.
- Do not cascade-delete documents into their ledger effects.
- Draft records may be deleted only before posting and only under application policy.
- Tenant ownership is validated at service/transaction level in addition to FK constraints.
- Historical snapshots avoid accidental dependency on mutable master data.

## 24. Seed / Reference Data

TASK-002 implementation must seed:
- Egypt governorates
- centers/cities/areas source dataset
- standard units
- default payment methods
- baseline permission keys
- document types

Seed scripts must be repeatable and environment-safe.

## 25. Migration Safety

- Migrations are forward-only.
- Large production tables require online/low-lock strategy where appropriate.
- Backward-compatible schema changes precede code changes during rolling deployment.
- Destructive changes require a separate migration after application compatibility is removed.
- Every migration has a rollback strategy where technically safe; irreversible data migrations require explicit backup/recovery procedure.

## 26. TASK-002 Implementation Gate

The next engineering task may use this document as the database contract.

TASK-003 should implement the Laravel database migrations in the exact dependency order above and add schema-level tests for:
- tenant isolation
- unique SKU/barcode
- packaging conversion
- stock movement integrity
- payment allocation limits
- return limits
- idempotency uniqueness
- vehicle/location relationship
- sync operation uniqueness

No production UI feature is required for TASK-002.
