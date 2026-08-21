# New SIMRS Rebuild Risk Register

**Scope:** clean-slate full-SIMRS rebuild for UEU campus teaching and simulation  
**Baseline:** vendor assessment of 268 visible menus and the complete hospital workflow model  
**Default data posture:** synthetic patients only; no production clinical or payer integrations

## 1. How to use this register

This is a working register, not a statement that every risk has happened. A menu name or historical manual entry is evidence of possible scope, not proof of implementation. Review risks at the workflow-baseline checkpoint, each release gate, each UAT/pilot session, and after any incident or material scope change.

Scoring uses Probability (P) and Impact (I), each from 1 (low) to 5 (very high). The rating is `P x I`: 1–4 low, 5–9 medium, 10–16 high, 17–25 critical. A critical or high risk may block a release even when its numerical score is unchanged.

Status values: `OPEN`, `MITIGATING`, `ACCEPTED`, `CLOSED`, `TRIGGERED`. Owners are accountable for evidence and decisions, not necessarily the person doing every action.

## 2. Top release-gating risks

The following risks are release blockers unless explicitly accepted by the product owner and the relevant institutional reviewer: real-data exposure; wrong-patient/wrong-record access; unsafe clinical/medication/stock/financial state; missing audit/provenance; production integration transmission; untested backup/restore; irrecoverable cutover; and inability to complete a critical end-to-end handoff.

## 3. Register

| ID | Category / risk statement | P | I | Rating | Owner | Trigger / early signal | Mitigation and contingency | Status |
|---|---|---:|---:|---:|---|---|---|---|
| R-01 | **Scope/parity:** 268 visible menus are mistaken for 268 completed requirements, leaving hidden rules, reports, or handoffs missing. | 4 | 5 | 20 | Product owner + workflow owners | UAT cannot complete a workflow; menu-to-capability mapping has unknowns | Maintain a menu/workflow traceability matrix; validate end-to-end scenarios; label inferred/unknown behaviour; defer with explicit decision. | OPEN |
| R-02 | **Clinical/data integrity:** wrong patient, encounter, order, result, medication, charge, or claim is linked or overwritten. | 3 | 5 | 15 | Clinical safety reviewer + engineering | Duplicate/ambiguous identity, cross-session record appears, reconciliation mismatch | Synthetic identity fixtures, strong encounter context, server-side constraints, confirmation for high-impact actions, immutable amendments, negative tests; pause affected pilot and restore/reconcile. | OPEN |
| R-03 | **Synthetic boundary:** real-looking or real patient/staff data enters the teaching environment. | 3 | 5 | 15 | Privacy/data owner | Real identifier, contact, image, credential, or production hostname in fixture/log/export | Generated fixtures, import guardrails, outbound allowlist, scan logs/exports, environment watermark, reset procedure; quarantine/delete only through approved incident process and investigate source. | OPEN |
| R-04 | **Authorization:** student or lecturer context can view or perform actions beyond assignment, discipline, session, or sensitivity. | 4 | 5 | 20 | Security lead + engineering | Forbidden action succeeds; broad role/menu assignment; denial only in UI | Capability and contextual policies enforced server-side; unique accounts; automated allow/deny matrix; audit and break-glass reason; disable role/session until fixed. | OPEN |
| R-05 | **Provenance/audit:** corrections, approvals, exports, permission changes, secret access, or integrations are not attributable or tamper-evident. | 3 | 5 | 15 | Records/audit owner | Missing actor/time/before-after/reason; empty or mutable log | Central immutable event model, retention/access controls, audit regression suite, export of event evidence; treat affected records as untrusted and restore/reconcile. | OPEN |
| R-06 | **Privacy/security:** secrets, sensitive notes, images, or exports leak through logs, browser, files, reports, public routes, or configuration. | 3 | 5 | 15 | Security/privacy reviewer | Secret readback, unmasked export, public URL, unsafe attachment, security test finding | Write-only/masked secrets, least privilege, encryption, secure headers, output watermark, restricted-note segmentation, DLP-style scans and incident response; rotate/revoke and contain. | OPEN |
| R-07 | **Integration boundary:** sandbox or stub calls reach production BPJS, SATUSEHAT, payer, payment, LIS/PACS, or notification endpoints. | 2 | 5 | 10 | Integration owner + IT | Non-sandbox hostname/key, unexpected external acknowledgement, outbound network alert | Environment-specific credentials, deny-by-default egress, adapter contracts, synthetic IDs, request review, idempotency/retry tests; disable adapter and revoke keys. | OPEN |
| R-08 | **Clinical workflow safety:** incomplete triage, assessment, result review, medication verification, discharge, or transfer is allowed to close silently. | 3 | 5 | 15 | Clinical workflow owners | Missing required step; user workaround; unsigned item after closure | State-machine gates, clear pending-task queue, supervisor review, exception reason, negative UAT; reopen/reconcile affected synthetic case and block release. | OPEN |
| R-09 | **Pharmacy/inventory:** stock, batch/expiry, return, dispense, administration support, or charge ledger diverges. | 3 | 5 | 15 | Pharmacy/GF owner | Negative stock, FEFO failure, duplicate issue, unexplained stock/tariff total | Lot-level ledger, transaction boundaries, approval and closed-period rules, reconciliation scenarios, stocktake rehearsal; freeze dispensing simulation and rebuild from last verified ledger. | OPEN |
| R-10 | **Financial/claims:** tariffs, discounts, guarantees, receipts, reversals, iDRG/BPJS simulation, or reports produce wrong totals. | 3 | 4 | 12 | Finance/claims owner | Charge/report mismatch, duplicate posting, impossible reversal, rejected claim fixture | Versioned tariff master, deterministic calculations, dual review, closed-period controls, reconciliation and reversal tests; mark affected output invalid and rerun from source events. | OPEN |
| R-11 | **Identity/master data:** duplicate patients, ambiguous clinicians, obsolete tariffs, services, code sets, locations, or schedules create inconsistent results. | 4 | 4 | 16 | RMIK/master-data owner | Duplicate match, missing code mapping, conflicting version, orphan relation | Steward-owned master data, validation and effective dates, duplicate workflow, crosswalks and seed review; quarantine invalid row and correct through governed change. | OPEN |
| R-12 | **Version coexistence:** legacy/v2/v3/EMR variants are rebuilt inconsistently or no canonical replacement is defined. | 4 | 4 | 16 | Product owner + architecture owner | Same work has competing screens/states; users unsure which route is authoritative | Map each source route to canonical capability; define compatibility/deprecation and migration plan; hide/retire noncanonical route after parity evidence. | OPEN |
| R-13 | **Performance/capacity:** concurrent class use, report generation, search, reset, or integration backlog makes the system unusable or corrupts state. | 3 | 4 | 12 | Engineering/IT | Latency/error/queue threshold breached in load test or pilot | Model expected cohort/peak load, load test, indexes/transactions, queue backpressure, monitoring and capacity budget; throttle noncritical reports and scale or pause pilot. | OPEN |
| R-14 | **Availability/DR:** backup exists but restore, storage recovery, release rollback, or RPO/RTO is unproven. | 3 | 5 | 15 | IT/operations owner | Restore test fails/too slow; only one copy; no rollback rehearsal | Encrypted/off-site copies, retention, isolated restore tests, runbook, measured RPO/RTO, release rollback rehearsal; enter incident mode and use last verified environment. | OPEN |
| R-15 | **Cutover/migration:** future migration loses records, provenance, attachments, open transactions, or creates duplicates; source rollback is unavailable. | 2 | 5 | 10 | Migration lead + records/privacy owners | Reconciliation mismatch, unresolved exception, converter non-idempotent, downtime overrun | Default no migration; if approved, classify/map/profile, rehearse twice, freeze/delta load, reconcile, retain read-only source, and define go/no-go/rollback. | OPEN |
| R-16 | **Usability/adoption:** users cannot find work or create unsafe workarounds because a clean-slate workflow changes familiar order/terms. | 4 | 4 | 16 | Product owner + change lead | High facilitator intervention, task failure, repeated help request, parallel spreadsheets | Preserve familiar operational concepts for parity; scenario-based training, champions, office hours, visible known issues, observation metrics, post-parity improvement backlog. | OPEN |
| R-17 | **Parity/improvement confusion:** popular improvement suggestions are treated as baseline defects, or parity gaps are deferred as preferences. | 4 | 3 | 12 | UAT facilitator + product owner | UAT findings lack classification; acceptance disputes; scope churn | Use separate parity and improvement fields/statuses, scripted acceptance criteria, evidence and decision log; pause sign-off until blocker classification is resolved. | OPEN |
| R-18 | **Teaching governance:** student work is graded or supervised without clear role, session, rubric, correction, or approval state. | 3 | 4 | 12 | Teaching governance owner | Lecturer cannot review; student can finalise; rubric/debrief lacks event evidence | Model cohort/session/assignment/supervisor context; draft-review-correction-approval states; facilitator and faculty pilot gate; use paper/controlled workaround and do not grade unsupported outcome. | OPEN |
| R-19 | **Training/support:** pilot starts without prepared scenarios, reset, quick guides, champions, or incident path. | 3 | 3 | 9 | Change lead + facilitator | Users miss orientation; unresolved support queue; reset fails | Readiness checklist, role-based training, rehearsal, support rota, office hours, known-issues list; postpone pilot or reduce scope. | OPEN |
| R-20 | **Dependency/supply chain:** unsupported libraries, vulnerable package, uncontrolled build, or undocumented deployment causes security or availability failure. | 3 | 4 | 12 | Engineering/IT | SBOM finding, failed build, unreviewed dependency, manual server edit | Pin/review dependencies, SBOM and vulnerability scans, protected release process, staging/rollback, supported versions; block release and patch/rebuild. | OPEN |
| R-21 | **Observability/incident response:** failure, unauthorized attempt, queue backlog, or data event is not detected or escalated. | 3 | 4 | 12 | Operations/security | No alert, missing correlation ID, delayed support acknowledgement, empty log | Define health/error/audit metrics, alert thresholds, incident severity and contacts, tabletop exercises, evidence retention; declare incident and preserve state even if root cause is unclear. | OPEN |
| R-22 | **Reporting/trust:** reports, regulatory-style outputs, dashboards, or exports look authoritative but use incomplete or inconsistent transactional data. | 3 | 4 | 12 | RMIK/reporting owner | Report totals fail reconciliation; users cite report as clinical truth; filter ambiguity | Build reports from validated events, display source/time/mode, reconcile fixture totals, restrict sensitive reports, mark simulation; withdraw invalid report and correct source. | OPEN |
| R-23 | **External/public display:** admission, queue, surgery, or bed information exposes more than intended or relies on an unowned/cleartext endpoint. | 2 | 5 | 10 | IT/privacy owner | HTTP route, unknown owner, identifiers in public output, stale data | Keep disabled until HTTPS ownership and minimisation are proven; synthetic-only public fixtures; privacy review and automated output scan. | OPEN |
| R-24 | **Scope and resourcing:** attempting all departments and reports at once delays the common patient/encounter foundation and weakens quality. | 4 | 4 | 16 | Product owner | Parallel disconnected screens, growing WIP, no complete vertical slice | Deliver vertical journeys, gate increments, limit WIP, defer dashboards/rare modules until transactional spine passes; rebaseline roadmap transparently. | OPEN |
| R-25 | **Vendor/continuity:** future reliance on a vendor, undocumented build, or inaccessible source leaves the institution unable to patch, restore, export, or exit. | 3 | 5 | 15 | Product owner + IT/procurement | No source/license/escrow, missing data dictionary, vendor-only admin, SLA gaps | Contract source/data/config export, SBOM, support/SLA, backup ownership, documentation, escrow/continuity and exit rehearsal; retain independent archive and stop expansion until evidence exists. | OPEN |

## 4. Risk review workflow

1. **Identify:** record the risk when discovered; cite the source, scenario, or observation.
2. **Assess:** score P/I using the current teaching scope and affected workflow; note assumptions.
3. **Assign:** one accountable owner and a target review date.
4. **Mitigate:** create an action with evidence, deadline, and acceptance criterion.
5. **Monitor:** watch the trigger and residual risk; update after tests, incidents, or scope changes.
6. **Decide:** close only with evidence; accept only with named authority, expiry/review date, and compensating control.

### Minimum risk action record

```text
Risk ID and date observed:
Source/scenario/build/evidence:
Changed probability/impact and rationale:
Owner and action due date:
Mitigation evidence link:
Residual risk and trigger:
Decision authority, decision, and review date:
```

## 5. Review cadence and escalation

- **Before each release:** engineering, QA, workflow owners, product owner; no unresolved release-gating risk.
- **During UAT/pilot:** facilitator reviews daily; escalate safety, privacy, authorization, data integrity, external transmission, and rollback failures immediately.
- **Weekly during active build:** product owner reviews open high/critical risks, overdue actions, and dependency changes.
- **Monthly after pilot:** include adoption, support, training, regression, and improvement risks.
- **After an incident or major requirement change:** perform an interim review and update affected test cases, training, and mitigations.

## 6. Required evidence links

Link each closed or accepted risk to the relevant artefact: test/UAT record, security assessment, synthetic-fixture scan, access-control test, reconciliation report, backup/restore transcript, performance report, training/pilot report, contract/evidence pack, or product-owner decision. A statement that “the menu exists” is never sufficient mitigation evidence.

References: [testing and UAT strategy](TESTING_AND_UAT_STRATEGY.md), [data migration and cutover](DATA_MIGRATION_AND_CUTOVER.md), [user research and change management](USER_RESEARCH_AND_CHANGE_MANAGEMENT.md), [initial vendor findings](../vendor-simrs-assessment-2026-08-21/INITIAL_FINDINGS.md), and [project charter](../PROJECT_CHARTER.md).
