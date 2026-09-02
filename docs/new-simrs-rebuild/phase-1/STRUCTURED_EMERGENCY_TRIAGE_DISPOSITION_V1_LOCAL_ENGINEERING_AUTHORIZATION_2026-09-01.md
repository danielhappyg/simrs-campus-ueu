# Structured IGD Triage-to-Disposition V1

Status: **locally authorized for bounded implementation and engineering verification**
Date: 2026-09-01
Coverage: `PAR-REG-002`, `PAR-CLN-002`, and `PAR-CLN-003`
Journey: `E2E-02` emergency registration, triage, treatment, and disposition

## Decision and outcome

The next local graph node is one hospital-like IGD journey from the existing emergency registration through mandatory attributable triage, reassessment, structured nursing and medical documentation, diagnostic-order visibility, and a physician-signed disposition. An inpatient disposition atomically creates one linked managed-bed Rawat Inap encounter; discharge and referral dispositions preserve complete source evidence without inventing billing, claim, or external-delivery effects.

This record is local engineering authority under the simplified governance direction. It does not confer Clinical/IGD-owner acceptance, Nursing acceptance, RMIK acceptance, parity acceptance, hosted readiness, deployment approval, or production use. No commit, push, hosted migration, or deployment is authorized by this record.

## Regulatory and evidence boundary

- Indonesia's currently published [Permenkes No. 47 Tahun 2018 tentang Pelayanan Kegawatdaruratan](https://jdih.kemkes.go.id/documents/peraturan-menteri-kesehatan-nomor-47-tahun-2018) requires every hospital to have a triage standard approved by its director. Its hospital procedure uses manually assigned `MERAH`, `KUNING`, `HIJAU`, and `HITAM` categories, assessment based on ABCDE, and continuous reassessment/retriage when the patient's condition changes.
- V1 implements those four canonical category identities and an attributable manual ABCDE observation record. It does not calculate a category, infer acuity from vital signs, recommend treatment, or claim that the campus catalogue is a director-approved hospital SOP. The versioned local master controls only display wording, non-colour cues, and bounded teaching guidance around the fixed categories; it cannot add, remove, merge, reorder, or repurpose them.
- A category `HITAM` record is a bounded triage fact. It does not itself declare death, establish cause or manner of death, authorize non-resuscitation, create a death certificate, or replace a physician disposition.
- The assessed vendor evidence proves distinct Triage and IGD surfaces but not their current field rules, reassessment timers, or correction semantics. Historical labels are not treated as current clinical authority.

## Actors and exact role boundaries

- Exact `admin` manages the triage vocabulary only. Administrator status is not a clinical substitute.
- Exact `registrar` retains the existing emergency-registration workflow, read-only status visibility, and executes a physician-approved inpatient handoff by selecting one current managed bed. A registrar cannot choose the clinical disposition, triage, or document care.
- Exact `nurse` records the initial triage assessment, appends reassessments, and authors nursing Draft/Final documentation.
- Exact `physician` authors medical Draft/Final documentation, reviews the current triage history, places existing governed laboratory/radiology orders, and signs one disposition.
- Exact `rmik` receives read-only clinical-readiness and provenance facts. V1 does not invent an IGD coding or closure workflow.
- No administrator, instructor, mixed-role account, or system-administrator flag may bypass these clinical boundaries.

Every mutation checks capability before route-resource lookup and repeats exact actor-policy checks inside the domain service before dereferencing protected relationships. Unauthorized actors receive no resource-existence disclosure.

## Managed triage vocabulary

The managed vocabulary has a stable code, display name, status, effective version, and immutable version history. Every version contains exactly the four fixed categories `MERAH`, `KUNING`, `HIJAU`, and `HITAM` in their canonical priority order. Every category snapshots its code, rank, Indonesian label, non-colour text cue, display colour token, and optional bounded local guidance text.

```text
ACTIVE -> RETIRED
```

The initial teaching catalogue states relative response priority and the regulatory category meaning without adding local symptom thresholds, diagnosis rules, target-time claims, or automated clinical advice. Colour is never the sole indicator. An Active vocabulary may update display wording and approved local guidance with optimistic version control, but the fixed codes and order cannot change. Updates never rewrite historical assessments. Retirement is terminal and only the current Active version may be selected for a new initial triage.

Stable-code reservation and immutable operation receipts prevent reuse, replay ambiguity, and race-dependent identity.

## Initial triage and reassessment

Every active emergency encounter must receive one finalized initial triage before any governed nursing or medical write, diagnostic order, or disposition. Finalizing the initial triage moves a `REGISTERED` encounter to `IN_EXAMINATION`; merely opening or partially filling the form does not change state.

The finalized initial assessment snapshots:

- the exact triage-vocabulary version and selected priority level;
- observed time, server-recorded time, exact nurse identity, and a late-entry reason when recorded more than fifteen minutes after observation;
- presenting concern, required manual clinical basis for the selected priority, and arrival-condition note;
- manual ABCDE observations, each recorded as `ASSESSED_NO_CONCERN`, `ASSESSED_CONCERN`, or `NOT_ASSESSED` with a bounded note;
- consciousness recorded manually as `ALERT`, `VOICE`, `PAIN`, or `UNRESPONSIVE`;
- respiratory rate in breaths/minute (`0..100`), pulse in beats/minute (`0..300`), paired systolic/diastolic pressure in mmHg (`0..300`), oxygen saturation in percent (`0..100`), temperature in Celsius (`20.0..45.0`), pain score (`0..10`), and optional weight in kilograms (`0.1..500.0`);
- an explicit `unobtainable_fields` list and bounded reason for every expected observation that is absent; BP values must be supplied or marked unobtainable as a pair;
- manual trauma and isolation-precaution flags with notes; and
- free-text handoff note.

Observed time may be up to 24 hours before registration for attributable pre-arrival observations and at most five minutes in the future for clock tolerance. Numeric fields receive only technical storage/plausibility validation. Zero values are preserved as observations and never interpreted by the software. Triage does not record a medical intervention; treatment belongs to governed nursing/medical documentation. The system does not interpret normality, infer severity, calculate early-warning scores, or change the selected category.

Reassessment is an immutable append-only version that binds the prior assessment digest, reason, new manual category, repeated observations, assessor, observed/recorded times, and timestamp. It never overwrites the first assessment. Same-content reassessment is allowed only with a documented reason; stale expected-version writes fail closed. Reassessment is allowed only while the encounter is `IN_EXAMINATION` and before a current signed disposition. After disposition or `READY_FOR_RM`, triage and clinical-document writes are rejected until a separately governed correction returns the episode to examination.

## Structured nursing and medical documentation

The emergency episode has one nursing document head and one medical document head. Each uses immutable versions with optimistic `expected_version` control:

```text
no document -> DRAFT v1 -> DRAFT v2 ... -> FINAL vN
```

Nursing documentation contains arrival condition, focused assessment, interventions, response/evaluation, safety or observation needs, and handoff note. Medical documentation contains anamnesis, focused physical examination, clinical impression, problem list, treatment/action plan, diagnostic-order rationale, and disposition-readiness note.

Finalization appends an identical-content Final version; it does not mutate a Draft. A Final document cannot be silently edited or deleted. V1 does not claim structured ICD diagnosis, procedure coding, medication ordering, CPPT, clinical decision support, or an approved specialty-specific form standard. Existing legacy free-text entries remain read-only compatibility history; all new IGD clinical writes use the governed documents. The legacy IGD write route becomes an explicit `410 Gone` fence and can no longer advance encounter state.

## Diagnostic integration

The existing governed laboratory and radiology lifecycles remain the only diagnostic write paths. The emergency encounter surface presents their order, specimen/performance, Verified/amended result, communication, and acknowledgement states according to the existing exact-role redaction rules.

Disposition may be signed while diagnostic work remains unresolved. Before signing any such disposition, the physician must create an immutable result-follow-up proposal to one current exact active physician, with assignment reason, effective time, and handoff note. The proposed physician must explicitly accept the exact assignment fingerprint before responsibility transfers; self-assignment by the current accountable physician is accepted atomically. Until acceptance, the original ordering physician remains accountable and the disposition cannot rely on the proposal. The original ordering physician or the current accepted covering physician may acknowledge the exact current result fingerprint; covering acknowledgement is attributable and requires the accepted assignment reference. A later amendment still makes an earlier acknowledgement stale. Reassignment is append-only, requires a reason and fresh assignee acceptance, and cannot remove accountability while unresolved evidence remains.

Those orders stay bound to the emergency encounter and may complete while the encounter is `READY_FOR_RM`; their existing acknowledgement and later IGD-closure blockers remain effective. V1 does not copy, re-parent, or silently reinterpret an order into the inpatient encounter. The linked inpatient read model must expose a read-only source-IGD projection containing the triage timeline, current Final nursing/medical documents, disposition and correction chain, diagnostic statuses/results allowed by the actor's role, and current follow-up assignment. Source and target same-patient/link integrity is verified on every projection.

## Physician disposition and atomic inpatient handoff

Only a physician may create and sign the disposition. V1 supports the following bounded outcomes:

- `PULANG`: condition at discharge, instructions, warning signs, and follow-up plan;
- `DIRUJUK`: destination, clinical reason, transport plan, and handoff note; and
- `RAWAT_INAP`: admission reason and receiving-unit handoff note. Bed selection and creation of the linked inpatient encounter are a separate registrar-executed step after the physician decision;
- `MENINGGAL_DI_IGD`: physician-recorded event time, bounded clinical note, and identity of the recording physician; and
- `DOA`: physician-recorded arrival/declaration time, bounded clinical note, and identity of the recording physician.

`DIRUJUK` records only the local referral plan and clinical handoff evidence. It does not prove completed transport, receiving-facility acceptance, custody transfer, or external delivery. The death/DOA branches record bounded episode facts only; they do not create a certificate, cause/manner-of-death determination, mortuary workflow, statutory report, or external notification. Leaving against advice, observation-unit admission, disaster/mass-casualty mode, and return-to-origin variants remain excluded until separately defined.

A signed disposition requires a finalized initial triage and current Final nursing and medical documents. Its exact version is immutable. For `RAWAT_INAP`, an exact registrar subsequently selects a current managed bed. To preserve the global admission lock hierarchy, direct and IGD-origin admission both acquire the patient-level active-admission claim mutex first, then the bed-code mutex; lock all involved encounter rows in deterministic ID order; then lock the ward and bed before reading the disposition, cancellation, and diagnostic evidence under the encounter locks. The service refuses a second active inpatient encounter for the same patient, even when two different IGD encounters race for different beds.

The handoff verifies the bed is Active and unoccupied; creates exactly one inpatient encounter for the same patient with `continue_from=DARI_IGD`; copies the locked payer/insurance snapshot, admission reason, visit/registration time, queue semantics, ward/class/bed snapshots, and actor attribution; records admission-location sequence 1; links source disposition, target encounter, and location event in an immutable receipt; and records the handoff audit facts in one transaction. Any validation, same-patient claim, bed race, linkage, receipt, or audit failure rolls the entire handoff operation back without changing the signed clinical decision. It must never lock an encounter and then request either admission mutex.

`PULANG`, `DIRUJUK`, `MENINGGAL_DI_IGD`, and `DOA` move the emergency encounter to `READY_FOR_RM` when signed. `RAWAT_INAP` remains `IN_EXAMINATION` until the registrar-executed inpatient handoff commits, then moves to `READY_FOR_RM`. This state means clinical disposition and its required handoff have been recorded; it is not an IGD RM completeness decision, coding completion, bill, claim, or closed encounter. A later separately authorized IGD RMIK slice must own final closure and invoke the existing laboratory/radiology gates plus the current follow-up assignment.

## Cancellation, correction, and downstream safety

- Pre-clinical encounter cancellation remains allowed only before any triage, governed emergency document, diagnostic, or disposition evidence exists.
- Once triage exists, encounter cancellation is denied with an attributable reason and no partial mutation.
- A signed disposition cannot be edited or deleted, but an exact physician may append a corrected disposition version with a bounded reason while the IGD encounter is not `CLOSED`. The prior signed version remains visible and fingerprinted. Correction temporarily returns the source encounter to `IN_EXAMINATION` until the corrected branch requirements are satisfied.
- A pre-handoff `RAWAT_INAP` decision may be corrected directly. For an executed handoff, the physician first appends an immutable correction intent containing the complete pre-signed replacement disposition, reason, current source/disposition/handoff/child fingerprints, and expiry. This intent changes no active state and grants no bed authority.
- An exact registrar then executes the accepted physician intent in one transaction, without changing any clinical field. The service revalidates the intent and all bound fingerprints, requires the linked inpatient encounter to remain `REGISTERED` with no clinical, diagnostic, discharge, RMIK, transfer, medication, inventory, financial, or other downstream evidence, runs attributable pre-clinical inpatient cancellation and bed reconciliation, appends an immutable handoff-compensation event, activates the physician-signed replacement disposition, and audits every effect. Stale, expired, revoked, replay-conflicting, or progressed-child intents fail closed. Identical replay returns the original compensation. Concurrent physician correction, registrar execution, child progression, cancellation, or bed operation yields one coherent outcome with no interval in which a corrected source and active old child are both current.
- `PULANG`, `DIRUJUK`, `MENINGGAL_DI_IGD`, and `DOA` corrections are append-only and allowed only before IGD closure or any separately governed irreversible downstream execution. No correction erases the original clinical fact.
- A linked inpatient encounter cannot be duplicated by retry, concurrent request, or a second disposition.
- A discharge/referral disposition does not create an inpatient encounter, stock movement, charge, claim, document transmission, or external message.
- Every triage, document, order, reassessment, disposition, or handoff mutation is rejected after `CLOSED`/`CANCELLED`; ordinary triage/document writes are also rejected after a current signed disposition or `READY_FOR_RM` until an authorized correction returns the episode to examination.

## User experience contract

- The Triage worklist shows waiting time, current manually assigned `MERAH`/`KUNING`/`HIJAU`/`HITAM` category, last assessment time, reassessment count, and status using text plus colour.
- A dedicated triage encounter screen supports initial assessment and reassessment with keyboard operation, clear error focus, status announcements, and 44-pixel targets.
- The IGD encounter screen shows the complete triage timeline, role-appropriate structured nursing/medical document controls, existing laboratory/radiology evidence, and the disposition panel.
- The inpatient handoff offers only current managed beds returned by the existing ward/bed read model and gives a clear conflict response for same-patient active admission or a concurrently taken bed.
- The receiving inpatient encounter shows the source-IGD triage, Final documents, current disposition/correction, diagnostic follow-up assignment, and role-safe diagnostic evidence as a read-only provenance panel.
- Registration and RMIK views receive read-only provenance and readiness facts without clinical editing controls.
- Indonesian labels and established UEU design tokens are used. No user-facing simulation or synthetic-data wording is added.

## Concurrency, receipts, audit, and database guards

Every mutation requires a normalized idempotency key, canonical payload SHA-256, optional correlation ID, and immutable short operation receipt unique on actor, operation, and canonical key. Same-key/same-payload replay returns the original result after recomputing retained evidence; changed-payload key reuse is denied and audited.

Ordinary triage, documentation, result-follow-up assignment, and physician-disposition writers lock the encounter first and then the relevant mutable vocabulary/document/disposition head. Every operation that creates, moves, releases, cancels, compensates, discharges, closes, resets, or reconciles an active inpatient claim follows one global order: patient active-admission claim mutex, sorted bed-code mutexes, all involved encounters in deterministic order, wards, beds, operation-specific evidence, audit, and receipt. This applies equally to direct admission, IGD-origin admission, bed transfer, ordinary inpatient cancellation, executed-handoff compensation, discharge/bed release, closure release where applicable, reset, and recovery reconciliation. No path may acquire the patient claim after a bed mutex, encounter, ward, or bed. Mutable heads have explicit legal-transition guards. Vocabulary versions, triage assessments, document versions, follow-up proposals/acceptances, signed disposition/correction intents/versions, handoff/compensation events, and receipts are append-only. Database constraints and triggers refuse evidence mutation/deletion, direct disposition changes, orphan or cross-patient handoffs, duplicate active inpatient claims, and PostgreSQL evidence-table truncation. Schema-qualified Laravel tables are never passed as strings to `Rule::exists`.

Success and denial audits use closed action/reason schemas. A missing success audit rolls back the business mutation. Bounded reset deletes diagnostic children, receiving-source projections, downstream linked inpatient graphs, admission-location facts, handoff/compensation events, disposition/follow-up/document/triage evidence, encounters, and patients in dependency order while preserving audit and managed-vocabulary evidence; bed occupancy must reconcile to zero afterward. Migration rollback refuses while governed business rows or correlated audit facts remain.

The recovery snapshot includes vocabulary, triage, document, follow-up assignment, disposition/correction, handoff/compensation, linked inpatient, admission-location, active-patient-claim, and bed counts plus deterministic digests. It refuses orphan links, source/target patient mismatch, two current active inpatient encounters for one patient, an uncompensated handoff to a cancelled child, or a compensated handoff whose bed remains claimed.

## Verification gate

Local completion requires:

1. feature tests for registration integration, fixed four-category vocabulary, manual ABCDE triage, reassessment/retriage, observed/recorded/late-entry rules, per-field unobtainable reasons, exact role denial, authorization-before-lookup, structured nursing/medical Draft/Final, explicit legacy-write `410`, laboratory/radiology projection and covering follow-up acknowledgement, all five dispositions, receiving-inpatient source projection, append-only disposition correction, pre-handoff correction, eligible executed-handoff compensation, and progressed-child correction refusal;
2. database/model/SQL guards, audit-contract, fresh migration, empty down/reapply, retained-evidence rollback refusal, cross-patient/cross-encounter denial, active-patient-admission claim, deterministic lock-order tests, and recovery-snapshot orphan/mismatch/refused-state tests;
3. keyboard, error-focus, status announcement, text-plus-colour status, contrast, and 44-pixel target checks for vocabulary, triage, IGD encounter, and disposition controls;
4. full PHP, frontend, formatting, type, lint, production-build, and diff gates; and
5. disposable PostgreSQL 17 and MySQL 8.4 evidence for least-privilege runtime grants, initial/reassessment and disposition races, cancellation-versus-triage, two distinct IGD encounters for one patient racing into different beds, two patients racing for one bed, admission-versus-inpatient-cancellation, admission-versus-discharge, two-actor correction-intent/compensation, stale-intent/child-progression races, covering-assignment proposal/acceptance and acknowledgement-versus-amended-result races, replay/conflict, append-only refusal, evidence-chain verification, atomic patient/bed reconciliation, reset/audit preservation, rollback refusal, and strict cleanup.

## Explicit exclusions

- automated triage scoring/category assignment, symptom thresholds, diagnosis, treatment recommendation, early-warning calculation, clinical alerting, or a claim that the campus catalogue is a director-approved hospital SOP;
- death certification, cause/manner-of-death determination, mortuary workflow, leaving against advice, disaster/mass-casualty mode, observation unit, ambulance dispatch, completed external referral/custody exchange, or encounter reopen;
- medication/prescription, administration, pharmacy, stock, tariff, charge, billing, cashier, claim, INA-CBG, BPJS/VClaim, SATUSEHAT, or reporting effects;
- live terminology, device, monitor, ambulance, LIS, PACS/RIS, printer, email, SMS, push notification, or external endpoint;
- real patient data, secrets, commit, push, deployment, hosted migration, or external write;
- Clinical/IGD-owner, Nursing, RMIK, facility, parity, hosted, production-readiness, G0, or G3 acceptance.
