# Outpatient Checkpoint 2 UAT Record Template

> **BLANK TEMPLATE — NOT UAT EVIDENCE, ACCEPTANCE, OR RELEASE APPROVAL.** Make a dated copy for an authorized synthetic-only session. Every result starts as `NOT RUN`; every product decision starts as `NOT DECIDED`.

- **Template owner:** Daniel Happy Putra, project manager/PIC
- **Purpose:** capture bounded Checkpoint 2 evidence against the [facilitator guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- **Data boundary:** synthetic patient/session data only; no production integration
- **Decision boundary:** Daniel classifies scope and acceptance. Participant feedback does not authorize scope, pilot use, merge, or deployment.

## 1. How to use this record

1. Create one dated copy per UAT run. Never overwrite a prior completed record.
2. Keep every scenario at `NOT RUN` until the named steps are actually observed.
3. Record observable facts and evidence references, not inferred causes.
4. Create one issue record for each failed condition, unsafe ambiguity, missing handoff, or decision request.
5. Use roles in the participant table. Keep personal names, contact details, credentials, cookies, raw audit data, and infrastructure secrets outside this record.
6. Store screenshots and detailed logs only in an institution-approved restricted location. Reference them with a sanitized evidence ID.
7. Daniel records final issue classifications and exit decisions after reviewing the evidence.
8. A completed Checkpoint 2 record does not authorize a faculty pilot; Checkpoint 3 remains separate.

Allowed scenario statuses: `NOT RUN`, `PASS`, `FAIL`, `DECISION REQUIRED`.

Allowed issue classifications: `NOT CLASSIFIED`, `STOP_SESSION`, `MUST_FIX_BEFORE_UAT_RESUME`, `MUST_FIX_BEFORE_PILOT`, `DECISION_REQUIRED`, `LATER_ENHANCEMENT`, `REJECTED_OUT_OF_SCOPE`.

Allowed Daniel decision states: `NOT DECIDED`, `RETAIN`, `REVISE`, `REMOVE`, `DEFER`, `REJECT`.

## 2. Run identity

| Field | Entry |
| --- | --- |
| Record ID | `<UAT-RUN-YYYYMMDD-NN>` |
| Local date/time and timezone | `<YYYY-MM-DD HH:MM Asia/Jakarta>` |
| Facilitator role | `<role; no password or personal contact>` |
| Candidate commit | `<40-character commit>` |
| Release-candidate/artifact reference | `<artifact ID or NOT USED>` |
| Environment identifier | `<isolated simulation environment>` |
| Application mode | `<must be SIMULATION>` |
| Synthetic-only configuration | `<must be true>` |
| Database/reset reference | `<fresh fixture or approved snapshot ID>` |
| Scenario/session identifier | `<synthetic public identifier>` |
| Encounter identifier | `<synthetic public identifier>` |
| ICD-10 release/checksum suffix | `<visible release and safe hash suffix>` |
| ICD-9-CM release/checksum suffix | `<visible release and safe hash suffix>` |
| Browser/viewport | `<supported browser, version, viewport, zoom>` |
| Recovery evidence | `<snapshot/reset reference>` |
| Evidence location | `<restricted location identifier; never credentials or URL tokens>` |

## 3. Entry and safety attestations

Record `NOT CONFIRMED`, `CONFIRMED`, or `FAILED`. Any `FAILED` safety item stops the session and requires an issue record.

| Gate | Initial status | Evidence/reference |
| --- | --- | --- |
| Isolated simulation environment | `NOT CONFIRMED` | `<reference>` |
| Permanent `SIMULASI — DATA SINTETIS` boundary visible | `NOT CONFIRMED` | `<reference>` |
| Fresh/snapshotted synthetic fixture | `NOT CONFIRMED` | `<reference>` |
| Exact tested commit identified | `NOT CONFIRMED` | `<reference>` |
| Required demo assignments available | `NOT CONFIRMED` | `<reference>` |
| No real patient or participant-sensitive data entered | `NOT CONFIRMED` | `<reference>` |
| No production endpoint, credential, or transmission enabled | `NOT CONFIRMED` | `<reference>` |
| Limitations in facilitator-guide section 11 disclosed | `NOT CONFIRMED` | `<reference>` |
| Stop-session rule understood | `NOT CONFIRMED` | `<reference>` |

## 4. Participant-role coverage

Do not enter personal names in the repository copy. If one person operates multiple roles during a development rehearsal, mark every combined role explicitly; this does not approve combined staffing for a faculty pilot.

| Sequence | Demo role | Present status | Combined-role note | Assignment/capability observed |
| ---: | --- | --- | --- | --- |
| 1 | Registration learner | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 2 | Nursing learner | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 3 | Nursing supervisor | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 4 | Medical learner | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 5 | Medical supervisor | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 6 | Facilitator/result operator | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 7 | Pharmacy learner | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 8 | Pharmacy supervisor | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 9 | RMIK coder | `NOT RECORDED` | `<none or role>` | `<reference>` |
| 10 | RMIK supervisor | `NOT RECORDED` | `<none or role>` | `<reference>` |

## 5. Scenario results

`PASS` requires the complete named scenario, not a nearby screen or a subset of steps. Use separate disposable fixtures where the guide requires them.

| Scenario | Initial status | Evidence IDs | Issue IDs | Concise observable result |
| --- | --- | --- | --- | --- |
| UAT-00 — safety and assignment check | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-01 — registration and check-in | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-01A — cancellation after check-in | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-01B — overdue no-show | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-02 — nursing assessment and draft guard | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-02B — human safety disposition | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-02C — patient-requested departure | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-03 — medical assessment/order/prescription | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-04 — synthetic result release | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-05 — pharmacy review and dispensing | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-06 — clinical closure | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-07 — RMIK completeness review | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-08 — human-reviewed coding/finalization | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-09 — longitudinal record/debrief/reports | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-10 — local interoperability preview | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-C01 — diagnosis-source correction | `NOT RUN` | `<none>` | `<none>` | `<result>` |
| UAT-C02 — performed-procedure correction | `NOT RUN` | `<none>` | `<none>` | `<result>` |

## 6. Issue records

Copy this block once per issue. Do not combine unrelated observations merely because they occurred on the same screen.

### `<UAT-YYYYMMDD-NNN>`

| Field | Entry |
| --- | --- |
| Scenario/step | `<exact UAT-* step and action>` |
| Acting role | `<demo role>` |
| Encounter/source | `<synthetic encounter and source/version/hash suffix>` |
| Expected | `<linked acceptance condition>` |
| Actual | `<observable result without speculation>` |
| Impact domain | `<safety, authorization, data integrity, workflow, terminology, teaching, accessibility, cosmetic>` |
| Reproducibility | `<always, intermittent, once; concise steps>` |
| Evidence IDs | `<synthetic-only screenshot/request/log references>` |
| Reporter proposal | `<suggested classification; not final>` |
| Daniel classification | `NOT CLASSIFIED` |
| Owner | `<role/team or UNASSIGNED>` |
| Target checkpoint | `<UAT resume, faculty pilot, later, none>` |
| Resolution evidence | `<commit/test/rerun reference or NOT RESOLVED>` |

## 7. Validation and product decisions

Only Daniel may change `NOT DECIDED` to a final decision state. Link the issue/evidence and state the affected scope; do not silently rewrite the source assumption register from meeting notes.

| Decision item | Initial state | Evidence/issue IDs | Daniel rationale and affected scope |
| --- | --- | --- | --- |
| VAL-A01–A04 — intake/safety/disposition | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-A05–A10 — nursing/medical/result/pharmacy content | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-A11–A12 — completeness and coding curriculum | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-A13–A14 — correction responsibility/timing | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-A15 — public queue identity | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-A16 — coding aliases/gold set/threshold | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-A17 — early-departure vocabulary/actor/incomplete record | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-T02/T07 — staffing and one-case session model | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-T03/T06 — rubric and debrief usefulness | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-T05 — class size/session duration target | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-U02/U04 — terminology and accessibility | `NOT DECIDED` | `<none>` | `<decision>` |
| VAL-U05 — outpatient summary | `NOT DECIDED` | `<none>` | `<retain, revise, remove, or defer>` |
| VAL-U05 — debrief-evidence report | `NOT DECIDED` | `<none>` | `<retain, revise, remove, or defer>` |
| Longitudinal teaching record | `NOT DECIDED` | `<none>` | `<retain, revise, remove, or defer>` |
| Local FHIR-aligned preview | `NOT DECIDED` | `<none>` | `<retain, revise, remove, or defer>` |

## 8. Unresolved P0/P1 risks

Every unresolved P0/P1 item needs an owner and next evidence step. `NONE IDENTIFIED` is allowed only after the issue and validation-decision sections are reviewed.

| Risk/validation ID | Level | Current evidence | Owner | Required action and target date/checkpoint |
| --- | --- | --- | --- | --- |
| `<ID or NONE IDENTIFIED>` | `<P0/P1>` | `<reference>` | `<owner>` | `<action>` |

## 9. Exit record and Daniel decision

| Field | Entry |
| --- | --- |
| All planned scenarios accounted for | `NOT CONFIRMED` |
| All issue records classified | `NOT CONFIRMED` |
| Real-data/production-integration boundary preserved | `NOT CONFIRMED` |
| Unresolved P0 count | `<number>` |
| Unresolved P1 count | `<number>` |
| Checkpoint 2 outcome | `NOT DECIDED` |
| Allowed outcomes | `ACCEPTED`, `CONDITIONALLY ACCEPTED`, `REQUIRES ANOTHER RUN` |
| Conditions/required reruns | `<issue and scenario IDs>` |
| Merge authorization | `NOT AUTHORIZED BY THIS RECORD` |
| Deployment authorization | `NOT AUTHORIZED BY THIS RECORD` |
| Faculty-pilot authorization | `NOT AUTHORIZED BY THIS RECORD` |
| Daniel decision date/timezone | `<NOT RECORDED>` |
| Daniel decision note | `<NOT RECORDED>` |

## 10. Change-control follow-through

For each accepted material decision:

1. retain the original question in the [Assumption and Validation Register](../product/ASSUMPTION_AND_VALIDATION_REGISTER.md);
2. record decision date, decider, and evidence;
3. link affected requirement and scenario IDs;
4. update product/design/data/policy contracts and tests;
5. use an ADR for material architecture or safety-boundary changes; and
6. require a new tested commit and rerun for every `MUST_FIX_BEFORE_UAT_RESUME` item.

This record remains evidence of a synthetic teaching-workflow review only. It is not a clinical validation, legal sign-off, institutional privacy/security approval, merge instruction, or deployment instruction.
