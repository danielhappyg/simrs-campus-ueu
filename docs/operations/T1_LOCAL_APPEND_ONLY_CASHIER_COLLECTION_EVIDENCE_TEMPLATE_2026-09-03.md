# T1 Local Append-Only Cashier Collection Evidence Template

Status: **READY / NOT RUN**
Scope: `APPEND_ONLY_CASHIER_COLLECTION_BATCH_CLOSE_AND_DEPOSIT_HANDOFF_V1` on disposable local PostgreSQL 17 and MySQL 8.4 only.

This template is not execution evidence. It may be completed only after both exact-engine rehearsals and the full shared gates pass against one unchanged source aggregate. Each rehearsal must write a new mode-`0600` JSON artifact under `storage/app/portability-rehearsals/`; an artifact is immutable after its SHA-256 is recorded here.

## Immutable paired artifacts

| Engine | Exact version readback | Immutable local artifact path | Artifact SHA-256 | Recorded at UTC | Result |
|---|---|---|---|---|---|
| PostgreSQL | `17.10` | `NOT RUN` | `NOT RUN` | `NOT RUN` | `NOT RUN` |
| MySQL | `8.4.11` | `NOT RUN` | `NOT RUN` | `NOT RUN` | `NOT RUN` |

The two paths must be distinct. Verify each SHA-256 from disk after strict cleanup. Never edit, replace, or reuse an artifact after recording its hash.

## Required identical source and catalogue bindings

Record the value from each artifact. Every row must match exactly between engines before this record can say `PASS`.

| Binding | PostgreSQL artifact | MySQL artifact | Identical |
|---|---|---|---|
| `application_source_sha256` / source aggregate | `NOT RUN` | `NOT RUN` | `NOT RUN` |
| `worker_source_sha256` | `NOT RUN` | `NOT RUN` | `NOT RUN` |
| `scenario_catalog_sha256` | `NOT RUN` | `NOT RUN` | `NOT RUN` |
| `runtime_grant_catalog_sha256` | `NOT RUN` | `NOT RUN` | `NOT RUN` |
| `sqlite_gate_catalog_sha256` | `NOT RUN` | `NOT RUN` | `NOT RUN` |
| `command_catalog_sha256` | `NOT RUN` | `NOT RUN` | `NOT RUN` |

The application source aggregate must bind the harness, authorization, this template and its documentation contracts, migrations, domain services, projections, guards, models, recovery/reset code, and focused tests declared in `SOURCE_PATHS`. Any source change after the first engine run invalidates that artifact pair and requires both engines to be rerun.

## Required engine-specific outcome bindings

These hashes must be recorded and verified from each artifact, but they must not be equal between engines. They bind engine-specific backend identities, generated durable public IDs, timings, serialized branch details, and exact test output.

| Binding | PostgreSQL artifact | MySQL artifact | Required relation |
|---|---|---|---|
| `sqlite_gate.result_catalog_sha256` | `NOT RUN` | `NOT RUN` | `RECORDED INDEPENDENTLY` |
| `source_bindings.result_catalog_sha256` | `NOT RUN` | `NOT RUN` | `RECORDED INDEPENDENTLY` |

An engine-specific result hash difference is expected and is not a source/catalogue mismatch. The closed scenario inventory, statuses, proof kinds, required invariant fields, and shared command catalogue must still satisfy the paired contract exactly.

## Exact 22-scenario paired summary

Each engine artifact must contain this identical closed inventory, in this order, with `PASS` and non-empty proof metadata for every row.

| # | Scenario | PostgreSQL | MySQL |
|---:|---|---|---|
| 1 | `fresh-migration` | `NOT RUN` | `NOT RUN` |
| 2 | `empty-down-reapply` | `NOT RUN` | `NOT RUN` |
| 3 | `migration-failure-guard-reinstall` | `NOT RUN` | `NOT RUN` |
| 4 | `failed-install-preserves-lifetime-uniqueness` | `NOT RUN` | `NOT RUN` |
| 5 | `shortened-identifier-inventory` | `NOT RUN` | `NOT RUN` |
| 6 | `exact-runtime-grants` | `NOT RUN` | `NOT RUN` |
| 7 | `exact-role-denials` | `NOT RUN` | `NOT RUN` |
| 8 | `runtime-reset-bypass-denial` | `NOT RUN` | `NOT RUN` |
| 9 | `owner-only-bounded-reset` | `NOT RUN` | `NOT RUN` |
| 10 | `database-check-constraints` | `NOT RUN` | `NOT RUN` |
| 11 | `database-append-only-refusals` | `NOT RUN` | `NOT RUN` |
| 12 | `real-settlement-v-close-wait` | `NOT RUN` | `NOT RUN` |
| 13 | `real-refund-completion-v-close-wait` | `NOT RUN` | `NOT RUN` |
| 14 | `concurrent-same-key-replay` | `NOT RUN` | `NOT RUN` |
| 15 | `double-close-refusal` | `NOT RUN` | `NOT RUN` |
| 16 | `double-verify-refusal` | `NOT RUN` | `NOT RUN` |
| 17 | `double-handoff-refusal` | `NOT RUN` | `NOT RUN` |
| 18 | `third-connection-membership-net-cash-readback` | `NOT RUN` | `NOT RUN` |
| 19 | `audit-failure-atomic-rollback` | `NOT RUN` | `NOT RUN` |
| 20 | `recovery-tamper-detection` | `NOT RUN` | `NOT RUN` |
| 21 | `retained-evidence-rollback-refusal` | `NOT RUN` | `NOT RUN` |
| 22 | `strict-cleanup` | `NOT RUN` | `NOT RUN` |

The native-wait rows must show two independent application processes, backend connection identities, the active-slot-before-batch lock trace, observed database waiting, and the final durable outcome. Sequential simulation is not acceptable. The third-connection row must independently reconcile exact membership, gross cash, completed refunds, net cash, event chain, receipts, and audit bindings.

For the refund-completion-versus-close wait, `CLOSED`, stale-fingerprint refusal, and batch-not-open refusal are permitted serialized contender outcomes. The contender must submit counted cash `0`. A deterministic final-state worker must then close the batch if it remains open or reconcile its retained close event if already frozen. Both branches must prove exactly one member and one close event, gross cash `9000`, completed refund `9000`, expected net cash `0`, counted cash `0`, variance `0`, and valid durable membership/event fingerprints.

The database-check-constraints scenario must not rely on catalogue presence alone. After valid owner-side fixture preparation, the runtime worker must attempt a `CLOSE_VERIFIED` event with non-zero variance and prove native rejection specifically by `fcce_values_ck`. It must also attempt an `OPEN` / `EVENT` / null-event-FK operation-receipt shape and prove native rejection specifically by `fccor_result_ck`. Record only constraint name, SQLSTATE, driver code, and a diagnostic fingerprint—never SQL text or credentials. Both failed inserts must roll back, retain zero probe rows, and leave the healthy collection recovery mismatch count at zero.

## Shared application gates

Run these gates after both engine artifacts exist and only while their shared application-source aggregate is still current.

| Gate | Required recorded result |
|---|---|
| Full backend suite | total tests, passed tests, intentional skips, assertions, duration, and `PASS` |
| Focused authorization/documentation contracts | test and assertion counts, and `PASS` |
| PHPStan | configured scope, error count `0`, and `PASS` |
| Pint / formatting | checked scope and `PASS` |
| Diff check | checked paths and `PASS` |
| Frontend tests | test-file count, test count, and `PASS` |
| Frontend lint | `PASS` |
| Frontend format check | `PASS` |
| Frontend type check | `PASS` |
| Frontend production build | `PASS` |

Record the final gate command catalogue hash and result catalogue hash. A prior green run against a different source aggregate is not acceptable evidence.

## Cleanup, secrecy, and integration verification

Before recording either artifact as `PASS`, verify and record all of the following:

- the disposable database/cluster, generated runtime role, temporary server state, temporary worker, and temporary credentials were removed;
- strict cleanup completed after both success and failure paths, with no retained process or generated database matching the rehearsal patterns;
- the artifact contains no password, token, connection string, cookie, secret, raw SQL query text, or environment dump;
- `APP_MODE=SIMULATION` and synthetic-only data were used;
- BPJS, VClaim, E-Klaim, SATUSEHAT, LIS, PACS, banking, treasury, and other live integrations remained disabled;
- no hosted Supabase/Vercel database, hosted migration, production alias, real patient record, or real payment was accessed;
- runtime reset-bypass was refused, owner reset was bounded, populated rollback was refused, and retained audit evidence remained reconcilable.

Any incomplete cleanup, mismatched hash, unexpected engine version, secret exposure, live integration, hosted configuration, or missing proof makes the pair `BLOCKED`, not partially passing.

## Honest boundary

This record is local engineering portability evidence only. It is **not** G0 closure, G3 closure, product-owner acceptance, cashier/revenue domain acceptance, finance-accounting acceptance, treasury acceptance or receipt, facility acceptance, UAT, hosted migration, deployment, production readiness, or SIMRS Sahabat parity completion.

It does not prove bank reconciliation, treasury receipt, accounting journal posting, revenue recognition, receivables, partial payment, split tender, card/bank settlement, post-handoff refund, claim processing, BPJS/VClaim/E-Klaim/SATUSEHAT integration, or use of real patient/payment data.

Final paired status: **READY / NOT RUN**
