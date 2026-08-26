# T1 Local PostgreSQL 17 Daily Queue Concurrency Evidence

**Status:** PASS — local disposable PostgreSQL 17 evidence only<br>
**Captured:** 2026-08-26 14:52:53 UTC / 21:52:53 Asia/Jakarta<br>
**Baseline HEAD:** `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88` with an intentionally dirty, uncommitted local milestone<br>
**Evidence:** `storage/app/queue-allocation-rehearsals/20260826T145253Z-e1653e619733.json` (ignored, mode `0600`)<br>

## Claim Boundary

This evidence proves atomic daily queue allocation on one local disposable PostgreSQL 17 instance using independent PHP processes and the private `laravel` schema. It does not prove hosted latency, hosted concurrency, capacity, SLA, or MySQL 8.4 behavior. No commit, push, GitHub Actions run, Vercel deployment, or hosted Supabase migration occurred.

The database was generated inside the closed `simrs_queuealloc_<12 hex>` namespace, contained zero non-synthetic patients, and was removed and re-queried as absent before PASS evidence was written. The evidence contains aggregates and source hashes only; it excludes database names, hosts, users, connection strings, secrets, patient/account identifiers, backend PIDs, and raw worker output.

## Implemented Invariant

- `daily_queue_counters.queue_date` is the one global daily counter across RJ, IGD, and RI.
- `DailyQueueAllocator` requires an active outer transaction, inserts the counter row if absent, locks it with `SELECT ... FOR UPDATE`, and returns queue date and number together.
- Each registration captures `registered_at` once and uses that instant for its Asia/Jakarta queue date, queue allocation, encounter timestamp, and audit metadata.
- `(encounters.queue_date, encounters.queue_number)` is database-unique.
- Encounter, counter increment, patient mutation, and required audit event commit or roll back together.
- Synthetic reset preserves committed counter high-water marks.
- Teaching census preserves same-date marker assignments, allocates again only when its rolling date changes, and never decrements a high-water mark.
- No RJ, IGD, or RI registration path retains runtime `MAX(queue_number) + 1` allocation.

## PostgreSQL 17 Contention Result

The harness first proved that `2030-01-15` had neither a counter nor an encounter. It then held a PostgreSQL advisory lock only as a simultaneous-start barrier. The readiness gate counted only ungranted `ShareLock` waiters for the exact disposable database, barrier key, and 16 generated worker application names. Sixteen independent Artisan worker processes opened sixteen distinct PostgreSQL backend sessions, entered transactions, waited together at that exact barrier, and then called the real allocator.

| Assertion | Result |
|---|---:|
| Independent commit workers / backend sessions | 16 / 16 |
| Counter and encounters absent before first-row race | PASS |
| Simultaneous barrier waiters observed | PASS |
| Committed encounters | 16 |
| Distinct queue numbers | 16 |
| Minimum / maximum | 1 / 16 |
| Counter high-water | 16 |
| Required registration audit events | 16 |
| Non-synthetic patient rows | 0 |
| Duplicate or missing committed number | 0 |

## Rollback Reuse Result

After the contention phase, worker A allocated number 17, wrote its synthetic patient, encounter, and audit inside the transaction, and held the transaction open. A second independent worker was observed waiting on the allocator lock. Worker A then rolled back; the waiting worker acquired the counter row and committed number 17. The harness directly queried the generated encounter resource identifiers and proved the rolled-back encounter and audit absent while the committed encounter and audit were each present once.

| Assertion | Result |
|---|---:|
| Rollback worker in-transaction hold observed | PASS |
| Waiting worker lock wait observed | PASS |
| Independent rollback/reuse backend sessions | 2 |
| Rolled-back allocated number | 17 |
| Waiting worker committed number | 17 |
| Rolled-back encounter present | No |
| Rolled-back audit present | No |
| Final committed encounters / distinct numbers | 17 / 17 |
| Final minimum / maximum / counter | 1 / 17 / 17 |
| Final required registration audit events | 17 |

This is intentional transactional behavior: an uncommitted number may be reused because it never became a hospital fact. A committed number is not reused, including after an audited synthetic reset.

## Migration Round Trip

The same disposable PostgreSQL database then executed the queue migration rollback and exact-path reapply.

- Rollback removed `daily_queue_counters` and `encounters.queue_date`.
- Reapply restored non-null `queue_date`.
- Reapply restored `encounters_queue_date_number_unique`.
- Deterministic synthetic remediation rebuilt a valid 1–17 queue and counter high-water 17.
- The non-synthetic patient count remained zero.

The migration data rewrite is not inherently reversible: its structural rollback cannot reconstruct invalid legacy duplicate numbers. Backup/restore remains the data rollback boundary for any hosted cutover.

## Local Automated Evidence

- Full PHP suite: **431 tests, 429 passed, 2 skipped, 5,521 assertions, PASS**.
- Focused application and invariant subset: **107 tests, 391 assertions, PASS**.
- PostgreSQL harness contract: **12 tests, 145 assertions, PASS**.
- PHPStan: **0 errors** for the implementation before the rehearsal.
- Post-change local PostgreSQL backup/restore rehearsal: **PASS**, snapshot `07d5b6c21eb08fdf747528b53b85197d617fa862b4f649bf421e2f54ee173990`, 0 observed quiesced-fixture row loss, evidence `storage/app/recovery-rehearsals/20260826T143840Z-6f2d1cc12247.json`. This remains same-host disposable evidence, not an approved institutional RPO/RTO claim.
- Source contract SHA-256: `1ad215c63a75a6b9012a290a31463a90255a2d2da920839943633ec82921b4c6`.
- Migration-set SHA-256: `0e2d869914d36d7463b5793eb2e2322bcfb667aa258db896c96f1d92046207f1`.
- Harness SHA-256: `16fad7a4f85dddcc3957c34bd998a82a8a763439e236ac2baca57619efacb718`.
- Contract-test SHA-256: `fc98a43f6d2688ee7963727f15c61f1741fd808a95cea385ff9d540000e261fd`.

Earlier artifacts were superseded as the harness was strengthened. The final PASS evidence named above came from a fresh disposable database after the fail-closed barrier and direct audit-persistence checks were added; cleanup then independently showed zero generated queue, query-plan, or recovery databases remaining.

## Remaining Gates

- Exact MySQL 8.4/InnoDB migration, contention, uniqueness, and rollback-reuse evidence is still open. Another MySQL version is not a substitute.
- Hosted Supabase migration locking/cutover, exact-SHA deployment, and role-based hosted UAT remain separate approval-gated work.
- GitHub and deployment publication remain paused to preserve the user's free quota.
