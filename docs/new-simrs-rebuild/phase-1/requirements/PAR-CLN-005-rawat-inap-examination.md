# Parity Requirement: PAR-CLN-005 Rawat Inap examination (canonical)

## Control information

- Legacy menu/category: Pemeriksaan / Rawat Inap
- Disposition: **Reproduce** (canonical); PAR-CLN-019 (Rawat Inap v2) consolidates here pending clinical confirmation
- Business owner: Clinical SME TBD (interim product owner Daniel); RMIK consulted on documentation completeness
- Affected actors: ward nurse, physician/learner, supervisors
- Evidence: **Observed** screen/route `/pemeriksaan/rawatinap` (processForm, riwayat, laboratorium posts; 71 inputs); **Manual-documented** Form Input Data Rawat Inap / tab menus (2018); v2 list at `/pemeriksaanv3/rawatinap` (**Observed** headers) — coexistence **Unknown**
- Target milestone: Phase 3 inpatient vertical slice
- Slice pack: [`../INPATIENT_SLICE_DISPOSITIONS.md`](../INPATIENT_SLICE_DISPOSITIONS.md)

## Business outcome

Document inpatient clinical work against the correct admission episode (assessment, diagnoses/problems, visits, orders, prescriptions) through transfer and discharge so RM, pharmacy, diagnostics, and claims receive consistent source records.

## Preconditions and master data

- Inpatient episode exists from PAR-REG-001 with bed/ward context in an openable state (**Unknown** exact gate)
- Actor has ward intake/medical write capability for the teaching assignment
- Terminology catalogues governed (ICD later); UI labels are not codes
- Bangsal / clinic filters available for worklist (**Observed** structural patterns elsewhere)

## Workflow and state transitions

1. Trigger: patient appears on inpatient examination worklist (by bangsal/clinic as configured)
2. Sequence: open episode → nursing/medical documentation → orders/Rx as needed → optional transfer documentation → discharge documentation when ready
3. Resulting state: clinical documentation versions with authors; order statuses; discharge readiness flags (**Unknown** exact statuses)
4. Downstream: pharmacy RI, lab/rad/OR/gizi as ordered, RM RI (PAR-RMIK-002), Kasir RI, claim prep

## Business rules and validation

| Rule ID | Rule | Error behavior | Evidence/source |
|---|---|---|---|
| BR-RICLN-001 | All entries bound to patient+inpatient episode | Reject orphan writes | Proposed NEW / architecture |
| BR-RICLN-002 | Student drafts need supervision per teaching policy | Block finalize without review when policy requires | Proposed NEW (teaching) |
| BR-RICLN-003 | Amendments preserve prior versions | No silent overwrite | Proposed NEW |
| BR-RICLN-004 | Exact clinical form tabs/fields | TBD | Observed processForm — **Unknown**; Manual-documented tabs historic |
| BR-RICLN-005 | Transfer/discharge effects on bed and charges | TBD | Inferred lifecycle — **Unknown** transaction rules |

## Exceptions

- Early discharge / AMA / death: vocabulary and gates **Unknown**; do not invent clinical/legal rules
- Concurrent edit: fail closed with clear conflict — Proposed NEW
- v2 vs classic field divergence: treat as discovery until Consolidate confirmed

## Authorization and audit

Role/capability matrix server-side; audit create/update/approve/amend/transfer/discharge documentation events.

## Acceptance criteria (synthetic)

1. Authorized clinician documents against correct inpatient episode only.
2. Unauthorized role denied with audit.
3. Downstream order/Rx appears for pharmacy/diagnostics actors when those modules are in slice.
4. Discharge documentation can be recorded without inventing unverified charge/bed release automation until discovery closes.
5. Unknown field rules do not block scaffold; they block “Verified” status until discovery.

## Open unknowns

Required ward assessment fields; CPPT / care-plan semantics; MAR; transfer acceptance; discharge summary signature; Rawat Inap v2 vs classic product relationship; EMR IPP RI differences (PAR-RMIK-007).
