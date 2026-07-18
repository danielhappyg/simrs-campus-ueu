# Outpatient Checkpoint 2 UAT Facilitator Guide

- **Status:** Working draft for Daniel's review before stakeholder scheduling
- **Scope:** one shared synthetic outpatient case in SIMRS Campus UEU
- **Decision authority:** Daniel Happy Putra, project manager/PIC
- **Evidence boundary:** teaching workflow acceptance only; never clinical-use approval

## 1. Purpose

This guide turns Checkpoint 2 into a bounded end-to-end review of the working reference application. Participants act through the same synthetic encounter in their assigned roles and record evidence against defined workflow, safety, terminology, authorization, correction, coding, reporting, and learning questions.

The session is not a blank-sheet menu workshop. Suggestions are evaluated against an observed task or failed acceptance condition. Daniel decides the corrective scope after the session.

## 2. Non-negotiable safety boundary

- Use only a fresh isolated `SIMULATION` environment with `APP_SYNTHETIC_ONLY=true`.
- Do not enter a real name, identifier, contact detail, diagnosis, document, or patient narrative.
- Keep the permanent `SIMULASI — DATA SINTETIS` label visible throughout the session.
- Do not use production SATUSEHAT, BPJS, hospital, email, or messaging credentials/endpoints.
- Treat candidate ICD codes as retrieval suggestions. Only the assigned human coder may decide an assignment, and the linked RMIK supervisor must review it.
- Treat supervisor approval as an educational simulation attestation, not a clinical signature.
- The rubric reference is non-scoring and must not be used to assign a grade, pass/fail decision, or competence claim.
- Stop the session immediately for real-data entry, cross-case disclosure, authorization bypass, silent overwrite, autonomous clinical decision, or unrecoverable source/provenance loss.

## 3. Entry gate

The facilitator records each item as `READY`, `NOT READY`, or `NOT APPLICABLE` before inviting participants.

| Entry item           | Required evidence                                                                             |
| -------------------- | --------------------------------------------------------------------------------------------- |
| Isolated environment | Separate URL, key, database, storage, and synthetic-only configuration                        |
| Tested build         | Identifiable commit/release candidate with passing required checks                            |
| Database             | Fresh migrations plus opt-in demo fixture                                                     |
| Terminology          | Exact approved development ICD-10 and ICD-9-CM releases active; version and checksums visible |
| Case state           | One new `PLANNED` encounter in `SIM-RJ-UEU-001`; no partially progressed prior case           |
| Accounts             | Ten administrator-provisioned demo accounts available; password distributed separately        |
| Browser              | Supported current desktop browser at 1280×720 or wider; 100% zoom                             |
| Recovery             | Snapshot/backup or disposable reset procedure confirmed before starting                       |
| Evidence capture     | This guide plus a dated copy of the [UAT record template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md) |
| Limitations          | Participants briefed on all items in section 11                                               |

Do not use `migrate:fresh` against shared or retained data. Do not enable the demo seeder to repair an existing environment.

## 4. Participants and demo accounts

Passwords are never written in this guide or repository. Provide the temporary UAT password through an approved out-of-band channel and rotate/remove it after the session.

| Sequence | Participant role            | Demo account                             | Responsibility in the case                                                     |
| -------: | --------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------ |
|        1 | Registration learner        | `mahasiswa.rmik@example.invalid`         | Search/reuse synthetic identity, create appointment/encounter, check in        |
|        2 | Nursing learner             | `mahasiswa.keperawatan@example.invalid`  | Initial assessment and safety-screen facts                                     |
|        3 | Nursing supervisor          | `supervisor.keperawatan@example.invalid` | Review the exact nursing version                                               |
|        4 | Medical learner             | `mahasiswa.kedokteran@example.invalid`   | Medical assessment, diagnosis source, order, prescription, closure             |
|        5 | Medical supervisor          | `supervisor.kedokteran@example.invalid`  | Review medical and closure versions                                            |
|        6 | Facilitator/result operator | `fasilitator.simulasi@example.invalid`   | Release pre-authored synthetic result; facilitate debrief                      |
|        7 | Pharmacy learner            | `mahasiswa.farmasi@example.invalid`      | Human pharmacy review and synthetic dispensing                                 |
|        8 | Pharmacy supervisor         | `supervisor.farmasi@example.invalid`     | Observe/confirm local supervision expectations; no learner self-approval claim |
|        9 | RMIK coder                  | `koder.rmik@example.invalid`             | Completeness review, candidate inspection, human coding decisions              |
|       10 | RMIK supervisor             | `supervisor.rmik@example.invalid`        | Review the exact RMIK/coding sources and finalize                              |

One person may operate multiple demo accounts only for a development rehearsal. Record every combined role. Do not infer that combined staffing is accepted for a faculty pilot (`VAL-T02`).

## 5. Facilitation protocol

For every step:

1. the acting participant states their role and expected input;
2. the facilitator records the encounter number and visible state before the action;
3. the participant completes the action without database intervention;
4. the next participant explains what became available and which source/version they received;
5. the facilitator records pass/fail evidence and any issue before continuing; and
6. participants do not redesign unrelated modules during the task.

Evidence should identify visible state, source/version, acting role, expected result, actual result, and impact. Screenshots must contain synthetic data only.

## 6. Reference journey script

### UAT-00 — safety and assignment check

Expected:

- every user sees the simulation boundary and only their assigned capabilities;
- another user's task is absent;
- no public self-registration is available; and
- no production integration status is implied.

Stop for missing simulation labelling, wrong patient/session, or unexpected role access.

### UAT-01 — registration and check-in

Actor: registration learner.

1. Search for the supplied synthetic patient using name or reserved identifier.
2. Confirm duplicate candidates require an explicit choice.
3. Open the existing appointment/case and perform Check-in.
4. Confirm `PLANNED → ARRIVED`, the clinic queue entry, and the same encounter number.
5. Return to registration and confirm a second shared case cannot be created in the same reference session.

Observe `REG-01`, `REG-02`, `SAF-01`, `UX-02`, and `VAL-T07`.

### UAT-01A — cancellation after check-in without deleting history

Actor: registration learner. Run this branch in its own fresh disposable fixture; do not continue the main finalized journey from the cancelled case.

1. Complete UAT-01 through Check-in and confirm the appointment is `Sudah check-in` and the encounter is `ARRIVED`.
2. Return to registration, open **Akhiri kunjungan**, choose **Batalkan kunjungan**, and enter a specific synthetic reason of 10–500 characters.
3. Confirm the appointment and encounter become `CANCELLED`/`Dibatalkan` and no termination action remains available.
4. Open the encounter and confirm the original `PLANNED → ARRIVED` check-in event, timestamp, actor, and completed registration work remain visible before the attributed `ARRIVED → CANCELLED` event.
5. Confirm unfinished downstream work is cancelled and the historical clinic-queue record is cancelled rather than deleted.
6. On the public queue display, confirm the active entry disappears and the free-text termination reason is not disclosed.

Observe `E2E-06`, `AUD-01`, `AUTH-01`, and preservation of completed provenance. Stop if any completed event/task is rewritten or deleted.

### UAT-01B — overdue no-show without queue creation

Actor: registration learner. Use a separate fresh disposable fixture whose persisted booked schedule is already due.

1. Before Check-in, open **Akhiri kunjungan** and confirm **Tandai tidak hadir** is available only because the persisted schedule is due.
2. Select **Tandai tidak hadir**, enter a specific synthetic reason of 10–500 characters, and confirm.
3. Confirm the appointment and encounter become `NO_SHOW`/`Tidak hadir` and no termination action remains available.
4. Open the encounter and confirm the attributed `PLANNED → NO_SHOW` transition appears after the original planned fixture event.
5. Confirm no clinic-queue row was created, check-in remains absent, completed orientation evidence remains, and unfinished encounter work is cancelled.
6. In a future-scheduled control fixture, confirm no-show is unavailable or rejected without mutation.

Observe `E2E-06`, `AUD-01`, server-clock eligibility, and terminal-state immutability. Do not use cancellation/no-show after clinical service begins; that distinct case is exercised in UAT-02C.

### UAT-02 — nursing assessment and safety decision

Actors: nursing learner, then nursing supervisor.

1. Record observed history source, complaint/onset, allergy state, current-medication state, vital signs with units, scenario-question responses, and a human safety decision.
2. Before saving, select **Antrean kerja**. Confirm `Perubahan belum disimpan` appears with **Tetap di halaman**, **Simpan draf lalu keluar**, and **Keluar tanpa perubahan lokal**. Choose **Tetap di halaman**. Repeat with browser **Back**, then **Forward** when a forward entry exists, and confirm both restore this same form before showing the same three choices. Repeat and choose **Simpan draf lalu keluar**; confirm all departure choices lock during `Menyimpan draf…` and the exact held destination opens only after a draft version is saved. If the facilitator runs a planned invalid-input or network-failure rehearsal, confirm `Draf belum tersimpan`, retained values, no navigation, and an enabled retry. In a separately planned expired-session rehearsal, confirm `Sesi masuk perlu dipulihkan`, no clinical text in the alert, login opening in a new tab, the original form remaining intact, and successful navigation only after same-account reauthentication plus an authorized retry. Return to the same task.
3. Save/submit the immutable nursing version.
4. Supervisor opens the exact version/hash and approves or requests correction.
5. For the routine branch, confirm the next medical task uses the approved nursing source without re-entry. For an escalation branch, confirm the encounter is `ESCALATED`, the medical task remains blocked, and continue to UAT-02B.

Discuss whether the wording and scenario questions are appropriate (`VAL-A01`–`VAL-A05`). Do not approve universal thresholds; core code intentionally makes no clinical threshold decision (`VAL-A03`).

### UAT-02B — human disposition after a safety escalation

Actor: linked nursing supervisor; the explicitly assigned session facilitator is the documented fallback.

1. Open the safety-disposition task and confirm the exact approved nursing version, source hash, human-authored escalation context, and permanent non-emergency/non-recommendation boundary.
2. Confirm neither outcome is selected and the system offers no severity score, threshold, diagnosis, treatment, or recommended answer.
3. To continue the shared case, the actor independently selects **Lanjutkan alur rutin simulasi**, writes a specific synthetic rationale, and records the decision. Use **Alihkan dalam simulasi** only in a separate disposable branch because it ends the routine case path.
4. Confirm the result becomes read-only with actor, role, time, rationale, source version/hash, and outcome.
5. Confirm resume changes the encounter to `WAITING_CLINICIAN`, readies the existing medical task, completes the actor task, and cancels the duplicate facilitator/supervisor task. In the separate transfer branch, confirm `TRANSFERRED_SIMULATION` and cancellation of routine medical work.
6. Attempt a late or competing disposition and confirm rejection without overwriting the first decision or partially changing tasks/state.

Discuss `VAL-A01`–`VAL-A04`: appropriateness of the question set, wording, authorized decision-maker, and allowed teaching dispositions. Passing this exercise does not approve an emergency workflow or clinical protocol.

### UAT-02C — patient-requested departure after clinical service begins

Actor: exact-case medical supervisor; the explicitly assigned session facilitator is the documented fallback. Use a separate disposable fixture because this action ends the routine case path.

1. Check in the appointment and begin nursing or medical service so the encounter is in an eligible active clinical state. Preserve at least one current clinical source version.
2. From the encounter overview, open **Catat pulang atas permintaan sendiri** and confirm the exact source encounter state plus current source IDs, versions, statuses, and hashes.
3. Confirm the page permanently says simulation only, distinguishes this event from cancellation/no-show, provides no clinical verdict or “safe to leave” statement, leaves confirmation unchecked, and explains that closure/coding/finalization are not automatic.
4. Explicitly confirm the event, enter the patient/representative-stated synthetic reason and a factual communication summary, then submit once.
5. Confirm the result becomes read-only with actor, role, time, reason, communication summary, source status, and hashes. Confirm the longitudinal event excludes both protected text fields.
6. Confirm encounter and appointment become `DEPARTED_ON_REQUEST`, check-in/prior completed work remain, active queue work ends, unfinished tasks cancel, and no closure, RMIK approval, coding assignment, final report, debrief release, or transmission is created.
7. Attempt a competing request and direct access as registrar, learner, unrelated supervisor, or revoked assignment; confirm rejection without overwriting the first record or partially changing state.

Discuss `VAL-A17`: whether the Indonesian vocabulary, authorized actors, form content, and future incomplete-record RMIK handling are pedagogically correct. Passing this exercise records feedback; it does not approve a clinical discharge protocol.

### UAT-03 — medical assessment, order, and prescription

Actors: medical learner, then medical supervisor.

1. Read the nursing handoff without editing it.
2. Record history, examination, clinician-authored diagnosis statement, plan, one synthetic service request, and one synthetic medication request.
3. Confirm a local edit exposes the same unsaved-change guard and that **Keluar tanpa perubahan lokal** requires an explicit destructive choice; remain on the form and retain the authored work for this run.
4. Submit the immutable medical version.
5. Supervisor reviews the exact source/version/hash and approves or requests correction.
6. Confirm result and pharmacy tasks remain tied to that approved medical source.

Observe `DOC-01`–`DOC-03`, `E2E-03`, `E2E-04`, `VAL-A06`, `VAL-A08`, and `VAL-A09`.

### UAT-04 — synthetic result release and acknowledgement

Actors: facilitator/result operator, then medical learner.

1. Release the pre-authored synthetic result.
2. Confirm result status/version, source service request, performer, clinical/effective time, and synthetic label.
3. Medical learner acknowledges the current result.
4. Confirm downstream work is not released before acknowledgement.

If a correction demonstration is required, use a separate disposable rehearsal; do not rewrite the result in place.

### UAT-05 — pharmacy review and dispensing

Actor: pharmacy learner; pharmacy supervisor observes the local supervision question.

1. Inspect derived patient, encounter, medication, prescriber, diagnosis-source, allergy, and version data.
2. Record separate administrative, pharmaceutical, and clinical review outcomes as human judgments.
3. Complete preparation/check/dispense with the synthetic stock lot.
4. Confirm review, dispense, and stock movement are attributable and transactional.

Discuss `VAL-A09` and `VAL-A10`. Do not infer clinical clearance from an empty deterministic validation list.

### UAT-06 — clinical closure

Actors: medical learner, then medical supervisor.

1. Record condition at closure, disposition, follow-up, education, summary, and performed-procedure source.
2. Confirm the unsaved-change guard is active before the first successful save and disappears after the server accepts the version baseline.
3. Submit the closure version.
4. Supervisor reviews the exact closure/procedure source and approves or requests correction.
5. Confirm ordinary clinical editing is locked after approval.

Observe `E2E-05`, `E2E-11`, `DOC-01`, and `VAL-A13`.

### UAT-07 — RMIK completeness review

Actors: RMIK coder, then RMIK supervisor.

1. Reproduce the checklist against current approved nursing, medical, result, pharmacy, and closure sources.
2. Confirm deterministic missing-source findings are distinct from authored manual findings.
3. Submit the versioned review with its source snapshot/hash.
4. Supervisor reviews the exact checklist version.

Discuss whether the checklist is appropriate for the curriculum and local forms (`VAL-A11`).

### UAT-08 — human-reviewed assisted coding and finalization

Actors: RMIK coder, then RMIK supervisor.

1. Open the clinician-authored diagnosis source and its ICD-10 release/version/checksum.
2. Generate/inspect ranked candidates or the honest no-candidate state.
3. Select, search manually, or reject with an attributed reason; never bulk accept.
4. Repeat independently for the performed-procedure source using ICD-9-CM.
5. Supervisor reviews each exact source hash, terminology release, suggestion/decision provenance, and assignment.
6. Confirm finalization remains blocked until all current sources are approved and then reaches `FINALIZED`.

Discuss `VAL-A12`, `VAL-A16`, candidate explanations, Indonesian aliases, ambiguity, and whether any threshold may be proposed. No pilot threshold is approved by this script.

### UAT-09 — debrief and reports

Actors: facilitator and all authorized participants.

1. During the active journey, an exact-case participant opens the longitudinal record and traces at least one registration event, one clinical source version, and one supervisor decision to exact actors/sources/times.
2. Confirm an unrelated/session-wide non-facilitator assignment neither sees the record link nor opens the route; confirm a session-wide facilitator can open it.
3. Exercise the stage and actor-program filters and confirm their counts/URL state remain presentation-only.
4. Confirm the debrief remains a distinct workspace, is unavailable before `FINALIZED`, and is released afterward.
5. In the finalized encounter, trace both human coding decisions and compare record provenance with the teaching-oriented debrief projection.
6. Facilitator creates and revises one shared debrief note; participant confirms read-only visibility and version history.
7. Confirm the rubric reference is visibly pending/non-scoring.
8. Open the outpatient summary and debrief-evidence report.
9. Confirm the permanent watermark, non-legal/non-FHIR statement, source-derived content, and human-reviewed ICD-10/ICD-9-CM presentation.
10. Daniel records the Checkpoint 2 decision to retain, revise, or remove the longitudinal record and each report (`VAL-U05`).

Discuss `VAL-T03`, `VAL-T06`, whether the longitudinal record is useful during care-flow learning, and whether its curated provenance is sufficient without exposing raw security/audit metadata.

### UAT-10 — local interoperability mapping preview

Actors: RMIK learner, facilitator, and one clinical/pharmacy representative.

1. From the finalized encounter, open **Pratinjau FHIR** and confirm `SIMULASI — DATA SINTETIS`, `BELUM DIKIRIM`, `Bundle · collection`, and the absent external endpoint remain visible.
2. Confirm the inventory contains Patient, Organization, Encounter, six vital Observations, Condition, ServiceRequest, result Observation, DiagnosticReport, MedicationRequest, pharmacy QuestionnaireResponse, MedicationDispense, Procedure, and preliminary Composition.
3. Trace one vital, diagnosis, result, medication, and procedure resource through the provenance ledger to its exact public source and source path.
4. Confirm `R42` and `38.99` are labelled as human-reviewed decisions and no invented SNOMED, KFA, IHS, practitioner, organization, or location identifier appears.
5. Inspect the validation findings for missing national identifiers, unrun SATUSEHAT profile validation, and each concept retained as local text.
6. Open and close the contained JSON inspector; confirm there is no send, connect, retry, credential, endpoint-edit, upload, or submit action.
7. With a role lacking `report.view` or the exact case context, confirm the route and navigation are unavailable/denied. Confirm the preview returns a conflict before finalization.
8. Daniel records whether the mapping is retained, revised, or deferred and which missing identifiers/terminologies require institutional owners.

This exercise validates an inspectable local teaching model only. It does not approve a SATUSEHAT profile, credential, sandbox transmission, legal Composition, or production connection.

## 7. Separate correction exercises

Run each correction in its own fresh disposable fixture. Never prepare diagnosis and procedure branches in the same reference fixture.

### UAT-C01 — coder-requested diagnosis-source clarification

1. Prepare the diagnosis correction state.
2. Coder confirms coding is blocked and the request names the exact condition/medical source and reason.
3. Original medical author creates a successor medical version with a required attributed change reason while locked occurrence time, orders, and prescriptions remain unchanged.
4. Linked medical supervisor reviews the successor.
5. Original closure author creates a successor closure pointing to the approved medical successor without changing the locked performed-procedure times; linked medical supervisor reviews it.
6. RMIK repeats completeness review; prior coding artifacts remain immutable/stale.
7. Coder and RMIK supervisor review new ICD-10 and ICD-9-CM suggestion/decision/assignment chains and confirm finalization.

### UAT-C02 — coder-requested performed-procedure clarification

1. Prepare the procedure correction state.
2. Coder confirms coding is blocked and the request names the exact performed procedure, closure, hashes, and reason.
3. Original closure author creates a successor closure changing only performed-procedure documentation; other closure fields, occurrence time, and unchanged performed times retain their exact seconds.
4. Linked medical supervisor reviews the successor closure.
5. RMIK repeats completeness review; prior procedure/coding artifacts remain immutable/stale.
6. Coder and RMIK supervisor review a new ICD-9-CM chain.

For both exercises, record the local decision on responsibility and timing (`VAL-A13`, `VAL-A14`, and the procedure-correction policy). The implementation is a working reference, not a pre-approved institutional policy.

## 8. Structured issue record

Use a dated copy of the [Outpatient Checkpoint 2 UAT Record Template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md). The blank template defaults every scenario to `NOT RUN`, every issue to `NOT CLASSIFIED`, and every Daniel decision to `NOT DECIDED`; the template itself is never evidence or acceptance.

Capture one record per observed issue:

| Field                   | Required content                                                                                   |
| ----------------------- | -------------------------------------------------------------------------------------------------- |
| Issue ID                | `UAT-YYYYMMDD-NNN`                                                                                 |
| Scenario/step           | Exact `UAT-*` step and screen/action                                                               |
| Acting role             | Demo role, never only the person's name                                                            |
| Encounter/source        | Synthetic encounter plus source/version/hash suffix where relevant                                 |
| Expected                | Acceptance condition or handoff expectation                                                        |
| Actual                  | Observable result without speculation                                                              |
| Impact                  | Safety, authorization, data integrity, workflow, terminology, teaching, accessibility, or cosmetic |
| Reproducibility         | Always, intermittent, or once; include concise steps                                               |
| Evidence                | Synthetic-only screenshot/request identifier/log reference                                         |
| Proposed classification | Stop-session, before-UAT-resume, before-pilot, or later enhancement                                |
| Decision                | Daniel's disposition, owner, and target checkpoint                                                 |

Do not copy passwords, session cookies, raw audit metadata, IP hashes, or private infrastructure details into the issue record.

## 9. Decision classification

| Classification               | Meaning                                                                                                                                                                             |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `STOP_SESSION`               | Real-data risk, cross-case disclosure, wrong-role access, destructive overwrite, autonomous clinical/final-coding decision, unrecoverable integrity loss, or unusable critical path |
| `MUST_FIX_BEFORE_UAT_RESUME` | Reference case cannot continue reliably or required evidence cannot be observed                                                                                                     |
| `MUST_FIX_BEFORE_PILOT`      | Safe workaround exists for review, but the issue violates an accepted pilot requirement                                                                                             |
| `DECISION_REQUIRED`          | Working behavior is configurable and safe, but Daniel/stakeholders must choose policy/content                                                                                       |
| `LATER_ENHANCEMENT`          | Useful improvement outside the accepted outpatient reference scope                                                                                                                  |
| `REJECTED_OUT_OF_SCOPE`      | Conflicts with the charter, synthetic-only boundary, or deferred-module sequence                                                                                                    |

Daniel records the final classification. A stakeholder suggestion does not automatically expand scope.

## 10. Exit record

Complete the exit section in the same dated [UAT record](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md); do not create a disconnected meeting summary that cannot be traced to scenario and issue IDs.

At the end, record:

- build/commit candidate and environment;
- scenario/session and synthetic encounter identifiers;
- participant roles present and any combined roles;
- each `UAT-*` result as `PASS`, `FAIL`, `NOT RUN`, or `DECISION REQUIRED`;
- issue IDs and Daniel's classifications;
- unresolved P0/P1 assumptions and owner;
- decision on the longitudinal record and both report views;
- decision on correction responsibility/timing;
- decision on assisted-coding aliases/gold-set/threshold next work;
- decision on longitudinal-record/debrief usefulness and rubric status;
- whether Checkpoint 2 is accepted, conditionally accepted, or requires another run; and
- explicit confirmation that no real data or production integration was used.

Checkpoint 2 acceptance does not authorize a faculty pilot. Checkpoint 3 still requires accessibility, security, hosted deployment/rollback, recovery, unresolved-risk, and institutional review evidence.

## 11. Known limitations to disclose before UAT

- The application is a reference teaching MVP, not a licensed production hospital EMR.
- The case content, safety questionnaire, rubric, coding aliases, gold-set proposals, and candidate threshold are not faculty-approved.
- Reports are browser-generated learning previews, not signed/immutable medical documents or SATUSEHAT submissions.
- The longitudinal record is a curated, read-only teaching projection with a 300-event display cap; it is not a legal record, clinical decision engine, or external interoperability transmission.
- The interoperability page is a deterministic local FHIR R4-aligned `collection` preview. It has no national identities, external-profile validator, endpoint, credential, transmission queue, legal-document status, or SATUSEHAT conformance evidence.
- Native print/PDF pagination review remains pending.
- Full native keyboard traversal remains pending. Automated regression now guards the complete sign-in Tab order, focusable password control, and programmatic validation-error associations. Internal browser rehearsal completed both functional correction routes through successor approval, replacement RMIK review, human coding review, resolution, and finalization. A later single retained browser tab/session repeated both complete branches across fresh isolated fixtures and ended with an empty retained warning/error console log. Stakeholder UAT is still required.
- No production SATUSEHAT/BPJS connection, complete billing/INA-CBG engine, disclosure workflow, or retention/reset policy is implemented.
- Emergency, inpatient, nutrition, psychology, physiotherapy, and other deferred programs/modules are not part of this checkpoint.
- Draft PR #10 passed the application, documentation, and MySQL 8.4 checks. Hostinger staging deployment/rollback remains pending hosting authorization and a separate deployment decision.

## 12. Related contracts

- [Checkpoint 2 UAT Record Template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Assumption and Validation Register](../product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
- [Outpatient Role Matrix](../product/OUTPATIENT_ROLE_MATRIX.md)
- [Outpatient Service Blueprint](../product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Outpatient Domain Spine Validation](OUTPATIENT_DOMAIN_SPINE_VALIDATION.md)
- [Computer-Assisted Coding Validation](COMPUTER_ASSISTED_CODING_VALIDATION.md)
- [Local MySQL and Recovery Validation](LOCAL_MYSQL_RECOVERY_VALIDATION.md)
- [ADR-004: Finalized Debrief Projection](../adr/ADR-004-FINALIZED-DEBRIEF-PROJECTION.md)
- [ADR-005: Debrief Notes and Rubric References](../adr/ADR-005-DEBRIEF-NOTES-AND-RUBRIC-REFERENCES.md)
- [ADR-006: Finalized Simulation Reporting](../adr/ADR-006-FINALIZED-SIMULATION-REPORTING.md)
- [ADR-007: Deterministic Local Interoperability Preview](../adr/ADR-007-LOCAL-INTEROPERABILITY-PREVIEW.md)
