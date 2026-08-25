# G0 Batch B patient and encounter proposal — 2026-08-25

**Status:** PROPOSAL ONLY — NOT OWNER-APPROVED  
**Gate effect:** none; G0 remains open  
**Scope:** the exact eight Batch B IDs in [`G0_PARITY_BATCH_MANIFEST.json`](G0_PARITY_BATCH_MANIFEST.json)  
**Machine-verifiable state:** [`G0_BATCH_B_DECISION_REGISTER_2026-08-25.json`](G0_BATCH_B_DECISION_REGISTER_2026-08-25.json); all evidence, decisions, appointments and approvals remain explicitly pending

This pack records decision candidates already visible in the current parity matrix and specifies what must be decided. It does not promote a candidate into an approved disposition, appoint any person or unit, establish legacy equivalence, or authorize real patient data, production operation, live payer services, BPJS/VClaim, SATUSEHAT or public identifier display.

## Current candidates requiring authoritative decision

| PAR ID | Current matrix candidate | Decision still required |
|---|---|---|
| PAR-ADM-009 | Reproduce ward/bed master | Approve hierarchy, capacity, availability, retirement, correction and downstream bed/charge/report rules. |
| PAR-ADM-010 | Pending evidence | Decide the canonical payment-method master and its payer, billing, claim-simulation and correction boundaries. |
| PAR-ADM-033 | Pending evidence | Decide encounter/accommodation class codes, eligibility, effective dating and bed/finance consequences. |
| PAR-REG-001 | Reproduce inpatient registration | Approve exact identity, admission, bed, duplicate, correction/cancellation and downstream state rules. |
| PAR-REG-002 | Reproduce ED registration | Approve exact identity/arrival, duplicate, correction/cancellation and ED handoff rules without inferring clinical triage policy. |
| PAR-REG-003 | Reproduce outpatient registration | Approve clinic/schedule, queue, duplicate, correction/cancellation and downstream reconciliation. |
| PAR-REG-004 | Replace with safe synthetic display | Approve the business display need, minimized fields, audience/access and explicit exclusion of insecure or real-data patterns. |
| PAR-REG-005 | Consolidate into PAR-REG-003 | Approve only after complete field/rule/state mapping, historical trace, deep-link retirement and downstream reconciliation. |

## Machine-validation contract

- Every non-pending evidence item and future appointment/approval must reference a regular closed-schema JSON artifact under `G0_BATCH_B_DECISION_EVIDENCE_2026-08-25/` with matching SHA-256 and independent reviewer verification.
- Every affected `co_owners` authority must have exactly one matching appointment dependency; no omitted or extra authority is allowed.
- Consolidation targets must exist and remain acyclic across Batch A, Batch B and current matrix consolidation edges.
- `register_status: complete` is valid only when every row is approved or authoritatively deferred with non-pending evidence, complete appointments, recorded approval and ready synthetic scenarios.

## References

- [`G0 parity-control baseline`](G0_PARITY_CONTROL_BASELINE_2026-08-25.md)
- [`G0 deterministic batch manifest`](G0_PARITY_BATCH_MANIFEST.json)
- [`G0 Batch B decision register`](G0_BATCH_B_DECISION_REGISTER_2026-08-25.json)
- [`G0 owner appointment pack`](G0_OWNER_APPOINTMENT_PACK_2026-08-25.md)
- [`Parity requirements matrix`](../PARITY_REQUIREMENTS_MATRIX.md)
