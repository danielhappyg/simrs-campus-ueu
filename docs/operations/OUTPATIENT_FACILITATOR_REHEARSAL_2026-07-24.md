# Outpatient Facilitator Rehearsal — 24 July 2026

## Record status

- Evidence type: developer-operated laboratory-readiness rehearsal
- Candidate code commit: `8303376` (`Fix cloned rehearsal journey stock selection`)
- Source branch: `agent/outpatient-domain-spine`
- Deployment status: **NOT DEPLOYED**
- Merge status: **NOT MERGED**
- Product-owner decision: **NOT DECIDED**
- Data boundary: synthetic teaching data only
- Scope boundary: outpatient reference MVP only; Claims and E-Klaim were excluded

This record supports a later decision about a small supervised laboratory pilot. It is not faculty acceptance, ten-person UAT, production validation, or evidence that the application is suitable for real patient care.

## Rehearsal environment

The candidate was exercised from an isolated archive of base commit `6c9e0b2`, with only the two files from candidate commit `8303376` overlaid before execution. The runtime used:

- a physical Composer `vendor` directory synchronized to `composer.lock`;
- a separate SQLite database created from `migrate:fresh --seed`;
- a separately compiled production frontend;
- a local-only simulation environment with synthetic-only enforcement;
- no production clinical integration endpoints or credentials.

Composer resolved application classes from the isolated rehearsal directory. An earlier run that reused the workspace through a symlinked Composer directory was rejected as evidence and was not used for any result below.

## Defect found and resolved

The first isolated disposable-session attempt exposed a real clone contract defect. The reference journey builder selected medication stock using the source fixture's hard-coded lot number, while the clone service correctly generated a distinct lot number for every disposable session.

Candidate commit `8303376` changes stock selection to the cloned session, authored medication, and synthetic-data boundary. Its regression test proves that:

- the disposable clone reaches `FINALIZED`;
- the pristine source encounter remains `PLANNED`;
- source stock remains unchanged;
- cloned stock is dispensed from the cloned lot;
- stock movement belongs only to the cloned session.

## Readiness gate

`php artisan simulation:lab-preflight --json` returned:

- overall status: `READY`;
- passed checks: 16;
- failed checks: 0;
- source session: `SIM-RJ-UEU-001`;
- source graph: pristine, synthetic, and `PLANNED`;
- reserved demo accounts: 10 active and verified;
- active source assignments: 10;
- initial source tasks: four expected pristine states.

The command is read-only and was run both before and after the disposable journey. The post-journey result remained `READY` with 16 passes and zero failures, proving that the source fixture was not progressed.

## Terminology evidence

The rehearsal imported the user-supplied reference workbooks as verified terminology releases:

| System | Release | Concepts | Verified checksum suffix |
| --- | --- | ---: | --- |
| ICD-10 | `ICD10_2010` | 18,543 | `5aac548c5f4e` |
| ICD-9-CM | `ICD9CM_2010` | 4,626 | `7a52e08f8d78` |

These files were used only as coding terminology references inside the main outpatient workflow. No E-Klaim workflow, adapter, claim payload, or claim screen was included.

## Disposable journey result

- Session code: `LAB-REHEARSAL-20260724-02`
- Initial clone state: `CREATED`
- Initial encounter state: `PLANNED`
- Final journey state: `COMPLETED`
- Final encounter state: `FINALIZED`
- Synthetic flag: true

Final evidence counts:

| Evidence | Count |
| --- | ---: |
| Clinical document versions | 2 |
| Results | 1 |
| Encounter closures | 1 |
| Procedures | 1 |
| Record-quality reviews | 1 |
| Coding assignments | 2 |
| Work tasks | 27 |

A second execution returned `ALREADY_FINALIZED` with the same counts, confirming idempotency.

## Facilitator browser rehearsal

The synthetic facilitator signed in through the clean local application and selected the disposable session. The browser showed:

- an explicit `SIMULASI — DATA SINTETIS` boundary;
- no self-registration or real-patient wording;
- exactly one open facilitator debrief task;
- the selected disposable session and facilitator assignment context;
- a finalized synthetic encounter;
- 35 source events, 19 handoffs, and six supervisor decisions;
- nursing, medicine, pharmacy, RMIK, and coding provenance;
- human-reviewed diagnosis code `R42`;
- human-reviewed procedure code `38.99`;
- a clear statement that coding was not finalized automatically;
- no Claims or E-Klaim capability;
- no browser console errors.

The shared debrief note remained visibly separate from the clinical record and required explicit confirmation that it was not a medical record, private note, or student grade.

## Automated evidence

The synchronized isolated candidate passed:

- production frontend build;
- ESLint;
- Prettier check;
- TypeScript check;
- Pint;
- PHPStan with zero errors;
- 72 of 72 frontend tests;
- 254 of 254 PHP tests;
- 3,179 PHP assertions;
- locked Composer dependency audit with zero advisories;
- npm dependency audit with zero vulnerabilities.

The release-manifest test initially failed only because a Git archive has no `.git` metadata. After the disposable archive was initialized as a local Git repository, the complete `composer ci:check` suite passed. No application code was changed to bypass that test.

## Remaining decision gates

This rehearsal does not authorize class use. Before routine laboratory scheduling:

1. run a supervised dry run with one facilitator and a small student group;
2. record usability, timing, account, and recovery findings;
3. correct any critical or high-severity finding;
4. obtain Daniel's explicit scope and pilot decision;
5. keep the application synthetic-only and separate from production hospital data.

Current recommendation: technically suitable for the next **small supervised dry-run gate**, but not yet accepted for routine class use.
