# ADR: Transactional Daily Registration Queue

**Status:** Accepted for local implementation; hosted rollout requires a separate milestone approval<br>
**Date:** 2026-08-26<br>
**Deciders:** Product owner / SIMRS Campus UEU system owner; implementation review by the engineering orchestrator<br>

## Context

Rawat Jalan, IGD, and Rawat Inap currently derive a global daily registration number with `MAX(queue_number) + 1`. Two concurrent registrations can observe the same maximum and create duplicate operational numbers. The database has no queue date column or daily uniqueness constraint, so it cannot reject that race.

The teaching system must remain synthetic-only while behaving like an operational hospital SIMRS. Queue allocation must therefore be:

- global across RJ, IGD, and RI for one Asia/Jakarta calendar date;
- atomic with patient, encounter, and audit writes;
- portable across PostgreSQL 17 and MySQL 8.4, with a sequential SQLite test fallback;
- reusable after a transaction rollback, but never reused after a committed registration or audited synthetic reset;
- protected by a database invariant rather than application convention alone.

## Decision

Create `daily_queue_counters`, keyed by `queue_date`, and add non-null `encounters.queue_date` with a unique constraint on `(queue_date, queue_number)`.

`DailyQueueAllocator` must run inside an existing database transaction. It derives `queue_date` from the single captured `registered_at` instant in the configured application timezone, inserts the date counter if absent, locks that counter row with `SELECT ... FOR UPDATE`, increments it, and returns the date and number together. RJ, IGD, and RI use the same allocator inside the same outer transaction as their encounter and audit writes.

Existing records may be remediated only while `APP_MODE=SIMULATION`, synthetic-only enforcement is active, and the database contains no non-synthetic patients. The migration deterministically renumbers existing synthetic encounters by `registered_at, id` within each Asia/Jakarta date, then initializes counter high-water marks. A future database containing any non-synthetic data must fail closed and use a separately reviewed migration plan.

Teaching census seeding preserves an existing marker's number when its queue date is unchanged. If its rolling date changes, it allocates a new number. Synthetic reset deletes the patient graph but preserves the counter table and high-water marks, preventing committed queue-number reuse.

## Options Considered

### Counter row plus `SELECT FOR UPDATE`

| Dimension | Assessment |
|---|---|
| Complexity | Medium |
| Transactional rollback | Yes |
| PostgreSQL / MySQL portability | High |
| SQLite test behavior | Sequential fallback |
| Operational characteristic | One short-lived hot row per date |

**Pros:** transactional, explicit daily scope, portable, observable, and compatible with a database uniqueness backstop.<br>
**Cons:** registrations for the same date serialize briefly on one row.

### Database sequence

| Dimension | Assessment |
|---|---|
| Complexity | Low on PostgreSQL, higher cross-engine |
| Transactional rollback | No |
| PostgreSQL / MySQL portability | Low |
| Daily reset | Custom operational mechanism required |

Rejected because PostgreSQL sequence values are not rolled back and daily portable reset semantics are poor.

### Advisory lock around `MAX + 1`

| Dimension | Assessment |
|---|---|
| Complexity | Medium |
| Transactional rollback | Engine-dependent |
| PostgreSQL / MySQL portability | Low |
| Connection-pool behavior | Easy to misuse |

Rejected as the allocator because lock APIs and lifecycles differ between engines. PostgreSQL advisory locks may be used only as a local rehearsal start barrier, never as the production allocation mechanism.

### Serializable `MAX + 1` with retry

| Dimension | Assessment |
|---|---|
| Complexity | High |
| Transactional rollback | Yes |
| PostgreSQL / MySQL portability | Semantics differ |
| Operational characteristic | Predicate conflicts and whole-flow retries |

Rejected because it expands contention and retry complexity across the entire registration transaction while retaining an aggregate-based allocator.

## Trade-off Analysis

The counter row introduces deliberate, short serialization for the one fact that must be serialized: the next number for a date. It avoids serializing unrelated clinical work and makes rollback behavior natural. The database unique constraint remains the final defense if a writer bypasses the service. This is preferable to accepting gaps, engine-specific locks, or broad serializable retries.

## Consequences

- Concurrent registration numbers are unique and monotonically committed within a date.
- The same queue number may appear on different dates, which is intentional.
- A failed transaction releases its uncommitted increment; the next successful registration can reuse that number.
- Audited synthetic resets preserve high-water marks and therefore do not reuse committed numbers.
- Existing synthetic queue numbers are rewritten once during migration. Rollback removes the new schema but cannot reconstruct prior invalid duplicate numbering; backup/restore is the data rollback boundary.
- Hosted migration requires maintenance/cutover planning because Vercel does not migrate automatically and index creation may lock the encounter table.
- PostgreSQL 17 concurrency evidence is required locally. Exact MySQL 8.4 concurrency evidence remains a separate portability gate before G3.

## Action Items

1. [x] Add the counter table, encounter queue date, deterministic synthetic remediation, and database uniqueness.
2. [x] Integrate one allocator into RJ, IGD, and RI registration transactions.
3. [x] Update teaching census and synthetic reset invariants.
4. [x] Add SQLite functional and rollback coverage.
5. [x] Run an independent-process PostgreSQL 17 contention and rollback-reuse rehearsal.
6. [ ] Run MySQL 8.4 evidence before formal G3 portability acceptance.
7. [ ] Obtain explicit milestone approval before commit, push, hosted migration, or deployment.
