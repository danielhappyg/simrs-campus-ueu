# Technology and Tool Selection Guide

## Purpose

Choose implementation tools only after requirements, architecture boundaries, data protection and operating constraints are approved. The selected stack must serve the SIMRS blueprint; the blueprint must not be narrowed to whichever tool creates the fastest prototype.

## Decision sequence

1. Confirm deployment and budget constraints.
2. Confirm data-classification and environment requirements.
3. Confirm team capability and long-term support model.
4. Define mandatory architecture/security/operations capabilities.
5. Shortlist no more than three viable stack combinations.
6. Build the same small architectural proof with each serious candidate.
7. Score evidence, not marketing claims.
8. Record the decision and exit/migration path in an ADR.

## Mandatory capabilities

A candidate fails immediately if it cannot credibly provide:

- relational transactions and integrity constraints;
- controlled schema migrations and rollback planning;
- server-side authentication and action-level authorization;
- secure secret management and environment separation;
- durable audit logging;
- background jobs with retry, idempotency and monitoring;
- documented APIs and external integration support;
- automated tests at unit, integration and browser levels;
- accessible forms, grids, printing and report generation;
- database/document backup and open-format export;
- maintainable deployment within UEU's operational capacity;
- license and cost terms acceptable for the expected lifecycle.

## Weighted evaluation matrix

Score each category from 0–5 and multiply by weight. A score of zero on a mandatory capability is disqualifying regardless of total.

| Criterion | Weight | Evidence required |
|---|---:|---|
| Data integrity and transaction support | 15 | prototype with concurrent writes, rollback and constraints |
| Security, privacy and authorization | 15 | framework/platform controls and negative-test proof |
| Maintainability and team capability | 12 | skills availability, conventions, upgrade path and code review |
| Integration/API capability | 10 | sandbox adapter proof with retries and reconciliation |
| Testing and quality automation | 10 | repeatable pipeline and representative automated tests |
| Operations, observability and recovery | 10 | deploy, rollback, monitoring, backup and restore proof |
| Functional/UI suitability | 8 | complex form, table, workflow, print and accessibility proof |
| Performance and scalability | 5 | measured representative workload |
| Portability and vendor independence | 5 | data/config export and alternate hosting path |
| Total lifecycle cost | 5 | five-year cost including support, messages and upgrades |
| Documentation/ecosystem maturity | 3 | maintained official documentation and release policy |
| Delivery speed | 2 | measured proof, not generator claims |
| **Total** | **100** |  |

## Architectural proof scenario

Every shortlisted tool combination should implement the same bounded proof:

1. create a synthetic patient and encounter;
2. assign role/context-specific access;
3. record a clinician order;
4. fulfill the order in another module;
5. create a charge and audit events;
6. update a report/read model;
7. process an idempotent sandbox integration message;
8. reject unauthorized direct access;
9. deploy a schema change and roll it back safely;
10. restore the environment from backup and reconcile identifiers.

The proof should test architecture risk, not serve as a disposable visual demo.

## Common selection traps

- choosing from a beautiful generated dashboard;
- assuming managed hosting automatically supplies application security or backups;
- treating ORM support as proof of database integrity;
- accepting a platform that makes open data export difficult;
- relying on one developer's private deployment knowledge;
- selecting microservices because the domain is large before operational capacity exists;
- selecting a low-code tool without proving audit, transactions, testing, version control and exit portability;
- enabling external production APIs during development because a connector exists;
- counting prototype speed above maintenance and recovery.

## Decision output

The final ADR must contain:

- selected frontend, backend, database, identity, job, reporting, hosting and observability choices;
- version/support policy;
- reasons and measured proof results;
- rejected alternatives and trade-offs;
- cost assumptions;
- team and training implications;
- security/privacy implications;
- portability and exit path;
- conditions that trigger reconsideration.

