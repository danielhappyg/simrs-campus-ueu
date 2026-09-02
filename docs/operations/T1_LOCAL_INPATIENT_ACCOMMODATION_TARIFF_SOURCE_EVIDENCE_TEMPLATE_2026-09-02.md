# T1 Local Inpatient Accommodation Occupancy-Day Tariff-Source Exact-Engine Evidence Template

Status: **READY, NOT RUN — TEMPLATE ONLY; NO SQLITE OR EXACT-ENGINE RESULT IS RECORDED HERE**

Scope: future local disposable PostgreSQL 17.10 and MySQL 8.4.11, with SQLite used only as the application gate. Configuration starts empty: the harness seeds or infers no tariff amount or accommodation binding.

Deployment, hosted readiness, affected-owner/domain/parity acceptance, real-hospital day-count acceptance, G0, G3, and production claims: **OPEN / NOT AUTHORIZED**.

## Bound artifacts

- Authorization: `docs/new-simrs-rebuild/phase-1/INPATIENT_ACCOMMODATION_OCCUPANCY_DAY_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md`
- Authorization contract: `tests/Documentation/InpatientAccommodationOccupancyDayTariffSourceV1LocalEngineeringAuthorizationTest.rb`
- Executable local harness: `scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb`
- Harness contract: `tests/Documentation/LocalInpatientAccommodationTariffSourcePortabilityHarnessContractTest.rb`
- Prospective provenance migration: `database/migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php`
- Accommodation tariff/source migration: `database/migrations/2026_09_02_000600_create_inpatient_accommodation_tariff_source.php`
- Future create-only evidence directory: `storage/app/portability-rehearsals/`

The harness hashes its closed source manifest, embedded worker, SQLite catalogue, exact scenario catalogue, and least-privilege grant catalogue before execution and rechecks those bytes before publication. It refuses inherited database overrides, a non-exact engine, source drift, missing scenario evidence, an unobserved database wait, rollback unexpectedly succeeding, cleanup failure, or overwriting evidence.

## Reserved local commands

These commands are reserved for the orchestrated run after the harness contract and bound source set are frozen. They have **not** been run by this template.

```sh
SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE \
  ruby scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb postgresql17

SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE \
  ruby scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb mysql8411
```

## Execution register

| Gate | Exact version | Result | Generated immutable evidence | Strict cleanup |
|---|---:|---|---|---|
| SQLite application gate | bundled | NOT RUN | — | — |
| PostgreSQL | 17.10 | NOT RUN | — | — |
| MySQL | 8.4.11 | NOT RUN | — | — |

Do not mutate this template into a final evidence record. Each successful exact-engine run must create a new mode-`0600` JSON record with create-only semantics after all 28 scenario results pass, the source binding remains unchanged, and strict cleanup is verified.

## Closed scenario register

All rows are **NOT RUN** on both exact engines:

1. fresh migration and empty readback;
2. empty rollback/reapply of both migrations;
3. exact-role, prospective bed-version, legacy-no-inference, and provenance-trigger boundaries;
4. deterministic same-day, midnight, transfer, and multi-day occupancy allocation;
5. no admission/census/cancellation/open-interval automatic charge;
6. exact effective-dated binding, terminal retirement, context, and positive-integer refusal;
7. exact four-domain pharmacy/radiology/laboratory/accommodation typed union;
8. partial and complete source/bill behavior with immutable historical values;
9. replay, changed payload, retroactive, and stale refusal;
10. independent-process same-day import, transfer/synchronization, discharge/issue-cutoff, bed-version/binding-resolution, and competing-binding-writer races with a real wait and third-connection reconciliation;
11. application SQL, database constraint, trigger, mutable-head, and append-only refusal;
12. recovery corruption refusal, reduced runtime grants, reset/recovery competition, retained-evidence rollback refusal, and strict cleanup.

## Required future readback

- Every eligible interval uses the exact prospective bed-version public ID, integer version, and digest retained by the clinical transaction; labels, current heads, and historical inference are forbidden.
- Half-open intervals and application-timezone anchors yield at most one positive `OCCUPANCY_DAY` source per encounter and service date.
- `finance_charge_events` accepts exactly one typed source across pharmacy, radiology, laboratory, and accommodation.
- Ordinary runtime grants are closed, table-specific `SELECT` only, with no wildcard, DDL, trigger, reset, user-administration, payment, claim, or integration authority.
- All required races use independent processes, observe a real database wait, record lock order, and reconcile durable state through a third connection.
- Reset removes the bounded synthetic accommodation graph in dependency order while preserving required audit evidence. Both migration downs refuse while their protected evidence remains.

## Claim boundary

This immutable template proves no execution. A later truthful PASS would prove only the exact bound local synthetic bytes on SQLite plus disposable PostgreSQL 17.10 or MySQL 8.4.11. It would not establish a real hospital tariff or day-count policy, owner/domain/parity acceptance, hosted readiness, deployment, production authority, G0 closure, or G3 closure.
