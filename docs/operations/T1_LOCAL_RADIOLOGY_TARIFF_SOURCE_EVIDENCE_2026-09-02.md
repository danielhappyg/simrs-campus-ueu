# T1 Local Radiology Tariff/Source Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Executed: 2026-09-02 Asia/Jakarta
Scope: PostgreSQL 17.10 and MySQL 8.4.11 in isolated harness-owned databases

Deployment, hosted migration, owner acceptance, affected-domain acceptance, parity acceptance, G0, G3, automatic-charge authority, and production claims remain **false**. This record does not authorize a commit, push, hosted migration, or deployment.

## Bound implementation

- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_RADIOLOGY_PERFORMANCE_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md`
- Harness: `scripts/rehearse-local-radiology-tariff-source-portability.rb`
- Harness contract: `tests/Documentation/LocalRadiologyTariffSourcePortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_02_000300_create_radiology_performance_tariff_source.php`
- Application-source binding: `cbe00da53dbb97a98e70aba0f691d70c83cfd63b4cb01bf57720c8f7f1ddc4bb`
- Embedded worker binding: `14dbefb82eed545de62ba63e4730f3c9f6312720655a938af579f1e82b1f043f`
- Scenario catalogue binding: `92bc0f7f1b781e569e4ad2f01241dffb582ac35db1e3481fe2f8c55e8f93efcd`
- Runtime-grant catalogue binding: `d39e19f98c32174e2a2af9818c10eaa5e4ee649b103e817585154017022dd2f6`

## Exact-engine register

| Engine | Result | Scenarios | Evidence | SHA-256 | Strict cleanup |
|---|---|---:|---|---|---|
| PostgreSQL 17.10 (`server_version_num=170010`, schema `laravel`) | PASS | 20/20 | `storage/app/portability-rehearsals/20260902T035600Z-postgresql17-radiology-tariff-source-06366a228ffe.json` | `9cc72386157da41f83ca74ca47865bba5225153f6f8e1e9107d3f79a6d8df1fc` | PASS |
| MySQL 8.4.11 (InnoDB) | PASS | 20/20 | `storage/app/portability-rehearsals/20260902T040246Z-mysql8411-radiology-tariff-source-8f572fe9bba5.json` | `aad16d2773bd8c0d26654fdd0cabb85b34f4fa7cf8f98aca0f92271b0de2b43d` | PASS |

Both results bind the same application source, embedded worker, scenario catalogue, and runtime-grant catalogue. Both record `owner_acceptance_claim=false`, `g0_claim=false`, `g3_claim=false`, and `strict_cleanup_verified=true`.

## Closed scenario result

Both engines passed all 20 bounded scenarios: fresh migration; empty down/reapply; exact roles; three care-setting bindings; performed-date resolution; future, half-open, and terminal retirement; upstream refusal; unperformed-order no-charge behavior; one performance/one typed source; radiology-only bill snapshot; unresolved partial synchronization and issuance refusal; later gap resolution into a new immutable bill version; replay/key/stale refusal; observed same-performance and competing-binding two-process races; application and database guards; audit/corruption/reconciliation refusal; least privilege; reset/recovery; retained-evidence rollback refusal; and strict cleanup.

## Exact-engine defect closed before PASS

The first exact-engine attempts exposed a cross-engine SQL-classification defect: a legitimate `SELECT ... FOR UPDATE` locking read on a governed tariff item was classified as a write because the token `UPDATE` appeared in the lock clause. The bounded guard correction permits single SELECT/CTE locking reads while continuing to refuse real UPDATE, DELETE, DDL, executable comments, and multi-statements. Focused guard regressions, Pint, PHPStan, and both exact engines passed after the correction. Failed attempts published no PASS evidence.

## Claim boundary

This evidence proves only the bound local implementation bytes on disposable exact engines. It proves governed radiology-master/care-setting/tariff binding, performed-service-date resolution, integer-rupiah immutable source snapshots, typed charge linkage, versioned bill behavior, refusal paths, races, least privilege, recovery, reset, and cleanup. It does not assert that any amount is a real or approved hospital price; authorize automatic charging; prove hosted migration, deployment, browser UAT, capacity, operations, owner or domain acceptance, parity acceptance, G0, or G3; or enable payment, accounting, claims, BPJS, VClaim, SATUSEHAT, LIS, or PACS/RIS.
