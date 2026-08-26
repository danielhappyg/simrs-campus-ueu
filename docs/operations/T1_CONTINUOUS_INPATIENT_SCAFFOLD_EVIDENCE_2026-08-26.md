# T1 — Continuous inpatient scaffold conformance evidence

**Date:** 2026-08-26<br>
**Status:** PASS — local automated scaffold evidence only<br>
**Repository baseline:** `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88` plus the unpublished local test named below<br>
**Data boundary:** `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`; synthetic test records only<br>
**Remote boundary:** no commit, push, pull request, GitHub Actions run, deployment, hosted migration, or hosted data mutation

## Purpose

Prove that the currently implemented inpatient scaffold behaves as one coherent, attributable teaching journey through its public Laravel routes. This record does not claim a complete inpatient lifecycle, SIMRS Sahabat parity, clinical production readiness, G2 acceptance, or domain-owner acceptance.

## Evidence artifact

- `tests/Feature/Inpatient/ContinuousInpatientTeachingJourneyTest.php`

The test uses separate registrar, nurse, and physician actors and one continuous synthetic inpatient episode.

## Proven behavior

1. An authorized registrar can create a synthetic inpatient admission with ward, class, bed, payer, continuation source, and complaint context.
2. The patient and encounter retain registrar attribution and a successful `patient.register` audit event.
3. The admission is visible on the inpatient registration desk and clinical worklist with the same public encounter identity and bed context.
4. A second sequential admission to the occupied bed is rejected with a field error and without creating a patient, encounter, or audit mutation.
5. An authorized nurse can write an inpatient nursing entry, attributed to that nurse, and move the encounter from `REGISTERED` to `IN_EXAMINATION`.
6. A nurse attempting a medical entry receives a server-side denial; no clinical mutation occurs and the `authorization.denied` event is attributable.
7. An authorized physician can write the medical entry, attributed to that physician, and move the existing scaffold to `READY_FOR_RM`.
8. The final clinical detail retains both entries in order with their respective authors.

## Verification results

| Check | Result |
| --- | --- |
| PHP syntax | PASS |
| Pint formatting check | PASS |
| SQLite in-memory inpatient suite | PASS — 7 tests, 189 assertions |
| PostgreSQL 17.10 focused continuous journey, private `laravel` schema | PASS — 1 test, 123 assertions |
| MySQL 9.7.1 focused continuous journey | PASS — 1 test, 123 assertions |
| Git diff whitespace check | PASS |

The MySQL result is useful forward-compatibility evidence but does not replace the required MySQL 8.4 verification at a later release gate. PostgreSQL and MySQL runs used dedicated disposable local databases; both were removed after the tests completed.

## Explicitly not proven

- concurrent bed-allocation safety; the current check proves sequential rejection only
- a linked or atomic ED-to-inpatient transition; `DARI_IGD` is currently only stored vocabulary
- transfer, class change, discharge, bed release, cancellation, correction, or reopening
- versioned inpatient documentation, clinical completeness, supervision, or an approved `READY_FOR_RM` gate
- inpatient RMIK, diagnostics, medication, pharmacy, inventory, charges, cashier, claims, or reporting reconciliation
- managed ward/bed master data under PAR-ADM-009
- hosted browser UAT, accessibility, performance, restore, rollback, or owner sign-off
- G2 or G3 acceptance

The journey deliberately stops at `READY_FOR_RM`. Extending it beyond this boundary requires the unresolved Clinical, RMIK, bed-management, pharmacy, finance, claims, and reporting decisions rather than guessed implementation rules.

## Next safe use

Retain this test in the unpublished local milestone batch as a regression contract. When the inpatient owners approve transfer, discharge, bed-release, RMIK, and downstream ledger behavior, extend the journey with those accepted states and reconciliation assertions rather than weakening or replacing this scaffold evidence.
