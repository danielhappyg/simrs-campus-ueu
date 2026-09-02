# T1 Local Cross-Setting Finance Billing Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Executed: 2026-09-01 Asia/Jakarta
Scope: PostgreSQL 17.10 and MySQL 8.4.11 in isolated local databases

Deployment, hosted readiness, cashier/finance-owner acceptance, parity acceptance, payment readiness, and production claims remain **false**. This record does not authorize a commit, push, hosted migration, or deployment.

## Bound implementation and verification

- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md`
- Harness: `scripts/rehearse-local-finance-billing-portability.rb`
- Harness contract: `tests/Documentation/LocalFinanceBillingPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_01_000800_create_cross_setting_financial_spine_tables.php`
- Focused backend references: `tests/Feature/Finance/FinanceBillCoreTest.php`, `tests/Feature/Finance/BillingHttpWorkflowTest.php`, and `tests/Feature/Operations/FinanceRecoverySnapshotTest.php`
- Shared source-binding aggregate: `21ce1c6104516cfeab801e5a8a88af387fa6082a524459a98fa7636fabe47671`
- Embedded worker binding: `1bffb2d9df3fad728c0587247e21d7cb6b74a75f9d6f139bcff8739e16372e07`
- Scenario catalogue binding: `4c8e0dfd7a1df034f81a0f68087a4582b5bdb76b33b700be9a2821e5dec31bd8`
- Runtime-grant catalogue binding: `c9e11b9c6c0c657a13b274adce31a8a558bb0adff0985a04e6e501eb7b6c045d`

## Exact-engine execution register

| Engine | Result | Scenarios | Evidence | SHA-256 | Mode | Strict cleanup |
|---|---|---:|---|---|---|---|
| PostgreSQL 17.10 (`server_version_num=170010`) | PASS | 20/20 | `storage/app/portability-rehearsals/20260901T122938Z-postgresql17-finance-billing-856a3900c141.json` | `b0ade4deb8d0f48a1166dd45eb6f200a825ff5f9f587431b3fca5dcd6d69381b` | `0600` | PASS |
| MySQL 8.4.11 (InnoDB) | PASS | 20/20 | `storage/app/portability-rehearsals/20260901T123023Z-mysql8411-finance-billing-9b0826c4a043.json` | `e0be703977f74ca48a7bf4c1fcef7e886e26ea8f67658cf166ea7332acaf25f9` | `0600` | PASS |

Both evidence files report kind `SIMRS_LOCAL_CROSS_SETTING_FINANCE_BILLING_PORTABILITY`, claim `LOCAL_DISPOSABLE_CROSS_SETTING_FINANCE_BILLING_ONLY`, status `PASS`, the same source, worker, scenario, runtime-grant, and command-catalogue bindings, 20 commands, and 10 protocol results. Their engine-specific result-catalogue digests differ only where engine behavior differs. All four cleanup flags are true: disposable database removed, temporary server removed, temporary user state removed, and temporary worker removed.

## Scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration | PASS | PASS |
| Empty down/reapply | PASS | PASS |
| Exact cashier role boundary; authorization before lookup | PASS | PASS |
| RJ, IGD, and RI versioned encounter billing | PASS | PASS |
| Read-only initial synchronization-candidate discovery | PASS | PASS |
| Real pharmacy CHARGE and REVERSAL source reconciliation | PASS | PASS |
| Initial source synchronization and version-one issue | PASS | PASS |
| Later-source synchronization and version-two issue | PASS | PASS |
| Immutable version-one history and previous-version chain | PASS | PASS |
| Exact idempotent replay | PASS | PASS |
| Changed-payload same-key conflict | PASS | PASS |
| Stale fingerprint refusal | PASS | PASS |
| Application SQL write-guard refusal | PASS | PASS |
| Database mutable-head refusal outside governed scope | PASS | PASS |
| Database append-only update, delete, and truncate refusal | PASS | PASS |
| Evidence-chain corruption refusal with restored source fixture | PASS | PASS |
| Least-privilege runtime grants | PASS | PASS |
| Bounded reset, empty finance readback, and audit preservation | PASS | PASS |
| Retained-evidence rollback refusal | PASS | PASS |
| Durable source, line, version, receipt, head, total, and orphan invariants | PASS | PASS |

## Regression readback

- Full PHP gate: 838 tests executed; 834 passed; 4 intentional skips; 16,792 assertions.
- Frontend gate: 25 files and 166 tests passed; TypeScript, ESLint, Prettier, and the production build passed.
- Finance exact-engine harness contract: 8 runs; 281 assertions; 0 failures and 0 errors.
- Focused finance, recovery, reset, authorization, and audit checks remained green after the PostgreSQL savepoint, immutable-version lock, and governed reset-lock corrections.
- Pint, PHPStan, and `git diff --check` passed.

## Least-privilege and reconciliation boundary

- The exact `cashier` role alone may view, synchronize, and deliberately issue this bounded bill slice. Cashier access does not imply payment, accounting, claim, tariff-master, or administrator authority.
- The encounter and mutable bill head serialize issuance. Immutable historical versions require only read/append privileges and are not locked with update-class authority.
- The pharmacy reversal is created by a separate pharmacy-source worker; the reduced cashier runtime only reads validated pharmacy source facts and writes governed finance records.
- Finance charge events, bill versions, bill lines, operation receipts, and audit events remain insert-only evidence for the application runtime. No runtime identity receives finance evidence delete, truncate, DDL, or blanket schema rights.
- PostgreSQL and MySQL both reconcile gross CHARGE totals, signed REVERSAL totals, non-negative net totals, source bindings, version chains, bill heads, receipts, orphans, reset order, and retained audit evidence.

## Claim boundary

This record proves only the bound local implementation bytes on disposable PostgreSQL 17.10 and MySQL 8.4.11. The bill is a versioned pharmacy-valued charge statement, not an invoice, payment receipt, accounting ledger, insurance claim, receivable, or tariff engine. This record does not prove hosted Supabase migration, production configuration, real price correctness, cashier/pharmacy/finance/RMIK validation, payment readiness, capacity, operational support readiness, owner acceptance, parity acceptance, deployment approval, or G0/G3 closure. No payment, bank, accounting, BPJS, VClaim, SATUSEHAT, mail, device, or other external integration was exercised.
