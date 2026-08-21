# ADR-015: Stack selection for the parity rebuild program

- Status: Accepted
- Date: 2026-08-21
- Decision owner: Daniel Happy Putra (architecture / product owner)
- Related: DEC-008, DEC-010, ADR-001, `docs/new-simrs-rebuild/TOOL_SELECTION_GUIDE.md`

## Context

ADR-001 selected a Laravel modular monolith for the teaching outpatient reference build. The clean-slate parity program requires an explicit re-score against `TOOL_SELECTION_GUIDE.md` so the blueprint is not narrowed to whatever is already running.

UEU needs immediate synthetic demo testing on the existing Vercel + Supabase path while keeping campus production hosting TBD.

## Decision drivers

- Functional and parity needs across the full hospital chain (not outpatient-only forever)
- Security, synthetic isolation, server-side authorization, durable audit
- Transactional integrity for clinical, stock, claim and financial ledgers
- Small-team maintainability and current skills
- Disposable demo hosting now; portable exit to future campus host
- Do not enable production BPJS/SATUSEHAT/LIS/PACS/payment endpoints

## Mandatory capability screen

| Capability | Result | Evidence |
|---|---|---|
| Relational transactions and constraints | Pass | Laravel migrations/FKs; domain services in transactions |
| Schema migrations and rollback planning | Pass | Versioned migrations; local MySQL rollback rehearsals documented historically |
| Server-side auth and action authorization | Pass | Fortify, policies, feature negative tests; matrix must expand in Phase 2 |
| Secret management and environment separation | Pass | Env-based secrets; Vercel env; synthetic flags |
| Durable audit logging | Pass (constrain) | Append-only audit module present; strengthen immutability/export in Phase 2 |
| Background jobs with retry/idempotency/monitoring | Pass (constrain) | Laravel queues exist; **Vercel demo uses `QUEUE_CONNECTION=sync`** |
| Documented APIs and integration support | Pass | Educational adapters (E-Klaim, FHIR preview) with fail-closed boundaries |
| Automated tests (unit/integration/browser) | Pass (constrain) | PHPUnit + Vitest strong; browser coverage partial |
| Accessible forms/grids/printing/reports | Pass (constrain) | Forms + some a11y tests; full report platform not built |
| Backup/export | Pass (constrain) | Supabase/demo backups; campus RPO/RTO unproven |
| Maintainable deployment within capacity | Pass (constrain) | Vercel community PHP runtime acceptable for **synthetic demo only** |
| License/cost acceptable | Pass | Open-source stack + free-tier demo hosts for teaching |

No mandatory capability scored zero. Candidate is not disqualified.

## Weighted score (contribution = score/5 × weight; max 100)

| Criterion | Weight | Score 0–5 | Contribution | Notes |
|---|---:|---:|---:|---|
| Data integrity and transactions | 15 | 4 | 12.0 | Strong monolith transactions; full stock/finance ledgers not yet built |
| Security, privacy, authorization | 15 | 4 | 12.0 | Synthetic guards + authz tests; full role/action matrix incomplete |
| Maintainability and team capability | 12 | 4 | 9.6 | Matches current ownership and PHP/React skills |
| Integration/API capability | 10 | 3 | 6.0 | Sandbox adapters exist; durable outbox/retry/recon still thin |
| Testing and quality automation | 10 | 4 | 8.0 | CI present; expand parity/UAT automation |
| Operations, observability, recovery | 10 | 3 | 6.0 | Demo deploy works; campus DR and worker ops TBD |
| Functional/UI suitability | 8 | 4 | 6.4 | Complex clinical forms proven in outpatient spine |
| Performance and scalability | 5 | 3 | 3.0 | Class-concurrency load evidence incomplete |
| Portability and vendor independence | 5 | 4 | 4.0 | Host-agnostic app; avoid lock-in to Vercel PHP runtime for campus prod |
| Total lifecycle cost | 5 | 4 | 4.0 | Acceptable for teaching demo horizon |
| Documentation/ecosystem maturity | 3 | 5 | 3.0 | Laravel/React ecosystems mature |
| Delivery speed | 2 | 4 | 1.6 | Existing prototype accelerates foundation |
| **Total** | **100** | | **75.6** | Continue with constraints |

## Considered options

| Option | Benefits | Costs/risks | Evidence | Decision |
|---|---|---|---|---|
| Continue Laravel + React/Inertia + relational DB; Vercel/Supabase synthetic demo | Reuses proven outpatient spine, tests, synthetic guards; fastest safe path to demo testing | Vercel sync queue; community PHP runtime; not campus prod | Working demo + CI + ADR-001 evidence | **Selected** |
| Rewrite to Node/Next full-stack now | One language | Loses transactional/teaching investment; slows ASAP test | No proof advantage for hospital ledgers | Rejected now |
| Low-code / generated HIS shell | Fast screens | Fails audit/transaction/test/exit portability traps in guide | Guide disallows narrowing requirements | Rejected |
| Microservices now | Isolation | Ops cost before domain proof | Blueprint prefers modular monolith first | Rejected |

## Decision

1. **Application stack:** PHP 8.3+, Laravel modular monolith, React + TypeScript + Inertia, relational database (PostgreSQL on Supabase for demo; MySQL-compatible path retained for campus options).
2. **Identity:** Fortify sessions, named accounts, server-side policies; expand action matrix in Phase 2.
3. **Jobs:** Laravel queues; use real async workers when the host supports them. On Vercel demo, sync queue is an accepted **constraint**, not a product requirement forever.
4. **Reporting:** Application-generated projections first; governed report definitions in Phase 4/6.
5. **Hosting (demo):** Vercel + Supabase Free, synthetic-only (`APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`).
6. **Hosting (campus production):** TBD — must re-enter this ADR when chosen.
7. **Observability:** structured logs + health `/up` now; expand metrics/alerts before parity pilot.

## Consequences

- Positive: immediate demo testing without stack rewrite; blueprint remains tool-governed via this ADR.
- Trade-offs: some architectural proof items (async retry, full restore RPO/RTO, class load test) remain open and block Phase 2/5 gates, not this demo publish.
- Follow-up: complete TOOL_SELECTION proof items 7–10 against demo/staging before G1 exit.
- Reversal: export schema/data/config; redeploy Laravel app to alternate PHP/PostgreSQL or MySQL host without rewriting domain modules.

## Validation / revisit triggers

Revisit ADR-015 if any occur:

- mandatory capability regresses to fail;
- Vercel/Supabase limits block a Phase 2–5 gate;
- campus host is selected;
- team can no longer maintain PHP/Laravel;
- production-capable (non-synthetic) operation is authorized.

## Architectural proof checklist (parity program)

| # | Proof item | Status |
|---|---|---|
| 1 | Synthetic patient + encounter | Proven (outpatient spine) |
| 2 | Role/context access | Partial — expand Phase 2 |
| 3 | Clinician order | Proven (outpatient) |
| 4 | Fulfill in another module | Proven (pharmacy) |
| 5 | Charge + audit | Partial — educational claims; full cashier later |
| 6 | Report/read model | Partial — debrief/report projections |
| 7 | Idempotent sandbox integration message | Partial — E-Klaim educational adapter |
| 8 | Reject unauthorized access | Proven in multiple feature tests |
| 9 | Schema migrate + rollback | Proven locally; rehearse on demo carefully |
| 10 | Restore + reconcile | Demo/campus evidence still open |
