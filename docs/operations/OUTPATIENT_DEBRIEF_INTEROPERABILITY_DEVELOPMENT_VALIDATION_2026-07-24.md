# Outpatient Debrief and Interoperability Development Validation — 2026-07-24

> **DEVELOPMENT EVIDENCE ONLY — NOT FACULTY UAT ACCEPTANCE, PILOT APPROVAL, MERGE AUTHORIZATION, OR DEPLOYMENT AUTHORIZATION.**

- **Record ID:** `DEV-UAT-RUN-20260724-02`
- **Owner and final decision authority:** Daniel Happy Putra, project manager/PIC
- **Data boundary:** synthetic patients, encounters, accounts, results, medicines, and terminology fixtures only
- **Workflow boundary:** main outpatient reference journey only; Claims and E-Klaim were not loaded or exercised
- **Branch:** `agent/outpatient-domain-spine`
- **Base commit:** `31a5a9b10a45c6a130a93d59a9840b5c0b3feba1`
- **Candidate delta:** visible human-review provenance on coded FHIR resource cards, branded expected-conflict handling, and their regression contracts
- **Environment:** isolated local candidate, SQLite, `127.0.0.1:8041`
- **Finalized source session:** `SIM-RJ-UEU-001`
- **Pre-finalization gate session:** `DEV-UAT-GATES-20260724`
- **Disposable branch sessions:** `DEV-UAT-CANCEL-20260724`,
  `DEV-UAT-NOSHOW-20260724`, `DEV-UAT-SAFETY-20260724`, and
  `DEV-UAT-DEPART-20260724`

This record extends the earlier Checkpoint 2 development rehearsal. It validates
the teaching-oriented longitudinal record, finalized debrief, local reports,
and contained FHIR R4 mapping preview. It does not replace stakeholder review
or Daniel's retain/revise/remove decisions.

## Isolation correction

The first debrief attempt returned HTTP 500 because the temporary candidate's
`vendor` directory was a symlink to the main workspace. Composer's optimized
autoload map therefore loaded uncommitted controller code from another
workflow instead of the candidate's outpatient source.

The candidate was repaired by copying `vendor`, regenerating its optimized
autoload map, and confirming by reflection that
`EncounterDebriefController` resolved under `/tmp/simrs-outpatient-pharmacy-check`.
After that correction, the unchanged outpatient debrief loaded successfully.
The initial 500 was test-environment contamination, not an outpatient-branch
defect.

## Disposable branch development observations

Four independent synthetic clones were used so terminal and escalation branches
could not damage the finalized reference journey.

### UAT-01A — cancellation after check-in

The registration learner checked in the appointment, then recorded a specific
synthetic cancellation reason. The appointment and encounter became
`CANCELLED`; the encounter retained its original planned event followed by
attributed `PLANNED → ARRIVED` and `ARRIVED → CANCELLED` transitions. The
historical clinic-queue row remained visible as cancelled, while the public
queue returned an empty entry list and did not expose the protected reason.
No further termination action remained available.

**Scenario status:** `PASS — DEVELOPMENT REHEARSAL ONLY`.

### UAT-01B — overdue no-show

The overdue booked appointment offered both cancellation and no-show before
check-in. The learner selected no-show and recorded a specific synthetic
reason. The appointment and encounter became `NO_SHOW`; the encounter showed
the original planned fixture event followed by attributed
`PLANNED → NO_SHOW`, with no clinic-queue row. The public queue returned an
empty entry list without the protected reason. In a future-scheduled control
fixture, the termination dialog offered cancellation only and made no
mutation.

**Scenario status:** `PASS — DEVELOPMENT REHEARSAL ONLY`.

### UAT-02B — human safety disposition

An escalation fixture was checked in, submitted by the assigned nursing
learner, and approved by the linked nursing supervisor. The private disposition
workspace displayed the exact approved nursing version, SHA-256 hash,
human-authored escalation response, and permanent non-emergency and
non-recommendation boundaries. Neither outcome was preselected.

The supervisor independently chose **Lanjutkan alur rutin simulasi** and wrote
a specific rationale. The immutable result retained actor, role, time,
rationale, source version/hash, and outcome. The encounter became
`WAITING_CLINICIAN`, the existing medical task became `READY`, and the acting
supervisor disposition task became `COMPLETE`. A competing transfer decision
was rejected and the first record remained the only disposition.

**Scenario status:** `DECISION REQUIRED`.

Daniel still needs to decide whether to retain or revise the Indonesian
question wording, authorized actor boundary, and teaching dispositions. This
does not validate emergency triage thresholds or clinical recommendations.

### UAT-02C — patient-requested departure

A separate fixture retained check-in and one current nursing draft while the
encounter was `IN_INTAKE`. The exact-case medical supervisor opened
**Catat pulang atas permintaan sendiri** from the encounter overview. The
workspace displayed the source state, nursing version/status/hash, unchecked
human confirmation, and permanent statements that the action was neither
cancellation/no-show nor a verdict that departure was clinically safe.

After explicit confirmation, the supervisor recorded a patient-stated
synthetic reason and factual communication summary. The result became
read-only. The encounter and appointment became `DEPARTED_ON_REQUEST`,
check-in remained attributable, the clinic queue became `COMPLETED`, the
nursing source remained, and no active encounter task or coding assignment was
created. The longitudinal event used the safe generic description and excluded
both protected text fields. A competing request was rejected, and direct
registrar access returned HTTP 403.

**Scenario status:** `DECISION REQUIRED`.

Daniel still needs to decide whether to retain or revise the Indonesian
vocabulary, authorized actors, form content, and future handling of incomplete
records. This does not approve a clinical discharge protocol.

## UAT-09 development observations

The following behaviors were observed in the browser:

1. A finalized encounter released a distinct **Linimasa Rekam & Debrief**
   workspace with 35 deterministic source events, 19 handoffs, and six
   supervisor decisions.
2. The longitudinal record retained actor, program, role, assignment, source,
   public identifier, and time for registration, clinical documentation,
   supervision, pharmacy, closure, RMIK, and coding events.
3. Timeline filters for `Registrasi` and `RMIK` reduced the display to one of
   35 events without changing source data.
4. Debrief filters for `Koding`, `RMIK`, and `Keputusan koding manusia`
   retained their URL state and reduced the display to eight of 35 events.
5. The finalized debrief exposed both manual coding chains:
   `R42 — Dizziness and giddiness` and
   `38.99 — Other puncture of vein`, including submission and linked
   supervisor approval.
6. A facilitator created shared debrief note v1, created attributed successor
   v2 with a change reason, and reopened the preserved v1 history.
7. An exact-case participant saw v2 and the preserved history read-only; no
   create or revise control was presented.
8. The rubric remained visibly pending, draft, and non-scoring.
9. The outpatient summary and debrief-evidence report both retained the
   permanent synthetic watermark and stated that they were not legal medical
   records, certified PDFs, or SATUSEHAT transmissions.
10. The outpatient summary presented the human-reviewed ICD-10 and ICD-9-CM
    decisions. The debrief report retained all 35 curated events and both
    debrief-note versions.
11. An observer scoped only to another synthetic session had no record task or
    link and received HTTP 403 when requesting the finalized source debrief.
12. An exact-case participant requesting debrief before finalization received
    HTTP 409.

**Scenario status:** `DECISION REQUIRED`.

The implemented behavior passed the development checks above. Daniel still
needs to decide whether to retain, revise, or remove the longitudinal record
and each report. A faculty rehearsal should also repeat the first provenance
trace while the journey is actively progressing, not only after finalization.

## UAT-10 development observations

The finalized local preview displayed:

- `SIMULASI — DATA SINTETIS`;
- `BELUM DIKIRIM`;
- `Bundle · collection`;
- FHIR `4.0.1`;
- 18 local resources and 18 provenance rows; and
- no configured external endpoint.

The resource inventory contained:

- Patient, Organization, Encounter, and preliminary Composition;
- six vital-sign Observations;
- Condition;
- ServiceRequest;
- result Observation and DiagnosticReport;
- MedicationRequest;
- pharmacy QuestionnaireResponse;
- MedicationDispense; and
- Procedure.

The provenance ledger linked every resource to a local source type, public
source identifier, and source path. The JSON inspector opened and closed
without exposing any send, connect, retry, credential, endpoint-edit, upload,
or submit control.

The contained JSON retained two `human-reviewed` extensions, one for `R42` and
one for `38.99`. It contained no Practitioner or Location resource and no KFA,
IHS, or SNOMED identifier. The visible validation boundary declared
`PROFILE_VALIDATION_NOT_RUN`, `NATIONAL_IDENTIFIERS_ABSENT`, and local-text-only
terminology findings.

The browser rehearsal exposed one presentation gap: the human-review extension
was present in JSON but absent from the visible cards. The candidate now shows
**Ditinjau manusia** on exactly the Condition and Procedure cards. No mapping
or transmission behavior changed.

An observer scoped only to another synthetic session received HTTP 403 for the
finalized preview. An exact-case participant received HTTP 409 before
finalization.

**Scenario status:** `DECISION REQUIRED`.

The development mapping checks passed. Daniel still needs to decide whether
the local preview is retained, revised, or deferred and assign institutional
owners for national identifiers, approved terminologies, and future
SATUSEHAT-profile validation.

## Resolved usability finding

Expected pre-finalization gates return the correct HTTP 409 status and preserve
the underlying data, but the default production error page says
`Something is broken`. That wording mischaracterizes an intentional workflow
gate.

- **Finding:** `DEV-UAT-20260724-002`
- **Impact:** usability and learner confidence
- **Proposed classification:** `SHOULD_FIX_BEFORE_FACULTY_UAT`
- **Daniel classification:** `NOT CLASSIFIED`
- **Resolution:** safe GET conflicts now render an Indonesian,
  simulation-branded **Tahap belum tersedia** page while preserving HTTP 409,
  the server-provided reason, the permanent synthetic boundary, and a return
  path to the work queue.
- **Browser result:** both the pre-finalization debrief and interoperability
  routes showed the branded gate and no longer described the expected state as
  a broken system.

## Automated evidence

Validated from the isolated candidate after the visible provenance change:

- focused debrief, shared-note, finalized-report, and interoperability feature
  suite: 15 tests passed, 315 assertions;
- complete PHP application suite: 270 tests passed, 3,617 assertions;
- complete frontend unit suite: 30 files and 81 tests passed;
- TypeScript `tsc --noEmit`: passed;
- production frontend build: passed;
- targeted ESLint: passed; and
- targeted Prettier check and full PHP formatting check: passed.

The first complete PHP run had one environmental failure because the archived
candidate intentionally had no `.git` metadata. After adding a read-only link
to the repository metadata required by `ReleaseManifestCommandTest`, the full
270-test suite passed. No application code was changed to manufacture that
result.

The first complete frontend run caught a nested `<main>` landmark in the new
gate page. The page now delegates the sole main landmark to the application
layout; the complete 81-test frontend suite and final browser snapshot both
confirmed one main landmark.

## Decision boundary

This evidence does not approve:

- formal Checkpoint 2 stakeholder acceptance;
- laboratory staffing or supervision policy;
- report use as a legal medical record;
- FHIR or SATUSEHAT conformance;
- national identifier ownership;
- merge, deployment, Hostinger changes, or Sites publication; or
- any Claims or E-Klaim integration.

Daniel remains the sole final scope and release decision-maker.
