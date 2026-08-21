# Phase 1 — Parity specification (working)

Status: **active**  
Started: 2026-08-21  
Branch: `rebuild/clean-slate` (also merged to `main` for demo)  
Product owner: Daniel Happy Putra  
RMIK owner: RMIK Department

## Objective

Classify all 268 `PAR-*` rows and specify P0/P1 vertical slices before building clinical workflows on the clean-slate foundation.

## Gate (Phase 1 exit)

- [ ] Every menu has owner + disposition
- [ ] P0/P1 unknowns have evidence plan or formal DEC
- [ ] Outpatient, ED, and inpatient slice packs use `templates/PARITY_REQUIREMENT_TEMPLATE.md`

## Priority order (this increment)

1. Outpatient vertical slice (Pendaftaran RJ + Pemeriksaan RJ + RM RJ handoffs)
2. Emergency / triage
3. Inpatient / bed
4. Remaining domains as Pending evidence with owners

## Active workstream

**One active step:** Outpatient slice disposition pack — see `phase-1/OUTPATIENT_SLICE_DISPOSITIONS.md` and detailed specs under `phase-1/requirements/`.
