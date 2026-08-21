# Project Team, Responsibilities and RACI

## Minimum accountable roles

| Role | Accountable for |
|---|---|
| Executive sponsor | funding, institutional authority, escalation and final program outcomes |
| Product owner | scope, parity dispositions, priorities and product acceptance |
| Program/delivery lead | plan, dependencies, risks, status and cross-team coordination |
| Business/process analyst | workflow discovery, requirements, traceability and decision preparation |
| Architecture/technical lead | system boundaries, technology decisions, quality and technical coherence |
| Data/integration lead | conceptual model, master data, mappings, migration and external interfaces |
| Security/privacy lead | data classification, threat/control requirements, audit and risk acceptance |
| QA/UAT lead | test strategy, environments, evidence, defects and release quality gates |
| Operations/release owner | environments, deployment, observability, backup, recovery and support readiness |
| Lecturer/teaching owner | teaching scenarios, cohort controls, student safety and learning workflow |
| Departmental subject-matter owners | clinical/operational correctness and parity acceptance for their domains |
| UX/research lead | workflow usability, accessibility, post-parity research and change validation |

Individuals may combine roles in a small team, but the responsibilities and approvals must remain explicit. Development cannot approve its own unresolved clinical, security or acceptance risk.

## Recommended delivery capabilities

- product/business analysis;
- clinical/RMIK subject expertise;
- UX and accessibility;
- frontend engineering;
- backend/domain engineering;
- data and integration engineering;
- reporting/analytics engineering;
- platform/operations engineering;
- security/privacy engineering;
- automated and exploratory QA;
- instructional design/training;
- support and documentation.

## High-level RACI

Legend: A = accountable, R = responsible, C = consulted, I = informed.

| Activity | Sponsor | Product | Program | SME | Architecture | Data/Integration | Security | QA | Operations | UX/Teaching |
|---|---|---|---|---|---|---|---|---|---|---|
| Approve program scope | A | R | C | C | C | I | I | I | I | C |
| Classify parity disposition | I | A | C | R | C | C | C | C | I | C |
| Approve clinical/business rule | I | A | I | R | C | C | C | C | I | C |
| Select technology | I | C | C | I | A/R | C | C | C | C | I |
| Approve data classification | I | C | I | C | C | R | A | C | C | C |
| Design integration | I | C | I | C | C | A/R | C | C | C | I |
| Approve authorization/audit | I | C | I | C | C | C | A/R | C | C | C |
| Prepare acceptance tests | I | A | C | R | C | C | C | R | I | C |
| Accept parity UAT | I | A | C | R | C | C | C | R | C | R |
| Approve release | I | A | C | I | C | C | C | R | A/R | I |
| Accept residual risk | A | C | C | C | C | C/R | R | C | C | I |
| Prioritize post-parity change | I | A/R | C | C | C | C | C | C | C | C |

## Departmental ownership map

| Domain | Required owner representation |
|---|---|
| Registration, admission and queue | front office/admission owner |
| Emergency, outpatient and inpatient | authorized clinician/nursing representatives |
| Laboratory, radiology and blood bank | respective diagnostic-service owners |
| Rehabilitation, nutrition and allied care | relevant professional owner |
| Surgery/IBS, ambulance and mortuary | respective service owners |
| Medical record, coding and reports | RMIK/casemix owner |
| Pharmacy and central warehouse | pharmacist/inventory owner |
| Claims and BPJS | casemix/claim owner |
| Cashier and revenue | finance/cashier owner |
| Master data and teaching administration | lecturer-administrator and data steward |
| Integrations and operations | technical/integration owner |

## Meeting and decision cadence

- Weekly delivery/risks: program, workstream leads and product.
- Weekly requirements clinic during parity specification: analyst, SMEs, product, QA and architecture.
- Per-slice design review: architecture, data, security, QA and affected SMEs.
- Per-release readiness review: product, QA, security, operations and affected owner.
- Monthly steering review: sponsor, product, program, architecture and major domain owners.
- Post-parity research review: product, UX/research, teaching and affected departments.

Every meeting that changes scope, rule, architecture, risk or acceptance must result in a linked decision/change record.
