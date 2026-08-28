# frozen_string_literal: true

require 'digest'
require 'minitest/autorun'
require 'open3'

class G0GovernanceV2ArchitectureDecisionTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  ADR_PATH = File.join(ROOT, 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md')
  PROPOSAL_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md')
  EVIDENCE_MAP_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json')
  LEDGER_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-27.json')
  VALIDATOR_PATH = File.join(ROOT, 'scripts/validate-parity-governance.rb')
  LEDGER_GENERATOR_PATH = File.join(ROOT, 'scripts/generate-g0-g3-coverage-ledger.rb')
  CI_PATH = File.join(ROOT, '.github/workflows/documentation-checks.yml')

  PLANNING_BASELINE = 'ad326cf2e9b36e6864e029adba10bd0d86a0cbc4'
  PROPOSAL_SHA256 = 'f5c635006f878a68c4b0775be5262115fe38e185562d3be558e02d8b28c38695'
  EVIDENCE_MAP_SHA256 = '3cfcc9898f4b541cea85cd448638fc300cae48eb5172c3dffd598925d9244ea0'
  LEDGER_SHA256 = 'd043e4ce3d2893bd19f543a971561b57964926a385969f0a2e7266e943196cbf'

  V1_BASELINE_SHA256 = {
    'docs/new-simrs-rebuild/phase-0/PARITY_MATRIX_BASELINE.json' => '38e5889889ba23b5b7e4b59af9e669161b87b5c5b6e8c7cb3113f086c3af11ae',
    'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json' => '59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json' => 'a5c1e01686fdb576026802331d3228df9a6335f6f7176b7c023c459bfc532098',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json' => '3db0a698be4f7729f24f997dbbc54e69c7c98442237fb7a9d4b12358971eeb1f',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_REGISTER_2026-08-25.json' => '7b97e8cc5169ff848dc5ca524281f93ad5b42b0a2134acc52cf91368cc580775',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_REGISTER_2026-08-25.json' => 'da057e99d9d7d2349662f532605efec87edf220deb885783852088a2975c6287',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_REGISTER_2026-08-25.json' => '10778f021fc23f7fac0d3dfbdaacd8574cc424aaa76788afd7a26ba9f426a93b',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_F_DECISION_REGISTER_2026-08-25.json' => 'd2e78446dc2aa84162c8229dec8ab809bd45ace51a8fc6a313da95286503b795',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json' => '53bef20b3f509c543213e649b2d5f6644e9dbe00a7dae34d209c57a93137612e',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_EVIDENCE_2026-08-25/README.md' => '1f5b1c82fd342bddbc1da3949542be026d0909052749715878e10d0af21bcf2c',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_EVIDENCE_2026-08-25/README.md' => '0cd2059ce381dcd65bda6ae00a08a43d2b4592771a34dba2277b2725a25e004e',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_C_DECISION_EVIDENCE_2026-08-25/README.md' => 'f95417dfb63f2d65202577a78245d3f19405e30770fba0fd95c4659691e4781f',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_D_DECISION_EVIDENCE_2026-08-25/README.md' => '7144bc8b22f25beab7e51b0840450b52a4e9365fb02cc91bd139f8e88231cc10',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_E_DECISION_EVIDENCE_2026-08-25/README.md' => '19e8037d03dc9baa37f7653c3e8b411ddfb010affcd97c1ed428fc980516286d',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_F_DECISION_EVIDENCE_2026-08-25/README.md' => 'e03dacd43c04cfb6c58ff952334a42cae0f7505c2ff030c7f376d9348fa5aa54',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_EVIDENCE_2026-08-25/README.md' => 'ffa4f1870ca91184437962698c47b70a41fcc0df1fb3d13facdf5193c705c5da',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json' => '3ab2abd46fdcbbd7f13ff6bd87857834c88e1cc373894bbe78fa54ae0a440ee0',
    'docs/new-simrs-rebuild/phase-0/G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json' => 'ba0cc0002a23a6fb04fa645c7c8089e8e5ce8d5a3ca7752fac023ae304eeb206',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json' => 'bd43a7f0fbfc6f3a6f571c6077f7ac4722cd241006358c7fd325c0a27c37cc75',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json' => '85697fd4c1979f6447e1e0b1ab0cf761ca8c8755941c9059795aea95cb77f008',
    'docs/new-simrs-rebuild/phase-0/G0_DECISION_SESSION_REGISTER_2026-08-25.json' => 'a86576e0bb80171b275a1f7b7d8dde92ecf3bcc0856a4b386481ef68746117b3',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_DECISION_EVIDENCE_2026-08-25/README.md' => '025f05437688815077754d57e82010441329dcaeeb4ce843c2888427770954e3',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_PACK_2026-08-25.md' => '9beb1ce52abec72ab829d479e0d4835409ca8356b3217818bb04286a663310b1',
    'docs/new-simrs-rebuild/phase-0/G0_S0_INSTITUTIONAL_AUTHORITY_INTAKE_2026-08-26.md' => '3b67978695b8729463d52e6bb6063713bcd70fb763e418381372f8c30e545a90',
    'scripts/validate-parity-governance.rb' => '251cd15d947ea14b517f56ac23aee143faa91d59ce349d96756c8e88378cf65f',
    'scripts/generate-g0-owner-governance-snapshot.rb' => 'a6c0e229123e30d02777a6f085c2da94062ada22e7a1676cea6ed77c1fab3a86',
    'scripts/validate-g0-s0-intake.rb' => 'd90e5452c63d61162e7683a2999fea89a3cb2e76c25048af3e907e03eb165ee5',
    'tests/Documentation/ParityGovernanceValidatorTest.rb' => '92441766a024eabe9462767f58d4985b87a5da9a636c22d67c4cab973c35e182',
    'tests/Documentation/G0OwnerGovernanceSnapshotGeneratorTest.rb' => 'a9f28a2fe4d682274aa1c18a71144c850c1f3a0c42423a6d7673a191dcffda51',
    'tests/Documentation/G0S0IntakeContractTest.rb' => '0d3de612c2a937434530c4c1d02477df57afcf264877475c3dbc1a63126b7be7'
  }.freeze

  CONSEQUENCE_FLAGS = %w[
    clinical_or_rm_lifecycle diagnostic medication inventory_without_valuation
    tariff_or_charge_without_money_movement claims_simulation report_formula
    correction_or_amendment denial_behavior cross_domain_control implementer_is_approver
    privileged_access_or_security privacy_or_export money_movement_or_stock_valuation
    clinical_safety_override external_integration migration_restore_or_retained_write_recovery
    statutory_output
  ].freeze

  GOVERNANCE_STATES = %w[
    PENDING DISCOVERY PROPOSED REVISION_REQUIRED PROPOSAL_REJECTED
    AUTHORIZED_FOR_SYNTHETIC_BUILD DEFERRED RETIRED EXCLUDED
  ].freeze

  def setup
    @adr = File.read(ADR_PATH)
    @proposal = File.read(PROPOSAL_PATH)
    @validator = File.read(VALIDATOR_PATH)
    @ledger_generator = File.read(LEDGER_GENERATOR_PATH)
    @ci = File.read(CI_PATH)
  end

  def test_adr_is_proposed_and_cannot_authorize_implementation_or_release
    assert_includes @adr, 'Status: **Proposed / not approved / no implementation authority**'
    assert_includes @adr, 'This ADR does not adopt governance v2, appoint an owner, decide a capability, authorize a synthetic build'
    assert_includes @adr, 'Implementation may begin only after an attributable product-owner adoption decision'
    assert_includes @adr, 'Selecting an active consumer, authorizing a capability slice, deploying an application, and accepting G3 remain separate decisions.'
    assert_includes @adr, 'Until accepted, the only authorized actions are review, revision, and local validation of this planning artifact.'
    refute_match(/^\s*(?:[-*+]\s+)?\[[xX]\]/, @adr)
  end

  def test_planning_baseline_and_proposal_are_cryptographically_bound
    assert_equal PROPOSAL_SHA256, Digest::SHA256.file(PROPOSAL_PATH).hexdigest
    assert_equal EVIDENCE_MAP_SHA256, Digest::SHA256.file(EVIDENCE_MAP_PATH).hexdigest
    assert_equal LEDGER_SHA256, Digest::SHA256.file(LEDGER_PATH).hexdigest
    assert_includes @adr, "Planning baseline: `#{PLANNING_BASELINE}`"
    assert_includes @adr, "Proposal SHA-256 at planning baseline: `#{PROPOSAL_SHA256}`"
    assert_includes @adr, "Historical evidence-map SHA-256: `#{EVIDENCE_MAP_SHA256}`"
    assert_includes @adr, "Historical ledger SHA-256: `#{LEDGER_SHA256}`"

    baseline_proposal = git('show', "#{PLANNING_BASELINE}:docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md")
    assert_equal PROPOSAL_SHA256, Digest::SHA256.hexdigest(baseline_proposal)
    _stdout, stderr, status = Open3.capture3('git', 'merge-base', '--is-ancestor', PLANNING_BASELINE, 'HEAD', chdir: ROOT)
    assert status.success?, "planning baseline is not an ancestor of HEAD: #{stderr}"

    assert_includes @proposal, '**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE`'
    refute_match(/^\s*(?:[-*+]\s+)?\[[xX]\]/, @proposal)
  end

  def test_adr_and_proposal_share_closed_states_flags_and_synthetic_boundary
    GOVERNANCE_STATES.each do |state|
      assert_includes @proposal, state
      assert_includes @adr, "`#{state}`"
    end

    CONSEQUENCE_FLAGS.each do |flag|
      assert_includes @proposal, "`#{flag}`"
      assert_includes @adr, "`#{flag}`"
    end

    assert_includes @proposal, 'V2 cannot authorize real patient data or a live BPJS, VClaim, SATUSEHAT'
    assert_includes @adr, 'v2 can never authorize real patient data or live BPJS, VClaim, SATUSEHAT'
    assert_includes @proposal, 'the family tier must equal the maximum derived member tier'
    assert_includes @adr, 'Mixed members require explicit exceptions.'
    assert_includes @proposal, 'distinct identity from the implementation executor and all decision authors and approvers'
    assert_includes @adr, 'Reviewer equals executor/author/approver where independent review is triggered'
    assert_includes @adr, '`G0_GOVERNANCE_V2_CONTRACT.json` is the canonical machine-readable contract'
    assert_includes @adr, 'The validator reads the JSON contract, not Markdown.'
    assert_includes @adr, 'Contract tests compare the exact ordered arrays and rules to the adopted proposal/ADR constants'
  end

  def test_architecture_is_additive_and_all_preserved_v1_inputs_exist
    assert_includes @adr, 'B. Add an independent v2 profile, deterministic comparator, and versioned consumer pointer'
    assert_includes @adr, 'V1 remains immutable, runnable historical evidence'
    assert_equal 30, V1_BASELINE_SHA256.length
    inventory_section = @adr.split('### Closed v1 preservation inventory at the planning baseline', 2).fetch(1)
                            .split('### Files preserved byte-for-byte', 2).first
    inventory_rows = inventory_section.lines.each_with_object([]) do |line, rows|
      match = line.match(/\A([^|\n]+)\|([0-9a-f]{64})\n?\z/)
      rows << [match[1], match[2]] if match
    end
    assert_equal V1_BASELINE_SHA256.to_a, inventory_rows

    V1_BASELINE_SHA256.each do |path, sha256|
      full_path = File.join(ROOT, path)
      assert File.file?(full_path), "missing preserved v1 path #{path}"
      assert_equal sha256, Digest::SHA256.file(full_path).hexdigest, "v1 byte drift at #{path}"
      baseline_bytes = git('show', "#{PLANNING_BASELINE}:#{path}")
      assert_equal sha256, Digest::SHA256.hexdigest(baseline_bytes), "planning-baseline drift at #{path}"
    end

    assert_includes @adr, '`scripts/validate-parity-governance.rb`, `scripts/generate-g0-owner-governance-snapshot.rb`, and `scripts/validate-g0-s0-intake.rb`.'
    assert_includes @adr, 'Their existing v1 tests and fixtures.'
    assert_includes @adr, 'never rewrite the 2026-08-27 evidence map or ledger snapshot.'
  end

  def test_current_v1_cli_ci_and_ledger_facts_are_not_misrepresented
    assert_includes @validator, "opts.on('--mode MODE', %w[integrity g0]"
    assert_includes @ci, 'ruby tests/Documentation/ParityGovernanceValidatorTest.rb'
    assert_includes @ci, 'ruby scripts/validate-parity-governance.rb --mode integrity'
    assert_includes @ci, 'ruby tests/Documentation/G0G3CoverageLedgerTest.rb'
    assert_includes @ledger_generator, "'status' => 'OPEN'"
    assert_includes @ledger_generator, "'decision_pointer' =>"
    assert_includes @adr, 'The current ledger\'s per-capability governance presence comes from immutable pending A–G rows and its formal gate is intentionally hardcoded `OPEN`.'
  end

  def test_design_contains_complete_artifact_and_test_file_map
    %w[
      scripts/g0-proportional-governance-v2.rb
      scripts/generate-g0-proportional-governance-v2.rb
      scripts/validate-g0-proportional-governance-v2.rb
      scripts/compare-g0-governance-v1-v2.rb
      scripts/select-g0-governance-consumer.rb
      scripts/validate-g0-governance.rb
      scripts/generate-g0-g3-coverage-evidence-map-v2.rb
      tests/Documentation/G0GovernanceV2ArchitectureDecisionTest.rb
      tests/Documentation/G0G3CoverageEvidenceMapV2Test.rb
    ].each { |path| assert_includes @adr, "`#{path}`" }

    %w[
      G0_GOVERNANCE_V2_ADOPTION_DECISION.json
      G0_GOVERNANCE_V2_CONTRACT.json
      G0_GOVERNANCE_V2_ACTIVATION_DECISIONS/*.json
      G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json
      G0_GOVERNANCE_V2_AUTHORITY_REGISTER.json
      G0_GOVERNANCE_V2_OWNER_REGISTER.json
      G0_GOVERNANCE_V2_DECISION_EVENT_REGISTER.json
      G0_GOVERNANCE_V2_EXPANDED_DECISION_REGISTER.json
      G0_GOVERNANCE_V2_GATE_REGISTER.json
      G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json
      G0_GOVERNANCE_CONSUMER_SELECTIONS/*.json
      G0_GOVERNANCE_CONSUMER_JOURNAL/*.json
      G0_GOVERNANCE_CONSUMER_POINTER.json
    ].each { |name| assert_includes @adr, "`#{name}`" }

    assert_includes @adr, 'ruby tests/Documentation/G0OwnerGovernanceSnapshotGeneratorTest.rb'
    assert_includes @adr, 'ruby tests/Documentation/G0S0IntakeContractTest.rb'
    assert_includes @adr, 'ruby tests/Documentation/G0GovernanceV2ArchitectureDecisionTest.rb'
  end

  def test_schema_v2_ledger_uses_dual_pointers_and_independent_gate_recomputation
    assert_includes @adr, '`schema_version: 2`'
    assert_includes @adr, '`source_decision_pointer`'
    assert_includes @adr, '`governance_decision_pointer`'
    assert_includes @adr, 'Owner, approval, disposition, and governance state are derived only from a hash-valid `governance_decision_pointer`'
    assert_includes @adr, 'The ledger independently recomputes G0 from all 268 expanded rows'
    assert_includes @adr, 'It does not copy a self-declared gate.'
    assert_includes @adr, 'an existing ledger whose binding no longer equals the active pointer is stale'
    assert_includes @adr, '`gate_summary.g0` and `gate_summary.g3` remain separate.'
    assert_includes @adr, 'Neither gate can be promoted by the engineering overlay alone.'

    mutable_section = @adr.split('### Files that may change after approval', 2).fetch(1).split('### Files preserved byte-for-byte', 2).first
    refute_includes mutable_section, 'G0_G3_COVERAGE_LEDGER_2026-08-27.json'
    refute_includes mutable_section, 'G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json'
  end

  def test_new_evidence_map_is_deterministic_and_engineering_only
    assert_includes @adr, '`G0_G3_COVERAGE_EVIDENCE_MAP_V2_YYYY-MM-DD.json` is generated deterministically'
    assert_includes @adr, 'Owner identities, approval references, decisions, dispositions, consequence flags, tiers, consumer pointers, and gate states are forbidden fields.'
    assert_includes @adr, '`G0G3CoverageEvidenceMapV2Test.rb` checks deterministic bytes, source hashes, secret rejection'
    assert_includes @adr, 'The ledger consumes this map only for engineering/evidence dimensions; it never becomes a governance source.'
  end

  def test_pointer_has_one_authority_and_activation_is_separate_from_adoption
    assert_includes @adr, 'The only mutable selector; binds one selection path/SHA'
    assert_includes @adr, 'The pointer does not duplicate bundle, adoption, activation, or last-known-good fields.'
    assert_includes @adr, 'The immutable selection is the single source that binds its kind, adoption decision, operation decision, exact bundle or held predecessor, prior-state facts, and previous validated selection.'
    assert_includes @adr, 'Validate the approved adoption decision and the separate operation decision.'
    assert_includes @adr, 'The operation decision must bind the exact operation, environment, candidate/held selection SHA, closed prior-state representation, actor, conditions, and expiry.'
    assert_includes @adr, 'For `recover`, it must also bind `recover_outcome: held|disabled` exactly; the held-selection path/SHA is required in the decision only for `held` and forbidden for `disabled`.'
    assert_includes @adr, '`activate` | activation decision, candidate bundle, prior state, receipt'
    assert_includes @adr, 'creates a new immutable `rollback_hold` selection'
    assert_includes @adr, 'The pointer publishes that new selection with `status: held`; it never points directly to the old `activation` selection.'
    assert_includes @adr, 'Recovery creates a new `recovery_hold` selection and publishes `status: held`.'
    assert_includes @adr, 'forces project G0 and G3 `OPEN` for every schema-v2 ledger while that selection remains current'
  end

  def test_cli_option_matrix_and_machine_receipt_are_closed
    assert_includes @adr, '--profile v1|v2|dual'
    assert_includes @adr, '--mode integrity|g0'
    assert_includes @adr, '--source candidate|active'
    assert_includes @adr, '`--source` is required for v2/dual and forbidden for v1.'
    assert_includes @adr, 'Candidate and active inputs are mutually exclusive.'
    assert_includes @adr, '`v2 --source candidate`'
    assert_includes @adr, '`v2 --source active`'
    assert_includes @adr, '`dual --source candidate`'
    assert_includes @adr, '`dual --source active`'
    assert_includes @adr, '`--root` is permitted for every profile and defaults to the canonical checkout.'
    assert_includes @adr, 'Exit `0` means the requested contract passed, exit `1` means a validation/gate failure, and exit `2` means invalid CLI usage.'
    assert_includes @adr, '`schema_version`, `operation_id`, `operation`, `profile`, `mode`, `source`, `status`, `reason_code`'
    assert_includes @adr, '`expected-pointer-sha256` is required exactly when prior state is `valid_pointer` and forbidden otherwise.'
    assert_includes @adr, '`observed-unreadable-pointer-sha256` is required exactly for `unreadable_pointer` and forbidden otherwise.'
    assert_includes @adr, '| `rollback` | activation decision, prior state, expected pointer SHA, receipt | `valid_pointer` only |'
    assert_includes @adr, '| `recover` with outcome `held` | activation decision, prior state, recover outcome, recover-selection path/SHA, receipt | `missing_pointer` or `unreadable_pointer` |'
    assert_includes @adr, '| `recover` with outcome `disabled` | activation decision, prior state, recover outcome, receipt | `missing_pointer` or `unreadable_pointer` |'

    matrix = @adr.split('The mutation option matrix is closed:', 2).fetch(1).split('## Activation and rollback', 2).first
    operation_rows = matrix.lines.grep(/^\| `(?:activate|rollback|disable|recover)/).map(&:strip)
    assert_equal [
      '| `activate` | activation decision, candidate bundle, prior state, receipt | `valid_pointer` or `initial_state` | recover-selection fields, unreadable hash |',
      '| `rollback` | activation decision, prior state, expected pointer SHA, receipt | `valid_pointer` only | candidate bundle, recover-selection fields, unreadable hash |',
      '| `disable` | activation decision, prior state, expected pointer SHA, receipt | `valid_pointer` only | candidate bundle, recover-selection fields, unreadable hash |',
      '| `recover` with outcome `held` | activation decision, prior state, recover outcome, recover-selection path/SHA, receipt | `missing_pointer` or `unreadable_pointer` | candidate bundle, expected pointer SHA |',
      '| `recover` with outcome `disabled` | activation decision, prior state, recover outcome, receipt | `missing_pointer` or `unreadable_pointer` | candidate bundle, expected pointer SHA, recover-selection fields |'
    ], operation_rows
  end

  def test_mutation_paths_durability_and_unreadable_pointer_recovery_fail_closed
    assert_includes @adr, 'rejects path traversal, symlinks at any component, non-regular files'
    assert_includes @adr, 'A `--root` override is permitted only for isolated test fixtures'
    assert_includes @adr, 'same-device atomic replacement, file `fsync`, directory `fsync`, and durable readback'
    assert_includes @adr, 'All mutation operations—activate, rollback, disable, and recover—refuse to run when any guarantee is unavailable'
    assert_includes @adr, 'Create the immutable selection with exclusive `O_EXCL` semantics'
    assert_includes @adr, '`fsync` the selection directory entry before publishing any pointer.'
    assert_includes @adr, 'Create an immutable hash-chained journal record with `O_EXCL`'
    assert_includes @adr, '`fsync` the journal directory.'
    assert_includes @adr, 'For recovery outcome `held`, `recover` requires the operator-supplied immutable selection path/SHA plus a new recovery operation decision'
    assert_includes @adr, 'recovery outcome `disabled` forbids selection fields and creates a new `disabled` selection/pointer'
    assert_includes @adr, '`prior_state_reason: unreadable_pointer` requires null `expected_prior_pointer_sha256` plus the 64-hex SHA-256 of the raw unreadable bytes'
    assert_includes @adr, '`initial_state` is valid only when the pointer is absent and the canonical selection and journal directories contain no records'
    assert_includes @adr, '`missing_pointer` is valid only when the pointer is absent but validated selection or journal history exists'
    assert_includes @adr, 'A declaration that differs from derived state exits `1` before mutation.'
    assert_includes @adr, 'It never activates v1.'
    assert_includes @adr, 'forces project G0 and G3 `OPEN`'
  end

  def test_delivery_separates_observation_activation_and_first_slice
    assert_includes @adr, 'Run an observation-only dual comparison. Do not activate v2 in the same change.'
    assert_includes @adr, 'obtain a separate activation decision.'
    assert_includes @adr, 'Activate or keep disabled; then nominate owners and decide the first bounded cancellation slice.'
    assert_includes @adr, 'batch work in local commits before the next GitHub push.'
    assert_includes @adr, 'set checkout `fetch-depth: 0` for the pinned-baseline byte test'
  end

  private

  def git(*args)
    stdout, stderr, status = Open3.capture3('git', *args, chdir: ROOT)
    assert status.success?, "git #{args.join(' ')} failed: #{stderr}"
    stdout
  end

end
