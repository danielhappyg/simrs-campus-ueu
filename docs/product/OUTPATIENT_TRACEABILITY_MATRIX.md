# Outpatient Requirements Traceability Matrix

- **Version:** 2.0
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
| EMR-001     | Blueprint stages 1–10                                             | Patient, Encounter, ClinicalEntry, OutpatientEarlyDeparture, Pharmacy, RecordQualityReview | E2E-01, E2E-06, E2E-07, E2E-11 | Registration, Clinical, Pharmacy, RMIK | IN PROGRESS³   |
| EMR-002     | Registration stage and duplicate exception                        | SyntheticPatient, PatientIdentifier, AppointmentRegistration                   | REG-01, REG-02, SAF-01, E2E-06, E2E-07 | Patient Identity                  | IN PROGRESS³   |
| EMR-003     | Documentation state model and longitudinal record                 | ClinicalEntryVersion, ReviewAction                                             | DOC-01, E2E-11                    | Clinical Documentation                 | IN PROGRESS³   |
| EMR-004     | Shared handoff contracts                                          | Patient/Encounter foreign-key boundary                                         | E2E-01, EDU-01                    | Cross-module foundation                | IN PROGRESS³   |
| EMR-005     | RMIK review stage                                                 | RecordQualityReview, CodeAssignment                                            | RMIK-01, RMIK-02                  | RMIK                                   | IN PROGRESS³   |
| EMR-006     | Source-linked coding rule                                         | CodeAssignment source/version                                                  | RMIK-03                           | RMIK                                   | IN PROGRESS³   |
| EMR-007     | Context policies and operational safeguards                       | Assignment, AuditEvent, private storage metadata                               | AUTH-01–05, AUD-01, OPS-01        | Identity/Audit/Operations              | IN PROGRESS³   |
| EMR-008     | Versioned completeness review                                     | RecordQualityReview/findings                                                   | RMIK-02                           | RMIK                                   | IN PROGRESS³   |
| EMR-009     | Separate create/review/approve/amend permissions                  | Assignment, ClinicalEntryVersion, ReviewAction                                 | DOC-02, AUTH-01–04, E2E-05        | Identity/Clinical                      | IN PROGRESS³   |
| EMR-010     | Honest simulation attestation                                     | ReviewAction                                                                   | DOC-03, SAF-02                    | Supervision                            | IN PROGRESS³   |
| COD-001     | Versioned ICD-10/ICD-9-CM source-linked coding                    | TerminologyRelease, CodeAssignment                                             | RMIK-01, RMIK-03, RMIK-07         | Terminology/RMIK                       | IN PROGRESS³   |
| COD-002     | SATUSEHAT-compatible classification metadata boundary             | TerminologyRelease/Concept, adapter mapping                                    | DATA-01, RMIK-01, INT-01          | Terminology/Integration                | IN PROGRESS³   |
| COD-003     | Human-reviewed ranked coding candidates                           | SuggestionRun/Candidate/Decision                                               | RMIK-04, RMIK-05, RMIK-08, SAF-04 | RMIK                                   | IN PROGRESS² ³ |
| COD-004     | Reproducible releases, engines, and stale-source handling         | Release hash, engine version, candidate snapshot                               | RMIK-06, RMIK-07, AUD-01          | Terminology/RMIK/Audit                 | IN PROGRESS³   |
| EDU-001     | Shared session/service/debrief model                              | Scenario, SimulationSession, Assignment, AuditEvent                            | E2E-01, EDU-01, EDU-02            | Teaching                               | IN PROGRESS³   |
| EDU-002     | Interprofessional case and handoffs                               | Assignment, Encounter                                                          | EDU-01                            | Teaching                               | IN PROGRESS³   |
| EDU-003     | Contextual competence/supervision policy                          | Assignment, task capabilities, supervision link                                | AUTH-01–05                        | Identity/Teaching                      | IN PROGRESS³   |
| EDU-004     | Activity and supervisor event history                             | ReviewAction, AuditEvent                                                       | EDU-02, AUD-01                    | Teaching/Audit                         | IN PROGRESS³   |
| EDU-005     | Permanent simulation boundary                                     | Environment mode and synthetic flags                                           | SAF-01–03                         | Platform                               | IN PROGRESS³   |
| EDU-006     | Product-owner-led autonomous build                                | Governance decision-rights table                                               | Checkpoint records                | Project governance                     | IMPLEMENTED¹   |
| EDU-007     | Planned, shared, attributable debrief notes                       | DebriefNote/DebriefNoteVersion                                                 | EDU-03, AUTH-01–03, AUD-01        | Teaching/Debrief                       | IN PROGRESS³   |
| EDU-008     | Non-scoring scenario rubric references                            | Scenario rubric-reference configuration                                        | EDU-04, VAL-T03                   | Teaching/Scenario                      | IN PROGRESS² ³ |
| OPD-001     | Outpatient encounter stages                                       | Encounter/service type/disposition, OutpatientEarlyDeparture                   | E2E-01, E2E-06, E2E-07           | Encounter                              | IN PROGRESS³   |
| OPD-002     | Stable Patient/Encounter mapping                                  | Internal IDs plus adapter mapping metadata                                     | INT-01                            | Interoperability                       | IN PROGRESS³   |
| OPD-003     | First-class outpatient concepts                                   | Observation, Condition, ServiceRequest, Result, Medication, Composition source | E2E-01, INT-01                    | Clinical/Integration                   | IN PROGRESS³   |
| OPD-004     | Terminology/unit separation                                       | Code and quantity conventions                                                  | DATA-01, NUR-01, RMIK-01          | Terminology                            | IN PROGRESS³   |
| OPD-005     | Intake/safety screen plus human disposition after escalation       | Intake decision, exact source version/hash, append-only safety disposition      | NUR-01, UX-01, E2E-02             | Nursing/Clinical                       | IN PROGRESS³   |
| OPD-006     | Configurable/unvalidated questions; no coded clinical thresholds    | Ruleset version, responses, and attributable human workflow outcome             | E2E-02, SAF-04                    | Scenario/Nursing                       | IN PROGRESS² ³ |
| OPD-007     | Concrete reference before broad review                            | Three-checkpoint validation                                                    | Scenario-linked UAT feedback      | Product delivery                       | IMPLEMENTED¹   |
| OPD-008     | Finalized watermarked report projections                          | Approved/current source projection; no stored signed artifact                  | REP-01–03, SAF-02, VAL-U05        | Reporting/Teaching/Audit               | IN PROGRESS² ³ |
| OPD-009     | Distinct attended patient-requested departure with `aadvice` mapping | OutpatientEarlyDeparture, Encounter/Appointment terminal states, source snapshot/hash | E2E-07, AUD-01, SAF-04, VAL-A17 | Clinical/Encounter/Audit               | IN PROGRESS² ³ |
| PHA-001     | Pharmacy review/dispense scope                                    | PharmacyReview, Intervention, Dispense                                         | PHA-01–03                         | Pharmacy                               | IN PROGRESS³   |
| PHA-002     | Three separate review domains                                     | PharmacyReview domain outcomes                                                 | PHA-01                            | Pharmacy                               | IN PROGRESS³   |
| PHA-003     | Derive administrative source data                                 | Patient/Encounter/MedicationRequest/author                                     | PHA-01                            | Pharmacy                               | IN PROGRESS³   |
| PHA-004     | Authored pharmaceutical/clinical outcomes, no automatic clearance | PharmacyReview and comments                                                    | PHA-01, PHA-02, SAF-04            | Pharmacy                               | IN PROGRESS² ³ |
| PHA-005     | Receive/review/prepare/check/dispense truth                       | Dispense and simulated StockMovement                                           | PHA-03                            | Pharmacy/Inventory                     | IN PROGRESS³   |
| SAF-001     | Reject real-data input                                            | Synthetic flags, fixture provenance, identifier namespace                      | SAF-01                            | Platform/Data                          | IN PROGRESS³   |
| SAF-002     | Honest labels/signatures/integrations                             | Environment mode, ReviewAction, integration status                             | DOC-03, SAF-02, SAF-03            | Platform/UI                            | IN PROGRESS³   |
| SAF-003     | No autonomous clinical decision support                           | Data-quality/clinical-rule separation; unselected human disposition             | E2E-02, SAF-04                    | Platform/Clinical                      | IN PROGRESS³   |

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

Active/completed longitudinal-record evidence is held separately in:

- `EncounterRecordTimelineTest` for exact-case or session-wide facilitator access, active/completed-session reads, deterministic curated-event projection, a 300-event cap, minimized view-audit metadata, cancellation-reason non-disclosure, and overview navigation governed by the same policy as the route; and
- `EncounterTimeline` React/axe coverage for the permanent simulation/non-legal boundary, source/version/actor/clinical/recorded time attribution, URL-synchronized stage/program filters, announced counts, truncation/empty states, and conditional navigation.

Shared teaching-evidence coverage is held in:

- `DebriefNoteWorkflowTest` for the separate write capability, finalized/active-session gate, create/revision idempotency, immutable successor history, required attestation/change reason, participant read access, wrong-context denials, non-scoring rubric provenance, and minimized audit metadata; and
- `EncounterDebrief` React/axe coverage for shared-note latest/history presentation, read-only participant behavior, facilitator authoring attestation, and explicit pending/non-scoring rubric language.

Finalized-report coverage is held in:

- `FinalizedEncounterReportTest` for the separate `report.view` capability, exact case scope, pre-finalization conflict, completed-session reads, no-store/no-index headers, source-derived outpatient content, human-reviewed ICD-10/ICD-9-CM presentation, shared-note version history, non-scoring rubric status, raw-audit exclusion, and minimized `report.rendered` metadata; and
- report-view and debrief/encounter navigation contracts for permanent simulation/legal-boundary language and browser print entry points.

Local-interoperability coverage is held in:

- `OutpatientInteroperabilityPreviewTest` for deterministic repeated mapping, FHIR ID/full-URL/reference structure, the 18-resource finalized reference set, six source-defined vital Observations, human-approved ICD-10/ICD-9-CM provenance, exact source paths for text-only mapping gaps, the permanent no-endpoint/no-transmission boundary, `report.view` plus finalized/exact-case gates, private/no-store/no-index headers, minimized `interop.preview_viewed` metadata, and conditional navigation; and
- `OutpatientInteroperabilityPreview` React/axe coverage for the UEU mapping ledger, permanent simulation/`BELUM DIKIRIM` boundary, validation warnings, resource inventory, source index, JSON containment, absence of transmission actions, and accessible semantics.

This bounded evidence advances `INT-01`, `INT-02`, `OPD-002`, `OPD-003`, `OPD-004`, and `SAF-03` but leaves them `IN PROGRESS`: national identities, an approved external validator/profile contract, institutional transmission policy, and SATUSEHAT sandbox evidence remain absent by design. See [ADR-007](../adr/ADR-007-LOCAL-INTEROPERABILITY-PREVIEW.md).

Full-journey/recovery evidence is held in:

- `CompleteReferenceOutpatientJourneyCommandTest` for the synthetic-only/non-production gate, missing-release preflight, partially progressed refusal, complete cross-domain finalization, manual human coding, debrief-task release, and zero-change finalized rerun; and
- `CloneReferenceSessionCommandTest` for fail-closed pristine-source validation, new/remapped session-case-assignment-task-stock identities, immediate-source provenance, absence of progressed-state copy, source immutability, bounded overdue no-show preparation, minimized output, unsafe/invalid/duplicate/progressed refusal, and late-failure transaction rollback; and
- the `001300` local MySQL rehearsal for exact official-workbook imports, finalized source data, byte-identical schema/data restoration, critical relationship checks, restored no-op behavior, and HTTP 200 from `/up`.

Checkpoint preparation is held in the [Outpatient Checkpoint 2 UAT Facilitator Guide](../operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md) and its [blank UAT Record Template](../operations/OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md). Together they bind the ten demo roles to the shared-case sequence, separate diagnosis/procedure correction fixtures, default-`NOT RUN` results, one issue per observation, unresolved-risk ownership, stop rules, known limitations, and Daniel's final decision classifications. Their existence does not constitute stakeholder evidence or acceptance.

Earlier domain-spine evidence is held in:

- `SyntheticDataGuardTest`;
- `WorkQueueTest` for active-session-only assignment discovery, automatic single-session selection, explicit fail-closed multi-session selection, exact authorized task/summary scoping, unavailable/malformed selector denial, and minimized audit metadata, plus `WorkQueue` React/axe coverage for a named native selector, selection-required state, URL navigation, and task-level session provenance;
- `OutpatientRegistrationTest` for synthetic identity/registration boundaries, server-issued request keys, single-case enforcement, and the booked workspace state;
- `AppointmentCheckInTest` for transactional and idempotent check-in, the `PLANNED` to `ARRIVED` transition, clinic-queue creation, and the post-check-in disabled registration state;
- `AppointmentTerminationTest` for authorized and transactional `CANCELLED`/`NO_SHOW` outcomes, coherent source-state/time guards, immutable first provenance, idempotency/conflict behavior, completed-history preservation, unfinished-task/active-queue termination, public-queue minimization, and server-derived action flags for `E2E-06` and `AUD-01`;
- `VisitTerminationDialog` axe/submission coverage plus `RegistrationWorkspace` React coverage for server-allowed actions, terminal/later-state suppression, the one-case explanation, disabled creation fieldset, enabled active Check-in action, and exact check-in route;
- `EncounterStateMachineTest`;
- `WorkTaskInvariantTest`;
- `PatientContextBanner` component accessibility test; and
- SQLite plus MySQL migration checks in the delivery workflow.

Human safety-disposition evidence is held in:

- `OutpatientSafetyDispositionWorkflowTest` for exact approved-source/hash binding, append-only attribution, supervisor/facilitator scope, task creation, resume/transfer effects, idempotency, competing-decision rejection, minimized audit, and full transactional rollback;
- related nursing/work-queue feature coverage for escalation blocking, authorized routes, private response headers, read-only post-decision access, and exact task actions; and
- `SafetyDisposition` React/axe coverage plus a fresh 1280×720 and 390×844 browser rehearsal for permanent non-emergency/non-recommendation language, no default outcome, exact provenance, a supervisor-authored resume, medical-task release, contained 44-pixel interaction targets, and an empty warning/error console.

This makes the reference `E2E-02` branch executable, but `OPD-005`, `OPD-006`, and `SAF-003` remain `IN PROGRESS` because `VAL-A01`–`VAL-A04` and faculty acceptance of the teaching content/disposition vocabulary are still pending.

Dense-form protection evidence is held in `HistoryTraversalCoordinator` unit coverage, the bounded clinical-draft recovery feature contract, `UnsavedChangesGuard` React/axe coverage, and the nursing, medical-assessment, and encounter-closure integrations. It proves position-only history marking, dirty Back/Forward interception before Inertia page replacement, current-entry restoration, exact-target replay, a visible dirty state, cancellable Inertia navigation, ignored ordinary `POST` submissions/prefetches, explicit discard, in-flight action locking, save-before-replay sequencing, generic validation/HTTP/network/cancellation failure handling, generic no-store `401/419` recovery without login-page replacement or content echo, separate-tab reauthentication, same-encounter retry through the authoritative endpoint, native unload blocking while mounted, listener cleanup, and no browser draft storage. A 2026-07-21 isolated native-browser rehearsal additionally proved visible-link plus marked Back/Forward interception, all three choices, append-only save-before-leave, no-version local discard, generic expired-session recovery without clinical-text echo, same-account reauthentication, authorized retry, and 390×844 containment/focus. A follow-up deliberate local-server outage proved that a real network failure keeps the exact local delta and retry on the encounter; restarting the same isolated server allowed the unchanged authoritative retry to create one new immutable version. Database evidence ended at three versions, three distinct hashes, and three minimized audit events with no clinical text in metadata. The retained console contained only the expected failed versions-endpoint request. Dirty reload blocking kept the form intact, but the harness could not inspect browser-owned unload wording. This advances `UX-04`; validation-failure rehearsal, every long form, browser-owned unload wording, full manual keyboard validation, and stakeholder acceptance remain open before faculty pilot.

Patient-requested early-departure evidence is held in:

- `OutpatientEarlyDepartureWorkflowTest` for distinct terminal states, append-only provenance, exact current-source completeness/hash binding, supervisor/facilitator authorization, confirmation/text validation, idempotency, competing-request rejection, queue/task effects, minimized audit, safe longitudinal projection, and full transactional rollback;
- direct workspace/overview feature coverage for private headers, eligible clinical-state gating, exact server-derived action visibility, wrong-role/revoked-assignment denial, and read-only post-record access; and
- `EarlyDeparture` plus encounter-overview React coverage for permanent simulation/non-recommendation/non-finalization language, an unchecked explicit confirmation, accessible required fields, source hashes, 44-pixel actions, and conditional navigation.

This makes the bounded `E2E-07` branch executable in automated tests. Faculty validation of the Indonesian vocabulary, authorized actor policy, form content, teaching usefulness, and incomplete-record RMIK handling remains open.

This evidence does **not** mark the full linked scenarios verified. Performed-procedure ICD-9-CM suggestion, decision, assignment, supervision, complete diagnosis/procedure source-correction journeys, stale-source invalidation, finalization gating, retained uninterrupted warning/error-console review across both fresh correction fixtures, a curated active/completed longitudinal record including a minimized early-departure event, a distinct finalized-event debrief, shared versioned facilitator notes with narrow-browser create/revise and participant read-only evidence, a non-scoring rubric reference, watermarked source-derived outpatient/debrief report working references, a draft checksummed synthetic retrieval baseline, local full-journey OPS-01 recovery evidence, the complete backend suite on real MySQL, repeated MySQL 8.4 CI, the active one-case registration/check-in path, bounded registrar cancellation/overdue no-show, and the bounded patient-requested early-departure branch are implemented. Daniel's Checkpoint 2 export selection, signed/persisted document or disclosure policy, stakeholder validation of cancellation/no-show and early-departure vocabulary/actor/incomplete-record policy plus longitudinal-record/debrief/note/report usefulness, an approved rubric/scoring policy, the procedure-correction responsibility policy, controlled synonyms/gold cases and the pilot threshold, remaining full-page native keyboard review, native print/PDF review, Hostinger staging/rollback, and multidisciplinary acceptance remain open. A minimized append-only audit record for authenticated 403 denials is implemented and covered for explicit HTTP denials and contextual authorization exceptions. See [ADR-004](../adr/ADR-004-FINALIZED-DEBRIEF-PROJECTION.md), [ADR-005](../adr/ADR-005-DEBRIEF-NOTES-AND-RUBRIC-REFERENCES.md), [ADR-006](../adr/ADR-006-FINALIZED-SIMULATION-REPORTING.md), [ADR-009](../adr/ADR-009-OUTPATIENT-EARLY-DEPARTURE.md), the [Computer-Assisted Coding Validation Record](../operations/COMPUTER_ASSISTED_CODING_VALIDATION.md), [Synthetic Coding Retrieval Baseline](../operations/CODING_GOLD_SET_BASELINE.md), and [Local MySQL and Recovery Validation](../operations/LOCAL_MYSQL_RECOVERY_VALIDATION.md).

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
| Recovery                 | ADR-001; Data Dictionary relationships                           | OPS-01, OPS-02         | Local backup/restore, read-only hosting preflight, verified CI release candidate, local fail-closed switch contract, and hosted deploy/rollback rehearsal |
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
