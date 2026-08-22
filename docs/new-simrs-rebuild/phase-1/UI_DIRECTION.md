# UI direction (clean-slate)

Status: Accepted under DEC-013, amended by DEC-015
Date: 2026-08-23

## What “familiar” means

| Source | Role |
|---|---|
| Vendor SIMRS live UI (`simrs.universitasesaunggul.com`) | **Primary** reference for information architecture, menu categories, screen placement, and workflow familiarity |
| UEU logo / campus brand colors | Acceptable theme inspiration (not a copy of the failed teaching MVP chrome) |
| Previous Vercel “Antrean kerja” / outpatient teaching MVP | **Not a reference.** Failed production path. Do not restore its IA, work-queue metaphor, or look-and-feel as the target product |

## Hard limits (unchanged)

- Functional parity is evidence-driven (`PAR-*`); menu lookalike ≠ working transaction.
- Do not copy vendor security model, cleartext displays, schema, credentials, or production BPJS/SatuSehat wiring.
- Synthetic-only teaching environment: the **backend** enforces synthetic-only / simulation safety and the shared application shell permanently displays **`SIMULASI — DATA SINTETIS`** on authentication and authenticated screens. The indicator is non-dismissible and must remain visible across teaching desks.
- The visible indicator is necessary but not sufficient: it never authorizes real patient data or production BPJS/VClaim/SATUSEHAT connectivity.
- Rebuild on modern Laravel + React/Inertia stack (ADR-015), not jQuery 1.7 clone-as-architecture.

## Target shell (to design/build)

Inspired by Observed vendor shell:

1. Top (or primary) category navigation aligned to hospital domains: Pendaftaran, Pemeriksaan, RM, Klaim, Laporan, BPJS, Apotek, GF, Kasir, Manajemen Data, … (enable by phase, not all 268 at once).
2. Dashboard / home as operational landing (stats/work areas per role), not a “personal task antrean” teaching metaphor from the failed MVP.
3. Forms and grids that feel like hospital SIMRS work (filters → list → detail/dialog), rebuilt cleanly.
4. UEU identity remains primary. The permanent **`SIMULASI — DATA SINTETIS`** indicator is a compact safety marker in the shared authenticated shell, not a modal, hero, work queue, or substitute product identity.

## Simulation indicator contract (DEC-015)

- Exact label: **`SIMULASI — DATA SINTETIS`**.
- Scope: every interactive application screen, including authentication and authenticated screens.
- Behavior: always visible, non-dismissible, and never conditional on role or module.
- Presentation: restrained, readable, and compatible with UEU tokens; it must not recreate the failed Antrean/work-queue MVP chrome.
- Safety: backend synthetic-only enforcement remains mandatory. Missing backend enforcement is not cured by the indicator, and a missing indicator is a teaching-readiness defect.

## Assessment

Full visual/IA assessment against vendor screens is scheduled separately (“later assess everything”). This note locks direction before that pass.
