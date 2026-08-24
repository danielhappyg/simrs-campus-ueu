# Phase 1 — Parity specification (working)

Status: **slice packs complete for P0 care settings; Phase 1 residual = remaining domains + unknowns**  
Started: 2026-08-21  
Branch: `main` (clean-slate) / historically `rebuild/clean-slate`  
Product owner: Daniel Happy Putra  
RMIK owner: RMIK Department

## Objective

Classify all 268 `PAR-*` rows and specify P0/P1 vertical slices before building clinical workflows on the clean-slate foundation.

## Gate (Phase 1 exit)

- [ ] Every menu has owner + disposition
- [x] P0 outpatient, ED, and inpatient slice packs drafted (`templates/PARITY_REQUIREMENT_TEMPLATE.md` style)
- [ ] P0/P1 unknowns have evidence plan or formal DEC
- [ ] Remaining domains dispositioned (pharmacy, GF, kasir, laporan bulk, manajemen data, …)

## Priority order (this increment)

1. ~~Outpatient vertical slice~~ — **done** (`OUTPATIENT_SLICE_DISPOSITIONS.md` + requirements)
2. ~~Emergency / triage~~ — **done** (`ED_SLICE_DISPOSITIONS.md` + requirements)
3. ~~Inpatient / bed~~ — **done** (`INPATIENT_SLICE_DISPOSITIONS.md` + requirements)
4. Remaining domains as Pending evidence with owners (continues in Phase 1 residual / Phase 2 parallel discovery)

## Completed slice packs

| Pack | File | Detailed specs |
|---|---|---|
| Outpatient | [`OUTPATIENT_SLICE_DISPOSITIONS.md`](OUTPATIENT_SLICE_DISPOSITIONS.md) | PAR-REG-003, PAR-CLN-004, PAR-RMIK-001 |
| ED / triage | [`ED_SLICE_DISPOSITIONS.md`](ED_SLICE_DISPOSITIONS.md) | PAR-REG-002, PAR-CLN-003 (PAR-CLN-002 dispositioned; FR after acuity discovery) |
| Inpatient / bed | [`INPATIENT_SLICE_DISPOSITIONS.md`](INPATIENT_SLICE_DISPOSITIONS.md) | PAR-REG-001, PAR-CLN-005 |

## Current owner-decision pack

The next outpatient-depth slice is specified only to the **owner-decision** stage. It is not approved for build and does not change parity acceptance:

| Artifact | Purpose | Status |
|---|---|---|
| [`STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md`](STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md) | Combined PAR-CLN-004 + PAR-RMIK-001 functional requirements, response sheets and acceptance scenarios | Draft; Clinical and RMIK decisions open |
| [`STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md`](STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md) | Indonesian, UEU-token design handoff for nurse, physician and RMIK desks | Draft; all clinical fields/checklist items marked as owner decisions |

Matrix: [`../PARITY_REQUIREMENTS_MATRIX.md`](../PARITY_REQUIREMENTS_MATRIX.md)  
UI direction: [`UI_DIRECTION.md`](UI_DIRECTION.md)

## Next

Obtain the recorded Clinical and RMIK decisions in the pack above before coding structured documentation or RM completeness. Continue Phase 1 residual disposition of non-slice menus with owners and evidence plans in parallel as needed for later phases.
