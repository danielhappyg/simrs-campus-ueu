# G0 domain-owner appointment pack — 2026-08-25

**Status:** DRAFT FOR PRODUCT/SPONSOR ACTION
**Purpose:** Name accountable owners who can approve the 268 parity dispositions and later G2/G3 outcomes
**Boundary:** Synthetic teaching SIMRS; appointments in this pack do not authorize real data or live integrations

## Why appointments are required

The parity register currently has 252 rows whose Business owner field still contains `TBD`. Daniel may approve product scope, architecture, priorities, synthetic-demo operations, and release coordination, but technical or product authority cannot substitute for professional Clinical, Laboratory, Pharmacy, Finance, or RMIK decisions.

A domain owner approves intended workflow outcomes and acceptance criteria within their authority. Cross-domain consequences require co-approval from the receiving owner; for example, a Clinical owner cannot alone approve stock valuation or financial posting.

The [G0 authority map](G0_AUTHORITY_MAP_2026-08-25.md) assigns every row to a decision batch: **28 rows are Daniel-led** through shared-control and patient/encounter responsibilities, while **240 rows require a professional/domain lead**. Daniel-led is not Daniel-only: affected clinical, RMIK, stock, financial, privacy or integration consequences still require their owners.

Appointments and approvals are separate events. A blank candidate, an interim cover, or an organizational owner without a delegate must not be represented as a named appointment. Naming an owner also does not approve any matrix disposition.

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

| G0 batch | Rows | Lead owners | Required co-owners |
| --- | ---: | --- | --- |
| A Shared controls | 20 | Product, security/data, operations | All affected role/teaching owners; sponsor for material risk or exclusion |
| B Patient/encounter | 8 | Registration/admission | Clinical, RMIK, finance/payer; security/data for identity/access |
| C Core care/RMIK | 19 | Clinical, Nursing, RMIK | Product, Registration, Laboratory, security/data |
| D Diagnostics/allied/surgery | 19 | Laboratory/Radiology/allied/surgery | Product, Clinical, RMIK, Pharmacy/GF, Finance; security/data where applicable |
| E Pharmacy/warehouse | 48 | Pharmacy and GF | Product, Clinical, Finance, security/data |
| F Claims/BPJS/revenue | 34 | RMIK/coding, claims, Finance | Product, Clinical, Pharmacy/GF, security/data |
| G Reports/public health | 120 | RMIK/report owner plus each source-domain owner | Product sponsor and security/privacy |
| **Total** | **268** | **28 Daniel-led / 240 professional-domain-led** | Cross-domain effects always retain their owner |

## Practical appointment order

1. **T0:** record the Outpatient Clinical owner, Laboratory owner and named RMIK delegate, then use the appointments for the focused evidence review and decisions.
2. **A:** confirm product/operations and security/data authority plus affected role owners.
3. **B:** confirm Registration/admission and its Clinical/RMIK/finance/security co-owners.
4. **C:** record Clinical, Nursing and RMIK authority for the core care/RM model.
5. **D:** add Laboratory, Radiology, allied, blood and surgery/service authorities.
6. **E:** add Pharmacy and GF/warehouse authorities.
7. **F:** add claims/BPJS-simulation and Finance/cashier authorities.
8. **G:** name the reporting coordinator and source-domain owner for each report.

Appointments may be collected in parallel, but parity decisions follow `A → B → C → D → E → F → G`. See the authority map for limits and minimum approval rules.

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

- [G0 authority map](G0_AUTHORITY_MAP_2026-08-25.md)
- [Owners and RACI](OWNERS_AND_RACI.md)
- [G0 parity-control baseline](G0_PARITY_CONTROL_BASELINE_2026-08-25.md)
- [Structured RJ/RM owner decision pack](../phase-1/STRUCTURED_RJ_RM_OWNER_DECISION_PACK_2026-08-25.md)
- [Parity requirements matrix](../PARITY_REQUIREMENTS_MATRIX.md)
- [Delivery roadmap](../DELIVERY_ROADMAP.md)
