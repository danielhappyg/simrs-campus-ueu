# Prototype reuse map (Keep / Adapt / Retire / Isolate)

Status: initial inventory — Phase 0  
Date: 2026-08-21  
Scope: committed `main` teaching prototype at orientation time + noted local WIP  
Rule: reuse security and domain patterns where they match the rebuild blueprint; do not treat outpatient MVP completeness as full-SIMRS parity.

## Disposition legend

| Label | Meaning |
|---|---|
| **Keep** | Retain as-is for the parity program foundation |
| **Adapt** | Reuse core idea/code with deliberate changes and tests |
| **Retire** | Stop using as a target path; may remain as historical reference briefly |
| **Isolate** | Keep available but do not expand; boundary until a DEC/ADR says otherwise |

## Platform and controls

| Area | Path / artifact | Disposition | Rationale |
|---|---|---|---|
| Modular monolith shape | `app/Modules/*`, ADR-001 | Keep | Matches reference architecture preference for initial delivery |
| Synthetic-only / simulation mode | `APP_MODE`, `APP_SYNTHETIC_ONLY`, guards | Keep | Mandatory safety boundary |
| Identity / Fortify auth | Fortify, named accounts | Adapt | Extend to full role/action matrix and cohort admin (Phase 2) |
| Append-only audit | `app/Modules/Audit` | Adapt | Must meet tamper-resistant / coverage NFRs for all privileged actions |
| Teaching sessions / clone / reset | `Teaching` module, clone command | Adapt | Becomes teaching control plane; expand fixture packs carefully |
| CI (`composer ci:check`, GitHub workflows) | `.github/workflows` | Keep | Phase 0 quality floor; extend with parity/auth/audit suites later |
| Vercel + Supabase demo path | `vercel.json`, hosting docs | Isolate | Allowed demo hosting; not campus production; no silent expand |
| Public self-registration | disabled | Keep | Correct for teaching provisioning model |

## Domain modules (committed prototype)

| Module | Disposition | Notes for parity program |
|---|---|---|
| Patient / registration / check-in | Adapt | Seed for Pendaftaran slice; expand multi-patient population & canonical RJ rules under Phase 1 specs |
| Encounter state machine | Adapt | Core spine; extend for IGD/RI without duplicating v2/v3 forks |
| Clinical outpatient (nursing, medical, orders, pharmacy handoff) | Adapt | Proving ground for vertical-slice quality; not a substitute for ED/IP specs |
| Coding / terminology import | Adapt | Keep checksummed import pattern; gold-set approval still pending stakeholders |
| Record quality / RMIK completeness | Adapt | Outpatient-shaped; generalize after PAR-RMIK discovery |
| Claims E-Klaim educational adapter | Isolate | Sandbox/education only (ADR-013); never production endpoint |
| Interoperability FHIR preview | Isolate | Local preview only (ADR-007); no transmission |
| Reporting / debrief projections | Adapt | Teaching evidence ≠ 117 operational Laporan definitions |
| Identity policies | Adapt | Least privilege vs legacy student over-permission is a hard NEW requirement |

## Explicitly not present (must not be invented as “already done”)

Emergency/triage full slice; inpatient bed/transfer/discharge; diagnostics LIS/PACS; surgery/IBS; GF warehouse ledger; full Kasir; 117 reports; BPJS production; IoT; mortuary/ambulance; full Manajemen Data masters.

These remain Phase 1+ specification and Phase 3–4 build work.

## Local WIP (uncommitted at Phase 0 scaffolding time)

| Item | Disposition | Notes |
|---|---|---|
| ADR-014 hospital-shell UX pivot | Isolate until reviewed | Proposed bridge for teaching IA; not PAR disposition |
| `Hospital*DeskController`, hospital pages, shell tests | Isolate | Do not commit inside Phase 0 docs baseline unless separately authorized |
| Expanded demo seeder / registration UX edits | Isolate | Preserve in worktree; review as Adapt candidate after G0 |

## Reuse principles

1. Prefer complete vertical-slice patterns (authz + audit + fixtures + tests) over copying UI alone.
2. One authoritative implementation per capability — do not recreate legacy/v2/v3/EMR forks in code.
3. Any Keep/Adapt item that fails a mandatory `TOOL_SELECTION_GUIDE` capability triggers DEC-008 / ADR, not silent narrowing of SIMRS requirements.
