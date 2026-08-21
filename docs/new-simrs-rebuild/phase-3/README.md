# Phase 3 — Outpatient vertical slice

**Status:** MVP delivered (teaching-demo quality)  
**Date:** 2026-08-21

## Scope

Clean-slate outpatient flow for SIMRS Campus UEU:

1. **Pendaftaran rawat jalan** — search synthetic patients, register patient + encounter
2. **Pemeriksaan rawat jalan** — worklist, encounter detail, nursing/medical clinical entries
3. **RM rawat jalan** — READY_FOR_RM worklist, mark reviewed → CLOSED
4. **Beranda** — live counts from domain tables

Parity references: PAR-REG-003, PAR-CLN-004, PAR-RMIK-001.

## Domain

| Table | Purpose |
|---|---|
| `patients` | Synthetic identity; MRN unique; `is_synthetic` enforced |
| `encounters` | Outpatient visit; statuses REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED |
| `clinical_entries` | NURSING_INTAKE / MEDICAL_ASSESSMENT notes bound to encounter |

## Authorization

Server-side Gate capabilities:

- Registrar: `patient.search`, `patient.register`, `encounter.list`
- Nurse/physician: `encounter.open` + type-specific clinical write
- RMIK: `rmik.review` to close

Audit via `AuditRecorder` on register, clinical note write, and RM complete.

## Routes

| Method | Path | Name |
|---|---|---|
| GET | `/pendaftaran/rawat-jalan` | `pendaftaran.rawat-jalan.index` |
| POST | `/pendaftaran/rawat-jalan` | `pendaftaran.rawat-jalan.store` |
| GET | `/pemeriksaan/rawat-jalan` | `pemeriksaan.rawat-jalan.index` |
| GET | `/pemeriksaan/rawat-jalan/{encounter}` | `pemeriksaan.rawat-jalan.show` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/entries` | `pemeriksaan.rawat-jalan.entries.store` |
| GET | `/rm/rawat-jalan` | `rm.rawat-jalan.index` |
| POST | `/rm/rawat-jalan/{encounter}/complete` | `rm.rawat-jalan.complete` |

Nav: Pendaftaran / Pemeriksaan / RM point at these dedicated routes (other categories remain `/modul/{slug}`).

## Not in this phase

- SEP/BPJS production eligibility
- ICD coding UI
- Orders, pharmacy, charges
- Antrean / work-queue MVP modules (do not restore)

## Evidence

- `tests/Feature/Outpatient/OutpatientFlowTest.php`
- `tests/Feature/RebuildHomeTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
