# Data and Integration Strategy

Status: proposed clean-slate design baseline

Scope: operational data, master data, documents, reporting, migration and external interoperability

Tooling position: technology- and vendor-agnostic

## 1. Outcome

The new SIMRS will use one governed source of truth for each business concept, preserve a traceable history of clinical and financial changes, and isolate external integrations from core workflow state. Old-system outputs and verified data meanings are compatibility inputs; the old physical schema and direct integration patterns are not.

This strategy supports all workflow areas described in the [full old-system workflow model](../vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md), including patient access, outpatient, emergency, inpatient, diagnostics, surgery, pharmacy, warehouse, medical records, claims, cashier and 117 visible reports.

## 2. Requirement labels

- **COMPAT:** required to preserve a verified old-system workflow, field meaning, output or external obligation.
- **NEW:** a clean-slate data or integration design decision.
- **DISCOVERY:** unknown old behavior that must be demonstrated or agreed.
- **DEFERRED:** retained in the roadmap but not required for first parity release.

## 3. Data principles

1. **NEW — one owner per fact.** Patient identity, encounter status, result verification, stock balance, bill balance and claim state each have one authoritative write owner.
2. **NEW — append history, do not rewrite reality.** Corrections create attributable versions or compensating transactions. Clinical and financial history is not silently overwritten.
3. **NEW — separate identifiers from meaning.** Internal stable identifiers are never derived solely from mutable names, national identifiers, medical-record display format or external-system IDs.
4. **NEW — explicit time.** Store event time, effective clinical/business time, recording time and actor where they differ. Use an unambiguous time zone policy and preserve source time zone/offset.
5. **NEW — provenance everywhere.** Derived reports, imported results, external acknowledgments and migrated rows retain source, version and transformation history.
6. **NEW — minimum necessary access.** Operational, analytics, teaching and integration datasets have distinct access policies and purposes.
7. **COMPAT — outputs stay explainable.** A parity report or bill must trace to authoritative source transactions and versioned definitions.
8. **DISCOVERY — legacy meaning is verified before mapping.** Similar labels across v2/v3/EMR routes are not assumed to share semantics.

## 4. Conceptual data model

```text
Person/Patient ----< Identifier / Address / Contact / Merge history
      |
      +----< Appointment/Referral ----< Queue event
      |
      +----< Encounter ----< Episode/Admission ----< Bed stay/Transfer
                  |
                  +----< Care team / Consent / Assessment / Note
                  +----< Diagnosis / Procedure / Clinical form version
                  +----< Order ----< Specimen/Service ----< Result version
                  +----< Medication order ----< Dispense/Return/Admin handoff
                  +----< Charge ----< Bill ----< Receipt/Refund/Receivable
                  +----< Claim ----< Grouping/Submission/Adjudication/Payment
                  +----< Document/Signature/Release record

Item/Product ----< Lot/Batch/Expiry ----< Stock ledger >---- Location
      |                                      |
      +----< Requisition/PO/Receipt/Transfer/Adjustment/Stocktake

All governed records ----< Audit event / Integration message / Data-quality issue
```

### 4.1 Identity and organization

| Entity | Minimum responsibility |
|---|---|
| Person and Patient | demographics, contact preferences, deceased indicator, identity-quality status |
| Patient Identifier | type, issuing authority, value, status, validity and verification; values protected according to classification |
| Patient Merge | source/target identifiers, reason, approval, timestamp, affected-record reconciliation and unmerge policy |
| Practitioner/Worker | person link, profession, registration/license reference, organization/unit affiliation and active period |
| Organization/Facility/Unit | hierarchy, code systems, location, service capability and effective dates |
| Room/Bed | physical identity, class, serviceability, isolation attributes and occupancy state |
| User Account | authentication identity linked to a person/service principal; never used as the clinical author identity by implication alone |

### 4.2 Care delivery

| Entity | Minimum responsibility |
|---|---|
| Appointment/Referral | source, reason, requested service, schedule, status, authorization and cancellation history |
| Encounter | class/type, service unit, patient, responsible care team, arrival/start/end, status and disposition |
| Admission/Episode | admitting context, payer/class, bed/ward history, transfers, leave and discharge outcome |
| Clinical Entry | author, verifier/signer, patient/encounter context, form/schema version, effective/recorded time, status and amendment chain |
| Diagnosis/Procedure | code system/version, code, display, certainty/context, author, rank and effective period |
| Order | requester, priority, requested service/product, clinical reason, status and cancellation reason |
| Result | order link, performer, value/units/ranges or structured narrative, status, verifier and correction chain |
| Medication | prescribed item/ingredient, dose, route, frequency, duration, substitution and clinical checks |

### 4.3 Revenue, claims and supply chain

| Entity | Minimum responsibility |
|---|---|
| Price Book/Tariff | item/service, payer/class conditions, price components, currency, validity, approval and version |
| Charge | source service event, quantity, unit price snapshot, adjustments, status and reversal link |
| Bill/Account | patient/encounter/payer scope, line aggregation, balance, status and responsible party |
| Financial Transaction | receipt, deposit, refund, allocation, write-off or settlement with immutable journal characteristics |
| Coverage/Eligibility | payer/plan, member reference, validity, verification source/time and authorization references |
| Claim | encounter/bill scope, grouping version, document checklist, submission version, adjudication and reconciliation state |
| Product/Item | clinical/supply classification, unit of measure, conversion, controlled status, storage conditions and active period |
| Stock Ledger Entry | location, item, lot/batch/expiry, quantity delta, reason, source document, actor and resulting balance reference |
| Procurement Document | request, approval, purchase order, supplier, receipt, discrepancy, return and invoice links |

### 4.4 Documents, messages and evidence

- Store generated/uploaded documents as governed objects with media type, size, cryptographic hash, owner record, classification, version, author, creation/source time, retention class and signature state.
- Store templates separately with effective dates, version, approval and rendering compatibility.
- Store integration messages with correlation ID, contract version, endpoint environment, direction, timestamp, status, attempt count, response/acknowledgment reference and redacted diagnostic detail.
- Never place secrets, access tokens or unnecessary health information in message diagnostics or general application logs.

## 5. Identifier strategy

**NEW:** Use opaque, non-reused internal identifiers for entities. Human-readable numbers are managed as separate business identifiers with their own issuing rules.

| Identifier | Rule |
|---|---|
| Patient internal ID | stable across merges; never exposed as proof of identity |
| Medical record number | facility-issued business identifier, formatted for display, uniqueness and reuse policy explicit |
| Encounter/admission number | unique within declared issuing scope and never recycled |
| Order/specimen/accession number | independently traceable; barcode/label checks required |
| Bill/receipt/claim number | immutable reference with controlled cancellation/reversal, not deletion |
| External ID | store system, environment, type, value, validity and mapping status; production and sandbox IDs never share a namespace |
| Correlation/idempotency key | unique per logical external request and retained through retry/reconciliation |

**DISCOVERY:** The old number formats, reset rules and use on printed artifacts must be captured. Compatibility can retain display formats while internal identifiers remain clean.

## 6. State, history and transaction rules

- State transitions are explicit and validated against a per-domain state machine.
- Records that are clinically signed, financially posted, stock-affecting or externally submitted are not hard-deleted.
- Correction creates a new version or compensating entry linked to the superseded record, with reason and authorization.
- Multi-module workflows use a local transaction within the owning module and durable events for downstream work.
- If a user-facing action requires several postings, the interface shows `completed`, `pending downstream`, `partially reconciled` or `failed with owner`; it never reports success while silently losing a side effect.
- Use a transactional outbox or equivalent atomic mechanism so committed business facts and publishable events cannot diverge.
- Consumers use an inbox/deduplication record or equivalent. At-least-once delivery must not duplicate charges, stock movements, claims or messages.
- Long-running workflows use an explicit process manager/saga with compensation and human intervention states.

## 7. Master-data governance

The old system exposes extensive master and settings surfaces. The rebuild separates ordinary master maintenance, sensitive configuration and secret administration.

### 7.1 Master classes

| Class | Examples | Governance |
|---|---|---|
| Identity/organization | facility, unit, clinician, specialty, room, bed | named steward; effective-dated; uniqueness and reference validation |
| Clinical terminology | diagnosis, procedure, laboratory, imaging, medication, unit of measure | terminology version, source/license, mapping status, clinical/RMIK approval |
| Service and finance | service catalogue, tariff, payer, class, discount reason | dual approval where financially material; immutable price snapshots on transactions |
| Supply chain | product, supplier, location, batch rules, reorder parameters | pharmacy/warehouse owner; unit-conversion validation |
| Forms/documents | clinical forms, labels, certificates, report layouts | versioned template approval and retirement |
| Integration configuration | endpoints, organization IDs, feature flags, certificates | environment-scoped change control; secrets stored separately |
| Teaching | course, cohort, scenario, synthetic identity namespace, reset policy | lecturer owner; automatic expiry and purge schedule |

### 7.2 Change lifecycle

`draft -> reviewed -> approved -> scheduled/effective -> superseded/retired`

Every change records requester, reviewer/approver, reason, old/new value excluding secret content, effective time, affected environments and impact assessment. Backdating requires an elevated, audited workflow.

## 8. API contract standards

The exact protocol is chosen later. Any synchronous application or integration API must provide equivalent semantics:

- authenticated caller and authorized action;
- versioned contract and compatibility policy;
- request/correlation ID and idempotency key for retryable mutations;
- validation errors tied to fields/rules;
- machine-readable error code plus safe human message;
- pagination, filtering and deterministic ordering for collections;
- concurrency control for mutable resources, such as version/precondition checking;
- declared time, terminology and unit formats;
- no silent truncation or coercion;
- rate/size limits and timeouts;
- audit classification for view, mutation, export and privileged operations.

Illustrative error contract, independent of transport syntax:

```text
code: ENCOUNTER_STATE_CONFLICT
message: Safe user-facing explanation
correlation_id: traceable support reference
field_errors: optional structured validation issues
retryable: true/false
current_version: optional concurrency reference
```

Contract lifecycle:

1. document consumer and owner;
2. publish schema and examples without real patient data;
3. run consumer/provider contract tests;
4. support additive evolution within a version;
5. announce deprecation and measure use;
6. remove only after consumers migrate and the retention window closes.

## 9. Event standards

Each domain/integration event includes:

- unique event ID and event type;
- schema version;
- aggregate/entity ID and version;
- occurred time and recorded/published time;
- producer context;
- correlation and causation IDs;
- environment and data-classification marker;
- minimum necessary payload or a reference to authorized retrieval.

Do not broadcast full clinical notes, identifiers or documents merely for consumer convenience. Consumers retrieve protected detail through an authorized API when needed.

## 10. Integration architecture

```text
Domain module
   | publishes fact / requests governed operation
   v
Integration port -> environment-specific adapter -> external system
   |                         |
   +-> message journal       +-> acknowledgment/error
   +-> retry schedule
   +-> reconciliation queue -> human owner
```

Each adapter owns external authentication, format transformation, rate limits, timeout, retry classification and protocol quirks. It does not own the clinical or financial business state.

### 10.1 Required integration dossiers

The observed configuration surface includes BPJS/VClaim/Antrol/Aplicares, SATUSEHAT, E-Klaim/iDRG, LIS, PACS, TTE/e-sign, RS Online/SIRS/SIRANAP, PDF/file transfer, WhatsApp, ERP/accounting, IoT and OpenAI/Aisha. For each proposed integration, maintain:

1. business owner and technical owner;
2. purpose and minimum data set;
3. production and sandbox endpoint ownership;
4. contract/version, terminology and identifier mappings;
5. authentication, certificate and secret-rotation method;
6. network path and allowlist;
7. request, acknowledgment, rejection and reconciliation states;
8. idempotency and duplicate rules;
9. timeouts, retry/backoff and circuit-breaking behavior;
10. monitoring, support and escalation;
11. retention, privacy and audit classification;
12. synthetic contract, failure and recovery test evidence;
13. downtime/manual fallback and re-entry procedure;
14. decommission/export obligations.

### 10.2 Failure taxonomy

| Failure | Automatic action | Human action |
|---|---|---|
| transient network/server response | bounded retry with backoff and jitter | alert if retry-age/objective breached |
| rate limit | honor server instruction or configured delay | capacity/contract review if persistent |
| authentication/certificate | do not retry aggressively; open circuit | integration/security owner rotates or corrects configuration |
| validation/business rejection | no blind retry | owning work queue corrects source or records accepted exception |
| duplicate/unknown acknowledgment | query/reconcile using idempotency and external reference | operator resolves ambiguous outcome before resubmission |
| contract/schema drift | quarantine affected messages | adapter owner updates mapping after impact/testing |

Dead-letter storage is a work queue, not a data graveyard. Every item has an owner, age, severity, safe diagnostic summary and closure outcome.

## 11. Strict sandbox and production separation

**NEW and non-negotiable for campus use:**

| Control | Campus/teaching | Production-capable hospital environment |
|---|---|---|
| Data | deterministic synthetic identities and cases only | approved operational data under formal governance |
| Accounts | cohort-bound, time-limited, resettable | named workforce accounts linked to employment and role |
| External endpoints | emulator/vendor sandbox or blocked | explicitly approved production endpoints |
| Credentials/certificates | unique sandbox material | separately managed production material; never copied to teaching |
| Identifiers | unmistakable synthetic namespace | authoritative facility/national identifiers |
| Notifications | sink/test recipients | verified real recipient routing and consent/purpose checks |
| Exports | watermarked synthetic and constrained | minimum-necessary, authorized and audited |
| Reset | instructor-approved case reset | no reset; governed correction/retention workflow |

Production-to-teaching database cloning is prohibited unless a separately approved de-identification process proves that re-identification risk and hidden document/log content are controlled. Synthetic generation is the default.

Environment selection must not be a user-editable URL on an ordinary settings page. Build/deploy policy and protected configuration determine the allowed endpoint set.

## 12. SATUSEHAT, payer and clinical-system boundaries

- **COMPAT:** Preserve user-visible statuses, identifiers and document outputs required for verified payer/national workflows.
- **NEW:** Map internal concepts to external standards in adapters; do not shape the entire core schema around one external payload.
- **NEW:** Store external resource/message identifiers with system and environment provenance.
- **NEW:** Validate terminology, reference integrity and required profiles before transmission.
- **NEW:** Keep submission, acknowledgment and reconciliation separate. `sent` is not `accepted`, and `accepted` is not `reconciled`.
- **DISCOVERY:** Confirm which old SatuSehat, BPJS and E-Klaim/iDRG surfaces are current, licensed and required. The observed old configuration is not operational proof.
- **DEFERRED:** Live external submission remains disabled until sandbox evidence, credentials, legal purpose, operations and security gates pass.

## 13. Reporting and analytics

The 117 old report menus are compatibility scope, but do not justify 117 independent query implementations.

**NEW:** Build governed data products/read models by subject area: patient access, encounters, clinical/coding, diagnostics, bed/census, pharmacy, inventory, revenue, claims, record quality, public-health/statutory reporting and audit/operations.

Every report definition records:

- business owner and intended decision/use;
- exact numerator, denominator, inclusion, exclusion and date basis;
- source entities/fields and transformations;
- terminology and grouping version;
- refresh/freshness class and last successful refresh;
- row-level access and suppression/masking rules;
- output layout/version and reconciliation test;
- parity reference and approved differences from the old output.

Operational reports may use near-real-time projections. Management/statutory reports use controlled snapshots so reruns remain explainable. Reports must not query integration staging tables as if they were authoritative clinical facts.

## 14. Data quality controls

Apply quality rules at the earliest responsible boundary:

- completeness: mandatory conditional fields and document/checklist status;
- conformance: format, units, terminology, identifiers and schema version;
- plausibility: clinical/business ranges and cross-field relationships, with override workflow where appropriate;
- consistency: encounter, charge, stock, claim and external-message reconciliation;
- uniqueness: duplicate patient, order, receipt, claim and integration submission detection;
- timeliness: late entry, late verification and integration-age alerts;
- provenance: source, author/device/system and transformation version.

Quality failures that require judgment enter role-owned queues. Do not silently discard, default or repair protected records.

## 15. Legacy migration strategy

Clean-slate implementation does not mean automatic legacy data migration.

### 15.1 Migration decision classes

| Class | Default approach |
|---|---|
| Synthetic teaching cases | regenerate in the new model from controlled scenario definitions |
| Master/configuration data | extract, cleanse, approve and import with effective dates and mapping report |
| Active operational records | migrate only if a real-hospital go-live is approved and clinical/financial continuity requires it |
| Historical closed records | retain in a controlled legacy archive or migrate selectively based on legal/operational need |
| Credentials/secrets | never migrate as data; issue/rotate separately |
| Audit/integration logs | preserve as evidence if required; do not transform into new-system audit events without provenance |

### 15.2 Migration stages

1. establish data ownership, legal basis, provenance and retention;
2. profile source structure and quality without copying secrets/unnecessary identifiers;
3. define source-to-target mappings and unresolved-value queues;
4. rehearse deterministic extract-transform-load with counts, hashes and rejects;
5. validate clinical, stock, financial and claim balances with business owners;
6. run dress rehearsal and rollback;
7. perform controlled cutover, reconciliation and sign-off;
8. secure, archive or dispose of extracts under an approved schedule.

No migration is accepted solely because row counts match. Referential integrity, critical field reconciliation, document accessibility and business balances must also match.

## 16. Retention, archive and disposition

Retention periods require current legal, academic and organizational approval. The architecture must support policy by record class, legal hold, archive state, retrieval evidence and authorized disposition.

- A user-facing delete is normally an operational status, not physical erasure.
- Privacy correction, retention expiry and legal hold are distinct workflows.
- Backups follow their own expiry and cannot be used as an undocumented permanent archive.
- External copies and exports are included in disposition/reconciliation where the organization controls them.
- Disposition produces an audit record without retaining the disposed protected content.

## 17. Data and integration acceptance gates

Before parity acceptance:

- every menu/workflow maps to an owning data context;
- critical entities, fields, states and cross-module postings are specified;
- report definitions reconcile against synthetic source transactions;
- duplicate, correction, cancellation, reversal and concurrency cases pass;
- integration emulators cover success, timeout, rejection, duplicate and ambiguous acknowledgment;
- message retry and reconciliation queues show ownership and closure;
- no campus route can reach a production endpoint or use production credentials;
- export and restoration prove that records remain usable outside the live application.

Before any production-capable operation:

- data protection, security, infrastructure and legal gates are approved;
- each live integration passes vendor/national sandbox or certification requirements;
- master-data owners sign off;
- migration is reconciled and reversible;
- downtime, reconciliation and incident runbooks are exercised.

## 18. Open decisions

1. Which identifier and printed-number formats are true compatibility requirements?
2. What is the authoritative terminology/version source for each clinical, claim and statutory output?
3. Which reports require historical values to reproduce the definition that was valid at the time?
4. Which external systems are enabled in the first parity pilot?
5. Are any real old records legally and operationally necessary in the new system?
6. What reporting freshness and archive retrieval targets apply by report class?
7. Who owns rejection and reconciliation queues outside normal hours?
