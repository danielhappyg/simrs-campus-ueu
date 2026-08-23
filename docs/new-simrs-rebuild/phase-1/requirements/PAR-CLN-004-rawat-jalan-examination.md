# Parity Requirement: PAR-CLN-004 Rawat Jalan examination (canonical)

## Control information

- Legacy menu/category: Pemeriksaan / Rawat Jalan
- Disposition: **Reproduce** (canonical clinical outpatient documentation)
- Business owner: Clinical SME TBD (interim product owner Daniel); RMIK consulted on documentation completeness
- Affected actors: nurse, physician/learner, supervisors
- Evidence: **Observed** screen/route `/pemeriksaan/rawatjalan`; Assesmen (PAR-CLN-001) consolidated into this capability unless ED-specific
- Target milestone: Phase 3 outpatient slice

## Business outcome

Document outpatient clinical work against the correct encounter (assessment, diagnoses/problems, orders, prescriptions) so RM, pharmacy, diagnostics, and claims receive consistent source records.

## Preconditions and master data

- Encounter exists from PAR-REG-003 and is in a clinically openable state (**Unknown** exact gate)
- Actor has intake/medical write capability for the teaching assignment
- Terminology catalogues governed (ICD later); UI labels are not codes

## Workflow and state transitions

1. Trigger: patient appears on outpatient examination worklist
2. Sequence: open encounter → nursing/medical documentation → orders/Rx as needed → resolve active lab orders → ready for closure/RM
3. Resulting state: clinical documentation versions with authors; order statuses
4. Downstream: pharmacy, lab/rad, RM completeness, charges

## Business rules and validation

| Rule ID | Rule | Error behavior | Evidence/source |
|---|---|---|---|
| BR-CLN-001 | All entries bound to patient+encounter | Reject orphan writes | Proposed NEW / architecture |
| BR-CLN-002 | Student drafts need supervision per teaching policy | Block finalize without review when policy requires | Proposed NEW (teaching) |
| BR-CLN-003 | Any future amendment workflow must preserve prior versions | Not built; no silent overwrite | Proposed NEW; owner approval required |
| BR-CLN-004 | Exact clinical form tabs/fields | TBD | Observed processForm endpoints — **Unknown** |
| BR-CLN-005 | No clinical entry or order may be created after encounter closure | Reject without mutation | Proposed NEW / teaching safety |
| BR-CLN-006 | Active lab orders block RM closure; final lab result lifecycle follows PAR-CLN-006 | Keep encounter open until the active order is resolved | Proposed NEW / teaching safety; **not SAHABAT-observed** |

## Exceptions

- Early departure / safety disposition: separate FR after discovery (legacy behaviour Unknown)
- Concurrent edit: fail closed with clear conflict — Proposed NEW

## Authorization and audit

Role/capability matrix server-side; audit create/update/approve/amend.

## Acceptance criteria (synthetic)

1. Authorized clinician documents against correct encounter only.
2. Unauthorized role denied with audit.
3. Downstream order/Rx appears for pharmacy/diagnostics actors when those modules are in slice.
4. Unknown field rules do not block scaffold; they block “Verified” status until discovery.
5. Closed encounters reject new clinical entries and lab orders; active lab orders prevent RM closure.

## Open unknowns

Required assessment fields; diagnosis coding timing; copy-forward (`enable_copy`) semantics; relation to EMR IPP menu. Preliminary results, final-result amendment, encounter reopen and lab-order cancellation are not built and require Clinical/Laboratory and RMIK approval. See [`PAR-CLN-006-laboratory.md`](PAR-CLN-006-laboratory.md) and [`../OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`](../OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md).
