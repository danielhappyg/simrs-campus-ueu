# Vendor Evidence Request and Demonstration Agenda

Purpose: evidence required before technical and commercial acceptance of SIMRS 3.0 — RS UEU.

## 1. Product, ownership and scope

- Exact product name, licensed edition, build/version, release date and deployment date.
- Contracted modules and limits; annotate every item in `MODULE_INVENTORY.md` as licensed, enabled, tested, teaching-use, inactive, legacy, placeholder or out of scope.
- Canonical status and retirement roadmap for v2, v3, EMR IPP, iDRG and legacy variants.
- Product roadmap for at least three years.
- Source-code/IP ownership, third-party licensing, SBOM and open-source obligations.
- Business-continuity arrangement if the vendor ceases support: escrow, source handover or named replacement support.

## 2. Current documentation

- Current versioned end-user manual for every licensed module.
- Administrator/security guide.
- Architecture and deployment guide.
- Data dictionary, entity relationship model and identifier rules.
- Integration/API catalogue and mapping specifications.
- Role/action matrix and segregation-of-duties guide.
- Backup, restore, disaster-recovery and incident runbooks.
- Release notes and database-migration history since 2018.
- Training plan, sandbox exercises and competency materials for lecturers and students.

## 3. Architecture and engineering

- Physical and logical topology: hosting, region, DNS/CDN/WAF, servers, network zones, database, storage, queues and schedulers.
- Supported OS, PHP, web server, database and browser versions.
- CI/CD, code review, test automation, release approval, rollback and emergency patch process.
- SBOM and current dependency/vulnerability scan, including the legacy jQuery/jQuery UI plan.
- Observability: availability, error, job, integration, capacity and security monitoring; alert owners and retention.
- Performance and capacity results with expected UEU users, concurrent sessions, database size and report load.

## 4. Data and privacy

- Written classification of current UEU data as synthetic, seeded, historical, imported, de-identified or identifiable.
- Controller/processor roles, purpose, retention, deletion, export, breach and data-subject procedures.
- Encryption in transit and at rest; key and certificate management.
- Patient duplicate/merge, correction, amendment, version history and deletion rules.
- Full database and document export in open documented formats, including relationships and audit history.
- Tenant/environment separation and a repeatable reset/refresh process for teaching cohorts.
- Proof that campus exercises use sandbox integrations and approved synthetic identities only.

## 5. Identity, authorization and audit

- Unique-user lifecycle: creation, approval, expiration, disablement, password policy, MFA and recovery.
- All group memberships reconciled to unique owners, especially both super-admin groups and `DEFAULT (PERLU MAPPING)`.
- Action-level authorization matrix for view/create/edit/delete/approve/print/export/reverse/settings/integration actions.
- Demonstration that hidden menus and direct URLs are denied server-side.
- Course-specific student role and negative tests against administrative, claim, pharmacy, inventory, cashier and sensitive-report actions.
- Immutable audit coverage for login, view, search, create/change, print/export, permission, settings, secret, integration, correction/reversal and deletion events.
- Audit retention, time synchronization, tamper protection, search/export and incident-use procedures.

## 6. Security

- Independent penetration-test report and remediation evidence; contract OWASP ASVS 5.0.0 verification scope.
- Session/cookie policy, timeout, concurrent sessions, logout invalidation and device/IP logging.
- CSRF, XSS, injection, file upload/download, access control, SSRF and business-logic test evidence.
- TLS configuration, response-security headers, WAF/rate limiting and DDoS controls.
- Secret storage, rotation, masking/write-only behavior and privileged-access controls.
- Secure development lifecycle, code scanning, dependency scanning, security training and incident SLA.
- Vendor remote-support access, approval, MFA, session recording and revocation.

## 7. Integration evidence

For BPJS/VClaim/Antrol/Aplicares, SatuSehat, E-Klaim/iDRG, LIS, PACS, TTE/e-sign, RS Online/SIRS/SIRANAP, WhatsApp, ERP/accounting, IoT, PDF/FTP and OpenAI/Aisha:

- UEU environment and business owner;
- sandbox or production endpoint and current enabled/disabled status;
- data contract, field/code mapping and version;
- authentication, certificate and secret rotation;
- successful redacted request/response trace;
- validation failure, timeout, retry, idempotency and duplicate handling;
- reconciliation queue/report and monitoring alert;
- retention and privacy impact;
- decommission procedure.

Explain and remediate the sampled localhost appointment-service Not Found responses and the VClaim-inactive state.

## 8. Backup, recovery and continuity

- Backup scope, frequency, encryption, retention, off-site/immutable copies and monitoring.
- Contracted RPO, RTO and availability target.
- Most recent successful full restore test with timestamp, duration and reconciliation results.
- Failover and disaster-recovery exercise evidence.
- Recovery of database, uploaded documents, templates, configuration, secrets, audit logs and integration queues as a consistent point in time.
- Exit backup and restore procedure deliverable to UEU.

## 9. Full synthetic demonstration agenda

1. Patient identity creation, search, duplicate prevention and merge/correction.
2. Outpatient booking/registration, queue, examination, orders, results, prescription, dispensing, billing, RM completion, claim and report.
3. Emergency triage, ED care, admission/referral/death dispositions and downstream records.
4. Inpatient admission, bed assignment, transfer, clinical/nursing documentation, pharmacy, diagnostics, surgery, discharge, final bill and claim.
5. Laboratory, pathology, microbiology, radiology, blood bank, nutrition, rehabilitation, speech and occupational therapy.
6. Surgery/IBS scheduling, clinical documentation, consumables, blood, IBS pharmacy, cancellation and register.
7. Pharmacy screening, dispense, partial/return/cancel, stock movement and patient billing.
8. Warehouse PO, receipt, batch/expiry, distribution, inter-depot transfer, stock opname, supplier/department return and perpetual reconciliation.
9. Cashier partial/multiple payment, guarantee/receivable, discount/approval, reversal, close shift, deposit and revenue reports.
10. BPJS, SatuSehat, E-Klaim/iDRG and other configured integrations in sandbox, including failures and retries.
11. Regulatory, clinical, operational, stock, financial and audit reports reconciled to source transactions.
12. Role creation/change, student denial, user expiration, password reset, audit search and settings change/reversal.
13. Backup during controlled activity and successful point-in-time restore.

For each demonstration capture prerequisites, actors, input data, expected state transitions, generated identifiers, downstream postings, audit events, failure/correction cases and pass/fail result.

## 10. Commercial and service evidence

- Complete one-time and recurring cost: licenses, hosting, integrations, certificates, messages, support, upgrades, training, migration and exit.
- SLA with severity definitions, response/resolution targets, maintenance windows, availability calculation, service credits and escalation contacts.
- Warranty and defect-remediation period.
- Named product, technical, security and support owners.
- Data ownership, export rights, configuration ownership and termination assistance.
- Contractual delivery dates for critical/high findings and acceptance holdback.
