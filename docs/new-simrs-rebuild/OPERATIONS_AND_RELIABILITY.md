# Operations and Reliability Specification

Status: proposed operating baseline

Scope: environments, observability, service objectives, deployment, backup, recovery, continuity, support and lifecycle

Tooling position: compatible with cloud, on-premises or hybrid operation; selection requires evidence and cost/risk evaluation

## 1. Outcome

The rebuilt SIMRS must be operable, recoverable and supportable as a complete system—not merely a set of functioning menus. Every critical workflow has an owner, health signals, failure behavior, runbook, backup/recovery plan, release path and evidence-based service target.

The old assessment could not establish topology, database/queue architecture, monitoring, capacity, backup restoration, release governance or failover. The observed missing update log and `autoupdate.sh` warning are not a release model to reproduce. These unknowns become acceptance work in the clean-slate program.

## 2. Requirement labels

- **COMPAT:** preserve required workflow availability, reports and downtime outputs confirmed from old-system operations.
- **NEW:** clean-slate reliability and operational control.
- **DISCOVERY:** workload, ownership or continuity requirement not yet proven.
- **PROVISIONAL TARGET:** planning value that becomes binding only after owner approval and workload validation.

## 3. Operating principles

1. **NEW:** Design failure modes and recovery with each feature, not after implementation.
2. **NEW:** Prefer graceful degradation: protect patient access, care documentation, medication, bed/ward and critical charging before nonessential analytics or convenience integrations.
3. **NEW:** Separate application health, business workflow health, security audit and external integration health.
4. **NEW:** Automate repeatable build, test, deployment, backup and restoration while retaining approval and traceability.
5. **NEW:** A successful backup job is not recovery evidence; restoration and business reconciliation are required.
6. **NEW:** A successful deployment is not release acceptance; health, synthetic smoke workflows and rollback readiness are required.
7. **COMPAT:** Preserve approved downtime identifiers/forms and reconcile every offline/queued transaction after recovery.
8. **NEW:** Keep portability and vendor exit continuously testable through exports, documented dependencies and reproducible environments.

## 4. Environment model

| Environment | Purpose | Data | External connectivity | Change authority |
|---|---|---|---|---|
| Developer/local | isolated implementation and automated tests | generated synthetic only | emulators or blocked by default | developer within controlled workspace |
| Shared integration | module and contract integration | deterministic synthetic | approved sandbox/emulators | automated pipeline + environment owner |
| UAT/training | parity and user validation | synthetic case packs only | sandbox/emulators; production blocked | UAT/release owner |
| Performance/resilience | load, failover and recovery exercise | generated synthetic at representative scale | controlled substitutes | operations/test owner |
| Staging | production-like deployment and release rehearsal | synthetic or separately approved de-identified minimum | sandbox by default; exceptional approved tests | release and operations approval |
| Production-capable | real-hospital operation if separately authorized | approved operational data | approved production integrations | formal change/release authority |
| Disaster recovery | recovery target and exercise | protected replicated/restored data | controlled failover paths | incident commander/operations authority |

Guardrails:

- unique accounts, databases, storage, keys, secrets and endpoint allowlists per environment;
- unmistakable environment banner and document watermark outside production;
- production data and backups cannot be restored into lower environments through ordinary operator action;
- deployment identity cannot automatically administer patient/clinical data;
- environment configuration is versioned and reviewed, while secret values remain outside source/configuration history.

## 5. Service catalogue and criticality

Assign each capability an availability and recovery class after workflow-owner review.

| Class | Typical capabilities | Downtime posture | Dependency expectation |
|---|---|---|---|
| C1 — care/transaction critical | patient identity, encounter registration, IGD/triage, inpatient census/bed, clinical documentation, critical orders/results, medication dispense, core charging | defined rapid recovery and tested downtime workflow | avoid hard dependency on noncritical reports/notifications; queue external work where safe |
| C2 — operationally important | scheduling/queues, routine diagnostics, warehouse, cashier, claims preparation, documents/print | bounded interruption with owned work queue and catch-up | degraded/manual process documented |
| C3 — deferrable | management analytics, bulk/statutory reports not due immediately, nonurgent notifications, selected teaching administration | restore within agreed business window | must not block C1/C2 writes |
| C4 — optional/experimental | AI assistance, nonessential IoT, future pilots | can be disabled without workflow loss | no critical workflow dependency |

The classification is not based on the old menu category alone. For example, a specific wristband/label or result view may be C1 even if document/report infrastructure is normally C2/C3.

## 6. Service-level objectives and agreements

The values below are starting points for discussion, not commitments. Establish measured baselines and owner-approved targets before procurement or production acceptance.

### 6.1 Provisional SLO framework

| Indicator | Campus pilot target | Production-capable target | Measurement rule |
|---|---:|---:|---|
| C1 monthly availability | 99.0% during scheduled teaching/use windows | 99.9% | successful valid requests/business probes; approved maintenance separately reported |
| C2 monthly availability | 98.5% during scheduled use | 99.5% | capability-specific success, not server ping only |
| Interactive response | 95% under 2.5 s; 99% under 5 s for ordinary tasks | same initial target, then tune by workload | server plus network from representative user point; exclude declared long jobs |
| Critical write acknowledgment | 99% under 3 s unless workflow is explicitly asynchronous | target finalized by workflow | committed or clearly `pending`; never false success |
| C1 background work age | 99% within owner-defined window | integration/job specific | oldest unprocessed age and completion, not queue depth alone |
| Restore-test success | 100% of scheduled exercises | 100% | includes integrity and business reconciliation |
| Critical alert acknowledgment | during staffed pilot window | 15 minutes, 24x7 if required | tracked from alert to responsible responder |

### 6.2 SLA content

Any internal or vendor SLA must define:

- covered services, users, locations and hours;
- availability formula and exclusions;
- severity based on patient/workflow/data impact;
- response, update and restoration targets;
- support channel, on-call and escalation contacts;
- data-loss/recovery objectives;
- maintenance notice and emergency-change rules;
- security incident and breach notification obligations;
- evidence, reporting, service credits/remedies and chronic-failure exit rights;
- dependency/shared-responsibility boundaries.

Do not use one availability percentage to hide repeated failure of a single critical workflow.

## 7. Recovery objectives and continuity

Final RPO/RTO values require business impact analysis. Planning ranges:

| Capability/data | Provisional maximum RPO | Provisional RTO | Continuity need |
|---|---:|---:|---|
| C1 clinical/encounter/medication/stock/financial writes | 5–15 minutes production; one teaching exercise step in campus | 1 hour production; 4 hours campus | downtime identity, orders/medication and reconciliation procedure |
| C2 operational services | 30 minutes | 4 hours | queued/manual work and catch-up |
| C3 reports/analytics projections | 24 hours or reconstructable | 1 business day | rebuild from operational sources |
| Configuration/master data | 15–60 minutes | 4 hours | versioned export and approval records |
| Audit/security evidence | near-zero loss objective | 1 hour for ingestion; longer query restoration if protected copy exists | local buffering and protected secondary sink |
| Documents/images | 15–60 minutes based on criticality | 4–8 hours | metadata/hash reconciliation and alternative access |

RPO is measured from the latest recoverable, verified point—not the configured backup interval. RTO ends only when the workflow is usable and reconciled, not when servers boot.

### 7.1 Downtime workflow requirements

- version-controlled downtime forms/procedures and responsibility matrix;
- temporary identifiers that cannot collide with normal numbering;
- minimum clinical, medication, bed, charge and contact data capture;
- controlled paper/offline custody and later data-entry authorization;
- duplicate detection and two-person reconciliation where risk warrants;
- audit linkage between downtime artifact and entered electronic record;
- clear closure report of missing, duplicate, unresolved and corrected items.

## 8. Observability model

```text
User/business probes ---->
Application metrics ------> health dashboards -> alerts -> incident/case
Structured logs ---------->
Distributed traces ------->
Queue/job/integration ---->

Security audit -----------> separate protected audit store/review
```

### 8.1 Required signals

| Signal | Examples |
|---|---|
| User experience | login success, task completion, latency/error by route/use case, client errors |
| Business workflow | encounters stuck by state/age, unsigned results, medication queue age, unreconciled charges, claim rejection age |
| Application | request rate/error/latency, saturation, dependency calls, cache effectiveness |
| Data platform | connection use, slow operations, locks/deadlocks, replication/backup lag, storage growth |
| Queue/jobs | oldest item age, throughput, retry, poison/dead-letter count, scheduler missed runs |
| Integration | success/rejection/timeout by partner and contract, circuit state, credential/certificate expiry, reconciliation backlog |
| Documents | generation failure, signature failure, object mismatch/hash failure, staging expiry |
| Infrastructure | compute/memory/disk/network saturation, node health, certificate and capacity thresholds |
| Security | authentication anomalies, privilege changes, break-glass, bulk access/export and audit ingestion gaps |

### 8.2 Logging rules

- Use structured logs with timestamp, severity, service/module, environment, correlation/trace ID, safe error code and deployment version.
- Do not log passwords, tokens, keys, raw authorization headers, full clinical documents or unnecessary identity/health fields.
- Use stable error codes so runbooks and support do not depend on fragile message text.
- Retention and access differ by log class; debug logs are not audit records.
- Synchronize time and alert on material clock drift.

### 8.3 Alert design

Every alert has an owner, severity, threshold rationale, runbook, escalation and closure signal. Alert on user/business impact and failure age, not only component utilization. Review noisy/non-actionable alerts and do not normalize persistent warnings.

## 9. Backup architecture

Apply a recoverable multi-copy strategy appropriate to the selected hosting model:

- frequent transaction/log or equivalent point-in-time protection for critical structured data;
- full and incremental/snapshot protection as appropriate;
- coordinated protection of database, document/object data, configuration, master data, key references and necessary queue/message state;
- at least one off-site or failure-domain-separated copy;
- at least one immutable or offline-protected copy for ransomware/privileged compromise;
- encryption and separate least-privilege backup credentials;
- automated completion, age, size and integrity monitoring;
- documented retention tiers and secure expiry.

Backups must exclude or separately protect secrets according to the key/secret recovery design. Losing the only decryption key makes a backup unusable; storing it beside the backup defeats separation.

### 9.1 Restore verification

At least quarterly for production-capable operation, and before each major pilot/release boundary:

1. select a declared recovery point;
2. restore into an isolated authorized environment;
3. verify schema/migrations and application compatibility;
4. reconcile entity counts, referential integrity, stock/financial balances, documents/hashes and audit continuity;
5. run synthetic critical workflows and report generation;
6. measure achieved RPO/RTO;
7. record gaps, owner and closure date;
8. securely dispose of the exercise environment.

## 10. Disaster recovery

DR design follows the approved criticality and hosting topology; it is not assumed to require a particular cloud or second data center.

Required scenarios:

- loss of application node/deployment;
- primary datastore loss or corruption;
- object/document store loss;
- region/site/network loss;
- credential/key/certificate compromise or expiry;
- ransomware/privileged destructive action;
- bad release/schema migration;
- major external partner outage;
- vendor/support unavailability.

The DR plan defines detection, authority to declare, communication, failover/fallback, data-loss assessment, verification, reconciliation, failback and post-incident review. Exercise at least annually for the full production-capable service, with more frequent component restore and tabletop tests.

## 11. Release and deployment control

### 11.1 Release pipeline requirements

`reviewed source -> repeatable build -> dependency/SBOM scan -> automated tests -> artifact integrity/provenance -> environment deployment -> migration -> smoke/business probes -> approval -> monitoring -> rollback/roll-forward evidence`

Minimum gates:

- code/config/schema changes reviewed by an independent authorized person;
- reproducible artifact created once and promoted, not rebuilt differently per environment;
- unit, module, contract, integration, workflow and security tests proportionate to change;
- database migration compatibility and restoration/roll-forward plan;
- synthetic smoke cases for login, patient/encounter, critical write, document/report and enabled integrations;
- release notes mapping parity items, improvements, risks and operational changes;
- protected production deployment identity and approval;
- post-deployment observation window and explicit acceptance.

### 11.2 Deployment strategies

Use rolling, blue/green, canary or planned maintenance according to state compatibility, infrastructure and risk. The chosen strategy must preserve session/workflow integrity and database compatibility.

### 11.3 Rollback and roll-forward

- Application rollback is permitted only when the database and message contracts remain compatible.
- Destructive schema changes use expand/migrate/contract or equivalent staged evolution.
- Prefer roll-forward correction once irreversible real transactions exist.
- Stop criteria are defined before deployment: error rate, latency, audit gap, workflow failure, reconciliation backlog or security anomaly.
- A release that cannot be safely reversed has explicit owner approval, backup checkpoint and tested recovery route.

Direct server edits, unmanaged scheduled update scripts and deployment from a developer workstation are prohibited for production-capable operation.

## 12. Change and configuration management

- Version non-secret configuration and associate it with environment, owner and deployment.
- Validate configuration schema, allowed endpoint domains and incompatible combinations before activation.
- Use feature controls with owner, purpose, environments, default, expiry/review date and audit.
- Separate ordinary business master changes from technical configuration and secret administration.
- Emergency changes require incident/change reference, limited scope, retrospective review and normalization into the standard pipeline.
- Maintain a current service/dependency catalogue, architecture view and ownership map.

## 13. Background jobs and integration operations

- Jobs are idempotent or explicitly non-retryable.
- Scheduler records intended run, actual start/end, owner, result and next action.
- Workers have bounded concurrency, retry/backoff, poison-message quarantine and graceful shutdown.
- Monitor oldest age and business deadline, not queue count only.
- Manual retry requires authorization and preserves original/corrected context.
- Reconciliation compares authoritative internal state, sent messages and external acknowledgments.
- Planned external downtime opens a named work queue and catch-up plan; it does not silently disable the integration.

## 14. Capacity and performance engineering

Before binding targets, measure or estimate:

- concurrent users by role, site and peak period;
- registrations/encounters/orders/results/prescriptions/charges/receipts/claims per hour;
- report/export concurrency and size;
- document/image volume and retention growth;
- integration message rate, burst and partner rate limits;
- database/storage growth, backup window and recovery throughput.

Test:

1. representative end-to-end task mix, not endpoint microbenchmarks only;
2. expected peak, two-times headroom and controlled overload;
3. long-running reports separated from C1 traffic;
4. lock/contention and concurrent edit/bed/stock/receipt scenarios;
5. integration slowdown/failure and queue catch-up;
6. node/worker/datastore failure under load;
7. backup/restore and deployment at representative data volume.

Define admission control, bounded queues, timeouts and user-visible pending states. More servers do not fix an unbounded report or contested transaction design.

## 15. Incident management

Severity is based on patient/workflow safety, confidentiality, integrity, data loss, availability, scope and duration.

| Severity | Example | Initial posture |
|---|---|---|
| SEV-1 | critical care workflow unavailable, confirmed major data loss/corruption, widespread restricted-data exposure | immediate incident command, containment/continuity and executive/security/privacy notification |
| SEV-2 | major module/site failure, significant integration/claim/stock backlog, high-risk security event | rapid owner response, workaround and frequent updates |
| SEV-3 | limited degradation with workaround, small controlled backlog | business-hours or defined support response |
| SEV-4 | minor defect/request without material workflow impact | normal backlog/change process |

Runbooks at minimum:

- login/identity provider failure;
- patient search/registration unavailable;
- datastore saturation/corruption;
- clinical write or result/signature failure;
- pharmacy/stock inconsistency;
- bill/payment/refund inconsistency;
- BPJS/SATUSEHAT/E-Klaim/LIS/PACS outage or rejection spike;
- document/print/TTE failure;
- queue backlog or scheduler failure;
- expired certificate/secret;
- suspected account/secret compromise or data breach;
- bad deployment/schema migration;
- backup failure and restore/DR declaration.

Each runbook states detection, impact check, safe containment, continuity action, diagnostics, recovery, reconciliation, communication, evidence preservation, escalation and closure.

## 16. Security and dependency operations

- Maintain a software bill of materials for every release and deployed component.
- Track supported versions, end-of-support dates and owners for application, operating system, datastore, queue, proxy, libraries, agents and devices.
- Scan source, dependencies, artifacts, configuration and infrastructure according to risk; triage findings with reachability and impact evidence.
- Define patch targets by severity/exposure, emergency release path and accepted-risk expiry.
- Perform recurring access review, secret/certificate rotation, vulnerability verification and privileged-support review.
- Conduct independent security assessment before production-capable use and after material architecture/exposure change.

## 17. Support, ownership and operating model

Every service/module/integration has:

- business/workflow owner;
- technical owner;
- data steward;
- security/privacy contact;
- support hours and escalation;
- dashboard, alerts and runbooks;
- dependency and vendor contacts;
- RPO/RTO, SLO and maintenance policy;
- current known risks and lifecycle status.

The lecturer–administrator model may remain appropriate for teaching workflow management, but infrastructure, secret, security-audit and production deployment duties remain separately controlled.

## 18. Portability and exit readiness

At least annually and before contractual renewal, prove:

- complete documented export of patients, encounters, clinical records, terminology, documents, charges, stock, claims, master/configuration data and required audit metadata in usable formats;
- stable identifiers, relationships, code-system versions, hashes and provenance survive export;
- deployment and recovery documentation is current;
- source/build/dependency ownership and licenses are known;
- keys, domains, certificates, external accounts and integration registrations have named organizational ownership;
- transition support, data return/deletion and access revocation are contractually defined;
- a qualified replacement team can restore and operate the system from authorized materials.

Vendor exit is a reliability scenario, not only a procurement clause.

## 19. Operational acceptance gates

### Before developer/integration environment acceptance

- repeatable environment creation and synthetic seed/reset;
- health, logs, metrics and trace correlation;
- automated test and artifact pipeline;
- no production data, credentials or endpoints.

### Before parity UAT

- service catalogue and owners;
- dashboards/alerts/runbooks for UAT-critical workflows;
- backup and successful isolated restore;
- deployment and rollback rehearsal;
- failure-mode tests for jobs and enabled integrations;
- performance baseline at expected pilot concurrency.

### Before production-capable acceptance

- approved service targets, support/on-call and incident communications;
- representative capacity, soak, resilience and failover evidence;
- achieved RPO/RTO restoration and business reconciliation;
- security assessment and patch/SBOM lifecycle;
- downtime and external-outage exercises;
- staged production deployment with recovery checkpoint;
- data portability and vendor-exit test.

## 20. Architecture/operations decision records

These complement the central ADR register in `REFERENCE_ARCHITECTURE.md`.

| ADR | Proposed decision | Main trade-off |
|---|---|---|
| ADR-OPS-001 | Define service targets by workflow criticality, not one application-wide SLA | more governance, clearer real impact |
| ADR-OPS-002 | Maintain strict isolated environments and promote immutable artifacts | additional environment management, lower drift and leakage risk |
| ADR-OPS-003 | Separate audit from operational logs | added storage/pipeline, materially stronger accountability |
| ADR-OPS-004 | Require point-in-time protection plus immutable/failure-domain-separated backup | additional cost, lower corruption/ransomware risk |
| ADR-OPS-005 | Use staged, compatible schema evolution and roll-forward capability | slower destructive cleanup, safer releases |
| ADR-OPS-006 | Treat external failures as queues/reconciliation, not core-state success/failure shortcuts | more operational ownership, reliable outcomes |
| ADR-OPS-007 | Keep hosting topology undecided until workload, ownership, five-year cost and recovery evidence exist | delays vendor selection, avoids architecture-by-marketing |

## 21. Open decisions

1. Campus-only operating hours or real-hospital 24x7 coverage?
2. Approved workflow criticality, RPO/RTO and SLO by module?
3. Expected concurrent users, sites, transaction volume and growth?
4. Internal versus vendor responsibilities for application, database, security, network, backup and integrations?
5. Hosting constraints, data-location requirements and five-year cost ceiling?
6. Required downtime forms/processes and who reconciles them?
7. Incident, privacy/breach and clinical-safety escalation contacts?
8. Which production dependencies must continue when internet/national/payer services are unavailable?
