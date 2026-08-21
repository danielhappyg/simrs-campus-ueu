# New SIMRS Reference Architecture

Status: proposed baseline for parity planning and clean-slate implementation

Audience: product owner, hospital/RMIK representatives, architects, developers, testers, operations, security and procurement

Tooling position: technology- and vendor-agnostic

## 1. Purpose and decision boundary

This document defines the logical architecture for rebuilding the complete SIMRS from a clean slate. The first product goal is **functional compatibility with the old system**, followed by evidence-driven improvement after users have tried the parity release.

The old application is a reference for workflow scope, terminology, outputs and user familiarity. It is not the reference architecture. Its observed breadth—268 visible menus in 13 categories—must be reconciled, but legacy implementation choices, unsafe controls and duplicate generations are not inherited automatically.

This is a target architecture, not proof that the old system implements any stated behavior. The evidence boundary in the [old-system knowledge base](../vendor-simrs-assessment-2026-08-21/SYSTEM_KNOWLEDGE_BASE.md) continues to apply: a menu or form does not prove validation, persistence, authorization, posting, integration success or audit coverage.

## 2. Requirement labels

Every architecture requirement uses one of these labels:

- **COMPAT** — compatibility requirement derived from the old system's observable scope or a subsequently verified workflow.
- **NEW** — clean-slate design decision or control that may intentionally differ from the old system.
- **DISCOVERY** — behavior that must be observed, demonstrated or decided before exact parity can be claimed.
- **DEFERRED** — explicitly outside the first parity release but preserved in the roadmap.

Compatibility means the same necessary business outcome, not necessarily the same number of screens or clicks. A legacy menu may become a tab, task, report, saved view or permission-scoped action if users can still complete and verify the same work.

## 3. Architectural goals

| ID | Label | Goal |
|---|---|---|
| A-01 | COMPAT | Cover the complete hospital operating chain represented by registration, examination, medical records, claims, reporting, BPJS, pharmacy, central warehouse, cashier, administration, IoT, IBS pharmacy and help. |
| A-02 | COMPAT | Preserve familiar Indonesian terms, required outputs and verified workflow handoffs during the parity phase. |
| A-03 | DISCOVERY | Capture exact fields, calculations, validation, state transitions, approvals, correction/reversal rules and print layouts for every parity item. |
| A-04 | NEW | Use explicit domain boundaries and one authoritative implementation per capability; do not recreate parallel v2/v3/EMR variants. |
| A-05 | NEW | Enforce authorization, audit, privacy, data integrity and environment isolation centrally and at every action boundary. |
| A-06 | NEW | Keep external systems behind replaceable adapters with durable message history, retry and reconciliation. |
| A-07 | NEW | Optimize initially for a maintainable modular system, not premature distribution. Permit later extraction when scale or organizational ownership justifies it. |
| A-08 | NEW | Make all critical workflows testable with deterministic synthetic cases before any real-data or production-capable operation. |
| A-09 | NEW | Keep the system portable: documented contracts, standards-based exports, infrastructure reproducibility and no unescrowed proprietary lock-in. |

## 4. System context

```text
Patients / representatives / public displays
                      |
Clinical, RM, pharmacy, warehouse, cashier, claims,
management, teaching users and administrators
                      |
                      v
             +------------------+
             |     New SIMRS    |
             |------------------|
             | workflow modules |
             | shared controls  |
             | reporting/read   |
             | integration hub  |
             +------------------+
                |      |      |
                v      v      v
         National/  Clinical  Business and
         payer APIs  devices   communication systems

Examples: BPJS/VClaim/Antrol/Aplicares, SATUSEHAT,
E-Klaim/iDRG, LIS, PACS, TTE, RS Online/SIRS/SIRANAP,
ERP/accounting, WhatsApp, IoT and approved AI services.
```

The integration names above reproduce observed configuration surfaces, not a commitment to enable each one. Each integration requires its own owner, environment, legal purpose, contract, test evidence and operational acceptance.

## 5. Recommended architectural shape

### 5.1 Initial shape: modular core with isolated adapters and workers

**NEW:** Begin with a modular application boundary that can be deployed as one or a small number of units, with separately runnable background workers and external-system adapters. This minimizes distributed-system failure modes while the team is still discovering old-system behavior.

The logical modules must remain strongly separated even if they share a deployment. They communicate through documented application interfaces, commands, queries and domain events—not direct cross-module table updates.

Extract a module into an independent service only when at least one criterion is met:

1. materially different availability or scaling requirement;
2. independent team ownership and release cadence;
3. strong regulatory or network-isolation boundary;
4. external adapter needs independent failure containment;
5. measured load or change coupling justifies the added operational cost.

This is not a prescription for a particular language, framework, database, cloud or hosting provider.

### 5.2 Logical layers

```text
Role-oriented web/mobile/display interfaces
                  |
Application API and use-case layer
                  |
Domain modules and policy/state machines
                  |
Persistence ports | event/outbox | document ports
                  |
Datastores, object storage, queues and integration adapters
```

Dependency rules:

- user interfaces call application use cases, not storage directly;
- domain rules do not depend on vendor SDKs or user-interface frameworks;
- each module owns its write model and invariants;
- cross-module reads use a published query contract or governed read model;
- external APIs are accessed only through adapters;
- security, audit and observability are mandatory platform capabilities, not optional page code;
- reports never become an undocumented second write path.

## 6. Bounded contexts and module ownership

| Context | Owns | Compatibility scope | May depend on |
|---|---|---|---|
| Identity and Access | users, workforce identity links, roles, policies, sessions, privileged approvals | old groups/users/permissions and lecturer/student separation | organization masters; audit |
| Organization and Master Data | facilities, units, rooms, beds, clinicians, specialties, services, payers, suppliers, terminology and effective-dated configuration | Manajemen Data masters and settings | identity; terminology sources |
| Patient Identity | enterprise patient identifier, demographics, contacts, identifiers, duplicate candidates, merge/unmerge history | patient registration/search and old/new patient distinction | master data; audit |
| Access, Scheduling and Queue | appointments, referrals, arrival, registration, queue tickets and service routing | Pendaftaran, online entry and queue outputs | patient; payer eligibility adapter; organization |
| Emergency and Triage | triage, acuity, emergency episode, initial stabilization and disposition | IGD examination and register flows | encounter; orders; billing |
| Encounter and Clinical Record | encounter lifecycle, assessments, problems, diagnoses, procedures, notes, care team, consents, signatures and discharge | outpatient, inpatient, nursing, allied and EMR functions | patient; access; terminology; orders; documents |
| Admission, Bed and Ward | admission, class, bed assignment, occupancy, transfer, leave and discharge readiness | rawat inap, census, transfer and discharge reports | encounter; organization; billing |
| Orders and Results | order lifecycle, specimen/accession, result, verification, correction and acknowledgment | laboratory, pathology, microbiology, radiology and supporting diagnostics | encounter; external LIS/PACS adapters |
| Perioperative and Procedure Services | theatre schedule, pre-op checklist, procedure record, implant/consumable use, recovery and cancellation | IBS/operation variants and IBS pharmacy handoff | encounter; orders; inventory; billing |
| Allied and Support Care | rehabilitation, nutrition/diet, blood bank, ambulance, mortuary and medical-device service records | corresponding Pemeriksaan workflows | encounter; orders; inventory; billing |
| Medication and Dispensing | medication orders, validation, compounding, dispense, administration handoff, return and patient-facing pharmacy | Apotek and Farmasi IBS workflows | encounter; inventory; pricing; billing |
| Inventory and Procurement | item catalogue, stock by location/batch/expiry, requisition, purchase order, receipt, transfer, adjustment, stocktake and return | GF central warehouse and depot flows | suppliers; finance; audit |
| Health Information Management | record completeness, coding, abstracting, amendment, filing/custody, release of information and retention | all RM menus and completeness reports | encounter; documents; terminology; claims |
| Tariff, Charge and Billing | price books, charge capture, bill, discount authorization, receipt, refund, deposit, receivable and settlement | Kasir and financial reporting | all charge-producing modules; payer |
| Payer and Claims | coverage, eligibility/SEP reference, claim episode, grouping, document pack, submission, adjudication, correction and payment reconciliation | Klaim and BPJS flows | encounter; coding; billing; integration hub |
| Reporting and Analytics | governed operational, clinical, statutory, inventory and financial read models; export metadata | all 117 visible report menus plus departmental reports | published data products from all contexts |
| Document and Print Services | versioned templates, generation, signature, watermark, delivery and print history | old forms, labels, cards, reports, clinical and claim documents | requesting modules; audit; TTE adapter |
| Integration Hub | endpoints, contracts, messages, acknowledgments, retries, dead-letter work and reconciliation | all old bridging/configuration/log surfaces | domain modules via stable ports |
| Teaching Control Plane | course/cohort roles, synthetic case packs, reset, scenario clock, sandbox endpoints and instructor review | campus operating model | identity; all enabled modules; audit |
| Device/IoT Gateway | approved device identities, readings, alarms and provenance | IoT temperature surface and future devices | organization; monitoring; audit |

The old menu taxonomy remains the scope ledger. Every menu must map to one owning context and one of: `parity-required`, `combined`, `replacement-output`, `inactive-by-decision`, `legacy-retired`, or `unresolved`.

## 7. Core workflow choreography

The compatibility spine is:

```text
identity -> appointment/referral/arrival -> registration/encounter
 -> triage or service intake -> assessment -> orders/procedures/results
 -> medication/support services -> record completion and coding
 -> charge/bill/claim -> payment/adjudication/reconciliation
 -> discharge/referral/death/closure -> reporting and retention
```

Recommended interaction styles:

- **Synchronous command:** user needs immediate validation and a committed result, such as registering an encounter or assigning a bed.
- **Synchronous query:** user needs current authoritative state, such as bed availability or bill status.
- **Asynchronous event:** downstream work can occur independently, such as projecting a report, creating a claim checklist or notifying pharmacy of a signed prescription.
- **Scheduled process:** periodic reconciliation, statutory extracts, expiry warnings and operational summaries.
- **Human work queue:** errors or ambiguous states require named ownership rather than endless automatic retry.

Events communicate completed facts, not requests disguised as facts. Examples: `EncounterRegistered`, `OrderPlaced`, `ResultVerified`, `MedicationDispensed`, `PatientTransferred`, `ClinicalRecordFinalized`, `ClaimSubmitted`, `PaymentPosted`.

## 8. Compatibility contract for the parity release

For each of the 268 old-system menu entries, the parity matrix must record:

1. old name, category and route evidence;
2. target module, screen/task/report and new navigation location;
3. user roles and action-level permissions;
4. entry conditions and required master data;
5. fields with format, terminology and conditional visibility;
6. business validations and formulas;
7. states, transitions and timestamps;
8. side effects in clinical, stock, billing, claims and reporting contexts;
9. print/export/integration outputs;
10. correction, cancellation, reversal, merge and late-entry behavior;
11. audit events and privacy constraints;
12. synthetic acceptance cases and evidence status.

Parity is achieved only when the required workflow outcome and downstream postings pass UAT. Pixel identity and one-to-one page reproduction are not acceptance criteria unless explicitly required for training continuity.

## 9. Non-functional design targets

Targets are finalized through the operational service catalogue; the following are architecture requirements rather than guaranteed SLAs.

| Area | Requirement |
|---|---|
| Safety and integrity | No silent partial posting across encounter, stock, charge or claim boundaries; all corrections are attributable and reversible according to policy. |
| Availability | Degrade noncritical reports/integrations before blocking core care workflows; define downtime procedures and recovery for each critical module. |
| Performance | Set task-specific response budgets and concurrency targets from observed workload; test the 95th and 99th percentile, not averages only. |
| Scalability | Scale stateless request handling and workers horizontally where useful; keep state ownership explicit. |
| Security and privacy | Apply least privilege, strong authentication, encryption, secret isolation, data minimization and immutable audit as described in `SECURITY_PRIVACY_AND_AUDIT.md`. |
| Interoperability | Version contracts; preserve message provenance, idempotency, acknowledgments and reconciliation as described in `DATA_AND_INTEGRATION_STRATEGY.md`. |
| Operability | Structured logs, metrics, traces, health checks, runbooks, backup/restore and reversible releases as described in `OPERATIONS_AND_RELIABILITY.md`. |
| Accessibility and usability | Keyboard-operable, readable, responsive role-based tasks; perform workflow usability testing with actual user groups. |
| Portability | Complete data export, configuration export, documented deployment and dependency/SBOM ownership. |

## 10. Architecture trade-offs

| Decision | Benefit | Cost/risk | Revisit trigger |
|---|---|---|---|
| Modular core before microservices | faster parity delivery, simpler transactions and operations | requires discipline to prevent module coupling | independent teams, asymmetric scale or isolation need |
| One authoritative model per concept | consistent patient/encounter/charge/stock state | migration and legacy reconciliation are harder | never relax; change ownership only through ADR |
| Events for downstream work | decoupling, replayable integration and reporting | eventual consistency and operational queues | use synchronous path when the user needs atomic confirmation |
| Separate reporting read models | protects operational workload and stabilizes definitions | projection delay and additional governance | adjust by report freshness class |
| Adapter isolation | vendor/API changes do not contaminate domains | more contracts and mapping work | never bypass for short-term convenience |
| Security uplift during parity | avoids reproducing known high-risk weaknesses | some old behavior will intentionally differ | exception requires explicit owner-approved risk decision |

## 11. Initial architecture decision record register

All entries are **proposed** until the product owner and technical governance group accept them.

| ADR | Decision | Label | Consequence |
|---|---|---|---|
| ADR-001 | Rebuild against workflow parity, not code/UI cloning | NEW | old terminology/output retained where useful; implementation is clean-slate |
| ADR-002 | Use a modular core with explicit bounded contexts first | NEW | independent services are optional later, not the starting assumption |
| ADR-003 | Assign one owner for every write model | NEW | no cross-module direct table mutations |
| ADR-004 | Use commands/queries plus durable domain and integration events | NEW | idempotency, correlation and outbox/inbox patterns are required |
| ADR-005 | Place all external systems behind adapters | NEW | integration failures cannot become undocumented domain states |
| ADR-006 | Separate operational writes from governed reporting read models | NEW | report freshness and lineage must be declared |
| ADR-007 | Make campus teaching an isolated control plane and data environment | NEW | synthetic-only defaults and resettable cohorts are first-class capabilities |
| ADR-008 | Apply action-level authorization and immutable audit from the first slice | NEW | menu visibility alone never grants an action |
| ADR-009 | Store documents as governed objects with metadata, version and hash | NEW | database rows do not contain unmanaged file paths as the only reference |
| ADR-010 | Permit only forward-compatible, reversible schema evolution | NEW | every release includes migration and recovery evidence |
| ADR-011 | Keep technology selection separate from logical architecture approval | NEW | teams can compare tools against the same requirements |
| ADR-012 | Retire duplicate legacy generations through mapped canonical workflows | COMPAT/NEW | each v2/v3/EMR variant must be resolved in the parity matrix |

ADR template:

```text
Title and status
Context and evidence
Compatibility requirement affected
Decision
Alternatives considered
Consequences and risks
Security/privacy/operations impact
Migration and rollback
Validation evidence
Owner, date and review trigger
```

## 12. Architecture governance and change control

- Maintain the parity matrix as the authoritative scope ledger.
- Require an ADR for any change to module ownership, identifier strategy, cross-module transaction boundary, integration contract, data classification, availability class or security invariant.
- Review architecture at the start and end of every vertical slice.
- Record user-requested improvements separately from parity defects until the parity baseline is accepted.
- Do not activate real data or production external endpoints through an architecture decision alone; security, privacy, operational and stakeholder acceptance gates also apply.
- Review this architecture after the first complete outpatient, inpatient, pharmacy/inventory and claim-to-cash slices. Those slices expose most cross-context assumptions.

## 13. Open decisions before implementation lock-in

1. Which old workflow generation is canonical for each duplicated route?
2. Is the first deployment campus-only, production-capable but inactive, or intended for a real hospital? The controls differ materially.
3. What scale, concurrency, uptime, RPO and RTO are actually required?
4. Which reports and print artifacts are legally or operationally mandatory versus historical convenience?
5. Which integrations are needed in parity UAT, and which must remain simulated?
6. What data, if any, may be migrated from the old system after provenance and legal review?
7. Who owns master-data approval, security operations, clinical safety, privacy and release acceptance?

These questions do not block specification work. They do block a claim of final architecture or production readiness.
