# SIMRS 3.0 — RS UEU Knowledge Base

Assessment snapshot: 2026-08-21

## Purpose

This folder is the reusable evidence base for understanding, evaluating, teaching with, negotiating, and later modernizing the vendor SIMRS. It intentionally covers the entire SIMRS rather than centering only registration or medical records.

## Confirmed context

- Target: vendor-hosted `SIMRS 3.0 — RS UEU` / SIMRS Sahabat installation.
- Owner-confirmed use: UEU campus teaching and management environment, not an operating hospital.
- Authorized account: intentionally combined lecturer–administrator because lecturers manage the teaching system.
- Visible authenticated scope: 13 categories and 268 submenu items.
- Student role: 6 displayed memberships and 211/280 permission nodes selected.
- Current linked manual: 103 pages, dated 2018, useful only as a historical baseline.
- Assessment method: passive public review, authorized authenticated read-only inspection, complete structural route crawl, manual review and official-source benchmarking.
- No patient search, transaction save, permission change, secret copy, external insecure-link visit or intrusive security test was performed.

## System footprint

| Category | Menus | Responsibility |
|---|---:|---|
| Pendaftaran | 5 | patient identity, registration, admission and queue initiation |
| Pemeriksaan | 20 | clinical, diagnostic, allied, theatre, mortuary and transport care |
| RM | 7 | record completion, coding, filing, claims monitoring and exchange |
| Klaim | 6 | claim preparation, iDRG, plafond and monitoring |
| Laporan | 117 | operational, clinical, quality, statutory and management reports |
| BPJS | 2 visible | outpatient and inpatient BPJS workflows; additional permission nodes exist |
| Apotek | 20 | dispensing, depot stock, returns and pharmacy reporting |
| GF | 23 | procurement, receipt, central stock, distribution and reconciliation |
| Kasir | 19 | billing, collection, receivables, settlement and revenue reporting |
| ManajemenData | 46 | roles, users, masters, tariffs, documents, settings and logs |
| IoT | 1 | temperature surface |
| FarmasiIBS | 1 | operating-theatre pharmacy surface |
| Help | 1 | manual book |
| **Total** | **268** | complete visible platform |

## Evidence package and reading order

1. `INITIAL_FINDINGS.md` — current purchase position, prioritized risks and acceptance gates.
2. `FULL_SIMRS_WORKFLOW_MODEL.md` — complete end-to-end operating model with observed/manual/inferred/unknown labels and all-menu traceability.
3. `FULL_MENU_TAXONOMY.md` — every menu classified by purpose, actor, workflow stage and dependencies.
4. `MENU_ROUTE_AUDIT.md` — every route's structural status, forms/actions and non-sensitive indicators.
5. `MODULE_INVENTORY.md` — verbatim visible menu inventory.
6. `ROLE_CATALOGUE.md` — all 65 role groups and the student permission summary.
7. `INTEGRATION_CONFIGURATION_MAP.md` — settings/integration field map with values excluded and secret state masked.
8. `TECHNICAL_MODEL.md` — logical architecture, data model, integration pattern, security boundaries and unknown physical architecture.
9. `MANUAL_COMPLETE_MAP.md` — 2018 manual mapped to the complete current product and documentation gaps.
10. `ASSESSMENT_BASELINE.md` — evidence rules and current regulatory, interoperability, security and quality references.
11. `EVIDENCE_REGISTER.md` — concise auditable observation register.
12. `VENDOR_EVIDENCE_REQUEST.md` — document request, demonstration agenda and procurement proof.
13. `ASSESSMENT_PLAN.md` — method, safety boundary and work plan.
14. `SIMRS_Manual_vendor.pdf` and `.txt` — preserved historical source.

## Core workflow model

```text
Public/online/referral entry
  -> identity and encounter registration
  -> IGD / outpatient / inpatient service
  -> assessment, orders, diagnoses and procedures
  -> diagnostics, allied care, surgery/IBS, blood, diet and transport
  -> prescription and pharmacy fulfillment
  -> central warehouse procurement and stock replenishment
  -> medical-record completion, coding, filing and interoperability
  -> claims/BPJS/iDRG and cashier/revenue cycle
  -> discharge/referral/death/closure
  -> operational, clinical, statutory, stock, financial and audit reporting

Cross-cutting: master data, roles/users, tariffs, printing, TTE, logs,
integrations, IoT, release operations, backup/recovery and vendor support.
```

## Purchase position

The current position is **conditional hold**, not a final rejection. The product's functional breadth is credible, but final acceptance should wait for:

- immediate rotation/masking of retrievable secrets;
- course-specific student least privilege and synthetic-data isolation;
- removal or replacement of the external cleartext admission-display route;
- current architecture, manuals, data dictionary, role matrix, SBOM and release history;
- integration success/failure/reconciliation evidence;
- immutable audit coverage;
- tested backup/restore with RPO/RTO;
- canonical-version and legacy-retirement plan;
- complete synthetic end-to-end UAT;
- data portability, SLA and vendor-exit protections.

## Evidence boundaries to preserve

- A visible menu is not proof of a working transaction.
- A loaded form is not proof of validation, authorization or correct posting.
- A configured field is not proof an integration is enabled or successful.
- Empty logs are not proof that no events occurred.
- The 2018 manual is not the current specification.
- Owner confirmation that this is campus-only does not by itself prove all stored data is synthetic.
- The intentional lecturer–administrator role is expected; student separation, named-account governance, secret handling and auditability remain review subjects.
- Database, source code, infrastructure, encryption at rest, performance and disaster recovery cannot be proven from the browser.

## How to extend this knowledge base

Future evidence should be added with date, environment, actor, source, classification, confidence, result and artifact reference. Preserve synthetic case identifiers and redact personal data and secrets. Each vendor claim should remain labeled vendor-stated until demonstrated and reconciled to logs/data/output.
