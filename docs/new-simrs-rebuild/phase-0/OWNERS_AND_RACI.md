# Owners and RACI — Phase 0 placeholders

Status: interim appointments recorded; institutional confirmation pending  
Updated: 2026-08-21  
Authority: DEC-001

## How to use

- Roles may be combined in a small campus team, but approvals must stay distinct.
- `Interim` means the named person may draft and coordinate; formal G0 acceptance still needs UEU sponsor confirmation where marked.
- Replace `TBD` with a person and date when appointed; do not leave silent ownership on clinical, privacy, finance or release gates.

## Accountable roles

| Role | Status | Name | Notes |
|---|---|---|---|
| Executive sponsor | TBD | TBD | Funding, institutional escalation, final program outcomes |
| Product owner | Interim | Daniel Happy Putra | Scope, parity dispositions, priorities, product acceptance drafting until UEU confirms |
| Program / delivery lead | Interim | Daniel Happy Putra | Plan, dependencies, risks, cross-team coordination |
| Business / process analyst | TBD | TBD | Workflow discovery, requirements, traceability |
| Architecture / technical lead | Interim | Daniel Happy Putra | Boundaries, stack ADR, technical coherence |
| Data / integration lead | TBD | TBD | Model, masters, mappings, sandbox adapters |
| Security / privacy lead | TBD | TBD | Classification, controls, audit, residual risk |
| QA / UAT lead | TBD | TBD | Test strategy, evidence, release quality gates |
| Operations / release owner | TBD | TBD | Environments, deploy, backup/restore, support |
| Lecturer / teaching owner | TBD | TBD | Scenarios, cohort controls, student safety |
| UX / research lead | TBD | TBD | Usability, accessibility, post-parity research |

## Departmental subject-matter owners (parity acceptance)

| Domain | Status | Name |
|---|---|---|
| Registration / admission / queue | TBD | TBD |
| Emergency / outpatient / inpatient clinical | TBD | TBD |
| Laboratory / radiology / blood bank | TBD | TBD |
| Rehabilitation / nutrition / allied | TBD | TBD |
| Surgery/IBS / ambulance / mortuary | TBD | TBD |
| RMIK / coding / reports | TBD | TBD |
| Pharmacy / central warehouse (GF) | TBD | TBD |
| Claims / BPJS | TBD | TBD |
| Cashier / revenue | TBD | TBD |
| Master data / teaching administration | TBD | TBD |
| Integrations / operations | TBD | TBD |

## Decision rights reminder

| Decision | Accountable |
|---|---|
| Product scope and parity disposition | Product owner |
| Clinical / safety rule | Authorized clinical / RMIK owner + product owner |
| Architecture and technology | Architecture lead (ADR) with product / security / ops consult |
| Data classification and migration | Data owner + privacy; migration default is **none** for teaching release |
| Integration environment activation | Integration owner + security + product + ops |
| Release acceptance | Product owner + operations/release owner |
| Post-parity improvement priority | Product governance (only after parity gate) |

## Open appointment actions

1. UEU confirms or replaces interim product owner (DEC-001 review).
2. Name executive sponsor for G0 sign-off.
3. Name teaching owner and at least RMIK, registration, and pharmacy/finance SMEs before Phase 1 P0/P1 dispositions.
4. Name security/privacy and operations owners before Phase 2 foundation exit (G1).
