# Testing and User Acceptance Strategy

**Product:** New SIMRS rebuild for UEU campus teaching and simulation  
**Status:** Working baseline for the clean-slate build  
**Scope:** Full hospital workflow model, with the first executable increment being the synthetic outpatient journey

## 1. Purpose and boundaries

This document defines how the rebuild proves that it is safe, coherent, usable, and faithful to the old system's operational intent before users are asked to suggest improvements. It is deliberately tool-agnostic: a team may use any framework, database, CI service, browser automation tool, or hosting platform, provided that the evidence and exit criteria below are met.

The source system is a **functional reference**, not a code or database migration source. The vendor assessment identified 268 visible menu items across 13 categories, but a visible menu does not prove that a transaction works or that an action is authorized. Tests therefore validate end-to-end work and cross-module handoffs, not menu screenshots alone.

The initial environment is a teaching/simulation system:

- synthetic patients and synthetic staff only;
- no production BPJS, SATUSEHAT, payment, LIS, PACS, or clinical transmission;
- `SIMULATION` or `SANDBOX INTEGRATION` visible in the interface, printouts, and exports;
- resettable test cases with deterministic IDs and an auditable event history.

The prior vendor system, earlier prototype, and new rebuild remain separate evidence boundaries. No test may silently turn a mock-up or teaching fixture into a clinical system.

## 2. Quality principles

1. **Test the patient journey.** A workflow is not accepted because each page loads; it must carry one encounter through the responsible departments.
2. **Parity before improvement.** First determine whether the new system can perform the old system's required work. Capture desired changes separately until parity is accepted.
3. **Synthetic by default.** Fixtures are generated, labelled, resettable, and incapable of reaching a production endpoint.
4. **Fail closed.** Denied roles, missing approvals, invalid states, duplicate submissions, and integration failures must be safe and visible.
5. **Provenance is part of correctness.** Every material entry, correction, review, approval, export, and integration event has actor, time, context, reason, and revision history.
6. **Evidence over assertion.** Each accepted scenario has a test ID, preconditions, steps, expected result, actual result, tester, build, timestamp, and retained evidence.
7. **Users validate work; engineers validate mechanics.** UAT does not replace automated tests, security testing, backup restoration, or performance testing.

## 3. Test environments and data

| Environment | Purpose | Data and integrations | Change control |
|---|---|---|---|
| Local/developer | Unit, component, migration and failure tests | Generated fixtures; integration stubs | Per branch; disposable |
| CI/test | Repeatable automated checks | Seeded synthetic dataset; contract stubs | Build is immutable for the run |
| Staging/UAT | Faculty and department acceptance | Synthetic dataset; approved sandbox adapters only | Release candidate, protected configuration |
| Teaching pilot | Supervised classroom use | Synthetic, resettable scenarios; no production endpoints | Approved pilot release; incident log |
| Future production (not in initial scope) | Real operations, if separately approved | Only after legal, privacy, clinical, infrastructure and integration gates | Separate deployment, keys, database, approvals |

### Synthetic fixture requirements

- Use a fixture manifest that names scenario, patient, encounter, role, expected status, and reset version.
- Use clearly artificial names, NIK-like values that cannot be mistaken for real NIKs, contact details, addresses, clinician identities, and external IDs.
- Mark every record and export with `synthetic=true`, environment, fixture version, and scenario ID.
- Seed normal, boundary, incomplete, duplicate, cancelled, corrected, late-result, failed-integration, stock-expiry, unpaid, and rejected-claim cases.
- Reset by deleting or replacing only the named test tenant/session; never use a broad production-style delete.
- Tests must assert that HTTP requests to production hostnames, real payer endpoints, and real national-health endpoints are blocked.
- Keep secrets out of fixtures and test logs. Use fake credentials or a test secret manager.

## 4. Test pyramid and required evidence

The pyramid is a risk distribution, not a prohibition on end-to-end tests. High-risk clinical, authorization, financial, stock, audit, and integration rules receive both fast tests and realistic workflow tests.

| Layer | What it proves | Minimum evidence |
|---|---|---|
| Unit/domain | State transitions, validation, calculations, coding candidates, tariff/stock/claim rules | Automated results, coverage of critical branches, mutation or equivalent confidence check |
| Component/UI | Form behaviour, accessibility, loading/error states, role-aware controls, print/export markers | Component checks plus keyboard/screen-reader review for critical forms |
| Repository/API | Persistence, constraints, transaction boundaries, idempotency, audit event creation | API tests for create/edit/correct/cancel/approve/reverse and forbidden actions |
| Integration/contract | Mapping to laboratory, radiology, pharmacy, BPJS/SATUSEHAT adapters, printers, files, queues | Versioned request/response contracts, sandbox/stub traces, retry and reconciliation cases |
| End-to-end | Full department handoffs and final outcome | Scenario record with IDs and before/after state evidence |
| Security/privacy | Authentication, authorization, session, CSRF, injection, secrets, tenant/data isolation, export controls | Independent or qualified security report and tracked remediation |
| Performance/capacity | Response time, concurrency, queue depth, report generation and recovery under load | Workload model, baseline, thresholds, graphs/logs, bottleneck decisions |
| Resilience/DR | Backup, restore, dependency outage, retry, failover and rollback | Restore transcript, RPO/RTO result, outage rehearsal and go/no-go decision |

### Minimum automated regression suite

The release candidate must automatically cover: login/session expiry; contextual role denial; synthetic-mode guard; patient duplicate detection; encounter lifecycle; clinical draft/review/approval/amendment; order/result status; prescription/dispense/return; stock ledger and negative-stock prevention; charge/receipt/reversal; claim state; report filters; audit immutability; integration idempotency/retry; export watermark; and backup/restore smoke validation.

## 5. End-to-end workflow catalogue

Each scenario below must be implemented as a traceable test case. The workflow names mirror the complete vendor assessment while allowing the rebuild to present them in a coherent work-oriented navigation.

| ID | Major workflow | Required handoffs and assertions |
|---|---|---|
| E2E-01 | Pre-arrival, registration and identity | Online/referral/walk-in fixture -> patient search/create -> duplicate check -> payer/referral context -> queue, label and encounter. Assert consent, identity provenance, cancellation, and no public identifier leakage. |
| E2E-02 | Emergency/IGD | Arrival -> triage -> assessment -> orders/procedures/medication -> diagnostics -> disposition to discharge, admission, referral, death/DOA. Assert acuity priority, reassessment, override reason, and disposition gate. |
| E2E-03 | Outpatient | Registration -> nursing intake/safety screen -> medical assessment -> diagnosis/problem -> order/referral -> result review -> prescription -> pharmacy -> closure. Assert one encounter context, no re-keying, supervision states, and complete handoff. |
| E2E-04 | Inpatient | Admission/bed -> medical and nursing notes -> daily care -> orders/results -> medication -> nutrition/rehab/psychology -> transfer -> discharge/leave-against-advice/death. Assert bed concurrency, transfer acceptance, discharge-summary gate, and bed release. |
| E2E-05 | Laboratory/pathology/microbiology | Order -> specimen/label -> collection/rejection/recollection -> processing -> result -> verification/correction -> clinician acknowledgement -> charge/report. Assert critical-result handling and result provenance. |
| E2E-06 | Radiology | Order -> schedule -> acquisition placeholder -> interpretation -> verified report/image reference -> acknowledgement -> charge/claim. Assert report correction and role restrictions. |
| E2E-07 | Blood bank | Request -> compatibility/allocation -> issue/return -> transfusion record/traceability -> adverse-event path -> stock and charge reconciliation. Assert no issue without required checks. |
| E2E-08 | Nutrition and allied health | Referral -> assessment -> plan -> scheduled intervention/diet -> delivery/session -> outcome -> multidisciplinary summary -> charge. Assert restricted-note boundaries and cancellation handling. |
| E2E-09 | Surgery/IBS | Request -> consent/pre-op -> schedule/team/resources -> medication/implant/blood -> procedure/anaesthesia -> recovery -> specimen -> post-op orders -> coding/charge. Assert checklist, lot traceability, and cancellation reason. |
| E2E-10 | Pharmacy | Prescription from IGD/RJ/RI/outside patient -> verification -> preparation/label -> dispense -> administration support -> return/reversal -> patient history. Assert allergy/interaction warning, batch/expiry, partial fill, and charge reversal. |
| E2E-11 | GF warehouse and inventory | Demand/PO -> receipt/variance -> central stock -> distribution/depot transfer -> consumption -> return -> stocktake/expiry -> perpetual report. Assert FEFO, approval, lot ledger, negative-stock prevention, and financial reconciliation. |
| E2E-12 | Medical record/RMIK | Encounter closure -> assembly -> missing/unsigned detection -> coding candidate -> human review -> amendment request -> final lock -> filing/disclosure/retention. Assert no silent overwrite and full access log. |
| E2E-13 | Claims/BPJS/iDRG simulation | Completed record -> eligibility/SEP fixture -> grouping/plafon simulation -> claim validation -> submit-to-stub -> rejection/correction/resubmit -> payment status. Assert external boundary and idempotency. |
| E2E-14 | Billing/cashier/revenue | Charges from registration, services, room, pharmacy -> discount/guarantee/receipt -> partial payment/receivable -> settlement/deposit -> reversal. Assert totals, permissions, closed-period control, and no duplicate posting. |
| E2E-15 | Reporting and regulatory outputs | Seeded transactions -> operational, clinical, quality, mortality, HAI, RL, claims, pharmacy, finance and management reports. Assert source filters, totals, role visibility, simulation watermark, export audit, and reproducibility. |
| E2E-16 | Administration, integrations and IoT | User/role/context -> master data/tariff/template -> integration configuration -> event/log/retry/reconcile -> temperature/device placeholder. Assert least privilege, masked secrets, audit trail, and disabled production targets. |

For each scenario include: normal path; missing data; duplicate submission; cancellation; correction/amendment; unauthorized actor; concurrent actor; network/service failure; retry/reconciliation; print/export; and reset/replay. A workflow may be accepted only when the complete scenario and its negative variants pass.

## 6. Parity acceptance versus improvement defects

The first UAT asks: **“Can the authorised user complete the necessary work represented by the old system and the agreed workflow?”** It does not ask users to approve every legacy usability or technical choice.

| Classification | Definition | Handling |
|---|---|---|
| P0 safety/security blocker | Prevents safe test execution, exposes data/secrets, permits forbidden action, corrupts clinical/financial/stock state, or bypasses audit | Stop UAT; fix and rerun impacted scenarios |
| P1 parity defect | A documented required old-system capability or handoff is missing, unusable, or produces materially wrong output | Must fix or obtain explicit product-owner disposition before parity sign-off |
| P2 parity clarification | Evidence is contradictory or the old behaviour is not sufficiently specified | Assign owner; decide with workflow owner; record decision and test |
| I1 improvement request | Work is possible but users want different fields, order, labels, automation, layout, or report | Do not change parity build silently; create post-parity backlog item |
| I2 future capability | New scope not required for old-system parity or first pilot | Product discovery and roadmap review |
| Test defect | Script, fixture, environment, or tester setup is wrong | Correct test artefact; do not misclassify product behaviour |

Every finding records classification, source menu/workflow, evidence, affected role, expected/actual outcome, severity, reproducibility, and whether it blocks parity. UAT facilitators must not relabel an improvement as a parity defect merely because it is popular.

## 7. UAT operating model

### Roles

- **Product owner:** approves scope, parity disposition, release and risk acceptance.
- **Workflow owner:** validates operational meaning for registration, clinical, pharmacy, RM, finance, diagnostics, claims, or administration.
- **Facilitator:** runs scripts, controls fixtures, prevents real data, and records evidence.
- **Learner/user tester:** performs the task as their assigned role and explains what is unclear.
- **Supervisor/auditor:** checks provenance, review, authorization, and learning evidence.
- **Engineering/QA:** prepares builds, observes telemetry, triages defects, and reruns regression.
- **Privacy/security/IT reviewer:** gates data, security, integration, backup, and deployment claims.

### Three checkpoints

1. **Workflow-baseline review:** users inspect a concrete reference model and confirm roles, handoffs, required data, and old-system parity scope.
2. **End-to-end UAT:** users execute normal and exception scenarios on a release candidate. Record parity defects separately from suggestions.
3. **Faculty/teaching pilot readiness:** users confirm that the system is teachable, resettable, auditable, and clearly labelled as simulation.

After checkpoint 2 passes parity, run a separate **improvement discovery cycle** in which users work with the system, then report friction, missing context, unnecessary work, and desired changes. That cycle creates the next backlog; it does not rewrite the accepted baseline without change control.

### UAT record template

```text
Scenario ID / version:
Build, environment, fixture version:
Tester, role, department, date:
Preconditions and synthetic patient/encounter IDs:
Steps actually performed:
Expected result / actual result:
Evidence links (screenshots, event IDs, report/export hashes, logs):
Parity status: PASS / FAIL / CLARIFICATION
Improvement observation (if any):
Defect ID, severity, owner, retest result:
```

## 8. Security, privacy, performance, and DR gates

### Security and privacy

Before UAT sign-off, test server-side authorization for view/create/edit/delete/approve/print/export/integrate/settings actions; session expiry and fixation; CSRF; injection; unsafe file upload; rate limiting; secret masking and rotation; audit tamper resistance; tenant/session isolation; restricted psychology and sensitive notes; synthetic-only enforcement; and export watermarks. Test both allowed and denied paths with a student, lecturer/supervisor, operational role, auditor, and administrator account.

### Performance and capacity

Define a workload from the intended class size, concurrent users, peak registration, report generation, and reset operations. Establish agreed thresholds for page/API response, queue freshness, search, save, print/export, and report completion. Load test normal, peak, burst, long-running reports, concurrent edits, and integration backlog. Record database saturation, queue depth, errors, and degradation behaviour. Do not claim capacity from a single browser smoke test.

### Backup, restore, and disaster recovery

Demonstrate encrypted backup, retention, access separation, restore into an isolated environment, integrity checks, fixture/session recovery, audit preservation, and application reconfiguration. Record measured RPO/RTO and compare with the approved target. Exercise database loss, storage loss, bad release rollback, dependency outage, and corrupted integration queue. A backup that has never been restored is not acceptance evidence.

## 9. Release gates and exit criteria

### Entry to UAT

- traceability exists from approved workflow/menu scope to test cases;
- synthetic fixtures and reset procedure are verified;
- automated critical regression passes;
- staging build, dependencies, configuration, and logs are identified;
- known P0/P1 defects and test limitations are disclosed;
- privacy/security reviewer confirms no real data or production endpoints.

### Exit from parity UAT

- all E2E-01 through E2E-16 applicable to the release have a recorded result;
- no open P0 or unaccepted P1 defect;
- all critical authorization-denial and provenance tests pass;
- reports, printouts, exports, queues, charges, stock, claims and audit events reconcile for fixture cases;
- security, performance, restore and rollback evidence meets approved thresholds;
- product owner signs parity disposition, including explicitly deferred items;
- improvement backlog is created separately from parity defects.

### Pilot stop criteria

Pause the pilot when a user can access an unassigned record, a forbidden action succeeds, a clinical/financial/stock state is materially corrupted, synthetic data isolation fails, audit evidence is missing, restore/rollback is unavailable, or a repeated workflow prevents safe completion. Resume only after the cause is fixed, evidence is rerun, and the product owner accepts the disposition.

## 10. Traceability and maintenance

Keep a living matrix with columns: source menu/workflow, rebuild capability, role(s), test IDs, data fixture, expected outputs, parity status, improvement backlog IDs, owner, and last verified build. Review it on every release. When a menu is intentionally consolidated, mark the source item as **mapped**, **retired with replacement**, **not in teaching scope**, or **pending demonstration**; never leave a missing menu ambiguous.

References: [full workflow model](../vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md), [module inventory](../vendor-simrs-assessment-2026-08-21/MODULE_INVENTORY.md), [project charter](../PROJECT_CHARTER.md), and [project start checklist](../PROJECT_START_CHECKLIST.md).
