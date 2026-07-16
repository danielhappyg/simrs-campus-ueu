# Outpatient Requirements Traceability Matrix

- **Version:** 1.9
- **Purpose:** connect evidence to workflow, data, implementation, and verification

## 1. Traceability rule

A requirement is ready for implementation when it has:

1. a classification and source/decision in the evidence register;
2. an observable workflow or policy response;
3. defined data/provenance where applicable;
4. at least one acceptance scenario; and
5. no hidden clinical rule outside the validation register.

Implementation status values are `NOT STARTED`, `IN PROGRESS`, `IMPLEMENTED`, and `VERIFIED`. `IN PROGRESS` is used when bounded application code and automated evidence exist but one or more linked acceptance gates remain open.

## 2. Matrix

| Requirement | Workflow/policy response                                          | Canonical data                                                                 | Acceptance evidence               | Planned module                         | Status         |
| ----------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------ | --------------------------------- | -------------------------------------- | -------------- |
| EMR-001     | Blueprint stages 1–10                                             | Patient, Encounter, ClinicalEntry, Pharmacy, RecordQualityReview               | E2E-01, E2E-11                    | Registration, Clinical, Pharmacy, RMIK | IN PROGRESS³   |
| EMR-002     | Registration stage and duplicate exception                        | SyntheticPatient, PatientIdentifier, AppointmentRegistration                   | REG-01, REG-02, SAF-01            | Patient Identity                       | IN PROGRESS³   |
| EMR-003     | Documentation state model and longitudinal record                 | ClinicalEntryVersion, ReviewAction                                             | DOC-01, E2E-11                    | Clinical Documentation                 | IN PROGRESS³   |
| EMR-004     | Shared handoff contracts                                          | Patient/Encounter foreign-key boundary                                         | E2E-01, EDU-01                    | Cross-module foundation                | IN PROGRESS³   |
| EMR-005     | RMIK review stage                                                 | RecordQualityReview, CodeAssignment                                            | RMIK-01, RMIK-02                  | RMIK                                   | IN PROGRESS³   |
| EMR-006     | Source-linked coding rule                                         | CodeAssignment source/version                                                  | RMIK-03                           | RMIK                                   | IN PROGRESS³   |
| EMR-007     | Context policies and operational safeguards                       | Assignment, AuditEvent, private storage metadata                               | AUTH-01–04, AUD-01, OPS-01        | Identity/Audit/Operations              | IN PROGRESS³   |
| EMR-008     | Versioned completeness review                                     | RecordQualityReview/findings                                                   | RMIK-02                           | RMIK                                   | IN PROGRESS³   |
| EMR-009     | Separate create/review/approve/amend permissions                  | Assignment, ClinicalEntryVersion, ReviewAction                                 | DOC-02, AUTH-01–04, E2E-05        | Identity/Clinical                      | IN PROGRESS³   |
| EMR-010     | Honest simulation attestation                                     | ReviewAction                                                                   | DOC-03, SAF-02                    | Supervision                            | IN PROGRESS³   |
| COD-001     | Versioned ICD-10/ICD-9-CM source-linked coding                    | TerminologyRelease, CodeAssignment                                             | RMIK-01, RMIK-03, RMIK-07         | Terminology/RMIK                       | IN PROGRESS³   |
| COD-002     | SATUSEHAT-compatible classification metadata boundary             | TerminologyRelease/Concept, adapter mapping                                    | DATA-01, RMIK-01, INT-01          | Terminology/Integration                | IN PROGRESS³   |
| COD-003     | Human-reviewed ranked coding candidates                           | SuggestionRun/Candidate/Decision                                               | RMIK-04, RMIK-05, RMIK-08, SAF-04 | RMIK                                   | IN PROGRESS² ³ |
| COD-004     | Reproducible releases, engines, and stale-source handling         | Release hash, engine version, candidate snapshot                               | RMIK-06, RMIK-07, AUD-01          | Terminology/RMIK/Audit                 | IN PROGRESS³   |
| EDU-001     | Shared session/service/debrief model                              | Scenario, SimulationSession, Assignment, AuditEvent                            | E2E-01, EDU-01, EDU-02            | Teaching                               | IN PROGRESS³   |
| EDU-002     | Interprofessional case and handoffs                               | Assignment, Encounter                                                          | EDU-01                            | Teaching                               | IN PROGRESS³   |
| EDU-003     | Contextual competence/supervision policy                          | Assignment, task capabilities, supervision link                                | AUTH-01–04                        | Identity/Teaching                      | IN PROGRESS³   |
| EDU-004     | Activity and supervisor event history                             | ReviewAction, AuditEvent                                                       | EDU-02, AUD-01                    | Teaching/Audit                         | IN PROGRESS³   |
| EDU-005     | Permanent simulation boundary                                     | Environment mode and synthetic flags                                           | SAF-01–03                         | Platform                               | IN PROGRESS³   |
| EDU-006     | Product-owner-led autonomous build                                | Governance decision-rights table                                               | Checkpoint records                | Project governance                     | IMPLEMENTED¹   |
| EDU-007     | Planned, shared, attributable debrief notes                       | DebriefNote/DebriefNoteVersion                                                 | EDU-03, AUTH-01–03, AUD-01        | Teaching/Debrief                       | IN PROGRESS³   |
| EDU-008     | Non-scoring scenario rubric references                            | Scenario rubric-reference configuration                                        | EDU-04, VAL-T03                   | Teaching/Scenario                      | IN PROGRESS² ³ |
| OPD-001     | Outpatient encounter stages                                       | Encounter/service type/disposition                                             | E2E-01                            | Encounter                              | IN PROGRESS³   |
| OPD-002     | Stable Patient/Encounter mapping                                  | Internal IDs plus adapter mapping metadata                                     | INT-01                            | Interoperability                       | IN PROGRESS³   |
| OPD-003     | First-class outpatient concepts                                   | Observation, Condition, ServiceRequest, Result, Medication, Composition source | E2E-01, INT-01                    | Clinical/Integration                   | IN PROGRESS³   |
| OPD-004     | Terminology/unit separation                                       | Code and quantity conventions                                                  | DATA-01, NUR-01, RMIK-01          | Terminology                            | IN PROGRESS³   |
| OPD-005     | Intake/safety-screen stage and language                           | Intake fields and decision                                                     | NUR-01, UX-01                     | Nursing                                | IN PROGRESS³   |
| OPD-006     | Configurable/unvalidated clinical thresholds                      | Ruleset version and question responses                                         | E2E-02, SAF-04                    | Scenario/Nursing                       | IN PROGRESS² ³ |
| OPD-007     | Concrete reference before broad review                            | Three-checkpoint validation                                                    | Scenario-linked UAT feedback      | Product delivery                       | IMPLEMENTED¹   |
| OPD-008     | Finalized watermarked report projections                          | Approved/current source projection; no stored signed artifact                  | REP-01–03, SAF-02, VAL-U05        | Reporting/Teaching/Audit               | IN PROGRESS² ³ |
| PHA-001     | Pharmacy review/dispense scope                                    | PharmacyReview, Intervention, Dispense                                         | PHA-01–03                         | Pharmacy                               | IN PROGRESS³   |
| PHA-002     | Three separate review domains                                     | PharmacyReview domain outcomes                                                 | PHA-01                            | Pharmacy                               | IN PROGRESS³   |
| PHA-003     | Derive administrative source data                                 | Patient/Encounter/MedicationRequest/author                                     | PHA-01                            | Pharmacy                               | IN PROGRESS³   |
| PHA-004     | Authored pharmaceutical/clinical outcomes, no automatic clearance | PharmacyReview and comments                                                    | PHA-01, PHA-02, SAF-04            | Pharmacy                               | IN PROGRESS² ³ |
| PHA-005     | Receive/review/prepare/check/dispense truth                       | Dispense and simulated StockMovement                                           | PHA-03                            | Pharmacy/Inventory                     | IN PROGRESS³   |
| SAF-001     | Reject real-data input                                            | Synthetic flags, fixture provenance, identifier namespace                      | SAF-01                            | Platform/Data                          | IN PROGRESS³   |
| SAF-002     | Honest labels/signatures/integrations                             | Environment mode, ReviewAction, integration status                             | DOC-03, SAF-02, SAF-03            | Platform/UI                            | IN PROGRESS³   |
| SAF-003     | No autonomous clinical decision support                           | Data-quality/clinical-rule separation                                          | SAF-04                            | Platform                               | IN PROGRESS³   |

1. `IMPLEMENTED` here means recorded in approved project/product governance documentation; it does not mean application code exists.
2. Human clinical validation remains pending by design. The application can implement the configurable boundary and non-decision behavior before content is approved.
3. `IN PROGRESS` means bounded application code and automated evidence now exist, but the full requirement or all linked acceptance scenarios are not complete. ADR-003 records the implemented boundary and current limitations.

## 3. Current implementation evidence

The codebase now provides automated evidence from the outpatient domain spine through clinical documentation, results, pharmacy, closure, RMIK completeness, and human-reviewed ICD-10 diagnosis plus ICD-9-CM performed-procedure coding. Coding evidence is held in:

- `TerminologyImportTest` for checksum/schema validation, atomic rejection, activation/supersession, immutability, and separate ICD-10/ICD-9-CM releases;
- `RecordQualityWorkflowTest` for diagnosis/procedure exact-source suggestions, honest no-candidate behavior, manual attribution, cross-system rejection, coder/supervisor separation, the separate complete coder-requested diagnosis and procedure correction chains, scope-denial behavior, diagnosis and procedure stale-source invalidation, immutable hashes, and all-current-source finalization gating; and
- `CodingGoldSetEvaluationTest` plus the versioned retrieval dataset for exact-match candidate-pool regression, tied-result review labelling, release/hash binding, separate diagnosis/procedure top-1/top-5 metrics, negative controls, and exclusion of pending expert proposals; and
- `CodingWorkspace` React/axe coverage for visible diagnosis/procedure source and release provenance, the mandatory-human-review warning, no bulk acceptance, and accessible landmark structure.

Finalization/debrief evidence is held in:

- `EncounterDebriefTest` for the `FINALIZED` release gate, correct participant access, wrong-role/wrong-session/wrong-case denials, completed-session read access, deterministic clinical/recorded-time order, exact source/version attribution, personal task completion, material-event allowlisting, and non-disclosure of raw audit/security metadata;
- the end-to-end coding finalization test for release of capability-checked debrief tasks to all eligible participant/instructor assignments; and
- `EncounterDebrief` React/axe coverage for permanent simulation labelling, actor/assignment/source/time visibility, presentation-only URL filters, announced result counts, and accessible structure.

Shared teaching-evidence coverage is held in:

- `DebriefNoteWorkflowTest` for the separate write capability, finalized/active-session gate, create/revision idempotency, immutable successor history, required attestation/change reason, participant read access, wrong-context denials, non-scoring rubric provenance, and minimized audit metadata; and
- `EncounterDebrief` React/axe coverage for shared-note latest/history presentation, read-only participant behavior, facilitator authoring attestation, and explicit pending/non-scoring rubric language.

Finalized-report coverage is held in:

- `FinalizedEncounterReportTest` for the separate `report.view` capability, exact case scope, pre-finalization conflict, completed-session reads, no-store/no-index headers, source-derived outpatient content, human-reviewed ICD-10/ICD-9-CM presentation, shared-note version history, non-scoring rubric status, raw-audit exclusion, and minimized `report.rendered` metadata; and
- report-view and debrief/encounter navigation contracts for permanent simulation/legal-boundary language and browser print entry points.

Full-journey/recovery evidence is held in:

- `CompleteReferenceOutpatientJourneyCommandTest` for the synthetic-only/non-production gate, missing-release preflight, partially progressed refusal, complete cross-domain finalization, manual human coding, debrief-task release, and zero-change finalized rerun; and
- the `001300` local MySQL rehearsal for exact official-workbook imports, finalized source data, byte-identical schema/data restoration, critical relationship checks, restored no-op behavior, and HTTP 200 from `/up`.

Checkpoint preparation is held in the [Outpatient Checkpoint 2 UAT Facilitator Guide](../operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md), which binds the ten demo roles to the shared-case sequence, separate diagnosis/procedure correction fixtures, structured issue evidence, stop rules, known limitations, and Daniel's final decision classifications. The guide's existence does not constitute stakeholder acceptance.

Earlier domain-spine evidence is held in:

- `SyntheticDataGuardTest`;
- `OutpatientRegistrationTest` for synthetic identity/registration boundaries, server-issued request keys, single-case enforcement, and the booked workspace state;
- `AppointmentCheckInTest` for transactional and idempotent check-in, the `PLANNED` to `ARRIVED` transition, clinic-queue creation, and the post-check-in disabled registration state;
- `RegistrationWorkspace` React coverage for the one-case explanation, disabled creation fieldset, enabled active Check-in action, and exact check-in route;
- `EncounterStateMachineTest`;
- `WorkTaskInvariantTest`;
- `PatientContextBanner` component accessibility test; and
- SQLite plus MySQL migration checks in the delivery workflow.

This evidence does **not** mark the full linked scenarios verified. Performed-procedure ICD-9-CM suggestion, decision, assignment, supervision, dedicated source correction, stale-source invalidation, finalization gating, a curated finalized-event debrief, shared versioned facilitator notes with narrow-browser create/revise and participant read-only evidence, a non-scoring rubric reference, watermarked source-derived outpatient/debrief report working references, a draft checksummed synthetic retrieval baseline, local full-journey OPS-01 recovery evidence, the complete backend suite on real MySQL, and the active one-case registration/check-in path are implemented. Daniel's Checkpoint 2 export selection, signed/persisted document or disclosure policy, stakeholder validation of debrief/note/report usefulness and release policy, an approved rubric/scoring policy, the procedure-correction responsibility policy, controlled synonyms/gold cases and the pilot threshold, remaining full-page keyboard and correction-route console review, the configured MySQL 8.4 job's first remote run, Hostinger staging/rollback, and multidisciplinary acceptance remain open. A minimized append-only audit record for authenticated 403 denials is implemented and covered for explicit HTTP denials and contextual authorization exceptions. See [ADR-004](../adr/ADR-004-FINALIZED-DEBRIEF-PROJECTION.md), [ADR-005](../adr/ADR-005-DEBRIEF-NOTES-AND-RUBRIC-REFERENCES.md), [ADR-006](../adr/ADR-006-FINALIZED-SIMULATION-REPORTING.md), the [Computer-Assisted Coding Validation Record](../operations/COMPUTER_ASSISTED_CODING_VALIDATION.md), [Synthetic Coding Retrieval Baseline](../operations/CODING_GOLD_SET_BASELINE.md), and [Local MySQL and Recovery Validation](../operations/LOCAL_MYSQL_RECOVERY_VALIDATION.md).

## 4. Cross-cutting quality coverage

| Quality concern          | Governing artifacts                                              | Acceptance scenarios   | Required delivery evidence                                                                  |
| ------------------------ | ---------------------------------------------------------------- | ---------------------- | ------------------------------------------------------------------------------------------- |
| Contextual authorization | Role Matrix POL-001–012                                          | AUTH-01–04             | Policy/feature tests plus direct API denial tests                                           |
| Immutable provenance     | Blueprint documentation state; Data Dictionary sections 5 and 10 | DOC-01–03, AUD-01      | Versioning and audit tests                                                                  |
| Transaction integrity    | Pharmacy/data model                                              | PHA-03                 | Database transaction failure test                                                           |
| Synthetic-data safety    | Evidence SAF-001/002; Data Dictionary section 12                 | SAF-01–03              | Seeder/import/configuration tests and UI/export checks                                      |
| No clinical overclaim    | Evidence SAF-003; Data Dictionary section 13                     | E2E-02, SAF-04         | Content/design review and feature tests                                                     |
| Assisted-coding safety   | Evidence COD-001–004; Computer-Assisted Coding Specification     | RMIK-01, RMIK-03–10    | Import/search/suggestion/decision/correction tests, synthetic gold-set report, and RMIK UAT |
| Accessibility            | Validation VAL-U04                                               | UX-01–04               | Automated axe checks plus keyboard/manual review                                            |
| Recovery                 | ADR-001; Data Dictionary relationships                           | OPS-01, OPS-02         | Backup/restore and deploy/rollback rehearsal                                                |
| Interoperability honesty | Evidence OPD-002–004                                             | INT-01, INT-02, SAF-03 | Local mapping tests; no production network dependency                                       |

## 5. Change control

When a source, decision, or accepted workflow changes, the pull request must identify:

- affected requirement IDs;
- source/version or product decision causing the change;
- blueprint/data/policy changes;
- acceptance scenarios added or modified;
- migration/backfill effect if code already exists;
- whether an assumption was resolved or newly introduced; and
- Daniel's scope/release decision for a material change.
