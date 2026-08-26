# T1 cross-setting clinical-entry locking evidence — 2026-08-27

## Evidence boundary

**Classification: `LOCAL / NOT_DEPLOYED`.** This unpublished increment hardens the existing IGD and RI clinical-note workflow on base `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`. It uses synthetic data only and makes no GitHub, GitHub Actions, Vercel, hosted Supabase, owner-acceptance, or production claim.

This is an owner-independent transaction/concurrency prerequisite. It does **not** add `CANCELLED`, a cancellation record/route/UI, bed release, report change, or any new clinical field/state. The cross-setting cancellation ADR and CAN-01 through CAN-20 pack remain **PROPOSED / NOT AUTHORIZED**.

## Implemented boundary

- One `LockedClinicalEntryWriter` now owns IGD/RI note creation, encounter state transition, and required success audit.
- The writer re-resolves and locks the encounter row by primary key inside the transaction, then independently verifies the synthetic-patient relationship, expected care setting, and positive `Encounter::EXAMINATION_STATUSES` allowlist while the lock is held.
- Nursing entry preserves `REGISTERED -> IN_EXAMINATION`; medical entry preserves transition to `READY_FOR_RM`.
- A stale route model, `CLOSED`, or unknown state cannot be used to write a clinical entry.
- Audit failure rolls back both the entry and encounter-state change.
- IGD and RI controllers authorize the entry-specific capability before the shared writer evaluates care setting or current business state, preventing unauthorized cross-setting and closed-state disclosure.
- Paired cross-setting denial tests require HTTP 403, a centralized `authorization.denied` audit, no clinical entry, and an unchanged encounter state.
- The audit architecture contract now follows the shared writer rather than requiring duplicate controller audit calls.

## Local verification

Focused clinical and cross-setting suites:

```bash
php artisan test \
  tests/Feature/Clinical/LockedClinicalEntryWriterTest.php \
  tests/Feature/Emergency/EmergencyFlowTest.php \
  tests/Feature/Inpatient/InpatientFlowTest.php \
  tests/Feature/Inpatient/ContinuousInpatientTeachingJourneyTest.php
```

Result: **PASS — 20 tests, 274 assertions**.

Focused code plus audit-architecture contract:

```bash
php artisan test \
  tests/Feature/Clinical/LockedClinicalEntryWriterTest.php \
  tests/Feature/Emergency/EmergencyFlowTest.php \
  tests/Feature/Inpatient/InpatientFlowTest.php \
  tests/Feature/Inpatient/ContinuousInpatientTeachingJourneyTest.php \
  tests/Unit/Audit/AuditWritePathArchitectureTest.php
```

Result: **PASS — 24 tests, 3,244 assertions**.

Additional checks:

- PHP application suite: **PASS — 451 tests; 449 passed, 2 skipped; 5,717 assertions**.
- PHPStan with 1 GiB analysis limit: **PASS — 0 errors**.
- Pint focused check: **PASS**.
- `git diff --check`: **PASS**.

## Independent review

Independent read-only review verdict: **GO — no blocking findings** for this owner-independent locking milestone. The reviewer confirmed authorization precedes encounter care-setting/state evaluation, the encounter row is locked by primary key before synthetic/care-setting/status revalidation, and both opposite-setting denial tests prove 403 plus centralized denial audit and no mutation. The reviewer independently ran 19 focused tests with 151 assertions, PHPStan level 7, Pint `--test`, and targeted `git diff --check`; all passed. No file or external system was changed by the reviewer.

## Claims this evidence does not make

- SQLite execution does not prove PostgreSQL/MySQL row-lock or independent-process race behavior.
- No cancellation-versus-first-note race has been executed because cancellation is not implemented or approved.
- It does not prove concurrent RI bed allocation, hosted load, rollback/restore, or cross-failure-domain recovery.
- It does not close G0/G3, appoint an owner, approve CAN-01 through CAN-20, or change any capability to owner accepted.
- It does not authorize a commit, push, pull request, CI run, deployment, hosted migration, real patient data, or live BPJS/VClaim/SATUSEHAT/integration use.

## Next evidence after owner approval

When cancellation is approved and implemented, run independent-process PostgreSQL 17 and MySQL 8.4 races for cancellation versus first clinical write. Exactly one legal outcome may commit; `CANCELLED` plus a dependent clinical entry is forbidden. Hosted UAT remains a separate later approval.
