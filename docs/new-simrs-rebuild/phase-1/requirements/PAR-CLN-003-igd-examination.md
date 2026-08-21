# Parity Requirement: PAR-CLN-003 IGD examination (canonical)

## Control information

- Legacy menu/category: Pemeriksaan / IGD
- Disposition: **Reproduce** (canonical ED clinical documentation)
- Business owner: Clinical SME TBD (interim product owner Daniel); RMIK consulted on documentation completeness
- Affected actors: triage nurse (upstream), ED nurse, ED physician/learner, supervisors
- Evidence: **Observed** screen/route `/pemeriksaan/ugd` (processForm, riwayat, visite grids); **Manual-documented** Form Input Data IGD (tabs: anamnesa/diagnosa/pemeriksaan, tindakan; kelanjutan; kasus; kecelakaan); Triage menu distinct (**Observed** `/pemeriksaan/triage`) — see PAR-CLN-002 disposition
- Target milestone: Phase 3 ED vertical slice
- Slice pack: [`../ED_SLICE_DISPOSITIONS.md`](../ED_SLICE_DISPOSITIONS.md)

## Business outcome

Document emergency clinical work against the correct ED encounter (assessment, diagnoses/problems, actions/orders, prescriptions) and record disposition so inpatient admission, referral, RM, pharmacy, diagnostics, and claims receive consistent source records.

## Preconditions and master data

- Encounter exists from PAR-REG-002 and is clinically openable (**Unknown** exact gate; triage-before-exam **Unknown**)
- Actor has ED intake/medical write capability for the teaching assignment
- Terminology catalogues governed (ICD later); UI labels are not codes
- Triage result from PAR-CLN-002 when required by policy — **Unknown** whether mandatory

## Workflow and state transitions

1. Trigger: patient appears on ED examination worklist
2. Sequence: open encounter → (optional) review triage → nursing/medical documentation → orders/Rx as needed → set disposition (kelanjutan)
3. Resulting state: clinical documentation versions with authors; order statuses; disposition recorded (**exact status vocabulary Unknown**)
4. Downstream: pharmacy (PAR-PHA-001), lab/rad, admit to RI (PAR-REG-001) if disposition requires, RM completeness, charges, Register IGD

## Business rules and validation

| Rule ID | Rule | Error behavior | Evidence/source |
|---|---|---|---|
| BR-EDCLN-001 | All entries bound to patient+encounter | Reject orphan writes | Proposed NEW / architecture |
| BR-EDCLN-002 | Student drafts need supervision per teaching policy | Block finalize without review when policy requires | Proposed NEW (teaching) |
| BR-EDCLN-003 | Amendments preserve prior versions | No silent overwrite | Proposed NEW |
| BR-EDCLN-004 | Disposition required before ED closure | TBD | Manual-documented kelanjutan list — **Unknown** if mandatory in live system |
| BR-EDCLN-005 | Exact clinical form tabs/fields | TBD | Observed processForm endpoints — **Unknown** |

## Exceptions

- DOA / death in ED: Manual-documented disposition options — clinical/legal teaching rules **Unknown**; do not invent
- Concurrent edit: fail closed with clear conflict — Proposed NEW
- Disposition to Rawat Inap without bed availability: behaviour **Unknown**

## Authorization and audit

Role/capability matrix server-side; audit create/update/approve/amend/disposition.

## Acceptance criteria (synthetic)

1. Authorized clinician documents against correct ED encounter only.
2. Unauthorized role denied with audit.
3. Disposition to discharge vs admit is recorded and auditable (admit handoff to inpatient slice when in scope).
4. Downstream order/Rx appears for pharmacy/diagnostics actors when those modules are in slice.
5. Unknown field/triage-scale rules do not block scaffold; they block “Verified” status until discovery.

## Open unknowns

Triage scale and mandatory link to exam; required assessment fields; diagnosis coding timing; exact kelanjutan vocabulary currency; Assesmen (PAR-CLN-001) usage in ED path.
