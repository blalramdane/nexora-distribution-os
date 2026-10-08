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


## Product Packaging & Sales Units

A product may have multiple commercial/stock units. The canonical case for NEXORA Distribution OS is:

**Carton → Pieces**

For each product, packaging configuration may define:
- base stock unit (piece/unit)
- carton/package unit
- pieces per carton
- barcode(s) for carton and piece
- optional additional packaging levels later

Inventory is normalized to the base stock unit for authoritative stock movements and valuation, while the UI can display both cartons and pieces.

Example:
- 1 carton = 50 pieces
- warehouse stock = 120 cartons + 35 pieces
- normalized stock = 6,035 pieces

Sales may be entered as:
- full cartons
- individual pieces
- mixed carton + piece quantities

The system must convert commercial quantities to base-unit movements without losing the entered unit context. A sale of 2 cartons + 7 pieces for a 50-piece carton posts 107 pieces out of inventory.

Purchase documents may arrive by carton or piece and must preserve the source unit and conversion used at posting time. Returns must follow the same conversion rules and remain traceable to the original document where linked.

Costing must remain base-unit accurate; a carton cost is converted to piece cost using the documented packaging conversion, not by rounding UI values.

Packaging configuration is master data and changing it must not rewrite historical transactions; historical lines retain their unit/conversion snapshot.
