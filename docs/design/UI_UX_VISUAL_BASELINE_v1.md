# NEXORA Distribution OS — UI/UX Visual Baseline v1

## Status
APPROVED — LOCKED VISUAL BASELINE

This document is the visual contract for all future NEXORA Distribution OS screens. New screens must feel like the same product and must not introduce an unrelated visual language.

## 1. Product Feel
NEXORA Distribution OS is a professional B2B operating system for electrical tools and home-appliance distribution.
Target: Premium, Professional, Fast, Operational, Trustworthy, Modern SaaS, and information-rich without feeling crowded.
Avoid generic ERP appearance, decorative dashboards, excessive gradients, excessive rounding, consumer-app styling, large wasted spaces, and unnecessary modal chains.

## 2. Visual Direction
- Desktop Admin Web for management and warehouse operations.
- Mobile-first Field PWA for sales representatives and drivers.
- Arabic RTL is primary; English is secondary localization.
- Deep navy navigation/sidebar.
- Clean white/light-gray canvas.
- Primary blue actions.
- Green success/availability.
- Amber warning.
- Red error/critical.
- Neutral slate/gray typography.
- Soft borders, restrained shadows, medium rounded corners.
- Compact, information-rich cards.

Exact color tokens are centralized implementation variables.

## 3. Typography
Use a highly readable modern Arabic sans-serif. Page titles are strong and compact; section titles medium/semibold; body/table text highly readable; metrics bold; metadata smaller and muted. No decorative Arabic fonts.

## 4. Navigation
Desktop sidebar contains NEXORA logo/product name, core modules, clear blue active state, consistent icons, and user/account area.
Core modules: الرئيسية, المبيعات, العملاء, المشتريات, المخزون, المستودعات, السيارات والتوزيع, الموردين, التحصيلات, المرتجعات, المصروفات, الحسابات, التقارير, الموظفين, الإعدادات.

## 5. Dashboard
The dashboard is an operational command center, not a decorative analytics page.
Typical KPIs: إجمالي المبيعات, إجمالي التحصيلات, الرحلات النشطة, العملاء النشطين, عدد المنتجات, عدد الكراتين, عدد القطع, السيارات النشطة.
Typical modules: Sales vs Collections, Top Products, Stock Status, Today's Vehicles/Trips, Distribution Map, Recent Invoices, Alerts, Warehouse/Vehicle Stock, Customer Performance.

## 6. Product and Packaging
Packaging is first-class UI.
Products can expose: image, name, SKU, piece barcode, carton barcode, base unit, carton/package unit, pieces per carton, available cartons, available pieces, total base quantity, piece price, carton price when configured, last cost, supplier.
Example: لمبة LED 9 وات — 1 كرتونة = 50 قطعة — 120 كرتونة — 5,820 قطعة — piece price — carton price.

## 7. Sales Entry
Fast Sale remains on one screen: Customer → Product search/scan → Unit → Quantity → Price → Payment → Post.
Packaging-aware entry supports Piece, Carton, and Mixed quantity. Example: 3 كرتونة + 4 قطع = 154 قطعة. The salesperson never calculates base quantity manually.

## 8. Inventory
Inventory tables show commercial and normalized quantities together: Product, SKU, Available cartons, Available pieces, Total pieces, Reserved, On vehicles, Main warehouse, Status.
Filters include warehouse, vehicle, category, supplier, stock status, and packaging unit.

## 9. Vehicle and Distribution
Vehicle cards show Vehicle, Driver/rep, Trip, Route, Load, Sold, Collected, Returns, Remaining stock, Progress, and Sync state. Maps are operational, not decorative.

## 10. Customer 360
Show identity, phone/location, credit limit, balance, total sales, collections, returns, last sale, last payment, products purchased, visits, statement, and navigation.
Primary actions: Sale / Collection / Return / Statement / Navigate.

## 11. Tables
Dense but readable. Strong hierarchy, sticky headers where useful, compact rows, status badges, inline safe actions, search/filter bar, bulk selection, and appropriate pagination.

## 12. Forms
Minimize clicks with smart defaults, autocomplete, keyboard navigation, Tab/Enter support, barcode scanning, inline validation, and draft preservation where appropriate.

## 13. Mobile Field UI
Same visual language, lower density, thumb-friendly and one-handed. Primary actions: Start Visit, New Sale, Collection, Return, Add Customer, Navigate.
Offline states are explicit: Online, Unstable, Offline, Syncing, Conflict. Offline data is never shown as confirmed server truth.

## 14. Status Language
Use consistent semantic states: متاح, منخفض, نفد المخزون, مكتملة, قيد التنفيذ, معلقة, فشلت, في انتظار المزامنة, متعارضة, غير متصل.

## 15. Core Components
AppShell, Sidebar, Topbar, GlobalSearch, CommandPalette, KPI Card, DataTable, SearchSelect, ProductCard, PackagingBadge, StockBadge, StatusBadge, MoneyDisplay, QuantityDisplay, InvoiceSummary, CustomerSummary, VehicleCard, TripProgress, MapPanel, ActivityFeed, EmptyState, ErrorState, OfflineBanner, SyncStatus, ConfirmAction.

## 16. Visual Quality Gate
Reject a screen if it looks like a different product, has inconsistent spacing/typography, unclear actions, hidden packaging information, unnecessary clicks, impractical mobile operation, ambiguous offline/status state, or business-critical information buried under decoration.

## 17. Reference
The approved visual reference is the NEXORA Distribution OS dashboard and operational screens generated during product design. Future implementation must reproduce the same layout philosophy, density, color semantics, card/table language, navigation hierarchy, packaging visibility, Arabic RTL direction, and professional B2B SaaS character.

This document is the source of truth for visual consistency.