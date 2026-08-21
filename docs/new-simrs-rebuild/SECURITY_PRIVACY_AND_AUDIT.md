# Security, Privacy and Audit Specification

Status: proposed mandatory baseline

Scope: all SIMRS modules, interfaces, integrations, data stores, reports, documents, devices, administrative functions and teaching environments

Tooling position: control objectives are technology-agnostic

## 1. Outcome and non-negotiable boundary

The clean-slate SIMRS must preserve legitimate old-system workflows without reproducing known weaknesses. Functional parity never requires secret readback, broad student permissions, insecure external links, unverifiable audit logs, shared privileged accounts or production data/endpoints in teaching.

The old assessment identified critical/high concerns around retrievable credentials, a student role with 211 of 280 permission nodes, unproven synthetic-data provenance, a cleartext external display route, incomplete audit evidence, inconsistent integration state and unclear privileged-account ownership. These become explicit security requirements, not deferred cleanup.

This document is an engineering baseline, not a determination that a particular deployment complies with Indonesian law or organizational policy. Before real-data use, the owner must validate applicable legal, health-record, privacy, retention, clinical-safety and national-integration requirements against the current official sources listed in the assessment baseline.

## 2. Requirement labels

- **COMPAT:** preserve legitimate old workflow outcome or required authorized access.
- **NEW:** clean-slate security/privacy control, including intentional differences from old behavior.
- **DISCOVERY:** unresolved user, legal, data or risk requirement.
- **PROHIBITED:** behavior that must not be recreated.

## 3. System boundary and assets

Protected assets include:

- patient identity, contact and demographic data;
- clinical notes, diagnoses, procedures, results, medication, documents and signatures;
- appointment, referral, admission, bed and discharge data;
- payer membership, claims and supporting documents;
- bills, payments, refunds, receivables, tariffs and financial exports;
- medication, controlled-item and stock/procurement records;
- user identities, roles, sessions, credentials, certificates and secrets;
- audit trails, integration messages, backups and exports;
- teaching case definitions and assessment activity;
- source code, build artifacts, configuration and operational access.

Trust boundaries:

1. public/unauthenticated users to SIMRS;
2. authenticated users to role- and action-scoped modules;
3. ordinary users to privileged administration;
4. lecturer administration to student/cohort use;
5. application to database, document store, queue and workers;
6. SIMRS to national, payer, clinical, business, messaging, device and AI services;
7. operational environment to reporting/analytics;
8. production-capable environments to teaching, development and test;
9. organization staff to vendor/support personnel;
10. online systems to backups, archive and disaster-recovery copies.

## 4. Threat model

| Threat actor/event | Representative risk | Required control themes |
|---|---|---|
| unauthenticated attacker | account takeover, injection, denial of service, public-display disclosure | hardened authentication, input/output controls, rate limits, secure headers, monitoring |
| ordinary or student user | unauthorized record access, bulk export, stock/financial mutation, privilege escalation | least privilege, action and record-scope policy, synthetic isolation, export controls, audit |
| privileged user | secret theft, role manipulation, hidden record change, disabling audit | separated privileges, MFA, approvals, immutable externalized audit, privileged monitoring |
| compromised session/device | impersonation and lateral access | short-lived risk-based sessions, device/session controls, reauthentication, revocation |
| malicious/compromised integration | forged messages, replay, malformed data, data exfiltration | adapter boundary, mutual authentication as applicable, allowlist, schema validation, idempotency, minimization |
| software supply-chain compromise | malicious dependency/build/deployment | controlled source, review, SBOM, signed/attested artifacts where feasible, vulnerability and provenance checks |
| operator mistake | wrong environment, accidental deletion, misconfiguration, release outage | protected configuration, change approval, guardrails, backup/restore, reversible release |
| infrastructure loss/ransomware | unavailable or corrupted records and backups | segmentation, least privilege, immutable/offline copy, restoration exercises, incident response |
| privacy misuse | curiosity viewing, overbroad reports, unauthorized secondary use | purpose/role/record scoping, minimum necessary views, break-glass, audit review, approval |

Threat models must be refreshed for every new external integration, public surface, mobile/device client, AI capability and production data flow.

## 5. Security invariants

The following must hold in every deployment:

1. The server authorizes every protected read, write, print, export, approve, reverse, configure and integrate action; menu visibility is never authorization.
2. A caller cannot change actor, facility, patient, encounter, amount, status or scope merely by modifying client-supplied identifiers.
3. Clinical, financial, stock, permission and integration changes are attributable and cannot disappear from the audit record.
4. Signed/posted/submitted records are corrected through governed version or reversal, not destructive overwrite.
5. Production credentials, endpoints and data cannot enter a teaching environment through UI configuration, database clone or backup restoration.
6. Secrets are never returned after creation, printed in logs, embedded in client code or stored in ordinary settings fields.
7. Integration retries cannot duplicate a charge, dispense, stock movement, receipt, claim or national submission.
8. External failure cannot silently convert an internal pending/failed state into success.
9. Public displays expose only approved, minimized, time-limited information and never rely on obscurity.
10. Audit, monitoring and backup controls cannot be disabled by an ordinary application administrator.
11. A denied action fails closed and produces safe user feedback plus security-relevant telemetry where appropriate.
12. Break-glass access is exceptional, time-bound, reasoned, reviewed and never silently equivalent to permanent privilege.

## 6. Data classification and handling

| Class | Examples | Minimum handling |
|---|---|---|
| Restricted health/identity | clinical record, identifiers, payer data, images, claims | need-to-know authorization, encryption, detailed audit, constrained export and retention |
| Restricted security | passwords, tokens, private keys, recovery codes, signing credentials | dedicated secret/key management, no readback, no logs, tightly separated administration |
| Confidential business | tariffs, procurement, financial details, internal operations, staff data | role/record scope, encryption, export controls and audit |
| Internal | non-sensitive configuration, operational metadata, synthetic scenario authoring | authenticated access and integrity controls |
| Public-approved | explicitly approved queue/display/education content | privacy review, field minimization, no accidental link to restricted data |
| Synthetic | generated cases with no real-person derivation | isolated namespace, watermark/marker and misuse controls; not automatically public |

Classification applies to primary data, documents, screenshots, caches, logs, traces, search indexes, messages, exports, backups and support attachments.

## 7. Identity and authentication

### 7.1 Workforce and privileged users

- Use unique named accounts linked to a verified workforce identity and organization relationship.
- Require strong multi-factor authentication for privileged, remote and production-capable access; apply it broadly where feasible.
- Separate human accounts from service principals. Service identities cannot sign clinical entries or provide shared interactive login.
- Support centralized disablement, session/token revocation and periodic access certification.
- Require reauthentication or step-up verification for high-impact operations such as permission changes, secret rotation, bulk export, irreversible workflow, refund and break-glass.
- Store passwords using a current adaptive one-way password-hashing method with per-credential salt and policy-managed work factor. Never store reversible passwords.
- Recovery and enrollment processes receive equal protection to login and are audited.

### 7.2 Sessions

- Use secure, HTTP-only session tokens/cookies and an explicit cross-site policy.
- Rotate identifiers at authentication and privilege elevation.
- Define idle and absolute timeouts by risk class; old-system four-hour behavior is not automatically inherited.
- Revoke sessions on account disablement, credential reset, material role reduction and suspected compromise.
- Protect state-changing requests against cross-site request forgery or equivalent confused-deputy attacks.
- Show users their active sessions where appropriate and let security administrators terminate them.

### 7.3 Students and teaching users

**NEW:** Student access is course- and cohort-specific, time-bounded and synthetic-only. There is no universal `MAHASISWA` super-role.

- provision from approved roster and disable automatically at course end;
- grant only the tasks needed for the current learning outcome;
- constrain users to assigned synthetic cases/teams where appropriate;
- prohibit secret/settings administration, user/role administration, unrestricted reports, bulk export, production integration and uncontrolled stock/financial effects;
- reset scenarios through a separate instructor workflow, not by giving students delete privileges;
- record instructor override, assessment access and reset events.

## 8. Authorization model

Use role-based access for job/course assignment plus contextual policy for facility, unit, care relationship, record state, cohort, payer, sensitivity and time. Ownership of a menu does not imply every action within it.

### 8.1 Action vocabulary

At minimum, distinguish:

`list`, `view`, `view-sensitive`, `create`, `edit-draft`, `sign/verify`, `amend`, `cancel`, `reverse`, `approve`, `dispense`, `transfer-stock`, `post-charge`, `collect`, `refund`, `submit-external`, `reconcile`, `print`, `export`, `manage-master`, `manage-role`, `manage-secret`, `view-audit`, `break-glass`.

### 8.2 Policy inputs

- authenticated subject, account assurance and active organization/role assignment;
- patient/encounter relationship and assigned care unit;
- resource classification and sensitivity flag;
- facility, department, course/cohort and environment;
- record state such as draft, signed, posted, paid, submitted or locked;
- amount/quantity/risk thresholds and required approval;
- purpose or declared reason when required;
- time and emergency/break-glass state.

### 8.3 Action-authorization matrix template

| Resource | Action | Permitted roles | Context constraints | Approval | Audit level | Synthetic/production difference |
|---|---|---|---|---|---|---|
| Patient | merge | designated identity/RM role | verified duplicate; same environment | second qualified approver | full before/after + link impact | instructor-only simulated merge |
| Clinical entry | sign | qualified author/verifier | active encounter and authorship/care relationship | per form policy | full event and version | scenario-specific |
| Stock | adjust | pharmacy/warehouse controller | assigned location; reason required | threshold based | ledger + before/after | reset handled separately |
| Payment | refund | cashier supervisor | original receipt and open accounting period | required above threshold | full transaction | normally disabled |
| Settings | rotate secret | secret administrator | approved environment and integration | dual control where critical | metadata only, never value | no production integration in campus |

The complete matrix is a required parity artifact. It must cover all old menu actions and hidden endpoints, not just 268 menu links.

### 8.4 Segregation of duties

Where feasible, separate:

- role requester, approver and provisioner;
- tariff/master-data requester and approver;
- purchaser, receiver and invoice/payment approver;
- stock adjustment creator and high-value approver;
- bill adjustment/refund creator and approver;
- coder/claim preparer and final submission/reconciliation;
- developer/release producer and production deployment approver;
- application administrator and audit/security reviewer;
- secret administrator and ordinary lecturer administrator.

Conflicts and temporary exceptions are visible, approved, time-limited and reviewed.

## 9. Privacy by design

- Define purpose, lawful/organizational authority, data owner and minimum fields for each workflow, report, integration and research/teaching use.
- Default lists and searches to the minimum identifying detail needed for safe matching.
- Mask especially sensitive identifiers in ordinary views; reveal only through an authorized, audited action where operationally necessary.
- Prevent broad unfiltered queries and uncontrolled browsing of patient populations.
- Apply record-level and field-level restrictions for especially sensitive cases where policy requires them, without compromising safe care.
- Track disclosure/release of information, recipient, purpose, scope, authorization, document version and delivery outcome.
- Do not use operational health data for teaching, development, analytics, AI prompting or vendor support merely because the application can access it.
- Exports include classification, creator, timestamp, scope and watermark/handling notice where appropriate; large or sensitive exports require approval and expire from download staging.
- Screenshots and printouts are disclosures. Minimize content, mark context and audit production of sensitive documents.
- Public queue/admission/surgery displays use an owner-approved data set, purpose, refresh interval and expiration. They are served only through controlled secure endpoints.

Privacy rights, correction, retention and disclosure processes must be validated by the responsible owner/legal/privacy function before real-data operation.

## 10. Cryptography and key management

- Encrypt traffic across user, internal service, administrative and external boundaries using approved current protocols.
- Encrypt restricted/confidential data and backups at rest where supported by the risk model.
- Separate encryption/signing keys by environment and purpose.
- Store key metadata, owners, activation, expiry, rotation and revocation; never store private material in ordinary application configuration.
- Limit decryption/signing operations to authorized service identities and record security-relevant use.
- Design rotation without mass downtime and test recovery from key loss/expiry.
- Cryptographic choices and key lengths are maintained in a separately reviewable current standard so this architecture document does not freeze aging algorithms.

## 11. Secret management

### 11.1 Prohibited behavior

- populated secret returned to a browser or API after initial entry;
- password/token/private key in source, build artifact, screenshot, ticket, report, log or database export;
- one credential reused across campus, test and production;
- user-editable arbitrary integration URL combined with production secrets;
- secret value captured in audit before/after fields.

### 11.2 Required lifecycle

`request/approve -> generate or receive securely -> store in dedicated secret control -> grant workload identity -> use without display -> monitor -> rotate -> revoke -> verify dependent service`

Application settings store only a secret reference and safe metadata such as integration name, owner, last rotation and expiry. Secret changes record who changed the reference and when, not the value. Emergency secret access uses a time-bounded, reviewed procedure.

## 12. Audit specification

Audit is distinct from debug logging and must remain available even if an application module is compromised.

### 12.1 Mandatory auditable events

- login success/failure, MFA, recovery, session creation/revocation and break-glass;
- view/search of sensitive records and population-level access where risk warrants;
- create/edit/sign/verify/amend/cancel/reverse/merge/unmerge/delete-disposition;
- print, preview, download, export, disclosure and bulk report access;
- order/result/medication/stock/charge/bill/payment/claim state changes;
- user, role, policy, roster and privilege changes;
- master data, tariff, template, feature and configuration changes;
- secret reference creation/rotation/revocation and privileged configuration access;
- integration send/receive/retry/reconcile/manual override and endpoint/environment change;
- data migration/import, backup/restore and archive/disposition;
- release/deployment, schema migration and privileged support access;
- teaching scenario creation/reset/assignment and instructor override.

### 12.2 Audit event schema

Each event includes, where applicable:

- unique event ID and immutable timestamp;
- actor account, human/service identity, role/assignment and assurance level;
- action and outcome, including denial;
- patient/resource type and stable internal reference—not unnecessary full content;
- encounter/facility/unit/environment;
- source channel, session, device/network context and correlation ID;
- reason/purpose/approval and break-glass reference;
- before/after structural change or version references, with sensitive-field redaction;
- related integration/release/request ID;
- audit schema version and integrity marker.

### 12.3 Integrity, access and review

- Append audit records to a protected sink where ordinary application administrators cannot alter or erase them.
- Detect gaps, ingestion delay, time drift and unexpected audit-volume changes.
- Restrict audit access and audit the auditors' searches/exports.
- Retain under an approved schedule with legal hold support.
- Provide routine review for privileged changes, bulk access/export, break-glass, repeated denials, unusual patient browsing, secret operations and support access.
- Create alert-to-case linkage so investigation and closure are attributable.
- Test audit completeness in every workflow acceptance test; an operation that succeeds without its mandatory audit event fails acceptance.

## 13. Application and API security

- Validate structured input server-side using allowlisted schemas and business rules.
- Use parameterized data access and context-appropriate output encoding.
- Enforce object-level and function-level authorization on every request.
- Protect against mass assignment: clients cannot set server-owned state, price, role, author, approval or audit fields.
- Apply request size, upload type, decompression, query complexity, pagination and rate limits.
- Treat uploaded documents as untrusted; scan, validate type by content, rename, isolate storage and never execute from upload locations.
- Do not expose stack traces, secrets, internal paths or raw external responses to users.
- Use secure browser headers and content policy appropriate to the final interface; eliminate cleartext HTTP and prevent unapproved framing/content injection.
- Protect caches/search indexes with the same record-level policy as the source.
- Define safe concurrency behavior so stale screens cannot silently overwrite newer clinical/financial state.
- Use dependency inventory/SBOM, automated checks, review and timely remediation; old jQuery-era dependencies are not parity requirements.
- Security testing includes misuse/negative cases, not only happy-path unit tests.

## 14. Integration, device and AI security

- Authenticate both ends where the external capability supports it; verify certificates/keys and constrain network destinations.
- Validate inbound origin, signature/message authentication, schema, size, replay window and identifier context.
- Separate external payload receipt from acceptance into the clinical/financial record; invalid messages enter quarantine.
- Redact protected data from operational diagnostics and vendor support bundles.
- Approve each device identity and associate readings with device, calibration/provenance, patient/context and time as applicable.
- AI integrations are off by default. Activation requires a documented purpose, approved data classification, minimum-data prompt design, contractual processing/retention review, human review, traceability and a non-AI fallback.
- No production secret or clinical content is sent to a generic AI service from a user-editable old-style settings screen.

## 15. Administrative and vendor access

- Production support access is named, MFA-protected, time-bounded, approved and attributable.
- Prefer just-in-time elevation and recorded/reviewable administrative sessions.
- Vendors receive no standing database or unrestricted server account by default.
- Support exports are minimized, redacted and time-limited; ticket systems are not health-record repositories.
- Emergency access has an expiry and mandatory review.
- Ownership, return/revocation and notification obligations are contractual and tested at vendor exit.

## 16. Security verification gates

### Gate S0 — design readiness

- classify data and environments;
- approve trust boundaries, threat model and security invariants;
- complete action-authorization and segregation-of-duty designs;
- assign privacy, security, clinical, data and operational owners.

### Gate S1 — every vertical slice

- positive and negative authorization tests;
- input, output, upload and concurrency tests as applicable;
- mandatory audit-event assertions;
- secrets/configuration scan;
- synthetic-data provenance check;
- dependency and code security review proportionate to risk.

### Gate S2 — parity pilot

- role certification including student denials;
- bulk search/report/export misuse tests;
- break-glass and privileged-change review;
- integration success/failure/replay/reconciliation tests;
- independent vulnerability assessment of the deployed pilot;
- incident/tabletop and audit-reconstruction exercise.

### Gate S3 — production-capable use

- current official/legal/privacy review;
- hardened topology and privileged-access evidence;
- penetration and application-security verification against the adopted current standard;
- backup/restore and disaster-recovery exercise;
- breach/incident notification contacts and runbooks;
- owner-signed residual-risk register.

## 17. Security compatibility exceptions

The following old behavior, if confirmed, is intentionally non-compatible:

| Old-system observation | New requirement |
|---|---|
| secret values retrievable in settings | write-only/reference-based secret management and rotation |
| one broad student role | course/cohort/action-scoped synthetic roles |
| cleartext external display route | approved secure same-owner or contracted endpoint |
| empty/failed activity log as evidence surface | protected, queryable and completeness-tested audit |
| menu/tree permission as primary control | server-side action and record-scope policy |
| multiple privileged group memberships without reconciliation | named ownership, certification, separation and expiry |
| legacy browser dependencies and missing hardening headers | supported dependencies and secure interface baseline |

These are security acceptance requirements, not optional user improvements after parity.

## 18. Open decisions

1. Is the target permanently campus-only or expected to become production-capable?
2. Who serves as privacy/data-protection, security, clinical-safety and audit owner?
3. Which actions require dual approval and what thresholds apply?
4. Which user groups need patient-population search or bulk export, and for what purpose?
5. What break-glass scenarios are legitimate in a teaching environment versus a real hospital?
6. What are the approved retention schedules and legal-hold processes by record class?
7. Which external/AI services are contractually permitted to process which data classes?
