# T1 Local Laboratory Verified-Result Tariff/Source Exact-Engine Evidence Template

Status: **READY, NOT RUN — EXECUTABLE FAIL-CLOSED HARNESS; NO ENGINE EVIDENCE YET**

Scope: future local disposable PostgreSQL 17.10 and MySQL 8.4.11; empty-by-default configuration

Deployment, hosted readiness, owner/domain/parity acceptance, G0, G3, and production claims: **not authorized by this record**

## Bound artifacts

- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_LABORATORY_VERIFIED_RESULT_TARIFF_SOURCE_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md`
- Authorization contract: `tests/Documentation/CrossSettingLaboratoryVerifiedResultTariffSourceV1LocalEngineeringAuthorizationTest.rb`
- Executable local harness: `scripts/rehearse-local-laboratory-tariff-source-portability.rb`
- Harness contract: `tests/Documentation/LocalLaboratoryTariffSourcePortabilityHarnessContractTest.rb`
- Future migration: `database/migrations/2026_09_02_000400_create_laboratory_verified_result_tariff_source.php`
- Future generated evidence directory: `storage/app/portability-rehearsals/`

The harness binds the closed application, migration, recovery/reset, authorization, contract, worker, SQLite-gate, scenario, and least-privilege grant catalogues by SHA-256. It refuses inherited database configuration, unsafe source paths, source drift, incomplete scenarios, unobserved race waits, non-exact engines, cleanup failure, and overwrite publication. A syntax or contract result is not engine evidence.

## Authorized local commands

```sh
SIMRS_LABORATORY_TARIFF_SOURCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_LABORATORY_TARIFF_SOURCE \
  ruby scripts/rehearse-local-laboratory-tariff-source-portability.rb postgresql17

SIMRS_LABORATORY_TARIFF_SOURCE_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_LABORATORY_TARIFF_SOURCE \
  ruby scripts/rehearse-local-laboratory-tariff-source-portability.rb mysql8411
```

Each command first runs the closed SQLite application gate, then creates its exact local disposable engine. Exact-engine execution remains harness-owned, disposable, and strictly cleaned; it never accepts a hosted database or external-integration override. Do not execute either command until the application gates and bound source bytes are frozen.

## Execution register

| Engine | Exact version required | Result | Generated evidence path | Strict cleanup |
|---|---:|---|---|---|
| PostgreSQL | 17.10 | NOT RUN | — | — |
| MySQL | 8.4.11 | NOT RUN | — | — |

Do not replace `NOT RUN` with `PASS` until the executable harness returns 22 per-scenario PASS records, rechecks the unchanged source binding, writes a new mode-`0600` evidence file create-only, returns its SHA-256, and verifies strict cleanup.

## Closed scenario register

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration and empty typed tables | NOT RUN | NOT RUN |
| Empty down/reapply | NOT RUN | NOT RUN |
| Exact finance-steward/cashier/admin/mixed boundary | NOT RUN | NOT RUN |
| Explicit outpatient, emergency, and inpatient bindings | NOT RUN | NOT RUN |
| Original VERIFIED `verified_at` service-date resolution | NOT RUN | NOT RUN |
| Future authoring, half-open selection, and terminal retirement | NOT RUN | NOT RUN |
| Missing/retired/mismatched result-chain refusal | NOT RUN | NOT RUN |
| Order/specimen/Draft/cancellation activity creates no charge | NOT RUN | NOT RUN |
| One original VERIFIED result creates one typed source and charge | NOT RUN | NOT RUN |
| Verified amendment and acknowledgement create no extra charge | NOT RUN | NOT RUN |
| Laboratory-only immutable bill snapshot | NOT RUN | NOT RUN |
| Partial synchronization and unresolved issuance refusal | NOT RUN | NOT RUN |
| Later gap resolution creates a new version without rewriting history | NOT RUN | NOT RUN |
| Replay, changed-key payload, retroactive and stale refusal | NOT RUN | NOT RUN |
| Two-process same-result import serialization/reconciliation | NOT RUN | NOT RUN |
| Two-process competing binding writer serialization/refusal | NOT RUN | NOT RUN |
| Verification/synchronization cutoff race | NOT RUN | NOT RUN |
| Application SQL guard refusal | NOT RUN | NOT RUN |
| Database mutable-head, append-only, and typed-source refusal | NOT RUN | NOT RUN |
| Required audit, corruption, and reconciliation refusal | NOT RUN | NOT RUN |
| Read-back least-privilege runtime | NOT RUN | NOT RUN |
| Bounded reset/recovery, rollback refusal, and strict cleanup | NOT RUN | NOT RUN |

## Required future readback

- Only the original immutable `VERIFIED` result with `base_verified_version_id=NULL` is eligible. Its `verified_at` supplies the service date.
- Specimen acceptance, Draft, amendment, acknowledgement, and pre-verification cancellation never create or change a source.
- One order and original verified result reconcile to one `finance_laboratory_source_events` row and one typed `finance_charge_events` row.
- Finance evidence contains no result values, reference ranges, critical flags, clinical questions, or communication notes.
- The three races use independent processes, observe real waits and lock order, and finish with third-connection reconciliation.
- Ordinary runtime grants are closed and contain no wildcard, write, DDL, reset, user-administration, payment, claim, or integration authority.
- Recovery reconciles heads, version chains, upstream snapshots, receipts, typed links, bill evidence, and digests. Reset preserves required audit evidence.

## Claim boundary

This template proves no database execution. A later truthful exact-engine PASS would prove only the bound local bytes on SQLite and disposable exact engines. It would not establish a real or approved hospital price, automatic-charge authority, hosted readiness, deployment, owner/domain/parity acceptance, G0, or G3; or enable payment, accounting, claims, BPJS, VClaim, SATUSEHAT, LIS, or analyser integration.
