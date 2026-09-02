# T1 Local Governed Finance Tariff and Cost-Component Master Exact-Engine Evidence Template

Status: **NOT RUN — TEMPLATE ONLY**
Scope: local disposable PostgreSQL 17.10 and MySQL 8.4.11; empty-by-default synthetic teaching configuration
Deployment, hosted readiness, affected-domain acceptance, parity acceptance, charge readiness, and production claims: **not authorized by this record**

## Bound artifacts

- Authorization: `docs/new-simrs-rebuild/phase-1/GOVERNED_EFFECTIVE_DATED_FINANCE_TARIFF_COMPONENT_MASTER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md`
- Harness: `scripts/rehearse-local-finance-tariff-component-master-portability.rb`
- Contract: `tests/Documentation/LocalFinanceTariffComponentMasterPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_02_000100_create_governed_finance_tariff_component_master.php`
- Focused backend, HTTP, recovery, access, guard, reset, and audit references: `tests/Feature/Finance/FinanceTariffMasterCoreTest.php`, `tests/Feature/Finance/FinanceTariffMasterHttpTest.php`, `tests/Feature/Authorization/FinanceTariffAccessTest.php`, `tests/Feature/Database/FinanceTariffSqlWriteGuardTest.php`, `tests/Feature/Operations/FinanceTariffRecoverySnapshotTest.php`, `tests/Feature/Simulation/FinanceTariffResetTest.php`, and `tests/Unit/Audit/FinanceTariffAuditIntegrationTest.php`
- Frontend references: `resources/js/components/finance/tariff-master-workspace.tsx`, `resources/js/components/finance/tariff-master-types.ts`, and `resources/js/pages/manajemen-data/tarif-komponen-biaya/index.tsx`
- Generated evidence directory: `storage/app/portability-rehearsals/`

The harness records a closed source-binding digest, exact application-source SHA-256, embedded-worker digest, scenario-catalogue digest, runtime-grant digest, sanitized command/result catalogues, and engine-owned strict-cleanup result. Generated JSON evidence must be a regular mode-`0600` file inside the repository-owned mode-`0700` evidence directory.

## Authorized local commands

The harness contract is **READY_NOT_RUN**. Its real-service worker, two-process competing-writer probe, reduced runtime and separate reset identities, temporal readbacks, immutable and mutable-head guards, recovery checks, rollback refusal, strict cleanup, and evidence writer are contract-bound. The harness is **NOT YET READY**. Exact-engine execution remains gated until the full local suite is green and the product owner explicitly starts the disposable exact-engine rehearsal. Do not execute these reserved commands or treat them as evidence before those gates are satisfied.

```sh
SIMRS_FINANCE_TARIFF_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_FINANCE_TARIFF_COMPONENT_MASTER \
  ruby scripts/rehearse-local-finance-tariff-component-master-portability.rb postgresql17

SIMRS_FINANCE_TARIFF_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_FINANCE_TARIFF_COMPONENT_MASTER \
  ruby scripts/rehearse-local-finance-tariff-component-master-portability.rb mysql8411
```

The harness may create and destroy only its own disposable local database, server, user, and worker state. It must not contact a hosted database, deployment service, payment service, bank, insurer, BPJS, VClaim, SATUSEHAT, LIS, PACS/RIS, terminology server, mail service, or other external integration.

## Execution register

| Engine | Exact version required | Result | Generated evidence path | File mode | Cleanup verified |
|---|---:|---|---|---|---|
| PostgreSQL | 17.10 | NOT RUN | — | — | — |
| MySQL | 8.4.11 | NOT RUN | — | — | — |

Do not replace `NOT RUN` with `PASS` unless the corresponding command returns a final `PASS` document, the bound SHA matches the tested source, every closed scenario passes, and strict cleanup succeeds. Contract, syntax, SQLite, mocked, or broad-suite results are not exact-engine execution evidence.

## Required scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration starts with zero catalogues, groups, components, and tariffs | NOT RUN | NOT RUN |
| Empty down/reapply | NOT RUN | NOT RUN |
| Exact finance-steward mutation boundary and authorization before lookup | NOT RUN | NOT RUN |
| Cashier read-only boundary; administrator and mixed-role non-bypass | NOT RUN | NOT RUN |
| Stable catalogue, group, component, and tariff code reservation | NOT RUN | NOT RUN |
| Integer-rupiah amount validation and floating-point refusal | NOT RUN | NOT RUN |
| Current effective version resolution | NOT RUN | NOT RUN |
| Future-authored version leaves today’s version effective | NOT RUN | NOT RUN |
| Half-open service-date interval boundary | NOT RUN | NOT RUN |
| Terminal retirement preserves historical resolution and blocks future selection | NOT RUN | NOT RUN |
| Upstream group/component/catalogue dependency retirement refusal | NOT RUN | NOT RUN |
| Exact idempotent replay and changed-payload conflict | NOT RUN | NOT RUN |
| Stale version/fingerprint refusal and competing-writer serialization | NOT RUN | NOT RUN |
| Application SQL write-guard refusal | NOT RUN | NOT RUN |
| Database mutable-head refusal outside governed scope | NOT RUN | NOT RUN |
| Database append-only update, delete, and truncate refusal | NOT RUN | NOT RUN |
| Required audit rollback and evidence-chain corruption refusal | NOT RUN | NOT RUN |
| Least-privilege runtime grants | NOT RUN | NOT RUN |
| Bounded reset, recovery reconciliation, and audit preservation | NOT RUN | NOT RUN |
| Retained-evidence rollback refusal and engine-owned strict cleanup | NOT RUN | NOT RUN |

## Least-privilege and reconciliation requirements

- `finance_steward` receives only view/manage tariff capabilities; `cashier` receives view only. Neither receives payment, claim, accounting, user-administration, or schema authority.
- Master heads may be inserted/updated only inside their governed mutation scope. Versions, code reservations, operation receipts, and audit events receive `SELECT, INSERT` only and no ordinary `UPDATE`, `DELETE`, or `TRUNCATE` privilege.
- Each head’s current digest/version must reconcile to the latest authored immutable version. Effective resolution must independently select the unique version whose half-open interval contains the service date.
- Future-authored versions, terminal retirement, and upstream state transitions must never create overlapping, ambiguous, or orphaned resolution.
- Reset removes tariff items before components, groups, and catalogues, empties the synthetic master graph, and preserves required audit evidence.
- The competing-writer scenario must use two independent application processes, observe a real database wait on the governed head row, produce one applied and one stale/conflict-denied outcome, and observe no deadlock.
- Every `PASS` scenario must carry its own migration, real-worker, filtered exact-engine test, guard, privilege, race, recovery, rollback, or strict-cleanup evidence; catalogue membership alone is not proof.

## Claim boundary

Even after both engine rows truthfully read `PASS`, this artifact will prove only the bound local implementation bytes on disposable exact engines. It will not prove that any amount is a real or approved hospital price, that laboratory/radiology/accommodation automatically produces a charge, or that payment, accounting, claims, hosted migration, deployment, capacity, operations, owner acceptance, parity acceptance, G0, or G3 is complete.
