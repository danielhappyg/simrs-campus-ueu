# G0 governance v2 implementation blueprint — 2026-08-28

**Status:** `PLANNING ONLY / AWAITING PRODUCT-OWNER ADOPTION / NOT AUTHORITATIVE`

**Effect:** None. This blueprint does not adopt governance v2, authorize implementation, activate a consumer, appoint an owner, decide a capability, authorize a workflow, permit a migration or deployment, close G0, or establish G3 acceptance.

**Data boundary:** `APP_MODE=SIMULATION`, synthetic teaching data only. No live BPJS/VClaim/SATUSEHAT, payment, LIS, PACS, device, or other production integration is authorized.

**Planning head:** `19c0029730a36115f718fa48468dc56c3f722a05`

## 1. Bound design inputs

| Input | Status at this blueprint | SHA-256 |
| --- | --- | --- |
| `G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md` | Proposal; not approved; not authoritative | `f5c635006f878a68c4b0775be5262115fe38e185562d3be558e02d8b28c38695` |
| `ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md` | Proposed; not approved; no implementation authority | `cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962` |
| `G0_GOVERNANCE_V2_ADOPTION_DECISION_DRAFT_2026-08-28.json` | Pending product-owner decision; effect none | Bound by its committed bytes at planning head |

The proposal and ADR remain the design authorities if adopted. This blueprint orders their work; it does not alter their contracts.

## 2. Authority gates

| Gate | Required attributable decision | What becomes permitted | What remains prohibited |
| --- | --- | --- | --- |
| A. Adoption | Product owner approves the exact proposal and ADR hashes | Local implementation and fixture-only validation of governance v2 | Consumer activation, capability decisions, application workflow changes, migration, deployment, real data, live integrations, G3 |
| B. Activation | Separate operation decision binds the exact validated bundle, environment, prior state, actor, conditions, and expiry | One atomic consumer selection operation | Owner nomination, capability disposition, workflow implementation, deployment, G3 |
| C. Capability/slice | Product owner, every affected-domain owner, and every identity/reviewer required by the deterministically derived T1/T2/T3 rule record the disposition and reviews; T2 triggers independent review only when `implementer_is_approver` or `cross_domain_control` is true, while T3 always requires a distinct independent-control authority separated from executor, authors, and approvers | Implementation of only the approved synthetic slice | Unapproved capabilities and live integrations |
| D. Deployment | Separate release decision after exact-SHA tests, migration preflight, and rollback readiness | Controlled synthetic teaching deployment | Real patient use and unapproved integrations |
| E. G3 acceptance | Product owner and affected-domain owners accept current hosted evidence only after complete required defect closure, including no open P0 and no accepted or unaccepted P1 | Formal Teaching Parity Release 1.0 acceptance | Any claim beyond the recorded synthetic teaching scope |

Until Gate A is recorded in a new immutable `G0_GOVERNANCE_V2_ADOPTION_DECISION.json`, only review, revision, and validation of planning artifacts are allowed.

## 3. Dependency graph

```text
immutable adoption decision
  -> machine contract + historical v1 hash manifest
  -> shared v2 validation core
  -> deterministic pending importer/generator
  -> exact v1/v2 268-row comparator
  -> engineering-only evidence map
  -> observation-only profile dispatcher and dual validation
  -> selector transaction implementation and fixture rehearsal
  -> schema-v2 dual-pointer ledger bridge
  -> full deterministic CI and observation evidence
  -> separate activation decision
  -> atomic selection or explicit disabled/held state
  -> attributable owner and capability decisions
  -> first bounded cross-setting cancellation slice
```

No later node may supply evidence for an earlier authority gate. Engineering evidence never becomes owner authority.

## 4. Local implementation waves after adoption

### Wave 0 — immutable adoption checkpoint

Create a new `G0_GOVERNANCE_V2_ADOPTION_DECISION.json`; never mutate the draft. Bind the exact proposal SHA, ADR SHA, selected option, attributable message/reference, decision-message SHA, conditions, and time. Add `G0GovernanceV2AdoptionDecisionTest.rb`. Commit this authority record alone before implementation.

Exit condition: the canonical adoption record passes closed-schema, duplicate-key, exact-hash, source-commit, attribution, and authority-scope tests.

### Wave 1 — contract and shared validation core

Create:

- `G0_GOVERNANCE_V2_CONTRACT.json`;
- `G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json` from the exact ADR planning baseline;
- `scripts/g0-proportional-governance-v2.rb`; and
- `tests/Documentation/G0ProportionalGovernanceV2ValidatorTest.rb`.

Use one namespace and one canonical JSON implementation. The contract is the source for closed states, outcomes, dispositions, consequence flags, tiers, authority rules, proposal/ADR hashes, and validator version. Do not add v2 branches to `ParityGovernanceValidator`.

Exit condition: closed schema/type/duplicate-key tests, tier precedence, owner/reviewer independence, family exceptions, correction history, secret rejection, and all exact v1 hashes pass.

### Wave 2 — deterministic pending import and migration parity

Create:

- `scripts/generate-g0-proportional-governance-v2.rb`;
- `scripts/compare-g0-governance-v1-v2.rb`;
- `tests/Documentation/G0ProportionalGovernanceV2GeneratorTest.rb`; and
- `tests/Documentation/G0ProportionalGovernanceV2MigrationTest.rb`.

Generate only into a new caller-supplied candidate directory and refuse overwrite. Import exactly 268 stable IDs in manifest order, with batches, source/row hashes, scenarios, dependencies, boundary, and every owner/decision state still pending. Compare the 14 provisional engineering bindings without importing them into owner, tier, approval, disposition, or gate derivation.

Exit condition: two clean runs produce identical bytes; all 268 rows match; one-field adversarial drift fails; every v1 baseline byte remains unchanged; a partial candidate is never usable.

### Wave 3 — engineering-only evidence producer

Create `scripts/generate-g0-g3-coverage-evidence-map-v2.rb`, its dated schema-v2 output, and `G0G3CoverageEvidenceMapV2Test.rb`. Structurally forbid owner, approval, decision, disposition, tier, pointer, and gate fields.

Exit condition: deterministic bytes and exact sources pass; nested secrets, unsafe paths, stale evidence, and governance-field injection fail before output.

### Wave 4 — candidate-only observation and profile dispatch

Create:

- `scripts/validate-g0-proportional-governance-v2.rb`;
- `scripts/validate-g0-governance.rb`; and
- `tests/Documentation/G0GovernanceProfileDispatchTest.rb`.

The dispatcher is thin and delegates v1 unchanged. In this wave, implement the complete `v1` path and only the `v2|dual --source candidate` paths for `integrity|g0`; use exit `0` for success, `1` for contract/gate failure, and `2` for invalid usage. The CLI must reject rather than stub or duplicate `--source active` behavior until Wave 5 supplies the shared read-only pointer resolver. Before activation, only `dual --source candidate` may run, and its verdict is observational.

Exit condition: the candidate-only option matrix passes; v1 delegation is equivalent; active-source requests return a stable fail-closed not-ready result; no default selects v2; and no pointer rule is duplicated in the dispatcher.

### Wave 5 — selector mechanics in isolated fixtures only

Create `scripts/select-g0-governance-consumer.rb` and `G0GovernanceConsumerPointerTest.rb`. Keep canonical checkout mutation prohibited until Gate B. Test only under mode-0700 temporary roots protected by the test-only root guard.

Implement distinct components for filesystem capability probing, canonical path guarding, prior-state derivation, a shared read-only pointer → selection → bundle resolver, operation-decision validation, immutable selection creation, hash-chained journaling, atomic pointer publication, and exclusive receipt writing. After the shared resolver passes, complete the dispatcher’s `v2|dual --source active` matrix by delegating to it.

Exit condition: traversal/symlink/non-regular/cross-device/probe failures create no authority; concurrent processes yield exactly one winner, every loser returns the stable documented conflict exit/reason, and losers create no selection, journal, pointer candidate, or receipt; injected failure at every durability boundary preserves the old pointer before rename; post-rename failures require explicit recovery; rollback/recovery publish new held or disabled selections and force G0/G3 open. A recovery decision binds `recover_outcome: held|disabled` exactly; held-selection path/SHA is required only for `held` and is forbidden for `disabled`.

### Wave 6 — schema-v2 ledger bridge

Preserve the historical schema-v1 evidence map and ledger byte-for-byte. Add a separate schema-v2 generator path and dated artifacts. Each capability has:

- immutable `source_decision_pointer`; and
- nullable active `governance_decision_pointer` resolved only through pointer → selection → bundle → expanded register.

The ledger independently recomputes G0 from all 268 expanded rows and compares it with the gate register. G0 and G3 remain separate; G3 additionally requires current exact-SHA engineering, hosted role UAT, reconciliation, recovery, security, accessibility, performance, defect, and owner-acceptance evidence.

Exit condition: stale bindings fail; disabled/held/invalid pointers always force G0 and G3 open; engineering PASS cannot promote owner or gate state; all schema-v1 tests remain green.

### Wave 7 — CI and observation evidence

Add explicit CI steps only after their referenced files exist. Preserve full-history checkout and enumerate every retained compatibility suite; do not assume the currently incomplete workflow already runs them all. Run this exact family order:

```text
ruby -Itests tests/Documentation/G0ProportionalGovernanceV2ProposalTest.rb
ruby -Itests tests/Documentation/G0GovernanceV2ArchitectureDecisionTest.rb
ruby -Itests tests/Documentation/G0GovernanceV2AdoptionDecisionDraftTest.rb
ruby -Itests tests/Documentation/G0GovernanceV2ImplementationBlueprintTest.rb
ruby -Itests tests/Documentation/G0GovernanceV2AdoptionDecisionTest.rb
ruby -Itests tests/Documentation/ParityGovernanceValidatorTest.rb
ruby scripts/validate-parity-governance.rb --mode integrity
ruby -Itests tests/Documentation/G0OwnerGovernanceSnapshotGeneratorTest.rb
ruby -Itests tests/Documentation/G0S0IntakeContractTest.rb
ruby -Itests tests/Documentation/G0ProportionalGovernanceV2ValidatorTest.rb
ruby -Itests tests/Documentation/G0ProportionalGovernanceV2GeneratorTest.rb
ruby -Itests tests/Documentation/G0ProportionalGovernanceV2MigrationTest.rb
ruby -Itests tests/Documentation/G0GovernanceConsumerPointerTest.rb
ruby -Itests tests/Documentation/G0GovernanceProfileDispatchTest.rb
ruby -Itests tests/Documentation/G0G3CoverageEvidenceMapV2Test.rb
ruby -Itests tests/Documentation/G0G3CoverageLedgerTest.rb
ruby scripts/validate-g0-governance.rb --profile dual --mode integrity --source candidate --candidate-bundle <new-dir> --adoption-decision docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json --json-receipt <new-exclusive-path>
```

CI must not contain an activation command or `continue-on-error` for a governance check.

Exit condition: all suites pass without skips, every declared fault point is covered, deterministic reruns match, and the observation receipt is secret-free and does not change the active consumer.

## 5. Required failure invariants

1. Unknown, missing, duplicate, or wrongly typed fields fail closed.
2. Under-tiering; missing, self, or wrong-capacity independent review; absent distinct T3 independent-control authority; mixed-family expansion without explicit exceptions; and broken correction chains cannot become terminal decisions.
3. A changed ID, batch, source hash, row hash, scenario, dependency, boundary, pending owner state, or provisional-binding comparison fails migration parity.
4. Generator errors leave no usable bundle; existing output, immutable evidence, and v1 history are never overwritten.
5. Active-path traversal, symlink swaps, non-regular files, unsupported filesystem guarantees, or cross-device staging abort before creating selection, journal, pointer candidate, or receipt.
6. Before atomic rename, a failed transaction leaves the previous pointer byte-identical. After rename, failed readback makes consumers fail closed until explicit recovery.
7. Rollback and recovery never reactivate v1 or point directly to an old activation; they publish a new held or disabled selection. Recovery rejects a decision whose exact `recover_outcome` differs, requires held-selection path/SHA only for `held`, and forbids it for `disabled`.
8. A missing, unreadable, stale, held, disabled, or hash-invalid pointer forces every current schema-v2 G0 and G3 verdict to `OPEN`.
9. Secret-like content rejects the write and diagnostics identify only the artifact/location, never the value.
10. No engineering overlay, receipt, journal, comparator, or ledger source can confer owner authority.

## 6. Local commit and push policy

- Keep each wave in reviewable local commits with exact-file staging and focused tests.
- Do not push each commit. Batch only after a complete, independently reviewed observation checkpoint and explicit push authorization.
- Never combine adoption, activation, owner decisions, slice authorization, workflow implementation, deployment, or G3 acceptance into one decision or commit.
- A failed GitHub account/billing check is not evidence that tests ran; retain local evidence and avoid wasteful retries.

## 7. First work after activation

Activation does not approve any capability. After activation, record proportional owner authorities and decisions in dependency order. The recommended first bounded candidate remains cross-setting pre-clinical encounter cancellation because it exercises registration, ED/outpatient/inpatient state transitions, downstream-order denial, audit, correction boundaries, RMIK traceability, and reconciliation without enabling a live external integration.

Its implementation starts only after the product owner, every affected-domain owner, and all deterministically required T2/T3 independent reviewers or control authorities approve/review its exact capability set, consequence tiers, conditions, denial/correction behavior, and acceptance scenarios with the required identity separation.

## 8. Stop conditions

Stop before the next wave when any required authority record is absent or ambiguous; a bound hash drifts; a protected or historical file would be rewritten; a secret or real patient datum appears; a proposed action would enable a live integration; the filesystem cannot prove required durability; tests skip a fault point; or any P0/unaccepted P1 defect remains.

This blueprint must be revised and re-reviewed if the accepted proposal/ADR hashes change, the 268-capability baseline is superseded, or the implementation cannot provide the ADR's fail-closed and recovery guarantees.
