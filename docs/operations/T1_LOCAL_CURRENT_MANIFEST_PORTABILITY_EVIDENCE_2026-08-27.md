# T1 current-manifest PostgreSQL 17 and MySQL 8.4 portability evidence — 2026-08-27

## Evidence boundary

**Classification: `LOCAL / NOT_DEPLOYED`.** This evidence covers the unpublished backend bytes bound below on base `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`. It used generated synthetic data and disposable local databases only. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, owner acceptance, production readiness, or external-integration behavior.

The reusable harness forces `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, break-glass `off`, global break-glass disabled, empty production-service credentials, fresh migrations, aggregate-only output, and cleanup in `ensure`. It accepts exactly one engine per invocation and refuses any `DB_URL`, PostgreSQL connection override, or executable-path override. PostgreSQL runs in a harness-created exact-17.10 cluster that rejects host authentication and listens only on a private mode-`0700` Unix-socket directory. MySQL runs in a harness-created exact-8.4.11 server bound to loopback. Trusted absolute binaries and a fixed subprocess `PATH` are used.

The harness computes the closed execution and pre-run manifest bindings before engine creation and recomputes them after all full/focused tests. Any drift suppresses PASS evidence. The milestone manifest separately parses both retained records and refuses ordinary `--write`/`--check` unless their shared execution bindings equal the live harness-defined binding. Its bootstrap-only mode is explicitly `UNVERIFIED_FOR_BOOTSTRAP` and cannot emit a portability PASS claim.

## Exact shared execution binding

The final PostgreSQL and MySQL PASS records match on every shared binding:

| Binding | Exact value |
| --- | --- |
| Pre-run local manifest SHA-256 | `1e3020773730e3cc031861a2bb1ebe16e699bc7538a8439ebded223b7d796402` |
| Manifest candidate count | 137 |
| Backend execution source set | 266 files; SHA-256 `74b18856f1e846e759f86f0f071c35ff85009ef1889acbfee6d9ccb87d49dd1b` |
| Migration set | 21 files; SHA-256 `f0e21175b1428c3744227376e2b81174df225b8a42995f3ff297d2f19fe70603` |
| Harness | SHA-256 `d3b0945d2daec9f442a0784b0bdb33581cc4cc2dcc51f37597599de0ff195680` |
| Workflow test catalogue | SHA-256 `68067bb43af8ac372cb9311f1b951b0e1f9c1e833342289783bd6d2e16abe88c` |
| PHP runtime | 8.5.7; not PHP 8.3 CI-runtime equivalence |

The closed backend source set covers `artisan`, application/configuration/bootstrap files, factories, migrations, seeders, routes, all PHP tests, Composer locks, PHPUnit configuration, and the harness. Evidence documentation and generated ledgers are intentionally outside this execution digest so that documenting a completed run cannot circularly invalidate the tested backend bytes.

## Exact-engine results

| Engine | Fresh migration | Complete PHP suite | Result |
| --- | --- | --- | --- |
| PostgreSQL 17.10 / harness-owned private cluster, Unix socket, and `laravel` schema | PASS | 451 tests; 450 passed, 1 skipped; 5,724 assertions | PASS |
| MySQL 8.4.11 / InnoDB | PASS | 451 tests; 444 passed, 7 skipped; 5,695 assertions | PASS |

The skip counts are engine-specific test-boundary skips, not failed tests. Both complete-suite commands exited successfully.

## Focused workflow results on both engines

Every mapped slice passed with the same test and assertion counts on PostgreSQL 17 and MySQL 8.4:

| Workflow | Current bounded scope | Tests | Assertions |
| --- | --- | ---: | ---: |
| E2E-01 | RJ/IGD/RI registration and transactional daily queue | 36 | 243 |
| E2E-02 | Current IGD scaffold and locked clinical entry | 11 | 77 |
| E2E-03 | Continuous outpatient teaching journey | 1 | 154 |
| E2E-04 | Continuous inpatient scaffold journey | 1 | 123 |
| E2E-05 | Current outpatient laboratory lifecycle | 12 | 105 |
| E2E-12 | Structured outpatient documentation and RM completeness | 12 | 214 |
| E2E-15 | Current outpatient print and recap projections | 13 | 108 |
| E2E-16 | RBAC, audit, correlation, rebuild-admin, and BG-03 comparison | 79 | 321 |

This supports exact-engine `PASS` only for the currently mapped partial capabilities and workflows exercised by these slices. It does not make any workflow complete.

## Retained sanitized evidence

- PostgreSQL: `storage/app/portability-rehearsals/20260826T212541Z-postgresql17-87c90954250d.json`; mode `0600`; SHA-256 `d4adba383775e5a977ca506ace7bb661eb4df6e929a1e2cc53ca9492d0d0f8e2`.
- MySQL: `storage/app/portability-rehearsals/20260826T212710Z-mysql8411-7d45f8dbe886.json`; mode `0600`; SHA-256 `fcca784137d4c88bf36ff22e0684775b30f82cf03965edb1c6ae75b786cfc274`.

The ignored JSON records contain aggregate counts, versions, boolean boundaries, durations, and SHA-256 bindings only. They do not contain database names, database users, passwords, DSNs, ports, sockets, process identifiers, raw test output, patient/account values, or external targets. Final cleanup found zero generated PostgreSQL portability databases and zero generated MySQL temporary server directories.

## Independent final review

- Defensive-security review: **GO**. All earlier binding, local-endpoint, executable-path, cleanup-observability, and evidence-directory findings are closed for this harness. Residual limits remain local-only evidence, same-user workspace trust, and abrupt power-loss cleanup risk.
- Release-gate integrity review: **GO with no P0–P3 findings** for this local exact-engine milestone. The review independently matched both retained records to the live execution bindings, confirmed ordinary semantic manifest and ledger checks, and retained every formal/hosted/owner boundary as open.

Neither verdict authorizes publication, deployment, hosted migration/UAT, production use, or owner acceptance.

## Fail-closed findings during rehearsal

1. The first PostgreSQL run exposed a test that assumed its normal test database was always SQLite. The test now asserts the correct fail-closed boundary for both PostgreSQL and non-PostgreSQL engines without weakening the recovery guard.
2. The harness initially expected the compact local JSON test formatter. Exact-engine subprocesses emitted the standard Laravel/Pest summary, so the parser was extended and adversarially tested for both formats.
3. MySQL initialization crashed under the filesystem sandbox with a mapped-memory permission error. The identical closed harness was rerun outside that sandbox; the isolated MySQL server then initialized, passed, stopped, and removed its generated data directory. No failed attempt emitted PASS evidence.
4. A publication-time audit detected that three Ruby contract-test files changed after the first retained engine pair, invalidating its source digest even though the PHP product suite remained green. The stale pair was rejected, the harness gained identical pre/post execution and manifest binding checks, and the manifest gained a semantic inner-record gate. The fresh pair above is the replacement evidence.
5. A defensive-security review found that accepting an arbitrary loopback PostgreSQL endpoint could traverse a local tunnel. PostgreSQL now always runs in a harness-owned exact-version cluster over a private Unix socket; caller PostgreSQL connection variables are rejected. The same review led to fixed/trusted executable resolution, a fixed subprocess path, strict evidence-directory validation, PID cleanup hardening, and visible exceptional-cleanup warnings.

## Claims this evidence does not make

- No PostgreSQL/MySQL independent-process clinical-entry race was executed; the writer's exact row-lock contention behavior remains a later concurrency gate.
- No MySQL independent-process daily-queue contention or rollback-reuse rehearsal was executed; the existing PostgreSQL contention evidence is not a substitute.
- It does not prove hosted connection pooling, latency, load, capacity, SLA, proxy behavior, backup/restore, rollback, or failure-domain recovery.
- It does not prove PHP 8.3 CI equivalence, browser behavior, accessibility, or role-based hosted UAT.
- It does not approve cancellation, amendment, triage scale, ED disposition, transfer, discharge, medication, inventory, billing, claims, reporting formulas, or live integrations.
- It does not close G0/G3, reconciliation, defect disposition, or owner acceptance.
- It does not authorize commit, push, GitHub Actions, deployment, hosted migration, or production use.
