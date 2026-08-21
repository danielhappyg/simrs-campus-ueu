# Initial Full-System Findings and Purchase Position

Assessment date: 2026-08-21

## Current purchase position

**Conditional hold — do not issue final technical acceptance yet.**

The system has a broad and credible full-SIMRS functional footprint: 268 visible items across registration, clinical services, diagnostics, medical records, claims, reporting, BPJS, pharmacy, central pharmacy warehouse, cashier/revenue cycle, administration, IoT, IBS pharmacy and help. However, menu breadth is ahead of the evidence available for security, data provenance, integration correctness, auditability, backup/recovery, maintainability and successful end-to-end transactions.

The appropriate next step is not rejection solely from this browser assessment. It is a time-bounded vendor evidence and remediation gate followed by controlled synthetic UAT. Purchase acceptance should be conditional on closing the blockers below.

## Severity interpretation

- **Critical:** immediate credential/data compromise potential or unacceptable secret exposure.
- **High:** substantial privacy, integrity, operational or purchase risk; must be remediated or contractually closed before acceptance.
- **Medium:** meaningful weakness requiring confirmation and scheduled remediation.
- **Evidence blocker:** cannot be safely judged from the interface; vendor proof is mandatory before acceptance.

## Prioritized findings

| ID | Priority | Finding | Evidence and impact | Required disposition |
|---|---|---|---|---|
| F-01 | Critical | Retrievable secrets in Settings | The expected lecturer–administrator account could retrieve populated SatuSehat client credentials and an OpenAI API key from editable controls. No value was retained. Admin access is intentional; secret readback is still unsafe because compromise of any admin session reveals reusable credentials. | Rotate affected credentials; make secret controls write-only/masked; separate secret administration; log access/change; prove environment and downstream scope. |
| F-02 | High | Student role is substantially over-broad | `MAHASISWA UEU` has 211/280 permission nodes, including all RM, report, pharmacy and warehouse-pharmacy permissions and broad registration/clinical access. | Define course-specific student roles; deny high-impact actions and sensitive reports; use unique time-bounded accounts; demonstrate server-side denials and synthetic-data isolation. |
| F-03 | High | Data provenance and teaching isolation are unproven | UEU confirms campus-only use, but the system contains operational-looking clinic/doctor dictionaries, historical integration logs, aggregate activity, and external integration configuration. No patient rows were retained. | Produce a signed data-classification statement: synthetic, seeded, historical, or identifiable; purge or isolate prohibited data; use sandbox endpoints and synthetic identities; document reset procedures. |
| F-04 | High | External cleartext admission-display route | `Display Admisi` points outside the assessed domain over plain HTTP. Ownership and data behavior are unknown. | Disable until ownership is proven; replace with an approved UEU/vendor HTTPS endpoint; assess data/referrer leakage and contractual control. |
| F-05 | High | Auditability is not demonstrated | Log Activity rendered empty in the shell and failed on direct navigation; e-sign log showed zero records; the role/permission system exposes powerful actions but immutable event coverage is unknown. | Demonstrate actor/timestamp/source/before-after logging for login, record view/change, print/export, permission, settings, integration, deletion/reversal and secret events; prove retention and tamper controls. |
| F-06 | High | Integration state is inconsistent or partly failing | VClaim showed inactive; the sampled bridging log included repeated localhost appointment-service Not Found responses and BPJS Antrol targets; SatuSehat/IoT/e-sign operational evidence was empty or absent. | Provide a per-integration environment and status matrix, redacted successful/failed traces, retry/reconciliation evidence, monitoring owners, and a sandbox demonstration. |
| F-07 | High | Documentation is obsolete relative to the live product | The only linked manual is from 2018 and omits SatuSehat, iDRG, EMR IPP, TTE, IoT, current module versions and much of the 268-item menu. | Make current versioned user/admin manuals, release notes, data dictionary, role matrix, integration guide and operational runbooks contractual deliverables. |
| F-08 | High | Release/update governance is unclear | The dashboard warns that `application/logs/git.log` is missing and instructs adding `autoupdate.sh` to cron. This suggests a release mechanism that is either incomplete, unmanaged, or not documented. | Prove deployed version/build, supported branch, change approvals, backup/rollback, test evidence, maintenance window, dependency scanning and patch SLA. |
| F-09 | High | Legacy client dependencies need a supported remediation plan | jQuery 1.7.2 and jQuery UI 1.8.x are loaded. Version age alone does not prove an exploitable vulnerability, but it materially raises maintainability, browser and security risk. | Supply SBOM and vulnerability status; identify vendor-supported replacements; contract an upgrade timeline and regression plan. |
| F-10 | High | Multiple functional generations coexist without a canonical roadmap | Rawat Jalan/v2, Operasi/v3, Rawat Inap/v2 and EMR IPP routes coexist. Shared data, authoritative workflow and retirement status are unknown. | Identify canonical modules and users; map shared tables/integrations; document migration, compatibility, deprecation and data-conversion plan. |
| F-11 | Medium | Public information surfaces require privacy minimization review | Public links include admitted-patient information, surgery queue and operational queues. They were not opened to avoid capturing personal data. | Demonstrate fields, masking, legal/teaching purpose, access controls, refresh/retention and a no-identifiable-data campus configuration. |
| F-12 | Medium | HTTP response hardening is incomplete in sampled responses | Sampled responses omitted CSP, HSTS, nosniff, X-Frame-Options and Referrer-Policy; session cookie SameSite was not observed. | Confirm reverse-proxy/WAF behavior; add appropriate headers and SameSite; regression-test application dialogs, prints and external integrations. |
| F-13 | Medium / unconfirmed | No visible anti-forgery token mechanism | Across loaded DOM, many POST forms exposed no token-like fields or inline CSRF setup. This is not proof of a CSRF vulnerability. | Vendor must document server-side mechanism and demonstrate negative CSRF tests in staging against high-impact actions. |
| F-14 | Medium | Privileged-role population requires reconciliation | `super admin IT` shows 10 memberships and `Super Administrator` 17; uniqueness, owners and service accounts are unknown. Five accounts remain in `DEFAULT (PERLU MAPPING)`. | Reconcile unique named users, business purpose, last use, MFA, approvals, service accounts and orphan/stale access. |

## Procurement evidence blockers

The following have not been proven and should be mandatory acceptance evidence:

1. current architecture and deployment topology;
2. database schema, data dictionary, integrity constraints, amendment/history and export capability;
3. source-code ownership/licensing, escrow or continuity arrangement, SBOM and dependency support;
4. complete action-level authorization matrix and server-side tests;
5. backup design, immutable/off-site copies, successful restore test, RPO and RTO;
6. performance, concurrency, capacity, availability and failover tests;
7. current vulnerability assessment and remediation evidence using OWASP ASVS 5.0.0 as the contractual baseline;
8. incident response, breach notification, support access and privileged remote-administration controls;
9. complete integration status and sandbox/production separation;
10. data portability, deletion/return, configuration export and vendor-exit plan;
11. SLA, severity definitions, response/resolution targets, maintenance windows and escalation contacts;
12. successful synthetic end-to-end UAT across all major workflows.

## Acceptance gates

### Gate 1 — Immediate protection

- rotate and mask exposed secrets;
- disable or replace the external HTTP route;
- classify all existing data and integration environments;
- freeze new student access until the role is narrowed or synthetic isolation is demonstrated.

### Gate 2 — Vendor evidence

- deliver current architecture, manuals, role/action matrix, data dictionary, SBOM, release history, integration catalogue, backup/restore evidence and SLA;
- annotate every one of the 268 menu items as licensed/enabled, tested, teaching-use, inactive, legacy or placeholder;
- identify canonical versions and retirement plan.

### Gate 3 — Controlled synthetic UAT

- execute complete patient, clinical, diagnostic, pharmacy, inventory, claims, billing, reporting and administration workflows using synthetic data;
- include failure, correction, cancellation, reversal, duplicate, concurrency, audit and integration-retry cases;
- verify student denials and lecturer–administrator controls.

### Gate 4 — Commercial acceptance

- close critical/high findings or bind them to dated contractual remediation with holdback;
- accept only against measurable results, data portability and a tested exit/continuity plan.

## What is already credible

- the system is visibly broader than an RMIK or registration tool;
- the menu architecture covers the full hospital value chain;
- every visible menu has been accounted for and structurally mapped;
- the 2018 manual demonstrates historical operating intent across core departments;
- current menus add substantial modern scope, including SatuSehat, iDRG, EMR IPP, expanded reporting, rehabilitation, blood bank, IoT and IBS pharmacy.

The purchasing decision now turns on demonstrated quality and control, not feature count.
