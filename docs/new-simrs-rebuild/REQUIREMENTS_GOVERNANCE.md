# New SIMRS Rebuild: Requirements Governance

Status: proposed governance model  
Applies to: parity discovery, delivery, UAT and the post-parity improvement programme

## 1. Purpose

This guide makes the new SIMRS rebuild auditable and prevents two common failures: mistaking an old menu for proof of a working requirement, and mixing user-led redesign into the first parity release. It governs requirements independently of any tracker, design tool, source-control platform or delivery framework.

The baseline consists of the assessed 268 visible menu items and the full hospital workflow, especially the [menu taxonomy](../vendor-simrs-assessment-2026-08-21/FULL_MENU_TAXONOMY.md), [workflow model](../vendor-simrs-assessment-2026-08-21/FULL_SIMRS_WORKFLOW_MODEL.md), [role catalogue](../vendor-simrs-assessment-2026-08-21/ROLE_CATALOGUE.md) and [assessment findings](../vendor-simrs-assessment-2026-08-21/INITIAL_FINDINGS.md).

## 2. Requirement hierarchy and identifiers

Use stable IDs; names and tools can change but the trace remains.

```text
Strategy / policy             STR-###
Product outcome / epic        EPC-###
Parity capability             PAR-<domain>-###
Functional requirement        FR-###
Business rule / state         BR-###
Non-functional/control req.   NFR-###
Report definition             RPT-###
Integration contract          INT-###
Acceptance scenario           UAT-###
Decision record               DEC-###
Risk / issue                  RSK-### / ISS-###
Change request                CR-###
Improvement request           IMP-###
```

One `PAR` capability may map several legacy routes into one canonical new workflow. It may not leave a legacy route unmapped: each must have an explicit **build**, **consolidate**, **simulate**, **exclude** or **retire** decision and an approver.

## 3. Required evidence classification

| Class | Source and meaning | May it become a committed parity requirement? |
|---|---|---|
| O — observed | Directly visible in authorised current interface/route evidence. | Yes, after outcome/rules are specified. Visibility alone is insufficient. |
| M — manual-documented | Present in the 2018 vendor manual. | Yes, after domain-owner confirmation; it is historic evidence. |
| I — inferred | Hypothesis from workflow context or naming. | No. It first requires discovery and a decision. |
| U — unknown | Important behavior has not been established. | No. It is a discovery item/blocker, not a hidden requirement. |
| P — proposed | Deliberate new-system behavior. | Yes, with product approval; it must not be represented as legacy parity. |

For every requirement, retain source path, relevant section/route, observation date, person who interpreted it, confidence, and links to demonstration/test evidence. Never retain credentials or personally identifying clinical data in requirement artifacts.

## 4. Minimum content of a requirement

Each functional or control requirement must be independently understandable and testable.

| Field | Required question it answers |
|---|---|
| ID, title and domain | What is the stable requirement and who owns it? |
| Type and priority | Is it parity, functional, rule, control, report, integration or improvement? Is it P0–P4? |
| Evidence label and source | What is known, inferred, proposed or unknown? |
| User/actor and trigger | Who starts it, under what condition? |
| Preconditions and input | What data/authorization/state must exist? |
| Expected behavior | What should happen, including validation and messages? |
| State/ledger effects | What is created, updated, locked, reversed, reconciled or retained? |
| Downstream and output | Which queues, reports, documents, stock, charges, claims or integrations change? |
| Permissions and audit | Who is allowed/denied and what event evidence is recorded? |
| Exceptions | Duplicate, cancellation, correction, external failure, concurrency and unsafe input handling. |
| Acceptance criteria | Observable pass/fail conditions, synthetic data and UAT scenario link. |
| Trace links | Parity item, decision, report/integration/risk and sign-off. |

Avoid requirements phrased only as “create a page/menu/form.” State the business outcome and observable effects.

## 5. Requirements lifecycle

```text
Captured -> Classified -> Discovered -> Specified -> Approved for build
-> Built -> Verified -> Domain accepted -> Parity-UAT accepted -> Released
                         \-> Blocked / Deferred / Retired (with DEC record)
```

| Status | Entry rule | Exit rule |
|---|---|---|
| Captured | Item came from menu, workflow, user observation or risk. | Evidence class and owner assigned. |
| Classified | Source and relationship to parity are recorded. | Discovery question or draft behavior is clear. |
| Discovered | Demonstration, document review or domain workshop occurred. | Unknowns and decisions are recorded. |
| Specified | Requirement contains all minimum fields. | Domain owner confirms it is testable. |
| Approved for build | Scope, priority and dependency are accepted. | Build work can start. |
| Built | Implementation is available in controlled environment. | Test evidence is attached. |
| Verified | Functional/control tests pass. | Domain owner reviews the intended outcome. |
| Domain accepted | Relevant owner accepts scenario(s). | Included in cross-domain parity UAT. |
| Parity-UAT accepted | Full workflow and downstream reconciliation pass. | Release gate approves it. |
| Deferred/retired | It is excluded or consolidated. | Decision has impact, rationale, owner and review date. |

## 6. Decision rights and stakeholder ownership

Roles may be held by the same person in a small teaching programme, but decision rights remain distinct and recorded.

| Role | Accountable for | Must be consulted on |
|---|---|---|
| Product sponsor | Scope, funding, parity acceptance, material exceptions and improvement-programme start. | Major trade-offs, retirement/consolidation and release gates. |
| Product lead | Backlog integrity, prioritisation, evidence traceability, change classification and release narrative. | Every cross-domain requirement/change. |
| Teaching-system owner | Synthetic-data purpose, student experience, course configurations and reset policy. | Student permissions, scenarios and teaching exclusions. |
| Clinical/ED/outpatient/inpatient leads | Clinical workflow, safety, documentation and handoffs. | Clinical rules, disposition, order/result/discharge changes. |
| RMIK/coding lead | Record completeness, coding, filing, data-quality and regulatory information outputs. | Documentation, coding, retention and report definitions. |
| Pharmacy/warehouse lead | Prescribing fulfillment, stock, procurement, returns, lot/expiry and reconciliation. | Medicine/supply and theatre-pharmacy changes. |
| Finance/cashier/claim lead | Tariffs, charges, payment, receivable, claim, settlement and journals. | Any item that affects money or payer status. |
| Reporting/quality lead | Report definition, ownership, validation and release. | Regulatory, quality and management outputs. |
| Data protection/security owner | Data classification, access, audit, secrets, retention, incident and integration trust boundary. | Any data, role, external service, export or log change. |
| Technical/operations lead | Reliability, performance, release, backup/restore, monitoring, portability and supportability. | Architecture and operational controls. |
| UAT coordinator | Scenario control, evidence collection, defect triage and sign-off record. | Gate readiness and test-data integrity. |
| End users/students | Observation of workability and improvement opportunities. | Usability and teaching workflow; they do not approve safety or financial rules alone. |

For cross-domain transactions, one role cannot unilaterally accept an impact owned by another. Example: a new pharmacy return process requires pharmacy, finance and audit/security acceptance when it changes stock, charges and history.

## 7. Change control during parity delivery

Every incoming request is triaged within the parity framework.

| Classification | Definition | Handling before parity UAT |
|---|---|---|
| Parity defect | Approved parity behavior is absent, incorrect or breaks a required handoff. | Fix in parity scope; update test evidence. |
| Evidence gap | Legacy claim/rule cannot be substantiated. | Discovery task and decision; no assumed build. |
| Mandatory exception | New behavior is necessary for safety, privacy, legal, security, synthetic-data control or blocking accessibility. | Product sponsor plus relevant owner approve; document as P/DEC with impact. |
| Delivery refinement | Does not change an accepted business outcome (for example wording/layout implementation detail). | Product lead may approve within agreed design boundaries. |
| Improvement request | A requested better/different workflow, convenience feature or redesign. | Log as `IMP`; do not schedule until G3 parity-UAT acceptance. |
| Scope expansion | A new domain/capability outside the approved parity baseline. | Sponsor decision with effort, dependency, risk and gate impact. |

The standing rule is: **user-requested improvements are collected during parity UAT but are prioritised and built only after parity UAT is accepted.** The only exceptions are a confirmed parity defect or mandatory exception as defined above.

## 8. Change request record and approval thresholds

Every material change has a `CR` record containing the request/problem, classification, evidence label, affected requirements/menus/workflows, roles, data/security/financial/report/integration impact, alternatives, recommendation, acceptance criteria, release/gate impact and decision.

| Change impact | Minimum approval |
|---|---|
| No business-outcome change | Product lead, with domain owner informed. |
| One-domain parity rule/output change | Product lead + accountable domain owner. |
| Cross-domain workflow, report, tariff, stock or claim effect | Product lead + each accountable affected owner. |
| Privacy, security, synthetic-data, external integration, audit, retention or production-boundary effect | Product sponsor + data protection/security owner + technical lead + affected domain owner. |
| Parity scope, canonical-version, deferral/retirement or gate change | Product sponsor + product lead + affected domain owners. |

An emergency safety/security change may be expedited by the authorised incident process, but it must be recorded and retrospectively reviewed before the next gate.

## 9. Definition of ready and definition of done

### Ready for build

A requirement is ready when it has a stable ID, accountable owner, evidence label/source, approved outcome, explicit input/state/permission and downstream effects, acceptance scenario, dependency/risk assessment, and an agreed treatment for unknowns.

### Done for a parity capability

A parity capability is done only when:

- its legacy menu(s) are mapped to a canonical new destination or approved exclusion/consolidation;
- normal and exception synthetic scenarios pass;
- role allow/deny behavior and relevant audit trail pass;
- required records, status changes, ledger/report/document/integration effects reconcile;
- correction/cancellation/reversal behavior is accepted where relevant;
- documentation is current; and
- the applicable domain owner signs off, followed by cross-domain parity UAT where required.

Rendering a page, completing a developer test, or copying a legacy label is never sufficient by itself.

## 10. Report and integration governance

### Reports

For each of the 117 legacy report entries, create a `RPT` definition rather than treating it as a static menu. Required fields include business owner/purpose, intended users, sensitivity/access, source data, inclusions/exclusions, parameters, calculation, period close rules, control totals, output layout/export, sign-off, retention and test scenario. Similar or duplicate legacy report labels may be consolidated only through a decision record.

### Integrations

For each external boundary, create an `INT` contract with business owner, technical owner, teaching versus production environment, allowed data classification, message/event purpose, credentials custody, authentication, schema/version, idempotency/correlation key, timeout/retry, error visibility, reconciliation, monitoring, retention and disable/fallback behavior. An interface configuration field or menu does not prove an integration is safe or active.

## 11. Traceability and release evidence

Maintain bidirectional traceability:

```text
Legacy menu/route/manual/workflow evidence
  <-> PAR capability <-> FR/BR/NFR/RPT/INT
  <-> design/build item <-> UAT scenario/result
  <-> defect/change/decision <-> release and owner sign-off
```

At every gate, publish an evidence summary that reports counts and links for: all 268 menus; evidence class; build/consolidate/simulate/exclude/retire status; scenario coverage; accepted/deferred/blocked status; high-risk controls; defects; decisions; report definitions; integration contracts; and named approvals. Keep the evidence fact-based: a loaded route is structural evidence, not proof that a save, calculation, permission or posting works.

## 12. Cadence, review and records

Use a lightweight recurring cadence suitable for the delivery team:

- weekly domain refinement: validate unknowns and newly specified requirements;
- weekly cross-domain dependency/risk review: focus on data, stock, money, claims, reports and integrations;
- per-slice demonstration: run scripted synthetic scenarios with the responsible domain owners;
- formal gate review: approve only complete evidence, exceptions and deferred items;
- post-parity user research cycle: triage `IMP` items, test hypotheses and measure outcomes.

Record decisions promptly, preserve originals, version requirements and never overwrite the source evidence. Protect all artifacts from credentials and identifiable health information. Update a requirement when facts change; do not rewrite its evidence history.

## 13. Governance success criteria

This governance is working when the team can answer, for any menu or workflow: what it is for; what evidence supports it; who owns it; whether it is parity, proposed or unknown; what data/rules it changes; how it is tested; what was consciously consolidated/retired; and whether a user request is a defect or a post-parity improvement.

