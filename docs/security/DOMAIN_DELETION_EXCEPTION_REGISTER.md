# Domain & Deletion-Policy Exception Register

**Reviewed:** 2026-10-13  
**Status:** Draft for owner review — no business policy is approved by this document.  
**Scope:** Schema-level relationships that currently permit `SET NULL`, use polymorphic references without a conventional foreign key, or intentionally support shared/global reference records.

## Decision rules

- Do not hard-delete posted financial or inventory documents to solve a relationship conflict. Prefer reversals, returns, archival, or explicit adjustment workflows.
- Keep immutable line snapshots (product label/SKU, entered unit, conversion factor and transaction values) even if a catalog item is later renamed or deactivated.
- API/service tenant checks are mandatory where a composite tenant FK is not possible; this register does not treat application checks as equivalent to a database constraint.
- Do not change deletion behavior until the owner approves the domain rule and a regression test proves the selected behavior.

## Exceptions requiring explicit decisions

| Area / current schema pattern | Risk / impact | Safe interim rule | Owner decision required | Status |
|---|---|---|---|---|
| Posted purchase/sales return `original_*_invoice_id` and `original_*_invoice_item_id` use nullable `SET NULL` | Deleting a source document/item can sever traceability for a posted return and complicate reconciliation | Do not expose hard-delete for posted invoices/items; keep source-linked return validation | Should source documents be permanently restricted from deletion once referenced, including drafts/voided documents? | NEEDS OWNER DECISION |
| Transaction item `packaging_id` uses nullable `SET NULL`; conversion and commercial snapshots remain on the line | Historical record can lose the live packaging relation while its stored conversion snapshot survives | Deactivate/retire packaging instead of deleting it; never recalculate posted quantities from current packaging | Is hard deletion allowed for never-used packaging only, or should all referenced packaging be retained? | NEEDS OWNER DECISION |
| `created_by`, `device_id`, and conflict `resolved_by` use nullable `SET NULL` | Actor/device linkage can disappear from historical documents after identity/device deletion | Deactivate users/devices; do not hard-delete identities used in posted operations | Is nullable linkage sufficient, or do audit snapshots of actor/device identity need retention? | NEEDS OWNER DECISION |
| `trip_id` may be nullable with `SET NULL` on stock movements, sales invoices/returns, payments and expenses; trip expenses also have nullable vehicle link | Deleting a trip can erase the direct operational association needed to explain settlement and field activity | Do not hard-delete trips that have posted transactions; close/cancel them through state transitions | Should any referenced trip/vehicle be deletable, and what history must remain queryable? | NEEDS OWNER DECISION |
| Polymorphic payment party (`party_type`/`party_id`) and allocation document (`document_type`/`document_id`) have no conventional FK | Invalid or cross-tenant IDs cannot be ruled out by a standard FK alone | Validate type allowlists, tenant ownership, document state, and allocation limits in posting service; preserve source references | Which party/document types are officially supported and what happens when a referenced party/document is archived? | SERVICE-GUARDED; POLICY OPEN |
| Polymorphic ledger and stock source references (`party_type`, `party_id`, `source_document_type`, `source_document_id`) | Audit trail may become ambiguous if source rows are removed or type labels drift | Treat posted ledger entries and stock movements as append-only; correct by reversal/return/adjustment | Is there any approved retention/archival period, and how are old source records represented? | SERVICE/DATA REVIEW REQUIRED |
| Sync conflict entity references and outbox aggregate references (`entity_type`/`entity_id`, `aggregate_type`/`aggregate_id`) | Async records may outlive or point at a missing business entity | Retain sync operation/outbox audit records; handlers must validate entity type and tenant before applying side effects | Define purge/retention policy and behavior for events whose aggregate has been archived | NEEDS OWNER DECISION |
| Global/shared reference data, including `payment_methods.organization_id IS NULL`; units can be global or tenant-owned | A blanket tenant FK would reject valid global references; an overly broad exception could expose tenant-owned rows | Allow only explicitly shared records or records owned by current tenant; test both positive and negative paths | Which reference catalogs are globally shared versus tenant-owned, and who may edit shared rows? | SHARED PAYMENT METHOD RULE IMPLEMENTED; CATALOG POLICY OPEN |

## Evidence and limits

- `2026_10_08_000006_create_purchasing_sales_finance.php` defines nullable source, packaging, actor/device, trip, and polymorphic finance relationships.
- `2026_10_08_000007_create_distribution_sync_and_projections.php` defines trip lifecycle, sync conflicts, outbox and projection relationships.
- `2026_10_08_000010_add_tenant_scoped_foreign_keys.php` adds composite tenant constraints for supported concrete relationships on MySQL; polymorphic references and nullable `SET NULL` cases are not all covered by that map.
- `docs/security/TENANT_RBAC_MATRIX.md` records API-level tenant tests. Three composite tenant-FK tests are intentionally skipped on SQLite and still require current-tree MySQL 8.4 CI evidence.

## Exit criteria

1. Owner decisions are recorded for each `NEEDS OWNER DECISION` row before changing schema deletion semantics.
2. Every accepted rule has migration/rollback review where applicable and tests for both permitted and rejected operations.
3. Posted source records, stock movements, ledger entries, return links and sync/outbox audit history remain reconcilable after archival/deactivation.
4. Current-tree MySQL 8.4 lifecycle and composite-FK tests pass before Batch 2/TASK-004 is approved.
