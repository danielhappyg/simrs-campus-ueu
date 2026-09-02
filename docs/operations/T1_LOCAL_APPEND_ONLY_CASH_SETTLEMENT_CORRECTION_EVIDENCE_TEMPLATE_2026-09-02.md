# T1 Local Append-Only Cash Settlement Correction Evidence Template

Status: **READY / NOT RUN**
Scope: `APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1` on disposable local PostgreSQL 17 and MySQL 8.4 only.

This template is not execution evidence. The dedicated rehearsal writes a new mode-`0600` JSON record under `storage/app/portability-rehearsals/` and never overwrites this file.

## Required source bindings

- Application-source aggregate, embedded-worker, closed-scenario, runtime-grant, SQLite-gate, command-catalogue, and result-catalogue SHA-256 values
- Exact database engine and version readback
- Fresh migration duration and immutable evidence timestamp

## Required exact-engine evidence

- Fresh install and empty rollback/reapply
- Forced pre-readiness installation failure proving both lifetime settlement uniqueness barriers remain and append-only guards reinstall in `finally`
- Correction constraints, recovery-relevant prior-net column, and database append-only triggers
- Read-back exact runtime grants with no delete, DDL, or schema-wildcard authority, plus exact-role denials
- Request/reject and request/approve/refund-complete/replacement workflows
- Same-key replay, changed-payload conflict, double-review refusal, and double-refund refusal
- Two independent application processes exposing a real native database wait and yielding one applied request plus one replay
- Audit-failure atomic rollback and receipt-integrity refusal
- Predecessor-refund net-cash case with `prior_net_collected_amount_snapshot`
- Independent third-connection readback of raw cash, completed refund, exact net cash, replacement snapshot, event chain, and operation receipts
- Populated migration rollback refusal, bounded synthetic reset with retained audit evidence, and strict cleanup

## Boundary

This is local engineering evidence only. It is not owner acceptance, UAT, hosted migration, deployment, production readiness, facility approval, G0 closure, or G3 closure. It uses synthetic records and disposable local engines only. It does not exercise partial refund/payment, split tender, card, bank transfer, treasury, accounting journal, claim, BPJS, VClaim, E-Klaim, SATUSEHAT, ERP, or real patient data.

The executable evidence must fail closed if any scenario, source binding, runtime grant, engine version, reset, or strict-cleanup check is incomplete.
