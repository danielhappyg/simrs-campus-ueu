# G0 domain-owner appointment pack — 2026-08-25

**Status:** DRAFT FOR PRODUCT/SPONSOR ACTION
**Purpose:** Name accountable owners who can approve the 268 parity dispositions and later G2/G3 outcomes
**Boundary:** Synthetic teaching SIMRS; appointments in this pack do not authorize real data or live integrations

## Why appointments are required

The parity register currently has 252 rows whose Business owner field still contains `TBD`. Daniel may approve product scope, architecture, priorities, synthetic-demo operations, and release coordination, but technical or product authority cannot substitute for professional Clinical, Laboratory, Pharmacy, Finance, or RMIK decisions.

A domain owner approves intended workflow outcomes and acceptance criteria within their authority. Cross-domain consequences require co-approval from the receiving owner; for example, a Clinical owner cannot alone approve stock valuation or financial posting.

## Appointment register

| Authority domain | Minimum decision scope | Current authority | Named person/unit | Appointment evidence/date | Status |
| --- | --- | --- | --- | --- | --- |
| Product sponsor | G0/G1/G3 gate, authorized exclusions and residual risk | Daniel Happy Putra, interim | | DEC/reference: | Interim |
| Product and delivery | Scope, priority, canonical disposition, dependency graph | Daniel Happy Putra | Daniel Happy Putra | DEC-001 | Confirmed for now |
| Registration/admission | Patient access, encounter, queue, correction/cancellation | Daniel Happy Putra, interim | | | Interim |
| Outpatient/ED/inpatient Clinical | Documentation, orders, transitions, clinical closure and safety | TBD | | | **Required for T0/C/D** |
| Nursing | Nursing documentation, supervision, administration boundary | TBD | | | Required |
| Laboratory | Test/order/specimen/result verification and correction | TBD | | | **Required for DEC-016/D** |
| Radiology | Scheduling, imaging result verification/correction, PACS boundary | TBD | | | Required before PAR-CLN-007 build |
| Allied care/blood/surgery | Nutrition, rehabilitation, blood, theatre and related handoffs | TBD | | | Required for D |
| RMIK/coding/reports | Completeness, filing, coding, report definitions and teaching acceptance | RMIK Department | Named delegate: | DEC-011 / delegation: | Organizational owner confirmed |
| Pharmacy | Prescription, dispense/return and pharmacy clinical handoff | TBD | | | Required for E |
| Warehouse/GF | Procurement, receipt, distribution, lot/expiry, stocktake | TBD | | | Required for E |
| Claims/BPJS simulation | Coding/grouping/status simulation and payer boundary | TBD | | | Required for F; live integration excluded |
| Cashier/revenue/finance | Tariff, charge, payment, reversal, receivable, settlement, journals | TBD | | | Required for F |
| Security/privacy/data | RBAC, break-glass, audit, synthetic isolation and exports | Daniel interim; institutional reviewer TBD | | | Required for G1/G3 |
| Operations/recovery | Deployment, backup/restore, rollback, monitoring and support | Daniel Happy Putra, interim | | | Interim |
| Teaching/facilitation | Classroom scenarios, learner boundaries and usability | RMIK Department | Named delegate: | | Organizational owner confirmed |

## Required appointment statement

For each named owner, retain a statement equivalent to:

> I accept accountability for reviewing the listed synthetic teaching workflows and parity dispositions within my professional authority. My approval does not extend to another domain’s clinical, stock, financial, privacy, integration, or operational consequences. I will identify required co-approvers and record approve/revise/reject/defer decisions with evidence and conditions.

Record:

- owner name, position and unit;
- authority domain and decision limits;
- effective date and review/replacement date;
- approving sponsor/product owner;
- conflicts or unavailable expertise; and
- authorized delegate, if any.

## Immediate appointments blocking T0

1. Outpatient/Clinical owner for the bounded nursing/medical Draft/Final fields and encounter transitions.
2. Laboratory owner for FINAL-only result, active-order closure, late-result and correction boundaries.
3. A named RMIK Department delegate for the automatic completeness checklist and sign-off model.

Use the recorded appointments with:

- `STRUCTURED_RJ_RM_OWNER_DECISION_PACK_2026-08-25.md`; and
- DEC-016 in `DECISION_LOG.md`.

## Appointment-to-batch mapping

| G0 batch | Lead owners | Required co-owners |
| --- | --- | --- |
| A Shared controls | Product, security/data, operations | All affected role owners |
| B Patient/encounter | Registration/admission | Clinical, RMIK, finance/payer |
| C Core care/RMIK | Clinical, Nursing, RMIK | Registration, Laboratory, security/data |
| D Diagnostics/allied/surgery | Laboratory/Radiology/allied/surgery | Clinical, RMIK, Pharmacy/GF, Finance |
| E Pharmacy/warehouse | Pharmacy and GF | Clinical, Finance, security/data |
| F Claims/BPJS/revenue | RMIK/coding, claims, Finance | Clinical, Pharmacy/GF, security/data |
| G Reports/public health | RMIK/report owner plus each source-domain owner | Security/privacy, product sponsor |

## Product-owner action record

| Action | Decision / evidence | Date |
| --- | --- | --- |
| Confirm interim appointments that remain valid | | |
| Name or request Clinical owner | | |
| Name or request Laboratory owner | | |
| Record named RMIK delegate | | |
| Name Pharmacy/GF owners before Batch E | | |
| Name Finance/claims owners before Batch F | | |
| Name institutional security/privacy reviewer before G3 or any non-synthetic path | | |

## References

- `docs/new-simrs-rebuild/phase-0/OWNERS_AND_RACI.md`
- `docs/new-simrs-rebuild/phase-0/G0_PARITY_CONTROL_BASELINE_2026-08-25.md`
- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_RM_OWNER_DECISION_PACK_2026-08-25.md`
- `docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md`
- `docs/new-simrs-rebuild/DELIVERY_ROADMAP.md`
