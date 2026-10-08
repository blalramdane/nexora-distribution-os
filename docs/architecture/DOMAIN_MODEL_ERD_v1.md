# NEXORA Distribution OS — Domain Model & ERD v1

## Tenant boundary
Every business-owned record is scoped by organization_id. Server-side authorization enforces tenant isolation.

## Core domains
Identity, Catalog, Geography, Parties, Locations, Purchasing, Sales, Finance, Inventory, Trips, Visits, Communication, Sync, Audit.

## Main entities
- organizations
- users / roles / permissions / devices
- categories / units / products / product_barcodes / product_aliases
- supplier_products
- governorates / centers / cities_areas
- customers / customer_addresses / customer_location_events
- suppliers
- locations / warehouses / vehicles
- purchase_invoices / purchase_invoice_items
- purchase_returns / purchase_return_items
- sales_invoices / sales_invoice_items
- sales_returns / sales_return_items
- payment_methods / financial_accounts / payments / payment_allocations
- stock_movements / stock_balances
- stock_transfers / stock_transfer_items
- stocktakes / stocktake_items
- trips / trip_customers / trip_loads / trip_load_items
- customer_visits / trip_expenses / trip_settlements
- sync_operations / sync_conflicts
- message_outbox / audit_logs

## Location model
Warehouse and Vehicle both reference a generalized Location. This lets the same inventory engine handle:
Warehouse → Vehicle → Warehouse and transfers between locations.

## Inventory
stock_movements is the authoritative append-oriented ledger. stock_balances is a rebuildable projection for speed.

## Customer ledger
Sales Invoices - Sales Returns - Payments + Adjustments.

## Supplier ledger
Purchase Invoices - Purchase Returns - Payments + Adjustments.

## Trip
Trip links vehicle, rep/driver, planned customers, route, load, sales, collections, returns, expenses and settlement.

## Critical constraints
- No posted transaction without required ledger effects.
- No negative sellable vehicle stock in MVP.
- Posted transactions are immutable.
- Payments cannot allocate above payment amount.
- Returns are bounded by returnable quantity when linked.
- Every retryable mutation is idempotent.
- Cross-organization references are forbidden.
- Sensitive corrections are audited.
