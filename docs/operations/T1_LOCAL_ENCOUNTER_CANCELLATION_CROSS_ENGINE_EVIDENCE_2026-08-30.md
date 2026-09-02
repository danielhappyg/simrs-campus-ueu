# Local encounter-cancellation cross-engine evidence — 2026-08-30

## Evidence boundary

**Classification: `LOCAL / NOT_DEPLOYED`.** This record covers the current uncommitted synthetic-only encounter-cancellation slice. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, owner acceptance, production-readiness claim, or external-integration behavior.

The retained T1 milestone manifest is intentionally not refreshed or rewritten. Its checker currently refuses the live checkout because that historical manifest is pinned to a different HEAD. The dedicated harness therefore binds its result directly to the live backend source set, migration set, harness bytes, foundation-harness bytes, and exact focused-test catalogue before engine creation and again after testing. Any source drift prevents PASS evidence.

## Harness and isolation

`scripts/rehearse-local-encounter-cancellation-portability.rb` reuses the trusted engine lifecycle from `scripts/rehearse-local-portability-full-suite.rb` while omitting the stale milestone-manifest binding. It:

- accepts only exact local PostgreSQL `17.10` or MySQL `8.4.11`;
- refuses `DB_URL`, PostgreSQL endpoint overrides, and executable-path overrides;
- creates an isolated PostgreSQL cluster on a private Unix socket or an isolated MySQL server on loopback;
- generates only disposable database/user state and a random in-memory application key;
- forces `APP_MODE=SIMULATION`, synthetic-only operation, disabled break-glass, and empty external-service credentials;
- runs `migrate:fresh` followed by the closed cancellation test catalogue;
- records aggregate results only in mode-`0600` JSON; and
- stops the server and removes its generated database, user state, and temporary directory before emitting PASS.

The successful runs required host-level process/shared-memory access because the normal workspace sandbox rejects PostgreSQL shared-memory initialization. No hosted service or caller-supplied database credential was used.

## Exact shared execution binding

The final PostgreSQL and MySQL records match on every shared binding:

| Binding | Exact value |
| --- | --- |
| Backend execution source set | 338 files; SHA-256 `f6420e02d38c195f617c97265251f89974de8b9c53d52c278b79758e46000cb6` |
| Migration set | 24 files; SHA-256 `f9da8f5fbe7ebd8aa84451e2ae2fb15bbca6371760905693440b78fc271f7dfa` |
| Dedicated harness | SHA-256 `38238a0fe6de27144f35964509e061a53d1aacbba4abaaa7b91261266222050b` |
| Trusted foundation harness | SHA-256 `14a390270fbd7a156058deb397406fff823da7db7fb7bb28602b4f55aa2b98d8` |
| Focused test catalogue | SHA-256 `d736f7807d077d97673f4df0e626074305729a081ad275da09beff0bdf9648bf` |

## Exact-engine results

| Engine | Fresh migration | Focused cancellation suite | Result |
| --- | --- | --- | --- |
| PostgreSQL 17.10, harness-owned private cluster, Unix socket, `laravel` schema | PASS | 70 passed; 845 assertions | PASS |
| MySQL 8.4.11, harness-owned loopback server, InnoDB | PASS | 69 passed, 1 skipped; 843 assertions | PASS |

The MySQL skip is the existing PostgreSQL-only statement-timeout assertion in `OutpatientPrintAndRecapTest`; it is not a cancellation or MySQL failure.

The closed focused catalogue contains:

- cancellation command, authorization, idempotency/race reconciliation, denial, atomic audit, migration-model, bed-release and print behavior;
- active/historical registration, worklist, dashboard and cancellation-history projections;
- encounter-first clinical-entry locking and cancelled-write denial audit;
- outpatient lab/document/RM direct-write guards;
- print and recap/CSV behavior; and
- synthetic reset/cascade with retained audit/security evidence.

## Retained sanitized evidence

- MySQL: `storage/app/portability-rehearsals/20260829T215613Z-mysql8411-encounter-cancellation-98a5315283f1.json`; mode `0600`; SHA-256 `33d8868c9126fb944e28c4591acae2dfac0012833c1f233ed4a938c509088fe4`.
- PostgreSQL: `storage/app/portability-rehearsals/20260829T215634Z-postgresql17-encounter-cancellation-62250ee69748.json`; mode `0600`; SHA-256 `7b67cdb0d5c691a8463c2513080e42e7e1b2ee7708ce06346b3199349bafce2a`.

The records contain no database name, database user, password, DSN, port, socket path, process identifier, raw test output, patient/account value, or external target. Post-run inspection found no `sp17-*` or `sp84-*` disposable database directory.

## Fail-closed observations and remaining boundary

1. The full milestone harness correctly stopped before engine creation because the historical local manifest is stale; this dedicated record does not weaken that gate.
2. A first MySQL attempt encountered an undefined test variable while another worker was still finishing the unique-race test. It emitted no PASS evidence, cleaned its disposable server, and was rerun only after the current source stabilized.
3. The final pair proves migration and focused cancellation behavior on both exact engines. It does **not** prove an independent-process cancellation-versus-first-clinical-write contention race, hosted pooling/proxy behavior, backup/restore, load, SLA, browser UAT, domain acceptance, Sahabat parity, G0/G3 closure, or production readiness.
