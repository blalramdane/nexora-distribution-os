# Contribution Rules

Before coding, read:
- docs/architecture/MASTER_BLUEPRINT_v1.md
- docs/architecture/DOMAIN_MODEL_ERD_v1.md
- docs/architecture/TRANSACTION_ENGINE_v1.md
- docs/architecture/UX_SCREEN_ARCHITECTURE_v1.md

Rules:
- Never bypass the Transaction Engine for posted inventory/financial operations.
- Never mutate stock balances without stock movements.
- Never hard-delete posted transactions.
- Retryable mutations must be idempotent.
- Server is authoritative after sync.
- Preserve domain contracts.
- Avoid unnecessary dependencies.
- Test critical flows before calling them complete.

Commit examples:
- feat(inventory): add stock movement posting
- feat(sales): implement cash sale transaction
- fix(sync): prevent duplicate payment posting
- test(trips): cover settlement variance
- docs(architecture): define sync protocol
## Mandatory Change Guard

Before every non-trivial change, follow `docs/engineering/NEXORA_PROJECT_CHANGE_GUARD.md`. It is the persistent project-safety checklist for context review, dependency impact, regression analysis, tests, CI, and verification.

For cross-cutting changes, review the full affected subsystem rather than only the file being edited. Never treat a passing build alone as proof that the architecture remains intact.
