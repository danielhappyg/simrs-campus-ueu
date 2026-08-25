# G0 authority map — 2026-08-25

**Status:** Governance baseline; appointments and row decisions remain open
**Scope:** All 268 assessed SIMRS Sahabat menu capabilities
**Boundary:** Synthetic teaching SIMRS only; this map grants no real-data or live-integration authority

## Headline allocation

The dependency-safe G0 batches account for every parity row:

- **28 Daniel-led rows:** Batch A shared controls (20) and Batch B patient/encounter (8). Daniel may lead these in his recorded product, operations, architecture and interim registration capacities. “Daniel-led” does not mean Daniel-only approval where another domain owns a consequence.
- **240 professional/domain-led rows:** Batches C–G. These require an appointed Clinical, Nursing, RMIK, diagnostic, Pharmacy/GF, claims/Finance, reporting or other affected professional authority. Daniel remains the product approver and delivery coordinator, not a substitute domain owner.

Current matrix ownership is less complete than this target allocation: only 16 rows have a Business owner field without `TBD` (five Daniel-led registration rows and eleven RMIK Department rows); 252 rows still contain an owner placeholder. A candidate, interim or organizational owner is not an appointed delegate, and an appointment is not an approved parity disposition.

## Authority by decision batch

| Batch and row count | Valid lead authority | Daniel's valid capacity | Minimum co-approval |
| --- | --- | --- | --- |
| **A. Shared controls — 20** | Product, security/data and operations | Product/architecture/operations lead; interim security authority for the synthetic boundary | Security/data and affected role/teaching owners; sponsor for material exception, exclusion or residual risk |
| **B. Patient/encounter — 8** | Registration/admission | Interim registration/admission lead | Clinical, RMIK and finance/payer when their downstream state is affected; security/data for identity or access controls |
| **C. Core care/RMIK — 19** | Clinical, Nursing and RMIK | Product priority, technical coordination and release | Product plus Registration, Laboratory and security/data where affected |
| **D. Diagnostics/allied/surgery — 19** | Laboratory, Radiology, allied-health, blood-bank and surgery/service authorities | Product sequencing, simulation boundary and operations | Clinical and RMIK; Pharmacy/GF and Finance where stock or charge effects exist; security/data for results or integration boundaries |
| **E. Pharmacy/warehouse — 48** | Pharmacy and GF/warehouse | Product and operations coordination | Clinical, Finance and security/data |
| **F. Claims/BPJS/revenue — 34** | RMIK/casemix, claims/BPJS-simulation and Finance/cashier | Product scope and non-live simulation boundary | Clinical, Pharmacy/GF and security/data; integration owner for any approved sandbox adapter |
| **G. Reports/public health — 120** | RMIK/reporting-quality lead plus each source-domain owner | Product sponsor for inclusion, consolidation or teaching exclusion | Every affected source-domain owner plus security/privacy; sponsor for statutory-style output or exclusion |
| **Total — 268** |  | **28 Daniel-led / 240 professional-domain-led** |  |

## Minimum approval rules

Every row needs two recorded decision capacities:

1. **Product/business authority** for scope, priority, canonical disposition, consolidation, retirement, deferral or teaching exclusion.
2. **Affected domain authority** for professional or operational correctness and testable acceptance criteria.

Additional co-approval is mandatory when a decision changes another owner's state:

- Clinical or medical-record consequence: Clinical and/or RMIK.
- Medicine, supply or stock consequence: Pharmacy/GF.
- Tariff, charge, payment, claim or journal consequence: Finance/claims.
- Role, data, audit, export, retention, external service or trust-boundary consequence: security/data and technical operations.
- Cross-domain report: reporting lead plus every source-domain owner.

One person may hold multiple capacities in the small teaching programme, but each capacity, limit and decision must remain explicit. Technical evidence, code availability and deployment do not approve an unresolved clinical, financial, stock, privacy or parity rule.

## Appointment sequence

### T0 — immediate bounded decisions

1. Confirm Daniel's interim product, release and synthetic-operations authority and its limits.
2. Appoint an Outpatient Clinical owner for Structured RJ/RM fields, Draft/Final meaning and encounter transitions.
3. Appoint a Laboratory owner for DEC-016 result/order/closure policy.
4. Record a named RMIK Department delegate for the completeness checklist and sign-off model.
5. Use those recorded authorities for the focused hosted UAT evidence review and the owner decisions; do not infer acceptance from appointment alone.

### G0 batches A–G

1. **A:** confirm product/operations authority; appoint or confirm security/data and affected role/teaching authorities.
2. **B:** confirm Registration/admission authority; add Clinical, RMIK, finance/payer and security/data co-owners.
3. **C:** appoint Clinical, Nursing and RMIK authorities; reuse T0 appointments only within their recorded scope.
4. **D:** appoint Laboratory, Radiology, allied, blood and surgery/service authorities; add downstream Clinical/RMIK/Pharmacy/GF/Finance co-owners as applicable.
5. **E:** appoint Pharmacy and GF/warehouse authorities, with Clinical, Finance and security/data limits recorded.
6. **F:** appoint claims/BPJS-simulation and Finance/cashier authorities; retain RMIK/casemix and the no-live-integration boundary.
7. **G:** appoint a reporting/quality coordinator and bind every report to its source-domain owner before formula, control-total or exclusion approval.

Appointments may be prepared in parallel, but row approval follows the dependency order `A → B → C → D → E → F → G`. Report discovery may start earlier; report approval waits for settled source workflows.

## Status vocabulary

| Term | Meaning | Does not mean |
| --- | --- | --- |
| Candidate | Proposed person or unit awaiting recorded authority | Appointed or permitted to approve |
| Interim | Temporarily covering a named capacity within recorded limits | Permanent institutional authority or authority outside that domain |
| Appointed | Name/unit, scope, limits, effective date and appointing authority are recorded | The 268 row decisions are approved |
| Organizational owner | A unit is accountable, but a delegate may still be required for attributable action | A named individual has signed |
| Approved row | Product and applicable domain authorities approved the disposition, target and acceptance path with evidence | Implemented, verified or parity accepted |

## Governing artifacts

- [G0 parity-control baseline](G0_PARITY_CONTROL_BASELINE_2026-08-25.md)
- [G0 owner appointment pack](G0_OWNER_APPOINTMENT_PACK_2026-08-25.md)
- [Owners and RACI](OWNERS_AND_RACI.md)
- [Parity requirements matrix](../PARITY_REQUIREMENTS_MATRIX.md)
- [Requirements governance](../REQUIREMENTS_GOVERNANCE.md)
- [Delivery roadmap](../DELIVERY_ROADMAP.md)
