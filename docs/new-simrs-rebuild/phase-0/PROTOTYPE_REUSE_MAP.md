# Prototype reuse map (Keep / Adapt / Retire / Isolate)

Status: rewritten for Option B clean-slate — Phase 0  
Date: 2026-08-21  
Scope: branch `rebuild/clean-slate` after DEC-012 / ADR-016  
Rule: the previous outpatient teaching MVP is **historical on `main`**. This rebuild branch starts fresh. Patterns may be re-copied later **deliberately**, not by default.

## Disposition legend

| Label | Meaning |
|---|---|
| **Keep** | Retained on the rebuild foundation |
| **Adapt** | Reintroduce later with deliberate changes and tests |
| **Retire** | Not present on this branch; historical on `main` only |
| **Isolate** | Available as evidence/docs or constrained demo hosting; do not expand silently |

## Platform and controls (this branch)

| Area | Path / artifact | Disposition | Rationale |
|---|---|---|---|
| Laravel + Inertia/React stack | framework configs, ADR-015 | Keep | Ratified stack for parity program |
| Synthetic-only / simulation mode | `APP_MODE`, `APP_SYNTHETIC_ONLY`, middleware | Keep | Mandatory safety boundary |
| Identity / Fortify auth | Fortify, settings pages | Keep / Adapt | Expand role/action matrix in Phase 2 |
| Append-only audit foundation | `audit_events`, `App\Support\Audit` | Keep / Adapt | Minimal schema; strengthen coverage in Phase 2 |
| CI | `.github/workflows` | Keep | Quality floor; extend with parity suites later |
| Vercel + Supabase demo path | `vercel.json`, hosting docs | Isolate | Synthetic demo hosting only |
| Public self-registration | disabled | Keep | Correct for provisioned teaching accounts |

## Previous MVP domain (historical on `main`)

The following lived on the outpatient teaching MVP and were **removed** from `rebuild/clean-slate`. They are **Retire** on this branch (recover from `main` only if a later DEC explicitly chooses Adapt):

- `app/Modules/*` (Patient, Clinical, Teaching, Coding, Claims, Encounter, Reporting, Interoperability, RecordQuality, prior Audit module graph)
- Domain HTTP controllers, requests, and domain console commands (`simulation:clone-*`, laboratory helpers, reference journey completers, terminology import, etc.)
- Domain migrations for outpatient/teaching/clinical/pharmacy/coding/eclaim graphs
- Domain Inertia pages under hospital/patient/clinical/coding/claims/encounter/work/record-quality
- Domain factories/seeders beyond the opt-in rebuild admin

## Explicit reuse policy

1. Do **not** bulk-copy MVP modules onto this branch “to save time.”
2. When a Phase 1/2 slice needs a proven pattern (authz + audit + fixtures + tests), open a DEC, cite the `main` path, and Adapt with new tests.
3. Prefer blueprint + `PARITY_REQUIREMENTS_MATRIX.md` over MVP UI completeness as the definition of done.
4. Teaching session clone/reset, coding gold-set, E-Klaim educational adapter, and FHIR preview remain **Isolate** ideas until re-specified for parity scope.

## Explicitly not present (must not be invented as “already done”)

Emergency/triage full slice; inpatient bed/transfer/discharge; diagnostics LIS/PACS; surgery/IBS; GF warehouse ledger; full Kasir; 117 reports; BPJS production; IoT; mortuary/ambulance; full Manajemen Data masters; and the retired outpatient MVP workspaces.

These remain Phase 1+ specification and later build work.

## Related decisions

- DEC-012 Option B Accepted
- ADR-016 Clean-slate replace-in-place
- ADR-015 Stack selection
