# Owners and RACI — Phase 0 placeholders

Status: product owner confirmed for now; RMIK Department named; remaining roles interim/TBD  
Updated: 2026-08-21  
Authority: DEC-001 (confirmed), DEC-011 (RMIK Department)

## How to use

- Roles may be combined in a small campus team, but approvals must stay distinct.
- `Confirmed (for now)` means Daniel may accept planning gates and direct delivery until UEU replaces the appointment.
- Departmental owner `RMIK Department` is the parity acceptance authority for RMIK/coding/reports until a named individual is recorded.

## Accountable roles

| Role | Status | Name | Notes |
|---|---|---|---|
| Executive sponsor | Interim cover | Daniel Happy Putra | Covers G0 progress for ASAP testing; UEU institutional sponsor still preferred |
| Product owner | Confirmed (for now) | Daniel Happy Putra | Scope, parity dispositions, priorities, product acceptance |
| Program / delivery lead | Confirmed (for now) | Daniel Happy Putra | Plan, dependencies, risks, coordination |
| Business / process analyst | Interim | Daniel Happy Putra | Until dedicated analyst appointed |
| Architecture / technical lead | Confirmed (for now) | Daniel Happy Putra | Boundaries, stack ADR, technical coherence |
| Data / integration lead | Interim | Daniel Happy Putra | Until dedicated owner appointed |
| Security / privacy lead | Interim | Daniel Happy Putra | Fail-closed synthetic posture; institutional reviewer still desired |
| QA / UAT lead | Interim | Daniel Happy Putra + RMIK Department (UAT) | Department validates RMIK scenarios |
| Operations / release owner | Interim | Daniel Happy Putra | Vercel + Supabase demo releases |
| Lecturer / teaching owner | Named (org) | RMIK Department | Teaching scenarios and student-facing acceptance for RMIK track |
| UX / research lead | Interim | Daniel Happy Putra | Until dedicated UX owner appointed |

## Departmental subject-matter owners (parity acceptance)

| Domain | Status | Name |
|---|---|---|
| Registration / admission / queue | Interim | Daniel Happy Putra |
| Emergency / outpatient / inpatient clinical | TBD | TBD |
| Laboratory / radiology / blood bank | TBD | TBD |
| Rehabilitation / nutrition / allied | TBD | TBD |
| Surgery/IBS / ambulance / mortuary | TBD | TBD |
| RMIK / coding / reports | Named (org) | RMIK Department |
| Pharmacy / central warehouse (GF) | TBD | TBD |
| Claims / BPJS | TBD | TBD |
| Cashier / revenue | TBD | TBD |
| Master data / teaching administration | Named (org) | RMIK Department |
| Integrations / operations | Interim | Daniel Happy Putra |

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

1. Record a named contact person inside RMIK Department when available.
2. Prefer a distinct UEU executive sponsor when institutional process allows.
3. Name clinical, pharmacy/finance SMEs before Phase 1 P0/P1 dispositions freeze.
4. Name dedicated security/privacy reviewer before any non-synthetic or campus-production path.
