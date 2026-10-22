# NEXORA Distribution OS — Open-source Reference Review

**Reviewed:** 2026-10-13  
**Purpose:** Identify proven design patterns for inventory, accounting, distribution and offline operations without replacing NEXORA's existing stack or copying external code.

## References reviewed

1. **ERPNext** — https://github.com/frappe/erpnext  
   Public repository README describes integrated accounting, stock/order management, replenishment, customers, suppliers, shipments and delivery. Relevant lesson: NEXORA's dashboard and reports must derive from the same posted transaction records and reconcile inventory with financial outcomes. Do not build a separate mock-data path for reports.

2. **InvenTree** — https://github.com/inventree/InvenTree  
   Public repository describes an inventory system with a backend, REST API, extensibility/plugins and a companion mobile app. The stock model's tracking-history pattern explicitly retains tracking evidence when a tracked item is deleted, while allowing the direct item reference to become null. Relevant lesson: define deletion/retention policy by record type; preserve audit/history where operationally meaningful and test each policy. This is a reference pattern, not an instruction to copy InvenTree's schema or stack.

3. **POS Canvas offline architecture** — https://github.com/aasishjannuaj/pos-canvas/blob/main/docs/OFFLINE_ARCHITECTURE.md  
   The published design describes durable local sale identity, server-authoritative pricing, eligibility checks before offline checkout, a persisted queue, bounded retries, explicit unknown outcomes and operator-visible rejected-sale handling. Relevant lesson: an offline financial operation needs a stable identity across retries, durable local state, clear outcome states, and no optimistic success before local persistence. NEXORA must adapt these ideas to its own transaction and stock policy; it must not adopt another project's stock-shortfall policy without owner approval.

4. **POS sync/outbox reference** — https://github.com/dthompsonfl/pos/blob/main/SYNC.md  
   The public sync document describes writing business state and an outbox event in one local transaction, stable idempotency keys, FIFO draining, retry backoff, and explicit server conflict/rejection responses. Relevant lesson: never allow a committed local business operation to be lost because its sync event failed to persist; expose conflicts instead of silently overwriting financial state.

5. **Odoo** — https://github.com/odoo/odoo  
   Used as a broad ERP reference only. NEXORA's present requirements and existing Laravel/Next.js architecture do not justify replacing the stack or porting Odoo modules.

6. **Laravel Warehouse Management** — https://github.com/farhanrahmananik/warehouse-management-laravel  
   The public project describes thin controllers, service-layer stock/purchase/report workflows, permission-protected exports, audit events, and SQLite-isolated feature tests. Relevant lesson: keep business mutations in services, treat exports as protected endpoints, and make test DB isolation explicit. Its published 249-test count is the repository author's stated baseline, not independently reproduced here.

7. **Laravel inventory package** — https://github.com/huuhabn/laravel-warehouse-management-system  
   The public description emphasizes warehouse-scoped stock, reservations, database row locks, append-only movement history, two-phase transfers and explicit adjustment reasons. Relevant lesson: use transaction + row-locking for contention-sensitive inventory, preserve an auditable movement trail, and make transfer completion a deliberate state transition. Any Redis lock would be an architectural dependency decision and is not being added to NEXORA without measured need.

8. **Multi-tenant inventory API** — https://github.com/Jezreeldv/multi-tenant-inventory  
   The repository describes tenant isolation across suppliers, products, stock, movements and sales, with transaction logging and rate limiting. Relevant lesson: test tenant boundaries across the full resource graph, not only the product list; NEXORA's matrix records endpoint-level gaps explicitly.

9. **Multi-tenant ERP** — https://github.com/benjdiasaad/ERP  
   The public README describes company-scoped models, granular RBAC, document numbering, audit trails, token/session controls and tests. Relevant lesson: scope identity and role relationships by tenant and keep document-numbering and authorization behavior covered by regression tests. We should not blindly copy a global-scope trait; explicit query/service checks and database constraints remain necessary for NEXORA's current schema.

10. **Offline POS sync design** — https://github.com/zackedus/POS/blob/main/docs/algorithm/OFFLINE-SYNC.md  
    The design distinguishes idempotent transaction replay from inventory reconciliation: transaction retries must not duplicate financial records, server stock remains authoritative, and stale offline inventory must not overwrite master data. Relevant lesson: keep stock conflict policy explicit and operator-visible; do not use last-write-wins for money or stock.

## Applied decisions for NEXORA

- Keep the current Laravel 13 + Next.js 16 + React 19 + TypeScript + MySQL 8.4/PWA architecture; no framework replacement is justified by this review.
- Treat `TransactionPostingService` and posted source documents as the authoritative transaction path. Dashboards and reports must be derived/reconciled against posted transactions.
- Preserve append-only financial/stock history; corrections should be reversals, returns or explicit adjustments, not edits/deletes to posted history.
- Use stable idempotency keys across ambiguous network outcomes. A request key may only be reused for the exact same payload; changed payloads require a new key.
- For offline writes, persist the operation and outbox atomically, keep one operation identity through retry, and represent `pending`, `accepted`, `rejected` and `conflict` explicitly.
- Make tenant boundaries and deletion policies explicit at both database and API/service layers where the data model allows it; document exceptions for shared reference and polymorphic data.
- Add vehicle-as-mobile-warehouse and trip settlement only as auditable flows tied to stock movements, payment/expense postings and ledger records.
- Do not add forecasting/AI, messaging integrations, external tax integrations or new runtime dependencies before transaction correctness, authorization, sync safety and operational gates pass.
- The review is pattern research, not a benchmark or a license/security audit. Repository feature descriptions are treated as claims from their own READMEs until independently tested.

## What this review does not prove

- It does not prove that any reference project's implementation is suitable for direct reuse or license-compatible integration.
- It does not replace official Egyptian Tax Authority requirements or owner-approved accounting/tax decisions.
- It does not prove NEXORA's current code is production-ready; all current-working-tree gates remain required.

## Next engineering use

Use these references when implementing NDX-040 (data retention/integrity), NDX-060 (financial/stock invariants), NDX-080/090 (offline sync/conflicts), and NDX-120 (reconciled reporting). Record a focused ADR if adopting a new architectural pattern or dependency.
