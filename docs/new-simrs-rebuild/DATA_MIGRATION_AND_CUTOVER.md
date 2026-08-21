# Data Migration and Cutover Strategy

**Default decision:** the initial clean-slate campus SIMRS uses generated synthetic data and does not migrate the old vendor application, its database, credentials, browser state, or earlier prototype data.

This document defines the controls if migration is later requested for a separately approved environment. It is a tool-agnostic runbook and does not assume that the old system's schema, identifiers, reports, or business rules are safe to copy.

## 1. Current boundary and migration trigger

The vendor assessment is a functional reference: 268 visible menus, a historical 2018 manual, observed role/menu evidence, and inferred cross-module workflows. It is not evidence of a clean export, complete schema, data quality, ownership, or lawful authority to copy records. The new project charter explicitly says the legacy application is reference material only and has no code/database migration requirement.

### Migration is not allowed for the initial teaching release

- Do not import real patients, encounters, clinical notes, images, claims, prescriptions, staff identities, credentials, logs, or integration secrets into the synthetic environment.
- Do not use a real national identifier, real insurance number, real address, real telephone number, or real external patient identifier in fixtures.
- Do not point a resettable classroom database at a production database or production integration endpoint.
- Do not call a one-time SQL dump “migration” unless ownership, lawful basis, data classification, schema, validation, and rollback are approved.

### Conditions that could trigger a future migration workstream

A future real-data or historical-data migration requires a written decision by the product owner and the designated institutional privacy, clinical, security, records, and IT authorities. The decision must define the purpose, source, lawful authority, target environment, retention, data subjects, scope, success criteria, and exit/rollback plan. A vendor-exit or continuity event may justify a read-only archive or controlled conversion; it does not waive these gates.

## 2. Migration principles

1. **Classify before copying.** Separate personal/health data, operational history, reference master data, financial data, audit evidence, credentials/secrets, and disposable teaching fixtures.
2. **Preserve provenance.** Keep source system, source identifier, extraction time, transformation version, target identifier, confidence, and validation disposition.
3. **Map meaning, not columns.** A same-named field may have different state, code set, unit, timezone, or ownership.
4. **Never migrate secrets.** Password hashes, API keys, tokens, private keys, cookies, and server configuration are rotated/recreated, not transferred.
5. **Keep source read-only.** No transformation job may write into the source. Work from an encrypted, access-controlled extract.
6. **Reconcile every total.** Patient counts, encounter counts, clinical documents, orders/results, prescriptions, stock, charges, claims, payments, reports, and audit events need quantitative and sample-based reconciliation.
7. **Make rollback possible.** Preserve a verified source snapshot or read-only archive until the target is accepted and the retention decision is documented.
8. **Use a staged cutover.** Migrate in rehearsals and controlled waves; do not combine unknown mapping, new software, and live operations in one irreversible event.

## 3. Data classification and disposition

| Data class | Initial synthetic release | Future migration disposition |
|---|---|---|
| Patient identity and demographics | Generated fixtures only | Import only approved minimum fields; identity matching and duplicate review mandatory |
| Encounters/admissions/queues/beds | Generated scenarios | Convert only required historical/open episodes; preserve source state and location/timezone |
| Clinical notes, results, images, consents | Synthetic examples | High-sensitivity controlled conversion; verify author, signature, amendments, attachments, retention |
| Orders, prescriptions, dispensing, medication administration | Synthetic | Map statuses, quantities, units, lot/expiry, returns, charges and responsible roles |
| Inventory, purchasing, suppliers, stock ledger | Synthetic | Reconcile lot/batch/expiry, valuation, closed periods, open orders, returns and stock counts |
| Charges, cashier, receivables and claims | Simulation values | Finance/claims authority decides whether history or only opening balances are converted |
| Reports and regulatory submissions | Generated report fixtures | Rebuild from validated transactions where possible; archive signed historical outputs separately |
| Users, roles, permissions, audit events | Dedicated synthetic accounts | Recreate identities and least-privilege roles; do not copy access blindly; preserve audit archive under policy |
| Master data/terminology/tariffs/templates | Curated seed data | Versioned mapping and owner sign-off; reconcile effective dates and retired codes |
| Integration configuration, logs and secrets | Sandbox stubs only | Recreate endpoints/credentials; import redacted message history only if needed for audit |
| Browser files, temporary tables, caches, debug logs | Never | Dispose securely unless a records officer explicitly requires an archive |

## 4. Migration lifecycle

### Phase 0 — Authorize and define scope

1. Name the accountable owner, privacy/data-protection reviewer, clinical/records reviewer, security lead, IT operator, vendor contact, and rollback authority.
2. Create a data inventory and processing register: source, purpose, data subject, sensitivity, owner, volume, retention, destination, access, and legal/contractual basis.
3. Decide whether the target is a synthetic teaching tenant, historical read-only archive, non-production rehearsal, or separately governed clinical system. Never mix these.
4. Define a minimum viable migration set and explicit exclusions.
5. Approve RPO/RTO, outage window, reconciliation tolerances, rollback point, support coverage, and communication plan.

**Exit evidence:** signed scope, classification, architecture/data-flow diagram, field-level mapping owner, threat/privacy assessment, and a no-production-endpoint confirmation for rehearsal.

### Phase 1 — Discover and profile

- Obtain a vendor-supported export, schema/data dictionary, code lists, report definitions, attachment manifest, transaction status definitions, and integration catalogue.
- Capture source version/build, database engine/version, timezone, collation, encoding, storage paths, and export checksums.
- Profile nulls, duplicates, invalid identifiers, orphan foreign keys, inconsistent dates, unit mismatches, free text, legacy code values, duplicate patients, impossible states, and data outside retention.
- Identify source-of-truth status for duplicate module generations (for example legacy/v2/v3/EMR paths) before choosing one record.
- Keep sensitive extracts encrypted and access-logged. Do not paste source rows into issue trackers or chat.

**Exit evidence:** data quality report, source-to-target inventory, unresolved exception list, and approved disposition for every class.

### Phase 2 — Design mappings and transformations

Produce a versioned mapping catalogue with at least:

```text
source table/field and code set
source meaning/state/unit/timezone
target entity/field and code set
transformation/default/normalisation rule
source identifier and target identifier strategy
provenance and confidence
privacy masking or minimisation rule
validation query and owner
exception disposition
```

Define canonical target keys separately from source IDs. Use deterministic crosswalks and idempotent imports. Model corrections and amendments as provenance-preserving events; never overwrite a signed document to make a conversion look clean. Preserve the original author/time/signature status where technically and legally valid; otherwise mark the imported item as historical/unverified and require review.

### Phase 3 — Build and test the converter

- Make extraction, transformation, validation, and load repeatable and versioned.
- Keep conversion code separate from application code and production credentials.
- Run unit tests for each mapping rule and boundary; component tests for identity matching, status transitions, dates/timezones, units, totals, attachments, and code sets.
- Run dry-run loads into an isolated database. Make reruns idempotent and produce an exception report rather than silently dropping rows.
- Verify that no import can create a production integration event, send a notification, print an operational label, or create a real charge.

### Phase 4 — Rehearse and reconcile

Perform at least two full rehearsals using production-shaped volumes in isolated infrastructure:

1. **Technical rehearsal:** time extract/transform/load, restore and rollback; measure downtime and backlog.
2. **Business rehearsal:** workflow owners inspect sampled patient histories, encounters, notes, results, prescriptions, stock, charges, claims, reports, permissions and audit trails.
3. **Failure rehearsal:** bad file, duplicate patient, missing code, partial load, network loss, duplicate retry, corrupted attachment, and rollback.

Reconcile totals by source and target for each entity and key cross-module relation. Use an agreed tolerance; any unexplained difference is a release blocker, not an assumption.

### Phase 5 — Approve cutover

The go/no-go meeting reviews:

- migration and reconciliation results;
- unresolved exceptions and their owners;
- security/privacy/records approval;
- backup and restore evidence;
- support rota and communications;
- external integration keys and endpoint status;
- rollback time and tested restore point;
- open P0/P1 defects and risk acceptance;
- user readiness and training completion.

The product owner may approve a synthetic teaching cutover only within the approved teaching boundary. A future real-data cutover requires institutional sign-off beyond this project document.

## 5. Cutover runbook (future approved environment)

### Recommended pattern

Use a **parallel, staged cutover**: load a validated baseline into a new target, run source and target in a controlled comparison period where operationally safe, then freeze source writes, apply a final delta, reconcile, switch traffic, and retain the source read-only for the approved rollback window. For a small teaching tenant, reset-and-seed is preferred over copying historical data.

### T-minus preparation

- Announce scope, downtime, user actions, help channel, and rollback authority.
- Freeze mapping and converter versions; tag the release candidate.
- Verify target backups, restore, encryption, monitoring, alerting, storage, print/export, queues, and access policies.
- Rehearse the exact cutover on representative data and record duration.
- Confirm external integrations are in the correct sandbox/production mode and that old credentials are not reused.
- Disable nonessential background jobs and outbound notifications during the final load.

### Freeze and final load

1. Announce the freeze and stop source writes according to the approved plan.
2. Capture an immutable source snapshot and checksum.
3. Extract and load the final delta with the same converter version.
4. Re-run duplicate, orphan, status, totals, attachment, and security validations.
5. Reconcile critical open episodes, orders, results, prescriptions, stock, charges, claims, and queues.
6. Enable target access for a small smoke group; keep ordinary users blocked.

### Go/no-go smoke test

Run a named synthetic or approved test case through login, patient/encounter lookup, one clinical read, one permitted update, one restricted action, one report/export, audit inspection, and restore/rollback check. For a clinical target, clinical/records, privacy, security, IT, finance/claims, and product owners must record explicit decisions.

### Switch and hypercare

- Switch the approved hostname/route or access configuration.
- Monitor errors, latency, queues, duplicate events, integration acknowledgements, print/export, stock/charge reconciliation, and access denials.
- Keep the source read-only and support contacts available for the agreed hypercare window.
- Log every incident against the cutover build and do not hot-edit the target without change control.

## 6. Rollback and recovery

Rollback is a planned decision, not an improvised database restore. Trigger it for data corruption, material reconciliation mismatch, unsafe authorization, lost audit/provenance, failed critical workflow, unacceptable performance, or integration duplication that cannot be contained.

1. Declare rollback and stop target writes/outbound integrations.
2. Preserve target logs, audit events, queues, snapshots, and incident timeline.
3. Repoint users to the verified source/read-only or last approved operational system according to the cutover plan.
4. Reconcile any target actions that occurred after switch; do not silently replay them.
5. Restore the target only in an isolated environment for diagnosis unless the approved recovery plan says otherwise.
6. Notify users and owners; document patient/record, financial, stock, claim, and integration effects.
7. Fix, retest, and rehearse before a new go/no-go decision.

For the current synthetic teaching system, rollback means restoring the last approved release and reseeding the named scenario/session. Never restore a broad snapshot over unrelated teaching sessions.

## 7. Post-cutover acceptance

Acceptance requires:

- zero unexplained critical-entity mismatches;
- approved disposition for every exception;
- no migrated secret or unapproved identifier;
- audit and amendment provenance retained or explicitly labelled historical/unverified;
- all critical end-to-end workflows and negative authorization tests pass;
- backup/restore and rollback rehearsals meet RPO/RTO;
- users can find work, reports, printouts, and support procedures;
- source retention, archive, return, destruction, and vendor-exit decisions are recorded.

## 8. Ownership and evidence pack

The migration evidence pack must contain: decision record; data inventory/classification; source snapshot checksum; mapping catalogue; converter version; dry-run and rehearsal reports; exception and reconciliation reports; access logs; security/privacy review; backup/restore/rollback evidence; cutover timeline; go/no-go minutes; incident log; training/readiness record; and post-cutover sign-off.

References: [project charter](../PROJECT_CHARTER.md), [legacy assessment](../LEGACY_ASSESSMENT.md), [full workflow model](../vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md), and [initial findings](../vendor-simrs-assessment-2026-08-21/INITIAL_FINDINGS.md).
