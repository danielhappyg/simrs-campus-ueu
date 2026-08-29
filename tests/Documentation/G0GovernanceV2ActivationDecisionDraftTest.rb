# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'
require 'open3'

require_relative '../../scripts/g0-proportional-governance-v2'
require_relative '../../scripts/select-g0-governance-consumer'

class G0GovernanceV2ActivationDecisionDraftTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  DRAFT_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISION_DRAFT_2026-08-29.json'
  )
  Core = G0ProportionalGovernanceV2
  Selector = G0GovernanceConsumerSelector
  PLANNING_HEAD = 'ed0ebfb54c8350cb5a750796bcfa13f5a5715a6b'

  class BindingError < StandardError; end

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id status effect data_boundary
    planning_head gate source_bindings requested_operation
    required_actor_attribution derived_prior_state observed_candidate
    required_eventual_operation_decision_schema approval_readiness
    required_approvals conditions authorization canonical_mutation_boundary
    draft_rules secret_handling
  ].freeze
  GATE_KEYS = %w[
    gate_id preparation_authorized_by decision_authority_status activation_status
  ].freeze
  SOURCE_BINDING_KEYS = %w[role path sha256 recorded_status].freeze
  REQUESTED_OPERATION_KEYS = %w[
    operation target_environment decision_status selector_environment_support
    decided_at expires_at candidate_bundle_reference held_selection_reference
    recover_outcome conditions
  ].freeze
  ATTRIBUTION_KEYS = %w[
    institutional_id display_name authority_capacity decision_reference
    decision_message decision_message_encoding decision_message_sha256
    source_message_at recorded_at recorded_at_basis attribution_status
  ].freeze
  DERIVED_PRIOR_KEYS = %w[
    derivation_status prior_state facts live_resolution
    operation_time_revalidation_required
  ].freeze
  PRIOR_STATE_KEYS = Selector::PRIOR_STATE_KEYS.freeze
  PRIOR_FACT_KEYS = %w[path observed_state authority_effect].freeze
  OBSERVED_CANDIDATE_KEYS = %w[
    observation_path observation_sha256 bundle_id bundle_manifest_sha256
    candidate_bundle_path retained decision_eligible ineligibility_reasons
  ].freeze
  EVENTUAL_SCHEMA_KEYS = %w[
    schema_status current_selector_exact_top_level_keys
    eventual_required_top_level_keys nested_exact_keys approved_semantics
    current_selector_attribution_evidence_gaps
  ].freeze
  NESTED_SCHEMA_KEYS = %w[
    actor prior_state reference decision_attribution technical_evidence
  ].freeze
  APPROVED_SEMANTIC_KEYS = %w[
    artifact_type status effect data_boundary operation environment
    one_operation_only action_time_revalidation_required
  ].freeze
  APPROVAL_READINESS_KEYS = %w[
    status blockers selector_hardening_required fresh_candidate_required
    fresh_independent_review_required
  ].freeze
  APPROVAL_KEYS = %w[
    requirement_id requirement_kind required_capacity status
    evidence_reference conditions
  ].freeze
  AUTHORIZATION_KEYS = %w[
    gate_b_operation_decision_recorded consumer_activation
    canonical_selector_mutation candidate_retention capability_disposition
    slice_implementation deployment hosted_migration real_patient_data
    live_integration domain_acceptance g0_closure g3_acceptance
  ].freeze
  MUTATION_BOUNDARY_KEYS = %w[
    canonical_authority_paths_created pointer_created recovery_marker_created
    selection_created activation_decision_created journal_created
    stable_lock_authority_effect selector_invoked
  ].freeze
  SECRET_KEYS = %w[
    credentials_permitted tokens_permitted private_keys_permitted
    connection_strings_permitted
  ].freeze
  EVENTUAL_ATTRIBUTION_KEYS = %w[
    decision_reference decision_message decision_message_encoding
    decision_message_sha256 source_message_at recorded_at recorded_at_basis
  ].freeze
  EVENTUAL_TECHNICAL_EVIDENCE_KEYS = %w[
    gate_a_adoption local_observation candidate_bundle canonical_preflight
    selector_contract
  ].freeze
  EXPECTED_SOURCE_PATHS = {
    'governance_v2_proposal' => 'docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md',
    'governance_v2_architecture' => 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md',
    'adoption_decision_draft' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION_DRAFT_2026-08-28.json',
    'independent_gate_a_review' => 'docs/operations/G0_GOVERNANCE_V2_INDEPENDENT_TECHNICAL_SECURITY_REVIEW_2026-08-29.md',
    'gate_a_adoption_decision' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json',
    'governance_v2_contract' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json',
    'v1_historical_hash_manifest' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json',
    'implementation_blueprint' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_IMPLEMENTATION_BLUEPRINT_2026-08-28.md',
    'local_candidate_observation' => 'docs/operations/G0_GOVERNANCE_V2_LOCAL_OBSERVATION_2026-08-29.json',
    'consumer_selector_source' => 'scripts/select-g0-governance-consumer.rb',
    'governance_v2_core_source' => 'scripts/g0-proportional-governance-v2.rb'
  }.freeze
  EXPECTED_SOURCE_STATUSES = {
    'governance_v2_proposal' => 'adopted_as_written_for_gate_a_local_implementation',
    'governance_v2_architecture' => 'accepted_for_gate_a_local_implementation',
    'adoption_decision_draft' => 'historical_pending_no_effect',
    'independent_gate_a_review' => 'pass_gate_a_local_implementation_only',
    'gate_a_adoption_decision' => 'approved_local_implementation_only_no_activation',
    'governance_v2_contract' => 'implemented_gate_a_contract',
    'v1_historical_hash_manifest' => 'historical_never_activated_integrity_source',
    'implementation_blueprint' => 'gate_sequence_source',
    'local_candidate_observation' => 'complete_candidate_only_observation_no_effect',
    'consumer_selector_source' => 'fixture_only_mutation_canonical_checkout_prohibited',
    'governance_v2_core_source' => 'implemented_validation_core'
  }.freeze
  EXPECTED_INELIGIBILITY_REASONS = [
    'observed candidate was temporary and was cleaned after read-only validation',
    'no immutable retained candidate path and path hash are available for an operation decision',
    'candidate observation confers no owner authority activation effect or gate closure'
  ].freeze
  EXPECTED_ATTRIBUTION_GAPS = [
    'decision_reference',
    'exact_decision_message',
    'decision_message_encoding',
    'decision_message_sha256',
    'source_message_at_and_recorded_time_basis',
    'hash_bound_technical_evidence_and_canonical_preflight_references'
  ].freeze
  EXPECTED_READINESS_BLOCKERS = [
    'current selector prohibits canonical-checkout mutation and accepts only isolated_test_fixture operation decisions',
    'current selector operation-decision schema lacks attributable decision-message evidence and hash-bound technical preflight references',
    'observed candidate was ephemeral cleaned and is not decision-eligible',
    'attributable actor institutional identity decision reference exact message timestamps and expiry are missing',
    'initial prior state must be rederived and bound immediately before any separately authorized operation'
  ].freeze
  EXPECTED_CONDITIONS = [
    'This draft is decision preparation only and must never be renamed copied or interpreted as an approved operation decision.',
    'Gate A adoption authorizes local governance-v2 implementation only and cannot supply Gate B activation authority.',
    'The temporary observed candidate is not activatable; a fresh retained hash-bound candidate is required.',
    'Canonical checkout support attribution evidence and technical-evidence binding must be implemented tested and independently reviewed before a decision request.',
    'Activation cannot appoint owners decide capabilities authorize an application slice deploy migrate use real patient data enable a live integration close G0 or establish G3.',
    'Any eventual approval expires and authorizes at most one exact operation after action-time prior-state revalidation.'
  ].freeze
  EXPECTED_DRAFT_RULES = [
    'Do not mutate this draft into an approved operation decision.',
    'Do not pass this draft to the selector as an activation decision.',
    'Create a new immutable operation decision only after every approval-readiness blocker is closed.',
    'Bind the future decision to exact hardened schema selector candidate preflight prior-state attribution approval condition and expiry evidence.',
    'Re-observe all canonical authority paths immediately before the separately authorized operation and fail closed on drift.',
    'Do not commit credentials tokens private keys connection strings or temporary candidate contents.'
  ].freeze
  AUTHORITY_PATHS = %w[
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTIONS
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_JOURNAL
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTION.lock
  ].freeze
  STABLE_LOCK_PATH = AUTHORITY_PATHS.last

  def setup
    @draft = Core.parse_json_file(DRAFT_PATH, label: '$.gate_b_draft')
  end

  def test_draft_has_closed_pending_no_effect_identity
    assert_closed @draft, TOP_LEVEL_KEYS
    assert_equal 'g0_governance_v2_activation_decision_draft', @draft.fetch('artifact_type')
    assert_equal 1, @draft.fetch('schema_version')
    assert_equal 'G0-GOVERNANCE-V2-ACTIVATION-DECISION-DRAFT-2026-08-29', @draft.fetch('artifact_id')
    assert_equal 'pending_gate_b_decision_preparation_not_approval_ready', @draft.fetch('status')
    assert_equal 'none_draft_only_no_consumer_activation_or_canonical_mutation', @draft.fetch('effect')
    assert_equal 'synthetic_only', @draft.fetch('data_boundary')
    assert_equal PLANNING_HEAD, @draft.fetch('planning_head')

    gate = @draft.fetch('gate')
    assert_closed gate, GATE_KEYS
    assert_equal 'GATE_B_CONSUMER_ACTIVATION', gate.fetch('gate_id')
    assert_equal 'gate_a_local_governance_v2_implementation_only', gate.fetch('preparation_authorized_by')
    assert_equal 'pending_attributable_product_owner_decision', gate.fetch('decision_authority_status')
    assert_equal 'not_authorized_not_executed', gate.fetch('activation_status')
  end

  def test_all_gate_a_sources_are_exact_regular_hash_bound_paths
    bindings = @draft.fetch('source_bindings')
    assert_equal EXPECTED_SOURCE_PATHS.keys, bindings.map { |entry| entry.fetch('role') }
    bindings.each { |entry| assert_source_binding!(entry) }

    changed_path = deep_copy(bindings.first)
    changed_path['path'] = 'docs/new-simrs-rebuild/phase-0/elsewhere.json'
    assert_raises(BindingError) { assert_source_binding!(changed_path) }
    changed_hash = deep_copy(bindings.first)
    changed_hash['sha256'] = '0' * 64
    assert_raises(BindingError) { assert_source_binding!(changed_hash) }
  end

  def test_planning_head_is_reachable_and_owns_every_bound_source
    _output, status = Open3.capture2e('git', 'cat-file', '-e', "#{PLANNING_HEAD}^{commit}", chdir: ROOT)
    assert status.success?, 'planning head must resolve to a commit'
    _output, status = Open3.capture2e('git', 'merge-base', '--is-ancestor', PLANNING_HEAD, 'HEAD', chdir: ROOT)
    assert status.success?, 'planning head must be an ancestor of HEAD'

    @draft.fetch('source_bindings').each do |binding|
      path = binding.fetch('path')
      committed_bytes, source_status = Open3.capture2('git', 'show', "#{PLANNING_HEAD}:#{path}", chdir: ROOT)
      assert source_status.success?, "planning head is missing #{path}"
      assert_equal File.binread(File.join(ROOT, path)), committed_bytes.b, "planning head does not own #{path}"
    end
  end

  def test_requested_operation_actor_and_times_are_explicitly_pending
    request = @draft.fetch('requested_operation')
    assert_closed request, REQUESTED_OPERATION_KEYS
    assert_equal %w[activate local_canonical_checkout pending], request.values_at(
      'operation', 'target_environment', 'decision_status'
    )
    assert_equal 'not_implemented_current_selector_accepts_isolated_test_fixture_only', request.fetch('selector_environment_support')
    %w[decided_at expires_at candidate_bundle_reference held_selection_reference recover_outcome].each do |key|
      assert_nil request.fetch(key), key
    end
    assert_empty request.fetch('conditions')

    attribution = @draft.fetch('required_actor_attribution')
    assert_closed attribution, ATTRIBUTION_KEYS
    assert_equal 'product_owner', attribution.fetch('authority_capacity')
    assert_equal 'missing_not_self_declarable_from_commit_or_draft_authorship', attribution.fetch('attribution_status')
    ATTRIBUTION_KEYS.reject { |key| %w[authority_capacity attribution_status].include?(key) }.each do |key|
      assert_nil attribution.fetch(key), key
    end
  end

  def test_prior_state_is_preparation_only_and_current_authority_paths_are_not_created
    prior = @draft.fetch('derived_prior_state')
    assert_closed prior, DERIVED_PRIOR_KEYS
    assert_equal 'preparation_only_must_be_rederived_at_operation_time', prior.fetch('derivation_status')
    assert_equal 'pointer_missing_g0_g3_open', prior.fetch('live_resolution')
    assert prior.fetch('operation_time_revalidation_required')
    assert_closed prior.fetch('prior_state'), PRIOR_STATE_KEYS
    assert_equal({
      'prior_state_reason' => 'initial_state',
      'expected_prior_pointer_sha256' => nil,
      'observed_unreadable_pointer_sha256' => nil
    }, prior.fetch('prior_state'))

    facts = prior.fetch('facts')
    assert_equal AUTHORITY_PATHS, facts.map { |entry| entry.fetch('path') }
    facts.each { |entry| assert_closed entry, PRIOR_FACT_KEYS }
    facts.first(5).each { |entry| assert_equal %w[absent none], entry.values_at('observed_state', 'authority_effect') }
    assert_equal 'absent_or_safe_inert_zero_byte_0600_single_link_current_uid', facts.last.fetch('observed_state')
    assert_equal 'none_coordination_only', facts.last.fetch('authority_effect')

    before = authority_inventory
    AUTHORITY_PATHS.first(5).each { |path| assert_equal 'absent', path_state(File.join(ROOT, path)), path }
    assert_safe_inert_lock_or_absent(File.join(ROOT, STABLE_LOCK_PATH))
    assert_equal before, authority_inventory
  end

  def test_observed_candidate_is_exact_ephemeral_and_not_decision_eligible
    candidate = @draft.fetch('observed_candidate')
    assert_closed candidate, OBSERVED_CANDIDATE_KEYS
    assert_equal 'docs/operations/G0_GOVERNANCE_V2_LOCAL_OBSERVATION_2026-08-29.json', candidate.fetch('observation_path')
    assert_equal 'c581d09348119fb82ab812d5e2e3e45e56f6a826be5c63e60c0f7b0eb7f789ca', candidate.fetch('observation_sha256')
    assert_equal 'G0-GOVERNANCE-V2-PENDING-c20e607ee1a35561d7330c1b', candidate.fetch('bundle_id')
    assert_equal 'a48e96b39ed519906bf32a14f04fbfcf98ca83b7a791a051db7f98074a17c2fa', candidate.fetch('bundle_manifest_sha256')
    assert_nil candidate.fetch('candidate_bundle_path')
    refute candidate.fetch('retained')
    refute candidate.fetch('decision_eligible')
    assert_equal EXPECTED_INELIGIBILITY_REASONS, candidate.fetch('ineligibility_reasons')

    observation = Core.parse_json_file(File.join(ROOT, candidate.fetch('observation_path')))
    assert_equal candidate.fetch('bundle_id'), observation.dig('candidate_bundle', 'bundle_id')
    assert_equal candidate.fetch('bundle_manifest_sha256'), observation.dig('candidate_bundle', 'artifacts', 5, 'sha256')
    refute observation.dig('candidate_bundle', 'retained')
    assert observation.dig('candidate_bundle', 'temporary_evidence_cleaned')
  end

  def test_eventual_schema_is_exact_and_explicitly_blocked_on_attribution_and_canonical_support
    schema = @draft.fetch('required_eventual_operation_decision_schema')
    assert_closed schema, EVENTUAL_SCHEMA_KEYS
    assert_equal 'hardening_required_not_implemented_not_selector_compatible', schema.fetch('schema_status')
    assert_equal Selector::OPERATION_DECISION_KEYS, schema.fetch('current_selector_exact_top_level_keys')
    assert_equal Selector::OPERATION_DECISION_KEYS + %w[decision_attribution technical_evidence], schema.fetch('eventual_required_top_level_keys')

    nested = schema.fetch('nested_exact_keys')
    assert_closed nested, NESTED_SCHEMA_KEYS
    assert_equal Selector::ACTOR_KEYS, nested.fetch('actor')
    assert_equal Selector::PRIOR_STATE_KEYS, nested.fetch('prior_state')
    assert_equal Selector::REFERENCE_KEYS, nested.fetch('reference')
    assert_equal EVENTUAL_ATTRIBUTION_KEYS, nested.fetch('decision_attribution')
    assert_equal EVENTUAL_TECHNICAL_EVIDENCE_KEYS, nested.fetch('technical_evidence')

    semantics = schema.fetch('approved_semantics')
    assert_closed semantics, APPROVED_SEMANTIC_KEYS
    assert_equal 'g0_governance_v2_consumer_operation_decision', semantics.fetch('artifact_type')
    assert_equal %w[approved authorizes_one_consumer_selection_operation synthetic_only activate local_canonical_checkout], semantics.values_at(
      'status', 'effect', 'data_boundary', 'operation', 'environment'
    )
    assert semantics.fetch('one_operation_only')
    assert semantics.fetch('action_time_revalidation_required')
    assert_equal EXPECTED_ATTRIBUTION_GAPS, schema.fetch('current_selector_attribution_evidence_gaps')

    selector_source = File.binread(File.join(ROOT, 'scripts/select-g0-governance-consumer.rb'))
    assert_includes selector_source, "decision.fetch('environment') == 'isolated_test_fixture'"
    assert_includes selector_source, "raise UsageError, 'canonical_checkout_mutation_prohibited'"
  end

  def test_draft_is_not_selector_compatible_and_creates_no_authority
    before = authority_inventory
    error = assert_raises(Selector::ValidationFailure) do
      Selector::Validators.operation_decision_record!(
        @draft,
        selection: {},
        adoption_reference: { 'path' => 'unused', 'sha256' => '0' * 64 }
      )
    end
    assert_equal 'operation_decision_invalid', error.reason_code
    assert_equal before, authority_inventory
  end

  def test_readiness_approvals_conditions_and_authorizations_are_closed_and_pending
    readiness = @draft.fetch('approval_readiness')
    assert_closed readiness, APPROVAL_READINESS_KEYS
    assert_equal 'not_approval_ready', readiness.fetch('status')
    assert_equal EXPECTED_READINESS_BLOCKERS, readiness.fetch('blockers')
    assert readiness.fetch('selector_hardening_required')
    assert readiness.fetch('fresh_candidate_required')
    assert readiness.fetch('fresh_independent_review_required')

    approvals = @draft.fetch('required_approvals')
    assert_equal 3, approvals.length
    approvals.each do |approval|
      assert_closed approval, APPROVAL_KEYS
      assert_equal 'pending', approval.fetch('status')
      assert_nil approval.fetch('evidence_reference')
      refute_empty approval.fetch('conditions')
    end
    assert_equal %w[authority_decision independent_review engineering_evidence], approvals.map { |row| row.fetch('requirement_kind') }
    assert_equal [
      ['GATE-B-ATTRIBUTABLE-PRODUCT-OWNER-DECISION', 'product_owner'],
      ['GATE-B-INDEPENDENT-TECHNICAL-SECURITY-REVIEW', 'independent_technical_security_reviewer'],
      ['GATE-B-CANONICAL-PREFLIGHT', 'named_execution_operator']
    ], approvals.map { |row| row.values_at('requirement_id', 'required_capacity') }
    assert_equal EXPECTED_CONDITIONS, @draft.fetch('conditions')

    authorization = @draft.fetch('authorization')
    assert_closed authorization, AUTHORIZATION_KEYS
    assert authorization.values.all? { |value| value == false }
    mutation = @draft.fetch('canonical_mutation_boundary')
    assert_closed mutation, MUTATION_BOUNDARY_KEYS
    mutation.reject { |key, _value| key == 'stable_lock_authority_effect' }.each do |key, value|
      refute value, key
    end
    assert_equal 'none_coordination_only', mutation.fetch('stable_lock_authority_effect')
    assert_equal EXPECTED_DRAFT_RULES, @draft.fetch('draft_rules')
  end

  def test_closed_secret_and_duplicate_key_contracts_fail_on_adversarial_drift
    secret_handling = @draft.fetch('secret_handling')
    assert_closed secret_handling, SECRET_KEYS
    assert secret_handling.values.all? { |value| value == false }
    scrubbed = deep_copy(@draft)
    scrubbed.delete('secret_handling')
    assert_empty Core.secret_locations(scrubbed)

    secret_copy = deep_copy(scrubbed)
    secret_copy.fetch('conditions') << 'password=should-never-be-retained'
    refute_empty Core.secret_locations(secret_copy)
    assert_raises(Core::ParseError) do
      Core.parse_json('{"status":"pending","status":"approved"}', label: '$.duplicate_fixture')
    end
  end

  private

  def assert_closed(value, keys)
    assert_kind_of Hash, value
    assert_equal keys.sort, value.keys.sort
  end

  def assert_source_binding!(entry)
    assert_closed entry, SOURCE_BINDING_KEYS
    expected_path = EXPECTED_SOURCE_PATHS.fetch(entry.fetch('role')) { raise BindingError, 'unknown role' }
    raise BindingError, 'path drift' unless entry.fetch('path') == expected_path
    raise BindingError, 'status drift' unless entry.fetch('recorded_status') == EXPECTED_SOURCE_STATUSES.fetch(entry.fetch('role'))
    raise BindingError, 'unsafe path' unless Core::SAFE_RELATIVE_PATH_PATTERN.match?(entry.fetch('path'))
    raise BindingError, 'invalid hash' unless Core::SHA256_PATTERN.match?(entry.fetch('sha256'))
    path = File.join(ROOT, entry.fetch('path'))
    stat = File.lstat(path)
    raise BindingError, 'source is not a regular file' unless stat.file? && !stat.symlink?
    raise BindingError, 'hash drift' unless Digest::SHA256.file(path).hexdigest == entry.fetch('sha256')

    true
  rescue KeyError, SystemCallError
    raise BindingError, 'source binding invalid'
  end

  def authority_inventory
    AUTHORITY_PATHS.to_h do |relative|
      path = File.join(ROOT, relative)
      [relative, path_state(path) == 'absent' ? nil : Digest::SHA256.hexdigest(path_inventory(path))]
    end
  end

  def path_inventory(path)
    stat = File.lstat(path)
    return "symlink:#{File.readlink(path)}" if stat.symlink?
    return File.binread(path) unless stat.directory?

    Dir.children(path).sort.map do |name|
      child = File.join(path, name)
      child_stat = File.lstat(child)
      value = if child_stat.symlink?
                "symlink:#{File.readlink(child)}"
              elsif child_stat.directory?
                path_inventory(child)
              elsif child_stat.file?
                Digest::SHA256.file(child).hexdigest
              else
                "other:#{child_stat.ftype}"
              end
      [name, value]
    end.inspect
  end

  def path_state(path)
    stat = File.lstat(path)
    return 'symlink' if stat.symlink?
    return 'directory' if stat.directory?
    return 'file' if stat.file?

    'other'
  rescue Errno::ENOENT
    'absent'
  end

  def assert_safe_inert_lock_or_absent(path)
    return assert_equal('absent', path_state(path)) if path_state(path) == 'absent'

    stat = File.lstat(path)
    assert stat.file?
    refute stat.symlink?
    assert_equal 0o600, stat.mode & 0o777
    assert_equal 1, stat.nlink
    assert_equal Process.uid, stat.uid
    assert_equal 0, stat.size
    assert_equal Digest::SHA256.hexdigest(''), Digest::SHA256.file(path).hexdigest
  end

  def deep_copy(value)
    JSON.parse(JSON.generate(value))
  end
end
