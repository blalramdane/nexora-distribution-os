# NEXORA Distribution OS — Project Context

This file is the distilled NEXORA context relevant to this product.

## Business
NEXORA is building reusable vertical SaaS products for SMEs. Distribution OS starts in Egypt with electrical tools/home-appliance distribution.

## Strategic model
Use a reusable NEXORA Core with vertical modules. The product should support recurring revenue, onboarding, migration, integrations, AI/data services and managed support.

## Operational problem
Distributors manage purchases from many suppliers, warehouse stock, mobile vehicle stock, trips across multiple cities/governorates, sales to traders, credit/cash/mixed payments, returns, expenses and settlements.

## Non-negotiable workflows
- Same SKU can have multiple suppliers and historical costs.
- One trip can serve many customers in the same city or across multiple cities.
- Vehicle is a stock location.
- Field work must operate offline.
- Customer location can be captured by the field rep with permission and verified later.
- Invoices/statements should be exportable to PDF/Excel and shareable through WhatsApp.
- Data entry must minimize clicks/taps and training burden.
- Old-system migration comes later.

## Product differentiators
Mobile Warehouse, Trip Engine, Offline-first Field PWA, Customer 360, unified transaction/ledger model, simple maps, WhatsApp layer, migration onboarding, Smart Load and later intelligence.

## Architecture
Modular Monolith + Transaction Engine + Ledger Projections + Offline Sync.

## Expansion
The same NEXORA core can later support other distribution verticals such as FMCG, food, cosmetics, medical supplies and spare parts.
