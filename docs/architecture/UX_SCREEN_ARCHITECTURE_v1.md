# NEXORA Distribution OS — UX & Screen Architecture v1

## UX North Star
Minimum Input → Maximum Result.

The Admin Web optimizes for control/analysis. The Field PWA optimizes for sell/collect/visit/sync.

## Admin navigation
Dashboard
Sales
Purchases
Inventory
Customers
Suppliers
Distribution
Reports
Settings

Quick actions:
+ Sale, + Purchase, + Customer, + Collection, + Return, + Transfer, + Stocktake, + Trip.

## Global search
Ctrl+K searches customers, suppliers, products, invoices, trips and vehicles by name, phone, SKU, alias or document number.

## Fast Sale
Customer → Product search/scan → Quantity → Price → Payment → Post.
Keep product entry open so high-volume sales do not require repeated modal navigation.

## Purchase
Excel-like grid, paste/import, supplier aliases, last cost, supplier-product mapping, barcode, smart defaults and review only unknown products.

## Customer 360
Total Sales, Paid, Outstanding, Returns, Last Purchase, Last Payment, Products, Visits, Statement, Location.
Primary actions: Sale, Collect, Return, Send Statement, Navigate.

## Vehicle
Current trip, rep/driver, stock, sales, collections, returns, expenses and history.

## Trip
Create: date → vehicle → rep/driver → customers → load → start.
During trip: visits, route, sales, collections, returns and expenses.
Close: stock + cash reconciliation → settlement.

## Field PWA
Home, Trip, Customers, Sale, Collection, Return, Add Customer, Sync, More.
The field user sees only the assigned trip, vehicle stock, relevant customers/products and required master data.

## Offline UX
Always show Online / Unstable / Offline / Syncing / Conflict state.
Example: “تم حفظ العملية على الجهاز — في انتظار المزامنة”.
Technical errors must never be exposed directly.

## Data-entry benchmarks
Targets to validate through user testing:
- New customer: ≤20 seconds.
- Simple sale: ≤30 seconds.
- Collection: ≤10 seconds.
- 30-item purchase without opening a product form for every row.
- Vehicle load as a bulk operation.

## Design system
Modern, professional, Arabic RTL, mobile-first, clear and fast. Clarity > decoration. Shared components must behave consistently.


## Packaging-Aware Product UX

Product cards, inventory, purchase and sales grids must make packaging obvious.

For products with packaging:
- Show **Carton / Piece** as selectable units.
- Display the conversion, e.g. `1 كرتونة = 50 قطعة`.
- Allow quick mixed entry such as `2 كرتونة + 7 قطعة`.
- Show available stock in both commercial and base units, e.g. `120 كرتونة + 35 قطعة`.
- Search must match product name, SKU, carton barcode and piece barcode.
- Price entry supports unit-specific selling prices where configured.
- The invoice line must visibly retain the selected unit and conversion.

Fast-sale UX should never force the user to manually calculate pieces inside the carton. The system performs the conversion and shows the resulting base quantity before posting.

Purchase grid must support supplier invoices entered by carton or piece without opening a separate product form for each row.
