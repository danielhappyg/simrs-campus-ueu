# T1 Local Exact Cash Settlement Evidence — 2026-09-02

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Scope: `EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1`
Application source aggregate SHA-256: `c2b8c1a8c7f7ca23fb0889584c66695534cfadec72824f84a81afc0449928e23`
Embedded worker SHA-256: `c649ed85091085118d85d3bd380a325d517c239540fedcfbcc9d48ebf96868b9`
Scenario catalogue SHA-256: `dfa9e59ad004380577223ee87545300b04736a31bdb4da744538b6d8ed24d8ef`

## Exact-engine records

| Engine | Result | Scenarios | Evidence record | Evidence SHA-256 |
|---|---:|---:|---|---|
| PostgreSQL 17.10 | PASS | 17/17 | `storage/app/portability-rehearsals/20260902T103254Z-postgresql17-exact-cash-settlement-bec9fabcd841.json` | `bb3f4782ed0813d4092c5340a80c9df8d581936298d46a3e0dc85744999946af` |
| MySQL 8.4.11 / InnoDB | PASS | 17/17 | `storage/app/portability-rehearsals/20260902T103218Z-mysql8411-exact-cash-settlement-135d63c195dd.json` | `4825d5f12e775ab5e4e26b04faf4f6802c8ebcc46a92b63eeb42e5469e466f79` |

Both JSON records are mode `0600`, bind the same source, worker, scenario, runtime-grant, and SQLite-gate catalogues, and report strict removal of the disposable database, server, runtime identities, and worker file.

## Verified boundary

The exact-engine pair verifies:

- fresh migration, empty rollback/reapply, database check constraints, and append-only triggers;
- forced duplicate migration-up failure followed by trigger-catalog readback proving guard reinstall;
- exact runtime grants, native database waiting between independent application processes, and one applied plus one replay outcome;
- an independent third connection proving exactly one settlement for the current bill version;
- cumulative version two of Rp14.000 collects only its Rp7.000 outstanding balance after a retained Rp7.000 version-one settlement, so the two retained settlements total Rp14.000 rather than double-charging the cumulative amount;
- technical replay receipt binding to the exact bill-version public identifier and content digest;
- idempotency conflict, stale/new-source/duplicate refusal, atomic audit rollback, retained-evidence rollback refusal, and synthetic reset/recovery with preserved audit evidence.

## Open boundary

G0 and G3 remain **OPEN**. This is not facility or finance-owner acceptance, hosted migration, deployment, UAT, or production-readiness evidence. It does not exercise partial payment, overpayment, card, refund, void, claims, BPJS, VClaim, E-Klaim, SATUSEHAT, ERP, or real patient data.

The READY / NOT RUN template remains unchanged at `docs/operations/T1_LOCAL_EXACT_CASH_SETTLEMENT_EVIDENCE_TEMPLATE_2026-09-02.md`; this final record was created only after both exact-engine records passed.
