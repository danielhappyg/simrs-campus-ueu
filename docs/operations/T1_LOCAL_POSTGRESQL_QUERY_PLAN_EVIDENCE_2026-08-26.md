# T1 Local PostgreSQL 17 Query-Plan Evidence — 2026-08-26

## Result

**PASS for the bounded local query-plan and index-selection slice.** A disposable PostgreSQL 17 workload proved that the reviewed composite indexes are selected for the four selective operational top-N queries and that those plans avoid sequential scans of their target operational tables, disk sorts and temporary blocks.

This is local single-user engineering evidence only. It does not prove hosted latency, concurrent classroom capacity, Vercel/Supabase performance, an SLA, or closure of risk R-13.

## Safety boundary

The rehearsal:

- requires `SIMRS_QUERY_PLAN_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_POSTGRES17_QUERY_PLANS` before any command;
- accepts only a local host literal and verifies PostgreSQL's actual server address is loopback or a local socket;
- requires PostgreSQL major 17, the private `laravel` schema, `APP_MODE=SIMULATION`, and `APP_SYNTHETIC_ONLY=true`;
- creates and removes only an attempt-owned database matching `simrs_queryplan_<12 hex>`;
- executes `EXPLAIN ANALYZE` inside a read-only transaction with statement, lock and idle-transaction timeouts;
- evaluates date bounds in `Asia/Jakarta`;
- records no real patient data, database name, password, demo password, or connection string; raw plans may contain only the reviewed synthetic search literals; and
- writes `PASS` evidence only after verified database cleanup.

The fixture contains 30,000 generated synthetic patients, 30,000 encounters, 12,000 lab requests and zero non-synthetic patients. This is a fast topology fixture, not an owner-approved representative hospital or teaching-cohort volume.

## Evidence binding

Current post-allocator proof:

- ignored local record: `storage/app/query-plan-rehearsals/20260826T144520Z-f4b60367a444.json`;
- migration file-set SHA-256: `0e2d869914d36d7463b5793eb2e2322bcfb667aa258db896c96f1d92046207f1`;
- fixture SQL SHA-256: `4ac1209e1e3945016d7739c017cb09acd04033d03ea1f4ed479992289c7097d3`;
- probe contract SHA-256: `9f52d21d31dd38066e913cb486a44158e2bf2161f43952e6cda4c5b73671f148`;
- harness SHA-256: `6050d9b5f41e263ba8b7ff81ef2d381081acd09f53cf96689979ec8fe365a782`;
- contract-test SHA-256: `a118c15a85c43dfd5f9080d1bfcc83890f47346f8f9873d46eb41a3f248a496c`;
- application query-source SHA-256: `2d59e1860fe9afe9f9accff5944ba0d981e188ee6e9f55cd70bb8c54ee1e6e01`.

The record binds HEAD `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`, explicitly records that the working tree is dirty, and supersedes the earlier pre-allocator query-plan records. It binds the exact harness, contract test, fixture, migration set and operational query-source bytes.

## Proven plan changes

| Probe | Before migration | After migration | Required assertion |
|---|---|---|---|
| Registration today, half-open range, 50 rows | sequential scans of `encounters` and `patients`; no selected index; 1,261 root shared-hit blocks | `encounters_care_registered_id_idx` plus patient PK; no sequential scan; 201 root shared-hit blocks | PASS |
| Examination, 31-day range, 100 rows | sequential scans of `encounters` and `patients`; no selected index; 1,261 root shared-hit blocks | `encounters_care_registered_id_idx` plus patient PK; no sequential scan; 505 root shared-hit blocks | PASS |
| Active laboratory worklist, 100 rows | status-only index plus full encounter/patient scans; 1,536 root shared-hit blocks | `lab_requests_status_requested_id_idx` plus encounter/patient PK; no sequential scan; 702 root shared-hit blocks | PASS |
| Recap, 31-day range, 500 rows | sequential scans of `encounters` and `patients`; no selected index; 1,261 root shared-hit blocks | `encounters_care_registered_id_idx` plus patient PK; no sequential scan; 2,006 root shared-hit blocks | PASS |

Root buffer totals are reported directly from PostgreSQL; child cumulative counters are not summed. The recap buffer count increased because the ordered index path performs bounded patient-PK checks for 500 returned rows. The acceptance gate is plan topology and spill behavior, not a fabricated universal buffer ceiling.

Measured execution times are retained in the ignored JSON records for diagnosis but are not acceptance thresholds. Hardware, cache state, fixture assumptions and the absence of concurrency make those milliseconds unsuitable for hosted or SLA claims.

## Implemented change

- `database/migrations/2026_08_26_000100_add_operational_worklist_indexes.php` adds portable B-tree indexes for `(care_setting, registered_at, id)` and `(status, requested_at, id)`.
- Registration, examination and recap date filters now use half-open timestamp ranges in the configured `Asia/Jakarta` application timezone instead of casting `registered_at` with `whereDate`.
- The disposable workload covers the four list queries plus paginator counts, recap online count, CSV maximum ID and first chunk, the atomic daily-counter primary-key lookup, leading-wildcard patient search, and exact-MRN lookup.
- The performance fixture now assigns collision-free global daily queue numbers above each seeded date's existing counter high-water mark and synchronizes `daily_queue_counters` after insertion.
- The latest disposable PostgreSQL run rolled back the index migration, verified both indexes were absent, reapplied the migration, and verified both reviewed indexes were restored.
- MySQL 8.4 migration execution is not yet proven locally; current cross-engine support is source-level Laravel schema portability plus SQLite and PostgreSQL execution evidence.

## Known gaps and next decisions

- Leading-wildcard patient search still sequentially scans `patients`. Do not add PostgreSQL trigram or database-specific search behavior until search semantics, extension governance and MySQL portability are approved.
- The retired daily `MAX(queue_number) + 1` path is no longer part of the application or this workload. The current counter lookup selects `daily_queue_counters_pkey`; transactional locking, rollback reuse and committed uniqueness are proven separately in `T1_LOCAL_POSTGRESQL_DAILY_QUEUE_CONCURRENCY_EVIDENCE_2026-08-26.md`.
- Unselective paginator counts can correctly retain broader scans. No rule requires PostgreSQL to avoid every sequential scan.
- Late-page offset behavior, concurrent load, write contention, reset load, report concurrency, hosted cold starts and Supabase resource behavior remain unproven.
- R-13 remains **OPEN** until owners approve the workload and service targets and hosted/concurrent evidence is completed.
- A future hosted PostgreSQL migration needs a reviewed maintenance/cutover decision because normal index creation can lock writes on a populated table.

## Verification

- Query-plan harness contract: 14 tests, 175 assertions, PASS.
- Focused outpatient/laboratory/performance pack: 33 tests, 422 assertions, PASS.
- Focused Pint check: PASS.
- Disposable PostgreSQL 17 post-migration rehearsal: 14 probes, 4 mandatory candidate assertions, PASS.
- Full backend quality suite: Pint PASS, PHPStan 0 errors, PHPUnit 431 tests / 429 passed / 2 skipped / 5,521 assertions.
- Disposable PostgreSQL 17 backup/restore after the queue migration: exact source/restore snapshot PASS, 0 reconciled data-loss rows, local-only evidence `20260826T143840Z-6f2d1cc12247.json`.

No GitHub push, pull request, GitHub Actions run, Vercel deployment or hosted Supabase migration was performed.
