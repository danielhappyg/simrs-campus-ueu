# T1 Local Exact Cash Settlement Evidence Template

Status: **READY / NOT RUN**
Scope: `EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1` on disposable local PostgreSQL 17 and MySQL 8.4 only.

This template is intentionally not execution evidence. The executable rehearsal writes a new mode-`0600` JSON record under `storage/app/portability-rehearsals/`; it never overwrites this file.

## Required run bindings

- Exact source aggregate SHA-256
- Embedded worker SHA-256
- Closed scenario-catalogue SHA-256
- Exact runtime-grant catalogue SHA-256
- SQLite application-gate catalogue SHA-256
- Command and result catalogue SHA-256

## Required evidence

- Fresh migration and empty rollback/reapply on PostgreSQL 17 and MySQL 8.4
- Forced duplicate migration-up failure followed by trigger-catalog readback proving append-only guards were reinstalled
- Database check constraints and append-only trigger refusal
- Exact cashier runtime grants without delete, update, DDL, or schema wildcard grants
- Exact server-derived amount bound to the current immutable bill version
- Cumulative-version outstanding amount equals current immutable net less all validated prior retained settlements, never the cumulative net charged again
- Same-key/same-payload pair exposing a real native database wait and yielding one applied result plus one replay
- Independent third-connection readback proving one durable settlement and one technical receipt
- Replay receipt retains the exact bill-version public identifier as well as its content digest
- Changed-payload conflict; stale-version, new-source, and duplicate-settlement refusal
- Audit failure atomic rollback
- Populated migration rollback refusal
- Synthetic reset/recovery deletion order with retained audit evidence
- Strict removal of database, temporary engine, runtime identities, and worker file

## Boundary

This is local engineering evidence only. It does not assert owner acceptance, hosted readiness, deployment, UAT, G0, or G3. No partial payment, overpayment, card, refund, void, claim, BPJS, VClaim, E-Klaim, SATUSEHAT, ERP, or real patient data is exercised.
