# New SIMRS Rebuild: Delivery Roadmap

Status: proposed, outcome-based roadmap  
Technology stance: tool-, vendor- and framework-agnostic

## 1. Roadmap purpose

This roadmap delivers a clean-slate SIMRS with first-release functional parity to the assessed legacy platform, then creates a disciplined route for user-led improvement. It is deliberately not a calendar promise: duration depends on validated rules, available domain owners, report/integration complexity and UAT results. A phase completes through evidence and sign-off, not because a date arrived.

The functional source is the 268-item [menu taxonomy](../vendor-simrs-assessment-2026-08-21/FULL_MENU_TAXONOMY.md) and the [end-to-end workflow model](../vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md). The legacy assessment proves visible scope and some historical intent; it does not prove all rules or transactions. This roadmap protects that distinction.

## 2. Delivery principles

- Deliver vertical patient/service journeys, not disconnected screens.
- Treat every legacy menu as a traceability item, while allowing approved consolidation into one canonical new workflow.
- Build safety, audit, data isolation, roles, correction/reversal and reporting lineage inside each slice.
- Use only synthetic, controlled cases until a separate production programme is authorised.
- Demonstrate normal, failure and correction paths before accepting a slice.
- Freeze non-essential redesign requests during parity build. Record them for the post-parity backlog; do not discard user insight.
- Keep observed, manual-documented, inferred, unknown and proposed behavior visibly distinct in every plan and test.

## 3. Roadmap at a glance

```text
0 Baseline and governance
  -> 1 Discovery and canonical design
  -> 2 Safe platform foundation
  -> 3 Outpatient and ED core
  -> 4 Inpatient and shared clinical services
  -> 5 Pharmacy, warehouse, claims and revenue
  -> 6 Reporting, integration boundaries and administration completion
  -> 7 Full parity UAT and release decision
  -> 8 User-led improvement discovery and iterative releases
```

Work may overlap only when dependencies permit. For example, report definition discovery can begin early, but a report cannot be accepted until its source workflow and calculation rules are validated.

## 4. Phases, outputs and gates

| Phase | Objective | Core outputs | Exit gate |
|---|---|---|---|
| 0. Baseline and governance | Turn the assessment into controlled product scope. | 268-item parity matrix; requirement IDs; evidence labels; ownership map; risk/dependency register; initial scope decision. | G0: sponsor accepts parity definition, scope and governance. |
| 1. Discovery and canonical design | Convert uncertain legacy intent into testable workflow decisions. | Field/action/state/output discovery records; canonical decisions for v2/v3/EMR variants; domain glossary; shared conceptual data model; scenario catalogue; report/integration inventory. | Each P0/P1 unknown has a decision, owner, due gate or explicit deferral. |
| 2. Safe platform foundation | Establish a reusable, safe environment before clinical/financial work. | Synthetic-data factory and reset; named identity/RBAC; audit; master data; tariff/catalogue baseline; document/print approach; error/support/release/backup procedures. | G1: roles, isolation, audit and reset scenarios pass. |
| 3. Ambulatory and emergency core | Make the first complete care journeys usable. | Outpatient registration-to-closure; triage/ED disposition; core orders, prescriptions, charges, cashier handoff, RMIK handoff; core outputs. | G2 for outpatient and ED scenarios, including cancellation/duplicate/denial paths. |
| 4. Inpatient and shared clinical services | Extend care into admission, wards and care-support workflows. | Admission/bed/transfer/discharge; inpatient documentation and charges; lab, radiology, nutrition, rehab and blood workflow boundaries; surgery/IBS discovery/build as approved. | G2 for inpatient and required support-service scenarios; bed, result and discharge reconciliation pass. |
| 5. Medication, inventory, claims and revenue | Close stock and financial value chains. | Dispense/return; warehouse procurement/receipt/distribution/stocktake; IBS pharmacy; claim/BPJS workflow; invoice, receipt, receivable, settlement and journals. | Stock, charge, claim and cash ledgers reconcile for approved scenarios and exceptions. |
| 6. Reporting, integrations and administration completion | Complete control, output and operational surfaces. | 117 report-definition register and approved outputs; administration capabilities; help/runbooks; integration adapters or safe simulators; IoT boundary; data export and operational evidence. | All in-scope menu mappings have an accepted outcome, approved consolidation/retirement, or an authorised teaching exclusion. |
| 7. Full parity UAT | Prove the whole approved system works together. | Cross-domain synthetic UAT; accessibility/usability observations; defect and evidence-gap closure; user/admin documentation; release candidate evidence. | G3: formal parity UAT acceptance. |
| 8. Improvement programme | Let users redesign based on hands-on experience. | Improvement research, prioritised product backlog, experiments/prototypes, iterative releases and outcome measurement. | G4 has already been passed; changes follow normal change control. |

## 5. Epic roadmap and dependencies

| Epic | Priority | Depends on | Demonstrates |
|---|---|---|---|
| E01 Product evidence and parity matrix | P0 | assessment sources | Every menu, workflow and unknown is traceable. |
| E02 Synthetic environment and data lifecycle | P0 | E01 | Teaching cases are safe, repeatable and resettable. |
| E03 Identity, RBAC and audit | P0 | E01 | Roles can do only authorised actions and activities are traceable. |
| E04 Shared masters, tariffs and documents | P0 | E01, E03 | Common clinical, financial and printing semantics are controlled. |
| E05 Patient/encounter/queue | P1 | E02–E04 | Identity and encounter are one reliable shared foundation. |
| E06 Outpatient care and closure | P1 | E05 | Outpatient treatment propagates safely to record, medication and revenue. |
| E07 Emergency and triage | P1 | E05, E06 components | Emergency paths can safely close, admit or refer. |
| E08 Inpatient/bed/discharge | P1 | E05, E04 | Admission through discharge is coherent. |
| E09 Diagnostics/allied/blood | P2 | E05–E08 | Orders/results/services are tracked and posted. |
| E10 Surgery and IBS | P2 | E08, E09, E12 | Theatre care, supplies and results reconcile. |
| E11 Pharmacy dispensing | P1/P2 | E04–E07 | Prescription, stock and charge effects agree. |
| E12 Warehouse/procurement | P2 | E04, E11 | Stock source, movement and count are traceable. |
| E13 RMIK/coding/claims/BPJS | P1/P2 | E05–E09 | Complete records and payer status can be managed. |
| E14 Cashier/revenue | P1/P2 | E04–E13 | Charges, payments, receivables and reversals reconcile. |
| E15 Reports and regulated outputs | P3 | source epics | Each output has approved business/data definition. |
| E16 Admin, help, operations and integrations | P0/P3 | E02–E15 as relevant | The system is operable, supportable and safe at its boundaries. |

P1/P2 labels may be split into smaller releases, but downstream financial, stock, claim and report handoffs must remain internally consistent for every released scenario.

## 6. Phase 0: the required parity matrix

Create one controlled register with one row per legacy menu capability plus rows for shared workflow actions that do not appear as a menu. At minimum, each row records:

| Field | Required content |
|---|---|
| Parity ID | Stable identifier, e.g. `PAR-REG-001`. |
| Legacy domain/menu/route | Exact assessed label and link to taxonomy/route evidence. |
| Capability and actor | Outcome, primary actor and authorised roles. |
| Evidence class | O, M, I, U or P, including date/source/reference. |
| New canonical destination | Epic/slice/workflow; retained, consolidated, simulated, excluded or retired decision. |
| Rules and states | Fields, validations, transitions, corrections, dependencies and exceptions. |
| Outputs and downstream effects | Documents, ledger/report/queue/integration consequences. |
| Acceptance scenario | Synthetic test ID, expected result and responsible domain owner. |
| Status | discovered, specified, built, verified, accepted, deferred or retired. |
| Decision/risk links | Evidence gap, change request, exception, dependency and sign-off references. |

The 117 report items deserve their own linked report-definition register; a report label alone is not a requirement. Define its owner, intended use, access restriction, source entities, parameters, calculation, period rule, validation/control total, format and retention.

## 7. Mandatory UAT scenario families

Each family contains normal and exception scenarios, and uses non-identifying synthetic people/cases.

1. Identity and registration: new/existing/duplicate/unknown patient, payer/referral, cancellation, queue and encounter correction.
2. ED: triage, assessment, order, prescription, observation/disposition, referral, admission and death/DOA where in scope.
3. Outpatient: appointment/arrival, consultation, orders/results, prescription, referral, close and bill.
4. Inpatient: admit, bed change, daily care/order, diet/medication, procedure, discharge and bed release.
5. Diagnostics/allied: order, specimen/service, result verification/correction/release, clinical acknowledgement and charge.
6. Surgery/IBS/blood: request, scheduling, consent checkpoint, theatre supplies, recovery, specimen/blood linkage and close.
7. Pharmacy/warehouse: stock availability, dispense, partial/return, receive, distribute, stocktake, expiry/lot where required and reconciliation.
8. RMIK/claims/revenue: completeness, coding, grouping, claim status, charge changes, payments, reversals, receivables and settlement.
9. Reporting: source-to-report reconciliation, period filter/close, role access, export/print and regulatory output where authorised.
10. Administration/control: login/logout, role denial, user lifecycle, master/tariff effective dating, audit evidence, document templates, reset, backup restore and integration failure/retry simulation.

## 8. Gate evidence and sign-off

| Gate | Accountable decision maker | Required contributors | Evidence retained |
|---|---|---|---|
| G0 baseline | Product sponsor | Product lead, RMIK/clinical/finance/pharmacy leads, teaching owner | Scope, parity matrix, risk list, owners. |
| G1 safe foundation | Product sponsor + security/data owner | Technical lead, teaching owner, role owners | Synthetic/reset proof, RBAC matrix/test, audit/control test, operating runbook. |
| G2 slice acceptance | Relevant domain owner | Product lead, testers, cross-domain recipients | Scripted scenario runs, output/ledger reconciliation, defect disposition. |
| G3 full parity UAT | Product sponsor | All domain owners, teaching owner, technical/security/data owners | Complete coverage report, cross-flow UAT, accepted exceptions, current docs. |
| G4 improvement start | Product sponsor | Product lead and user-research owner | Approved parity result and separated improvement backlog. |

A domain owner cannot sign off a downstream accounting, privacy or stock reconciliation impact outside their authority; it requires the co-signature of the responsible owner.

## 9. Handling blockers and incomplete evidence

If a legacy behavior cannot be established, select one of the following explicitly:

- obtain evidence through read-only observation or a synthetic demonstration;
- obtain a domain-owner decision on the intended canonical behavior;
- provide a safe simulation for teaching with the limitations displayed;
- defer it from the approved parity baseline; or
- retire/consolidate it with impact analysis, user communication and sponsor approval.

“The page exists,” “the old manual says so,” or “a developer thinks it should work this way” is not acceptance evidence. Known vendor-system risks—especially retrievable secrets, broad student access, unclear auditability, unclear module versions and unproven integration/restore behavior—must be designed out, not carried forward.

## 10. After G3: user-led improvement programme

Only after full parity UAT acceptance do users begin structured improvement discovery. Collect observations while they perform real teaching scenarios, but classify them as future improvements unless they are a parity defect or mandatory safety exception.

The improvement cycle is:

```text
User observation -> research/measure -> problem statement -> prioritisation
-> prototype or safe experiment -> acceptance criteria -> release -> outcome review
```

Prioritise by patient/data safety, teaching value, operational impact, frequency, evidence strength, dependency and effort. Preserve parity traceability so a future redesign never breaks the established core workflow without an approved change.

## 11. Roadmap risks to actively manage

- Treating 268 menu links as 268 independently complete functions.
- Parallel legacy versions becoming parallel new-system implementations.
- Building reports before agreeing their definitions and source data.
- Connecting live external systems or importing real data into a teaching environment.
- Deferring audit, roles, synthetic reset, correction/reversal or reconciliation until the end.
- Mixing early user ideas with parity defects and expanding scope before UAT.
- Sign-off by people who did not execute the end-to-end scenarios.

The [requirements governance guide](REQUIREMENTS_GOVERNANCE.md) is the control mechanism for these risks.

