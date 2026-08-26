# T1 Continuous Emergency Scaffold Evidence — 2026-08-27

**Status:** `LOCAL / BOUNDED AUTOMATED AND BROWSER PASS`
**Published base:** `1e188215ff4b39af547cda75a1904cfe1d062f75`
**Data boundary:** `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`; synthetic test records only
**Workflow binding:** bounded partial evidence for `E2E-02`

## Claim boundary

This record covers only the existing synthetic IGD scaffold through public application routes:

1. an authorized registrar creates one synthetic IGD encounter;
2. the read-only triage projection and IGD worklist expose that encounter;
3. an authorized nurse writes one nursing intake and the encounter moves from `REGISTERED` to `IN_EXAMINATION`;
4. a nursing user is denied when attempting a medical assessment, with no partial clinical write or state change;
5. an authorized physician writes one medical assessment and the current scaffold moves to `READY_FOR_RM`; and
6. successful and denied actions retain attributable audit evidence.

`READY_FOR_RM` is only the existing scaffold state. This record does not treat it as an approved RMIK handoff, completeness decision, coding state, or encounter closure.

## Current local evidence

| Evidence | Current result |
| --- | --- |
| Continuous IGD public-route journey | 1 test, 125 assertions, local SQLite pass |
| E2E-02 focused slice | 12 tests, 202 assertions, local SQLite pass |
| PostgreSQL 17.10 exact-engine rerun | 12 E2E-02 tests, 202 assertions; PASS inside 453-test exact-engine suite |
| MySQL 8.4.11 exact-engine rerun | 12 E2E-02 tests, 202 assertions; PASS inside 453-test exact-engine suite |
| Native-browser role rehearsal | Registrar, nurse, physician, and RMIK route/permission surfaces plus bounded IGD/RI note journeys; PASS with explicit accessibility limits |
| Hosted role-based UAT | `NOT_RUN` |
| Owner acceptance | `NOT_READY` |

Primary executable evidence:

- `tests/Feature/Emergency/ContinuousEmergencyTeachingJourneyTest.php`
- `tests/Feature/Emergency/EmergencyFlowTest.php`
- `tests/Feature/Clinical/LockedClinicalEntryWriterTest.php`
- `scripts/rehearse-local-portability-full-suite.rb`
- `docs/operations/T1_LOCAL_CURRENT_MANIFEST_PORTABILITY_EVIDENCE_2026-08-27.md`
- `docs/operations/T1_IGD_RI_NATIVE_BROWSER_REHEARSAL_2026-08-27.md`

## Explicitly unproven

- triage observations, acuity scales, reassessment, prioritization, or override rules;
- diagnostic, procedure, medication, pharmacy, inventory, or financial behavior;
- disposition to discharge, inpatient admission, referral, death, or DOA;
- an atomic IGD-to-Rawat-Inap transfer or bed-management workflow;
- RMIK completeness, coding, sign-off, or closure for IGD;
- downstream reconciliation, hosted database behavior, hosted UAT, load, accessibility acceptance, or owner approval.

The exact-engine and bounded browser rehearsals are complete for the narrow scaffold above. `E2E-02`, `PAR-REG-002`, `PAR-CLN-002`, and `PAR-CLN-003` nevertheless remain partial/provisional because the explicitly unproven workflow, hosted, reconciliation, accessibility, and owner-acceptance boundaries are material parts of the intended hospital workflow.
