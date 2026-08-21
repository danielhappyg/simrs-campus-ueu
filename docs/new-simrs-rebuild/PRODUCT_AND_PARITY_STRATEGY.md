# New SIMRS Rebuild: Product and Parity Strategy

Status: proposed product strategy  
Audience: UEU product sponsor, teaching-system owner, hospital-domain leads, delivery team and future users  
Technology stance: deliberately tool- and framework-agnostic

## 1. Decision and intended outcome

Build a new SIMRS from a clean technical foundation. Its first release is a **safe, usable functional-parity system**: users can perform the hospital and teaching workflows represented by the assessed legacy SIMRS, with familiar responsibilities and outcomes. Once this parity release has passed controlled UAT, users may propose and validate changes to improve the experience.

This is not a lift-and-shift, visual clone, database conversion, or promise to reproduce undocumented defects. The legacy system is the functional reference; the new system will have its own maintainable architecture, secure controls, clear versioning and synthetic-data boundary.

The assessed baseline is the vendor SIMRS snapshot of 21 August 2026:

- 268 visible menus across 13 domains, accounted for in the [full menu taxonomy](../vendor-simrs-assessment-2026-08-21/FULL_MENU_TAXONOMY.md).
- The hospital-wide handoffs and evidence labels in the [full workflow model](../vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md).
- Structural route evidence in the [menu route audit](../vendor-simrs-assessment-2026-08-21/MENU_ROUTE_AUDIT.md), not transaction proof.
- Historical descriptions in the 2018 manual map, which are useful but not current specifications: [manual completeness map](../vendor-simrs-assessment-2026-08-21/MANUAL_COMPLETE_MAP.md).

## 2. Evidence language: do not turn assumptions into requirements

Every requirement, design decision, test and backlog item must retain one of these labels.

| Label | Meaning | Treatment in the rebuild |
|---|---|---|
| **Observed legacy behavior (O)** | Directly seen in the authorised 2026 interface or route evidence. | Candidate parity requirement; confirm outcomes in synthetic UAT where it has a transaction effect. |
| **Manual-documented legacy behavior (M)** | Described in the dated 2018 manual. | Candidate parity requirement; confirm with a domain lead because the current implementation may differ. |
| **Inferred behavior (I)** | Plausible workflow inferred from menu names, related routes, or normal hospital operations. | Discovery hypothesis only; it cannot become committed scope without validation. |
| **Unknown (U)** | Rule, field, state, action, integration, ownership or output has not been established. | Create a discovery task and explicit decision gate; never silently invent legacy parity. |
| **Proposed new behavior (P)** | A deliberate improvement or safety/maintainability decision for the new system. | Versioned product decision, visibly separate from parity and approved through change control. |

An item may have more than one evidence source. The confidence and source links belong in the parity matrix, not in informal notes or memory.

## 3. Definition of functional parity

Parity means that an authorised role can complete an equivalent end-to-end business outcome using synthetic data, and that required downstream records, statuses, calculations, documents and handoffs are demonstrably coherent.

It does **not** mean identical screens, URLs, field ordering, source code, technical stack, historical bugs, unsafe permissions, exposed secrets, duplicate module generations, or unproven integrations.

For an individual menu/workflow, parity is reached only when all applicable criteria are satisfied:

1. **Role and access:** the right role can find the capability; a disallowed role is denied server-side.
2. **Input and rules:** required data, validations, defaults and decision rules match validated legacy intent or an approved replacement decision.
3. **State transition:** creation, update, cancellation, correction, reversal, completion and locking behavior are explicitly defined.
4. **Downstream effect:** expected queue, clinical, stock, charge, claim, record, report or audit effect is verified.
5. **Output:** required screen, print/export artifact, calculation and report result is testable.
6. **Traceability:** actor, time, action, before/after values where appropriate and correlation to related records are audit-visible.
7. **Failure path:** validation failure, duplicate, unavailable stock/bed, external failure or insufficient permission has defined safe behavior.

The unit of acceptance is therefore usually a workflow scenario—not a page that merely renders.

## 4. Scope boundaries

### In scope for the parity release

- A coherent, single canonical workflow for the entire assessed functional footprint: registration, care delivery, diagnostics, RMIK, claims, BPJS, pharmacy, warehouse, cashier, reporting, administration, IoT/IBS surfaces and help.
- Familiar menu/domain names where they improve migration and teaching recognition. A menu may consolidate old variants after a recorded canonical-workflow decision.
- All 268 assessed menu capabilities represented in the parity matrix as **build**, **report/output**, **administration**, **integration boundary**, **retired/consolidated**, or **not applicable to the approved teaching configuration**. No menu may disappear without a decision record.
- Synthetic patients, synthetic cases, synthetic master data and resettable teaching scenarios.
- Security, auditability, role separation, privacy, data portability and operational controls that the legacy assessment identified as missing or unproven.
- Sandbox or simulated integration contracts where external services are not authorised or ready.

### Explicitly out of scope for the first parity release

- Copying the legacy application's PHP/jQuery implementation, route structure, database schema, deployment scripts or exposed settings.
- Production patient data, live payer credentials, production SatuSehat credentials, real e-sign certificates or production external endpoints unless separately authorised and verified.
- Exact reproduction of legacy defects, insecure HTTP public displays, broad student permissions, retrievable secrets or undocumented administrative bypasses.
- A data migration from the vendor environment. This needs a separately approved data-migration programme, legal basis, mapping, reconciliation and rollback plan.
- Feature redesign requested by users before parity is accepted, except safety, legal, privacy, security, or blocking usability fixes approved as a parity exception.
- Declaring a production hospital rollout merely because the teaching parity release is complete.

## 5. Product model and canonical domains

The product must be planned around hospital outcomes and shared records, not as 268 isolated pages.

```text
Identity / patient / encounter
        -> care queues and clinical episode
        -> diagnostics, allied care, surgery and medication
        -> record completion / coding / claims / billing
        -> discharge or closure / reports

Cross-cutting: master data, tariffs, access, audit, documents, integrations,
synthetic-data management, support, reporting and operational resilience.
```

| Product domain | Legacy visible footprint | Parity outcome |
|---|---:|---|
| Access and encounter | Pendaftaran (5) | Patient identity, registration, queue, referral/payer context, admission and safe encounter lifecycle. |
| Care delivery | Pemeriksaan (20) | ED, outpatient, inpatient, assessment/triage, diagnostics, allied care, blood, mortuary, ambulance and surgery handoffs. |
| RMIK and interoperability | RM (7) | Completeness, coding, filing/custody, EMR views and controlled exchange boundary. |
| Payer and claims | Klaim (6), BPJS (2) | Claim preparation/grouping/monitoring and payer eligibility workflow, with a sandbox boundary where needed. |
| Pharmacy | Apotek (20), Farmasi IBS (1) | Prescription-to-dispense/return, depot stock movement and theatre-pharmacy linkage. |
| Warehouse | GF (23) | Procurement, receipt, central stock, distribution, stocktake, return and traceable ledgers. |
| Revenue cycle | Kasir (19) | Charges, bills, payment/receivable, settlement, service fees and accounting handoff. |
| Reporting | Laporan (117) | Operational, quality, clinical, pharmacy, finance and statutory-report outputs with an owned definition for each. |
| Administration | Manajemen Data (46) | Master data, tariff/catalogue, roles/users, templates, configurations, logs and operational administration. |
| Connected/help services | IoT (1), Help (1) | Controlled temperature-monitoring boundary and current user/admin guidance. |

Counts are a traceability baseline, not an instruction to reproduce legacy menu fragmentation. `Rawat Jalan`, `Rawat Jalan v2`, `Rawat Inap`, `Rawat Inap v2`, `Operasi`, `Operasi v3`, and EMR IPP coexist in the legacy footprint; a canonical new workflow must be selected before build, with the legacy variants mapped as retained, migrated, consolidated or retired.

## 6. Vertical slices: sequence by usable outcomes

Each slice includes user interface, rules, role checks, audit trail, shared records, reporting effect, negative paths and automated/manual acceptance scenarios. Later slices may start discovery early, but are not accepted before their upstream dependencies are stable.

| Slice | Primary journey | Required end state | Principal parity evidence |
|---|---|---|---|
| 0. Foundation and safe campus boundary | Sign in -> role -> synthetic case -> audit | Named accounts, least privilege, resettable synthetic data, master data, audit events, document/print shell and support baseline | Manajemen Data; assessment security findings |
| 1. Outpatient core | Find/create patient -> register -> queue -> examine -> order/prescribe -> close -> bill | One coherent outpatient episode with records, charges, pharmacy and report trace | Pendaftaran, Pemeriksaan, RM, Kasir, Apotek |
| 2. Emergency core | Register/triage -> ED care -> diagnostics/pharmacy -> disposition | Safe ED episode including admit, referral, discharge and death/DOA decision paths | IGD, Assesmen, Triage, RM, reports |
| 3. Inpatient core | Admit -> bed -> daily care/orders -> transfer -> discharge | Bed lifecycle, ongoing care/charges, record completion and final closure | Rawat Inap, ward, nursing, diet, RM, Kasir |
| 4. Diagnostics and allied care | Order -> schedule/specimen/service -> verify result -> release | Result lifecycle, clinical handoff, billing and traceability | Lab, PA, Mikro, Radiologi, Gizi, rehab, blood |
| 5. Surgery/IBS | Request -> schedule -> theatre care -> recovery -> stock/charge/record | Theatre lifecycle with dedicated pharmacy and specimen/blood interfaces | Operasi, Operasi v3, IBS, Group IBS |
| 6. Pharmacy and warehouse | Prescribe -> screen/dispense -> return; procure -> receive -> distribute -> count | Patient and stock ledgers reconcile across central store, depot and care use | Apotek, GF, Farmasi IBS |
| 7. Claims, BPJS and revenue | Eligibility -> charge/claim -> group -> submission status -> collection/receivable | Controlled payer/claim and revenue lifecycle, with reconciliation state | Klaim, BPJS, Kasir, RM |
| 8. Reporting and operational administration | Execute defined report -> inspect source/period -> export; manage authorized masters | All required report definitions, parameters, ownership, data lineage and administration controls | Laporan, Manajemen Data, IoT, Help |

## 7. Parity backlog: epics and priority rules

Priority is based first on safety and workflow dependency, then teaching/operational value—not the number of old menu entries.

| Priority | Epics | Exit condition |
|---|---|---|
| **P0: foundation / no safe operation without it** | identity and encounter keys; roles/permissions; synthetic-data isolation; audit; master data; tariffs; document/print baseline; canonical-version decisions | A privileged teaching administrator and a student can safely complete only their permitted synthetic work, with reset and audit evidence. |
| **P1: core care and closure** | outpatient; ED; inpatient; RMIK/coding/filing; pharmacy dispensing; core cashier charges/collection | Synthetic patient journeys complete with verified handoffs, reversals/corrections and core reports. |
| **P2: diagnostic and operational completeness** | lab/PA/microbiology; radiology; nutrition; rehabilitation; blood; surgery/IBS; warehouse/procurement; claims/BPJS; remaining cashier | The complete end-to-end clinical, stock and financial lifecycle reconciles under normal and exception scenarios. |
| **P3: reporting, integrations and extended operations** | 117 report definitions; regulatory exports; IoT; external integration adapters; dashboard/management views; current manuals | Every required output has owner, definition, source/period logic, access rule and acceptance evidence. |
| **P4: post-parity improvements** | validated user-requested redesigns, automation, convenience features and non-blocking enhancements | Each change has been triaged after parity UAT and released through normal governance. |

P0 security, privacy, audit and data-isolation work is part of parity—not an optional later hardening phase. A safety/legal control may take precedence over a P1 workflow; it is an approved parity exception rather than a user-experience enhancement.

## 8. Parity-UAT before improvement: the non-negotiable rule

During parity build and parity UAT, participant feedback must be classified into one of four buckets:

1. **Parity defect:** a validated legacy-required outcome is missing, incorrect or unsafe. Fix before parity acceptance.
2. **Evidence gap:** the claimed old behavior is not established. Run discovery and obtain a decision; do not guess.
3. **Mandatory exception:** a legal, privacy, security, safety or teaching-data control requires a safer new behavior. Record rationale and acceptance criteria.
4. **Improvement request:** a user wants a different or better workflow. Log it, acknowledge it, and hold it in the post-parity improvement backlog.

No improvement request becomes committed parity scope simply because it is useful or popular. The improvement discovery/research, prioritisation and delivery cycle starts only after the parity-UAT exit gate has been passed by the product sponsor and domain owners. This prevents the rebuild from becoming an uncontrolled redesign before anyone has verified that the old work can be completed.

## 9. What must be discovered before a parity claim

The browser assessment did not establish the exact field rules, action authorization, posting formulas, locking/correction policy, integration behavior or source schema. The following discovery is mandatory and should be run with synthetic scenarios and named domain owners:

- registration: duplicate merge, unknown/newborn identity, cancellation, queue order, bed locks and transfer;
- clinical: assessment schemas, order/result lifecycle, co-signature, amendments, discharge gates and emergency override;
- diagnostics and theatre: specimen, verification, critical results, scheduling, consent, recovery, implant/blood traceability;
- pharmacy/warehouse: verification, substitution, lots/expiry, partial supply, stock valuation, adjustments, return and reconciliation;
- RMIK/claims/revenue: coding, completeness, grouping, tariff formulas, invoice, receipt, reversal, receivable, settlement and journal rules;
- reporting: business definition, source records, filters, calculation, period close, sign-off, regulatory format and export;
- administration/integration: action-level permission, secret custody, data retention, retry/idempotency/reconciliation, reset, backup/restore and support operation.

Each unknown receives an owner, discovery method, due gate and decision record. A discovery result may validate legacy behavior, establish a new canonical behavior, or formally defer a non-required capability.

## 10. Cross-cutting new-system obligations

These are proposed new-system requirements derived from the assessment findings; they are not claims that the legacy system already performs them.

- A separate teaching environment containing only approved synthetic data, sandboxed/simulated integrations and a documented reset process.
- Named accounts, least privilege, server-side authorization and strong separation between administrator, lecturer, student, clinical, financial and secret-management actions.
- Write-only/masked secret handling; no retrievable integration credentials from ordinary configuration forms.
- Immutable, queryable audit events for view/change/print/export/login/permission/settings/integration/reversal actions as appropriate.
- A single canonical workflow/version per domain, with explicit deprecation decisions instead of parallel `v2`/`v3` ambiguity.
- An auditable correction/reversal model; clinical and financial history must not be silently overwritten.
- Defined data ownership, retention, export/portability, backup/restore test, support, release and incident procedures.
- Secure public information design; no unapproved cleartext HTTP route or unnecessary identifiable display.
- Integration adapters that show environment, ownership, message status, retry, idempotency and reconciliation rather than silently failing.
- Current versioned user, administrator, data-dictionary and operation documentation generated and maintained with the product.

## 11. Measures and acceptance gates

| Gate | Decision question | Minimum evidence |
|---|---|---|
| G0 — Baseline accepted | Do we know what "parity" means? | 268-item parity matrix, workflow map, initial canonical-module decisions, known unknowns, named owners. |
| G1 — Foundation ready | Is the campus environment safe to exercise? | Synthetic-data/reset proof, role denials, audit scenarios, master/tariff baseline, build/release/backup checks. |
| G2 — Slice ready | Does an end-to-end slice work under normal and exception paths? | Scripted synthetic scenarios, output/ledger/report checks, defects triaged, domain-owner sign-off. |
| G3 — Full parity UAT | Can all required roles complete the approved legacy-equivalent work? | Coverage of all in-scope parity items, cross-slice reconciliation, reporting acceptance, security/control evidence, unresolved exceptions accepted. |
| G4 — Improvement discovery open | May user-requested redesign begin? | G3 approval by sponsor and relevant domain owners; improvement backlog separated from parity defects. |
| G5 — Production consideration, if later authorized | Is a real-hospital deployment justified? | Separate production readiness, legal/privacy, integration, performance, DR, migration and operational acceptance programme. |

Useful measures include parity-item coverage by evidence class, scenario pass rate, defect severity/age, orphaned menu count, cross-module reconciliation pass rate, role-denial pass rate, audit-event completeness, report-definition approval rate, and post-parity user improvement themes. None should be used to hide an unresolved safety or data-integrity issue.

## 12. How this strategy is maintained

The product strategy is intentionally stable; detailed scope belongs in the parity matrix and requirements register. Any change to parity definition, scope boundary, canonical workflow, priority gate or the “improvements after parity UAT” rule needs sponsor approval and a linked decision record under [requirements governance](REQUIREMENTS_GOVERNANCE.md).

