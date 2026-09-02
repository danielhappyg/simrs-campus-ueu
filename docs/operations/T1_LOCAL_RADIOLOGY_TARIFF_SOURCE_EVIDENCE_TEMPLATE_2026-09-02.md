# T1 Local Radiology Tariff/Source Exact-Engine Evidence Template

Status: **NOT RUN — TEMPLATE ONLY**
Scope: local disposable PostgreSQL 17.10 and MySQL 8.4.11; empty-by-default teaching configuration
Deployment, hosted readiness, affected-domain acceptance, parity acceptance, G0, G3, and production claims: **not authorized by this record**

## Bound artifacts

- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_RADIOLOGY_PERFORMANCE_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md`
- Harness: `scripts/rehearse-local-radiology-tariff-source-portability.rb`
- Contract: `tests/Documentation/LocalRadiologyTariffSourcePortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_02_000300_create_radiology_performance_tariff_source.php`
- Focused tariff/source rules: `tests/Feature/Finance/FinanceRadiologyTariffBindingCoreTest.php`, `tests/Feature/Finance/FinanceRadiologySourceAdapterTest.php`, and `tests/Feature/Database/FinanceRadiologyTariffGuardTest.php`
- Generated evidence directory: `storage/app/portability-rehearsals/`

The harness binds every current source file by SHA-256 before execution, binds its embedded worker and closed 20-scenario catalogue separately, rechecks the binding before publication, and writes a new regular mode-`0600` JSON file without overwriting an existing result. Exact-engine evidence is valid only when the engine is harness-owned, disposable, strictly cleaned, and reports the exact required version.

## Reserved local commands

The contract state is **READY_NOT_RUN**. These commands are reserved for the orchestrated exact-engine gate after the application slice and full local suite are stable. A syntax check, documentation contract, SQLite result, mocked result, or inherited tariff/billing result does not execute this rehearsal.

```sh
SIMRS_RADIOLOGY_TARIFF_SOURCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_RADIOLOGY_TARIFF_SOURCE \
  ruby scripts/rehearse-local-radiology-tariff-source-portability.rb postgresql17

SIMRS_RADIOLOGY_TARIFF_SOURCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_RADIOLOGY_TARIFF_SOURCE \
  ruby scripts/rehearse-local-radiology-tariff-source-portability.rb mysql8411
```

The harness may create and destroy only database/server/user/worker state whose names it generated inside its disposable local engine. It must not contact a hosted database, deployment platform, payer, bank, insurer, BPJS, VClaim, SATUSEHAT, LIS, PACS/RIS, terminology server, mail service, or another external integration.

## Execution register

| Engine | Exact version required | Result | Generated evidence path | Mode | Strict cleanup |
|---|---:|---|---|---|---|
| PostgreSQL | 17.10 | NOT RUN | — | — | — |
| MySQL | 8.4.11 | NOT RUN | — | — | — |

Do not replace `NOT RUN` with `PASS` unless the corresponding command returns a final `PASS` document, all 20 scenario records pass, the source SHA still matches, and strict cleanup succeeds.

## Closed scenario register

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration and empty typed tables | NOT RUN | NOT RUN |
| Empty down/reapply | NOT RUN | NOT RUN |
| Exact finance-steward/cashier/admin/mixed role boundary | NOT RUN | NOT RUN |
| Explicit outpatient, emergency, and inpatient binding scope | NOT RUN | NOT RUN |
| Performed service-date resolution | NOT RUN | NOT RUN |
| Future authoring, half-open selection, and terminal retirement | NOT RUN | NOT RUN |
| Missing/retired/mismatched upstream refusal | NOT RUN | NOT RUN |
| Order/report activity does not itself create a charge | NOT RUN | NOT RUN |
| One performance creates one typed source and one typed charge | NOT RUN | NOT RUN |
| Radiology-only immutable bill snapshot | NOT RUN | NOT RUN |
| Partial synchronization and unresolved issuance refusal | NOT RUN | NOT RUN |
| Later gap resolution creates a new version without rewriting history | NOT RUN | NOT RUN |
| Replay, changed-key payload, retroactive and stale refusal | NOT RUN | NOT RUN |
| Two-process same-performance import serialization/reconciliation | NOT RUN | NOT RUN |
| Two-process competing binding writer serialization/refusal | NOT RUN | NOT RUN |
| Application SQL guard refusal | NOT RUN | NOT RUN |
| Database mutable-head and append-only refusal | NOT RUN | NOT RUN |
| Required audit, corruption, and source reconciliation refusal | NOT RUN | NOT RUN |
| Read-back least-privilege runtime | NOT RUN | NOT RUN |
| Bounded reset/recovery, retained-evidence rollback refusal, and strict cleanup | NOT RUN | NOT RUN |

## Required readback

- A radiology order or report alone never creates a financial source. A completed performance is necessary, but it remains unresolved until an exact retained radiology-master version, care setting, effective binding, effective tariff, and component provenance all reconcile.
- The source stores integer rupiah and immutable order, performance, binding, tariff, component, encounter, patient, date, and digest snapshots. Later master, tariff, binding, or report activity must not rewrite issued evidence.
- One retained performance may have exactly one `finance_radiology_source_events` row and one matching `finance_charge_events` row. The pharmacy and radiology typed foreign keys are mutually exclusive.
- The two race scenarios must use two independent application processes, observe a real database wait, record the lock order, produce the closed outcomes, show no deadlock, and pass a durable third-connection reconciliation.
- The runtime identity must have only the closed `SELECT` grants and no wildcard, write, DDL, user-administration, payment, claim, or schema authority.
- Recovery must reconcile heads, immutable version chains, upstream snapshots, receipts, typed source/charge links, and digests. Reset may remove bounded teaching rows in dependency order but must preserve required audit evidence.

## Claim boundary

Even if both engine rows truthfully become `PASS`, this artifact proves only the bound local implementation bytes on disposable engines. It does not establish that any amount is a real or approved hospital price; authorize automatic charging; prove hosted migration or deployment; establish capacity, operations, UAT, owner acceptance, affected-domain acceptance, parity acceptance, G0, or G3; or enable payment, accounting, claims, BPJS, VClaim, SATUSEHAT, LIS, or PACS/RIS.
