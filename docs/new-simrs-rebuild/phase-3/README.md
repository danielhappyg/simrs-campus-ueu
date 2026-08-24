# Phase 3 — Outpatient vertical slice

**Status:** Teaching slice available; parity acceptance not granted
**Date:** 2026-08-24

This page describes build availability. It does not claim complete SAHABAT parity, clinical production readiness, or acceptance of PAR-REG-003, PAR-CLN-004, PAR-CLN-006, or PAR-RMIK-001. See the separate availability and acceptance ledger in `../PARITY_REQUIREMENTS_MATRIX.md`.

## Scope

Clean-slate outpatient flow for SIMRS Campus UEU:

1. **Pendaftaran rawat jalan** — search synthetic patients, register patient + encounter
2. **Pemeriksaan rawat jalan** — worklist, encounter detail, versioned structured nursing/medical documents, **lab order (Order Lab tab)**
3. **Pemeriksaan laboratorium** — active lab worklist, one immutable synthetic `FINAL` result
4. **RM rawat jalan** — completeness review, attributable sign-off, and read-only closed record; close only when the current automatic checklist is complete and no lab order remains `ACTIVE`
5. **Beranda** — live counts from domain tables

Parity references: PAR-REG-003, PAR-CLN-004, PAR-CLN-006 (partial), PAR-RMIK-001.

## Domain

| Table | Purpose |
|---|---|
| `patients` | Synthetic identity; MRN unique; `is_synthetic` enforced |
| `encounters` | Outpatient visit; statuses REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED |
| `clinical_entries` | Legacy outpatient notes retained as read-only history; still used by non-outpatient legacy flows |
| `outpatient_clinical_documents` | Current nursing/medical document heads with explicit `DRAFT` / `FINAL` state |
| `outpatient_clinical_document_versions` | Immutable attributable version history for every draft save and finalization |
| `outpatient_rm_completeness_reviews` | Versioned RMIK completeness snapshots and sign-off provenance |
| `outpatient_rm_completeness_items` | Automatic checklist results captured with each completeness review |
| `lab_service_requests` | Physician lab orders on encounter (catalog test code + label) |
| `lab_diagnostic_results` | Nurse-entered synthetic FINAL results (one immutable result per order) |

## Authorization

Server-side Gate capabilities:

- Registrar: `patient.search`, `patient.register`, `encounter.list`
- Nurse: `encounter.open`, `clinical.nursing.write`, `clinical.lab.result.write`
- Physician: `encounter.open`, `clinical.medical.write`, `clinical.order.create`
- RMIK: `rmik.review` for worklist access; `rmik.completeness.signoff` to close

Audit via `AuditRecorder` on registration, structured draft save/finalization, lab order/result, completeness review, and RM sign-off.

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
| POST | `/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/draft` | `pemeriksaan.rawat-jalan.documents.draft` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/final` | `pemeriksaan.rawat-jalan.documents.final` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/lab-orders` | `pemeriksaan.rawat-jalan.lab-orders.store` |
| GET | `/pemeriksaan/laboratorium` | `pemeriksaan.laboratorium.index` |
| POST | `/pemeriksaan/laboratorium/{order}/results` | `pemeriksaan.laboratorium.results.store` |
| GET | `/rm/rawat-jalan` | `rm.rawat-jalan.index` |
| GET | `/rm/rawat-jalan/{encounter}` | `rm.rawat-jalan.show` |
| POST | `/rm/rawat-jalan/{encounter}/reviews` | `rm.rawat-jalan.reviews.store` |
| POST | `/rm/rawat-jalan/{encounter}/signoff` | `rm.rawat-jalan.signoff` |

Nav: Pendaftaran / Pemeriksaan / RM point at these dedicated routes (other categories remain `/modul/{slug}`).

## Not in this phase

- SEP/BPJS production eligibility
- ICD coding UI
- Pharmacy, radiology, charges (lab teaching slice only; no billing/LIS)
- Preliminary lab results, final-result amendment, encounter reopen and lab-order cancellation
- Antrean / work-queue MVP modules (do not restore)

## Current bounded depth slice

**Structured Outpatient Documentation and RM Completeness v1** is implemented as this phase's current bounded depth slice, not as Radiology or a claim of a complete clinical record. Its [functional-requirements pack](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md), [wireframe handoff](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md), and [bounded implementation decision](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md) define the engineering boundary. Clinical/RMIK acceptance, broader field and supervision decisions, PAR-CLN-004/PAR-RMIK-001 acceptance, and DEC-016 status remain unchanged.

Facilitator script: `docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`  
**Full handoff (start here for other humans/agents):** `docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md`

Current exact-SHA deployment and schema reconciliation: `docs/operations/T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`. Focused authenticated structured-v1 UAT and Clinical/RMIK acceptance remain open.

## Evidence

- `tests/Feature/Outpatient/OutpatientFlowTest.php`
- `tests/Feature/Outpatient/OutpatientLabFlowTest.php`
- `tests/Feature/Outpatient/OutpatientLifecycleContractTest.php` (closure and final/late/duplicate-result rules)
- `tests/Feature/Outpatient/StructuredOutpatientDocumentationTest.php` (versioned documents, automatic completeness review, sign-off, and closed-record access)
- `tests/Feature/RebuildHomeTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
