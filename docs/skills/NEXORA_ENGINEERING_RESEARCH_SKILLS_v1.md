# NEXORA Engineering Research Skills v1

## Purpose

This project uses a reusable engineering skill layer so implementation decisions are evidence-driven rather than copied from memory.

## Active skills

### 1. Architecture Research
- Compare existing NEXORA architecture before introducing dependencies.
- Reuse proven patterns from nexora-platform where compatible.
- Keep Distribution OS domain rules authoritative.

### 2. Transaction Integrity
- Every inventory/financial mutation passes through a transaction service.
- Use DB transactions and row locking for balance-affecting operations.
- Use idempotency for retryable mutations.
- Posted records are immutable; reverse instead of editing history.

### 3. Database Engineering
- MySQL 8.4 / InnoDB.
- DECIMAL for money and quantities.
- Explicit short index names for MySQL identifier limits.
- CHECK constraints are enforced at MySQL level where useful.
- Large-table indexes must be justified and later validated with EXPLAIN.

### 4. Offline-First Field Engineering
- Server is authoritative after synchronization.
- Device operation UUID + idempotency key.
- Local queue with explicit status.
- No silent overwrite on financial conflicts.
- Read models can be cached; authoritative mutations are queued operations.

### 5. Route Intelligence
- Domain model supports many customers per city/center/governorate.
- Route optimization is provider-agnostic.
- Google Route Optimization is the preferred production adapter for multi-vehicle constraints when enabled.
- Optimization results are recommendations; dispatcher can manually reorder stops.
- Provider failures must never block core sales/settlement.

### 6. AI Engineering
- AI sits above verified business data.
- AI never writes financial/inventory facts directly.
- Every AI tool is authorization-aware and tenant-scoped.
- Use Vercel AI SDK patterns when the web/admin AI layer is implemented.
- Verify installed SDK APIs against version-matched docs before coding.

### 7. UX / Data Entry
- Minimum Input → Maximum Result.
- Keyboard-first admin.
- Mobile-first field PWA.
- Search/autocomplete before creation forms.
- Packaging level is always visible.
- Carton/piece/mixed entry preserves conversion snapshots.

### 8. Research Discipline
For changing technology or provider decisions:
1. Official documentation.
2. Existing NEXORA implementation.
3. High-quality open-source reference.
4. Community evidence only when useful.
5. Record the decision and trade-offs.

## Current researched decisions

- Laravel 13 remains the backend framework.
- MySQL 8.4 remains the authoritative relational store.
- Google Route Optimization is the preferred optional optimization adapter because its current API supports multi-vehicle routes, capacity, time windows and long-running optimization. The domain remains provider-independent. 
- MySQL CHECK constraints are valid for enforced business invariants.
- NEXORA Platform's idempotency, tenant-context and stock-ledger patterns are references, not code to copy blindly.
- Egypt geography should use normalized master data. The project has evaluated public datasets containing 27 governorates, hundreds of districts and thousands of local areas; imported data must preserve provenance and license information.

## Project rule

A research finding is not a product requirement until it is translated into:
- Architecture decision
- Implementation contract
- Test
- Verification evidence
