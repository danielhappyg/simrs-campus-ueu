# frozen_string_literal: true

require 'json'
require 'minitest/autorun'
require 'fileutils'
require 'tmpdir'

require_relative '../../scripts/g0-proportional-governance-v2'

class G0ProportionalGovernanceV2ValidatorTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  CONTRACT_PATH = File.join(PHASE, 'G0_GOVERNANCE_V2_CONTRACT.json')
  V1_MANIFEST_PATH = File.join(PHASE, 'G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json')
  Validator = G0ProportionalGovernanceV2

  def setup
    @contract = Validator.parse_json_file(CONTRACT_PATH)
    @manifest = Validator.parse_json_file(V1_MANIFEST_PATH)
  end

  def test_current_contract_and_historical_manifest_pass
    assert Validator.validate_contract!(@contract, root: ROOT)
    assert Validator.verify_v1_manifest!(@manifest, root: ROOT)
    assert_equal '1.0.0', @contract.dig('validator', 'version')
    assert_equal 30, @manifest.fetch('files').length
  end

  def test_recursive_duplicate_json_keys_fail_closed
    error = assert_raises(Validator::ParseError) do
      Validator.parse_json('{"outer":{"state":"PENDING","state":"PROPOSED"}}', label: 'fixture')
    end

    assert_includes error.message, 'duplicate JSON object key'
  end

  def test_canonical_json_is_recursive_stable_and_does_not_mutate_input
    first = { 'z' => [{ 'b' => 2, 'a' => 1 }], 'a' => true }
    second = { 'a' => true, 'z' => [{ 'a' => 1, 'b' => 2 }] }
    original = Marshal.load(Marshal.dump(first))

    assert_equal '{"a":true,"z":[{"a":1,"b":2}]}', Validator.canonical_json(first)
    assert_equal Validator.canonical_json(first), Validator.canonical_json(second)
    assert_equal Validator.canonical_sha256(first), Validator.canonical_sha256(second)
    assert_equal original, first
  end

  def test_contract_rejects_unknown_fields_wrong_types_and_stale_source_hashes
    unknown = deep_copy(@contract)
    unknown['unexpected'] = true
    assert_validation_error(/unknown fields unexpected/) { Validator.validate_contract!(unknown, root: ROOT) }

    wrong_type = deep_copy(@contract)
    wrong_type['boundary']['real_patient_data_authorized'] = 'false'
    assert_validation_error(/expected boolean/) { Validator.validate_contract!(wrong_type, root: ROOT) }

    stale = deep_copy(@contract)
    stale['adopted_sources']['proposal']['sha256'] = '0' * 64
    assert_validation_error(/adopted source hash mismatch/) { Validator.validate_contract!(stale, root: ROOT) }
  end

  def test_contract_rejects_coordinated_semantic_and_source_rewrites
    coordinated_source = deep_copy(@contract)
    coordinated_source['adopted_sources']['proposal'] = deep_copy(coordinated_source['adopted_sources']['architecture'])
    assert_validation_error(/semantic drift/) { Validator.validate_contract!(coordinated_source, root: ROOT) }

    [
      ->(contract) { contract['closed_values']['owner_states'][0] = 'INVENTED' },
      ->(contract) { contract['governance_transitions'] = [] },
      ->(contract) { contract['owner_outcome_rules'][0]['governance_state'] = 'DEFERRED' },
      ->(contract) { contract['authority_rules']['all_tiers_require'] = ['technical_reviewer'] },
      ->(contract) { contract['gate_rules']['project_g0_pass_requires'] = ['caller_says_pass'] }
    ].each do |mutation|
      changed = deep_copy(@contract)
      mutation.call(changed)
      assert_validation_error(/semantic drift/) { Validator.validate_contract!(changed, root: ROOT) }
    end
  end

  def test_consequence_map_is_closed_complete_and_boolean
    missing = false_consequences
    missing.delete('diagnostic')
    assert_validation_error(/missing fields diagnostic/) { Validator.validate_consequence_map!(missing, @contract) }

    unknown = false_consequences.merge('unregistered_risk' => false)
    assert_validation_error(/unknown fields unregistered_risk/) { Validator.validate_consequence_map!(unknown, @contract) }

    wrong_type = false_consequences.merge('diagnostic' => 0)
    assert_validation_error(/expected boolean/) { Validator.validate_consequence_map!(wrong_type, @contract) }
  end

  def test_tier_derivation_obeys_t3_precedence_over_t2
    assert_equal 'T1_STANDARD', Validator.derived_tier(false_consequences, @contract)
    assert_equal 'T2_DOMAIN_CRITICAL', Validator.derived_tier(false_consequences.merge('diagnostic' => true), @contract)

    mixed = false_consequences.merge('diagnostic' => true, 'privacy_or_export' => true)
    assert_equal 'T3_INDEPENDENT_CONTROL', Validator.derived_tier(mixed, @contract)
  end

  def test_declared_tier_must_equal_not_merely_exceed_derived_tier
    assert_validation_error(/must equal/) do
      Validator.validate_declared_tier!('T3_INDEPENDENT_CONTROL', false_consequences, @contract)
    end
    assert_validation_error(/must equal/) do
      Validator.validate_declared_tier!('T1_STANDARD', false_consequences.merge('diagnostic' => true), @contract)
    end
  end

  def test_t2_review_is_triggered_only_by_closed_trigger_flags
    ordinary_t2 = authority_args(false_consequences.merge('diagnostic' => true), 'T2_DOMAIN_CRITICAL')
    assert_equal 'T2_DOMAIN_CRITICAL', Validator.validate_authority_independence!(**ordinary_t2)

    triggered = authority_args(false_consequences.merge('cross_domain_control' => true), 'T2_DOMAIN_CRITICAL')
    assert_validation_error(/required by consequence/) { Validator.validate_authority_independence!(**triggered) }

    triggered[:independent_reviewer] = { 'identity' => 'reviewer-1', 'capacity' => 'accountable_domain_authority' }
    assert_equal 'T2_DOMAIN_CRITICAL', Validator.validate_authority_independence!(**triggered)
  end

  def test_independent_reviewer_cannot_be_executor_author_or_approver
    args = authority_args(false_consequences.merge('cross_domain_control' => true), 'T2_DOMAIN_CRITICAL')
    %w[executor-1 author-1 approver-1].each do |identity|
      candidate = args.merge(independent_reviewer: { 'identity' => identity, 'capacity' => 'accountable_domain_authority' })
      assert_validation_error(/must be distinct/) { Validator.validate_authority_independence!(**candidate) }
    end
  end

  def test_t3_requires_distinct_independent_control_authority
    args = authority_args(false_consequences.merge('privacy_or_export' => true), 'T3_INDEPENDENT_CONTROL')
    args[:independent_reviewer] = { 'identity' => 'reviewer-1', 'capacity' => 'accountable_domain_authority' }
    assert_validation_error(/wrong capacity for T3/) { Validator.validate_authority_independence!(**args) }

    args[:independent_reviewer]['capacity'] = 'independent_control_authority'
    assert_equal 'T3_INDEPENDENT_CONTROL', Validator.validate_authority_independence!(**args)

    args[:independent_reviewer]['capacity'] = 'invented_capacity'
    assert_validation_error(/unknown authority capacity/) { Validator.validate_authority_independence!(**args) }
  end

  def test_product_domain_capacities_scopes_and_material_co_owner_approval_are_explicit
    args = authority_args(false_consequences.merge('diagnostic' => true), 'T2_DOMAIN_CRITICAL')
    args[:product_authority] = args[:product_authority].merge('capacity' => 'accountable_domain_authority')
    assert_validation_error(/expected product_authority/) { Validator.validate_authority_independence!(**args) }

    args = authority_args(false_consequences.merge('diagnostic' => true), 'T2_DOMAIN_CRITICAL')
    args[:domain_authority] = args[:domain_authority].merge('scopes' => [])
    assert_validation_error(/must not be empty/) { Validator.validate_authority_independence!(**args) }

    args = authority_args(false_consequences.merge('diagnostic' => true), 'T2_DOMAIN_CRITICAL')
    args[:materially_affected_co_owner_identities] = ['lab-owner-1']
    assert_validation_error(/missing required owner approval/) { Validator.validate_authority_independence!(**args) }
    args[:approver_identities] << 'lab-owner-1'
    assert_equal 'T2_DOMAIN_CRITICAL', Validator.validate_authority_independence!(**args)
  end

  def test_family_with_identical_members_and_exact_maximum_tier_passes
    rows = [family_row('PAR-REG-001'), family_row('PAR-REG-002')]
    family = { 'member_ids' => rows.map { |row| row.fetch('requirement_id') }, 'declared_tier' => 'T2_DOMAIN_CRITICAL', 'exception_ids' => [] }

    assert Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
  end

  def test_mixed_family_requires_enumerated_exception_and_own_owner_record
    rows = [family_row('PAR-REG-001'), family_row('PAR-REG-002')]
    rows.last['conditions'] = ['different-condition']
    family = { 'member_ids' => rows.map { |row| row.fetch('requirement_id') }, 'declared_tier' => 'T2_DOMAIN_CRITICAL', 'exception_ids' => [] }
    assert_validation_error(/mixed inherited family field conditions/) do
      Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
    end

    family['exception_ids'] = ['PAR-REG-002']
    assert_validation_error(/exception requires its own owner record/) do
      Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
    end

    rows.last['own_owner_record_id'] = 'OWNER-EXCEPTION-1'
    assert Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
  end

  def test_family_tier_is_maximum_of_all_members_including_exceptions
    rows = [family_row('PAR-REG-001'), family_row('PAR-REG-002')]
    rows.last['consequence_map'] = false_consequences.merge('privacy_or_export' => true)
    rows.last['derived_tier'] = 'T3_INDEPENDENT_CONTROL'
    rows.last['independent_review_requirement'] = true
    rows.last['own_owner_record_id'] = 'OWNER-EXCEPTION-1'
    family = { 'member_ids' => rows.map { |row| row.fetch('requirement_id') }, 'declared_tier' => 'T2_DOMAIN_CRITICAL', 'exception_ids' => ['PAR-REG-002'] }

    assert_validation_error(/maximum derived member tier/) do
      Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
    end
    family['declared_tier'] = 'T3_INDEPENDENT_CONTROL'
    assert Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
  end

  def test_family_rows_must_exactly_match_enumerated_ids_and_order
    rows = [family_row('PAR-REG-002'), family_row('PAR-REG-001')]
    family = { 'member_ids' => %w[PAR-REG-001 PAR-REG-002], 'declared_tier' => 'T2_DOMAIN_CRITICAL', 'exception_ids' => [] }

    assert_validation_error(/exactly match member_ids in declared order/) do
      Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
    end
  end

  def test_family_rejects_invalid_member_base_semantics
    family = { 'member_ids' => %w[PAR-REG-001 PAR-REG-002], 'declared_tier' => 'T2_DOMAIN_CRITICAL', 'exception_ids' => [] }
    mutations = [
      [->(row) { row['requirement_id'] = nil }, /canonical requirement ID/],
      [->(row) { row['disposition'] = 'invented' }, /unknown canonical disposition/],
      [->(row) { row['evidence_boundary'] = 'real_data' }, /must remain synthetic_only/],
      [->(row) { row['target_pattern'] = '' }, /expected nonempty_string/],
      [->(row) { row['owners'] = [] }, /must not be empty/]
    ]

    mutations.each do |mutation, pattern|
      rows = [family_row('PAR-REG-001'), family_row('PAR-REG-002')]
      mutation.call(rows.first)
      adjusted_family = deep_copy(family)
      adjusted_family['member_ids'][0] = rows.first['requirement_id']
      assert_validation_error(pattern) { Validator.validate_family_decision!(adjusted_family, rows, @contract, root: ROOT) }
    end
  end

  def test_family_rejects_pattern_valid_unknown_ids_and_open_row_schema
    rows = [family_row('PAR-TST-001'), family_row('PAR-TST-002')]
    family = { 'member_ids' => rows.map { |row| row.fetch('requirement_id') }, 'declared_tier' => 'T2_DOMAIN_CRITICAL', 'exception_ids' => [] }
    assert_validation_error(/unknown capability ID/) do
      Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
    end

    rows = [family_row('PAR-REG-001'), family_row('PAR-REG-002')]
    family = { 'member_ids' => rows.map { |row| row.fetch('requirement_id') }, 'declared_tier' => 'T2_DOMAIN_CRITICAL', 'exception_ids' => [] }
    rows.first['owner_override'] = 'unregistered'
    assert_validation_error(/unknown fields owner_override/) do
      Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
    end

    rows = [family_row('PAR-REG-001'), family_row('PAR-REG-002')]
    rows.last['own_owner_record_id'] = 123
    family['exception_ids'] = ['PAR-REG-002']
    assert_validation_error(/exception requires its own owner record/) do
      Validator.validate_family_decision!(family, rows, @contract, root: ROOT)
    end
  end

  def test_linear_hash_bound_correction_history_passes
    root = correction_event('EVENT-1')
    correction = correction_event(
      'EVENT-2',
      predecessor_id: 'EVENT-1',
      predecessor_sha: Validator.canonical_sha256(root),
      supersedes_id: 'EVENT-1'
    )

    assert Validator.validate_correction_history!([root, correction])
  end

  def test_correction_history_rejects_broken_hash_missing_supersession_and_fork
    root = correction_event('EVENT-1')
    correction = correction_event('EVENT-2', predecessor_id: 'EVENT-1', predecessor_sha: '0' * 64, supersedes_id: 'EVENT-1')
    assert_validation_error(/predecessor hash mismatch/) { Validator.validate_correction_history!([root, correction]) }

    correct_sha = Validator.canonical_sha256(root)
    missing_supersession = correction_event('EVENT-2', predecessor_id: 'EVENT-1', predecessor_sha: correct_sha)
    assert_validation_error(/must bind the predecessor/) { Validator.validate_correction_history!([root, missing_supersession]) }

    first = correction_event('EVENT-2', predecessor_id: 'EVENT-1', predecessor_sha: correct_sha, supersedes_id: 'EVENT-1')
    second = correction_event('EVENT-3', predecessor_id: 'EVENT-1', predecessor_sha: correct_sha, supersedes_id: 'EVENT-1')
    assert_validation_error(/forks at predecessor/) { Validator.validate_correction_history!([root, first, second]) }
  end

  def test_secret_rejection_reports_only_location
    secret = 'do-not-echo-this-value'
    error = assert_raises(Validator::ValidationError) do
      Validator.assert_secret_free!({ 'nested' => { 'password' => secret } }, label: 'artifact')
    end

    assert_includes error.message, 'artifact.nested.password'
    refute_includes error.message, secret
    assert Validator.assert_secret_free!({ 'secret_like_content' => 'reject_without_echoing_value' })
  end

  def test_manifest_rejects_unknown_schema_baseline_drift_and_byte_drift
    unknown = deep_copy(@manifest)
    unknown['extra'] = true
    assert_validation_error(/unknown fields extra/) { Validator.verify_v1_manifest!(unknown, root: ROOT) }

    baseline_drift = deep_copy(@manifest)
    baseline_drift['planning_baseline']['git_commit'] = '0' * 40
    assert_validation_error(/accepted planning baseline changed/) { Validator.verify_v1_manifest!(baseline_drift, root: ROOT) }

    byte_drift = deep_copy(@manifest)
    byte_drift['files'][0]['sha256'] = '0' * 64
    assert_validation_error(/accepted ordered historical path\/hash inventory changed/) { Validator.verify_v1_manifest!(byte_drift, root: ROOT) }
  end

  def test_manifest_rejects_authority_provenance_boundary_and_inventory_rewrites
    [
      [->(manifest) { manifest['status'] = 'active' }, /historical identity or authority effect changed/],
      [->(manifest) { manifest['effect'] = 'authorizes_live_production' }, /historical identity or authority effect changed/],
      [->(manifest) { manifest['planning_baseline']['repository_origin'] = 'https://example.invalid/rewrite.git' }, /repository or accepted ADR inventory source changed/],
      [->(manifest) { manifest['boundary']['prohibited_live_integrations'] = [] }, /synthetic\/no-live boundary changed/],
      [->(manifest) { manifest['files'].first['path'] = '../outside' }, /unsafe repository-relative path/],
      [->(manifest) { manifest['files'][0], manifest['files'][1] = manifest['files'][1], manifest['files'][0] }, /inventory order must be contiguous/],
      [->(manifest) { manifest['files'][1]['path'] = manifest['files'][0]['path'] }, /duplicate inventory path/]
    ].each do |mutation, pattern|
      changed = deep_copy(@manifest)
      mutation.call(changed)
      assert_validation_error(pattern) { Validator.verify_v1_manifest!(changed, root: ROOT) }
    end
  end

  def test_path_guard_rejects_intermediate_symlink_and_git_error_is_stable
    Dir.mktmpdir('g0-v2-path') do |root|
      FileUtils.mkdir_p(File.join(root, 'inside'))
      File.symlink('/tmp', File.join(root, 'inside', 'linked'))
      assert_validation_error(/symlinked path component/) do
        Validator.send(:safe_regular_file_under_root!, Pathname.new(root), 'inside/linked/target', label: 'fixture')
      end
    end

    attacker_path = 'do-not-echo-this-path'
    error = assert_validation_error(/git source bytes unavailable/) do
      Validator.send(:git_show, ROOT, Validator::V1_PLANNING_BASELINE, attacker_path, label: 'fixture')
    end
    refute_includes error.message, attacker_path
  end

  def test_contract_validation_uses_component_guard_and_working_tree_drift_fails
    Dir.mktmpdir('g0-v2-contract-root') do |root|
      File.symlink(File.join(ROOT, 'docs'), File.join(root, 'docs'))
      assert_validation_error(/symlinked path component/) do
        Validator.validate_contract!(@contract, root: root)
      end
    end

    Dir.mktmpdir('g0-v2-working-source') do |root|
      FileUtils.mkdir_p(File.join(root, 'nested'))
      path = File.join(root, 'nested', 'source.txt')
      File.write(path, 'drifted bytes')
      assert_validation_error(/working-tree byte hash drift/) do
        Validator.send(:verify_working_tree_source!, Pathname.new(root), 'nested/source.txt', '0' * 64, label: 'fixture')
      end
    end
  end

  private

  def deep_copy(value)
    JSON.parse(JSON.generate(value))
  end

  def false_consequences
    @contract.dig('closed_values', 'consequence_flags').to_h { |flag| [flag, false] }
  end

  def authority_args(consequences, tier)
    {
      consequences: consequences,
      declared_tier: tier,
      product_authority: {
        'identity' => 'product-1',
        'capacity' => 'product_authority',
        'scopes' => ['programme']
      },
      domain_authority: {
        'identity' => 'domain-1',
        'capacity' => 'accountable_domain_authority',
        'scopes' => ['registration']
      },
      executor_identity: 'executor-1',
      author_identities: ['author-1'],
      approver_identities: %w[product-1 domain-1 approver-1],
      materially_affected_co_owner_identities: [],
      independent_reviewer: nil,
      contract: @contract
    }
  end

  def family_row(requirement_id)
    consequences = false_consequences.merge('clinical_or_rm_lifecycle' => true)
    {
      'requirement_id' => requirement_id,
      'owners' => %w[product-1 domain-1],
      'disposition' => 'reproduce',
      'target_pattern' => 'target-{requirement_id}',
      'evidence_boundary' => 'synthetic_only',
      'conditions' => ['bounded-teaching-use'],
      'acceptance_contract' => ['scenario-1'],
      'consequence_map' => consequences,
      'derived_tier' => 'T2_DOMAIN_CRITICAL',
      'downstream_effects' => ['audit'],
      'independent_review_requirement' => false
    }
  end

  def correction_event(id, predecessor_id: nil, predecessor_sha: nil, supersedes_id: nil)
    {
      'event_id' => id,
      'predecessor_event_id' => predecessor_id,
      'predecessor_event_sha256' => predecessor_sha,
      'supersedes_event_id' => supersedes_id,
      'decision' => 'approve'
    }
  end

  def assert_validation_error(pattern, &block)
    error = assert_raises(Validator::ValidationError, &block)
    assert_match pattern, error.message
    error
  end
end
