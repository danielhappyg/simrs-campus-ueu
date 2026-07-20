# Outpatient Acceptance Scenarios

- **Version:** 1.1 reference baseline
- **Purpose:** executable contract for product, policy, provenance, simulation safety, and the first outpatient vertical slice
- **Test data:** generated synthetic fixtures only

## 1. Test conventions

Each scenario must eventually be represented at the lowest reliable automated level and, where human interpretation is required, in the UAT script.

| Test type   | Purpose                                                                                            |
| ----------- | -------------------------------------------------------------------------------------------------- |
| Domain/unit | State transitions, rules, terminology/value objects, versioning.                                   |
| Feature/API | Authentication, contextual authorization, validation, transactions, audit, and response contracts. |
| Browser E2E | Critical multi-role journey, accessible interaction, persistent context, and handoffs.             |
| Security    | Direct endpoint denial, session/CSRF controls, data exposure, import/export boundaries.            |
| Manual UAT  | Clinical/teaching plausibility, terminology, handoff usability, and debrief usefulness.            |
| Operations  | Build, migration, health/readiness, backup/restore, deploy, smoke test, rollback.                  |

Critical automated tests must not depend on a public external service. SATUSEHAT/BPJS behavior uses deterministic local fakes until a separately approved sandbox increment.

## 2. Reference fixture

The acceptance suite creates:

- scenario `OPD-REF-001`, version 1;
- active session `SIM-001` in `SIMULATION` mode;
- synthetic patient `PAT-SYN-001` with synthetic MRN/NIK-like identifier;
- outpatient encounter `ENC-SYN-001`;
- one assigned learner and supervisor per pilot discipline;
- one unrelated learner in another session;
- one inactive/unassigned account;
- one configurable medication, order/result, and completeness checklist;
- no real credentials, identifiers, endpoints, or patient-derived data.

## 3. End-to-end journey

### E2E-01 — complete one shared outpatient encounter

**Given** an active simulation session with assigned registration/RMIK, nursing, medicine, and pharmacy learners and supervisors
**When** the registrar verifies the synthetic patient, checks in the appointment, nursing submits an intake, medicine records an assessment and prescription, pharmacy reviews and dispenses, medicine closes the encounter, and RMIK completes coding and quality review
**Then** every record references the same patient, encounter, and session
**And** each handoff contains its required source data and provenance
**And** the encounter reaches `FINALIZED` only after required approvals
**And** the debrief timeline shows the chronological multi-professional history.

### E2E-02 — pause routine flow for an outpatient safety concern

**Given** nursing is performing the configured intake
**When** the learner selects `ESCALATE_TO_SUPERVISOR` and records a reason
**Then** the encounter moves to `ESCALATED`
**And** routine handoff is blocked until an authorized supervisor/facilitator records a disposition
**And** the software gives no diagnosis or treatment recommendation
**And** the decision, actor, time, and disposition are audited.

### E2E-03 — incorporate a corrected synthetic result

**Given** an active order and a preliminary/final synthetic result
**When** the facilitator releases a corrected version
**Then** the original result remains available as superseded
**And** the corrected result is visibly current
**And** the medical learner/supervisor must acknowledge the correction before closure
**And** any plan amendment creates a new attributable version.

### E2E-04 — resolve a pharmacy clarification

**Given** an approved-for-review prescription
**When** pharmacy records `CLARIFICATION_REQUIRED` for one item
**Then** the affected item cannot be marked dispensed
**And** medicine can respond without pharmacy editing the prescription
**And** a replacement/cancellation preserves the prior request and reason
**And** pharmacy can re-review the resulting current request.

### E2E-05 — correct a record after clinical closure

**Given** an encounter in `RECORD_REVIEW` and RMIK identifies a blocking documentation issue
**When** RMIK requests correction and the authorized author submits an amendment
**Then** the original approved version remains immutable
**And** the amendment links to the original and includes a reason
**And** discipline review and RMIK completeness checks rerun
**And** finalization remains blocked until the finding is resolved.

### E2E-06 — cancel without deleting history

**Given** a registered outpatient encounter with one completed action
**When** an authorized user cancels the encounter with a reason
**Then** the encounter reaches `CANCELLED`
**And** completed records and queue events remain attributable
**And** no ordinary user can hard-delete the encounter.

### E2E-07 — record patient-requested departure after clinical service begins

**Given** a checked-in encounter in an eligible active clinical state with current source versions
**When** the exact medical supervisor or session facilitator explicitly confirms `Pulang atas permintaan sendiri`, records the stated reason and factual communication summary, and submits one idempotent request
**Then** an append-only departure binds the actor, assignment, source encounter state, current source public IDs, versions, statuses, hashes, and server time
**And** the encounter and appointment reach `DEPARTED_ON_REQUEST`, active queue work ends, and unfinished tasks are cancelled without erasing completed work
**And** a competing request, stale or invented source, wrong role/context, revoked assignment, or ineligible source state is rejected transactionally
**And** the interface provides no safety verdict, treatment recommendation, routine closure, RMIK approval, coding assignment, finalization, report, debrief release, or transmission.

### E2E-11 — assemble the longitudinal record from source entries

**Given** multiple nursing, medical, pharmacy, result, review, and RMIK events with differing clinical and recorded times
**When** an authorized user opens the longitudinal record
**Then** the system renders a deterministic chronological timeline
**And** shows author, role, discipline, time, version, and review state
**And** never substitutes a manually copied summary for source provenance.

## 4. Registration and identity

### REG-01 — create a compliant synthetic registration

**Given** an assigned registrar and no duplicate candidate selected
**When** the registrar submits required synthetic identity/social data
**Then** a simulation MRN and invalid-for-production synthetic NIK-like identifier are issued in separate namespaces
**And** the patient is marked synthetic at database and UI levels
**And** the registration author and server time are recorded.

### REG-02 — resolve a possible duplicate explicitly

**Given** patient search finds a possible synthetic duplicate
**When** the registrar chooses an existing patient or creates a new patient with reason
**Then** the decision is audited
**And** no automatic merge occurs
**And** creating a new record without a required reason is rejected.

### REG-03 — restrict queue-display disclosure

**Given** a patient waits in the outpatient queue
**When** the public-display projection is requested
**Then** it contains only the ticket and configured minimal masked cue
**And** excludes diagnosis, NIK-like identifier, contact data, and clinical notes.

## 5. Nursing and medical documentation

### NUR-01 — submit nursing intake with typed observations

**Given** an assigned nursing learner in an active session
**When** the learner records required intake fields and observations
**Then** values retain their coded concepts, original numeric values, UCUM units where mapped, occurrence time, author, and source version
**And** missing required fields are reported before submission
**And** the system does not infer a diagnosis from the measurements.

### NUR-02 — distinguish allergy states

**Given** nursing records the allergy assessment
**When** the learner chooses known allergy, no known allergy reported, or not assessed
**Then** the selected state is explicit
**And** an absent database row is never rendered as “no allergy.”

### MED-01 — link diagnosis, plan, order, and prescription to authored assessment

**Given** an assigned medical learner has reviewed nursing intake
**When** the learner submits the assessment
**Then** diagnoses, orders, and medication requests link to the same encounter and source entry version
**And** coded concepts preserve system/code/display/version
**And** downstream roles can see source status without editing it.

### DATA-01 — preserve code and unit semantics

**Given** a coded observation, diagnosis, or medication concept
**When** the UI displays an Indonesian label
**Then** the stored code system, code, display, and version remain unchanged
**And** unit conversion, if supported later, records original and converted values rather than silently replacing the original.

## 6. Document lifecycle and supervision

### DOC-01 — preserve clear chronological authorship

**Given** a learner saves and submits a clinical entry
**Then** the system records author account, acting assignment, discipline, clinical occurrence time, recorded time, schema version, content version, and content hash
**And** the timeline identifies the exact reviewed version.

### DOC-02 — request correction without overwrite

**Given** a submitted or approved entry
**When** a supervisor requests changes
**Then** the reviewed version remains immutable
**And** the feedback is attributable
**And** the learner's correction creates a successor version with reason.

### DOC-03 — label internal sign-off honestly

**Given** a supervisor approves a learner version
**When** the approval appears on screen or export
**Then** it says `Persetujuan simulasi` or equivalent
**And** records authenticated reviewer and time
**And** does not claim to be a certified legal electronic signature.

### EDU-01 — share one case across professions

**Given** four program assignments in one session
**When** work crosses registration, nursing, medicine, pharmacy, and RMIK
**Then** each role sees only the minimum required prior information
**And** no user re-enters patient identity into a disconnected module.

### EDU-02 — record learner and supervisor activity

**Given** a learner submits work and a supervisor responds
**Then** assignment, submission, feedback, correction, approval, and attestation events are attributable and visible in debrief
**And** deleting or ending the session does not silently erase those events.

### EDU-03 — preserve a shared facilitator debrief note without changing the clinical record

**Given** an encounter is `FINALIZED` and the simulation session remains active
**When** an assignment with `debrief.write` creates a shared facilitator note
**Then** the note is stored outside the clinical-source timeline with author, assignment, type, version, content hash, and authored time
**And** every assignment authorized to view that case's debrief can read the latest version
**And** a learner or wrong-session/wrong-case assignment cannot create or revise it
**And** revising the note requires a reason and appends a successor version without overwriting the prior text
**And** a completed session remains readable but rejects new note versions.

### EDU-04 — show rubric provenance without inventing a score

**Given** a scenario version contains one or more rubric references
**When** an authorized participant opens the finalized debrief
**Then** each reference shows its code, title, version, validation status, source label, and linked learning outcomes
**And** a draft or pending reference is visibly labelled as not approved for grading
**And** the application creates no criterion score, total, grade, pass/fail result, or competence verdict.

## 7. Pharmacy

### PHA-01 — complete three-domain prescription review

**Given** a prescription is ready for pharmacy review
**When** the assigned pharmacy learner reviews it
**Then** administrative, pharmaceutical, and clinical domains each have an explicit outcome and comment where required
**And** overall outcome cannot be `ACCEPT` while a required domain is incomplete
**And** automated missing-data warnings are not represented as a clinical clearance.

### PHA-02 — retain a pharmacy intervention thread

**Given** pharmacy identifies an issue
**When** the learner opens an intervention
**Then** the affected request/version, category, question/recommendation, author, and time are stored
**And** each prescriber response is attributable
**And** neither participant can delete prior messages through the normal UI.

### PHA-03 — dispense transactionally and truthfully

**Given** an accepted prescription and available simulated stock
**When** pharmacy records preparation, final check, handoff, quantity, and counseling
**Then** dispense and stock movement commit atomically
**And** partial/no dispense records the actual outcome and reason
**And** failure does not leave a successful dispense with missing stock movement.

## 8. RMIK quality and coding

### RMIK-01 — assign a code with classification provenance

**Given** a clinician-authored diagnosis in an approved/current version
**When** the RMIK learner assigns a code
**Then** source statement/version, classification system/version, code/display, coder, and time are stored
**And** changing the UI language does not change the code.

### RMIK-02 — produce a reproducible completeness review

**Given** a clinically closed encounter
**When** RMIK runs the configured checklist
**Then** the checklist version and each finding are stored
**And** blocking findings prevent finalization
**And** rerunning after correction preserves both review histories.

### RMIK-03 — prevent code without source evidence

**Given** no clinician-authored source diagnosis/procedure exists
**When** a coder tries to submit an assignment
**Then** the API rejects it
**And** the coder cannot create a clinical diagnosis through the coding endpoint.

### RMIK-04 — generate ranked candidates without automatic assignment

**Given** an approved/current clinician-authored diagnosis or procedure version and the matching active terminology release
**When** an authorized RMIK learner requests coding suggestions
**Then** the system returns a bounded ranked list containing classification/version, code/display, source version, engine version, confidence band, and match evidence
**And** no `code_assignment` exists until the coder explicitly accepts a candidate or selects an alternative through manual search
**And** the interface provides no bulk or silent acceptance action.

### RMIK-05 — report ambiguity or no candidate honestly

**Given** the source statement is ambiguous, lacks required specificity, or has no reliable terminology match
**When** the candidate engine completes
**Then** it may return `no reliable candidate` or candidates marked `REVIEW_REQUIRED`
**And** it does not infer undocumented clinical detail
**And** the coder can search the source-matched classification manually
**And** a diagnosis source may enter the attributed diagnosis-correction route
**And** a procedure source may enter its separately attributed closure-correction route
**But** a procedure source cannot be misrouted into diagnosis authoring.

### RMIK-06 — invalidate stale suggestion context

**Given** a suggestion run or code assignment references a specific clinical source version
**When** that source is amended
**Then** the historical suggestion and decision remain immutable
**And** the linked assignment becomes `REVIEW_REQUIRED`
**And** operational orders and prescriptions cannot be changed through the coding-correction route
**And** the successor medical version and closure require their exact linked supervisor approvals
**And** the old RMIK approval is treated as stale until a successor completeness review is approved
**And** replacing a closure moves assignments linked to procedures in the old closure to `REVIEW_REQUIRED`
**And** regeneration creates a new run linked only to the successor source version.

### RMIK-07 — import terminology as an immutable validated release

**Given** an administrator stages an ICD workbook with the expected schema and declared classification
**When** validation succeeds
**Then** completely blank trailing rows are ignored and the system records filename, SHA-256, logical version, row counts, import actor/time, and validation result before atomic activation
**But when** a partial row, duplicate code, conflicting display/version, or invalid code format exists
**Then** the entire staged import is rejected and the previously active release is unchanged.

### RMIK-08 — enforce coding scope at the server

**Given** a user lacks an active coding assignment, terminology-search capability, or exact patient/encounter/session scope
**When** the user calls suggestion, search, decision, or assignment endpoints directly
**Then** the server denies the request
**And** does not expose the source statement or candidate list
**And** records a safe audit event for the denied action.

### RMIK-09 — distinguish a performed procedure from an order and its ICD-9-CM assignment

**Given** the clinician is preparing the exact encounter-closure version
**When** no procedure was performed
**Then** the clinician must explicitly attest `NONE_PERFORMED`
**But when** one or more procedures were performed
**Then** each procedure records completed status, clinician-authored text, performed time, performer, optional diagnosis/order linkage, and an integrity hash
**And** the closure supervisor reviews the procedure source as part of the exact closure hash
**And** neither a ServiceRequest nor a procedure source contains an RMIK ICD-9-CM assignment
**And** the procedure source cannot be updated or deleted after persistence
**And** an authorized coder may generate bounded ICD-9-CM candidates only from that completed source
**And** an ICD-10 concept cannot be assigned to the procedure source
**And** accepting a candidate creates only a draft until the exact coder submits and linked RMIK supervisor approves it
**And** the encounter cannot finalize until every current diagnosis and every current performed procedure has a separately approved assignment.

### RMIK-10 — correct a performed-procedure source without overwriting history

**Given** an authorized coder finds that the exact completed procedure source lacks or contradicts detail needed for ICD-9-CM selection
**When** the coder requests correction with an attributed reason
**Then** coding is blocked and the exact closure/procedure author receives a dedicated procedure-source task
**And** the coder cannot edit the clinical source
**And** the successor closure may change performed-procedure documentation only, while other authored closure fields and the original clinical occurrence time remain locked
**And** the old closure, procedure, hashes, suggestion, decision, and assignment remain immutable history
**And** the exact linked medical supervisor must approve the successor closure
**And** assignments bound to the old procedure become `REVIEW_REQUIRED`
**And** the prior RMIK approval remains stale until the exact prior reviewer and linked supervisor approve a replacement completeness review
**And** coding resumes only against the successor procedure after that replacement approval.

## 9. Contextual authorization

### AUTH-01 — allow the assigned role in the correct context

**Given** a learner has an active assignment, enabled capability, matching session, patient, encounter, and document state
**When** the learner performs the allowed action
**Then** the API permits it and records the acting assignment.

### AUTH-02 — deny the same role in another session

**Given** a nursing learner is assigned to `SIM-001` but not `SIM-002`
**When** the learner directly requests the `SIM-002` encounter API
**Then** access is denied without exposing clinical payload or existence-sensitive details
**And** the denial is auditable at the configured level.

### AUTH-03 — deny wrong role and self-approval

**Given** a learner can author a document
**When** the learner calls an approval endpoint or uses another discipline's endpoint directly
**Then** the API denies the action even if the browser request is manually constructed.

### AUTH-04 — prevent administrator privilege from becoming chart access

**Given** an account has system-configuration privileges but no clinical/session assignment
**When** it requests a patient chart
**Then** access is denied
**And** configuration access remains available.

### AUTH-05 — select exactly one active work-queue session

**Given** one synthetic demo identity has active assignments in two disposable simulation sessions
**When** the user opens the work queue without a session selector
**Then** both non-clinical session choices are visible but no task is returned or actionable
**And** the user must select one exact active session before its tasks, summaries, scenario, roles, programs, and capabilities appear
**And** every returned task repeats that selected session code
**And** an invalid, inactive, unknown, or unassigned selector fails without disclosing another session's existence or task payload
**And** the successful view or denied selection creates minimized audit evidence without recording a rejected selector value.

## 10. Audit, safety, and integration truth

### AUD-01 — create an immutable material-action trail

**Given** a registration, clinical submission, review, prescription action, dispense, coding decision, export, or finalization occurs
**Then** an append-only audit event records actor, assignment/capability, resource/version, patient/encounter/session context, time, action, outcome, and correlation ID
**And** no ordinary application endpoint can update/delete the event.

### AUD-02 — avoid secrets and excessive content in logs

**Given** authentication, integration, or validation fails
**Then** application logs contain correlation-safe diagnostic metadata
**And** exclude passwords, session secrets, access tokens, and full sensitive payloads.

### SAF-01 — reject non-synthetic data paths

**Given** the MVP environment
**When** a fixture/import lacks `synthetic_flag=true`, uses a forbidden production namespace, or resembles configured real-data input
**Then** persistence/import is rejected and audited
**And** no bypass is available to ordinary users.

### SAF-02 — watermark every clinical representation

**Given** any patient-context screen, print, PDF, CSV, screenshot-oriented view, or debrief export
**Then** `SIMULASI — DATA SINTETIS` and environment identity are visible/non-ambiguous.

### SAF-03 — represent integration state honestly

**Given** no verified external sandbox transmission has occurred
**When** an integration status is displayed
**Then** it says `Simulator`, `Belum dikirim`, or equivalent
**And** never shows `Connected`, `Submitted`, or `Accepted` based only on a button click/timer.

### SAF-04 — provide no autonomous clinical verdict

**Given** observations, diagnosis candidates, allergies, or prescription review data
**When** the user views or submits them
**Then** the application may report missing/invalid-format/configured-warning facts
**And** does not diagnose, prescribe, recommend treatment, or mark clinical appropriateness automatically.

### INT-01 — map the internal model deterministically

**Given** an approved synthetic encounter record
**When** the local adapter creates a SATUSEHAT/FHIR-aligned representation
**Then** Patient, Encounter, Observation, Condition, ServiceRequest/result, MedicationRequest/review/dispense, and Composition mappings use explicit source IDs/versions
**And** validation errors identify the source field
**And** no network call is required.

### INT-02 — keep production endpoints impossible in MVP configuration

**Given** the reference MVP build/profile
**When** configuration attempts to set a production health-service endpoint or secret
**Then** startup/deployment validation fails safely
**And** the secret is not logged.

### REP-01 — project a source-derived outpatient summary after finalization

**Given** an authorized assignment with `report.view` for a synthetic encounter in `FINALIZED`
**When** the user opens the outpatient-summary report
**Then** identity, encounter, history, allergy, examination, diagnoses, current results, performed procedures, medication outcomes, plan, disposition, education, follow-up, and approved human coding are projected from current approved sources
**And** the response is private/no-store/no-index
**And** the page and print output state `SIMULASI — DATA SINTETIS`
**And** the output says that it is not a legal record, certified PDF, FHIR artifact, or SATUSEHAT submission.

### REP-02 — project curated debrief evidence without raw audit internals

**Given** the same authorized finalized case
**When** the user opens the debrief-evidence report
**Then** it contains the curated material-event timeline, shared note version lineage, scenario learning outcomes, and non-scoring rubric-reference status
**And** it does not expose raw audit reasons, arbitrary metadata, request correlation IDs, IP hashes, user agents, or hidden scores.

### REP-03 — enforce the report boundary directly at the server

**Given** a pre-finalization case, missing `report.view`, or a mismatched assignment scope
**When** a report URL is requested directly
**Then** the server returns a safe conflict or denial without rendering report content
**And** a successful render records only report type, section count, and source counts in `report.rendered` audit metadata
**And** an assigned user may still read the finalized report after the simulation session is completed.

## 11. UX and accessibility

### UX-01 — identify routine outpatient intake correctly

**Given** an outpatient encounter
**When** any role sees the intake stage
**Then** Indonesian UI uses `Asesmen Awal dan Skrining Keselamatan`
**And** does not label it as an emergency triage/acuity system.

### UX-02 — preserve patient and encounter context

**Given** a user navigates among authorized patient-work screens
**Then** the persistent banner shows simulation mode, patient name plus second identifier, encounter/clinic/status, allergies/alerts, learner role, and sign-off state
**And** switching patient requires an explicit action.

### UX-03 — operate the critical path accessibly

**Given** a keyboard-only user at supported desktop width
**When** they complete registration, intake, assessment, pharmacy review, and RMIK review
**Then** focus order and visible focus are logical
**And** all controls have accessible names and error associations
**And** statuses are not conveyed by color alone
**And** automated WCAG checks report no serious/critical violations on the critical screens.

### UX-04 — protect dense form work

**Given** a long clinical form with unsaved changes
**When** the user navigates, changes patient, loses a validation round, or the session becomes stale
**Then** the system warns/preserves the draft as designed
**And** in-session browser Back/Forward restores the dirty form and requires the same explicit stay, save-draft-then-leave, or discard-local-delta decision before replaying the exact target
**And** an expired authentication/CSRF session cannot replace the dirty form with the login page; reauthentication opens separately and retry revalidates the current server context
**And** never attaches it to a different encounter
**And** server errors do not erase already acknowledged saved sections.

## 12. Operations

### OPS-01 — recover the transactional record

**Given** a documented staging backup
**When** it is restored into an isolated validation environment
**Then** patient/encounter relationships, immutable versions, approvals, pharmacy stock transactions, coding source links, and audit events remain consistent
**And** health/readiness checks pass
**And** the restore does not contact production integrations.

### OPS-02 — deploy and roll back a release artifact

**Given** CI has produced a tested release candidate and a staging backup exists
**When** staging deployment and smoke tests run
**Then** the deployed commit/migration set is identifiable
**And** a failed health/smoke check prevents promotion
**And** the documented rollback/recovery procedure restores the prior healthy state.

## 13. Acceptance gates

### Reference implementation complete

- all domain/feature tests for state, authorization, provenance, and simulation guardrails pass;
- E2E-01, E2E-04, E2E-05, AUTH-02–04, SAF-01–04, and UX-03 pass in CI or the documented browser test environment;
- no unresolved critical/high security finding;
- no real data, credentials, or production endpoint exists in repository/fixtures;
- requirements trace to tests and affected screens.

### Ready for combined stakeholder UAT

- Daniel accepts the reference journey and known-assumption list;
- all P0 assumptions needed for the UAT case have a safe configured position;
- the [Checkpoint 2 UAT facilitator guide](../operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md), [blank UAT record template](../operations/OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md), and role accounts are available;
- participants can complete one shared case without developer database edits;
- feedback is captured against scenario IDs, not as an unbounded menu wish list.

### Ready for faculty pilot

- Daniel gives release approval;
- Checkpoints 1–3 are recorded;
- no unresolved P0 item and every accepted P1 risk has an owner/mitigation;
- accessibility, security, backup/restore, staging deployment, and rollback evidence is current;
- the pilot limitation statement explicitly excludes real-patient care and production integration.
