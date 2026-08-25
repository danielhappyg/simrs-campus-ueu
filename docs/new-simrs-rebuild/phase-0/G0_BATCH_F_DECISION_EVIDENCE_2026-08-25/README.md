# Batch F decision evidence directory

This directory is reserved for synthetic-only, independently reviewed JSON artifacts referenced by `G0_BATCH_F_DECISION_REGISTER_2026-08-25.json`. It is intentionally empty at proposal time. A filename, screenshot, route, or menu capture is not evidence of finance, claim, payment, journal, reconciliation, or integration behavior.

## Safety boundary

- Never place real patient, claim, payment, bank, BPJS, VClaim, E-Klaim, iDRG, or SATUSEHAT data here.
- Every integration fixture must keep `endpoint: null`, `credential_state: absent`, `outbound_network: false`, and `delivery_state: NOT_SENT`.
- Klaim and BPJS surfaces remain `Soon`; artifacts cannot assert Production or external-system readiness.
- Files must be regular JSON files inside this directory, use the closed schema expected by the validator, match the register fields, and match their recorded SHA-256.
- The reviewer must be a non-placeholder person distinct from the artifact subject/author, with a verification method and reference.

## Closed artifact types

The validator accepts only these structured contracts:

1. `g0_parity_governance_attestation` for an accountable-owner appointment, co-owner appointment, gate-authority appointment, or final approval. It binds the register, PAR ID, subject, identity, authority, exact scope, date, decision status/disposition where applicable, conditions, and independent reviewer.
2. `g0_parity_evidence` for non-pending O/M/I/U evidence. It binds the register, PAR ID, normalized evidence class and basis, date, source, reference, interpreter, confidence, and reviewer.
3. `g0_batch_f_gate_resolution` for an applicability-specific A–E upstream resolution or scoped E/G deferral. A–E resolution binds the loaded register ID/SHA and every frozen source requirement to its appointed owner and recorded approved reproduce/replace decision. A–D cannot be deferred. A scoped E deferral binds appointed `pharmacy_gf` and `finance_accounting` identities/artifacts and exactly excludes medication-charge readiness, stock valuation, pharmacy-claim completeness, and live delivery. G deferral binds the manifest SHA, appointed reporting authority, and exact no-readiness/no-export/no-financial-truth/no-live-delivery exclusions.
4. `g0_batch_f_gate_deferral_approval` for each authority participating in an E or G deferral. It independently binds that appointed authority's identity/domain to the exact batch, scope, resolution, exclusions, date, and reviewer; the enclosing gate artifact binds every approval by path and SHA-256.
5. `g0_batch_f_intra_dependency_resolution` for an intra-F edge. It binds the source and target PAR IDs, exact lifecycle scope, target appointed owner/lead, approved reproduce/replace disposition, approval reference/SHA, date, and reviewer.
6. `g0_batch_f_ledger_receipt` for one applicable immutable ledger or read-only projection. It binds the synthetic period, Asia/Jakarta cutoff, IDR minor unit, positive event count, exact control values, idempotency key, distinct ledger digest, author, and reviewer.
7. `g0_batch_f_reconciliation` for an applicability-specific reconciliation. It binds the exact ledger-receipt files, profile, integer control totals, frozen equations, all-zero computed differences, late-posting policy, author, and reviewer.
8. `g0_batch_f_consolidation_mapping` for one audited candidate. Every member must use the same artifact/terminal target; that target must be approved as reproduce/replace and the mapping binds its appointed owner and approval SHA. The artifact preserves every ID, role, context, state, field, amount, control total, lineage, authority, audit effect, and exclusion.

Unknown top-level fields, malformed JSON, path escape, symlink artifacts, digest mismatch, placeholder identities, self-review, unrelated approvals, structural-only behavioral claims, nonpositive event counts, or unbalanced equations fail closed.

## Current state

No owner, co-owner, gate authority, approval, behavioral evidence, ledger receipt, reconciliation, dependency resolution, or consolidation mapping is recorded. Every register row remains P/pending.
