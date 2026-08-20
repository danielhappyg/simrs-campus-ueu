# Outpatient Checkpoint 2 UAT Record — 20 August 2026 Draft

> **DRAFT RUN COPY — NOT COMPLETED UAT EVIDENCE, ACCEPTANCE, OR RELEASE APPROVAL.** Derived from the blank template for the next authorized synthetic-only session. Every result starts as `NOT RUN`; every product decision starts as `NOT DECIDED`.

- **Template owner:** Daniel Happy Putra, project manager/PIC
- **Purpose:** capture bounded Checkpoint 2 evidence against the [facilitator guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- **Data boundary:** synthetic patient/session data only; no production integration
- **Decision boundary:** Daniel classifies scope and acceptance. Participant feedback does not authorize scope, pilot use, merge, or deployment.

## 1. How to use this record

1. Use this dated copy only for one authorized UAT run. Never overwrite a prior completed record.
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
| Record ID | `UAT-RUN-20260820-01` |
| Local date/time and timezone | `<YYYY-MM-DD HH:MM Asia/Jakarta>` |
| Facilitator role | `<role; no password or personal contact>` |
| Candidate commit | `7d667de` (residual evidence tip on `main`; primary afternoon journey was earlier the same day) |
| Release-candidate/artifact reference | `NOT USED` |
| Environment identifier | `local isolated Laravel fixture` |
| Hosting topology | `local isolated` (+ synthetic demo published at https://simrs-campus-ueu-demo.vercel.app tip `7d667de`; campus production TBD) |
| Application mode | `SIMULATION` |
| Synthetic-only configuration | `true` |
| Database/reset reference | `reserved demo access enabled; active rehearsal fixture retained locally` |
| Scenario/session identifier | `LAB-REHEARSAL-001` (primary); residual addendum `CP2-RESIDUAL-001` / `CP2-RESIDUAL-002` |
| Encounter identifier | `ENC-SIM-Q0XZYBHFMREY` (primary); residual finalized `ENC-SIM-TQT4QNY118PJ` |
| Initial session-monitor phase | `OK / READY_TO_START` |
| Closeout session-monitor phase | `FINALIZED` (primary afternoon + residual `CP2-RESIDUAL-001`) |
| ICD-10 release/checksum suffix | `<visible release and safe hash suffix>` |
| ICD-9-CM release/checksum suffix | `<visible release and safe hash suffix>` |
| Dependency-hygiene record | `DEPENDENCY_HYGIENE_BASELINE_2026-08-20.md` |
| Browser/viewport | `local browser rehearsal check on 127.0.0.1:8030; desktop viewport; exact browser/version still to record` |
| Recovery evidence | `<snapshot/reset reference>` |
| Evidence location | `<restricted location identifier; never credentials or URL tokens>` |

## 3. Entry and safety attestations

Record `NOT CONFIRMED`, `CONFIRMED`, or `FAILED`. Any `FAILED` safety item stops the session and requires an issue record.

| Gate | Initial status | Evidence/reference |
| --- | --- | --- |
| `simulation:lab-preflight` reports `READY` with zero failures | `CONFIRMED` | `DEV-ENTRY-GATE-20260820-01` |
| `simulation:lab-session-status` reports `OK` / `READY_TO_START` for the exact disposable session | `CONFIRMED` | `LAB-REHEARSAL-001 status JSON` |
| Isolated simulation environment | `CONFIRMED` | `local isolated Laravel fixture` |
| Hosting topology recorded (local or current Vercel + Supabase demo; not campus production) | `CONFIRMED` | `local isolated` |
| Dependency-hygiene evidence recorded for the exact candidate branch | `CONFIRMED` | `DEPENDENCY_HYGIENE_BASELINE_2026-08-20.md` |
| `composer audit` and `npm audit --omit=dev` are clean for the exact candidate | `CONFIRMED` | `dependency baseline record` |
| Permanent `SIMULASI — DATA SINTETIS` boundary visible | `CONFIRMED` | `hosted demo /login banner + copy on simrs-campus-ueu-demo.vercel.app; earlier local /login on 127.0.0.1:8030` |
| Fresh/snapshotted synthetic fixture | `NOT CONFIRMED` | `<reference>` |
| Exact tested commit identified | `CONFIRMED` | `7d667de on main for residual addendum; primary afternoon journey predated tip` |
| Required demo assignments available | `CONFIRMED` | `10 active assignments; 4 tasks; ready REGISTRATION handoff` |
| No real patient or participant-sensitive data entered | `NOT CONFIRMED` | `<reference>` |
| No production endpoint, credential, or transmission enabled | `NOT CONFIRMED` | `<reference>` |
| Limitations in facilitator-guide section 11 disclosed | `NOT CONFIRMED` | `<reference>` |
| Stop-session rule understood | `NOT CONFIRMED` | `<reference>` |

Session-monitor task counts are operational evidence only. Do not convert them into a percentage, score, grade, pass/fail result, or acceptance decision.

## 4. Participant-role coverage

Do not enter personal names in the repository copy. If one person operates multiple roles during a development rehearsal, mark every combined role explicitly; this does not approve combined staffing for a faculty pilot.

| Sequence | Demo role | Present status | Combined-role note | Assignment/capability observed |
| ---: | --- | --- | --- | --- |
| 1 | Registration learner | `RECORDED` | `<none or role>` | `Mahasiswa RMIK Demo on /work?session=LAB-REHEARSAL-001 with one ready registration task` |
| 2 | Nursing learner | `RECORDED` | `<none or role>` | `Mahasiswa Keperawatan Demo on /work?session=LAB-REHEARSAL-001 with one ready Asesmen Awal dan Skrining Keselamatan task for ENC-SIM-Q0XZYBHFMREY` |
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
| UAT-00 — safety and assignment check | `DECISION REQUIRED` | `automated WorkQueueTest / WorkTaskInvariantTest / Interop-Timeline-Debrief deny suites; residual actingAs probes; hosted demo login banner` | `<none>` | `On tip da2d15c: targeted deny/isolation PHPUnit green. Residual actingAs probes documented in §8A. Hosted demo https://simrs-campus-ueu-demo.vercel.app/login shows permanent top banner SIMULASI — DATA SINTETIS plus Indonesian synthetic-data teaching copy (browser snapshot 20 Aug 2026 evening). Facilitator confirmation of only-assigned capabilities after sign-in on a disposable session remains outstanding before PASS.` |
| UAT-01 — registration and check-in | `DECISION REQUIRED` | `local authenticated queue, encounter, and session-status observation` | `<none>` | `Search returned one explicit synthetic candidate, the shared rehearsal encounter advanced to ARRIVED, provenance recorded appointment_check_in for ENC-SIM-Q0XZYBHFMREY, and command-line session status released NURSING_INTAKE as the next ready task; the remaining explicit Daniel/facilitator decision is whether this local rehearsal is sufficient to mark the full scenario PASS without a separately recorded second-shared-case rejection step.` |
| UAT-01A — cancellation after check-in | `DECISION REQUIRED` | `AppointmentTerminationTest (arrived cancellation preserves check-in); tip da2d15c` | `<none>` | `41+14 branch-related PHPUnit cases green on tip da2d15c including arrived-cancellation atomicity. Facilitator/browser observation of Akhiri kunjungan after check-in on a fresh disposable fixture remains outstanding before PASS.` |
| UAT-01B — overdue no-show | `DECISION REQUIRED` | `AppointmentTerminationTest overdue no-show; CloneReferenceSession --appointment-offset=-5; fixture UAT-NO-SHOW-001` | `<none>` | `Local tip da2d15c prepared UAT-NO-SHOW-001 with BOOKED appointment already due (scheduledAt before session start) and session monitor READY_TO_START / REGISTRATION ready. Feature tests cover no-show without queue creation and future no-show rejection. Browser confirmation of Tandai tidak hadir availability + future-control rejection remains outstanding before PASS.` |
| UAT-02 — nursing assessment and draft guard | `DECISION REQUIRED` | `local authenticated nursing-intake and supervisor-review observation` | `<none>` | `Nursing learner opened /encounters/.../nursing-intake for ENC-SIM-Q0XZYBHFMREY under LAB-REHEARSAL-001; human-only safety boundary was visible; Simpan draf created Versi 1 · nursing-intake.v1 (SHA-256 ...b7aa31c6); Ajukan untuk tinjauan created immutable Versi 2 · nursing-intake.v1 (SHA-256 ...73399f7d); nursing supervisor opened clinical-entry-versions/.../review for that exact Versi 2 hash and recorded Persetujuan simulasi (Setujui untuk simulasi) at 20 Agu 2026, 14.07 WIB; version status became Disetujui untuk simulasi; session status advanced encounter to WAITING_CLINICIAN and released MEDICAL_ASSESSMENT for MEDICINE LEARNER. Full draft-guard rehearsal (dirty-nav / Back / expired-session branches) remains outstanding before PASS.` |
| UAT-02B — human safety disposition | `DECISION REQUIRED` | `OutpatientSafetyDispositionWorkflowTest (14 cases) on tip da2d15c` | `<none>` | `Safety-disposition workflow Feature suite passed (14). Facilitator/browser observation of the safety-disposition task after an escalated nursing branch remains outstanding before PASS.` |
| UAT-02C — patient-requested departure | `DECISION REQUIRED` | `OutpatientEarlyDepartureWorkflowTest on tip da2d15c` | `<none>` | `Early-departure append-only provenance, capability gates, and conflict Feature coverage passed. Facilitator/browser observation of Pulang atas permintaan sendiri copy/boundaries remains outstanding before PASS.` |
| UAT-03 — medical assessment/order/prescription | `DECISION REQUIRED` | `local authenticated medical-assessment and medical supervisor-review observation` | `<none>` | `Medicine learner opened medical-assessment for ENC-SIM-Q0XZYBHFMREY under LAB-REHEARSAL-001 with read-only nursing handoff Versi 2 hash ...73399f7d visible; authored history/exam/diagnosis/plan plus one laboratory service request and one medication request; Simpan draf created Versi 1 · medical-assessment.v1 (SHA-256 ...90e40335); Ajukan untuk tinjauan created Versi 2 Diajukan untuk ditinjau (same hash); medical supervisor Persetujuan simulasi at 20 Agu 2026, 14.14 WIB set Disetujui untuk simulasi; session status advanced encounter to AWAITING_RESULT and released SYNTHETIC_RESULT_RELEASE for FACILITATOR. Dirty-nav draft-guard branch remains outstanding before PASS.` |
| UAT-04 — synthetic result release | `DECISION REQUIRED` | `local authenticated order-results release and acknowledgement observation` | `<none>` | `Facilitator released LAB-SIM-CP2-001 / Panel darah sintetis Checkpoint 2 as Final untuk simulasi (SHA-256 ...c05e46dc) tied to approved medical source hash ...90e40335 on ENC-SIM-Q0XZYBHFMREY; medicine learner acknowledged current result version; session status advanced encounter to AWAITING_PHARMACY and released PHARMACY_REVIEW for PHARMACY LEARNER. Correction-branch demonstration remains NOT RUN.` |
| UAT-05 — pharmacy review and dispensing | `DECISION REQUIRED` | `local pharmacy workspace browser review + authenticated workflow-service dispense evidence` | `UAT-20260820-001`, `UAT-20260820-002` | `Pharmacy learner recorded ACCEPT human review (all CLEAR criteria) in browser on ENC-SIM-Q0XZYBHFMREY. Seeded stock matched only Obat Simulasi A while the approved prescription authored Parasetamol tablet sintetis, so FEFO lot select was empty until a session-scoped synthetic lot LOT-SIM-PARA-CP2-001 was added. Preparation v1 (hash ...bc0f0306) and supervisor FINAL_CHECK then completed via PharmacyWorkflowService after Chrome remote-debugging permission blocked further browser automation; dispense public id 01M0F0S5VPYN2JCHKB76GC57RX. Orphan DRAFT medication request from medical Versi 1 then blocked encounter advancement until cancelled; encounter advanced to IN_CONSULTATION with ENCOUNTER_CLOSURE ready.` |
| UAT-06 — clinical closure | `DECISION REQUIRED` | `authenticated EncounterClosureService submit/approve after readiness remediation` | `UAT-20260820-002`, `UAT-20260820-003` | `After cancelling orphan DRAFT service request 01M0F08B2JNG15VVGV5DSJ0EY0 from medical Versi 1, closure readiness passed. Medicine learner submitted EncounterClosure 01M0F1FBBXYYQSWSPF4TDR00MJ v1 (hash ...048555a5); medical supervisor APPROVE_SIMULATION set APPROVED; encounter became CLINICALLY_CLOSED and session released RECORD_REVIEW for RMIK CODER. Browser UI path not re-run for this stage after CDP permission block.` |
| UAT-07 — RMIK completeness review | `DECISION REQUIRED` | `authenticated RecordQualityWorkflowService submit/approve` | `<none>` | `koder.rmik submitted RecordQualityReview 01M0F1N14GJ7CAFZADD8Z3D8S2 v1 (hash ...f7a1a7f4) with empty manual findings against a passing completeness checklist; supervisor.rmik APPROVE_SIMULATION set APPROVED; encounter remained RECORD_REVIEW and session released CODING for RMIK CODER. Browser UI path not re-run for this stage; VAL-A11 curriculum checklist discussion remains outstanding.` |
| UAT-08 — human-reviewed coding/finalization | `DECISION REQUIRED` | `authenticated CodingSuggestionService + CodingWorkflowService observation` | `UAT-20260820-004` | `koder.rmik generated diagnosis suggestions for approved medical condition 01M0F08PV02C8TFK1R8RC4818Q; engine returned NO_RELIABLE_CANDIDATE for Indonesian authored text Faringitis akut against English ICD-10 displays. ManualAlternative selected active J02.9 (Acute pharyngitis, unspecified) from ICD10_2010 release hash ...548c5f4e into CodingAssignment 01M0F4540AAQX7JE8XJ8NXRTRN (hash ...9e058b44); submit then supervisor.rmik APPROVE_SIMULATION set APPROVED. Procedure source was NonePerformed so no ICD-9-CM assignment. Encounter advanced to FINALIZED and session phase FINALIZED with DEBRIEF ready for all ten assignments.` |
| UAT-09 — longitudinal record/debrief/reports | `DECISION REQUIRED` | `authenticated timeline/debrief/report route probes + DebriefNoteService; residual CP2-RESIDUAL-001 probes` | `<none>` | `After FINALIZED, facilitator and medicine learner both received HTTP 200 for /timeline, /debrief, outpatient-summary, and debrief-evidence on the afternoon session. Residual tip 7d667de: simulation:complete-reference-journey on CP2-RESIDUAL-001 → ENC-SIM-TQT4QNY118PJ FINALIZED; actingAs facilitator/medicine timeline+debrief+interop on that finalized encounter HTTP 200; debrief/interop on other-session PLANNED encounter ENC-SIM-G175X40NXTAW HTTP 409; Feature tests for same-role/other-session and wrong-case deny remain green (32 targeted cases). Observed disposable-session browser deny + VAL-U05 retain/revise/remove still outstanding before PASS (Cursor browser MCP could not reach localhost).` |
| UAT-10 — local interoperability preview | `DECISION REQUIRED` | `authenticated interoperability-preview GET` | `<none>` | `Facilitator opened /encounters/.../interoperability-preview for FINALIZED ENC-SIM-Q0XZYBHFMREY and received HTTP 200. Detailed FHIR-aligned content review and VAL-U05 retain/revise/remove decision remain outstanding before PASS.` |
| UAT-C01 — diagnosis-source correction | `DECISION REQUIRED` | `simulation:prepare-reference-correction diagnosis on UAT-CORR-DX-001` | `<none>` | `Tip da2d15c prepared disposable UAT-CORR-DX-001 → ENC-SIM-CVFC514EVGDB AMENDMENT_PENDING with diagnosis correction OPEN (CORRECTION_OPEN; diagnosisCorrections 1). Full coder-request → clinician clarification → replacement review browser path remains outstanding before PASS.` |
| UAT-C02 — performed-procedure correction | `DECISION REQUIRED` | `simulation:prepare-reference-correction procedure on UAT-CORR-PX-001` | `<none>` | `Tip da2d15c prepared disposable UAT-CORR-PX-001 → ENC-SIM-DHXDQWQPCF1V AMENDMENT_PENDING with procedure correction OPEN (CORRECTION_OPEN; procedureCorrections 1). Full browser clarification chain remains outstanding before PASS.` |

## 6. Issue records

Copy this block once per issue. Do not combine unrelated observations merely because they occurred on the same screen.

### `UAT-20260820-001`

| Field | Entry |
| --- | --- |
| Scenario/step | `UAT-05 step 3 — select synthetic stock lot / prepare dispense` |
| Acting role | `Mahasiswa Farmasi Demo` |
| Encounter/source | `ENC-SIM-Q0XZYBHFMREY · medical Versi 2 hash ...90e40335 · authored medication Parasetamol tablet sintetis` |
| Expected | `FEFO lot select lists a matching synthetic stock lot for the authored medication name and quantity unit` |
| Actual | `Only seeded lots for Obat Simulasi A (LOT-SIM-A-001 / LOT-SIM-Q0XZYBHFMREY-1) existed; matchingStocks was empty for Parasetamol tablet sintetis / tablet until a temporary LOT-SIM-PARA-CP2-001 was created in the local session DB` |
| Impact domain | `workflow` |
| Reproducibility | `always when medical prescription authored medication text differs from seeded MedicationStock.authored_medication` |
| Evidence IDs | `local pharmacy page observation; MedicationStock query; temporary LOT-SIM-PARA-CP2-001` |
| Reporter proposal | `teaching-data / scenario-seed alignment; optionally warn when no matching lot exists` |
| Daniel classification | `NOT CLASSIFIED` |
| Owner | `UNASSIGNED` |
| Target checkpoint | `UAT resume` |
| Resolution evidence | `NOT RESOLVED` |

### `UAT-20260820-002`

| Field | Entry |
| --- | --- |
| Scenario/step | `UAT-03 draft then submit → UAT-05 pharmacy completion advancement` |
| Acting role | `Mahasiswa Kedokteran Demo` / system after pharmacy final check |
| Encounter/source | `orphan MedicationRequest 01M0F08B2K2RP0212HVZXGRFTS DRAFT from medical Versi 1; completed request 01M0F08PV2R066SMMV2T8YQRZK` |
| Expected | `After approved medical submit and completed pharmacy dispense of the active request, encounter advances and ENCOUNTER_CLOSURE becomes ready` |
| Actual | `Draft-save created a DRAFT medication request that remained open after Versi 2 submit/approve/dispense; PharmacyWorkflowService::advanceWhenMedicationWorkComplete treated DRAFT as open work and left encounter AWAITING_PHARMACY with zero ready tasks until the orphan draft was cancelled and advancement was re-applied` |
| Impact domain | `workflow` / `data integrity` |
| Reproducibility | `always when medical assessment SAVE_DRAFT includes medication_requests and a later SUBMIT creates a second request from the submitted version` |
| Evidence IDs | `MedicationRequest statuses; lab-session-status before/after cancel; dispense 01M0F0S5VPYN2JCHKB76GC57RX` |
| Reporter proposal | `cancel or supersede draft medication/service requests when a newer medical version is submitted/approved` |
| Daniel classification | `NOT CLASSIFIED` |
| Owner | `UNASSIGNED` |
| Target checkpoint | `UAT resume` |
| Resolution evidence | `NOT RESOLVED` |

### `UAT-20260820-003`

| Field | Entry |
| --- | --- |
| Scenario/step | `UAT-03 draft then submit → UAT-06 closure readiness CURRENT_RESULTS_ACKNOWLEDGED` |
| Acting role | `Mahasiswa Kedokteran Demo` / closure readiness gate |
| Encounter/source | `orphan ServiceRequest 01M0F08B2JNG15VVGV5DSJ0EY0 DRAFT from medical Versi 1; completed/acked SR 01M0F08PV1GE9NW8Z5T8ZSQNVG` |
| Expected | `Only current approved medical-version service requests participate in closure readiness, or superseded draft orders are terminal` |
| Actual | `Draft-save left a DRAFT service request that failed CURRENT_RESULTS_ACKNOWLEDGED after the Versi 2 order had a released and acknowledged result; closure save blocked until the orphan draft was cancelled` |
| Impact domain | `workflow` / `data integrity` |
| Reproducibility | `always when medical SAVE_DRAFT includes service_requests and a later SUBMIT creates another request from the submitted version` |
| Evidence IDs | `ClosureReadinessService evaluate evidence; cancelled SR 01M0F08B2JNG15VVGV5DSJ0EY0; closure 01M0F1FBBXYYQSWSPF4TDR00MJ` |
| Reporter proposal | `same root cause as UAT-20260820-002 — cancel/supersede draft orders when a newer medical version is submitted/approved` |
| Daniel classification | `NOT CLASSIFIED` |
| Owner | `UNASSIGNED` |
| Target checkpoint | `UAT resume` |
| Resolution evidence | `NOT RESOLVED` |

### `UAT-20260820-004`

| Field | Entry |
| --- | --- |
| Scenario/step | `UAT-08 steps 2–3 — generate candidates / select or manual alternative` |
| Acting role | `koder.rmik@example.invalid` |
| Encounter/source | `condition 01M0F08PV02C8TFK1R8RC4818Q · authored text Faringitis akut dalam evaluasi pada skenario simulasi · release ICD10_2010` |
| Expected | `Deterministic lexical engine either ranks usable candidates or honestly returns NO_RELIABLE_CANDIDATE; coder can still complete human-reviewed coding` |
| Actual | `Suggestion run 01M0F41QR26DCYNQ85QZEGBY8V returned NO_RELIABLE_CANDIDATE with zero candidates despite active J02/J02.9 concepts existing in the same release; coding completed via ManualAlternative to J02.9` |
| Impact domain | `terminology` / `teaching` |
| Reproducibility | `observed for Indonesian pharyngitis wording against English ICD-10 display strings on this release; may recur for other Indonesian clinician text without aliases` |
| Evidence IDs | `run 01M0F41QR26DCYNQ85QZEGBY8V; decision 01M0F4540EZSHMRG4MJV4V8SVZ; assignment 01M0F4540AAQX7JE8XJ8NXRTRN; concept J02.9` |
| Reporter proposal | `treat as expected honesty for VAL-A16 alias/threshold discussion; consider Indonesian alias fixtures only after Daniel approval` |
| Daniel classification | `NOT CLASSIFIED` |
| Owner | `UNASSIGNED` |
| Target checkpoint | `faculty pilot / VAL-A16` |
| Resolution evidence | `NOT RESOLVED` |

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
| `UAT-20260820-001` | `P1 candidate` | pharmacy FEFO empty until session lot `LOT-SIM-PARA-CP2-001`; fix code merged via PR #28 | Daniel (classification) | classify; spot-check stock-alignment UX on disposable session before PASS |
| `UAT-20260820-002` | `P0 candidate` | orphan DRAFT medication request blocked pharmacy completion; fix code merged via PR #27 + regression test | Daniel (classification) | classify; confirm cancel-on-successor behavior on disposable session if required before PASS |
| `UAT-20260820-003` | `P0 candidate` | orphan DRAFT service request blocked closure readiness; same fix as `002` / PR #27 | Daniel (classification) | classify with `002` |
| `UAT-20260820-004` | `P1 candidate` | Indonesian authored diagnosis returned `NO_RELIABLE_CANDIDATE`; ManualAlternative to J02.9 succeeded; honesty path merged via PR #29 | Daniel (classification) | classify for VAL-A16; do not add aliases without approval |
| Draft-guard / deny-case gaps | `P1 candidate` | UAT-02/03 dirty-nav browser branches still outstanding; UAT-09 deny now has residual actingAs + Feature evidence on tip `7d667de` / `CP2-RESIDUAL-001` | Facilitator + Daniel | schedule browser residual for draft-guard + SIMULASI banner before PASS; classify whether residual deny probes are sufficient |

## 8A. Residual evidence addendum — 20 August 2026 evening

> Supporting evidence only. Does **not** mark scenarios PASS or authorize Checkpoint 2 acceptance.

| Field | Entry |
| --- | --- |
| Tip | `7d667de` |
| Lab preflight | `READY` (17/17) before residual clones |
| Disposable sessions | `CP2-RESIDUAL-001` → `ENC-SIM-TQT4QNY118PJ` FINALIZED via `simulation:complete-reference-journey`; `CP2-RESIDUAL-002` → `ENC-SIM-G175X40NXTAW` left PLANNED for cross-case probes |
| Journey counts (001) | clinicalVersions 2, results 1, closures 1, procedures 1, recordQualityReviews 1, codingAssignments 2, workTasks 28 |
| Targeted PHPUnit | 32 passed (WorkQueue / WorkTaskInvariant / Timeline / Debrief / Interop deny + orphan-draft cancel filter set) |
| ActingAs HTTP probes | assigned FINALIZED timeline/debrief/interop 200; other-session PLANNED debrief/interop 409; unknown work-session 404; facilitator work queue 001 200 |
| Browser MCP | local `127.0.0.1` unreachable (`chrome-error`); hosted demo `/login` shows `SIMULASI — DATA SINTETIS` banner |
| Branch fixtures (tip `da2d15c`) | `UAT-NO-SHOW-001` due BOOKED appointment; `UAT-CORR-DX-001` / `UAT-CORR-PX-001` CORRECTION_OPEN; Feature suites AppointmentTermination + EarlyDeparture + SafetyDisposition green |
| Merged fix posture | PRs #27–#29 on `main`; issues still need Daniel classification |

## 9. Exit record and Daniel decision

| Field | Entry |
| --- | --- |
| All planned scenarios accounted for | `NOT CONFIRMED` |
| All issue records classified | `NOT CONFIRMED` |
| Real-data/production-integration boundary preserved | `NOT CONFIRMED` |
| Unresolved P0 count | `2 candidate issues pending Daniel classification (UAT-20260820-002, 003)` |
| Unresolved P1 count | `2 candidate issues + residual draft-guard/deny-case gaps pending Daniel classification` |
| Checkpoint 2 outcome | `NOT DECIDED` |
| Allowed outcomes | `ACCEPTED`, `CONDITIONALLY ACCEPTED`, `REQUIRES ANOTHER RUN` |
| Conditions/required reruns | `<issue and scenario IDs>` |
| Merge authorization | `NOT AUTHORIZED BY THIS RECORD` |
| Deployment authorization | `NOT AUTHORIZED BY THIS RECORD` |
| Faculty-pilot authorization | `NOT AUTHORIZED BY THIS RECORD` |
| Dependency-maintenance decision for next branch | `NOT DECIDED` |
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
