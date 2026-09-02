# Local routine inpatient discharge summary v1 evidence — 2026-08-31

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted Routine Inpatient Discharge Summary v1 slice. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, domain-owner acceptance, G0/G3 closure, production readiness, or SIMRS Sahabat parity acceptance.

The implementation remains within the established simulation boundary: synthetic records only, no secrets, and no live BPJS, VClaim, SATUSEHAT, E-Klaim, LIS, PACS, payment, pharmacy, device, or other external integration.

## Implemented workflow

The inpatient encounter now exposes an Indonesian **Ringkasan pulang** physician workflow:

1. an actor with the exact physician role and `clinical.inpatient.discharge-summary.write` capability creates the episode's one Draft;
2. that original author may append Draft revisions using the exact expected version and an actor-scoped idempotency key;
3. Finalization reuses the stored Draft and requires non-blank `Alasan Masuk`, `Temuan Penting`, `Ringkasan Perawatan dan Pengobatan`, `Kondisi Saat Pulang`, and `Rencana Tindak Lanjut`;
4. one terminal immutable Final and its ordered attributable version history remain readable; and
5. each version stores a server-derived coherent encounter, managed ward/bed, location-event, and location-sequence snapshot.

Draft and Final operations do **not** discharge or close the encounter, release the bed, create a coded disposition, change occupancy, post a charge, submit a claim, perform a pharmacy action, or establish RMIK acceptance. The later atomic discharge/bed-release workflow remains a separate dependency and may consume this Final document.

## Integrity, authorization, and concurrency controls

- Authorization runs before manual encounter or summary lookup. Role-only, capability-only, nurse, registrar, RMIK, administrator, and system-administrator paths do not gain write authority.
- One episode has at most one summary head. Only its original physician author may revise or Finalize it; there is no takeover, co-sign, reopening, amendment, or second Final in v1.
- Optimistic expected-version checks, actor/operation/key uniqueness, canonical payload digests, and receipt/result/encounter/version binding make exact retry replay deterministic and changed-payload or cross-encounter key reuse fail closed.
- The canonical current-bed mutex serializes the summary with transfer. MySQL uses current shared reads for immutable receipt and location evidence; PostgreSQL uses its fresh `READ COMMITTED` statement snapshot. Runtime identities retain only `SELECT, INSERT` on immutable evidence.
- A transfer race records one complete pre-transfer or post-transfer placement/location snapshot, never a hybrid. Both orderings use real application services without harness bed pre-locks.
- Real admission/transfer provenance stores event public ID, event type, sequence, and completeness. A retained pre-history episode stores an explicit sequence-0 `LEGACY_CURRENT_PLACEMENT` incomplete baseline without fabricating an event.
- Retained cancellation evidence, non-synthetic or non-inpatient episodes, terminal state, missing or incoherent placement, inactive master data, stale version, and unauthorized actor all fail closed.
- Version and receipt tables are append-only at model, SQL-listener, database-trigger, and database-privilege layers. The shared location SQL guard permits genuine locking reads while continuing to reject real writes, including a read-prefixed multi-statement write.
- Summary head/version/receipt, required success audit, and result commit atomically. Audit or receipt failure rolls back the whole success path. Audit metadata excludes all five clinical narratives and patient identifiers.
- Bounded reset removes the synthetic summary chain through the separate reset identity while retaining reset/audit evidence. Populated rollback refuses to discard retained summary, version, receipt, or correlated audit evidence.

## Exact-engine scenario catalogue

Both engines passed the same closed 16-scenario catalogue:

- migration and lifecycle: fresh migration, empty down/reapply, populated-evidence down refusal, bounded reset, and final invariant verification;
- normal and denial paths: Draft-to-Final, terminal Final refusal, exact replay, and changed-payload key conflict;
- atomicity and protection: audit-failure rollback, receipt-failure rollback, append-only engine refusal, and least-privilege runtime; and
- concurrent application behavior: same-summary competing Finalization plus summary-first and transfer-first snapshot races.

The competing-Final proof uses two independent application processes, observes a native database wait, commits one Final, denies the competitor, reports no deadlock, and verifies exactly one terminal Final with a two-version chain from a third connection.

Both transfer/summary orderings use two independent application processes and no harness bed pre-lock. Each engine observes a native wait, commits both operations, reports no deadlock, and verifies from a third connection that the summary captured the complete pre-transfer or post-transfer placement and real location-event provenance.

## Local quality gates

| Gate | Result |
| --- | --- |
| Full PHPUnit (`php artisan test`) | PASS — 681 tests; 677 passed; 4 skipped; 10,473 assertions |
| Pint | PASS |
| Full frontend unit suite | PASS — 18 files; 95 tests |
| TypeScript, ESLint, Prettier | PASS |
| Local engineering authorization contract | PASS — 9 tests; 192 assertions |
| Discharge-summary portability harness contract | PASS — 19 tests; 414 assertions |
| Full portability-suite source-binding contract | PASS — 12 tests; 152 assertions |
| Focused service, SQL-guard, documentation-contract, and transfer regression | PASS — 51 tests; 414 assertions |
| Independent bounded backend and evidence re-review | CLEAN — no residual P0–P2 findings |
| Working-tree diff check | PASS |

The four PHPUnit skips are retained conditional tests, not failures in this slice.

## Final matched exact-engine records

The final PostgreSQL and MySQL records share source aggregate SHA-256 `b31996f1ef0dd3090d0a19a2f4ac9c561997c88ebb396fdb6c95c571ddc3c613`, worker SHA-256 `21aa66e7951bc17430c20aa3ce8e74cb5ebdb900fb45d24331a997bb7510a7f4`, and scenario-catalogue SHA-256 `a865d58beb6e9e836f10d044e33c76d2d13522f5eaa4ba542e2e1f3a6aa6f788`. Both contain 16 PASS scenarios, no failed scenario, mode-`0600` evidence, and successful database/server/user/worker cleanup.

- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260831T044245Z-mysql8411-inpatient-discharge-summary-748e8fdf15a4.json`; 22 safe logical commands; 10 protocol results; artifact SHA-256 `02c5eaba3d66147d340db04ad9370404955d59d86cff1cf32c65bcf98cb24315`.
- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260831T044240Z-postgresql17-inpatient-discharge-summary-98c0e538574b.json`; 22 safe logical commands; 10 protocol results; artifact SHA-256 `6188481d715819f3540eb67f6673d9ff6c4ca55cb6cc16272dff0ac0452a1aa3`.

All earlier failed or partial discharge-summary rehearsal attempts are superseded and must not be used as evidence.

## Remaining boundary

This local checkpoint does not establish an encounter discharge transition, bed release, coded disposition, discharge timestamp, final-bill gate, medication reconciliation, RMIK completeness/coding/closure, claim preparation, reporting formula, hosted compatibility, browser UAT, backup/restore, load, capacity, SLA, owner acceptance, G0/G3 closure, parity, or production readiness. Any commit, push, deployment, or hosted Supabase `laravel` schema migration remains a separate release action and was intentionally not performed here.
