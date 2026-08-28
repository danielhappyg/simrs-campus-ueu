# ADR-018: Versioned proportional G0 governance profile

- Status: **Proposed / not approved / no implementation authority**
- Date: 2026-08-28
- Decision owner: Daniel Happy Putra (product owner; approval still required)
- Required review before acceptance: independent technical/security review
- Related: `G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md`, G0–G3 coverage ledger, ADR-015
- Planning baseline: `ad326cf2e9b36e6864e029adba10bd0d86a0cbc4`
- Proposal SHA-256 at planning baseline: `f5c635006f878a68c4b0775be5262115fe38e185562d3be558e02d8b28c38695`
- Historical evidence-map SHA-256: `3cfcc9898f4b541cea85cd448638fc300cae48eb5172c3dffd598925d9244ea0`
- Historical ledger SHA-256: `d043e4ce3d2893bd19f543a971561b57964926a385969f0a2e7266e943196cbf`

## Effect of this ADR

None while proposed. This ADR does not adopt governance v2, appoint an owner, decide a capability, authorize a synthetic build, close G0, authorize a migration or deployment, permit real patient data or a live integration, or establish G3 acceptance.

Implementation may begin only after an attributable product-owner adoption decision accepts the linked governance-v2 proposal. Selecting an active consumer, authorizing a capability slice, deploying an application, and accepting G3 remain separate decisions.

## Context

The parity programme has an immutable 268-capability inventory and extensive fail-closed v1 validation. Its current owner-governance ceremony requires institution-scale RSA trust roots, appointments, signed sessions, and seven or eight people before an ordinary teaching-scope decision can close. No v1 owner appointment or terminal capability decision has been activated.

The proposed proportional model keeps the 268-row truth, exact source hashes, named product and affected-domain accountability, independent control for higher risk, and fail-closed G0/G3 gates. It needs an implementation architecture that cannot silently reinterpret historical v1 artifacts or let engineering evidence become owner authority.

## Requirements

### Functional

1. Import exactly 268 stable `PAR-*` identities, batches, source-row hashes, scenarios, dependencies, pending decisions, and owner state without mutation. Compare the 14 provisional engineering bindings from the historical evidence map as a read-only parity dimension; never import them into owner, tier, approval, or gate derivation.
2. Validate closed v2 authority, owner, decision-event, expanded-decision, gate, bundle, selection, and pointer contracts.
3. Expand approved family events into explicit per-capability rows while preserving each member's complete consequence map and independently derived maximum risk tier.
4. Derive T1/T2/T3 deterministically and require the exact product, domain, co-owner, and independent-review identities dictated by the consequences.
5. Keep owner decisions, engineering delivery, evidence, hosted acceptance, deployment, and G3 as independent state dimensions.
6. Dual-run v1 integrity and v2 validation before any v2 artifact can influence a formal gate.
7. Select one validated v2 bundle through a hash-bound consumer pointer and support atomic rollback to a last-known-good v2 selection or a disabled state.
8. Feed validated governance references into a new dated coverage ledger without rewriting the historical 2026-08-27 ledger.

### Non-functional

- Deterministic output: identical source bytes and inputs produce identical canonical JSON and hashes.
- Fail closed: unknown keys, duplicate JSON keys, missing values, stale hashes, lower-than-derived tiers, broken history, ambiguous pointers, or partial writes are rejected.
- Small-team maintainability: Ruby standard library only; no institutional PKI, database, service, queue, or network dependency for governance validation.
- Portability: read-only validation runs wherever Ruby runs. Pointer mutation is supported only after a capability probe proves a local POSIX filesystem with same-device atomic rename, advisory file locking, file `fsync`, and directory `fsync`; unsupported platforms remain validation-only and fail closed for activation/rollback.
- Recoverability: activation and rollback are serialized, atomic on one filesystem, receipt-producing, and failure-injection tested.
- Auditability: immutable selection records bind actor, decision reference, old/new hashes, time, reason, validator version, and outcome without secrets.
- Boundary: synthetic-only; v2 can never authorize real patient data or live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, device, or other production integration.

## Decision drivers

- Preserve the already-tested v1 integrity surface and historical bytes.
- Remove ceremony that is disproportionate to a small teaching programme without weakening owner accountability.
- Prevent a migration defect from changing the meaning of any of the 268 capabilities.
- Make activation and rollback observable, testable, and reversible.
- Avoid new runtime infrastructure and dependencies.
- Keep GitHub pushes batched while allowing local, reviewable checkpoints.

## Considered options

| Option | Complexity | Integrity risk | Maintainability | Rollback quality | Decision |
| --- | --- | --- | --- | --- | --- |
| A. Modify the current v1 validator and artifacts in place | Medium initially, high over time | High: v1 history can be reinterpreted or overwritten | Poor: RSA and proportional logic become entangled | Poor: no clean profile boundary | Reject |
| B. Add an independent v2 profile, deterministic comparator, and versioned consumer pointer | Medium | Low when dual-run and hashes pass | Strong: bounded files and no new service | Strong: last-known-good v2 or disabled | **Proposed** |
| C. Record decisions only in prose and meetings | Low | High: incomplete rows, ambiguous authority, and drift | Poor | None | Reject |
| D. Replace v1 and the ledger in one cutover | Medium | High: migration, activation, and evidence changes are inseparable | Medium | Weak | Reject |

## Proposed decision

Adopt Option B only if the product owner approves the linked v2 proposal. The implementation is additive and profile-based. V1 remains immutable, runnable historical evidence; v2 receives separate files, validation, tests, and activation records.

### High-level graph

```text
Immutable sources
  matrix + baseline + manifest + A-G registers + source hashes
  historical evidence map/hash (comparison input only)
                              |
                              v
                  deterministic v2 importer
                              |
             authority + owner + decision events
                              |
                 family expansion / exceptions
                              |
                 exact 268 expanded decisions
                              |
     consequence flags -> derived tier -> authority validation
                              |
                  v2 gate + bundle manifest
                              |
           v1 integrity ---- parity comparator
                              |
        adoption + activation decisions + immutable selection
                              |
             atomic active-consumer pointer
                              |
    dual-pointer capability projection + independent G0 check
                              |
        new dated schema-v2 G0-G3 coverage ledger
```

Engineering overlays may describe implementation evidence but never enter owner, tier, approval, or gate derivation.

## Component and file boundaries

### New implementation files after approval

| Path | Responsibility |
| --- | --- |
| `scripts/g0-proportional-governance-v2.rb` | Closed contracts, canonicalization, duplicate-key rejection, tier and authority derivation, family expansion, source/hash validation, gate computation |
| `scripts/generate-g0-proportional-governance-v2.rb` | Deterministic importer/generator; writes only to a new candidate directory and refuses overwrite |
| `scripts/validate-g0-proportional-governance-v2.rb` | Standalone v2 `integrity` and `g0` CLI |
| `scripts/compare-g0-governance-v1-v2.rb` | Exact 268-row migration and immutable-v1 parity comparison |
| `scripts/select-g0-governance-consumer.rb` | Serialized validation, immutable selection receipt, atomic pointer activation and rollback |
| `scripts/validate-g0-governance.rb` | Thin profile orchestrator; never reimplements v1 rules |
| `scripts/generate-g0-g3-coverage-evidence-map-v2.rb` | Deterministic closed engineering-evidence map producer; cannot emit owner, approval, tier, disposition, or gate fields |
| `tests/Documentation/G0ProportionalGovernanceV2ValidatorTest.rb` | Closed schemas, states, tiers, owners, history, negative cases |
| `tests/Documentation/G0ProportionalGovernanceV2GeneratorTest.rb` | Determinism, overwrite refusal, source-byte and hash preservation |
| `tests/Documentation/G0ProportionalGovernanceV2MigrationTest.rb` | Exact 268-row parity and v1 immutability |
| `tests/Documentation/G0GovernanceConsumerPointerTest.rb` | Activation, concurrency, crash injection, rollback, stale-authorization rejection |
| `tests/Documentation/G0GovernanceProfileDispatchTest.rb` | v1/v2/dual CLI behavior and fail-closed defaults |
| `tests/Documentation/G0G3CoverageEvidenceMapV2Test.rb` | Engineering-only map schema, deterministic generation, secret rejection, and governance-field prohibition |
| `docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md` | This proposed architecture; adoption binds its exact accepted SHA |
| `tests/Documentation/G0GovernanceV2ArchitectureDecisionTest.rb` | Cross-contract validation against the committed proposal, planning baseline, v1 files, and architecture invariants |

### New canonical v2 artifacts after approval

| Path | Mutability and purpose |
| --- | --- |
| `G0_GOVERNANCE_V2_ADOPTION_DECISION.json` | Immutable attributable adoption decision; required before implementation output becomes eligible for activation |
| `G0_GOVERNANCE_V2_CONTRACT.json` | Canonical machine-readable states, outcomes, dispositions, consequence flags, tiers, authority rules, and proposal/ADR hashes |
| `G0_GOVERNANCE_V2_ACTIVATION_DECISIONS/*.json` | Immutable attributable activate/rollback/disable/recover operation decisions bound to operation, recovery outcome when applicable, environment, exact candidate/selection, and prior-state facts |
| `G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json` | Generated once from the planning baseline; exact byte hashes for every preserved v1 artifact and test |
| `G0_GOVERNANCE_V2_AUTHORITY_REGISTER.json` | Append-only authority identities, capacities, scopes, dates, and references |
| `G0_GOVERNANCE_V2_OWNER_REGISTER.json` | Append-only accountable-owner and co-owner records |
| `G0_GOVERNANCE_V2_DECISION_EVENT_REGISTER.json` | Append-only individual/family decision events and corrections |
| `G0_GOVERNANCE_V2_EXPANDED_DECISION_REGISTER.json` | Deterministic 268-row projection; regenerated, never manually edited |
| `G0_GOVERNANCE_V2_GATE_REGISTER.json` | Derived project/slice gate state; regenerated, never manually promoted |
| `G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json` | Exact source and artifact hashes, validator contract, and bundle identity |
| `G0_GOVERNANCE_CONSUMER_SELECTIONS/*.json` | Immutable `activation`, `rollback_hold`, `recovery_hold`, or `disabled` selections; each binds adoption, operation decision, bundle/held predecessor, prior-state facts, and previous validated selection |
| `G0_GOVERNANCE_CONSUMER_JOURNAL/*.json` | Append-only hash-chained operation journal used to reconstruct or disable a corrupt pointer; journal records do not confer authority |
| `G0_GOVERNANCE_CONSUMER_POINTER.json` | The only mutable selector; binds one selection path/SHA and is atomically replaced after full validation |
| `G0_GOVERNANCE_V2_EVIDENCE/` | Referenced, secret-free attributable decision evidence |

All canonical v2 artifacts live under `docs/new-simrs-rebuild/phase-0/`. Candidate generation uses a caller-supplied new directory and refuses existing output.

### Files that may change after approval

- `.github/workflows/documentation-checks.yml`: set checkout `fetch-depth: 0` for the pinned-baseline byte test, then add ADR/proposal, v2, dual-run, pointer, evidence-map, and ledger checks while retaining every v1 check.
- `scripts/generate-g0-g3-coverage-ledger.rb`: retain immutable A–G source pointers, add pointer → selection → bundle resolution, and project active v2 governance through a separate per-capability pointer.
- `tests/Documentation/G0G3CoverageLedgerTest.rb`: bind the dual-pointer capability schema, pointer, selection, bundle, active profile, historical v1 hashes, independent G0 recomputation, and engineering/owner separation.
- `docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md`: document profile resolution and rollback.
- `docs/new-simrs-rebuild/phase-0/README.md`: distinguish v1 historical integrity, v2 adoption, active selection, slice authorization, and project G0.
- A new dated schema-v2 G0–G3 ledger and evidence map; never rewrite the 2026-08-27 evidence map or ledger snapshot.

### Closed v1 preservation inventory at the planning baseline

`G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json` must contain exactly the following 30 path/hash pairs in this order. No glob, directory walk, or broad category may add or omit files silently.

```text
docs/new-simrs-rebuild/phase-0/PARITY_MATRIX_BASELINE.json|38e5889889ba23b5b7e4b59af9e669161b87b5c5b6e8c7cb3113f086c3af11ae
docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json|59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca
docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json|a5c1e01686fdb576026802331d3228df9a6335f6f7176b7c023c459bfc532098
docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json|3db0a698be4f7729f24f997dbbc54e69c7c98442237fb7a9d4b12358971eeb1f
docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_REGISTER_2026-08-25.json|7b97e8cc5169ff848dc5ca524281f93ad5b42b0a2134acc52cf91368cc580775
docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_REGISTER_2026-08-25.json|da057e99d9d7d2349662f532605efec87edf220deb885783852088a2975c6287
docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_REGISTER_2026-08-25.json|10778f021fc23f7fac0d3dfbdaacd8574cc424aaa76788afd7a26ba9f426a93b
docs/new-simrs-rebuild/phase-0/G0_BATCH_F_DECISION_REGISTER_2026-08-25.json|d2e78446dc2aa84162c8229dec8ab809bd45ace51a8fc6a313da95286503b795
docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json|53bef20b3f509c543213e649b2d5f6644e9dbe00a7dae34d209c57a93137612e
docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_EVIDENCE_2026-08-25/README.md|1f5b1c82fd342bddbc1da3949542be026d0909052749715878e10d0af21bcf2c
docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_EVIDENCE_2026-08-25/README.md|0cd2059ce381dcd65bda6ae00a08a43d2b4592771a34dba2277b2725a25e004e
docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_EVIDENCE_2026-08-25/README.md|f95417dfb63f2d65202577a78245d3f19405e30770fba0fd95c4659691e4781f
docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_EVIDENCE_2026-08-25/README.md|7144bc8b22f25beab7e51b0840450b52a4e9365fb02cc91bd139f8e88231cc10
docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_EVIDENCE_2026-08-25/README.md|19e8037d03dc9baa37f7653c3e8b411ddfb010affcd97c1ed428fc980516286d
docs/new-simrs-rebuild/phase-0/G0_BATCH_F_DECISION_EVIDENCE_2026-08-25/README.md|e03dacd43c04cfb6c58ff952334a42cae0f7505c2ff030c7f376d9348fa5aa54
docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_EVIDENCE_2026-08-25/README.md|ffa4f1870ca91184437962698c47b70a41fcc0df1fb3d13facdf5193c705c5da
docs/new-simrs-rebuild/phase-0/G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json|3ab2abd46fdcbbd7f13ff6bd87857834c88e1cc373894bbe78fa54ae0a440ee0
docs/new-simrs-rebuild/phase-0/G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json|ba0cc0002a23a6fb04fa645c7c8089e8e5ce8d5a3ca7752fac023ae304eeb206
docs/new-simrs-rebuild/phase-0/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json|bd43a7f0fbfc6f3a6f571c6077f7ac4722cd241006358c7fd325c0a27c37cc75
docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json|85697fd4c1979f6447e1e0b1ab0cf761ca8c8755941c9059795aea95cb77f008
docs/new-simrs-rebuild/phase-0/G0_DECISION_SESSION_REGISTER_2026-08-25.json|a86576e0bb80171b275a1f7b7d8dde92ecf3bcc0856a4b386481ef68746117b3
docs/new-simrs-rebuild/phase-0/G0_OWNER_DECISION_EVIDENCE_2026-08-25/README.md|025f05437688815077754d57e82010441329dcaeeb4ce843c2888427770954e3
docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_PACK_2026-08-25.md|9beb1ce52abec72ab829d479e0d4835409ca8356b3217818bb04286a663310b1
docs/new-simrs-rebuild/phase-0/G0_S0_INSTITUTIONAL_AUTHORITY_INTAKE_2026-08-26.md|3b67978695b8729463d52e6bb6063713bcd70fb763e418381372f8c30e545a90
scripts/validate-parity-governance.rb|251cd15d947ea14b517f56ac23aee143faa91d59ce349d96756c8e88378cf65f
scripts/generate-g0-owner-governance-snapshot.rb|a6c0e229123e30d02777a6f085c2da94062ada22e7a1676cea6ed77c1fab3a86
scripts/validate-g0-s0-intake.rb|d90e5452c63d61162e7683a2999fea89a3cb2e76c25048af3e907e03eb165ee5
tests/Documentation/ParityGovernanceValidatorTest.rb|92441766a024eabe9462767f58d4985b87a5da9a636c22d67c4cab973c35e182
tests/Documentation/G0OwnerGovernanceSnapshotGeneratorTest.rb|a9f28a2fe4d682274aa1c18a71144c850c1f3a0c42423a6d7673a191dcffda51
tests/Documentation/G0S0IntakeContractTest.rb|0d3de612c2a937434530c4c1d02477df57afcf264877475c3dbc1a63126b7be7
```

### Files preserved byte-for-byte

- `PARITY_MATRIX_BASELINE.json`, `G0_PARITY_BATCH_MANIFEST.json`, all seven 2026-08-25 A–G registers, and their evidence directories.
- All v1 identity, authority, appointment, session, evidence, snapshot-plan, and S0 intake artifacts.
- `scripts/validate-parity-governance.rb`, `scripts/generate-g0-owner-governance-snapshot.rb`, and `scripts/validate-g0-s0-intake.rb`.
- Their existing v1 tests and fixtures.
- The approved proposal bytes used by the adoption decision; revisions require a new proposal hash and approval.
- The accepted ADR bytes and `G0GovernanceV2ArchitectureDecisionTest.rb`; a design revision requires a new ADR hash and review.

## Data contracts

### Expanded row

Every expanded row binds at least:

- `requirement_id`, batch, source register ID/SHA, source-row index/SHA;
- governance state and canonical disposition/target;
- full closed consequence map and derived tier;
- product, domain, co-owner, and independent-review bindings;
- event ID/SHA, conditions, exclusions, downstream effects, scenarios;
- predecessor/supersession references; and
- synthetic-only and no-live-integration boundary.

`G0_GOVERNANCE_V2_CONTRACT.json` is the canonical machine-readable contract and binds the exact adopted proposal and ADR hashes. Its closed governance states are `PENDING`, `DISCOVERY`, `PROPOSED`, `REVISION_REQUIRED`, `PROPOSAL_REJECTED`, `AUTHORIZED_FOR_SYNTHETIC_BUILD`, `DEFERRED`, `RETIRED`, and `EXCLUDED`. Its closed consequence map contains `clinical_or_rm_lifecycle`, `diagnostic`, `medication`, `inventory_without_valuation`, `tariff_or_charge_without_money_movement`, `claims_simulation`, `report_formula`, `correction_or_amendment`, `denial_behavior`, `cross_domain_control`, `implementer_is_approver`, `privileged_access_or_security`, `privacy_or_export`, `money_movement_or_stock_valuation`, `clinical_safety_override`, `external_integration`, `migration_restore_or_retained_write_recovery`, and `statutory_output`.

The validator reads the JSON contract, not Markdown. Contract tests compare the exact ordered arrays and rules to the adopted proposal/ADR constants and reject any divergence. A proposal or ADR revision changes its hash and requires a new contract plus adoption review.

The projection must contain exactly 268 unique rows in canonical manifest order. A family event may expand only when every member has an identical consequence map, derived tier, owner set, disposition pattern, conditions, downstream effects, and review requirement. Mixed members require explicit exceptions.

### Schema-v2 ledger bridge

The current ledger's per-capability governance presence comes from immutable pending A–G rows and its formal gate is intentionally hardcoded `OPEN`. Those behaviors remain historical; pointer activation does not mutate or reinterpret them.

The first pointer-aware ledger is a new dated artifact with `schema_version: 2`. Every capability contains two non-interchangeable pointers:

- `source_decision_pointer`: the immutable A–G register path, source SHA, row index, and row SHA; and
- `governance_decision_pointer`: the active expanded-v2 register path/SHA, row index/SHA, event ID/SHA, selection SHA, and bundle SHA, or explicit `null` while the consumer is disabled.

Owner, approval, disposition, and governance state are derived only from a hash-valid `governance_decision_pointer` resolved through the active pointer. Engineering evidence and the immutable `source_decision_pointer` cannot populate those fields. A disabled/invalid pointer produces pending governance presence for all capabilities and forces G0/G3 `OPEN`.

The schema-v2 ledger binds one closed `governance_profile_binding` containing the pointer revision/SHA, selection path/SHA, bundle manifest path/SHA, expanded-register path/SHA, gate-register path/SHA, and validator contract/version. The ledger independently recomputes G0 from all 268 expanded rows and compares the complete result with the gate register. It does not copy a self-declared gate. Any mismatch, missing row, stale pointer revision, or changed selection/bundle/register hash makes ledger generation fail; an existing ledger whose binding no longer equals the active pointer is stale and cannot be accepted as current evidence.

`gate_summary.g0` and `gate_summary.g3` remain separate. G0 is owner-governance completeness only. G3 additionally requires G0 PASS plus current exact-SHA engineering evidence, hosted role UAT, reconciliation, recovery, security, accessibility, performance, defect closure, and owner acceptance. Neither gate can be promoted by the engineering overlay alone.

The new dated `G0_G3_COVERAGE_EVIDENCE_MAP_V2_YYYY-MM-DD.json` is generated deterministically by `generate-g0-g3-coverage-evidence-map-v2.rb` from the previous hash-bound engineering map plus explicitly listed current evidence inputs. Its closed schema contains only capability/workflow engineering dimensions, evidence paths, and provenance. Owner identities, approval references, decisions, dispositions, consequence flags, tiers, consumer pointers, and gate states are forbidden fields. `G0G3CoverageEvidenceMapV2Test.rb` checks deterministic bytes, source hashes, secret rejection, and that forbidden governance fields cannot be emitted. The ledger consumes this map only for engineering/evidence dimensions; it never becomes a governance source.

### Consumer pointer

The pointer is a closed object containing:

- `status: active|held|disabled`;
- `profile: v2|null` (`v2` for active/held, `null` for disabled);
- pointer revision and predecessor pointer SHA;
- immutable selection path/SHA;
- validator contract/version;
- activated-at timestamp.

The pointer does not duplicate bundle, adoption, activation, or last-known-good fields. The immutable selection is the single source that binds its kind, adoption decision, operation decision, exact bundle or held predecessor, prior-state facts, and previous validated selection. Exact cross-equality is mandatory whenever another artifact repeats an identifier for observation.

Every operation decision and selection uses the same closed prior-state representation:

- `prior_state_reason: valid_pointer` requires a 64-hex `expected_prior_pointer_sha256` and null `observed_unreadable_pointer_sha256`;
- `prior_state_reason: initial_state|missing_pointer` requires both hashes null; and
- `prior_state_reason: unreadable_pointer` requires null `expected_prior_pointer_sha256` plus the 64-hex SHA-256 of the raw unreadable bytes in `observed_unreadable_pointer_sha256`.

Unknown combinations fail closed. The raw unreadable content is never copied into a receipt.

Prior state is independently derived from canonical filesystem and journal state before it is compared with the caller/decision declaration:

- `initial_state` is valid only when the pointer is absent and the canonical selection and journal directories contain no records;
- `missing_pointer` is valid only when the pointer is absent but validated selection or journal history exists;
- `unreadable_pointer` is valid only when pointer bytes exist but closed parsing/hash-chain validation fails, and the tool computes the raw-byte SHA itself; and
- `valid_pointer` is valid only when pointer → selection → bundle validation passes.

A declaration that differs from derived state exits `1` before mutation. A new pointer's `predecessor_pointer_sha256` equals the validated prior pointer SHA for `valid_pointer` and is null for `initial_state`, `missing_pointer`, or `unreadable_pointer`. The selection and journal retain the closed reason and observed unreadable-byte SHA when applicable.

Consumers fail closed when the pointer is missing, partially written, ambiguous, stale, symlinked, or hash-invalid. A disabled pointer forces project G0 and G3 `OPEN`.

### Canonical path and mutation boundary

In a real checkout, the active pointer, selection directory, activation-decision directory, journal directory, and lock path are exact repository-root locations under `docs/new-simrs-rebuild/phase-0/`. The mutation CLI rejects path traversal, symlinks at any component, non-regular files, a root outside the canonical checkout, cross-device staging, or caller overrides of those active locations. A `--root` override is permitted only for isolated test fixtures; every resolved path must remain inside that root.

Before mutation, a capability probe in the pointer directory must prove exclusive creation, advisory locking, same-device atomic replacement, file `fsync`, directory `fsync`, and durable readback. All mutation operations—activate, rollback, disable, and recover—refuse to run when any guarantee is unavailable, including unsupported Windows/network filesystems. Read-only validation may still run.

## CLI contract

The existing `validate-parity-governance.rb --mode integrity|g0` contract remains unchanged.

```text
validate-g0-governance.rb
  --profile v1|v2|dual
  --mode integrity|g0
  --source candidate|active
  --candidate-bundle PATH
  --adoption-decision PATH
  --root PATH
  --json-receipt PATH
```

- `v1`: `--source`, `--candidate-bundle`, and `--adoption-decision` are forbidden; delegate to the existing validator unchanged.
- `v2 --source candidate`: require exactly one new candidate-bundle directory and the exact approved adoption decision; forbid active-pointer input. `--mode g0` is an observational candidate verdict and cannot influence the formal ledger.
- `v2 --source active`: forbid candidate/adoption overrides and resolve only the canonical pointer → selection → bundle chain under the selected root.
- `dual --source candidate`: run v1 integrity, candidate v2 validation, and exact migration-parity comparison; require the candidate bundle and adoption decision. It never requires v1 G0 to pass because v1 owner governance was never activated.
- `dual --source active`: run v1 integrity, validate the canonical active v2 chain, and compare preserved sources. Candidate/adoption flags are forbidden.
- `--source` is required for v2/dual and forbidden for v1. Candidate and active inputs are mutually exclusive. Missing, duplicated, ignored, or conflicting options exit `2`.
- `--root` is permitted for every profile and defaults to the canonical checkout. A noncanonical root is accepted only when the test-only environment guard is set; otherwise it exits `2`. V1 delegates with paths resolved inside that root.
- Before v2 activation, CI uses `dual --source candidate` only for observation. The formal consumer remains disabled/current behavior; v2 is never silently selected.
- Exit `0` means the requested contract passed, exit `1` means a validation/gate failure, and exit `2` means invalid CLI usage.
- Optional `--json-receipt PATH` writes a secret-free machine receipt to a new regular file under the selected root using exclusive creation and refuses overwrite.
- Every receipt has the closed fields `schema_version`, `operation_id`, `operation`, `profile`, `mode`, `source`, `status`, `reason_code`, `message`, `actor`, `started_at`, `finished_at`, `planning_baseline`, `adoption_sha256`, `activation_sha256`, `prior_pointer_sha256`, `selection_sha256`, `bundle_sha256`, `validator_contract`, and `secret_scan_passed`; non-applicable hashes are explicit `null`.

Mutation uses a separate closed CLI:

```text
select-g0-governance-consumer.rb
  --operation activate|rollback|disable|recover
  --activation-decision PATH
  --candidate-bundle PATH
  --prior-state valid_pointer|initial_state|missing_pointer|unreadable_pointer
  --expected-pointer-sha256 SHA
  --observed-unreadable-pointer-sha256 SHA
  --recover-outcome held|disabled
  --recover-selection PATH
  --recover-selection-sha256 SHA
  --root PATH
  --json-receipt PATH
```

The mutation option matrix is closed:

| Operation | Required | Allowed prior state | Forbidden |
| --- | --- | --- | --- |
| `activate` | activation decision, candidate bundle, prior state, receipt | `valid_pointer` or `initial_state` | recover-selection fields, unreadable hash |
| `rollback` | activation decision, prior state, expected pointer SHA, receipt | `valid_pointer` only | candidate bundle, recover-selection fields, unreadable hash |
| `disable` | activation decision, prior state, expected pointer SHA, receipt | `valid_pointer` only | candidate bundle, recover-selection fields, unreadable hash |
| `recover` with outcome `held` | activation decision, prior state, recover outcome, recover-selection path/SHA, receipt | `missing_pointer` or `unreadable_pointer` | candidate bundle, expected pointer SHA |
| `recover` with outcome `disabled` | activation decision, prior state, recover outcome, receipt | `missing_pointer` or `unreadable_pointer` | candidate bundle, expected pointer SHA, recover-selection fields |

`expected-pointer-sha256` is required exactly when prior state is `valid_pointer` and forbidden otherwise. `observed-unreadable-pointer-sha256` is required exactly for `unreadable_pointer` and forbidden otherwise. Recover-selection fields are required exactly for recovery outcome `held` and forbidden for `disabled`; `recover-outcome` is required only for `recover`. `--root` follows the same canonical/test-only rule as validation. Any irrelevant, missing, duplicate, or conflicting option exits `2` before mutation.

## Activation and rollback

Activation is a filesystem transaction on one volume:

1. Resolve exact canonical paths, reject symlinks/traversal/cross-device inputs, pass the mutation-filesystem capability probe, and acquire the exclusive lock.
2. Validate the approved adoption decision and the separate operation decision. The operation decision must bind the exact operation, environment, candidate/held selection SHA, closed prior-state representation, actor, conditions, and expiry. For `recover`, it must also bind `recover_outcome: held|disabled` exactly; the held-selection path/SHA is required in the decision only for `held` and forbidden for `disabled`.
3. Validate candidate bundle, every source hash, v2 integrity, requested gate, immutable-v1 manifest, historical evidence-map comparison, and dual-run parity.
4. Create the immutable selection with exclusive `O_EXCL` semantics, `fsync` the file, re-read/hash it, and `fsync` the selection directory entry before publishing any pointer.
5. Create an immutable hash-chained journal record with `O_EXCL`, `fsync` its file, re-read/hash it, and `fsync` the journal directory. The journal records the prior pointer/selection and candidate selection but does not confer authority.
6. Write a complete pointer candidate in the pointer directory with exclusive creation and `fsync`; re-read and validate it.
7. Atomically rename the candidate over the pointer and `fsync` the pointer directory.
8. Re-read pointer → selection → adoption/activation decisions → bundle → registers, independently recompute the active contract, and emit the successful operation receipt.

Any failure before the atomic rename leaves the previous pointer authoritative. An unreferenced selection/journal candidate is retained as failed-operation evidence but is non-authoritative. A failure after rename is detected by readback; consumers fail closed until explicit recovery.

Rollback uses the same lock and transaction. It derives the previous validated selection from the hash-chained journal, revalidates it, then creates a new immutable `rollback_hold` selection bound to the rollback decision and the held predecessor/bundle. The pointer publishes that new selection with `status: held`; it never points directly to the old `activation` selection. A held selection makes every capability authorization non-operative and forces G0/G3 `OPEN` until a fresh activation decision creates a new `activation` selection.

For recovery outcome `held`, `recover` requires the operator-supplied immutable selection path/SHA plus a new recovery operation decision and derived missing/unreadable prior-state facts; it does not trust fields from a corrupt pointer. Recovery creates a new `recovery_hold` selection and publishes `status: held`. When no validated predecessor exists, recovery outcome `disabled` forbids selection fields and creates a new `disabled` selection/pointer explicitly authorized by the operation decision. It never activates v1.

Every `rollback_hold`, `recovery_hold`, or `disabled` selection forces project G0 and G3 `OPEN` for every schema-v2 ledger while that selection remains current, not only the first ledger. Reuse requires a fresh validation and activation decision.

## Failure modes and controls

| Failure | Required behavior |
| --- | --- |
| Missing/duplicate/unknown capability | Reject bundle; pointer unchanged |
| Source or v1 byte/hash drift | Reject migration and activation; report exact artifact ID only, never secret content |
| Missing/unknown/non-boolean consequence flag | Reject row/family |
| Declared tier below derived tier | Reject decision event |
| Reviewer equals executor/author/approver where independent review is triggered | Reject authority binding |
| Family contains mixed risk without exception | Reject expansion |
| Broken/forked correction history | Reject event chain |
| Approval reference exists but is not attributable/hash-valid | Treat as pending; G0 remains open |
| Engineering evidence claims PASS without owner approval | Keep owner/gate state unchanged |
| Concurrent activation | One lock holder proceeds; others fail with a stable conflict code |
| Crash before rename | Remove only the staged candidate; retain previous pointer |
| Invalid pointer after rename/readback | Consumers fail closed; recover from a validated journal selection or operator-supplied immutable selection plus recovery decision |
| No last-known-good selection | Disable consumer and force G0/G3 open |
| Unsupported filesystem or symlinked active path | Refuse mutation before creating selection, journal, or pointer candidates |
| Active pointer and current ledger binding differ | Reject the ledger as stale; regenerate schema-v2 ledger after validation |
| Secret-like content in any generated artifact/receipt | Reject write and report location without value |

## Verification matrix

The implementation is not complete until tests prove:

1. Closed schemas reject unknown/missing fields, duplicate keys, wrong types, unknown states, and invalid nested values.
2. The machine-readable v2 contract contains the exact ordered states, flags, tiers, outcomes, and authority rules bound to the adopted proposal/ADR hashes; validator constants cannot diverge.
3. Deterministic import produces exactly 268 unique ordered rows and preserves every batch/source/scenario/dependency/pending fact; the historical evidence map remains a separately hashed read-only comparison input for provisional-binding parity.
4. Family expansion, exceptions, tier precedence, and authority separation fail closed under adversarial fixtures.
5. Corrections are append-only and bind predecessor hashes; historical bytes never change.
6. The exact 30-file closed v1 inventory matches the planning hashes; v1 integrity and all v1 negative suites still pass unchanged.
7. Dual-run parity detects any ID, batch, source hash, pending-state, owner, scenario, dependency, boundary, or provisional-binding drift.
8. Activation authority is distinct from adoption and binds operation, environment, candidate, closed prior-state facts, actor, expiry, and conditions.
9. Activation survives concurrent attempts and injected crashes before/after exclusive create, file fsync, selection-directory fsync, journal-directory fsync, pointer rename, and pointer-directory fsync.
10. Rollback/recovery publishes a new held or disabled selection reachable from the pointer, works with an unreadable pointer, keeps G0/G3 open until fresh activation, and rejects stale authorizations.
11. The engineering-only evidence-map producer is deterministic, secret-free, source-hash bound, and structurally unable to emit governance fields.
12. The schema-v2 ledger preserves immutable source pointers, uses active governance pointers, independently recomputes G0, detects stale pointer bindings, keeps G0/G3 separate, and cannot derive owner PASS or G3 from engineering overlays.
13. Canonical-root, traversal, symlink, file-type, same-device, and mutation-filesystem checks fail closed.
14. CI runs the ADR/proposal, every v1 compatibility suite, v2, dual-run, pointer, evidence-map, and ledger contracts in deterministic order.

Initial local commands after implementation:

```text
ruby tests/Documentation/G0GovernanceV2ArchitectureDecisionTest.rb
ruby tests/Documentation/G0ProportionalGovernanceV2ProposalTest.rb
ruby tests/Documentation/ParityGovernanceValidatorTest.rb
ruby scripts/validate-parity-governance.rb --mode integrity
ruby tests/Documentation/G0OwnerGovernanceSnapshotGeneratorTest.rb
ruby tests/Documentation/G0S0IntakeContractTest.rb
ruby tests/Documentation/G0ProportionalGovernanceV2ValidatorTest.rb
ruby tests/Documentation/G0ProportionalGovernanceV2GeneratorTest.rb
ruby tests/Documentation/G0ProportionalGovernanceV2MigrationTest.rb
ruby tests/Documentation/G0GovernanceConsumerPointerTest.rb
ruby tests/Documentation/G0GovernanceProfileDispatchTest.rb
ruby tests/Documentation/G0G3CoverageEvidenceMapV2Test.rb
ruby tests/Documentation/G0G3CoverageLedgerTest.rb
```

## Delivery sequence after approval

1. Record the adoption decision bound to the exact approved proposal and ADR hashes.
2. Add `G0_GOVERNANCE_V2_CONTRACT.json`, exact enum/rule parity tests, and the v2 validator with negative tests.
3. Add deterministic importer/generator and exact 268-row migration tests.
4. Generate `G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json`, add v1/v2 comparator, and prove the historical evidence-map/provisional-binding parity without importing it into authority.
5. Add separate activation-decision contracts, immutable selections/journal, lock/atomic pointer, capability probe, failure injection, and rollback/recovery tests.
6. Add schema-v2 dual-pointer dated ledger generation, independent G0 recomputation, stale-binding rejection, and separate G3 computation while retaining the historical ledger/evidence map.
7. Add the ADR, proposal, `G0OwnerGovernanceSnapshotGeneratorTest.rb`, `G0S0IntakeContractTest.rb`, all other v1, and all v2 checks to CI; batch work in local commits before the next GitHub push.
8. Run an observation-only dual comparison. Do not activate v2 in the same change.
9. Review evidence and obtain a separate activation decision.
10. Activate or keep disabled; then nominate owners and decide the first bounded cancellation slice.

## Trade-offs and consequences

Positive:

- v1 history and its large negative-test surface remain trustworthy;
- v2 is understandable and maintainable by a small team;
- every migration and activation boundary is hash-verifiable;
- rollback has a real target and cannot silently restore never-activated v1; and
- capability decisions can progress by bounded slice without falsely closing project G0.

Costs:

- two validators and compatibility suites must run during migration;
- pointer/receipt durability code requires careful cross-platform testing;
- the ledger needs a new dated schema/snapshot; and
- proportional governance still requires real domain owners—it simplifies ceremony, not accountability.

## Decision and revisit triggers

Accept this ADR only together with an attributable adoption decision for the exact governance-v2 proposal hash. Revisit it if:

- the 268-capability baseline or batch manifest is intentionally superseded;
- the programme gains an institutional governance service that can replace file-based selection safely;
- filesystem atomic-rename guarantees do not hold on the selected execution environment;
- campus production, real data, or any live integration is separately considered; or
- the owner model cannot supply the affected-domain and independent-control identities required by the risk tiers.

Until accepted, the only authorized actions are review, revision, and local validation of this planning artifact.
