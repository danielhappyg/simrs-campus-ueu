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
5. **Adendum pascapenutupan** — physician request/separate approval, immutable addendum, and addendum-specific renewed RMIK review without reopening or overwriting the original record
6. **Radiologi lintas layanan** — physician order in RJ/IGD/RI, technologist performance, radiologist Draft/Verified report and immutable signed amendment, ordering-physician acknowledgement
7. **Beranda** — live counts from domain tables

Parity references: PAR-REG-003, PAR-CLN-004, PAR-CLN-006 (partial), PAR-RMIK-001.

## Domain

| Table | Purpose |
|---|---|
| `patients` | Synthetic identity; MRN unique; `is_synthetic` enforced |
| `encounters` | Outpatient visit; active path REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED, plus terminal pre-care CANCELLED |
| `encounter_cancellations` | Immutable local evidence for a bounded synthetic pre-clinical RJ/IGD/RI cancellation |
| `clinical_entries` | Legacy outpatient notes retained as read-only history; still used by non-outpatient legacy flows |
| `outpatient_clinical_documents` | Current nursing/medical document heads with explicit `DRAFT` / `FINAL` state |
| `outpatient_clinical_document_versions` | Immutable attributable version history for every draft save and finalization |
| `outpatient_rm_completeness_reviews` | Versioned RMIK completeness snapshots and sign-off provenance |
| `outpatient_rm_completeness_items` | Automatic checklist results captured with each completeness review |
| `outpatient_post_closure_amendment_requests` | Versioned physician request/decision state against an exact final source |
| `outpatient_clinical_document_addenda` | Separate current addendum head; the original clinical document remains unchanged |
| `outpatient_clinical_document_addendum_versions` | Immutable attributable draft/final addendum history |
| `outpatient_rm_amendment_reviews` | Append-only addendum-specific RMIK review and terminal sign-off snapshots |
| `outpatient_rm_amendment_review_items` | Exact checklist evidence for each renewed review snapshot |
| `outpatient_amendment_operation_receipts` | Immutable replay/conflict receipts for amendment and renewed-review operations |
| `lab_service_requests` | Physician lab orders on encounter (catalog test code + label) |
| `lab_diagnostic_results` | Nurse-entered synthetic FINAL results (one immutable result per order) |

## Authorization

Server-side Gate capabilities:

- Registrar: `patient.search`, `patient.register`, `encounter.list`
- Nurse: `encounter.open`, `clinical.nursing.write`, `clinical.lab.result.write`
- Physician: `encounter.open`, `clinical.medical.write`, `clinical.order.create`
- RMIK: `rmik.review` for worklist access; `rmik.completeness.signoff` to close

The post-closure flow adds physician-only request, approval, addendum-write, and addendum-finalize capabilities. The requester cannot decide their own request, and the deciding physician cannot author or finalize the addendum. Renewed review/sign-off reuses the existing RMIK capabilities with an additional explicit RMIK-role boundary.

Audit via `AuditRecorder` on registration, structured draft save/finalization, lab order/result, completeness review, and RM sign-off.

## Order/result/closure boundary

The teaching implementation is fail-closed: active lab orders block RM closure; closed encounters reject late results; only one `FINAL` result may complete an active order. This is **Proposed NEW teaching safety**, not observed or validated SIMRS Sahabat lifecycle parity. DEC-016 remains Proposed pending Clinical/Laboratory and RMIK owner approval.

Preliminary results, result amendment, encounter reopening and lab-order cancellation are not built. See [`../phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`](../phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md).

Existing IGD/RI clinical-note writes share an encounter-first locked writer with fresh state validation and atomic audit. The bounded cross-setting pre-clinical cancellation route/state is now locally implemented with direct-write, active-worklist, historical recap, bed, print and reset safeguards under the product-owner synthetic-only engineering boundary. Domain/parity acceptance, hosted migration and deployment remain open. Locking evidence: [`../../operations/T1_CROSS_SETTING_CLINICAL_ENTRY_LOCKING_EVIDENCE_2026-08-27.md`](../../operations/T1_CROSS_SETTING_CLINICAL_ENTRY_LOCKING_EVIDENCE_2026-08-27.md); contract: [`../phase-1/CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md`](../phase-1/CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md).

## Routes

| Method | Path | Name |
|---|---|---|
| GET | `/pendaftaran/rawat-jalan` | `pendaftaran.rawat-jalan.index` |
| POST | `/pendaftaran/rawat-jalan` | `pendaftaran.rawat-jalan.store` |
| POST | `/pendaftaran/kunjungan/{encounter}/batalkan` | `pendaftaran.kunjungan.batalkan` |
| GET | `/pemeriksaan/rawat-jalan` | `pemeriksaan.rawat-jalan.index` |
| GET | `/pemeriksaan/rawat-jalan/{encounter}` | `pemeriksaan.rawat-jalan.show` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/draft` | `pemeriksaan.rawat-jalan.documents.draft` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/final` | `pemeriksaan.rawat-jalan.documents.final` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/lab-orders` | `pemeriksaan.rawat-jalan.lab-orders.store` |
| POST | `/pemeriksaan/rawat-jalan/{encounter}/amendments` | `pemeriksaan.rawat-jalan.amendments.store` |
| POST | `/pemeriksaan/rawat-jalan/amendments/{amendmentRequest}/decision` | `pemeriksaan.rawat-jalan.amendments.decision` |
| POST | `/pemeriksaan/rawat-jalan/amendments/{amendmentRequest}/addendum` | `pemeriksaan.rawat-jalan.amendments.addendum.store` |
| POST | `/pemeriksaan/rawat-jalan/amendments/{amendmentRequest}/addendum/finalize` | `pemeriksaan.rawat-jalan.amendments.addendum.finalize` |
| GET | `/pemeriksaan/laboratorium` | `pemeriksaan.laboratorium.index` |
| POST | `/pemeriksaan/laboratorium/{order}/results` | `pemeriksaan.laboratorium.results.store` |
| GET | `/rm/rawat-jalan` | `rm.rawat-jalan.index` |
| GET | `/rm/rawat-jalan/{encounter}` | `rm.rawat-jalan.show` |
| POST | `/rm/rawat-jalan/{encounter}/reviews` | `rm.rawat-jalan.reviews.store` |
| POST | `/rm/rawat-jalan/{encounter}/signoff` | `rm.rawat-jalan.signoff` |
| POST | `/rm/rawat-jalan/amendments/{amendmentRequest}/reviews` | `rm.rawat-jalan.amendments.reviews.store` |
| POST | `/rm/rawat-jalan/amendments/{amendmentRequest}/signoff` | `rm.rawat-jalan.amendments.signoff` |

Nav: Pendaftaran / Pemeriksaan / RM point at these dedicated routes (other categories remain `/modul/{slug}`).

## Not in this phase

- SEP/BPJS production eligibility
- ICD coding UI
- Pharmacy and charges (no billing/LIS/PACS/RIS)
- Preliminary lab results, final-result amendment, encounter reopen and lab-order cancellation
- Antrean / work-queue MVP modules (do not restore)

## Current bounded depth slice

**Structured Outpatient Documentation and RM Completeness v1** is implemented as this phase's current bounded depth slice, not as Radiology or a claim of a complete clinical record. Its [functional-requirements pack](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md), [wireframe handoff](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md), and [bounded implementation decision](../phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md) define the engineering boundary. Clinical/RMIK acceptance, broader field and supervision decisions, PAR-CLN-004/PAR-RMIK-001 acceptance, and DEC-016 status remain unchanged.

The local depth slice now also includes the exact [post-closure amendment engineering authorization](../phase-1/OUTPATIENT_POST_CLOSURE_AMENDMENT_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-30.md): an append-only physician correction chain plus renewed RMIK review that preserves the original closed record. Local implementation and cross-engine concurrency verification are complete; Clinical/RMIK acceptance, parity acceptance, hosted migration, and deployment remain open. Evidence: [`../../operations/T1_LOCAL_OUTPATIENT_POST_CLOSURE_AMENDMENT_EVIDENCE_2026-08-30.md`](../../operations/T1_LOCAL_OUTPATIENT_POST_CLOSURE_AMENDMENT_EVIDENCE_2026-08-30.md).

The cross-setting extension now includes the bounded [radiology order/report v1 engineering authorization](../phase-1/CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-08-31.md). It is locally implemented and verified on disposable PostgreSQL 17.10 and MySQL 8.4.11 databases. Radiology-owner acceptance, parity acceptance, hosted migration, and deployment remain open; PACS/RIS and external integrations are outside this slice. Evidence: [`../../operations/T1_LOCAL_CROSS_SETTING_RADIOLOGY_EVIDENCE_2026-09-01.md`](../../operations/T1_LOCAL_CROSS_SETTING_RADIOLOGY_EVIDENCE_2026-09-01.md).

Facilitator script: `docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`  
**Full handoff (start here for other humans/agents):** `docs/operations/HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md`

Current exact-SHA deployment and schema reconciliation: `docs/operations/T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`. Focused authenticated structured-v1 UAT and Clinical/RMIK acceptance remain open.

## Evidence

- `tests/Feature/Outpatient/OutpatientFlowTest.php`
- `tests/Feature/Outpatient/OutpatientLabFlowTest.php`
- `tests/Feature/Outpatient/OutpatientLifecycleContractTest.php` (closure and final/late/duplicate-result rules)
- `tests/Feature/Outpatient/StructuredOutpatientDocumentationTest.php` (versioned documents, automatic completeness review, sign-off, and closed-record access)
- `tests/Feature/Outpatient/OutpatientPostClosureAmendmentRequestTest.php` (request/decision/addendum lifecycle, source immutability, authorization, audit, replay and reset)
- `tests/Feature/Outpatient/OutpatientRmAmendmentReviewTest.php` (canonical fingerprint, renewed review/sign-off, terminality, active-lab block, aggregate invariants and rollback)
- `tests/Feature/Radiology/CrossSettingRadiologyWorkflowTest.php` (RJ/IGD/RI order-to-acknowledgement lifecycle, role boundaries, amendments, cancellation/closure blockers, replay and reset)
- `tests/Documentation/LocalRadiologyPortabilityHarnessContractTest.rb` and `scripts/rehearse-local-radiology-portability.rb` (disposable PostgreSQL 17.10 and MySQL 8.4.11 portability evidence)
- `tests/Documentation/LocalOutpatientAmendmentConcurrencyHarnessContractTest.rb` and `scripts/rehearse-local-outpatient-amendment-concurrency.rb` (independent-process PostgreSQL/MySQL race evidence)
- `tests/Feature/Registration/EncounterCancellationIntegrationReadModelTest.php` (active-list exclusion and direct-write denial)
- `tests/Feature/Registration/EncounterCancellationRegistrationProjectionTest.php` (shared RJ/IGD/RI registration projection)
- `tests/Feature/RebuildHomeTest.php`
- `tests/Feature/Simulation/SimulationResetCommandTest.php`
- `docs/operations/T1_LOCAL_CURRENT_MANIFEST_PORTABILITY_EVIDENCE_2026-08-27.md` (current bound backend bytes: fresh migration, full suite, and E2E-01/02/03/04/05/12/15/16 focused slices on a harness-owned PostgreSQL 17.10 private cluster and isolated exact MySQL 8.4.11 server; pre/post binding and semantic manifest gates; not hosted or accepted)
