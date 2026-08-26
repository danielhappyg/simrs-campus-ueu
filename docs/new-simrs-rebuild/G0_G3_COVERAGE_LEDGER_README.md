# G0–G3 coverage ledger

This ledger is a deterministic, synthetic-only engineering evidence inventory for the 268 assessed capabilities and the canonical E2E-01 through E2E-16 workflows.

It is not an owner-decision register and does not copy owner names, dispositions, approvals, deployment claims, or gate verdicts. Those remain authoritative in their source artifacts. Per-capability governance here is limited to immutable source pointers and whether required references are present. Workflow bindings are per capability: unmapped rows remain `PENDING`; the twelve current engineering-supported mappings are explicitly `PROVISIONAL`; `AUTHORIZED` requires a repository authority reference.

## Files

- `G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-26.json` is the closed engineering-evidence overlay. Missing capability evidence uses explicit fail-closed defaults.
- `G0_G3_COVERAGE_LEDGER_2026-08-26.json` is generated. Do not edit it manually.
- `scripts/generate-g0-g3-coverage-ledger.rb` validates sources, computes hashes and writes or checks the ledger.

## Deterministic commands

Run from the fixed repository root:

```bash
ruby scripts/generate-g0-g3-coverage-ledger.rb --write --snapshot-date 2026-08-26
ruby scripts/generate-g0-g3-coverage-ledger.rb --check --snapshot-date 2026-08-26
ruby tests/Documentation/G0G3CoverageLedgerTest.rb
```

The generator accepts no caller-selected repository root or evidence-map path. It rejects absolute paths, traversal, missing files, non-regular files, symlinks, repository escapes, duplicate JSON keys, unknown or duplicate IDs, invalid vocabularies, secret-looking values, an incomplete E2E catalogue, and stale generated output. It also rejects the DEC-013 Checkpoint-2 and named older outpatient prototype artifacts so they cannot be imported as current clean-slate evidence. Writes use a same-directory temporary file followed by an atomic rename.

## Interpretation

- `runtime_availability` distinguishes absence, partial scaffolding, and complete implementation.
- `automated_evidence` does not equate test files with a current pass.
- database evidence is recorded independently for SQLite, PostgreSQL 17, MySQL 8.4, and other MySQL compatibility runs.
- MySQL 9.7.1 evidence is compatibility-only and never substitutes for MySQL 8.4.
- hosted UAT, reconciliation, defect disposition, and owner acceptance are separate dimensions.
- `gate_summary.status=OPEN` is mandatory in this version because the generator does not parse formal governance approvals or a formal gate-decision contract. Even a fully green engineering overlay cannot promote the gate. Reference presence is never interpreted as approval or acceptance PASS.

All referenced records and scenarios remain synthetic. This artifact does not authorize real patient data or live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, or other production integrations.
