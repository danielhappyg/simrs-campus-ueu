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
    observation_path observation_sha256 canonical_preflight_path
    canonical_preflight_sha256 independent_review_path
    independent_review_sha256 evidence_expires_at bundle_id
    bundle_manifest_sha256 candidate_bundle_path retained decision_eligible
    ineligibility_reasons
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
    canonical_authority_artifacts_created pointer_created recovery_marker_created
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
    validator_contract selector_source independent_review
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
    'retained_candidate_bundle_manifest' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CANDIDATES/2026-08-29-v1.3-b74fd990-pending-1553d79a68431e2b7434428c/G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json',
    'gate_b_local_observation' => 'docs/operations/G0_GOVERNANCE_V2_GATE_B_LOCAL_OBSERVATION_2026-08-29.json',
    'gate_b_canonical_preflight' => 'docs/operations/G0_GOVERNANCE_V2_GATE_B_CANONICAL_PREFLIGHT_2026-08-29.json',
    'gate_b_independent_review' => 'docs/operations/G0_GOVERNANCE_V2_GATE_B_INDEPENDENT_REVIEW_2026-08-29.json',
    'consumer_selector_source' => 'scripts/select-g0-governance-consumer.rb',
    'governance_v2_core_source' => 'scripts/g0-proportional-governance-v2.rb'
  }.freeze
  EXPECTED_SOURCE_STATUSES = {
    'governance_v2_proposal' => 'adopted_as_written_for_gate_a_local_implementation',
    'governance_v2_architecture' => 'accepted_for_gate_a_local_implementation',
    'adoption_decision_draft' => 'historical_pending_no_effect',
    'independent_gate_a_review' => 'pass_gate_a_local_implementation_only',
    'gate_a_adoption_decision' => 'approved_local_implementation_only_no_activation',
    'governance_v2_contract' => 'gate_b_operation_decision_contract_1_3_external_trust_blocked_not_activated',
    'v1_historical_hash_manifest' => 'historical_never_activated_integrity_source',
    'implementation_blueprint' => 'gate_sequence_source',
    'retained_candidate_bundle_manifest' => 'retained_candidate_pending_no_authority',
    'gate_b_local_observation' => 'pass_observation_only_no_authority_expires_2026_08_29t08_58_48z',
    'gate_b_canonical_preflight' => 'pass_preflight_only_no_authority_expires_2026_08_29t08_58_48z',
    'gate_b_independent_review' => 'pass_review_evidence_only_no_authority_expires_2026_08_29t08_58_48z',
    'consumer_selector_source' => 'gate_b_hardened_local_canonical_support_not_invoked',
    'governance_v2_core_source' => 'gate_b_operation_decision_validation_1_3_external_trust_blocked'
  }.freeze
  EXPECTED_INELIGIBILITY_REASONS = [
    'retained candidate observation preflight and independent review evidence confer no owner authority activation effect or gate closure',
    'external product-owner and independent-review trust anchors are absent unapproved and unprovisioned',
    'attributable product-owner approval for this exact operation is absent',
    'canonical selector execution remains fail-closed while external attestation trust is unprovisioned',
    'time-bound observation preflight and review evidence must be refreshed after 2026-08-29T08:58:48Z'
  ].freeze
  EXPECTED_ATTRIBUTION_GAPS = [].freeze
  EXPECTED_READINESS_BLOCKERS = [
    'external product-owner and independent-review trust anchors are absent unapproved and unprovisioned so every canonical operation is fail-closed',
    'attributable actor institutional identity decision reference exact message timestamps and expiry are missing',
    'attributable product-owner approval for this exact retained-candidate operation has not been recorded',
    'canonical selector execution remains blocked until external attestation trust is separately approved and provisioned',
    'initial prior state must be rederived and bound immediately before any separately authorized operation',
    'time-bound observation preflight and independent review evidence must be refreshed after 2026-08-29T08:58:48Z'
  ].freeze
  EXPECTED_CONDITIONS = [
    'This draft is decision preparation only and must never be renamed copied or interpreted as an approved operation decision.',
    'Gate A adoption authorizes local governance-v2 implementation only and cannot supply Gate B activation authority.',
    'The retained hash-bound candidate is pending and cannot activate itself or confer owner authority.',
    'Local observation canonical preflight and independent review evidence are complete but non-authoritative and expire at 2026-08-29T08:58:48Z.',
    'Canonical selector execution is intentionally blocked before lock probe marker or write until external product-owner and independent-review trust is separately approved and provisioned.',
    'Activation cannot appoint owners decide capabilities authorize an application slice deploy migrate use real patient data enable a live integration close G0 or establish G3.',
    'Any eventual approval expires and authorizes at most one exact operation after action-time prior-state revalidation.'
  ].freeze
  EXPECTED_DRAFT_RULES = [
    'Do not mutate this draft into an approved operation decision.',
    'Do not pass this draft to the selector as an activation decision.',
    'Create a new immutable operation decision only after every approval-readiness blocker is closed.',
    'Bind the future decision to exact hardened schema selector candidate preflight prior-state attribution approval condition and expiry evidence.',
    'Re-observe all canonical authority paths immediately before the separately authorized operation and fail closed on drift.',
    'Do not commit credentials tokens private keys connection strings or external signing material.'
  ].freeze
  POST_PLANNING_HEAD_HARDENING_ROLES = %w[
    governance_v2_contract retained_candidate_bundle_manifest gate_b_local_observation
    gate_b_canonical_preflight gate_b_independent_review consumer_selector_source
    governance_v2_core_source
  ].freeze
  POST_PLANNING_HEAD_NEW_ROLES = %w[
    retained_candidate_bundle_manifest gate_b_local_observation
    gate_b_canonical_preflight gate_b_independent_review
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
    assert_equal 'blocked_external_attestation_unprovisioned_not_executed', gate.fetch('activation_status')
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

  def test_planning_head_is_reachable_and_gate_b_hardening_bindings_are_explicitly_newer
    _output, status = Open3.capture2e('git', 'cat-file', '-e', "#{PLANNING_HEAD}^{commit}", chdir: ROOT)
    assert status.success?, 'planning head must resolve to a commit'
    _output, status = Open3.capture2e('git', 'merge-base', '--is-ancestor', PLANNING_HEAD, 'HEAD', chdir: ROOT)
    assert status.success?, 'planning head must be an ancestor of HEAD'

    @draft.fetch('source_bindings').each do |binding|
      path = binding.fetch('path')
      committed_bytes, source_status = Open3.capture2e('git', 'show', "#{PLANNING_HEAD}:#{path}", chdir: ROOT)
      current_bytes = File.binread(File.join(ROOT, path))
      if POST_PLANNING_HEAD_NEW_ROLES.include?(binding.fetch('role'))
        refute source_status.success?, "#{path} must be new after the Gate-A planning head"
      elsif POST_PLANNING_HEAD_HARDENING_ROLES.include?(binding.fetch('role'))
        assert source_status.success?, "planning head is missing prior bytes for #{path}"
        refute_equal current_bytes, committed_bytes.b, "Gate-B hardening source must be newer than the Gate-A planning head"
      else
        assert source_status.success?, "planning head is missing #{path}"
        assert_equal current_bytes, committed_bytes.b, "planning head does not own #{path}"
      end
    end
  end

  def test_requested_operation_actor_and_times_are_explicitly_pending
    request = @draft.fetch('requested_operation')
    assert_closed request, REQUESTED_OPERATION_KEYS
    assert_equal %w[activate local_canonical_checkout blocked_external_attestation_unprovisioned], request.values_at(
      'operation', 'target_environment', 'decision_status'
    )
    assert_equal 'implemented_but_canonical_blocked_until_external_attestation_trust_is_separately_approved_and_provisioned', request.fetch('selector_environment_support')
    %w[decided_at expires_at held_selection_reference recover_outcome].each do |key|
      assert_nil request.fetch(key), key
    end
    assert_equal({
      'path' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CANDIDATES/2026-08-29-v1.3-b74fd990-pending-1553d79a68431e2b7434428c',
      'sha256' => '53f727db483580fc7337e8b09d990bd394d390602304ec511b4b650fbd2cfbbd'
    }, request.fetch('candidate_bundle_reference'))
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
    facts.first(2).each { |entry| assert_equal %w[absent none], entry.values_at('observed_state', 'authority_effect') }
    assert_equal %w[
      documentation_only_no_selection_artifacts
      documentation_only_no_operation_decision_artifacts
      documentation_only_no_journal_artifacts
    ], facts[2, 3].map { |entry| entry.fetch('observed_state') }
    facts[2, 3].each { |entry| assert_equal 'none', entry.fetch('authority_effect') }
    assert_equal 'absent_or_safe_inert_zero_byte_0600_single_link_current_uid', facts.last.fetch('observed_state')
    assert_equal 'none_coordination_only', facts.last.fetch('authority_effect')

    before = authority_inventory
    AUTHORITY_PATHS.first(2).each { |path| assert_equal 'absent', path_state(File.join(ROOT, path)), path }
    AUTHORITY_PATHS[2, 3].each { |path| assert_readme_only_directory(File.join(ROOT, path)) }
    assert_safe_inert_lock_or_absent(File.join(ROOT, STABLE_LOCK_PATH))
    assert_equal before, authority_inventory
  end

  def test_observed_candidate_and_time_bound_evidence_are_exact_retained_and_not_decision_eligible
    candidate = @draft.fetch('observed_candidate')
    assert_closed candidate, OBSERVED_CANDIDATE_KEYS
    assert_equal 'docs/operations/G0_GOVERNANCE_V2_GATE_B_LOCAL_OBSERVATION_2026-08-29.json', candidate.fetch('observation_path')
    assert_equal '7830d5d8386118a450efc362a9eab550d0b8cb8e4d3189fff0ef442fcdd0898e', candidate.fetch('observation_sha256')
    assert_equal 'docs/operations/G0_GOVERNANCE_V2_GATE_B_CANONICAL_PREFLIGHT_2026-08-29.json', candidate.fetch('canonical_preflight_path')
    assert_equal '063ae4dc2510e740ec577ad419c1692ac348534c962e1d185bb1f2878223d25f', candidate.fetch('canonical_preflight_sha256')
    assert_equal 'docs/operations/G0_GOVERNANCE_V2_GATE_B_INDEPENDENT_REVIEW_2026-08-29.json', candidate.fetch('independent_review_path')
    assert_equal '44af902f5e8b1e6e09aa6d16f55e667b3aecce7ef8ecf1f3952cb2163ce604c9', candidate.fetch('independent_review_sha256')
    assert_equal '2026-08-29T08:58:48Z', candidate.fetch('evidence_expires_at')
    assert_equal 'G0-GOVERNANCE-V2-PENDING-1553d79a68431e2b7434428c', candidate.fetch('bundle_id')
    assert_equal '53f727db483580fc7337e8b09d990bd394d390602304ec511b4b650fbd2cfbbd', candidate.fetch('bundle_manifest_sha256')
    assert_equal 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CANDIDATES/2026-08-29-v1.3-b74fd990-pending-1553d79a68431e2b7434428c', candidate.fetch('candidate_bundle_path')
    assert candidate.fetch('retained')
    refute candidate.fetch('decision_eligible')
    assert_equal EXPECTED_INELIGIBILITY_REASONS, candidate.fetch('ineligibility_reasons')

    observation = Core.parse_json_file(File.join(ROOT, candidate.fetch('observation_path')))
    preflight = Core.parse_json_file(File.join(ROOT, candidate.fetch('canonical_preflight_path')))
    review = Core.parse_json_file(File.join(ROOT, candidate.fetch('independent_review_path')))
    candidate_reference = {
      'path' => candidate.fetch('candidate_bundle_path'),
      'sha256' => candidate.fetch('bundle_manifest_sha256')
    }
    assert_equal candidate_reference, observation.fetch('candidate_bundle')
    assert_equal candidate_reference, preflight.fetch('candidate_bundle')
    assert_equal candidate_reference, review.fetch('candidate_bundle')
    assert_equal candidate.fetch('evidence_expires_at'), observation.fetch('expires_at')
    assert_equal candidate.fetch('evidence_expires_at'), preflight.fetch('expires_at')
    assert_equal candidate.fetch('evidence_expires_at'), review.fetch('expires_at')
    assert_equal 'none', observation.fetch('authority_effect')
    assert_equal 'none', preflight.fetch('authority_effect')
    assert_equal 'none', review.fetch('authority_effect')
    assert_equal({
      'local_observation' => {
        'path' => candidate.fetch('observation_path'),
        'sha256' => candidate.fetch('observation_sha256')
      },
      'canonical_preflight' => {
        'path' => candidate.fetch('canonical_preflight_path'),
        'sha256' => candidate.fetch('canonical_preflight_sha256')
      }
    }, review.fetch('reviewed_evidence'))
    manifest_path = File.join(ROOT, candidate.fetch('candidate_bundle_path'), 'G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json')
    assert_equal candidate.fetch('bundle_manifest_sha256'), Digest::SHA256.file(manifest_path).hexdigest
    manifest = Core.parse_json_file(manifest_path)
    assert_equal candidate.fetch('bundle_id'), manifest.fetch('bundle_id')
    assert_equal 'candidate_pending_not_active', manifest.fetch('status')
  end

  def test_eventual_schema_is_implemented_but_still_has_no_activation_authority
    schema = @draft.fetch('required_eventual_operation_decision_schema')
    assert_closed schema, EVENTUAL_SCHEMA_KEYS
    assert_equal 'implemented_contract_1_3_selector_compatible_canonical_trust_blocked', schema.fetch('schema_status')
    assert_equal Selector::OPERATION_DECISION_KEYS, schema.fetch('current_selector_exact_top_level_keys')
    assert_equal Selector::OPERATION_DECISION_KEYS, schema.fetch('eventual_required_top_level_keys')

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

    assert_equal %w[isolated_test_fixture local_canonical_checkout], Selector::ENVIRONMENTS
    assert_equal Selector::OPERATION_DECISION_KEYS,
                 @draft.dig('required_eventual_operation_decision_schema', 'eventual_required_top_level_keys')
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
    assert_equal 'blocked_external_attestation_unprovisioned_not_approval_ready', readiness.fetch('status')
    assert_equal EXPECTED_READINESS_BLOCKERS, readiness.fetch('blockers')
    refute readiness.fetch('selector_hardening_required')
    refute readiness.fetch('fresh_candidate_required')
    refute readiness.fetch('fresh_independent_review_required')

    approvals = @draft.fetch('required_approvals')
    assert_equal 3, approvals.length
    approvals.each { |approval| assert_closed approval, APPROVAL_KEYS }
    owner_approval, review_evidence, preflight_evidence = approvals
    assert_equal 'pending', owner_approval.fetch('status')
    assert_nil owner_approval.fetch('evidence_reference')
    [review_evidence, preflight_evidence].each do |approval|
      assert_equal 'evidence_satisfied_no_authority', approval.fetch('status')
      assert_closed approval.fetch('evidence_reference'), Selector::REFERENCE_KEYS
    end
    assert_equal({
      'path' => 'docs/operations/G0_GOVERNANCE_V2_GATE_B_INDEPENDENT_REVIEW_2026-08-29.json',
      'sha256' => '44af902f5e8b1e6e09aa6d16f55e667b3aecce7ef8ecf1f3952cb2163ce604c9'
    }, review_evidence.fetch('evidence_reference'))
    assert_equal({
      'path' => 'docs/operations/G0_GOVERNANCE_V2_GATE_B_CANONICAL_PREFLIGHT_2026-08-29.json',
      'sha256' => '063ae4dc2510e740ec577ad419c1692ac348534c962e1d185bb1f2878223d25f'
    }, preflight_evidence.fetch('evidence_reference'))
    approvals.each { |approval| refute_empty approval.fetch('conditions') }
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

  def assert_readme_only_directory(path)
    stat = File.lstat(path)
    assert stat.directory?
    refute stat.symlink?
    assert_equal ['README.md'], Dir.children(path).sort
    readme = File.join(path, 'README.md')
    readme_stat = File.lstat(readme)
    assert readme_stat.file?
    refute readme_stat.symlink?
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
