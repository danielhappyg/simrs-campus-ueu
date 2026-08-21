# Master Blueprint: Clean-Slate SIMRS Reconstruction and Evolution

## 1. Executive direction

UEU will build a new teaching-oriented SIMRS from a clean technical foundation. The first product objective is **functional parity with the old vendor system**, sufficient for lecturers and representative users to perform and teach the same coherent hospital workflows. Only after parity is accepted will the program treat user-requested workflow changes and new capabilities as product improvements.

The strategy deliberately separates four things:

1. **Legacy evidence:** what the old system visibly does or historically documents.
2. **Parity requirement:** old behavior UEU deliberately chooses to reproduce.
3. **Foundation improvement:** security, privacy, audit, architecture and reliability that must be better from the beginning even if users do not see it.
4. **Post-parity improvement:** a user-requested change evaluated after users try the parity system.

The old system is therefore an **acceptance oracle for familiar behavior**, not a source-code, database or security template.

## 2. Why this approach

Starting immediately with redesign workshops would mix three different conversations: what the old system really does, what is defective, and what users imagine they want. A parity-first system creates a shared, testable reference. Users can then react to a working product rather than screenshots or abstract questions.

This reduces four risks:

- losing important hospital handoffs while simplifying screens;
- reproducing menu labels without the downstream posting behavior;
- accepting contradictory requests from different departments;
- making architecture decisions from a legacy UI rather than long-term needs.

## 3. Baseline evidence

The authenticated assessment captured 13 categories and 268 visible menu items spanning the complete hospital lifecycle. Supporting evidence is under `../vendor-simrs-assessment-2026-08-21/`.

| Legacy domain | Menus | New-system planning interpretation |
|---|---:|---|
| Pendaftaran | 5 | patient identity, visit/admission and queue context |
| Pemeriksaan | 20 | emergency, outpatient, inpatient, diagnostic, allied, surgery and support care |
| RM | 7 | record completion, coding, filing, claims monitoring and exchange |
| Klaim | 6 | coding/grouping, claim preparation, plafond and monitoring |
| Laporan | 117 | operational, clinical, regulatory, quality and management outputs |
| BPJS | 2 visible | payer/service workflows plus additional permission nodes |
| Apotek | 20 | prescription fulfillment, depot inventory and returns |
| GF | 23 | purchasing, receipt, central stock, distribution and reconciliation |
| Kasir | 19 | billing, payment, receivables, deposits and revenue reporting |
| Manajemen Data | 46 | users, roles, master data, tariffs, documents, settings and logs |
| IoT | 1 | temperature monitoring surface |
| Farmasi IBS | 1 | operating-theatre pharmacy surface |
| Help | 1 | user documentation |
| **Total** | **268** | complete parity traceability baseline |

The assessment does not establish every hidden rule, field validation, report formula, posting, integration behavior or database relationship. Those items remain discovery tasks and cannot be guessed into the build.

## 4. Program outcomes

### 4.1 Parity outcome

A lecturer or representative departmental user can complete the approved synthetic workflows that the old system supports, using familiar terminology and producing equivalent operational outputs, while the new platform supplies safer authorization, audit, data handling and reliability.

### 4.2 Teaching outcome

Lecturers can create controlled cohorts and synthetic scenarios, students receive time-bounded course-specific access, and exercises can be reset or replayed without exposing operational credentials or personal health data.

### 4.3 Evolution outcome

After parity, UEU has an evidence-based product process for improving workflows, adding health-program modules, modifying reports and integrating other study programs without destabilizing the core platform.

### 4.4 Ownership outcome

UEU retains documented control over requirements, configuration, data export, integration mappings, acceptance tests, deployment evidence and vendor/technology replacement options.

## 5. Scope boundaries

### In scope for the parity program

- all 268 legacy menu items as classification and traceability entries;
- approved end-to-end emergency, outpatient and inpatient journeys;
- diagnostics, allied health, surgery/IBS and mortuary/ambulance handoffs;
- pharmacy, warehouse and stock-ledger workflows;
- RM completion, coding, filing, claim and reporting workflows;
- cashier, receivable, settlement and management reporting;
- users, roles, master data, tariffs, templates, logs and settings;
- sandbox-capable BPJS, SATUSEHAT, E-Klaim/iDRG and other required integration adapters;
- teaching administration, synthetic data, cohort reset and assessment evidence;
- security, privacy, audit, operations, backup and recovery controls.

### Not automatically in parity scope

- defects, unsafe behavior or broken external routes in the old system;
- duplicate legacy/v2/v3 implementations without an approved canonical choice;
- undocumented report formulas presented as regulatory truth;
- production integration credentials or real patient datasets;
- new user requests that do not restore approved legacy behavior;
- speculative AI, predictive or automation features without a separately approved purpose and evaluation plan.

## 6. Product principles

1. **Workflow before screens.** Build complete handoffs and state changes, not a collection of menu pages.
2. **Patient and encounter context everywhere.** Every clinical, financial, stock and claim transaction must identify the correct subject and episode.
3. **One authoritative state.** Avoid duplicate implementations writing inconsistent versions of the same record.
4. **Configuration over forks.** Course, unit and report variation should use governed configuration when safe.
5. **API- and event-capable boundaries.** Modules must integrate through documented contracts even if initially deployed together.
6. **Secure and auditable by default.** Authorization, audit, secret protection and safe correction are foundational parity requirements.
7. **Synthetic-first teaching.** Teaching workflows never depend on production data or credentials.
8. **Evidence-based delivery.** A feature is not complete until behavior, tests, documentation and deployment state agree.
9. **Reversible change.** Corrections, reversals, releases and migrations require recovery paths.
10. **Tool independence.** Requirements and acceptance criteria remain valid if the implementation stack changes.

## 7. Target user and responsibility groups

| Group | Core responsibilities in the new system |
|---|---|
| Student | performs only assigned synthetic workflow steps within a cohort and time window |
| Lecturer–administrator | manages teaching cases, cohorts, approved master data, exercises and evaluation |
| Registration/front office | identity, booking, registration, admission and queue context |
| Clinicians and nurses | assessment, orders, diagnoses, procedures, care documentation and disposition |
| Diagnostic/allied services | accept orders, perform services, verify results and close work |
| Surgery/IBS | schedule, document, consume stock and complete peri-operative workflow |
| Pharmacy/depot | validate prescriptions, dispense/return and maintain depot ledger |
| Central pharmacy warehouse | procure, receive, batch/expiry control, distribute and reconcile stock |
| RMIK/casemix | completeness, coding, filing, claims, exchange and reporting quality |
| Cashier/finance | bills, payments, receivables, reversals, settlement and revenue reconciliation |
| Technical administrator | environments, integrations, secrets, deployment, monitoring and recovery |
| Product/governance group | scope, decisions, prioritization, risk and acceptance |

One person may hold several roles in a campus setting, but permissions and audit identity must remain explicit.

## 8. Target capability structure

The new platform should be organized by durable capabilities rather than copying the legacy menu hierarchy into code.

```text
Identity and access
  -> Teaching administration and cohorts
  -> Patient identity and encounter management
  -> Queue, referral, admission and bed management
  -> Clinical documentation and orders
  -> Diagnostics and allied care
  -> Surgery/IBS and special services
  -> Pharmacy dispensing
  -> Procurement and inventory
  -> Medical record, coding and filing
  -> Claims and payer workflows
  -> Billing, cashier and revenue
  -> Reporting, quality and regulatory outputs
  -> Integration gateway
  -> Master data and configuration
  -> Audit, observability and operations
```

These capabilities may initially run as a modular monolith, services, or another architecture. The contracts and ownership boundaries matter more than the deployment style.

## 9. Delivery sequence

### Phase 0 — Program foundation

Establish product ownership, decision rights, environments, repository/release governance, evidence labels, risk process and documentation source of truth.

Exit gate: named owners, approved scope method, clean development baseline and no unknown production data or credentials.

### Phase 1 — Legacy parity specification

Classify every menu as reproduce, consolidate, retire, replace or pending evidence. Capture actors, fields, rules, states, outputs, reports, integrations, permissions and failure/correction behavior.

Exit gate: every menu has an owner and disposition; priority vertical slices have approved acceptance tests; unknowns have an evidence plan.

### Phase 2 — Secure platform foundation

Implement identity, RBAC/action policy, cohorts, patient/encounter identifiers, master-data governance, audit, secret management, environments, observability, backup and deployment controls.

Exit gate: foundation security and recovery tests pass before clinical workflows rely on it.

### Phase 3 — Core vertical slices

Deliver complete outpatient, emergency and inpatient flows through clinical completion, pharmacy/diagnostics, RM, claim, billing and reporting. A slice is incomplete if downstream postings or audit evidence are absent.

Exit gate: approved synthetic cases reconcile end-to-end and parity defects are within the agreed threshold.

### Phase 4 — Extended hospital capabilities

Add rehabilitation/allied care, surgery/IBS, blood bank, mortuary, ambulance, advanced pharmacy/warehouse, specialized claims and remaining reports/master data.

Exit gate: all approved parity dispositions are implemented, intentionally consolidated or formally deferred.

### Phase 5 — Integration and operational readiness

Validate adapters in sandbox, report lineage, performance, security, backup/restore, disaster recovery, training and support.

Exit gate: release candidate meets technical, security, operational and user acceptance criteria.

### Phase 6 — Parity pilot

Representative users run defined synthetic scenarios. Defects are classified as parity, safety/control, usability or improvement. Only parity and safety/control items block the parity gate.

Exit gate: product owner and departmental owners sign the parity acceptance record.

### Phase 7 — User-led evolution

Open structured discovery and improvement cycles. Users may now propose workflow changes based on real use of the parity product. Changes are prioritized by safety, teaching value, regulatory need, operational impact, evidence and cost.

## 10. Vertical-slice definition

Each vertical slice must include:

- actors and permissions;
- prerequisites and master data;
- happy path and state transitions;
- duplicate, invalid, cancellation, correction and reversal cases;
- downstream clinical, stock, financial, claim and report effects;
- audit and notification events;
- print/export outputs;
- integration behavior and offline/failure handling;
- synthetic test data;
- measurable acceptance criteria;
- user and operational documentation.

## 11. Parity decision taxonomy

Every old-system item receives one disposition:

| Disposition | Meaning |
|---|---|
| Reproduce | preserve recognizable behavior and output |
| Consolidate | combine duplicate old variants behind one canonical workflow |
| Replace | meet the same business outcome with an approved safer/current mechanism |
| Retire | intentionally omit an unused, unsafe or obsolete function |
| Pending evidence | do not build until behavior or necessity is demonstrated |

Every decision needs an owner, rationale, evidence, acceptance consequence and approval date.

## 12. Parity versus improvement classification

| Classification | Example | Handling before parity gate |
|---|---|---|
| Parity defect | a legacy-approved prescription cannot reach pharmacy | fix before acceptance |
| Safety/control defect | student can change production-like settings | fix before acceptance even if old system allowed it |
| Usability defect | label is unclear but work can complete | prioritize by impact; may be fixed before gate |
| Improvement | user wants a new dashboard or fewer approval steps | record separately; normally schedule after parity |
| Regulatory update | old report/form no longer matches current standard | replace old behavior with current approved requirement |
| Unknown legacy behavior | cancellation consequences were never observed | evidence task, not guessed implementation |

## 13. Tool-selection principles

UEU may choose different implementation tools. Evaluate candidates against the blueprint rather than changing the blueprint to fit a tool.

Required capabilities include:

- strong transactional integrity and relational data support;
- mature authentication and action-level authorization;
- immutable audit and observable background work;
- documented APIs and integration libraries;
- safe migrations and repeatable automated testing;
- maintainable frontend forms, tables, printing and accessibility;
- environment and secret separation;
- backup/export portability;
- deployability within UEU budget and operational capability;
- available skills and support in the UEU/vendor team.

Avoid selecting a tool primarily from prototype speed, visual attractiveness or a single developer's familiarity.

## 14. Governance and decision rights

| Decision | Accountable | Required consultation |
|---|---|---|
| Product scope and parity disposition | Product owner | lecturers, department owners, analyst, architect, QA |
| Clinical workflow/safety rule | authorized clinical/RMIK owner | affected professions, privacy/security, QA |
| Architecture and technology | Technical/architecture owner | product, security, operations, data |
| Data classification and migration | Data owner | privacy, product, technical, affected department |
| Integration environment/activation | Integration owner | security, data owner, product, operations |
| Release acceptance | Product owner and technical release owner | QA, security, operations, user representatives |
| Post-parity improvement priority | Product governance group | requestors, affected departments, delivery team |

No single developer or lecturer should silently determine clinical, financial, privacy or regulatory behavior.

## 15. Quality gates

### Requirement ready

- source and evidence label identified;
- actor, precondition, rule, state and output defined;
- unknowns and safety implications resolved or explicitly blocked;
- acceptance criteria and synthetic data specified;
- owner approved.

### Development complete

- code/configuration reviewed;
- automated tests pass;
- authorization and audit tests pass;
- documentation and traceability updated;
- no secret or personal data committed;
- migration and rollback considered.

### Release candidate

- end-to-end UAT passes;
- representative reports reconcile;
- security and dependency checks pass;
- performance targets pass;
- backup and restore evidence is current;
- release/rollback runbooks tested;
- known limitations approved.

### Parity accepted

- all approved parity requirements are delivered, consolidated or explicitly deferred;
- no open critical/high safety or security issue;
- departmental owners sign representative journeys;
- operational and teaching guides are usable;
- improvement backlog is separate and prioritized only after sign-off.

## 16. Success measures

### Parity measures

- percentage of 268 menu items with approved disposition;
- percentage of parity requirements with passing acceptance tests;
- end-to-end reconciliation success by vertical slice;
- open parity defects by severity;
- report lineage/reconciliation coverage.

### Safety and quality measures

- authorization negative-test pass rate;
- audit-event coverage for high-impact actions;
- secret exposure incidents;
- synthetic-data policy violations;
- restore-test success and measured RPO/RTO;
- performance and error-budget compliance.

### Adoption measures after parity

- task completion and error rate by user group;
- time to complete representative workflows;
- training completion and support demand;
- user-reported friction supported by observed evidence;
- percentage of accepted improvements producing measured benefit.

## 17. Required program artifacts

- approved parity matrix and workflow specifications;
- architecture and decision records;
- conceptual and physical data models;
- integration contracts and mapping versions;
- role/action matrix;
- test strategy and synthetic scenario library;
- migration/cutover and rollback plans;
- runbooks for release, incident, backup and recovery;
- training and user guides;
- risk, decision, dependency and change registers;
- deployment and acceptance evidence for each release;
- post-parity research findings and benefit measurements.

## 18. Immediate next actions

1. Appoint the product owner, architecture owner, data owner, security/privacy owner and departmental representatives.
2. Approve this blueprint as the planning baseline.
3. Review all 268 parity-matrix rows and assign disposition/owner, beginning with the first three vertical slices.
4. Schedule controlled legacy walkthroughs for unknown rules using synthetic cases.
5. Choose candidate tools only after agreeing architecture, security, data and operational criteria.
6. Create the clean repository/environment baseline and decision log.
7. Specify the patient/encounter, identity/RBAC/audit and teaching-cohort foundations before feature coding.
8. Build one complete outpatient slice as an architectural proving ground, while keeping it subordinate to the full-SIMRS roadmap.

## 19. Definition of program completion

The reconstruction program is complete when UEU owns a documented, tested and recoverable platform that covers every approved parity disposition, supports the complete synthetic hospital workflows, protects users/data/secrets, can be operated without hidden vendor knowledge, and has passed the parity gate. The next product era begins when user-requested improvements are prioritized against observed use rather than assumptions.
