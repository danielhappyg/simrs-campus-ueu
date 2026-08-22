# Phase 3 — Outpatient vertical slice

**Status:** MVP delivered (teaching-demo quality)  
**Date:** 2026-08-21

## Scope

Clean-slate outpatient flow for SIMRS Campus UEU:

1. **Pendaftaran rawat jalan** — search synthetic patients, register patient + encounter
2. **Pemeriksaan rawat jalan** — worklist, encounter detail, nursing/medical clinical entries, **lab order (Order Lab tab)**
3. **Pemeriksaan laboratorium** — active lab worklist, synthetic result entry (teaching slice, PR #48)
4. **RM rawat jalan** — READY_FOR_RM worklist, mark reviewed → CLOSED
5. **Beranda** — live counts from domain tables

Parity references: PAR-REG-003, PAR-CLN-004, PAR-CLN-006 (partial), PAR-RMIK-001.

## Domain

| Table | Purpose |
|---|---|
| `patients` | Synthetic identity; MRN unique; `is_synthetic` enforced |
| `encounters` | Outpatient visit; statuses REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED |
| `clinical_entries` | NURSING_INTAKE / MEDICAL_ASSESSMENT notes bound to encounter |
| `lab_service_requests` | Physician lab orders on encounter (catalog test code + label) |
| `lab_diagnostic_results` | Nurse-entered synthetic results (one per order) |

## Authorization

Server-side Gate capabilities:

- Registrar: `patient.search`, `patient.register`, `encounter.list`
- Nurse: `encounter.open`, `clinical.nursing.write`, `clinical.lab.result.write`
- Physician: `encounter.open`, `clinical.medical.write`, `clinical.order.create`
- RMIK: `rmik.review` to close

Audit via `AuditRecorder` on register, clinical note write, lab order/result, and RM complete.

## Routes

| Method | Path | Name |
|---|---|---|
| GET | `/pendaftaran/rawat-jalan` | `pendaftaran.rawat-jalan.index` |
| POST | `/pendaftaran/rawat-jalan` | `pendaftaran.rawat-jalan.store` |
| GET | `/pemeriksaan/rawat-jalan` | `pemeriksaan.rawat-jalan.index` |
| GET | `/pemeriksaan/rawat-jalan/{encounter}` | `pemeriksaan.rawat-jalan.show` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/entries` | `pemeriksaan.rawat-jalan.entries.store` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/lab-orders` | `pemeriksaan.rawat-jalan.lab-orders.store` |
| GET | `/pemeriksaan/laboratorium` | `pemeriksaan.laboratorium.index` |
| POST | `/pemeriksaan/laboratorium/{order}/results` | `pemeriksaan.laboratorium.results.store` |
| GET | `/rm/rawat-jalan` | `rm.rawat-jalan.index` |
| POST | `/rm/rawat-jalan/{encounter}/complete` | `rm.rawat-jalan.complete` |

Nav: Pendaftaran / Pemeriksaan / RM point at these dedicated routes (other categories remain `/modul/{slug}`).

## Not in this phase

- SEP/BPJS production eligibility
- ICD coding UI
- Pharmacy, radiology, charges (lab teaching slice only; no billing/LIS)
- Antrean / work-queue MVP modules (do not restore)

Facilitator script: `docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`

## Evidence

- `tests/Feature/Outpatient/OutpatientFlowTest.php`
- `tests/Feature/Outpatient/OutpatientLabFlowTest.php`
- `tests/Feature/RebuildHomeTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
