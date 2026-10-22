# NEXORA Distribution OS — Execution Master Plan

> **Purpose:** This file is the single execution handoff for the owner, ChatGPT, and any coding agent working on this repository. Read this file and `PROJECT_STATUS.md` + `TASK_LOOP.md` before editing code.
>
> **Current baseline date:** 2026-10-13. Status is based on repository documentation; rerun relevant checks before claiming they pass on the current working tree.

## 1. Product objective

Ship a sellable, reliable vertical SaaS for Egyptian home-appliance/electrical-goods distributors. The core promise is accurate stock and money across warehouse → vehicle → trip → customer → sale/collection/return → settlement, including field work with unreliable connectivity.

**Do not sell or label the current Local Alpha as production-ready.** A controlled pilot may start only after the Pilot Gate below is explicitly approved.

## 2. Source of truth and agent protocol

Read in this order:
1. `EXECUTION_MASTER_PLAN.md` — phase order, completion definitions, schedule assumptions and handoff protocol.
2. `PROJECT_STATUS.md` — observed current state and active blockers.
3. `TASK_LOOP.md` — active task, technical details and release gates.
4. `docs/architecture/MASTER_BLUEPRINT_v1.md`, `docs/architecture/DOMAIN_MODEL_ERD_v1.md`, `docs/architecture/TRANSACTION_ENGINE_v1.md`, `docs/architecture/UX_SCREEN_ARCHITECTURE_v1.md`, and `docs/architecture/TECHNICAL_IMPLEMENTATION_ARCHITECTURE_v1.md` — architectural source of truth.

### Mandatory rules for every agent
- Start with `git status --short`, current branch, and recent commit. **Never overwrite, reset, clean, or discard pre-existing user changes.** Do not run destructive commands or alter production data.
- Confirm the active task in `PROJECT_STATUS.md` and `TASK_LOOP.md`. One task/phase at a time; do not silently skip a gate.
- Gather evidence before a fix: reproduce → inspect logs/code/tests → identify root cause → smallest safe change → test → verify.
- Do not invent business policy. Tax, costing, numbering, negative-stock, credit limits, and approval thresholds require explicit owner decisions and must remain configurable until decided.
- Do not add dependencies or replace frameworks without a documented technical reason and an ADR/review.
- No deployment, publication, paid service, live customer-data import, or production migration without explicit owner approval.
- Never place credentials, tokens, customer personal data, or secrets in this file, logs, commits, or test fixtures.
- After every phase, update this file's status, `PROJECT_STATUS.md`, and `TASK_LOOP.md` consistently. Keep only one active phase/task.
- A phase can be marked **DONE** only with code/docs evidence, test commands and results, known limitations, and an explicit gate decision. “Code written” alone is not completion.
- Commit only when explicitly requested/authorized by the owner; do not assume permission to commit or push.

## 3. Current baseline — not yet re-verified on this turn

Repository docs record: Laravel 13 / PHP 8.3+, Next.js 16 / React 19 / TypeScript, MySQL 8.4 target, local SQLite for isolated development, field PWA with IndexedDB/Dexie, and Playwright E2E.

The last documented CI baseline (2026-10-10, commit `29a7348`) reported 59 MySQL tests passed / 343 assertions, frontend lint/build passed, and 4 Playwright E2E passed. These are historical results, not proof that the current working tree passes.

Known open gates from `PROJECT_STATUS.md`:
- Domain/deletion-policy decisions for nullable `SET NULL`, shared reference data, and polymorphic finance references.
- Re-run MySQL 8.4 migration/seed/rollback/test lifecycle against the latest schema changes.
- Broaden tenant isolation and permission tests across remaining resources.
- Validate UI forms and API contracts, including loading/error/empty/validation/session-expiry states.
- E2E for trip expenses, UI-boundary idempotency/replay, and offline conflicts.
- Finish PWA install/update/conflict/device lifecycle checks.
- Owner-approved finance, costing, tax/invoice, numbering, credit and stock policies.
- Production security/configuration, secrets, backups + restore drill, observability, rate limits, load tests, and real-distributor validation.

### Current working-tree verification — 2026-10-13

- Branch/starting commit: `ci/mysql84-latest-gate` / `b8ae518`.
- Existing local changes were preserved; do not assume they are committed or owned by the next agent.
- Focused backend tests passed using the repository-pinned PHP 8.3.35 runtime: `EndToEndDistributionGateTest` — 1 passed / 60 assertions; `PermissionEnforcementTest` — 5 passed / 17 assertions.
- Frontend TypeScript lint passed: `npm run lint` in `frontend/`.
- A first attempt with the machine-default PHP 8.2.12 failed before boot because Composer requires PHP >=8.3; rerunning with `.tools/php83/php.exe -c .tools/php83/php.ini` passed both focused suites. Do not downgrade the project's PHP requirement.
- A new UI retry safeguard and browser regression scenario are in progress; full browser E2E/build is still required before calling the task DONE.
- GitHub reference review is recorded in `docs/research/OPEN_SOURCE_REFERENCE_REVIEW_2026-10-13.md`.

## 4. Delivery phases and target schedule

Estimates are working-day ranges, not promises. They assume one primary engineer/agent, prompt owner decisions, available CI, and no major architectural surprise. Calendar dates should be recalculated when Phase 0 starts and capacity is confirmed.

| Phase | Scope | Estimate | Exit gate | Status |
|---|---|---:|---|---|
| 0 — Safe handoff & baseline | Preserve working tree, inspect diff/branch, identify latest commits and rerun feasible baseline checks | 0.5–1 day | Current state and changed files documented; no user changes lost | DONE |
| 1 — Close TASK-004 / DB integrity | Decide and document deletion/reference/polymorphic relationship rules; add regression/invariant tests; run current MySQL 8.4 lifecycle and suite | 1–3 days | MySQL migration→seed twice→rollback→remigrate→seed and tests pass on current code; exceptions documented; review approval | BLOCKED — local current-tree MySQL/backend evidence passed; owner decisions remain open and no current-tree CI run was initiated without commit/push authorization |
| 2 — Tenant & authorization audit | Exercise every resource, list/detail/mutation/export/sync, roles, inactive users and revoked devices across two tenants | 1–3 days | Negative cross-tenant tests prove no read/write leakage; permission matrix approved | IN PROGRESS — NDX-050 focused route matrix done; broader phase review remains |
| 3 — Financial/distribution closure | Audit stock ledger, customer/supplier balances, payment allocation, returns, trip expenses, settlement, costing and concurrency | 2–4 days | Invariant and E2E tests reconcile stock/money; unresolved owner policies remain explicit blockers | IN PROGRESS — NDX-060 fail-closed guards verified; source-ledger and return policy review continues, plus trip-opening-stock/carry-over reconciliation |
| 4 — Offline/PWA reliability | Replay/idempotency at UI boundary, partial sync failures, conflicts, stale stock, revocation, update/install/logout/device lifecycle | 2–4 days | Browser tests prove no duplicate financial posting and no silent conflict merge; private cache lifecycle verified | IN PROGRESS — field account, role redirect and assigned-trip visit/transaction E2E now pass; broader device/cache/conflict lifecycle remains |
| 5 — UX & workflow completion | Audit each screen/button/form; Arabic RTL, validation, loading/error/empty/session states, keyboard/table/bulk entry and responsive field UX | 3–5 days | Screen-by-screen checklist passes; critical journeys covered by E2E; no redesign without evidence | IN PROGRESS — field workspace tabs, per-customer price entry, whole-number Arabic digit entry and review-only stocktake verified; Playwright 9/9; application-wide screen checklist remains |
| 6 — Operational readiness | Production config, secrets, rate limits, logging/correlation IDs, queue/outbox retries, backup/restore, dependency/security audit, load smoke tests | 3–5 days | Staging rehearsal and restore drill pass; no critical/high unresolved security issues | PENDING |
| 7 — Controlled distributor pilot | Isolated staging tenant, demo/import validation, opening-balance reconciliation, staff training, support/runbook, KPI baseline | 5–10 days elapsed including user feedback | Owner + pilot business approve reconciliation, workflow fit and rollback plan | PENDING |
| 8 — Commercial release decision | Pricing/package, onboarding, support, contract/privacy/retention, monitoring/incident plan; decide limited paid launch | 2–4 days | Explicit release checklist signed off; only then consider production deployment | PENDING |

**Planning estimate:** roughly 3–6 engineering weeks plus pilot feedback time, if scope stays controlled and owner decisions arrive promptly. This is not a guaranteed finish date. Re-estimate at the end of Phases 0, 1 and 3 using actual test/defect evidence. No live deployment date is committed yet.

## 5. Definition of DONE — required for every phase

Before setting a phase to `DONE`, record all of the following:
- [ ] Scope and files changed.
- [ ] Evidence / root cause for bug fixes, or acceptance criteria for new work.
- [ ] Tests executed (exact commands) and actual pass/fail counts.
- [ ] Relevant CI result/URL if applicable; do not invent one.
- [ ] Security/tenant/data-integrity impact reviewed.
- [ ] Migrations/rollback/data-preservation considerations reviewed if schema changed.
- [ ] UI/E2E impact reviewed if user-facing behavior changed.
- [ ] Known gaps and owner decisions listed.
- [ ] `PROJECT_STATUS.md` and `TASK_LOOP.md` updated to match this file.
- [ ] Phase gate explicitly recorded as `DONE`, `BLOCKED`, or `NEEDS OWNER DECISION`.

## 6. Phase 0 — exact first actions

1. Read the current `git status --short`, branch, and latest commit; save the output in the work report, not credentials or sensitive data.
2. Inspect the existing diff and untracked files without modifying them. Identify which changes predate this task and preserve them.
3. Compare `TASK_LOOP.md`, `PROJECT_STATUS.md`, this plan, and the latest migration/test evidence. If they conflict, record the conflict and prefer the latest verified code/CI evidence, without silently changing business policy.
4. Run only safe, project-scoped checks that do not target production or another project's database. Do not run schema rollback against any persistent user database; lifecycle tests must use disposable isolated test DBs/CI.
5. Update the phase status and report the evidence before moving to Phase 1.

## 7. Product differentiators — only after core correctness

1. Vehicle as a mobile warehouse and auditable trip settlement.
2. Carton/piece/mixed units with immutable conversion snapshots.
3. Offline field workflow with visible pending/accepted/rejected/conflict states.
4. Customer credit/collection intelligence and stock replenishment alerts based on verified ledgers.
5. Official Egyptian e-invoice/e-receipt integration only after current tax-authority requirements and approved integration method are verified.

Do not build AI forecasting or external messaging before the transaction data, authorization, outbox, and audit trail are reliable.

## 8. Agent handoff report template

Append a dated report to `docs/EXECUTION_LOG.md` (create it if absent) after each meaningful work session:

```text
Date/time:
Agent / model (if relevant):
Phase / task ID:
Starting commit + branch:
Pre-existing working-tree changes preserved:
Observed behavior / evidence:
Root cause or requirement:
Files changed:
Tests run + exact results:
Security / tenant / data-integrity review:
Status: DONE | BLOCKED | NEEDS OWNER DECISION
Remaining blockers:
Next single action:
```

The next agent must read the latest report and verify the working tree before continuing. Never infer that an item is done solely because an earlier agent said so; verify the evidence.

## 9. Current status update

- [x] Product goal and release gates captured.
- [x] Known gaps and historical baseline captured from project docs.
- [x] Agent handoff and phase completion rules defined.
- [x] Phase 0 read-only baseline inspection and focused backend/frontend checks logged; browser E2E/build still pending.
- [ ] Phase 1 TASK-004 closure and current MySQL gate.
- [ ] Pilot / production approval.

## 10. Traceable task backlog (single source of execution order)

Keep exactly one task ACTIVE. Do not mark a task DONE without its listed evidence. `TASK_LOOP.md` must mirror the active task ID and next action.

| ID | Priority | Task / acceptance criteria | Dependencies | Status |
|---|---|---|---|---|
| NDX-000 | P0 | Safe handoff: verify all modified/untracked files; preserve worktree; record branch/commit and test environment. | None | DONE — 2026-10-13 |
| NDX-010 | P0 | Expense replay safety: stable UI idempotency key across ambiguous/lost responses; same payload replays same expense; changed payload gets a new key; browser test proves one expense after a committed request whose response is lost. | NDX-000 | DONE — browser response-loss regression passed |
| NDX-020 | P0 | Run full isolated backend suite and frontend type/build/E2E against current working tree; fix regressions only with evidence. | NDX-010 | DONE — 57 backend passed / 363 assertions, 3 MySQL-only skips; frontend lint/build passed; 4 Playwright E2E passed |
| NDX-030 | P0 | Re-run MySQL 8.4.11 migration → seed twice → rollback → remigrate → seed → backend tests on current schema branch/CI; retain run URL and exact commit. | NDX-020 | LOCAL GATE PASSED 2026-10-18 — MySQL 8.4.11 lifecycle evidence; latest current-tree backend suite 120/1077, 0 skipped; current-tree GitHub CI still NOT RUN because commit/push is not authorized |
| NDX-040 | P0 | TASK-004 exception register: nullable `SET NULL`, shared/global reference tables, polymorphic finance refs; document each domain rule, tenant enforcement layer, deletion behavior, and negative test. No irreversible policy invented. | NDX-030 + owner decisions where required | PENDING |
| NDX-050 | P0 | Tenant/RBAC matrix across every list/detail/create/update/post/export/sync endpoint; two-tenant positive and negative tests; inactive user and revoked device coverage; verify no cross-tenant identifiers leak. | NDX-020 | DONE — includes regression denial for legacy field roles on generic cost-bearing catalogs; broader resource-by-resource phase review remains open |
| NDX-060 | P0 | Financial/stock invariant audit: ledger balance per source, no balance without movement, returns linked to source, account/cash effects, trip expense/settlement math, repeated request and conflicting key behavior. | NDX-020 | IN PROGRESS — MySQL full suite 120/1077, 0 skipped. Per-customer selling prices preserve server cost snapshots; variance remains record-only. Define source-backed trip-opening stock/carry-over before expected closing quantities. Tax/discount/credit/refund/variance posting policies remain subject to owner decisions |
| NDX-070 | P1 | UI idempotency coverage for sale, purchase, payment, trip load, settlement and returns; persist request identity during ambiguous outcomes and prove no duplicate posting. | NDX-060 | IN PROGRESS — field sale uses manually entered per-customer price and stable UI key; integer enforcement also runs on offline sales/returns/payments/expenses. Other purchase/payment/settlement retry edges remain |
| NDX-080 | P1 | Offline sync contract: atomic local mutation + outbox, FIFO/single-flight, exponential backoff, stable operation UUID/key, ACK exact operation, explicit accepted/rejected/conflict states, partial failure and restart recovery. | NDX-050, NDX-060 | IN PROGRESS — rep visit offline queue/reconnect E2E and role/trip scope verified; revocation, stale stock, full private-cache and conflict lifecycle coverage remain |
| NDX-090 | P1 | Offline conflict UX: no silent last-write-wins for financial/inventory mutations; server-authoritative decision, manager-visible resolution, revoked device behavior, stale stock handling and audit trail. | NDX-080 | PENDING |
| NDX-100 | P1 | PWA/device lifecycle: install/update, stale cache invalidation, login expiry, logout/private cache clearing, device revoke, reconnect and cold-start checks on supported browsers/devices. | NDX-080 | PENDING |
| NDX-110 | P1 | UX screen/button audit: every route/form loading/error/empty/validation/session-expiry state, Arabic RTL, responsive/mobile, keyboard and duplicate-submit behavior. Capture checklist and critical-journey E2E. | NDX-020 | IN PROGRESS — field customer route, paginated vehicle search, expense form, review-only stocktake, manually negotiated whole-number sale price and Arabic numeral normalization; Playwright 9/9; remaining application-wide UX checklist open |
| NDX-120 | P1 | Reports/projections reconciliation: dashboard and reports agree with posted stock movements, invoices, payment allocations, ledger, vehicle balances and settlements; projections rebuild deterministically. | NDX-060 | PENDING |
| NDX-130 | P1 | Packaging completeness: carton/piece/mixed entry, immutable conversion snapshots, barcode aliases, unit-specific prices, source-linked return conversion, concurrent stock availability. | NDX-060 | PENDING |
| NDX-140 | P1 | Production readiness: secrets/config separation, secure headers/rate limits, request correlation/audit, queues/outbox retries, dependency/security checks, observability and incident runbook. | NDX-030, NDX-050 | PENDING |
| NDX-150 | P1 | Backup/restore and performance gates: automated backup, isolated restore drill with reconciliation, agreed load/concurrency thresholds and results. | NDX-140 | PENDING |
| NDX-160 | P1 | Pilot kit: isolated staging tenant, approved demo/import mapping, opening-stock/balance reconciliation, training, support path, rollback and KPI baseline with a real distributor. | NDX-120, NDX-130, NDX-140, NDX-150 | PENDING |
| NDX-170 | P2 | Approved Egypt tax/e-invoice/e-receipt integration research and ADR; only implement after official requirements, API access and owner policy are confirmed. | Owner decision | NEEDS OWNER DECISION |
| NDX-180 | P2 | Commercial release gate: package/pricing, onboarding, support/retention terms, monitoring/alerts and signed owner approval. | NDX-160, NDX-170 if applicable | PENDING |

### Continuous execution loop contract

At the end of each task, the agent must: (1) state exact evidence and root cause/requirement; (2) list files changed; (3) run focused tests plus applicable regression gates; (4) inspect `git diff --check` and the final diff; (5) update this table, `PROJECT_STATUS.md`, `TASK_LOOP.md`, and append `docs/EXECUTION_LOG.md`; (6) mark `DONE`, `BLOCKED`, or `NEEDS OWNER DECISION`; (7) immediately begin the next unblocked task. If blocked, record the precise blocker, the last successful command/checkpoint, and the single next command/action. Never claim an external GitHub workflow passed until its actual result is observed.
