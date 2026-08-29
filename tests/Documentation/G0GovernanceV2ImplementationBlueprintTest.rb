# frozen_string_literal: true

require 'digest'
require 'minitest/autorun'
require 'open3'

class G0GovernanceV2ImplementationBlueprintTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  BLUEPRINT_PATH = File.join(PHASE, 'G0_GOVERNANCE_V2_IMPLEMENTATION_BLUEPRINT_2026-08-28.md')
  PROPOSAL_PATH = File.join(PHASE, 'G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md')
  ADR_PATH = File.join(ROOT, 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md')
  ADOPTION_DRAFT_PATH = File.join(PHASE, 'G0_GOVERNANCE_V2_ADOPTION_DECISION_DRAFT_2026-08-28.json')
  CI_PATH = File.join(ROOT, '.github/workflows/documentation-checks.yml')
  PLANNING_HEAD = '19c0029730a36115f718fa48468dc56c3f722a05'
  INPUTS = {
    'docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md' => PROPOSAL_PATH,
    'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md' => ADR_PATH,
    'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION_DRAFT_2026-08-28.json' => ADOPTION_DRAFT_PATH
  }.freeze
  CURRENT_V2_GOVERNANCE_TESTS = %w[
    tests/Documentation/G0G3CoverageEvidenceMapV2Test.rb
    tests/Documentation/G0GovernanceConsumerPointerTest.rb
    tests/Documentation/G0GovernanceProfileDispatchTest.rb
    tests/Documentation/G0GovernanceV2AdoptionDecisionDraftTest.rb
    tests/Documentation/G0GovernanceV2AdoptionDecisionTest.rb
    tests/Documentation/G0GovernanceV2ArchitectureDecisionTest.rb
    tests/Documentation/G0GovernanceV2ImplementationBlueprintTest.rb
    tests/Documentation/G0GovernanceV2IndependentReviewTest.rb
    tests/Documentation/G0GovernanceV2ObservationReceiptTest.rb
    tests/Documentation/G0ProportionalGovernanceV2GeneratorTest.rb
    tests/Documentation/G0ProportionalGovernanceV2MigrationTest.rb
    tests/Documentation/G0ProportionalGovernanceV2ProposalTest.rb
    tests/Documentation/G0ProportionalGovernanceV2ValidatorTest.rb
  ].freeze
  WAVE_7_CI_COMMANDS = [
    'ruby -Itests tests/Documentation/G0ProportionalGovernanceV2ProposalTest.rb',
    'ruby -Itests tests/Documentation/G0GovernanceV2ArchitectureDecisionTest.rb',
    'ruby -Itests tests/Documentation/G0GovernanceV2IndependentReviewTest.rb',
    'ruby -Itests tests/Documentation/G0GovernanceV2AdoptionDecisionDraftTest.rb',
    'ruby -Itests tests/Documentation/G0GovernanceV2ImplementationBlueprintTest.rb',
    'ruby -Itests tests/Documentation/G0GovernanceV2AdoptionDecisionTest.rb',
    'ruby -Itests tests/Documentation/ParityGovernanceValidatorTest.rb',
    'ruby scripts/validate-parity-governance.rb --mode integrity',
    'ruby -Itests tests/Documentation/G0OwnerGovernanceSnapshotGeneratorTest.rb',
    'ruby -Itests tests/Documentation/G0S0IntakeContractTest.rb',
    'ruby -Itests tests/Documentation/G0ProportionalGovernanceV2ValidatorTest.rb',
    'ruby -Itests tests/Documentation/G0ProportionalGovernanceV2GeneratorTest.rb',
    'ruby -Itests tests/Documentation/G0ProportionalGovernanceV2MigrationTest.rb',
    'ruby -Itests tests/Documentation/G0GovernanceConsumerPointerTest.rb',
    'ruby -Itests tests/Documentation/G0GovernanceProfileDispatchTest.rb',
    'ruby -Itests tests/Documentation/G0G3CoverageEvidenceMapV2Test.rb',
    'ruby -Itests tests/Documentation/G0G3CoverageLedgerTest.rb',
    'ruby scripts/validate-g0-governance.rb --profile dual --mode integrity --source candidate --candidate-bundle "$candidate_dir" --adoption-decision docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json --json-receipt "$receipt_path"'
  ].freeze
  OBSERVATION_RECEIPT_TEST_COMMAND = 'ruby -Itests tests/Documentation/G0GovernanceV2ObservationReceiptTest.rb'

  def setup
    @blueprint = File.read(BLUEPRINT_PATH)
    @ci = File.read(CI_PATH)
  end

  def test_blueprint_is_explicitly_planning_only_and_fail_closed
    assert_includes @blueprint, '**Status:** `PLANNING ONLY / AWAITING PRODUCT-OWNER ADOPTION / NOT AUTHORITATIVE`'
    assert_includes @blueprint, '**Effect:** None.'
    assert_includes @blueprint, '**Data boundary:** `APP_MODE=SIMULATION`, synthetic teaching data only.'
    assert_includes @blueprint, "**Planning head:** `#{PLANNING_HEAD}`"
    assert_includes @blueprint, 'Until Gate A is recorded in a new immutable `G0_GOVERNANCE_V2_ADOPTION_DECISION.json`, only review, revision, and validation of planning artifacts are allowed.'
  end

  def test_bound_proposal_and_adr_hashes_are_current
    assert_includes @blueprint, Digest::SHA256.file(PROPOSAL_PATH).hexdigest
    assert_includes @blueprint, Digest::SHA256.file(ADR_PATH).hexdigest
    assert_includes File.read(PROPOSAL_PATH), '**Status:** `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE`'
    assert_includes File.read(ADR_PATH), 'Status: **Proposed / not approved / no implementation authority**'
  end

  def test_planning_head_is_reachable_and_owns_every_bound_input
    assert_git_success 'cat-file', '-e', "#{PLANNING_HEAD}^{commit}"
    assert_git_success 'merge-base', '--is-ancestor', PLANNING_HEAD, 'HEAD'

    INPUTS.each do |repository_path, current_path|
      source_bytes, status = Open3.capture2('git', 'show', "#{PLANNING_HEAD}:#{repository_path}", chdir: ROOT)
      assert status.success?, "planning head is missing #{repository_path}"
      assert_equal File.binread(current_path), source_bytes.b
    end
  end

  def test_dependency_order_and_separate_authority_gates_are_locked
    expected_waves = (0..7).map { |number| "### Wave #{number}" }
    positions = expected_waves.map do |heading|
      position = @blueprint.index(heading)
      refute_nil position, "missing #{heading}"
      position
    end
    assert_equal positions.sort, positions

    %w[Adoption Activation Capability/slice Deployment].each do |gate|
      assert_match(/^\| [A-D]\. #{Regexp.escape(gate)} \|/, @blueprint)
    end
    assert_match(/^\| E\. G3 acceptance \|/, @blueprint)
    assert_includes @blueprint, 'Activation does not approve any capability.'
    assert_includes @blueprint, 'No later node may supply evidence for an earlier authority gate.'
    assert_includes @blueprint, 'T2 triggers independent review only when `implementer_is_approver` or `cross_domain_control` is true, while T3 always requires a distinct independent-control authority separated from executor, authors, and approvers'
    assert_includes @blueprint, 'complete required defect closure, including no open P0 and no accepted or unaccepted P1'
  end

  def test_required_fail_closed_and_recovery_invariants_are_present
    required = [
      'a failed transaction leaves the previous pointer byte-identical',
      'failed readback makes consumers fail closed until explicit recovery',
      'Rollback and recovery never reactivate v1',
      'requires held-selection path/SHA only for `held`, and forbids it for `disabled`',
      'every loser returns the stable documented conflict exit/reason',
      'forces every current schema-v2 G0 and G3 verdict to `OPEN`',
      'No engineering overlay, receipt, journal, comparator, or ledger source can confer owner authority',
      'CI must not contain an activation command or `continue-on-error` for a governance check'
    ]
    required.each { |invariant| assert_includes @blueprint, invariant }
  end

  def test_candidate_dispatch_precedes_active_pointer_resolution
    candidate_wave = @blueprint.index('### Wave 4 — candidate-only observation and profile dispatch')
    pointer_wave = @blueprint.index('### Wave 5 — selector mechanics in isolated fixtures only')
    refute_nil candidate_wave
    refute_nil pointer_wave
    assert_operator candidate_wave, :<, pointer_wave
    assert_includes @blueprint, 'reject rather than stub or duplicate `--source active` behavior until Wave 5 supplies the shared read-only pointer resolver'
    assert_includes @blueprint, 'complete the dispatcher’s `v2|dual --source active` matrix by delegating to it'
  end

  def test_ci_matrix_explicitly_retains_current_and_future_contracts
    required_commands = %w[
      G0ProportionalGovernanceV2ProposalTest.rb
      G0GovernanceV2ArchitectureDecisionTest.rb
      G0GovernanceV2IndependentReviewTest.rb
      G0GovernanceV2AdoptionDecisionDraftTest.rb
      G0GovernanceV2ImplementationBlueprintTest.rb
      G0GovernanceV2AdoptionDecisionTest.rb
      ParityGovernanceValidatorTest.rb
      G0OwnerGovernanceSnapshotGeneratorTest.rb
      G0S0IntakeContractTest.rb
      G0ProportionalGovernanceV2ValidatorTest.rb
      G0ProportionalGovernanceV2GeneratorTest.rb
      G0ProportionalGovernanceV2MigrationTest.rb
      G0GovernanceConsumerPointerTest.rb
      G0GovernanceProfileDispatchTest.rb
      G0G3CoverageEvidenceMapV2Test.rb
      G0G3CoverageLedgerTest.rb
    ]
    required_commands.each { |test_file| assert_includes @blueprint, test_file }
    assert_includes @blueprint, 'ruby scripts/validate-parity-governance.rb --mode integrity'
    assert_includes @blueprint, 'ruby scripts/validate-g0-governance.rb --profile dual --mode integrity --source candidate'
  end

  def test_current_ci_runs_wave_7_contracts_once_in_exact_fail_closed_order
    command_positions = WAVE_7_CI_COMMANDS.map do |command|
      invocations = @ci.lines.count do |line|
        stripped = line.strip
        stripped == command || stripped == "run: #{command}"
      end
      assert_equal 1, invocations, "expected one CI invocation of #{command}"
      position = @ci.index(command)
      refute_nil position, "missing CI invocation of #{command}"
      position
    end

    assert_equal command_positions.sort, command_positions
    observation_contract_invocations = @ci.lines.count do |line|
      line.strip == "run: #{OBSERVATION_RECEIPT_TEST_COMMAND}"
    end
    assert_equal 1, observation_contract_invocations
    assert_operator @ci.index(WAVE_7_CI_COMMANDS.last), :<, @ci.index(OBSERVATION_RECEIPT_TEST_COMMAND)
    assert_includes @ci, 'fetch-depth: 0'
    assert_includes @ci, 'Check proportional G0 governance v2 contract discovery'
    CURRENT_V2_GOVERNANCE_TESTS.each { |path| assert_includes @ci, path }
    assert_includes @ci, 'abort("Governance v2 planning-test set drifted: #{actual.inspect}") unless actual == required'
    refute_match(/actual\.each\s*\{[^}]*require/, @ci)
    refute_includes @ci, 'continue-on-error:'
    refute_includes @ci, 'select-g0-governance-consumer.rb'
  end

  def test_ci_candidate_observation_is_ephemeral_exclusive_and_pointer_neutral
    required = [
      'mktemp -d "$PWD/.g0-governance-v2-ci-observation.XXXXXX"',
      'chmod 700 "$observation_root"',
      'trap cleanup EXIT',
      'candidate_dir="$observation_root/candidate"',
      'receipt_path="$observation_root/dual-integrity-receipt.json"',
      'test ! -e "$receipt_path"',
      'ruby scripts/generate-g0-proportional-governance-v2.rb --root "$PWD" --output "$candidate_dir"',
      '"status" => "PASS"',
      '"reason_code" => "dual_candidate_observation_passed"',
      'pointer_before="$(pointer_state)"',
      'pointer_after="$(pointer_state)"',
      'test "$pointer_before" = "$pointer_after"',
      'rm -rf -- "$observation_root"'
    ]
    required.each { |contract| assert_includes @ci, contract }

    assert_operator @ci.index('case "$observation_root" in'), :<, @ci.index('rm -rf -- "$observation_root"')
    assert_operator @ci.index('pointer_before="$(pointer_state)"'), :<, @ci.index(WAVE_7_CI_COMMANDS.last)
    assert_operator @ci.index(WAVE_7_CI_COMMANDS.last), :<, @ci.index('pointer_after="$(pointer_state)"')
    refute_match(/--source\s+active/, @ci)
    refute_match(/select-g0-governance-consumer\.rb/, @ci)
  end

  def test_current_v2_governance_contract_discovery_is_closed_and_complete
    discovered = (
      Dir[File.join(ROOT, 'tests/Documentation/G0*V2*Test.rb')] +
      %w[
        tests/Documentation/G0GovernanceConsumerPointerTest.rb
        tests/Documentation/G0GovernanceProfileDispatchTest.rb
      ].map { |path| File.join(ROOT, path) }
    )
      .uniq
      .sort
      .map { |path| path.delete_prefix("#{ROOT}/") }

    assert_equal CURRENT_V2_GOVERNANCE_TESTS, discovered
  end

  def test_blueprint_has_balanced_fences_and_no_secret_material
    assert_predicate @blueprint.scan(/^```/).length, :even?
    secret_pattern = /(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|postgres(?:ql)?:\/\/[^\s:]+:[^\s@]+@|(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)["']?\s*(?::|=)\s*["']?[^\s,;}"']+)/i
    refute_match secret_pattern, @blueprint
  end

  private

  def assert_git_success(*arguments)
    _output, status = Open3.capture2e('git', *arguments, chdir: ROOT)
    assert status.success?, "git #{arguments.join(' ')} failed"
  end
end
