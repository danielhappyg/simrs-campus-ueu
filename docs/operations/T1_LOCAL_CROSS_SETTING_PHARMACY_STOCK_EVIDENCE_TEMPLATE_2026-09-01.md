# T1 Local Cross-Setting Pharmacy and Stock Exact-Engine Evidence Template

Status: **NOT RUN — TEMPLATE ONLY**
Scope: local disposable PostgreSQL 17.10 and MySQL 8.4.11; synthetic-only data boundary
Deployment, hosted readiness, affected-domain acceptance, parity acceptance, and production claims: **not authorized by this record**

## Bound artifacts

- Authorization: `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md`
- Harness: `scripts/rehearse-local-pharmacy-stock-portability.rb`
- Contract: `tests/Documentation/LocalPharmacyStockPortabilityHarnessContractTest.rb`
- Migration: `database/migrations/2026_09_01_000600_create_cross_setting_pharmacy_tables.php`
- Focused backend references: `tests/Feature/Pharmacy/CrossSettingPharmacyWorkflowTest.php` and scenario-filtered `tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php`
- Focused lifecycle-gate references: cancellation, RJ documentation/RM closure, IGD disposition/handoff/compensation, RI transfer/discharge/RM closure feature tests bound by the harness source catalogue
- Generated evidence directory: `storage/app/portability-rehearsals/`

The future harness must record a closed source-binding digest, embedded-worker digest, scenario-catalogue digest, runtime-grant digest, sanitized command/result catalogues, exact application source SHA, and engine-owned strict-cleanup result. Generated JSON evidence must be a regular mode-`0600` file inside the repository-owned mode-`0700` evidence directory.

## Authorized local commands

The harness is `READY_NOT_RUN`: the four-race launcher, reduced runtime identity, immutable/corruption guards, reset/recovery readbacks, rollback refusal, strict cleanup, and evidence writer are contract-bound. Every future `PASS` scenario must carry its own worker, filtered-test, database, or race evidence; a broad focused-suite pass is not scenario evidence. Do not execute or treat these commands as evidence until the focused backend and full suite are green and the product owner explicitly starts the disposable exact-engine rehearsal.

```sh
SIMRS_PHARMACY_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_PHARMACY_STOCK_PORTABILITY \
  ruby scripts/rehearse-local-pharmacy-stock-portability.rb postgresql17

SIMRS_PHARMACY_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_PHARMACY_STOCK_PORTABILITY \
  ruby scripts/rehearse-local-pharmacy-stock-portability.rb mysql8411
```

The harness may create and destroy only its own disposable local database/server/user state. It must not contact suppliers, payment services, BPJS, VClaim, SATUSEHAT, mail, hosted databases, deployment services, or any other external integration.

## Execution register

| Engine | Exact version required | Result | Generated evidence path | File mode | Cleanup verified |
|---|---:|---|---|---|---|
| PostgreSQL | 17.10 | NOT RUN | — | — | — |
| MySQL | 8.4.11 | NOT RUN | — | — | — |

Do not replace `NOT RUN` with `PASS` unless the corresponding command returns a final `PASS` document, the bound SHA matches the tested source, and strict cleanup succeeds. Contract, syntax, SQLite, or mocked test results are not exact-engine execution evidence.

## Required scenario readback

| Scenario | PostgreSQL 17.10 | MySQL 8.4.11 |
|---|---|---|
| Fresh migration | NOT RUN | NOT RUN |
| Empty down/reapply | NOT RUN | NOT RUN |
| Exact physician/pharmacist/technician/inventory-controller boundaries | NOT RUN | NOT RUN |
| RJ, IGD, and RI prescription-to-handover journeys | NOT RUN | NOT RUN |
| Medicine/depot create, version, and retire | NOT RUN | NOT RUN |
| Opening balance and lot/expiry/quarantine controls | NOT RUN | NOT RUN |
| Pharmacist verification and refusal | NOT RUN | NOT RUN |
| Deterministic FEFO multi-lot preparation and final revalidation | NOT RUN | NOT RUN |
| Full, partial, and unfilled-closed handover reconciliation | NOT RUN | NOT RUN |
| Return-to-stock, quarantine, and non-returnable outcomes | NOT RUN | NOT RUN |
| Stock movement, lot balance, and charge-source reconciliation | NOT RUN | NOT RUN |
| Encounter cancellation, IGD handoff, RI transfer/discharge, and RM closure blockers | NOT RUN | NOT RUN |
| Exact replay after later head advance | NOT RUN | NOT RUN |
| Changed-payload same-key conflict | NOT RUN | NOT RUN |
| Two-process competing handover and no-negative-stock proof | NOT RUN | NOT RUN |
| Handover-versus-return and preparation-versus-transfer/discharge races | NOT RUN | NOT RUN |
| Reset/recovery-versus-operation race and clean post-race readback | NOT RUN | NOT RUN |
| Exact patient-claim, inventory, encounter, location, bed, and prescription lock-order observability | NOT RUN | NOT RUN |
| Application SQL write-guard refusal | NOT RUN | NOT RUN |
| Database snapshot/update/delete/truncate refusal | NOT RUN | NOT RUN |
| Evidence-chain corruption refusal | NOT RUN | NOT RUN |
| Least-privilege runtime grants | NOT RUN | NOT RUN |
| Bounded reset, audit preservation, and semantic recovery snapshot | NOT RUN | NOT RUN |
| Retained-evidence rollback refusal | NOT RUN | NOT RUN |
| Durable invariant verification | NOT RUN | NOT RUN |

## Least-privilege and reconciliation requirements

- Reference/master version and evidence tables are `SELECT` only or `SELECT, INSERT` according to their append-only contract.
- Encounter, inventory mutex, prescription head, preparation head, and lot-balance rows receive only the minimum `SELECT, INSERT, UPDATE` rights required by their domain writers; no application runtime identity receives blanket schema rights.
- Stock movements, prescription versions, verification/refusal events, allocations, handovers, returns, financial-source events, operation receipts, and audit events receive no runtime `UPDATE` or `DELETE` grant.
- Every net lot balance must equal its opening and correction movements less handovers plus eligible returns; every medication charge-source total must equal handovers less return reversals.
- The reset identity is separate and is used only for bounded synthetic reset. Reset removes the pharmacy transaction graph in dependency order while preserving required audit evidence and leaving no orphaned or unreconciled rows.

## Claim boundary

Even after both rows are truthfully recorded as `PASS`, this artifact proves only the bound local implementation bytes on disposable exact engines. It does not prove hosted migration, production configuration, real stock or price correctness, pharmacy/clinical/warehouse/finance/RMIK validation, capacity, operational support readiness, owner acceptance, parity acceptance, deployment approval, or G0/G3 closure.
