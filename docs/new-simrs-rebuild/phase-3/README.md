# Phase 3 — Outpatient vertical slice

**Status:** Teaching slice available; parity acceptance not granted
**Date:** 2026-08-24

This page describes build availability. It does not claim complete SAHABAT parity, clinical production readiness, or acceptance of PAR-REG-003, PAR-CLN-004, PAR-CLN-006, or PAR-RMIK-001. See the separate availability and acceptance ledger in `../PARITY_REQUIREMENTS_MATRIX.md`.

## Scope

Clean-slate outpatient flow for SIMRS Campus UEU:

1. **Pendaftaran rawat jalan** — search synthetic patients, register patient + encounter
2. **Pemeriksaan rawat jalan** — worklist, encounter detail, nursing/medical clinical entries, **lab order (Order Lab tab)**
3. **Pemeriksaan laboratorium** — active lab worklist, one immutable synthetic `FINAL` result
4. **RM rawat jalan** — READY_FOR_RM worklist, close only when no lab order remains `ACTIVE`
5. **Beranda** — live counts from domain tables

Parity references: PAR-REG-003, PAR-CLN-004, PAR-CLN-006 (partial), PAR-RMIK-001.

## Domain

| Table | Purpose |
|---|---|
| `patients` | Synthetic identity; MRN unique; `is_synthetic` enforced |
| `encounters` | Outpatient visit; statuses REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED |
| `clinical_entries` | NURSING_INTAKE / MEDICAL_ASSESSMENT notes bound to encounter |
| `lab_service_requests` | Physician lab orders on encounter (catalog test code + label) |
| `lab_diagnostic_results` | Nurse-entered synthetic FINAL results (one immutable result per order) |

## Authorization

Server-side Gate capabilities:

- Registrar: `patient.search`, `patient.register`, `encounter.list`
- Nurse: `encounter.open`, `clinical.nursing.write`, `clinical.lab.result.write`
- Physician: `encounter.open`, `clinical.medical.write`, `clinical.order.create`
- RMIK: `rmik.review` for worklist access; `rmik.completeness.signoff` to close

Audit via `AuditRecorder` on register, clinical note write, lab order/result, and RM complete.

## Order/result/closure boundary

The teaching implementation is fail-closed: active lab orders block RM closure; closed encounters reject late results; only one `FINAL` result may complete an active order. This is **Proposed NEW teaching safety**, not observed or validated SIMRS Sahabat lifecycle parity. DEC-016 remains Proposed pending Clinical/Laboratory and RMIK owner approval.

Preliminary results, result amendment, encounter reopening and lab-order cancellation are not built. See [`../phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`](../phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md).

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
- Preliminary lab results, final-result amendment, encounter reopen and lab-order cancellation
- Antrean / work-queue MVP modules (do not restore)

## Next specification gate

The next bounded depth slice is **structured outpatient documentation + RM completeness**, not Radiology. Its [functional-requirements/owner-decision pack](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md) and [wireframe handoff](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md) are Draft. Clinical and RMIK owners must decide the field families, Draft/Final and supervision policy, checklist items, applicability and closure blockers before implementation. These drafts do not change PAR-CLN-004/PAR-RMIK-001 acceptance or DEC-016 status.

Facilitator script: `docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`  
**Full handoff (start here for other humans/agents):** `docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md`

## Evidence

- `tests/Feature/Outpatient/OutpatientFlowTest.php`
- `tests/Feature/Outpatient/OutpatientLabFlowTest.php`
- `tests/Feature/Outpatient/OutpatientLifecycleContractTest.php` (closure and final/late/duplicate-result rules)
- `tests/Feature/RebuildHomeTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
