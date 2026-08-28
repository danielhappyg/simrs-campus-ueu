# G0 proportional owner governance v2 — proposal — 2026-08-28

**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE`
**Scope:** All 268 assessed SIMRS Sahabat capabilities, synthetic teaching boundary only
**Effect:** None. This proposal does not appoint an owner, approve a disposition, authorize implementation, replace the v1 governance artifacts, or change any G0–G3 gate.

## 1. Decision requested

Choose whether SIMRS Campus UEU should replace the current institution-grade cryptographic ceremony with a proportional, attributable governance model suitable for a small teaching programme.

The recommended v2 model preserves:

- the closed 268-capability universe and stable `PAR-*` identities;
- one explicit canonical disposition and target or authorized deferral for every capability;
- named product and affected-domain accountability;
- additional independent review for material clinical-safety, security, privacy, financial, integration, and recovery risk;
- immutable source references, evidence references, timestamps, conditions, and exact artifact hashes;
- the `synthetic_only` and no-live-integration boundaries;
- dependency order `A -> B -> C -> D -> E -> F -> G`; and
- fail-closed G0/G3 gates when any required owner, decision, evidence, or acceptance is missing.

It removes mandatory RSA trust roots, public-key onboarding, and seven or eight distinct people as prerequisites for every ordinary teaching-scope decision. Detached signatures remain optional evidence; they are not the only way to prove attributable approval.

## 2. Verified current state

The 2026-08-28 read-only audit found:

| Control | Current result |
| --- | ---: |
| Canonical capabilities | 268 unique IDs |
| Decision rows | 268 recorded |
| Terminal owner-approved dispositions | 0 |
| Explicit authorized deferrals | 0 |
| Accountable-owner appointments | 0 |
| Decision sessions | 0 |
| Authorized workflow bindings | 0 |
| Engineering-supported provisional capability mappings | 14 |
| Fully implemented E2E workflows in the coverage ledger | 0 |
| Workflow hosted-UAT PASS | 0 |
| Workflow owner-acceptance PASS | 0 |

All 268 decision-register rows remain `pending`; all 268 owner, approval, and release-evidence references remain pending. The current G0/S0 intake requires seven distinct people for `reproduce`, eight for `replace`, public-only RSA keys, two trust-root signers, an independently supplied trust-root pin, policy signatures, appointment signatures, decision-vote signatures, and reviewer receipts before closing the first ordinary decision.

That ceremony is internally coherent but operationally disproportionate for this synthetic teaching programme. It has become the dominant constraint on owner decisions rather than a control proportional to their risk.

## 3. Non-negotiable v2 invariants

V2 may simplify ceremony, but it must not simplify truth.

1. Every capability retains exactly one stable `PAR-*` identity and one Batch A–G assignment.
2. Every capability eventually reaches one terminal governance state: `AUTHORIZED_FOR_SYNTHETIC_BUILD`, `DEFERRED`, `RETIRED`, or `EXCLUDED`. `revise` and rejection of a proposal are nonterminal outcomes and cannot close G0.
3. An approved capability receives exactly one canonical disposition: `reproduce`, `replace`, `consolidate`, `retire`, or `exclude`.
4. `defer` identifies the unresolved owner, reason, review trigger, and review date; it is not silent permission to implement.
5. Every decision binds its product authority, affected-domain authority, evidence considered, conditions, target/exclusion, downstream effects, acceptance scenarios, date, and exact source hashes.
6. An agent, implementation author, passing test, deployment, or technical reviewer cannot substitute for a business or professional domain owner.
7. Blank, placeholder, organizational-only, stale, conflicting, or unverifiable owner records fail closed.
8. Clinical, diagnostic, medication, stock, financial, claim, statutory-reporting, privacy, security, and recovery consequences require their affected authority; product approval alone is insufficient.
9. V2 cannot authorize real patient data or a live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, device, or other production integration. No v2 decision can override this boundary. Any future change requires a separate governance instrument outside v2 plus explicit user authorization, and cannot inherit authority from a v2 approval.
10. G3 still requires exact-SHA automated evidence, hosted role-based UAT, reconciliation, backup/restore, security, accessibility, performance, defect closure, and owner acceptance.

## 4. Proportional approval tiers

The tier is derived from the decision's consequences, not selected to reduce the number of reviewers.

| Tier | Typical scope | Required attributable approvals | Independent review |
| --- | --- | --- | --- |
| `T1_STANDARD` | Navigation, low-risk teaching configuration, non-clinical labels, bounded administrative behavior | Product authority + accountable domain authority | Optional unless another risk trigger applies |
| `T2_DOMAIN_CRITICAL` | Clinical/RMIK lifecycle, diagnostics, medication, inventory, tariff/charge, claims simulation, report formulas, correction/amendment, denial behavior | Product authority + accountable domain authority + every materially affected co-owner | Required when the implementer is also an approver or a cross-domain control is affected |
| `T3_INDEPENDENT_CONTROL` | Privileged access, privacy/export, money movement, stock valuation, clinical-safety override, external integration, migration/restore, retained-write recovery, statutory output | Product authority + accountable domain authority + affected co-owners + one independent control authority | Mandatory and held by a person distinct from the implementation executor |

One person may hold product and domain capacities only when the institution explicitly records both capacities and their scope. That does not create two people for a quorum. A `T3_INDEPENDENT_CONTROL` reviewer must remain a distinct person.

Each decision event must declare every flag in this closed consequence map:

- `clinical_or_rm_lifecycle`, `diagnostic`, `medication`, `inventory_without_valuation`, `tariff_or_charge_without_money_movement`, `claims_simulation`, `report_formula`, `correction_or_amendment`, `denial_behavior`, `cross_domain_control`, and `implementer_is_approver`;
- `privileged_access_or_security`, `privacy_or_export`, `money_movement_or_stock_valuation`, `clinical_safety_override`, `external_integration`, `migration_restore_or_retained_write_recovery`, and `statutory_output`.

Tier derivation is deterministic: any true flag in the second group requires `T3_INDEPENDENT_CONTROL`; otherwise any true flag in the first group requires at least `T2_DOMAIN_CRITICAL`; only an all-false consequence map may use `T1_STANDARD`. `T3` takes precedence over `T2`. An unknown flag, missing flag, non-boolean value, declared tier below the derived tier, or conflicting consequence declaration fails closed. `implementer_is_approver` and `cross_domain_control` also trigger the independent review required by the T2 rule. Every triggered T2 or T3 independent reviewer must be a distinct identity from the implementation executor and all decision authors and approvers for that event; T3 additionally requires the independent-control authority capacity.

## 5. Attributable approval evidence

Each approval record must bind:

- stable institutional or programme identity;
- display name, role, unit, and authority capacity;
- exact capability IDs or closed family/batch scope;
- decision status, disposition, target/exclusion, conditions, and downstream effects;
- evidence and meeting/review references;
- approval timestamp and effective/expiry limits when applicable;
- source-register, decision-row, requirement, and artifact SHA-256 values; and
- conflict/recusal information.

Accepted attribution methods are:

1. a signed institutional document or approved meeting record with a stable reference;
2. an attributable approved repository decision record merged through protected review;
3. an approved ticket/workflow record with immutable identity, timestamp, and content; or
4. an optional detached cryptographic signature over the canonical decision payload.

Private keys, passwords, tokens, connection strings, or other secrets never enter the repository or evidence record.

## 6. Batch and family decisions

V2 permits one approval event to decide a closed family of capabilities only when all members share the same owners, disposition, target pattern, evidence boundary, conditions, acceptance contract, complete consequence map, derived tier, downstream effects, and independent-review requirement.

The event must enumerate every inherited `PAR-*` ID. Every expanded row retains its complete consequence map and independently derived tier; the family tier must equal the maximum derived member tier. Because family inheritance requires identical consequence maps and tiers, any mixed-risk member becomes a per-row exception with its own owner record. Per-row exceptions override family defaults and require their own owner record. After expansion, the machine register must still contain 268 explicit terminal rows; inheritance cannot hide an undecided capability.

Minimum execution order:

1. **Batch A:** shared controls, identity, authorization, audit, configuration, recovery.
2. **Batch B:** patient, registration, encounter, payer, ward/class/bed masters.
3. **Batch C:** core clinical and RMIK documentation/completion.
4. **Batch D:** diagnostics, allied care, blood, surgery.
5. **Batch E:** pharmacy and warehouse.
6. **Batch F:** finance, cashier, claims and BPJS simulation.
7. **Batch G:** operational, clinical, quality, statutory-style, and management reports.

A downstream batch may be drafted early but cannot receive terminal approval while a required upstream source decision remains unresolved.

## 7. Slice authorization and project-level G0

V2 recognizes safe incremental progress without calling the whole 268-capability gate complete.

Each row has one governance state:

```text
PENDING -> DISCOVERY -> PROPOSED
PROPOSED -> REVISION_REQUIRED | PROPOSAL_REJECTED
PROPOSED -> AUTHORIZED_FOR_SYNTHETIC_BUILD | DEFERRED | RETIRED | EXCLUDED
REVISION_REQUIRED | PROPOSAL_REJECTED -> DISCOVERY | PROPOSED
```

The closed owner-outcome mapping is:

| Owner outcome | Canonical disposition | Governance state | G0-terminal? |
| --- | --- | --- | --- |
| `approve` | `reproduce`, `replace`, or `consolidate` | `AUTHORIZED_FOR_SYNTHETIC_BUILD` | Yes |
| `approve` | `retire` | `RETIRED` | Yes |
| `approve` | `exclude` | `EXCLUDED` | Yes |
| `defer` | no implementation disposition; reason, trigger, and review date required | `DEFERRED` | Yes |
| `revise` | none | `REVISION_REQUIRED` | No |
| `reject` | none until an explicit `defer`, `retire`, or `exclude` decision is approved | `PROPOSAL_REJECTED` | No |

Neither a revision request nor a rejected proposal satisfies the 268-row terminal-decision requirement. `retire` and `exclude` are explicit no-build terminal decisions; they must still bind owners, evidence, consequences, and downstream handling.

An `AUTHORIZED_FOR_SYNTHETIC_BUILD` slice must enumerate its covered `PAR-*` IDs, risk tier, owners, affected authorities, evidence, decision reference, conditions, unknowns, acceptance scenarios, and exact hashes. It authorizes only the bounded synthetic build described by that slice. It does not authorize a deployment, hosted migration, real-data use, live integration, domain acceptance, or G3.

The project-level G0 gate remains `OPEN` until all 268 expanded rows have terminal owner decisions and satisfy the G0 exit contract. A completed slice is useful progress, not a substitute for the remaining 267 or fewer decisions.

V2 must use closed schemas and reject unknown fields, unknown states, missing or duplicate IDs, overlapping contradictory slice decisions, orphan/cyclic targets, stale hashes, silent inheritance, or a slice whose declared owners do not satisfy its risk tier.

## 8. Decision and delivery states

Owner decision and engineering delivery remain separate dimensions.

```text
GOVERNANCE:  PENDING -> DISCOVERY -> PROPOSED -> REVISION_REQUIRED | PROPOSAL_REJECTED | AUTHORIZED_FOR_SYNTHETIC_BUILD | DEFERRED | RETIRED | EXCLUDED
OWNER:       DRAFT -> READY_FOR_REVIEW -> APPROVED | REVISE | DEFER | REJECT
ENGINEERING: NOT_IMPLEMENTED -> PARTIAL -> IMPLEMENTED
EVIDENCE:    NOT_RUN -> PARTIAL_PASS -> COMPLETE_PASS
ACCEPTANCE:  NOT_READY -> NOT_ACCEPTED -> PASS | FAIL
```

An owner-approved disposition does not prove implementation. An implemented workflow does not prove owner acceptance. A deployed workflow does not prove G3.

## 9. Migration from v1

No v1 artifact is rewritten or relabelled as approved.

1. Preserve the current identity registry, policy, appointment register, session register, A–G registers, tests, and historical hashes unchanged as `v1 proposal / never activated` evidence.
2. Create separate v2 authority, owner, decision-event, expanded-decision, and gate schemas.
3. Import the exact 268 IDs, batches, source-row hashes, draft scenarios, dependencies, and engineering evidence with every owner decision still pending.
4. Record Daniel Happy Putra only in capacities he explicitly accepts; do not infer Clinical, Nursing, Laboratory, Radiology, Pharmacy, Finance, or other domain authority from product ownership.
5. Nominate and obtain acceptance from the minimum accountable domain owners needed for each batch.
6. Decide batches in dependency order, using family decisions plus explicit exceptions.
7. Generate the expanded 268-row decision register deterministically and reject missing, duplicate, orphaned, cyclic, or contradictory targets.
8. Dual-run v1 integrity validation and v2 validation; prove exact 268-ID, batch, source-hash, pending-state, owner-acceptance, and provisional-binding parity before any v2 consumer can affect a formal gate.
9. Bind owner decisions into the G0–G3 coverage ledger without converting engineering evidence into approval.
10. Keep G0 `OPEN` until all 268 rows are terminal and every required owner/approval/evidence reference is present.
11. Remove v1 from migration candidacy only after v2 integrity, negative tests, migration comparison, rollback rehearsal, and product-owner approval pass; keep its compatibility validation and historical bytes.
12. Select the active governance consumer through one versioned, atomic pointer bound to a validated artifact hash. Before activation, record the last-known-good v2 version and hash. Rollback atomically restores that last-known-good v2 consumer, or disables governance consumption when no validated predecessor exists; it never makes the never-activated v1 proposal authoritative. Rollback forces G0 and G3 to `OPEN`, makes every authorization issued only by the rolled-back v2 version non-operative, and requires revalidation before those authorizations can be consumed again. Historical v1 and v2 evidence remains untouched and readable.

## 10. First decision after v2 approval

The first product/domain decision should be the existing cross-setting pre-clinical encounter-cancellation pack, because it addresses a high-value real hospital reversal path across RJ, IGD, RI, RMIK, reporting, audit, denial, and recovery while explicitly blocking unapproved clinical, stock, finance, claim, and integration reversal.

Implementation remains prohibited until the required Registration, Clinical, RMIK, bed-management, reporting, and control authorities approve the pack or approve a documented revision.

Radiology, Pharmacy, Claims/BPJS, inventory, and billing must not be selected as implementation shortcuts while their owner decisions remain pending.

## 11. Decision options

Record exactly one:

- [ ] **Approve v2 as written.** Authorize implementation of the governance schemas, validator, deterministic migration, and tests. This does not approve any capability disposition.
- [ ] **Approve with revisions.** Record replacement wording and affected sections; revise and review this proposal before implementation.
- [ ] **Retain v1.** Continue the existing trust-root/RSA/appointment/session ceremony and resource plan.
- [ ] **Reject or defer.** Keep the current v1 proposal path and record the reason/review trigger; this does not relabel its never-activated owner records as approved.

### Exact approval statement

> Approve proportional G0 governance v2 as written in `G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md`. Preserve all 268 capability identities and traceability; require attributable product and affected-domain approval; require independent review for material clinical, security, privacy, financial, integration, and recovery risk; preserve the synthetic-only and no-live-integration boundaries; retain v1 as historical never-activated evidence; and do not treat this approval as a capability disposition, workflow implementation approval, deployment approval, or G3 acceptance.

## 12. References

- `G0_PARITY_CONTROL_BASELINE_2026-08-25.md`
- `G0_OWNER_APPOINTMENT_PACK_2026-08-25.md`
- `G0_AUTHORITY_MAP_2026-08-25.md`
- `G0_OWNER_AUTHORITY_POLICY_2026-08-25.json`
- `G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json`
- `G0_DECISION_SESSION_REGISTER_2026-08-25.json`
- `G0_BATCH_A_DECISION_REGISTER_2026-08-25.json` through `G0_BATCH_G_DECISION_REGISTER_2026-08-25.json`
- `../G0_G3_COVERAGE_LEDGER_2026-08-27.json`
- `../G0_G3_COVERAGE_LEDGER_README.md`
- `../phase-1/CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md`
