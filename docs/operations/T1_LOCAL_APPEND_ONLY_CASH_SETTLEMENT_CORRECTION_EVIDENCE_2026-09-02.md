# T1 Local Append-Only Cash Settlement Correction Evidence — 2026-09-02

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Scope: `APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1`
Application source aggregate SHA-256: `ad4489db9c5192601b810d3e631febc1d1a10225695ccda417e87dee69068419`
Embedded worker SHA-256: `3b772585149b151e17058808e91a6e69269dfba23b3b9e3f00ceb7af74eca340`
Scenario catalogue SHA-256: `3bc2de7ef1e0c44724700ee1e7f046fb52bf5fb986f2e03c8c0ff2e71229b8fc`

## Exact-engine records

| Engine | Result | Scenarios | Evidence record | Evidence SHA-256 |
|---|---:|---:|---|---|
| PostgreSQL 17.10 | PASS | 22/22 | `storage/app/portability-rehearsals/20260902T155803Z-postgresql17-cash-settlement-correction-add99d552334.json` | `dca440d71242b5bdd27093ae56f221220545bad79ac4faf6c0cafe08e823d3d2` |
| MySQL 8.4.11 / InnoDB | PASS | 22/22 | `storage/app/portability-rehearsals/20260902T155936Z-mysql8411-cash-settlement-correction-996d6f8df6f9.json` | `3e518413ea7ddf5820655b9ba0ed75b0751b0d82c0ae2bb64291d27765432fec` |

Both mode-`0600` JSON records bind identical application, worker, scenario, runtime-grant, and SQLite-gate catalogues. Both report strict removal of the disposable database, temporary server, runtime identities, and worker file.

## Verified behavior and integrity

The exact-engine pair verifies:

- fresh migration, empty rollback/reapply, forced pre-readiness failure preserving both old lifetime uniqueness barriers, and guard reinstall;
- four required database checks, correction append-only triggers, and the recovery-relevant prior-net snapshot column;
- explicit short-name inventory: PostgreSQL `fscor_immutable` and `fscor_truncate_guard`, MySQL `fscor_immutable_update` and `fscor_immutable_delete`, and `fscor_public_id_uq` on both engines;
- exact runtime grants and role denials, including denial when the runtime identity tries to activate the synthetic-reset session flag;
- request/reject and request/approve/refund-complete/replacement, same-key replay, changed-payload conflict, double-review refusal, and double-refund refusal;
- a real native database wait between two independent application processes, with one applied request and one replay;
- an independent third connection reading two retained Rp9.000 settlement rows, one Rp9.000 completed refund, Rp9.000 net cash, and replacement prior-net snapshot `0`;
- healthy same-version replacement recovery with zero settlement-audit mismatches; deleting or tampering either uniquely bound audit produces one mismatch and cannot be masked by the sibling settlement;
- audit-failure atomic rollback, receipt integrity, populated migration rollback refusal, installer-owner bounded reset with two retained reset audit events, and strict cleanup.

## Open boundary

G0 and G3 remain **OPEN**. This record is not facility or finance-owner acceptance, hosted migration, deployment, UAT, or production-readiness evidence. It does not exercise partial refund/payment, split tender, card, bank transfer, treasury, accounting journal, claim, BPJS, VClaim, E-Klaim, SATUSEHAT, ERP, or real patient data.

The READY / NOT RUN template remains separate at `docs/operations/T1_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_EVIDENCE_TEMPLATE_2026-09-02.md`.
