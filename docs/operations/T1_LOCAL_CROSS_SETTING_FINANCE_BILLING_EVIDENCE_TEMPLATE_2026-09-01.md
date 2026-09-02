# T1 Local Cross-Setting Finance Billing Exact-Engine Evidence Template

Status: **NOT RUN — TEMPLATE ONLY**
Scope: local disposable PostgreSQL 17.10 and MySQL 8.4.11; synthetic-only data boundary
Deployment, hosted readiness, affected-domain acceptance, parity acceptance, payment readiness, and production claims: **not authorized by this record**

## Bound artifacts

- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md`
- Harness: `scripts/rehearse-local-finance-billing-portability.rb`
- Contract: `tests/Documentation/LocalFinanceBillingPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_01_000800_create_cross_setting_financial_spine_tables.php`
- Focused backend references: `tests/Feature/Finance/FinanceBillCoreTest.php`, `tests/Feature/Finance/BillingHttpWorkflowTest.php`, and `tests/Feature/Operations/FinanceRecoverySnapshotTest.php`
- Generated evidence directory: `storage/app/portability-rehearsals/`

The future harness must record a closed source-binding digest, embedded-worker digest, scenario-catalogue digest, runtime-grant digest, sanitized command/result catalogues, exact application source SHA, and engine-owned strict-cleanup result. Generated JSON evidence must be a regular mode-`0600` file inside the repository-owned mode-`0700` evidence directory.

## Authorized local commands

The harness is `READY_NOT_RUN`. Its real-service worker, reduced runtime and separate reset identities, immutable and mutable-head guards, recovery readbacks, rollback refusal, strict cleanup, and evidence writer are contract-bound. Every future `PASS` scenario must carry its own worker, filtered-test, migration, guard, or database-readback evidence. Do not execute or treat these commands as evidence until the full local suite is green and the product owner explicitly starts the disposable exact-engine rehearsal.

```sh
SIMRS_FINANCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_FINANCE_BILLING_PORTABILITY \
  ruby scripts/rehearse-local-finance-billing-portability.rb postgresql17

SIMRS_FINANCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_FINANCE_BILLING_PORTABILITY \
  ruby scripts/rehearse-local-finance-billing-portability.rb mysql8411
```

The harness may create and destroy only its own disposable local database, server, user, and worker state. It must not contact payment services, accounting systems, banks, BPJS, VClaim, SATUSEHAT, mail, hosted databases, deployment services, or any other external integration.

## Execution register

| Engine | Exact version required | Result | Generated evidence path | File mode | Cleanup verified |
|---|---:|---|---|---|---|
| PostgreSQL | 17.10 | NOT RUN | — | — | — |
| MySQL | 8.4.11 | NOT RUN | — | — | — |

Do not replace `NOT RUN` with `PASS` unless the corresponding command returns a final `PASS` document, the bound SHA matches the tested source, all 20 scenarios pass, and strict cleanup succeeds. Contract, syntax, SQLite, mocked, or broad-suite results are not exact-engine execution evidence.

## Required scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration | NOT RUN | NOT RUN |
| Empty down/reapply | NOT RUN | NOT RUN |
| Exact cashier role boundary; authorization before lookup | NOT RUN | NOT RUN |
| RJ, IGD, and RI versioned encounter billing | NOT RUN | NOT RUN |
| Read-only initial synchronization-candidate discovery | NOT RUN | NOT RUN |
| Real pharmacy CHARGE and REVERSAL source reconciliation | NOT RUN | NOT RUN |
| Initial source synchronization and version-one issue | NOT RUN | NOT RUN |
| Later-source synchronization and version-two issue | NOT RUN | NOT RUN |
| Immutable version-one history and previous-version chain | NOT RUN | NOT RUN |
| Exact idempotent replay | NOT RUN | NOT RUN |
| Changed-payload same-key conflict | NOT RUN | NOT RUN |
| Stale fingerprint refusal | NOT RUN | NOT RUN |
| Application SQL write-guard refusal | NOT RUN | NOT RUN |
| Database mutable-head refusal outside governed scope | NOT RUN | NOT RUN |
| Database append-only update, delete, and truncate refusal | NOT RUN | NOT RUN |
| Evidence-chain corruption refusal with restored source fixture | NOT RUN | NOT RUN |
| Least-privilege runtime grants | NOT RUN | NOT RUN |
| Bounded reset, empty finance readback, and audit preservation | NOT RUN | NOT RUN |
| Retained-evidence rollback refusal | NOT RUN | NOT RUN |
| Durable source, line, version, receipt, head, total, and orphan invariants | NOT RUN | NOT RUN |

## Least-privilege and reconciliation requirements

- The exact `cashier` role alone may view, synchronize, and deliberately issue this bill slice; no cashier privilege implies payment, accounting, claim, tariff-master, or administrator authority.
- Encounters may be selected and locked; finance bill heads may be inserted and updated only inside the governed finance mutation scope.
- Finance charge events, bill versions, bill lines, operation receipts, and audit events receive `SELECT, INSERT` only. No application runtime identity receives finance evidence `UPDATE`, `DELETE`, `TRUNCATE`, DDL, or blanket schema rights.
- Every finance charge event must retain an exact source binding to a validated pharmacy financial event. Every version line must bind to one retained finance charge event.
- Version gross must equal CHARGE lines; reversal must equal signed REVERSAL lines; net must equal gross plus reversal and remain non-negative. The bill head must identify the latest immutable version and source-set digest.
- The reset identity is separate and may operate only for bounded synthetic reset. Reset removes finance before pharmacy source evidence in dependency order, empties the synthetic finance graph, and preserves required audit evidence.

## Claim boundary

Even after both rows are truthfully recorded as `PASS`, this artifact proves only the bound local implementation bytes on disposable exact engines. The bill is a versioned pharmacy-valued charge statement, not an invoice, payment receipt, accounting ledger, insurance claim, or tariff engine. This record does not prove hosted migration, production configuration, real price correctness, cashier/pharmacy/finance/RMIK validation, payment readiness, capacity, operational support readiness, owner acceptance, parity acceptance, deployment approval, or G0/G3 closure.
