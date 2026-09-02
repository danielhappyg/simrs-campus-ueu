# T1 Local Cross-Setting Laboratory Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Executed: 2026-09-01 Asia/Jakarta
Scope: PostgreSQL 17.10 and MySQL 8.4.11 in isolated local databases

Deployment, hosted readiness, laboratory-owner acceptance, parity acceptance, and production claims remain **false**. This record does not authorize a commit, push, hosted migration, or deployment.

## Bound implementation and verification

- Harness: `scripts/rehearse-local-laboratory-portability.rb`
- Harness contract: `tests/Documentation/LocalLaboratoryPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_01_000200_create_cross_setting_laboratory_tables.php`
- Feature reference: `tests/Feature/Laboratory/CrossSettingLaboratoryWorkflowTest.php`
- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md`
- Shared source-binding aggregate: `6192dcfb1ca202eecf15ce508cdf1c8c654ce7dc40a15cd03a39de7f597cf0f0`
- Embedded worker binding: `d171351ecc2ad751996d7223cd7b060fac018dabb0113f2401fa8c4f68309911`
- Scenario catalogue binding: `dfae091e27ceaee537e4571073a9fc513732c94eb9fb57c9cad6bb5c2b822a67`
- Runtime-grant catalogue binding: `22777344c7df1f192a1c28382839d493e2e3eb059c39325f451f60c0feee8be8`

## Exact-engine execution register

| Engine | Result | Scenarios | Evidence | SHA-256 | Mode | Strict cleanup |
|---|---|---:|---|---|---|---|
| PostgreSQL 17.10 (`server_version_num=170010`) | PASS | 17/17 | `storage/app/portability-rehearsals/20260831T201328Z-postgresql17-laboratory-95d808026060.json` | `fba91619a185ef4a30b698c5c8a59786256225466b46555a1915277d260f4469` | `0600` | PASS |
| MySQL 8.4.11 (InnoDB) | PASS | 17/17 | `storage/app/portability-rehearsals/20260831T201400Z-mysql8411-laboratory-cb8e8ef7851d.json` | `b46f2a1cc31d4f0485422b6002d50d4c9407c0b97f705bc111244a20285fc2f0` | `0600` | PASS |

Both evidence files report kind `SIMRS_LOCAL_CROSS_SETTING_LABORATORY_PORTABILITY`, status `PASS`, the same source, worker, scenario, runtime-grant, and command-catalogue bindings, and all four cleanup flags as true: disposable database removed, temporary server removed, temporary user state removed, and temporary worker removed. Every recorded protocol worker (`PREPARE`, `SEQUENTIAL`, `APP_GUARDS`, `OWNER_GUARDS`, `RUNTIME_GUARDS`, `CORRUPTION`, `VERIFY_RACE`, `VERIFY`, and `RESET`) finished in `COMMITTED` state. The observed two-process race produced one `APPLIED` and one `REPLAYED` outcome with one durable order and one durable receipt.

## Scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration | PASS | PASS |
| Empty down/reapply | PASS | PASS |
| Exact role boundary | PASS | PASS |
| Outpatient, emergency, and inpatient orders | PASS | PASS |
| Master create/update/retire | PASS | PASS |
| Rejection/recollection → Draft → critical communication → Verified → two independently communicated critical amendments → acknowledgement | PASS | PASS |
| Normal → critical and critical → changed-critical amendments each require their own immutable communication | PASS | PASS |
| Closure and cancellation blockers | PASS | PASS |
| Exact replay after later head advance | PASS | PASS |
| Changed-payload same-key conflict | PASS | PASS |
| Two-process same-key race and unique reconciliation | PASS | PASS |
| Application SQL write guard refusal | PASS | PASS |
| Database snapshot/delete/truncate refusal | PASS | PASS |
| Full evidence-chain corruption refusal | PASS | PASS |
| Least-privilege runtime grants | PASS | PASS |
| Bounded reset with dependency-order deletion and audit preservation | PASS | PASS |
| Retained-evidence rollback refusal and durable invariant verification | PASS | PASS |

The closed machine catalogue remains 17 scenarios; the normal-to-critical communication and multi-amendment assertions are recorded inside `order-specimen-result-critical-amend-acknowledge`.

## Regression readback

- Full PHP: 758 tests executed; 754 passed; 4 intentional skips; 13,716 assertions.
- Focused laboratory integration and migration: 21 tests; 426 assertions; all passed.
- Dedicated laboratory portability contract: 8 runs; 200 assertions; 0 failures and 0 errors.
- Ruby syntax, embedded PHP syntax, and bounded diff checks passed.

## Least-privilege boundary

- Reference tables are read-only.
- The encounter head has only the additional update authority required for PostgreSQL row locks; it has no insert or delete authority.
- Laboratory master, order, and specimen-attempt heads allow select, insert, and update, but no delete.
- Immutable reservations, versions, cancellations, specimen events, result versions, critical communications, acknowledgements, receipts, and audit events allow select and insert only.
- Reset uses a separate bounded identity and deletes the laboratory graph in dependency order while retaining master and audit evidence.

## Claim boundary

This record proves only the bound local implementation bytes on disposable PostgreSQL 17.10 and MySQL 8.4.11. It does not prove hosted Supabase migration, production configuration, capacity, clinical validation, laboratory-owner acceptance, operational support readiness, LIS/analyser interoperability, or deployment approval. No LIS, analyser, label printer, BPJS, VClaim, SATUSEHAT, mail, or other external integration was exercised.
