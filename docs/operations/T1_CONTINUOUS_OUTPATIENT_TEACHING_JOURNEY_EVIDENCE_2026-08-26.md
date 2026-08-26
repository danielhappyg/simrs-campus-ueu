# T1 — Continuous outpatient teaching journey evidence

**Date:** 2026-08-26<br>
**Status:** PASS — local automated teaching-journey evidence only<br>
**Repository baseline:** `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88` plus the unpublished local test named below<br>
**Data boundary:** `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`; synthetic test records only<br>
**Remote boundary:** no commit, push, pull request, GitHub Actions run, deployment, hosted migration, or hosted data mutation

## Purpose

Prove the currently implemented outpatient teaching slice as one continuous, attributable, multi-role journey through public Laravel routes. This is current clean-slate rebuild evidence. It does not import Checkpoint 2, Antrean/work-queue MVP, or other DEC-013 anti-reference evidence.

## Evidence artifact

- `tests/Feature/Outpatient/ContinuousOutpatientTeachingJourneyTest.php`

The test keeps one synthetic patient and encounter identity across registrar, nurse, physician, laboratory, and RMIK responsibilities.

## Proven behavior

1. Registrar creates a synthetic outpatient encounter with accountable patient and encounter attribution.
2. Nurse saves and finalizes a versioned nursing document.
3. Physician saves and finalizes a versioned medical document.
4. Physician creates a synthetic laboratory order tied to the same encounter.
5. RMIK review and sign-off fail closed while the active order remains unresolved, including an attributable `active_lab_orders` denial.
6. Nurse records the one immutable synthetic FINAL result and completes the order.
7. RMIK records a current completeness review and closes the encounter through attributable sign-off.
8. Closed-state clinical mutation is rejected without changing the signed record.
9. Registrar print and recap surfaces retain the same public encounter identity and closed status.
10. Material successful and denied actions retain actor attribution in the audit ledger.

## Verification results

| Environment | Result |
| --- | --- |
| SQLite in-memory | PASS — 1 test, 154 assertions |
| PostgreSQL 17.10, private `laravel` schema | PASS — 1 test, 154 assertions |
| MySQL 9.7.1 compatibility run | PASS — 1 test, 154 assertions |

The MySQL 9.7.1 result is compatibility evidence only and does not replace the required MySQL 8.4 release-gate run. PostgreSQL and MySQL checks used dedicated disposable local databases; both were removed after verification.

## Explicitly not proven

- full E2E-03: diagnosis/problem list, referral, prescription, pharmacy handoff, supervision, and the complete negative-variant catalogue remain absent
- full E2E-05: specimen, collection/rejection/recollection, processing, correction/amendment, critical result, acknowledgement, charge, pathology, and microbiology remain absent
- full E2E-12: inpatient RMIK, coding, amendment request, filing, disclosure, retention, and complete access-log behavior remain absent
- Clinical, Laboratory, or RMIK acceptance of the structured fields, checklist, sign-off semantics, or DEC-016
- current hosted structured-v1 browser UAT, deployment, accessibility, performance, restore, rollback, or cross-domain financial/stock/claim reconciliation
- G2 or G3 acceptance

The existing Run 3 and Run 4 records remain historical hosted slice evidence only. They must not be treated as current structured-v1 owner acceptance, and no older Antrean/Checkpoint-2 artifact may be used to fill this evidence boundary.
