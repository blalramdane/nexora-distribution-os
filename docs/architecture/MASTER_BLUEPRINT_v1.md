# NEXORA Distribution OS — Master Blueprint v1

## Product
Vertical SaaS / Operating System for field distribution businesses in Egypt, starting with electrical tools and home-appliance distribution.

## Core workflow
Supplier → Warehouse → Vehicle → Trip → Route → Customer → Sale → Collection → Return → Settlement

## Product principles
- Vehicle is a first-class Mobile Warehouse.
- Trip is a first-class business object.
- Inventory is ledger-based.
- Transactions are atomic, auditable and idempotent.
- Field operations are Offline-first.
- Minimal-click data entry is a core product requirement.
- Customer/Supplier balances are derived from posted transactions.
- WhatsApp is a communication layer, not the source of truth.
- AI comes after reliable transactional data.
- Legacy migration is a future adapter, never a development dependency.

## MVP modules
Core identity/permissions, catalog, customers, suppliers, geography, warehouses, vehicles, inventory ledger, purchases, purchase returns, sales, sales returns, payments, payment allocations, customer/supplier ledgers, vehicle loads, trips, settlement, field PWA, offline sync, basic maps, PDF documents, dashboard/reports, audit.

## P1
Official WhatsApp API, secure document links, advanced route planning, richer reports.

## P2
Smart Load, customer intelligence, route intelligence, forecasting, profit intelligence.

## Target roles
Owner, Admin, Accountant, Warehouse, Sales Manager, Driver/Sales Rep.

## Architecture direction
Modular Monolith + Transaction Engine + Ledger Projections + Offline Sync.

## NEXORA strategy
Build a reusable NEXORA Core and place Distribution-specific behavior in a vertical module. The goal is repeatable SaaS, not one-off source-code delivery.

## Quality gate
A feature is complete only after implementation, automated tests, build and verification of the critical workflow.
