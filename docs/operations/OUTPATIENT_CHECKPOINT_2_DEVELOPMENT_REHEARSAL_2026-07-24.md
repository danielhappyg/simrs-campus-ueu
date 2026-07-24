# Outpatient Checkpoint 2 Development Rehearsal — 24 July 2026

> **DEVELOPMENT EVIDENCE ONLY — NOT FACULTY UAT ACCEPTANCE, PILOT APPROVAL, MERGE AUTHORIZATION, OR DEPLOYMENT AUTHORIZATION.**

- **Record ID:** `DEV-UAT-RUN-20260724-01`
- **Owner and final decision authority:** Daniel Happy Putra, project manager/PIC
- **Execution model:** one development operator used the named demo roles in sequence
- **Data boundary:** synthetic patient and encounter data only
- **Workflow boundary:** main outpatient reference journey only; no Claims or E-Klaim workflow was exercised
- **Environment:** local simulation application at `127.0.0.1`
- **Base commit:** `a1aa46aea988fed4615df8b6e374518a5c327894`
- **Candidate delta:** procedure date-time preservation fix and regression contract described below
- **Session:** `LOCAL-TRY-20260724-001`
- **Encounter:** `ENC-SIM-NEM7MP3Q3B7Y`
- **Initial monitored phase:** `READY_TO_START`
- **Closeout monitored phase:** `FINALIZED`

This record is a structured facilitator/developer rehearsal against the
[Checkpoint 2 guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md). It creates a concrete
model for Daniel to review before stakeholder sessions. It does not replace
faculty, privacy, security, accessibility, or institutional validation.

## Safety and source boundaries

The following were observed:

- the permanent `SIMULASI — DATA SINTETIS` boundary remained visible;
- all accounts, patient details, results, medicines, and encounter identifiers were synthetic;
- the selected disposable session was visible in each role-specific queue;
- no production hospital, SATUSEHAT, BPJS, email, or messaging endpoint was used;
- the two supplied workbooks were used only as outpatient coding terminology references;
- assisted coding exposed candidate/no-candidate states and never finalized a code automatically; and
- no browser warning or error was present in the final 100-entry warning/error log query.

The supplied terminology sources matched the approved checksums before import:

| System   | Release       | Concepts | SHA-256                                                            |
| -------- | ------------- | -------: | ------------------------------------------------------------------ |
| ICD-10   | `ICD10_2010`  |   18,543 | `3c22aa15012dd2e15576657e49001291fd21a5b30ce797998a495aac548c5f4e` |
| ICD-9-CM | `ICD9CM_2010` |    4,626 | `9f625ada077b198e75e5f6a51596191cb9de94be198a967cedf07a52e08f8d78` |

No workbook-derived diagnosis or procedure code was written without a separate
human coder decision and linked RMIK supervisor review.

## Role and handoff coverage

| Sequence | Demo role                   | Observable handoff                                                                                                                                               |
| -------: | --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
|        1 | Registration learner        | Checked in the synthetic appointment; encounter moved from `PLANNED` to `ARRIVED`.                                                                               |
|        2 | Nursing learner             | Submitted initial assessment v1 with complaint, duration, consciousness, allergy/medicine attestations, six vital signs, concern state, flow, note, and handoff. |
|        3 | Nursing supervisor          | Reviewed and approved exact nursing v1/hash.                                                                                                                     |
|        4 | Medical learner             | Submitted medical assessment v1, clinician-authored diagnosis text, synthetic laboratory order, prescription, education, follow-up, and disposition.             |
|        5 | Medical supervisor          | Reviewed and approved exact medical v1/hash; later reviewed the exact closure successor versions.                                                                |
|        6 | Facilitator/result operator | Released one pre-authored synthetic result; the requesting medical learner acknowledged the current result.                                                      |
|        7 | Pharmacy learner            | Completed the ten-item human review and atomic FEFO dispensing of six synthetic tablets.                                                                         |
|        8 | Pharmacy supervisor         | Not operated in this rehearsal. Independent checker/supervisor staffing remains a validation item.                                                               |
|        9 | RMIK coder                  | Submitted versioned completeness review and made separate manual ICD-10 and ICD-9-CM assignments after honest no-reliable-candidate results.                     |
|       10 | RMIK supervisor             | Approved the exact checklist version/hash and each exact coding source/release/assignment hash.                                                                  |

One development operator combined the roles above. This is not evidence that
combined staffing is acceptable for a faculty pilot.

## Immutable source evidence

| Source                        | Final observed state         | Safe evidence           |
| ----------------------------- | ---------------------------- | ----------------------- |
| Nursing assessment            | v1 `APPROVED`                | `5ee06f1ca1ef…f55efa46` |
| Medical assessment            | v1 `APPROVED`                | `7c266e2d675a…c0276b38` |
| Synthetic result              | v1 acknowledged              | `983e713539ed…88b51edc` |
| Pharmacy review               | completed                    | `23066d…2c23f15a`       |
| Dispensing                    | `COMPLETED`, stock 100 to 94 | `eb5855f43187…d14072e6` |
| Closure                       | v4 `APPROVED`                | `e7c4074defbb…18b03c81` |
| Performed procedure           | 16:00–16:10 WIB              | `603da898bfd4…0a2f52aa` |
| RMIK completeness             | v1 `APPROVED`, `OPD-COMP-v2` | `84d041d2bdc1…066f99d6` |
| ICD-10 diagnosis assignment   | `R42`, `APPROVED`, manual    | `fcd16c760a71…3e2e7f94` |
| ICD-9-CM procedure assignment | `38.99`, `APPROVED`, manual  | `bd2b3a2ed344…2be34497` |

The coding engine returned `Tidak ada kandidat andal` for both the Indonesian
diagnosis statement and performed-procedure statement. The coder then searched
the active releases manually, selected `R42 — Dizziness and giddiness` and
`38.99 — Other puncture of vein` separately, documented a rationale for each,
and submitted each assignment separately. This is evidence of a human-reviewed
fallback, not evidence of automatic-coding accuracy.

## Scenario accounting

`PASS` below means only that the named main-journey behavior was observed in
this development rehearsal. It does not mean stakeholder acceptance.

| Scenario                                       | Result              | Observable result or limitation                                                                                                                              |
| ---------------------------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| UAT-00 — safety and assignment check           | `PASS`              | Synthetic boundary, selected-session queues, ten active assignments, and initial `READY_TO_START` monitor state were observed.                               |
| UAT-01 — registration and check-in             | `PASS`              | Appointment check-in created the active queue path and moved the encounter to `ARRIVED`.                                                                     |
| UAT-01A — cancellation after check-in          | `NOT RUN`           | Requires a separate disposable fixture.                                                                                                                      |
| UAT-01B — overdue no-show                      | `NOT RUN`           | Requires a separate disposable fixture.                                                                                                                      |
| UAT-02 — nursing assessment and draft guard    | `PASS`              | Versioned nursing source was submitted and approved by the linked supervisor.                                                                                |
| UAT-02B — human safety disposition             | `NOT RUN`           | Optional branch not exercised.                                                                                                                               |
| UAT-02C — patient-requested departure          | `NOT RUN`           | Requires a separate disposable fixture.                                                                                                                      |
| UAT-03 — medical assessment/order/prescription | `PASS`              | Versioned clinician source, order, prescription, and supervisor approval were observed.                                                                      |
| UAT-04 — synthetic result release              | `PASS`              | Facilitator release and exact-result acknowledgement were observed.                                                                                          |
| UAT-05 — pharmacy review and dispensing        | `DECISION REQUIRED` | Human review and atomic dispensing passed, but the same learner appeared as preparer/checker and no independent pharmacy supervisor was operated.            |
| UAT-06 — clinical closure                      | `PASS`              | Correction history remained immutable; closure v4 stored and displayed the 16:00–16:10 performed-procedure interval and was approved against its exact hash. |
| UAT-07 — RMIK completeness review              | `PASS`              | `OPD-COMP-v2` was reproduced, submitted without manual findings, and approved against closure v4/hash.                                                       |
| UAT-08 — human-reviewed coding/finalization    | `PASS`              | Separate manual `R42` and `38.99` assignments were supervisor-approved; monitor reported `FINALIZED`.                                                        |
| UAT-09 — longitudinal record/debrief/reports   | `NOT RUN`           | Finalized debrief and filter/report exercise remains pending.                                                                                                |
| UAT-10 — local interoperability preview        | `NOT RUN`           | Not exercised in this run.                                                                                                                                   |
| UAT-C01 — diagnosis-source correction          | `NOT RUN`           | Dedicated correction branch not exercised.                                                                                                                   |
| UAT-C02 — performed-procedure correction       | `NOT RUN`           | Dedicated post-coding documentation-correction branch not exercised.                                                                                         |

## Issue DEV-UAT-20260724-001 — native procedure date-time state

| Field                 | Entry                                                                                                                                                                  |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Scenario/step         | UAT-06, authoring a closure successor with performed-procedure start/end                                                                                               |
| Acting role           | Medical learner                                                                                                                                                        |
| Expected              | Visible start and end values remain exact and the submitted immutable successor stores the same interval.                                                              |
| Actual before fix     | Closure v1–v3 retained the previous start (`16:17`) and empty end despite a visible edit attempt; the medical supervisor correctly requested changes each time.        |
| Impact                | Data integrity and provenance                                                                                                                                          |
| Reproducibility       | Reproduced when native date-time edits occurred before React form state had committed the latest controls.                                                             |
| Reporter proposal     | `MUST_FIX_BEFORE_UAT_RESUME`                                                                                                                                           |
| Daniel classification | `NOT CLASSIFIED`                                                                                                                                                       |
| Candidate resolution  | Preserve exact procedure instants, allow second precision, merge field updates against previous form state, and reconcile native date-time values on blur.             |
| Regression evidence   | `closure-procedure-time-contract.test.ts`; focused six-test validation; browser rerun through closure v4.                                                              |
| Resolution result     | Closure v4 stored `2026-07-24 16:00:00` through `2026-07-24 16:10:00`; supervisor UI displayed `24 Jul 2026, 16.00 WIB – 24 Jul 2026, 16.10 WIB`; exact hash approved. |

The failed v1–v3 records and their supervisor decisions were intentionally
preserved. No database record was edited to manufacture the passing result.

## Closeout monitor

The final read-only session monitor reported:

- status `OK`;
- phase `FINALIZED`;
- encounter status `FINALIZED`;
- 30 total tasks;
- 20 completed tasks;
- 10 ready debrief tasks;
- zero waiting, blocked, in-progress, submitted, or changes-requested tasks; and
- no attention flags.

Task counts are operational evidence only. They are not a percentage, learner
score, grade, competence statement, or acceptance decision.

## Known limitations and next validation

- Pharmacy independent checking/supervision remains unresolved for the faculty-pilot profile.
- Native-browser keyboard-only operation, printing, responsive layouts, zoom, and assistive-technology behavior were not accepted by this run.
- UAT-01A, UAT-01B, UAT-02B, UAT-02C, UAT-09, UAT-10, UAT-C01, and UAT-C02 remain `NOT RUN`.
- The exact in-app browser version and viewport were not captured.
- No stakeholder has approved the terminology, forms, teaching suitability, staffing model, or coding curriculum.
- No merge, deployment, Hostinger change, or faculty-pilot authorization is created by this record.

Daniel must review the issue classification, the pharmacy staffing limitation,
and the remaining scenarios before deciding whether this evidence is retained,
revised, or used to schedule a formal Checkpoint 2 session.
