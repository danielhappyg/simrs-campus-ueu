# T1 Local MySQL 8.4 Integration Evidence — 2026-08-26

## Evidence boundary

**Classification: `LOCAL / NOT_DEPLOYED`.** This record covers the current unpublished T1 worktree on base commit `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`. It used synthetic teaching data only. It does not record a commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, owner acceptance, or production release.

The disposable database ran on loopback `127.0.0.1:3307`. No real patient data or live BPJS, VClaim, SATUSEHAT, or other external integration was used. The temporary database, user, server process, and generated database directories were removed after verification; no credential value is retained in this artifact.

## Exact engine and runtime

| Item | Observed value |
| --- | --- |
| MySQL server | MySQL 8.4.11, Homebrew formula `mysql@8.4` 8.4.11_3 |
| Server binary | `/opt/homebrew/Cellar/mysql@8.4/8.4.11_3/bin/mysqld` |
| Server binary SHA-256 | `ce949b8eb14d8821b4fe037087f5aaf3d7b63765d4eb4bcc2ca0a5f256a1a903` |
| SQL modes | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` |
| PHP runtime | PHP 8.5.7 with `pdo_mysql` |
| CI-equivalence boundary | The project CI target is PHP 8.3; this local run is therefore exact MySQL 8.4 engine evidence, not exact CI-runtime equivalence. |

The migration user was restricted rather than administrative. Initial migration correctly exposed MySQL binary-log trigger policy error `1419`. The disposable server was then configured with `log_bin_trust_function_creators=1`; migrations passed without broadening the application user's privileges. A hosted environment using binary logging must make an equivalent reviewed operational decision before migration.

## Verification results

| Verification | Result |
| --- | --- |
| `php artisan migrate:fresh --force` | PASS; all migrations applied through `2026_08_26_000200_create_daily_queue_allocator` |
| Full application suite on MySQL 8.4 | 431 tests; 424 passed, 7 skipped; 5,499 assertions; PASS in 63,520 ms |
| Focused administration/audit slice | 26 tests; 26 passed; 126 assertions; PASS in 2,941 ms |
| Rollback last two T1 migrations | PASS |
| Post-rollback schema probe | `0 0 0 0`: allocator table, `queue_date`, encounter indexes, and laboratory worklist index absent |
| Reapply last two T1 migrations | PASS |
| Post-reapply schema probe | `1 1 2 1`: allocator table present, `queue_date` present and non-null, two encounter indexes present, one laboratory index present |
| Disposable generated database directories remaining | 0 |

The focused administration/audit slice covered:

- `tests/Feature/Authorization/ReconcileRebuildAdminCommandTest.php`
- `tests/Feature/Audit/AuditRecorderSafetyTest.php`

The two migration byte bindings were:

- `database/migrations/2026_08_26_000100_add_operational_worklist_indexes.php` — SHA-256 `e9077592ac53dd9ef704ac376e7bc7c45ecddc43040273597d7a5dcadb23f954`
- `database/migrations/2026_08_26_000200_create_daily_queue_allocator.php` — SHA-256 `5eca2d46ea0fba89cf4bfe26afb83a096e47aa249a08e89580937b508f0ac3e0`

## Claims this evidence does not make

- It does not prove PHP 8.3/MySQL 8.4 CI equivalence.
- It does not prove hosted Supabase, Vercel, production, or owner-role behavior.
- It does not prove MySQL recovery, high-concurrency allocation, capacity, performance, SLA, or disaster recovery.
- It does not convert a partial workflow into a complete workflow.
- It does not close G0, G3, owner acceptance, security review, or institutional authority gaps.
- It does not authorize commit, push, PR, GitHub Actions, deployment, or hosted migration.

This record may support `mysql_8_4: PASS` only for the explicitly mapped partial capabilities and workflows exercised by the full and focused test suites. All other MySQL 8.4 entries remain `NOT_RUN`.
