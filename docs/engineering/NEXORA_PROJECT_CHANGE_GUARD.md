# NEXORA Project Change Guard

> Mandatory review contract for every future change to NEXORA Distribution OS.

## Purpose

Protect the existing architecture and business contracts while the product grows.

This file is the persistent pre-change and post-change checklist. It is not a substitute for tests.

## 1. Context First

Before changing code, inspect:

1. Current `main` commit and recent merged changes.
2. `docs/NEXORA_PROGRESS.md`
3. `docs/context/NEXORA_DISTRIBUTION_CONTEXT.md`
4. `docs/architecture/MASTER_BLUEPRINT_v1.md`
5. `docs/architecture/DOMAIN_MODEL_ERD_v1.md`
6. `docs/architecture/TRANSACTION_ENGINE_v1.md`
7. `docs/architecture/UX_SCREEN_ARCHITECTURE_v1.md`
8. Relevant ADRs under `docs/adr/`.
9. `CONTRIBUTING.md`.
10. The complete set of files directly affected by the requested change.
11. Existing tests covering the affected domain.

Do not blindly read every byte of the repository on every change. Instead, review the complete authoritative project contract plus every affected dependency and contract. For cross-cutting changes, expand the review to the whole affected subsystem.

## 2. Architecture Guard

Never introduce a change that violates these contracts:

- Modular Monolith remains the structural direction.
- Transaction Engine remains the only path for posted inventory/financial mutations.
- Stock balances are never mutated without corresponding stock movements.
- Retryable mutations are idempotent.
- Tenant scoping is mandatory.
- Server is authoritative after sync.
- Vehicle remains a stock location.
- Trip remains a first-class business object.
- Posted transactions are immutable; corrections use reversals/returns.
- Historical unit/conversion snapshots are preserved.
- Carton/piece/mixed quantities reconcile at base-unit level.
- External side effects use the outbox boundary.
- AI never writes financial or inventory facts directly.
- No unnecessary dependency or framework expansion.
- No rewrite/refactor of stable areas without evidence.

## 3. Dependency Impact Review

For every changed file, identify:

- Direct imports/dependencies.
- Routes/API contracts affected.
- Database tables/migrations affected.
- Services used by the changed code.
- Frontend consumers.
- Tests that assert the behavior.
- Authentication/authorization/tenant implications.
- Idempotency/offline implications when mutations are involved.

If a contract changes intentionally, update its documentation and tests in the same change.

## 4. Change Sequence

Use:

Requirement
→ Context Review
→ Impact Map
→ Minimal Implementation
→ Test
→ Build/CI
→ Verify
→ Documentation

Prefer the smallest safe change that satisfies the requirement.

## 5. Mandatory Verification

### Backend changes

Run/verify:

- Relevant feature/unit tests.
- Full backend test suite when the change touches shared services, migrations, transaction posting, auth, sync, tenant scope, or shared infrastructure.
- Fresh migration compatibility when migrations change.
- Seeder repeatability when seeders change.
- Transaction/inventory/accounting invariants for financial or stock changes.

### Frontend changes

Run/verify:

- TypeScript/build.
- Relevant route/component checks.
- Mobile/RTL behavior for user-facing field/admin screens.
- Critical workflow compatibility when API contracts change.

### Cross-cutting changes

Verify both backend and frontend plus the affected business workflow end-to-end.

## 6. Git Safety Gate

Before merge:

- Review the final diff against the intended base.
- Confirm no unrelated files changed.
- Confirm no secrets, credentials, generated junk, or temporary debug files.
- Confirm migrations are additive/reversible unless a documented exception exists.
- Confirm public API contracts did not change accidentally.
- Confirm no existing feature was silently removed.
- Confirm CI is green for the exact commit being merged.
- Never force-merge divergent history to make a branch appear complete.

## 7. Regression Review

After implementation, explicitly ask:

- What existing workflow could this break?
- What data invariant could this break?
- What tenant could this expose?
- What retry could duplicate?
- What offline replay could conflict?
- What migration could fail on a fresh database?
- What UI consumer could receive a different response shape?
- What historical transaction could become inconsistent?

If any answer is unknown, the task is not verified yet.

## 8. Evidence Rule

Do not label a feature "complete" because code exists.

Completion requires:

**Evidence → Fix/Implementation → Test → Build/CI → Verification**

## 9. Change Record

For significant architectural or cross-cutting changes, record:

- Why the change was needed.
- What existing contract it touches.
- What alternatives were rejected.
- What tests prove safety.
- What remains open.

Use an ADR under `docs/adr/` when the decision changes architecture.

## 10. Final Quality Gate

Every significant task must end with:

### Done
What was implemented.

### Changed
What files/contracts changed.

### Tested
What tests/builds ran.

### Verified
What behavior was actually confirmed.

### Remaining Issues
Anything still open.

### Next Step
The next highest-value safe action.

---

**Rule:** NEXORA grows by extending verified contracts, not by accidentally replacing them.
