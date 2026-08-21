# UI direction (clean-slate)

Status: Accepted under DEC-013  
Date: 2026-08-21

## What “familiar” means

| Source | Role |
|---|---|
| Vendor SIMRS live UI (`simrs.universitasesaunggul.com`) | **Primary** reference for information architecture, menu categories, screen placement, and workflow familiarity |
| UEU logo / campus brand colors | Acceptable theme inspiration (not a copy of the failed teaching MVP chrome) |
| Previous Vercel “Antrean kerja” / outpatient teaching MVP | **Not a reference.** Failed production path. Do not restore its IA, work-queue metaphor, or look-and-feel as the target product |

## Hard limits (unchanged)

- Functional parity is evidence-driven (`PAR-*`); menu lookalike ≠ working transaction.
- Do not copy vendor security model, cleartext displays, schema, credentials, or production BPJS/SatuSehat wiring.
- Synthetic-only teaching environment; simulation banners remain required.
- Rebuild on modern Laravel + React/Inertia stack (ADR-015), not jQuery 1.7 clone-as-architecture.

## Target shell (to design/build)

Inspired by Observed vendor shell:

1. Top (or primary) category navigation aligned to hospital domains: Pendaftaran, Pemeriksaan, RM, Klaim, Laporan, BPJS, Apotek, GF, Kasir, Manajemen Data, … (enable by phase, not all 268 at once).
2. Dashboard / home as operational landing (stats/work areas per role), not a “personal task antrean” teaching metaphor from the failed MVP.
3. Forms and grids that feel like hospital SIMRS work (filters → list → detail/dialog), rebuilt cleanly.
4. UEU identity visible; simulation labeling always on.

## Assessment

Full visual/IA assessment against vendor screens is scheduled separately (“later assess everything”). This note locks direction before that pass.
