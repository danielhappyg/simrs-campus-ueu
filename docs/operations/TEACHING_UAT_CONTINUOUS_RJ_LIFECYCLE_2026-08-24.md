# Teaching UAT — Continuous RJ lifecycle Run 4 (2026-08-24)

Agent-operated, role-specific synthetic UAT on the deployed teaching demo. No passwords, tokens, cookies, URL tokens or real patient data are recorded here.

## Verdict — **PASS with one partial manual-evidence item**

One synthetic encounter completed the continuous hosted journey: registration → nursing note → medical note → active lab order → visible RM closure blocker → one FINAL result → duplicate-result denial → successful RM closure → late-result denial → cetak → filtered ONLINE rekap.

The active-order closure control passed at the UI boundary: the close action was disabled and displayed **“Belum dapat ditutup: masih ada order laboratorium aktif.”** Because the UI correctly prevented submission, this manual run did not generate an `active_lab_orders` server-denial audit. Server-side enforcement remains covered by automated lifecycle tests and code evidence. This is a **partial manual-evidence item, not a product failure**.

Passing this run does **not** establish SIMRS Sahabat lifecycle parity, move DEC-016 beyond **Proposed NEW**, replace Clinical/Laboratory or RMIK owner review, or authorize clinical production use.

## Run identity

| Field | Verified value |
| --- | --- |
| Record ID | `UAT-TEACH-20260824-04` |
| Execution window | 2026-08-24 09:35–09:45 Asia/Jakarta |
| Environment | `https://simrs-campus-ueu-demo.vercel.app` |
| Production commit | `c6979de82b6e8b2c518841ad8e72e1d9bf8bd213` |
| Vercel deployment | `dpl_6U3eC7uFF4A27mS7qoib7DMMywvb` — `READY`, target `production` |
| Production alias | `simrs-campus-ueu-demo.vercel.app` resolves to the deployment above |
| Hosting topology | Vercel teaching demo + Supabase Postgres schema `laravel` |
| Application boundary | `SIMULATION` / synthetic-only |
| Evidence location | This record and the hosted audit identifiers below |
| Prior evidence | [Runs 1–2](TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md) and [Run 3](TEACHING_UAT_LAB_SLICE_2026-08-22.md) |

## Entry and safety gates

| Gate | Status | Verified evidence |
| --- | --- | --- |
| Public `/up` | **PASS** | HTTP 200 |
| Public `/login` | **PASS** | HTTP 200 |
| Production alias matches candidate | **PASS** | Deployment `dpl_6U3eC7uFF4A27mS7qoib7DMMywvb`; commit `c6979de…213` |
| Permanent **SIMULASI — DATA SINTETIS** indicator | **PASS** | Present on the exercised authenticated teaching desks |
| Synthetic data only | **PASS** | Fresh `SIM UAT4…` patient and synthetic notes/results only |
| Live integrations | **PASS** | No BPJS, VClaim, SATUSEHAT or LIS integration used |
| Honest stub boundary | **PASS** | Klaim, BPJS and Apotek remained **Soon** |
| Global reset prohibited | **PASS** | `simulation:reset` was not run |
| Secrets excluded | **PASS** | No credential or session secret retained in documentation or temporary files |

## Role coverage

| Sequence | Demo role/account | Activity | Status | Evidence |
| ---: | --- | --- | --- | --- |
| 1 | Registrar — `registrar.demo@example.invalid` | Login, registration, wrong-role denial, cetak, rekap | **PASS** | Registration and denial audit IDs below; print audit `01M0RTGBAH22GEKGJGKK668HNT` |
| 2 | Nurse — `nurse.demo@example.invalid` | Login, nursing note, FINAL result, duplicate and late-result attempts | **PASS** | Nursing/result/denial audit IDs below |
| 3 | Physician — `physician.demo@example.invalid` | Login, medical note, lab order | **PASS** | Medical/order audit IDs below |
| 4 | RMIK — `rmik.demo@example.invalid` | Login, visible active-order blocker, successful close | **PASS** | Close audit `01M0RTDXKRD33BRQKKRZHM71PY` |

## Single-encounter artifact ledger

| Artifact | Verified value |
| --- | --- |
| Synthetic patient | `SIM UAT4 LIFECYCLE 240824` |
| MRN | `RM-260824-JKJN` |
| Synthetic NIK | `9999240824000001` |
| Encounter `public_id` | `01M0RSYJ1RCCAG0DP5QJ7QR4YQ` |
| Booking/channel | `UAT4-240824-001` / **ONLINE** |
| Queue / clinic / physician / schedule | `001` / Poliklinik Umum / dr. Budi Santoso / Selasa 08:00–11:00 |
| Lab test | `HB` / Hemoglobin |
| Synthetic result text | `Hb 12.8 g/dL — hasil sintetis UAT4` |
| Lab order `public_id` | `01M0RT1QVWDHGHWQE93CCXNSSZ` |
| Lab result `public_id` | `01M0RT3MH9PR5NBZ456W7J8VPN` |
| Final encounter state | `CLOSED` |
| Final lab-order state | `COMPLETED` |
| Final result count | `1` |

## Scenario results

| ID | Role | Scenario | Status | Verified result |
| --- | --- | --- | --- | --- |
| R4-00 | Facilitator | Entry gates and deployment identity | **PASS** | `/up` and `/login` HTTP 200; production alias, deployment and commit matched; simulation banner present |
| R4-01 | Registrar | Register one fresh synthetic RJ encounter | **PASS** | Patient/MRN/encounter above created; audit `01M0RSYJ2F3CVF7R0SW2P8FFWM` |
| R4-02 | Nurse | Save nursing assessment | **PASS** | Nursing note persisted; audit `01M0RT0G6PCBT5BMV5FME1N9KR` |
| R4-03 | Physician | Save medical assessment | **PASS** | Medical note persisted; audit `01M0RT1929MEY2G5CX2ES3K6GT` |
| R4-04 | Physician | Create lab order | **PASS** | Order `01M0RT1QVWDHGHWQE93CCXNSSZ` became active; audit `01M0RT1QW8R8G2MGH6D1ZVNMEJ` |
| R4-05 | RMIK | Attempt closure while one lab order is active | **PARTIAL EVIDENCE** | UI disabled close and displayed the Indonesian blocker text. No request was sent, so no manual `active_lab_orders` DENIED audit exists; automated tests cover the server guard |
| R4-06 | Registrar / wrong role | Open protected RMIK route | **PASS** | HTTP 403; `authorization.denied`, reason `authorization_check_failed`, resource `http_route/rm.rawat-jalan.index`; audit `01M0RSZ3XPDX1XDMJ5591MJ7BD` |
| R4-07 | Nurse | Submit one FINAL lab result | **PASS** | Result `01M0RT3MH9PR5NBZ456W7J8VPN` created; order `COMPLETED`; audit `01M0RT3MJ0W8V7QGR236PEK7RP` |
| R4-08 | Nurse | Attempt duplicate FINAL result | **PASS** | HTTP 422; DENIED reason `result_already_final`; result count remained `1`; audit `01M0RT88CKW90S1M67Q9J7WQXQ` |
| R4-09 | RMIK | Close after active-order count reached zero | **PASS** | Encounter became `CLOSED`; audit `01M0RTDXKRD33BRQKKRZHM71PY` |
| R4-10 | Nurse | Attempt result after encounter close | **PASS** | HTTP 422; DENIED reason `encounter_closed`; result count remained `1`; audit `01M0RTF1NEE1THT6TRB5Q40WK9` |
| R4-11 | Registrar | Open teaching cetak | **PASS** | Print view opened; audit `01M0RTGBAH22GEKGJGKK668HNT` |
| R4-12 | Registrar | Filter rekap for ONLINE channel | **PASS** | Synthetic UAT encounter appeared in the filtered ONLINE rekap |
| R4-13 | Evidence review | Reconcile attributable success and denial audits | **PASS with partial item** | Named success, authorization and business-denial audits reconciled; manual `active_lab_orders` denial absent for the honest UI reason above |
| R4-14 | Authorized operator | Safe closeout | **PASS** | UAT clinical artifacts retained as synthetic teaching/audit evidence; four temporary role accounts disabled, passwords rotated to unknown values, sessions revoked, temporary credential/session files deleted |

## No-mutation and audit checks

| Check | Status | Verified result |
| --- | --- | --- |
| Active-order close blocker | **PASS at UI / PARTIAL server evidence** | One active order kept close unavailable. No stale/direct request was submitted and no manual denial audit was generated |
| Wrong-role protected route | **PASS** | HTTP 403 before protected RMIK worklist access; authorization denial audit recorded |
| Duplicate FINAL attempt | **PASS** | HTTP 422, reason `result_already_final`; existing result count remained `1` |
| Late-result attempt after close | **PASS** | HTTP 422, reason `encounter_closed`; encounter remained `CLOSED`, order `COMPLETED`, result count `1` |

| Path | Audit action/outcome/reason | Audit ID |
| --- | --- | --- |
| Registration | `patient.register` | `01M0RSYJ2F3CVF7R0SW2P8FFWM` |
| Wrong-role denial | `authorization.denied` / `DENIED` / `authorization_check_failed` | `01M0RSZ3XPDX1XDMJ5591MJ7BD` |
| Nursing note | `clinical.note.write` | `01M0RT0G6PCBT5BMV5FME1N9KR` |
| Medical note | `clinical.note.write` | `01M0RT1929MEY2G5CX2ES3K6GT` |
| Lab order | `clinical.lab.order.create` | `01M0RT1QW8R8G2MGH6D1ZVNMEJ` |
| Active-order close denial | Not generated — UI correctly disabled submission; automated-test evidence only | **PARTIAL manual evidence** |
| FINAL result | `clinical.lab.result.write` | `01M0RT3MJ0W8V7QGR236PEK7RP` |
| Duplicate FINAL denial | `DENIED` / `result_already_final` | `01M0RT88CKW90S1M67Q9J7WQXQ` |
| Successful RM close | `rmik.review.complete` | `01M0RTDXKRD33BRQKKRZHM71PY` |
| Late-result denial | `DENIED` / `encounter_closed` | `01M0RTF1NEE1THT6TRB5Q40WK9` |
| Cetak | Cetak audit event | `01M0RTGBAH22GEKGJGKK668HNT` |

## Cleanup and evidence retention

- Global `simulation:reset` was **not** run because it is not encounter-scoped.
- The UAT patient, encounter, order, result and audit events were deliberately retained as synthetic teaching/audit evidence.
- The four temporary registrar, nurse, physician and RMIK accounts were disabled.
- Their passwords were rotated to unknown values and active sessions were revoked.
- Temporary credential and session files were deleted.
- No secrets are stored in this record.

Closeout verification query: `active_session_rows=0`, `disabled_account_rows=4`, and `preserved_closed_encounter_rows=1`.

This closeout prevents continued access through the temporary accounts while preserving the evidence chain. It does not claim that a general session-scoped clinical-data reset now exists.

## Evidence limitation — `R4-EVIDENCE-01`

| Field | Entry |
| --- | --- |
| Scenario | R4-05 — close with one `ACTIVE` lab order |
| Observed | RM UI disabled the close action and displayed the active-order explanation |
| Missing manual evidence | Server-side `active_lab_orders` rejection and denial audit were not exercised because no request was sent |
| Existing supporting evidence | Automated lifecycle tests and server-side guard implementation |
| Classification | **Partial manual evidence; not a product failure** |
| Follow-up | Repeat only if a future acceptance plan explicitly requires a safe stale/direct hosted request; do not bypass controls casually |

## Exit record

| Field | Result |
| --- | --- |
| Planned continuous journey accounted for | **PASS** |
| Same encounter proven across the journey | **PASS** — `01M0RSYJ1RCCAG0DP5QJ7QR4YQ` |
| Executed denials proven no-mutation | **PASS** — duplicate and late-result result count stayed `1`; wrong-role request returned 403 |
| Active-order server denial manual evidence | **PARTIAL** — UI blocker passed; automated-test evidence remains authoritative for server rejection |
| Simulation/synthetic-only boundary preserved | **PASS** |
| Closeout safe and documented | **PASS** — evidence retained; temporary access disabled and revoked |
| Product failures identified | None in this run |
| Run 4 verdict | **PASS with one partial manual-evidence item** |
| Clinical/Laboratory owner acceptance | **OPEN** — DEC-016 remains Proposed |
| RMIK owner acceptance | **OPEN** — DEC-016 remains Proposed |

## Explicitly outside this run

- Preliminary results, final-result amendment/correction, order cancellation and encounter reopen are not built.
- Radiology and a generalized cross-module order engine are not built.
- ICD coding, pharmacy dispensing, charges and kasir are outside this bounded journey.
- Live integrations and real patient data remain prohibited.
- The failed Antrean/work-queue MVP remains retired under DEC-013.

## Governing references

- [Teaching RJ facilitator runbook](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)
- [Outpatient order, result and closure contract](../new-simrs-rebuild/phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md)
- [Current rebuild handoff](HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md)
- [Vercel + Supabase demo operations](VERCEL_SUPABASE_DEMO.md)
- [Run 3 lab slice evidence](TEACHING_UAT_LAB_SLICE_2026-08-22.md)
- [Runs 1–2 cetak/rekap evidence](TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md)
