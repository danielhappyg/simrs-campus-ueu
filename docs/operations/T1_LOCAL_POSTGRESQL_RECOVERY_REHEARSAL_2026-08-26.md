# T1 local PostgreSQL recovery rehearsal evidence

- **Date:** 26 August 2026
- **Environment:** local PostgreSQL 17.10 on macOS arm64
- **Application boundary:** `SIMULATION`, synthetic-only, private `laravel` schema
- **Release boundary:** local uncommitted milestone based on `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`
- **Result:** PASS — local disposable recovery evidence only

## What this proves

The guarded local rehearsal created two attempt-owned databases under the closed `simrs_recovery_<12 hex>_(source|restore)` namespace. It migrated and seeded the source with the bounded synthetic teaching census, added one validated authorization-denial audit sentinel through `AuditRecorder`, created a PostgreSQL custom-format logical backup, restored it into the empty target in a single transaction, and verified the restored application through the read-only `ops:verify-synthetic-recovery` command. Generated ULIDs and password hashes may differ between attempts; the proof is exact source/restore equality within one attempt, not an identical fingerprint across separate rehearsals.

The source and restore, including the atomic daily queue-counter schema and populated encounter queue dates, matched across four independent comparisons:

1. the value-minimized application snapshot;
2. the frozen `BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql` output;
3. a fixed-restrict-key schema-only logical dump; and
4. an ordered column-insert data-only logical dump.

The harness verified PostgreSQL major version 17, the private `laravel` schema, the exact checkout migration ledger and migration-file hashes, zero non-synthetic patients, one recovery audit sentinel, zero checked relationship orphans, and successful Laravel migration-state reads from the restored database.

## Recorded measurements and integrity evidence

| Check | Result |
| --- | --- |
| Application snapshot SHA-256 | `07d5b6c21eb08fdf747528b53b85197d617fa862b4f649bf421e2f54ee173990` |
| Migration file-set SHA-256 | `2c03ce089b99ec8a685aaa971b4b4673dbe530cbcfb2d0003fa46a844ff79f9f` |
| Frozen acceptance SQL SHA-256 | `832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba` |
| Frozen acceptance output SHA-256 | `111286ea98fadf0066711f28d5bcc8e4e4c0ea00db9667f58ee7d514d83bd541` |
| Custom backup SHA-256 | `b881e743a44a0480eb607dc9c0a467abaca4046bc11c7bf82a87fca50f10d2c6` |
| Custom backup size | 166,649 bytes |
| Schema dump SHA-256 | `edb6763340ab1931842a196c66ff90098faa5eb1727deb0a7374b935ff280846` |
| Data dump SHA-256 | `e09a4c022dfcb3f0c8f04b36c4b7aaab9d69eac9304c53ae5c126f505ef4a7b0` |
| Backup elapsed time | 113 ms |
| Restore plus application verification | 752 ms |
| Quiesced-fixture row loss | 0 |
| Generated recovery databases remaining | 0 |

The complete machine-readable evidence remains local and ignored under `storage/app/recovery-rehearsals/20260826T143840Z-6f2d1cc12247.json`. It contains hashes, counts, timings, and claim boundaries, but no password, DSN, patient/account value, or ephemeral database name.

## Safety controls exercised

- explicit `SIMRS_RECOVERY_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_POSTGRES17` opt-in before any command;
- loopback or approved local-socket host only;
- PostgreSQL 17 only;
- generated names only, with pre-existing targets refused;
- no caller-selected repository root, database name, or evidence path;
- no `migrate:fresh`, `pg_restore --clean`, or global simulation reset;
- restore uses `--exit-on-error --single-transaction --no-owner --no-privileges`;
- errors redact DSNs, secrets, and generated database names;
- inherited process variables are cleared except for a small operating-system allowlist, preventing libpq variables such as `PGHOSTADDR`, `PGSERVICE`, or `PGOPTIONS` from overriding the reviewed connection;
- the frozen acceptance SQL must match its reviewed SHA-256 before any database is created;
- direct recovery-sentinel seeding requires the same explicit confirmation and verifies the connected server address is loopback or a local socket;
- cleanup considers only databases created by the current attempt and matching the closed name pattern, verifies each database is absent, and writes no PASS evidence when cleanup cannot be proven; and
- temporary dumps and local machine evidence use restrictive filesystem permissions.

The focused contract suites passed:

- PHPUnit: 4 tests, 16 assertions;
- Ruby harness contract: 10 tests, 69 assertions; and
- Pint check: PASS.

## Honest remaining boundary

This does not establish an approved RPO or RTO. Zero row loss came from a quiesced synthetic fixture, and the 752 ms measurement came from same-host local recovery. The run does not prove Supabase backup retention, encryption/access separation, cross-failure-domain recovery, object/file restoration, hosted application reconfiguration, dependency outage recovery, corrupted queue recovery, Vercel application rollback, or institutional disaster-recovery readiness.

Those items remain open until an approved target, workload, retention policy, recovery objectives, operator, and hosted rehearsal are available. No GitHub workflow, Vercel deployment, Supabase migration, or remote database was used for this evidence.
