# ADR-014: Hospital-Shell UX Pivot (Option B)

**Status:** Accepted  
**Date:** 2026-08-21  
**Deciders:** Product owner (Daniel) and SIMRS Campus UEU engineering maintainers

## Context

Checkpoint 2 delivered a synthetic-only outpatient encounter spine with work-queue (“Antrean kerja”) as the primary door. That surface teaches handoffs well, but it does not feel like a hospital desk: after registration/check-in the registrar’s queue can feel “finished,” and learners hunt tasks instead of opening Pendaftaran, Pemeriksaan, RM, Apotek, or Klaim as modules.

Legacy campus SIMRS ([simrs.universitasesaunggul.com](https://simrs.universitasesaunggul.com/)) cues hospital IA (Pendaftaran, Pemeriksaan, RM, Klaim, Laporan, BPJS, Apotek, Kasir). The rebuild must not migrate legacy code/DB, claim real BPJS/SATUSEHAT, or scrap the encounter/provenance model.

## Decision

Ship a **hospital desk experience** (Option B):

1. **Primary IA** — module menus (Pendaftaran, Pemeriksaan, Rekam Medis, Apotek, Klaim, Laporan, BPJS, Kasir) plus a role desk dashboard.
2. **Secondary** — “Kerja saya” / Antrean kerja remains available for assigned tasks, not the only entry.
3. **Keep** — modular monolith, `APP_SYNTHETIC_ONLY`, encounter state machine, provenance/audit from Checkpoint 2.
4. **Pendaftaran** — primary write path for search, pasien baru vs lama, register, and check-in over a **synthetic multi-patient population**.
5. **Sibling modules** — thin connected views against the same encounters (status/timeline/read; deep writes stay in existing workspaces).
6. **Never** invent or enable production BPJS, E-Klaim send, or SATUSEHAT live exchange. BPJS/Klaim remain educational simulation only (see ADR-013).

Visual language aligns with modern UEU campus SI (Plus Jakarta Sans feel, `ueu-blue` `#1b75bc`, orange `#f26a1b` as accent only, navy shell)—not a clone of legacy purple glass chrome.

## Consequences

- Design principle “work before modules” becomes **modules first; Kerja saya secondary**.
- Demo seed expands beyond a single fixture patient so Pendaftaran search has volume.
- Home lands on the hospital desk, not only the work queue.
- UAT and facilitator docs should describe hospital-first surfaces while teaching outcomes stay encounter-based.

## Options considered

| Option | Summary | Result |
| --- | --- | --- |
| A — Keep work-queue-only | Lowest churn | Rejected: does not meet hospital-desk teaching feel |
| B — Hospital shell over existing core | Module nav + Pendaftaran population; keep synthetic/encounter | **Accepted** |
| C — Migrate/clone legacy SIMRS | Full legacy parity | Rejected: unsafe, out of scope, scrap risk |

## Related

- `docs/design/UEU_CLINICAL_DESIGN_SYSTEM.md`
- `docs/design/INFORMATION_ARCHITECTURE.md`
- `docs/SIMRS_CAMPUS_MASTER_PLAN.md`
- ADR-003 (domain spine), ADR-012 (work-queue scoping), ADR-013 (E-Klaim educational adapter)
