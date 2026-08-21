# ADR-016: Clean-slate replace-in-place on `rebuild/clean-slate`

- Status: Accepted
- Date: 2026-08-21
- Decision owner: Daniel Happy Putra (product owner / architecture lead)
- Related: DEC-012, DEC-004, DEC-008, ADR-015, `docs/new-simrs-rebuild/TOOL_SELECTION_GUIDE.md`

## Context

Phase 0 scaffolding and ADR-015 ratified continuing Laravel + React/Inertia for the parity program. The committed outpatient teaching MVP on `main` is a valuable historical reference, but carrying its full domain graph forward on the rebuild branch risks treating MVP completeness as SIMRS parity and slows a deliberate Phase 2 identity/authorization restart.

Two implementation options were considered for the rebuild working branch:

| Option | Description |
|---|---|
| A | Parallel greenfield app / package beside the MVP |
| B | Replace application domain code in place on `rebuild/clean-slate`, preserving framework, safety controls, and documentation |

## Decision

**Option B accepted:** on branch `rebuild/clean-slate`, remove the previous outpatient/teaching/clinical/coding/claims MVP application domain and leave a clean Laravel 13 + Inertia/React foundation aligned with ADR-015 / `TOOL_SELECTION_GUIDE.md`, ready for Phase 2.

Preserve:

- framework tooling (Artisan, Composer, Vite, TS, ESLint, Pint, PHPStan, PHPUnit, Vitest);
- simulation safety (`APP_MODE`, `APP_SYNTHETIC_ONLY`, related middleware);
- Fortify/Inertia auth and settings surfaces;
- entire `docs/` tree (including `docs/new-simrs-rebuild/` and vendor assessment);
- CI workflows, Vercel/PHP runtime packaging files when still valid;
- append-only audit foundation table (minimal schema without teaching FKs).

Do not:

- push without explicit authorization;
- delete documentation;
- enable production integrations;
- treat historical MVP code on `main` as the default copy-forward path.

## Consequences

- Positive: rebuild branch starts with a clear Phase 0/2 boundary; safety rails remain enforceable; docs remain the source of truth.
- Trade-offs: domain features must be reintroduced deliberately under Phase 1 specs and Phase 2+ slices; patterns from `main` may be re-copied later only when chosen, not by default.
- Follow-up: Phase 1 discovery/parity specs; Phase 2 identity, role/action matrix, and first vertical slice under the rebuild blueprint.
- Reversal: recover MVP application code from `main` history; do not silently merge domain back without a DEC/ADR.

## Validation

- Authenticated rebuild home renders foundation status.
- `/up` health endpoint remains available.
- Simulation middleware rejects non-simulation / non-synthetic-only configuration.
- Opt-in seeder creates only `admin.rebuild@example.invalid` and refuses unsafe env.
- Quality gates: Pint, PHPStan, PHPUnit, TypeScript check, Vite build.
