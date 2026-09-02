# T1 Local Append-Only Cashier Collection Evidence — 2026-09-03

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Scope: `APPEND_ONLY_CASHIER_COLLECTION_BATCH_CLOSE_AND_DEPOSIT_HANDOFF_V1`
Application source aggregate SHA-256: `90e15b44c88469eadd4968087d1e44d84784276039c6f81d5f3fefce5884e6e0`

## Exact-engine records

| Engine | Result | Scenarios | Recorded at UTC | Immutable evidence record | Evidence SHA-256 |
|---|---:|---:|---|---|---|
| PostgreSQL 17.10 | PASS | 22/22 | `2026-09-02T19:01:19Z` | `storage/app/portability-rehearsals/20260902T190119Z-postgresql17-cashier-collection-25a08bba6ea3.json` | `2665e91ff3573a2fedd184daa96bdb3757ca63fe5c07c19a5c8d67809ac7becf` |
| MySQL 8.4.11 / InnoDB | PASS | 22/22 | `2026-09-02T19:03:31Z` | `storage/app/portability-rehearsals/20260902T190331Z-mysql8411-cashier-collection-22dbbe7ff187.json` | `4c3923cfda495b700316d407b149e33c8e74c41674b0bbc6eca21a88238e40d3` |

Both immutable JSON records are mode `0600`, contain the same closed 22-scenario inventory, and report strict removal of the disposable database, temporary server, generated runtime identity, and worker file.

## Identical source and catalogue bindings

| Binding | PostgreSQL | MySQL | Relation |
|---|---|---|---|
| `application_source_sha256` | `90e15b44c88469eadd4968087d1e44d84784276039c6f81d5f3fefce5884e6e0` | `90e15b44c88469eadd4968087d1e44d84784276039c6f81d5f3fefce5884e6e0` | IDENTICAL |
| `worker_source_sha256` | `43315f727c4ec59ef937b5bf4fd0cfdff2b3a329473e3aeb11d4f353e51fad9f` | `43315f727c4ec59ef937b5bf4fd0cfdff2b3a329473e3aeb11d4f353e51fad9f` | IDENTICAL |
| `scenario_catalog_sha256` | `392165504ae0959fba4b1d812cba32e58a5978ed6210c3485463582a7bfa3e63` | `392165504ae0959fba4b1d812cba32e58a5978ed6210c3485463582a7bfa3e63` | IDENTICAL |
| `runtime_grant_catalog_sha256` | `26002b75b3609bc9c5247bf24b71773c1118e5a30836b9d95547eaa6480f2376` | `26002b75b3609bc9c5247bf24b71773c1118e5a30836b9d95547eaa6480f2376` | IDENTICAL |
| `sqlite_gate_catalog_sha256` | `64bd0a9b5320d47039c21c9eac3d01b71088716dcf917300ebfe5fe35189a783` | `64bd0a9b5320d47039c21c9eac3d01b71088716dcf917300ebfe5fe35189a783` | IDENTICAL |
| `command_catalog_sha256` | `b7a6b888fa4a0e831a45bc650d0e0645f1c98750ccf7aee327fb1a80e50ced78` | `b7a6b888fa4a0e831a45bc650d0e0645f1c98750ccf7aee327fb1a80e50ced78` | IDENTICAL |

The bound file inventory is identical between the artifacts. Its current file hashes and recomputed aggregate are checked by `tests/Documentation/LocalAppendOnlyCashierCollectionEvidenceRecordTest.rb`.

## Independently recorded engine results

| Result binding | PostgreSQL | MySQL | Relation |
|---|---|---|---|
| `sqlite_gate.result_catalog_sha256` | `2a87ac50ae34e081c19e4d94bda014578bdbd1fe19421900095b821b07f41c24` | `33ee3a38d97caa98d28bb8cc1c9f191fd69133cb62b9fd994f6c17f807c4c845` | RECORDED INDEPENDENTLY |
| `source_bindings.result_catalog_sha256` | `e526a54302ad5204cf7c8c98d3aaae770b9c171dc98ff8a34abdc1168bfc15b8` | `7cd9069e8203c0cb01f63180115b09ef229ed4df230a3b1497720cf58844e1bb` | RECORDED INDEPENDENTLY |

These four values bind engine-specific application output, connection identities, timings, durable identifiers, and serialized branch details. Their difference is expected and does not weaken the identical source/catalogue binding.

## Exact 22-scenario result

Every scenario below is `PASS` in both immutable artifacts:

| # | Scenario | PostgreSQL | MySQL |
|---:|---|---:|---:|
| 1 | `fresh-migration` | PASS | PASS |
| 2 | `empty-down-reapply` | PASS | PASS |
| 3 | `migration-failure-guard-reinstall` | PASS | PASS |
| 4 | `failed-install-preserves-lifetime-uniqueness` | PASS | PASS |
| 5 | `shortened-identifier-inventory` | PASS | PASS |
| 6 | `exact-runtime-grants` | PASS | PASS |
| 7 | `exact-role-denials` | PASS | PASS |
| 8 | `runtime-reset-bypass-denial` | PASS | PASS |
| 9 | `owner-only-bounded-reset` | PASS | PASS |
| 10 | `database-check-constraints` | PASS | PASS |
| 11 | `database-append-only-refusals` | PASS | PASS |
| 12 | `real-settlement-v-close-wait` | PASS | PASS |
| 13 | `real-refund-completion-v-close-wait` | PASS | PASS |
| 14 | `concurrent-same-key-replay` | PASS | PASS |
| 15 | `double-close-refusal` | PASS | PASS |
| 16 | `double-verify-refusal` | PASS | PASS |
| 17 | `double-handoff-refusal` | PASS | PASS |
| 18 | `third-connection-membership-net-cash-readback` | PASS | PASS |
| 19 | `audit-failure-atomic-rollback` | PASS | PASS |
| 20 | `recovery-tamper-detection` | PASS | PASS |
| 21 | `retained-evidence-rollback-refusal` | PASS | PASS |
| 22 | `strict-cleanup` | PASS | PASS |

## Verified wait, equation, and terminal behavior

- Settlement-versus-close used two independent application processes, exposed a real native database wait, observed `finance_cashier_collection_active_slots` before `finance_cashier_collection_batches`, and reported no deadlock. Both engines retained `SETTLEMENT_COMMITTED` plus `STALE_COLLECTION_BATCH_REFUSED`; the deterministic retry then closed the batch.
- Refund-completion-versus-close used the same real-wait and lock-order proof. Both engines retained `REFUND_COMPLETED` plus `CLOSED`, then reconciled exactly one member and one close event with gross Rp9.000, completed refund Rp9.000, expected/countable net cash `0`, counted cash `0`, variance `0`, and a valid durable membership/event fingerprint.
- The same-key close race exposed a real wait and retained exactly one `CLOSED` result plus one `REPLAYED` result, with no deadlock.
- An independent third connection reconciled: settlement race `2` members / `1` event / Rp18.000 net; refund race `1` member / `1` event / Rp0 net after Rp9.000 refund; and same-key lifecycle `1` member / `2` events / Rp7.000 net. Healthy recovery mismatches were `0`.
- Double close, double verify, and double handoff were each refused without a duplicate durable result.

## Constraint, append-only, recovery, reset, and audit proof

- Fresh migration, empty rollback/reapply, forced failure, preservation of both predecessor lifetime-uniqueness barriers, and append-only guard reinstall all passed.
- Both engines inventoried `fccm_values_ck`, `fcce_values_ck`, `fcdh_values_ck`, and `fccor_result_ck`, plus the short trigger bases `fccb`, `fccm`, `fcce`, `fcdh`, and `fccor` within their native identifier limits.
- Native invalid inserts proved `fcce_values_ck` rejects non-zero verification semantics and `fccor_result_ck` rejects an invalid operation/result/null-event-FK shape. Each engine retained zero failed probe rows and a healthy recovery mismatch count of `0`; diagnostic evidence stores hashes and codes, not query text.
- Least-privilege runtime identities were denied delete, update, and truncate three times; user-settable reset-bypass escalation and runtime reset were also denied. Exact role-denial tests passed.
- Recovery detected member tampering, proved rollback restoration, and retained zero healthy mismatch counts. Audit-failure rollback preserved atomicity, and uniquely bound operation receipts/audits were included in reconciliation.
- Installer-owner reset was bounded, preserved audit evidence, retained exactly two reset audit events without decreasing the audit count, and removed the owned collection evidence in dependency order. Populated migration rollback was refused.

## Shared application gates

The final unchanged source aggregate also passed the shared application gates:

| Gate | Verified result |
|---|---|
| Full backend suite | PASS — 1,014 total, 1,010 passed, 4 intentional skips, 21,783 assertions, 45.100s |
| PHPStan configured `app` scope | PASS — 0 errors |
| Full Pint formatting | PASS — including ordered-import formatting |
| Frontend Vitest | PASS — 32 files, 211 tests, 39.24s |
| ESLint check | PASS |
| Prettier `resources` check | PASS |
| TypeScript check | PASS |
| Vite production build | PASS — 2,409 modules, 8.64s |

## Cleanup, secrecy, and operating boundary

Both records report strict cleanup of the disposable database, isolated local server, generated runtime identity state, and temporary worker. The evidence is sanitized: it contains no password, token, cookie, connection string, environment dump, credential, or raw SQL query text. Both runs used `APP_MODE=SIMULATION`, synthetic-only data, and disabled live integrations. No hosted Supabase/Vercel database, hosted migration, production alias, real patient record, or real payment was accessed.

## Open boundary

G0 and G3 remain **OPEN**. This record is local engineering portability evidence only. It is not product-owner, cashier/revenue, finance-accounting, treasury, or facility acceptance; it is not hosted UAT, hosted migration, deployment, production readiness, or SIMRS Sahabat parity completion.

It does not prove treasury receipt, bank reconciliation, accounting journal posting, revenue recognition, receivables, partial payment, split tender, card/bank settlement, post-handoff refund, claim processing, BPJS, VClaim, E-Klaim, SATUSEHAT, ERP, or use of real patient/payment data.

The READY / NOT RUN template remains separate at `docs/operations/T1_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_EVIDENCE_TEMPLATE_2026-09-03.md`.
