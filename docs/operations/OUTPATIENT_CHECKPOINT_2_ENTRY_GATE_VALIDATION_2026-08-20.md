# Outpatient Checkpoint 2 Entry Gate Validation — 20 August 2026

> **DEVELOPMENT EVIDENCE ONLY — NOT FACULTY UAT ACCEPTANCE, PILOT APPROVAL, MERGE AUTHORIZATION, OR DEPLOYMENT AUTHORIZATION.**

- **Record ID:** `DEV-ENTRY-GATE-20260820-01`
- **Owner and final decision authority:** Daniel Happy Putra, project manager/PIC
- **Scope:** automated Checkpoint 2 entry gate only; browser journey not yet observed in this record
- **Environment:** local isolated SQLite fixture
- **Base commit on `main`:** `f4ddfdf`
- **Register recheck branch:** `docs/icd9cm-register-recheck-2026-08-20` / PR #25
- **Disposable session:** `LAB-REHEARSAL-001`
- **Encounter:** `ENC-SIM-Q0XZYBHFMREY`

## Terminology releases active before gate

| System   | Release       | Concepts | SHA-256 suffix | Register row |
| -------- | ------------- | -------: | -------------- | ------------ |
| ICD-10   | `ICD10_2010`  |   18,543 | `5aac548c5f4e` | REF-COD-002  |
| ICD-9-CM | `ICD9CM_2010` |    4,626 | `8c2de59e9697` | REF-COD-001 recheck in PR #25 |

The retained local workbook `[PUBLIC] ICD-9CM e-klaim.xlsx` passed the import contract on 20 August 2026. The July 2026 `(1)` workbook bytes (`9f625ada…`) were not recovered locally.

## Automated gate results

| Command | Result | Sanitized detail |
| ------- | ------ | ---------------- |
| `php artisan simulation:lab-preflight --json` | `READY` | 17 passed; 0 failed |
| `php artisan simulation:lab-access enable --confirm=ENABLE-RESERVED-DEMO-ACCESS` | success | `UNCHANGED`; 10 accounts |
| `php artisan simulation:clone-reference-session LAB-REHEARSAL-001 --duration=480` | success | 10 assignments; 4 initial tasks; no progressed state copied |
| `php artisan simulation:lab-session-status LAB-REHEARSAL-001 --json` | success | `status: OK`; `phase: READY_TO_START`; ready task `REGISTRATION` |

## Dependency-hygiene evidence linked to this candidate

- See [Dependency Hygiene Baseline — 20 August 2026](DEPENDENCY_HYGIENE_BASELINE_2026-08-20.md).
- `composer audit`: clean
- `npm audit --omit=dev`: clean
- direct dependency maintenance backlog exists in both Composer and npm and remains review work, not a same-day forced upgrade for this entry-gate record
- the `npm` `devdir` warning is recorded as an environment-level reproducibility note to trace separately

## Next evidence still required

1. Daniel PIC decision on PR #25 register hash alignment.
2. Stage A facilitator browser rehearsal at `/work?session=LAB-REHEARSAL-001` with out-of-band demo password distribution.
3. Faculty Checkpoint 2 UAT using a dated copy of the [UAT record template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md).
4. Daniel decision on whether any low-risk dependency-maintenance batch should land before the next hosted or faculty rehearsal, using [Dependency Update Candidates — 20 August 2026](DEPENDENCY_UPDATE_CANDIDATES_2026-08-20.md).

## Local live-browser starting-state observation

On 20 August 2026, a fresh local Laravel server was started on `http://127.0.0.1:8030` after an older port `8028` session appeared stale. A live browser check confirmed:

- `/` redirected to `/login`;
- the page title rendered `SIMRS Campus UEU`;
- the permanent `SIMULASI — DATA SINTETIS` boundary was visible; and
- the Indonesian sign-in surface and simulation-only copy rendered before authentication.

This is preparation evidence only. It does not yet confirm the reserved demo accounts, exact session selection, or the post-login work queue path for `LAB-REHEARSAL-001`.

## Authenticated local rehearsal-path observation

Using the reserved local rehearsal account for the RMIK registration role, a live browser check confirmed the exact path `http://127.0.0.1:8030/work?session=LAB-REHEARSAL-001` and observed:

- page title `Antrean kerja - SIMRS Campus UEU`;
- permanent simulation boundary and synthetic-only wording still visible after login;
- session `LAB-REHEARSAL-001` shown as active;
- program `RMIK` and role `Petugas Registrasi Simulasi`;
- one ready personal task, `Verifikasi registrasi dan check-in`; and
- encounter `ENC-SIM-Q0XZYBHFMREY` shown on the ready task card.

Continuing that same local rehearsal path, the authenticated registration flow then reached the encounter page for `ENC-SIM-Q0XZYBHFMREY`, where the server-side orbit showed `ARRIVED` active and provenance recorded `appointment_check_in` by `Mahasiswa RMIK Demo · Petugas Registrasi Simulasi` at `20 Agu 2026, 13.54 WIB`.

An immediate follow-up `simulation:lab-session-status LAB-REHEARSAL-001 --json` then reported:

- `phase: IN_PROGRESS`;
- encounter status `ARRIVED`;
- `openTasks: 2`;
- `readyTasks: NURSING_INTAKE` for program `NURSING`, role `LEARNER`; and
- the same exact `workQueuePath`, `/work?session=LAB-REHEARSAL-001`.

After revoking the prior browser session and signing in as the nursing learner, a local live check of `http://127.0.0.1:8030/work?session=LAB-REHEARSAL-001` confirmed:

- active session `LAB-REHEARSAL-001`;
- program `Keperawatan`;
- role `Mahasiswa`;
- one ready personal task, `Asesmen Awal dan Skrining Keselamatan`; and
- the same encounter `ENC-SIM-Q0XZYBHFMREY` on the nursing task card.

Opening `.../nursing-intake` then confirmed the shared clinical documentation workspace for the same encounter, with the permanent human-only safety boundary visible. Saving a synthetic draft created attributable `Versi 1 · nursing-intake.v1` (`SHA-256` suffix `b7aa31c6`) by `Mahasiswa Keperawatan Demo`. Submitting that assessment with `Ajukan untuk tinjauan` then created immutable `Versi 2 · nursing-intake.v1` marked `Diajukan untuk ditinjau` (`SHA-256` suffix `73399f7d`), set the learner task to `Diajukan`, and updated the patient allergy banner to `Tidak ada alergi yang dilaporkan` without erasing Versi 1.

A follow-up `simulation:lab-session-status LAB-REHEARSAL-001 --json` reported:

- encounter status `IN_INTAKE`;
- `SUBMITTED: 1`;
- `readyTasks: SUPERVISOR_REVIEW` for program `NURSING`, role `SUPERVISOR`; and
- attention flag `SUPERVISOR_REVIEW_PENDING`.

After signing in as `Supervisor Keperawatan Demo`, the ready review link opened `/clinical-entry-versions/01M0EZNZGP7H8JDA4G839R37WA/review` for nursing-intake `Versi 2` with the same content hash suffix `73399f7d`. Choosing `Setujui untuk simulasi` wrote `Persetujuan simulasi` by that supervisor at `20 Agu 2026, 14.07 WIB`, and the reviewed version label became `Disetujui untuk simulasi`.

A later `simulation:lab-session-status LAB-REHEARSAL-001 --json` then reported:

- encounter status `WAITING_CLINICIAN`;
- `COMPLETE: 4`;
- `openTasks: 1`;
- `readyTasks: MEDICAL_ASSESSMENT` for program `MEDICINE`, role `LEARNER`; and
- empty `attention`.

Signing in as `Mahasiswa Kedokteran Demo` then opened `/encounters/.../medical-assessment` with the approved nursing handoff (`Versi 2 · nursing-intake.v1`, hash suffix `73399f7d`) visible read-only. The learner authored history, examination, clinician diagnosis text, plan, one synthetic laboratory service request, and one synthetic medication request. `Simpan draf` created `Versi 1 · medical-assessment.v1` (`SHA-256` suffix `90e40335`); `Ajukan untuk tinjauan` created immutable `Versi 2` marked `Diajukan untuk ditinjau` with the same content hash and set the learner task to `Diajukan`.

`Supervisor Kedokteran Demo` then opened `/clinical-entry-versions/01M0F08PV02C8TFK1R8RC4818P/review`, matched that hash, and recorded `Persetujuan simulasi` at `20 Agu 2026, 14.14 WIB`. The reviewed medical version became `Disetujui untuk simulasi`.

A subsequent `simulation:lab-session-status LAB-REHEARSAL-001 --json` reported:

- encounter status `AWAITING_RESULT`;
- `COMPLETE: 6`;
- `openTasks: 3`;
- `readyTasks: SYNTHETIC_RESULT_RELEASE` for program `FACILITATION`, role `FACILITATOR`; and
- empty `attention`.

`Fasilitator Simulasi UEU` then opened `/encounters/.../order-results` and released synthetic result `LAB-SIM-CP2-001` (`Panel darah sintetis Checkpoint 2`) as `Final untuk simulasi` with content hash suffix `c05e46dc`, tied to the approved medical source hash `90e40335`. Session status next released `RESULT_ACKNOWLEDGEMENT` for the medicine learner.

`Mahasiswa Kedokteran Demo` acknowledged that current result version from the same order-results workspace. A follow-up `simulation:lab-session-status LAB-REHEARSAL-001 --json` reported:

- encounter status `AWAITING_PHARMACY`;
- `COMPLETE: 8`;
- `openTasks: 1`;
- `readyTasks: PHARMACY_REVIEW` for program `PHARMACY`, role `LEARNER`; and
- empty `attention`.

`Mahasiswa Farmasi Demo` then opened `/encounters/.../pharmacy`, inspected the derived prescription/allergy context, and saved an `ACCEPT` human review with all criterion outcomes `CLEAR`. Two blocking observations interrupted a clean browser-only dispense path:

1. **UAT-20260820-001** — seeded synthetic stock lots matched `Obat Simulasi A` only; the approved prescription authored `Parasetamol tablet sintetis`, so the FEFO lot selector had no matching options until local session stock `LOT-SIM-PARA-CP2-001` was created.
2. Chrome remote-debugging permission interrupted further browser automation; preparation `v1` (`SHA-256` suffix `bc0f0306`) and pharmacy supervisor `FINAL_CHECK` were completed through `PharmacyWorkflowService`, producing dispense `01M0F0S5VPYN2JCHKB76GC57RX`.

3. **UAT-20260820-002** — an orphan `DRAFT` medication request from medical assessment `Versi 1` remained after `Versi 2` submit/approve and blocked `advanceWhenMedicationWorkComplete`, leaving the encounter at `AWAITING_PHARMACY` with no ready tasks until that draft was cancelled and advancement re-applied.

A later `simulation:lab-session-status LAB-REHEARSAL-001 --json` then reported:

- encounter status `IN_CONSULTATION`;
- `readyTasks: ENCOUNTER_CLOSURE` for program `MEDICINE`, role `LEARNER`; and
- empty `attention`.

Closure readiness initially failed on `CURRENT_RESULTS_ACKNOWLEDGED` because orphan DRAFT service request `01M0F08B2JNG15VVGV5DSJ0EY0` from medical `Versi 1` remained beside the completed/acknowledged order (**UAT-20260820-003**). After that draft was cancelled, `EncounterClosureService` accepted learner submit of closure `01M0F1FBBXYYQSWSPF4TDR00MJ` `v1` (hash suffix `048555a5`) and medical supervisor `APPROVE_SIMULATION` set status `APPROVED`.

A subsequent `simulation:lab-session-status LAB-REHEARSAL-001 --json` reported:

- encounter status `CLINICALLY_CLOSED`;
- `readyTasks: RECORD_REVIEW` for program `RMIK`, role `CODER`; and
- empty `attention`.

`koder.rmik@example.invalid` then submitted `RecordQualityReview` `01M0F1N14GJ7CAFZADD8Z3D8S2` `v1` (hash suffix `f7a1a7f4`) with no authored manual findings against a passing completeness checklist. `supervisor.rmik@example.invalid` recorded `APPROVE_SIMULATION` and the review became `APPROVED`.

A follow-up `simulation:lab-session-status LAB-REHEARSAL-001 --json` reported:

- encounter status `RECORD_REVIEW`;
- `readyTasks: CODING` for program `RMIK`, role `CODER`; and
- empty `attention`.

`koder.rmik@example.invalid` then generated diagnosis coding suggestions for approved condition `01M0F08PV02C8TFK1R8RC4818Q`. The deterministic lexical engine returned `NO_RELIABLE_CANDIDATE` for Indonesian authored text `Faringitis akut…` against English ICD-10 displays (**UAT-20260820-004**). The coder selected manual alternative `J02.9` (`Acute pharyngitis, unspecified`) from active release `ICD10_2010` (`source_sha256` suffix `548c5f4e`), creating `CodingAssignment` `01M0F4540AAQX7JE8XJ8NXRTRN` (hash suffix `9e058b44`). After submit, `supervisor.rmik@example.invalid` recorded `APPROVE_SIMULATION`. Closure procedure documentation was `NonePerformed`, so no ICD-9-CM procedure coding was required.

A subsequent `simulation:lab-session-status LAB-REHEARSAL-001 --json` reported:

- `phase: FINALIZED`;
- encounter status `FINALIZED`;
- `readyTasks`: ten `DEBRIEF` tasks across Facilitation/RMIK/Nursing/Medicine/Pharmacy; and
- empty `attention`.

Post-finalization UAT-09/UAT-10 probes then confirmed:

- HTTP `200` for facilitator and medicine-learner access to `/timeline`, `/debrief`, outpatient-summary, and debrief-evidence;
- `EncounterDebriefTimeline::build` returned 33 provenance events spanning check-in through coding (`J02.9` visible in timeline payload);
- facilitator `DebriefNote` `01M0F4848R9C1XTVTV1SV3G8VP` (`FacilitatorSynthesis`) versioned from `v1` (hash suffix `2008f6fe`) to `v2` (hash suffix `212ed485`); and
- HTTP `200` for `/interoperability-preview` on the same finalized encounter.

This remains local rehearsal evidence rather than a final faculty-UAT verdict. The shared outpatient reference journey has been exercised end-to-end to finalized coding, debrief, reports, and interoperability preview. Issues `UAT-20260820-001`–`004`, optional deny-case checks, draft-guard branches, and Daniel decisions (`VAL-A11`/`A12`/`A16`/`VAL-U05`, scenario PASS marks) remain before Checkpoint 2 acceptance. Docs on this branch are still uncommitted pending Daniel's commit/PR instruction.

This record does not authorize merge, deployment, hosted-demo reuse, or a faculty pilot.
