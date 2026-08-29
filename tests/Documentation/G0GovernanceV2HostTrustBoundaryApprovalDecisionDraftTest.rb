# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'
require 'time'

class G0GovernanceV2HostTrustBoundaryApprovalDecisionDraftTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  DRAFT_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_HOST_TRUST_BOUNDARY_APPROVAL_DECISION_DRAFT_2026-08-29.json')

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id status effect data_boundary
    source_bindings required_decision requested_reply successor_approval_contract
    eventual_exact_approval_scope current_boundaries authorization
    approval_record_rules secret_handling
  ].freeze
  SOURCE_KEYS = %w[role path sha256 recorded_status].freeze
  DECISION_KEYS = %w[
    authority_capacity decision_status selected_option actor_attribution
    decision_reference decision_message decision_message_encoding
    decision_message_sha256 request_presented_at source_message_at recorded_at
    effective_at conditions
  ].freeze
  SOURCE_PATHS = {
    'host_trust_boundary_adr_019' => 'docs/adr/ADR-019-HOST-OWNED-G0-GOVERNANCE-TRUST-BOUNDARY.md',
    'predecessor_adr_018' => 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md',
    'gate_a_adoption_decision' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json',
    'external_trust_provisioning_proposal' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md'
  }.freeze
  SOURCE_HASHES = {
    'host_trust_boundary_adr_019' => '288f8c25fe506643750f2202831b7c1e5345a805702d85d4b6393b76794cd272',
    'predecessor_adr_018' => 'cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962',
    'gate_a_adoption_decision' => '139bf2f952ab5c47cd34a43f80d93224e48386c57f180a799d8f206a9049c0f3',
    'external_trust_provisioning_proposal' => 'a5397de2333c9e107cc78175b09031450122bcada14c17afc5e30d48eccd6eea'
  }.freeze
  REQUESTED_REPLY = 'Approve ADR-019 exactly as written as the append-only Gate-B root-only offline host-trust-boundary successor to the bound ADR-018 and external-trust proposal. Authorize only publication in the limited sense of local working-tree artifact creation of a corresponding closed machine contract, and later local working-tree fixture implementation and deterministic tests after a separate independent technical/security review of this exact ADR passes. Commit, push, pull request creation, release, deployment, and migration remain unauthorized. Keep canonical mutation and resolution, request import, host provisioning, human key enrollment, consumer activation, capability or slice implementation, real patient data, live integration, domain acceptance, G0 closure, and G3 acceptance unauthorized until their own later prerequisites and decisions are satisfied. This approval has no activation effect and does not change the current all-false Gate-B authorization state.'
  DECISION_REFERENCE_PREFIX = 'codex_thread:01a02b58-641d-7090-aad4-00871c7ddf47#decision-message-sha256:'
  SUCCESSOR_KEYS = %w[
    artifact_requirement pending_draft_reference required_source_sha256_bindings
    actor_requirement external_task_record_requirement timestamp_contract
    future_successor_schema resolver_backed_record_schemas validation_contract failure_policy
  ].freeze
  PENDING_DRAFT_KEYS = %w[path sha256 binding_rule self_hash_rule].freeze
  ACTOR_REQUIREMENT_KEYS = %w[identity authority_capacity decision_reference_prefix basis].freeze
  EXTERNAL_RECORD_REQUIREMENT_KEYS = %w[
    control_boundary provider_id task_id author_role
    pinned_external_verification_identity_id request_context whole_message_bytes_required
    whole_message_sha256_required successor_record_reference_required
  ].freeze
  TIMESTAMP_CONTRACT_KEYS = %w[required_order format null_or_unverifiable].freeze
  FUTURE_SCHEMA_KEYS = %w[
    top_level_keys draft_reference_keys source_binding_keys
    independent_review_reference_keys actor_keys approved_scope_keys
    authorization_keys immutability_keys secret_handling_keys closed_values
  ].freeze
  FUTURE_TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id status effect data_boundary
    draft_reference source_bindings independent_review_reference actor
    decision_reference decision_message decision_message_encoding
    decision_message_sha256 external_task_record_reference request_presented_at
    source_message_at recorded_at effective_at recorded_at_basis effective_at_basis
    conditions approved_scope authorization immutability secret_handling
  ].freeze
  FUTURE_REVIEW_KEYS = %w[artifact_type schema_version review_id record_sha256 resolver_id].freeze
  EXTERNAL_SCHEMA_KEYS = %w[
    reference_keys verified_result_keys resolved_envelope_keys resolved_record_keys
    approval_verified_metadata_keys independent_review_reference_keys
    independent_review_record_keys review_verified_metadata_keys
  ].freeze
  EXTERNAL_REFERENCE_KEYS = %w[
    artifact_type schema_version provider_id task_id message_id event_id account_id
    record_sha256 resolver_id
  ].freeze
  RESOLVED_ENVELOPE_KEYS = %w[
    artifact_type schema_version record record_sha256 verified_result_metadata
  ].freeze
  VERIFIED_RESULT_KEYS = %w[
    verification_status resolver_id external_verification_identity_id resolved_envelope
  ].freeze
  RESOLVED_RECORD_KEYS = %w[
    provider_id task_id message_id event_id account_id author_role author_account_id
    author_identity_id author_display_identity request_draft_path
    request_draft_sha256 requested_reply_sha256 request_presented_at
    whole_message_bytes whole_message_sha256 source_message_at
  ].freeze
  RESOLVER_VERIFICATION_KEYS = %w[
    resolver_id resolver_kind verification_method external_verification_identity_id
    verification_evidence_sha256 verified_reference_sha256 verified_record_sha256
    account_binding_status bound_author_account_id bound_author_identity_id
    bound_gate_a_identity bound_gate_a_capacity verified_at
  ].freeze
  REVIEW_RECORD_KEYS = %w[
    review_id reviewer_identity_id reviewer_display_identity reviewer_capacity
    reviewer_kind reviewer_author_role reviewer_account_id executor_identity_id
    reviewed_adr_sha256 reviewed_draft_sha256 review_bytes review_sha256 verdict reviewed_at
  ].freeze
  REVIEW_VERIFICATION_KEYS = %w[
    resolver_id resolver_kind verification_method external_verification_identity_id
    verification_evidence_sha256 verified_reference_sha256 verified_record_sha256
    verified_reviewer_identity_id verified_reviewer_account_id verified_reviewer_capacity
    verified_reviewer_kind reviewer_eligibility_status verified_executor_identity_id verified_at
  ].freeze
  VALIDATION_CONTRACT_KEYS = %w[
    trusted_resolver_delivery record_hash reference_hash cross_equal_times
    strict_time_order approval_provider_task_author approval_external_verification_identity
    review_external_verification_identity review_separation recorded_at_semantics effective_at_semantics
    unknown_duplicate_missing_fields offline_embedded_self_claimed_or_unresolved_record
  ].freeze
  APPROVAL_SCOPE = {
    'closed_machine_contract_publication' => 'local_working_tree_artifact_creation_only_after_new_exact_attributable_approval_no_commit_push_pr_release_deploy_or_migrate',
    'local_fixture_implementation' => 'local_working_tree_only_after_new_exact_attributable_approval_and_separate_exact_adr_019_review_pass_no_commit_push_pr_release_deploy_or_migrate',
    'local_deterministic_tests' => 'local_working_tree_only_after_new_exact_attributable_approval_and_separate_exact_adr_019_review_pass_no_commit_push_pr_release_deploy_or_migrate',
    'canonical_mutation_or_resolution' => 'separate_later_decision_required',
    'request_import' => 'separate_later_decision_required',
    'host_provisioning' => 'separate_later_decision_required',
    'human_key_enrollment' => 'separate_later_decision_required',
    'consumer_activation' => 'separate_later_decision_required',
    'capability_or_slice_implementation' => 'separate_later_decision_required',
    'commit_push_pr_release_deployment_or_migration' => 'separate_later_decision_required',
    'real_patient_data' => 'prohibited',
    'live_integration' => 'prohibited',
    'domain_acceptance' => 'separate_later_decision_required',
    'g0_closure' => 'separate_later_decision_required',
    'g3_acceptance' => 'separate_later_decision_required'
  }.freeze
  AUTHORIZATION_KEYS = %w[
    adr_019_approved closed_machine_contract_publication local_fixture_implementation
    local_deterministic_tests canonical_mutation canonical_resolution request_import
    host_provisioning human_key_enrollment consumer_activation capability_disposition
    slice_implementation commit push pull_request release deployment migration
    real_patient_data live_integration domain_acceptance g0_closure g3_acceptance
  ].freeze
  FUTURE_AUTHORIZATION_KEYS = %w[
    closed_machine_contract_local_creation local_fixture_implementation
    local_deterministic_tests canonical_mutation canonical_resolution request_import
    host_provisioning human_key_enrollment consumer_activation capability_disposition
    slice_implementation commit push pull_request release deployment migration
    real_patient_data live_integration domain_acceptance g0_closure g3_acceptance
  ].freeze
  FUTURE_SCOPE = {
    'closed_machine_contract_local_creation' => 'authorized_local_working_tree_after_exact_approval',
    'local_fixture_implementation' => 'authorized_local_working_tree_after_exact_approval_and_review_pass',
    'local_deterministic_tests' => 'authorized_local_working_tree_after_exact_approval_and_review_pass'
  }.freeze
  PROHIBITED_FUTURE_AUTHORIZATIONS = FUTURE_AUTHORIZATION_KEYS - FUTURE_SCOPE.keys
  SECRET_HANDLING = {
    'credentials_permitted' => false,
    'tokens_permitted' => false,
    'private_keys_permitted' => false,
    'connection_strings_permitted' => false
  }.freeze

  class DuplicateKeyRejectingHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON key: #{key}" if key?(key)

      super
    end
  end

  class TrustedResolverFixture
    attr_reader :resolver_id, :verification_identity_id

    def initialize(resolver_id:, verification_identity_id:, verified_results:)
      @resolver_id = resolver_id
      @verification_identity_id = verification_identity_id
      @verified_results = verified_results
    end

    def resolve_verified(reference)
      @verified_results[reference.fetch('record_sha256')]
    end
  end

  def setup
    @raw = File.binread(DRAFT_PATH)
    @draft = parse_json(@raw)
  end

  def test_is_closed_pending_non_authoritative_and_no_effect
    assert_equal TOP_LEVEL_KEYS, @draft.keys
    assert_equal 'g0_governance_v2_host_trust_boundary_approval_decision_draft', @draft.fetch('artifact_type')
    assert_equal 1, @draft.fetch('schema_version')
    assert_equal 'G0-GOVERNANCE-V2-HOST-TRUST-BOUNDARY-APPROVAL-DECISION-DRAFT-2026-08-29', @draft.fetch('artifact_id')
    assert_equal 'pending_exact_attributable_product_owner_approval_and_separate_review', @draft.fetch('status')
    assert_equal 'none_draft_only_non_authoritative_no_activation_or_implementation_authority', @draft.fetch('effect')
    assert_equal 'synthetic_only', @draft.fetch('data_boundary')
  end

  def test_all_four_predecessors_are_exact_regular_hash_bound_sources
    bindings = @draft.fetch('source_bindings')
    assert_equal SOURCE_PATHS.keys, bindings.map { |binding| binding.fetch('role') }
    bindings.each do |binding|
      assert_equal SOURCE_KEYS, binding.keys
      role = binding.fetch('role')
      assert_equal SOURCE_PATHS.fetch(role), binding.fetch('path')
      assert_equal SOURCE_HASHES.fetch(role), binding.fetch('sha256')
      path = File.join(ROOT, binding.fetch('path'))
      assert File.file?(path), path
      refute File.symlink?(path), path
      assert_equal SOURCE_HASHES.fetch(role), Digest::SHA256.file(path).hexdigest
    end
    assert_equal 'proposed_not_approved_not_authoritative_no_effect', bindings.first.fetch('recorded_status')
    assert_equal 'proposal_not_approved_not_authoritative_no_effect', bindings.last.fetch('recorded_status')
  end

  def test_exact_requested_reply_is_verbatim_from_adr_019
    assert_equal REQUESTED_REPLY, @draft.fetch('requested_reply')
    adr = File.binread(File.join(ROOT, SOURCE_PATHS.fetch('host_trust_boundary_adr_019')))
    assert_equal 1, adr.scan("> #{REQUESTED_REPLY}\n").length
    assert_includes adr, 'Approval must be a new immutable attributable artifact binding this file\'s exact SHA-256'
  end

  def test_decision_is_unselected_unattributed_and_has_no_message_or_time
    decision = @draft.fetch('required_decision')
    assert_equal DECISION_KEYS, decision.keys
    assert_equal 'product_owner', decision.fetch('authority_capacity')
    assert_equal 'pending_exact_attributable_reply', decision.fetch('decision_status')
    %w[
      selected_option actor_attribution decision_reference decision_message
      decision_message_encoding decision_message_sha256 request_presented_at
      source_message_at recorded_at effective_at
    ].each { |key| assert_nil decision.fetch(key), key }
    assert_equal [], decision.fetch('conditions')
  end

  def test_successor_contract_binds_final_draft_sources_gate_a_actor_and_external_record
    contract = @draft.fetch('successor_approval_contract')
    assert_equal SUCCESSOR_KEYS, contract.keys
    assert_equal 'new_immutable_local_working_tree_successor_decision_no_commit_push_pr_release_deploy_or_migrate', contract.fetch('artifact_requirement')

    draft_reference = contract.fetch('pending_draft_reference')
    assert_equal PENDING_DRAFT_KEYS, draft_reference.keys
    assert_equal DRAFT_PATH.delete_prefix("#{ROOT}/"), draft_reference.fetch('path')
    assert_nil draft_reference.fetch('sha256')
    assert_equal 'successor_must_bind_exact_sha256_of_final_pending_draft_bytes', draft_reference.fetch('binding_rule')
    assert_equal 'pending_draft_cannot_embed_its_own_sha256', draft_reference.fetch('self_hash_rule')
    assert_equal SOURCE_HASHES, contract.fetch('required_source_sha256_bindings')

    actor = contract.fetch('actor_requirement')
    assert_equal ACTOR_REQUIREMENT_KEYS, actor.keys
    assert_equal ['Daniel Happy Putra', 'product_owner', DECISION_REFERENCE_PREFIX], actor.values_at(
      'identity', 'authority_capacity', 'decision_reference_prefix'
    )
    assert_equal 'must_match_adopted_gate_a_decider_and_source', actor.fetch('basis')
    gate_a = parse_json(File.binread(File.join(ROOT, SOURCE_PATHS.fetch('gate_a_adoption_decision'))))
    assert_equal gate_a.dig('decider', 'identity'), actor.fetch('identity')
    assert_equal gate_a.dig('decider', 'authority_capacity'), actor.fetch('authority_capacity')
    gate_a_prefix = gate_a.dig('decider', 'decision_reference').sub(/[0-9a-f]{64}\z/, '')
    assert_equal gate_a_prefix, actor.fetch('decision_reference_prefix')

    external = contract.fetch('external_task_record_requirement')
    assert_equal EXTERNAL_RECORD_REQUIREMENT_KEYS, external.keys
    assert_equal 'host_or_platform_task_record_outside_repository_writer_control', external.fetch('control_boundary')
    assert_equal ['codex', '01a02b58-641d-7090-aad4-00871c7ddf47', 'user', 'codex-platform-trust-anchor-v1'], external.values_at(
      'provider_id', 'task_id', 'author_role', 'pinned_external_verification_identity_id'
    )
    assert_equal 'exact_pending_draft_path_sha256_and_requested_reply_presented_before_actor_message', external.fetch('request_context')
    assert external.values_at('whole_message_bytes_required', 'whole_message_sha256_required', 'successor_record_reference_required').all?

    timestamps = contract.fetch('timestamp_contract')
    assert_equal TIMESTAMP_CONTRACT_KEYS, timestamps.keys
    assert_includes timestamps.fetch('required_order'), 'source_message_at <= approval_verified_at <= recorded_at'
    assert_includes timestamps.fetch('required_order'), 'reviewed_at <= review_verified_at <= recorded_at'
    assert_equal 'rfc3339_with_offset', timestamps.fetch('format')
    assert_equal 'fail_closed', timestamps.fetch('null_or_unverifiable')

    schema = contract.fetch('future_successor_schema')
    assert_equal FUTURE_SCHEMA_KEYS, schema.keys
    assert_equal FUTURE_TOP_LEVEL_KEYS, schema.fetch('top_level_keys')
    assert_equal %w[path sha256], schema.fetch('draft_reference_keys')
    assert_equal %w[role path sha256], schema.fetch('source_binding_keys')
    assert_equal FUTURE_REVIEW_KEYS, schema.fetch('independent_review_reference_keys')
    assert_equal %w[identity authority_capacity], schema.fetch('actor_keys')
    assert_equal %w[closed_machine_contract_local_creation local_fixture_implementation local_deterministic_tests], schema.fetch('approved_scope_keys')
    assert_equal FUTURE_AUTHORIZATION_KEYS, schema.fetch('authorization_keys')
    assert_equal %w[record_mutable correction_method], schema.fetch('immutability_keys')
    assert_equal SECRET_HANDLING.keys, schema.fetch('secret_handling_keys')
    assert_equal({
      'artifact_type' => 'g0_governance_v2_host_trust_boundary_approval_decision',
      'schema_version' => 1,
      'status' => 'approved_exact_with_required_review_pass',
      'effect' => 'authorizes_local_working_tree_contract_fixture_and_tests_only',
      'data_boundary' => 'synthetic_only',
      'decision_message_encoding' => 'exact_utf8_no_trailing_newline',
      'recorded_at_basis' => 'host_recorder_observed_resolved_external_record',
      'effective_at_basis' => 'host_recorder_first_effect_after_exact_approval_and_bound_review_pass',
      'conditions' => []
    }, schema.fetch('closed_values'))

    external_schemas = contract.fetch('resolver_backed_record_schemas')
    assert_equal EXTERNAL_SCHEMA_KEYS, external_schemas.keys
    assert_equal EXTERNAL_REFERENCE_KEYS, external_schemas.fetch('reference_keys')
    assert_equal VERIFIED_RESULT_KEYS, external_schemas.fetch('verified_result_keys')
    assert_equal RESOLVED_ENVELOPE_KEYS, external_schemas.fetch('resolved_envelope_keys')
    assert_equal RESOLVED_RECORD_KEYS, external_schemas.fetch('resolved_record_keys')
    assert_equal RESOLVER_VERIFICATION_KEYS, external_schemas.fetch('approval_verified_metadata_keys')
    assert_equal FUTURE_REVIEW_KEYS, external_schemas.fetch('independent_review_reference_keys')
    assert_equal REVIEW_RECORD_KEYS, external_schemas.fetch('independent_review_record_keys')
    assert_equal REVIEW_VERIFICATION_KEYS, external_schemas.fetch('review_verified_metadata_keys')

    validation = contract.fetch('validation_contract')
    assert_equal VALIDATION_CONTRACT_KEYS, validation.keys
    assert_equal %w[request_presented_at source_message_at], validation.fetch('cross_equal_times')
    assert_includes validation.fetch('strict_time_order'), 'source_message_at <= approval_verified_at <= recorded_at'
    assert_equal 'codex_fixed_task_user_author_account_bound_to_gate_a_decider', validation.fetch('approval_provider_task_author')
    assert_includes validation.fetch('review_external_verification_identity'), 'independent-review-platform-trust-anchor-v1'
    assert_equal 'resolver_verified_reviewer_identity_account_capacity_kind_and_eligibility_must_cross_equal_review_record_and_differ_from_product_owner_account_identity_and_verified_actual_executor', validation.fetch('review_separation')
    assert_includes validation.fetch('trusted_resolver_delivery'), 'out_of_band'
    assert_equal 'fail_closed_at_every_level', validation.fetch('unknown_duplicate_missing_fields')
    assert_equal 'fail_closed_even_if_byte_perfect', validation.fetch('offline_embedded_self_claimed_or_unresolved_record')
    assert_equal 'actor_capacity_thread_message_hash_request_context_time_or_external_record_mismatch_fails_closed', contract.fetch('failure_policy')
  end

  def test_prior_broad_approvals_cannot_satisfy_exact_adr_019_approval
    refute exact_approval_message?('I approve everything')
    refute exact_approval_message?('I approve the governance-v2 proposal and ADR-018 exactly as stated in the approval block.')
    refute exact_approval_message?("#{REQUESTED_REPLY} I also approve deployment.")
    refute exact_approval_message?('Approve publication of the closed machine contract and push it.')
    refute exact_approval_message?(REQUESTED_REPLY.sub('Keep canonical mutation', 'Authorize canonical mutation'))
    assert exact_approval_message?(REQUESTED_REPLY)
    rule = @draft.fetch('approval_record_rules').find { |entry| entry.include?('I approve everything') }
    refute_nil rule
    assert_includes rule, 'does not match or approve ADR-019'
  end

  def test_eventual_scope_is_narrow_and_requires_separate_exact_adr_review
    assert_equal APPROVAL_SCOPE, @draft.fetch('eventual_exact_approval_scope')
    publication = APPROVAL_SCOPE.fetch('closed_machine_contract_publication')
    assert_includes publication, 'local_working_tree_artifact_creation_only'
    assert_includes publication, 'no_commit_push_pr_release_deploy_or_migrate'
    %w[local_fixture_implementation local_deterministic_tests].each do |key|
      assert_includes APPROVAL_SCOPE.fetch(key), 'separate_exact_adr_019_review_pass'
      assert_includes APPROVAL_SCOPE.fetch(key), 'no_commit_push_pr_release_deploy_or_migrate'
    end
    APPROVAL_SCOPE.each do |key, value|
      next if %w[closed_machine_contract_publication local_fixture_implementation local_deterministic_tests].include?(key)

      assert_includes %w[separate_later_decision_required prohibited], value
    end
  end

  def test_every_current_authority_boolean_is_false
    authorization = @draft.fetch('authorization')
    assert_equal AUTHORIZATION_KEYS, authorization.keys
    assert authorization.values.all? { |value| value == false }
    assert_includes @draft.fetch('current_boundaries'), 'Canonical Gate-B state remains unchanged and G0 and G3 remain open.'
    joined = @draft.fetch('current_boundaries').join(' ')
    %w[synthetic-only commit push pull-request release deployment migration import provisioning enrollment].each do |term|
      assert_includes joined, term
    end
  end

  def test_closed_successor_rejects_schema_smuggling_resolver_and_time_forgery
    valid, resolver, review_resolver = valid_successor_fixture
    assert successor_approval_valid?(valid, resolver, review_resolver)

    unknown_top = deep_copy(valid).merge('extra' => true)
    refute successor_approval_valid?(unknown_top, resolver, review_resolver)
    unknown_nested = deep_copy(valid)
    unknown_nested['actor']['extra'] = true
    refute successor_approval_valid?(unknown_nested, resolver, review_resolver)
    missing = deep_copy(valid)
    missing.delete('effect')
    refute successor_approval_valid?(missing, resolver, review_resolver)

    %w[effect conditions approved_scope].each do |field|
      forged = deep_copy(valid)
      forged[field] = field == 'conditions' ? ['push'] : 'expanded'
      refute successor_approval_valid?(forged, resolver, review_resolver), field
    end
    PROHIBITED_FUTURE_AUTHORIZATIONS.each do |field|
      forged = deep_copy(valid)
      forged['authorization'][field] = true
      refute successor_approval_valid?(forged, resolver, review_resolver), field
    end

    refute successor_approval_valid?(valid, nil, review_resolver)
    refute successor_approval_valid?(valid, resolver, nil)
    fabricated = TrustedResolverFixture.new(resolver_id: resolver.resolver_id, verification_identity_id: resolver.verification_identity_id, verified_results: {})
    refute successor_approval_valid?(valid, fabricated, review_resolver)
    embedded = deep_copy(valid)
    embedded['external_task_record'] = resolver.resolve_verified(valid.fetch('external_task_record_reference'))
    refute successor_approval_valid?(embedded, resolver, review_resolver)
    bad_reference = deep_copy(valid)
    bad_reference['external_task_record_reference']['record_sha256'] = '0' * 64
    refute successor_approval_valid?(bad_reference, resolver, review_resolver)

    bad_result = deep_copy(resolver.resolve_verified(valid.fetch('external_task_record_reference')))
    bad_result['verification_status'] = 'UNVERIFIED'
    refute successor_approval_valid?(valid, resolver_with(valid, bad_result), review_resolver)
    bad_identity = deep_copy(resolver.resolve_verified(valid.fetch('external_task_record_reference')))
    bad_identity['external_verification_identity_id'] = 'forged-identity'
    refute successor_approval_valid?(valid, resolver_with(valid, bad_identity), review_resolver)
    %w[assistant service].each do |role|
      bad_author = deep_copy(resolver.resolve_verified(valid.fetch('external_task_record_reference')))
      bad_author['resolved_envelope']['record']['author_role'] = role
      refute successor_approval_valid?(valid, resolver_with(valid, bad_author), review_resolver), role
    end
    bad_account = deep_copy(resolver.resolve_verified(valid.fetch('external_task_record_reference')))
    bad_account['resolved_envelope']['record']['author_account_id'] = 'other-account'
    refute successor_approval_valid?(valid, resolver_with(valid, bad_account), review_resolver)
    bad_author_identity = deep_copy(resolver.resolve_verified(valid.fetch('external_task_record_reference')))
    bad_author_identity['resolved_envelope']['record']['author_identity_id'] = 'other-identity'
    refute successor_approval_valid?(valid, resolver_with(valid, bad_author_identity), review_resolver)
    %w[malformed future].each do |kind|
      bad_time = deep_copy(resolver.resolve_verified(valid.fetch('external_task_record_reference')))
      bad_time['resolved_envelope']['verified_result_metadata']['verified_at'] = kind == 'malformed' ? 'not-a-time' : '2026-08-29T10:04:00+07:00'
      refute successor_approval_valid?(valid, resolver_with(valid, bad_time), review_resolver), kind
    end

    missing_review = TrustedResolverFixture.new(resolver_id: review_resolver.resolver_id, verification_identity_id: review_resolver.verification_identity_id, verified_results: {})
    refute successor_approval_valid?(valid, resolver, missing_review)
    unverified_review = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    unverified_review['verification_status'] = 'UNVERIFIED'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, unverified_review))
    wrong_review_anchor = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    wrong_review_anchor['external_verification_identity_id'] = 'forged-review-anchor'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, wrong_review_anchor))
    bad_review_hash = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    bad_review_hash['resolved_envelope']['record_sha256'] = '0' * 64
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, bad_review_hash))
    %w[reviewed_adr_sha256 reviewed_draft_sha256 verdict reviewer_identity_id].each do |field|
      bad_review = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
      bad_review['resolved_envelope']['record'][field] = field == 'verdict' ? 'BLOCKED' : 'forged'
      refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, bad_review)), field
    end
    owner_review = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    owner_review['resolved_envelope']['record']['reviewer_identity_id'] = 'gate-a-product-owner-daniel-happy-putra'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, owner_review))
    self_review = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    self_review['resolved_envelope']['record']['reviewer_identity_id'] = self_review['resolved_envelope']['record']['executor_identity_id']
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, self_review))
    authenticated_but_uncredentialed = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    authenticated_but_uncredentialed['resolved_envelope']['verified_result_metadata']['reviewer_eligibility_status'] = 'AUTHENTICATED_NOT_ELIGIBLE'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, authenticated_but_uncredentialed))
    actual_reviewer_is_executor = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    actual_reviewer_is_executor['resolved_envelope']['verified_result_metadata']['verified_executor_identity_id'] = actual_reviewer_is_executor['resolved_envelope']['verified_result_metadata']['verified_reviewer_identity_id']
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, actual_reviewer_is_executor))
    reviewer_account_mismatch = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    reviewer_account_mismatch['resolved_envelope']['verified_result_metadata']['verified_reviewer_account_id'] = 'different-authenticated-reviewer-account'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, reviewer_account_mismatch))
    reviewer_capacity_mismatch = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    reviewer_capacity_mismatch['resolved_envelope']['verified_result_metadata']['verified_reviewer_capacity'] = 'authenticated_but_uncredentialed_reviewer'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, reviewer_capacity_mismatch))
    reviewer_kind_mismatch = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    reviewer_kind_mismatch['resolved_envelope']['verified_result_metadata']['verified_reviewer_kind'] = 'owner_or_executor_affiliated'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, reviewer_kind_mismatch))
    future_review = deep_copy(review_resolver.resolve_verified(valid.fetch('independent_review_reference')))
    future_review['resolved_envelope']['verified_result_metadata']['verified_at'] = '2026-08-29T10:04:00+07:00'
    refute successor_approval_valid?(valid, resolver, review_resolver_with(valid, future_review))

    %w[request_presented_at source_message_at recorded_at effective_at].each do |field|
      forged = deep_copy(valid)
      forged[field] = '2026-08-29T09:00:00+07:00'
      refute successor_approval_valid?(forged, resolver, review_resolver), field
    end
  end

  def test_draft_rules_preserve_no_push_deploy_migrate_provision_import_enroll_or_activate
    rules = @draft.fetch('approval_record_rules')
    assert_equal 7, rules.length
    joined = rules.join(' ')
    [
      'canonical mutation', 'request import', 'host provisioning', 'human key enrollment',
      'consumer activation', 'commit push pull request release deployment migration',
      'real patient data', 'live integration', 'G0 closure', 'G3 acceptance'
    ].each { |phrase| assert_includes joined, phrase }
    assert_includes joined, 'Do not mutate this draft into an approval artifact.'
    assert_includes joined, 'Publication means local working-tree artifact creation only'
  end

  def test_secret_classes_duplicate_keys_and_bytes_are_safe
    assert_equal SECRET_HANDLING, @draft.fetch('secret_handling')
    secret_pattern = /(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|postgres(?:ql)?:\/\/[^\s:]+:[^\s@]+@|(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)["']?\s*(?::|=)\s*["']?[^\s,;}"']+)/i
    refute_match secret_pattern, @raw
    assert_raises(JSON::ParserError) { parse_json('{"status":"pending","status":"approved"}') }
    assert_raises(JSON::ParserError) { parse_json('{"actor":{"identity":"a","identity":"b"}}') }
    assert @raw.valid_encoding?
    refute_includes @raw, "\r"
    assert @raw.end_with?("\n")
  end

  private

  def valid_successor_fixture
    message_sha = Digest::SHA256.hexdigest(REQUESTED_REPLY.b)
    draft_path = DRAFT_PATH.delete_prefix("#{ROOT}/")
    draft_sha = Digest::SHA256.file(DRAFT_PATH).hexdigest
    record = {
      'provider_id' => 'codex',
      'task_id' => '01a02b58-641d-7090-aad4-00871c7ddf47',
      'message_id' => message_sha,
      'event_id' => 'event-fixture',
      'account_id' => 'owner-account-fixture',
      'author_role' => 'user',
      'author_account_id' => 'owner-account-fixture',
      'author_identity_id' => 'gate-a-product-owner-daniel-happy-putra',
      'author_display_identity' => 'Daniel Happy Putra',
      'request_draft_path' => draft_path,
      'request_draft_sha256' => draft_sha,
      'requested_reply_sha256' => message_sha,
      'request_presented_at' => '2026-08-29T10:00:00+07:00',
      'whole_message_bytes' => REQUESTED_REPLY,
      'whole_message_sha256' => message_sha,
      'source_message_at' => '2026-08-29T10:01:00+07:00'
    }
    record_sha = Digest::SHA256.hexdigest(canonical_json_lf(record))
    reference = {
      'artifact_type' => 'g0_external_task_record_reference_v1',
      'schema_version' => 1,
      'provider_id' => record.fetch('provider_id'),
      'task_id' => record.fetch('task_id'),
      'message_id' => record.fetch('message_id'),
      'event_id' => record.fetch('event_id'),
      'account_id' => record.fetch('account_id'),
      'record_sha256' => record_sha,
      'resolver_id' => 'trusted-platform-resolver-v1'
    }
    reference_sha = Digest::SHA256.hexdigest(canonical_json_lf(reference))
    envelope = {
      'artifact_type' => 'g0_resolved_external_task_record_v1',
      'schema_version' => 1,
      'record' => record,
      'record_sha256' => record_sha,
      'verified_result_metadata' => {
        'resolver_id' => reference.fetch('resolver_id'),
        'resolver_kind' => 'out_of_band_host_platform',
        'verification_method' => 'externally_keyed_attestation',
        'external_verification_identity_id' => 'codex-platform-trust-anchor-v1',
        'verification_evidence_sha256' => 'e' * 64,
        'verified_reference_sha256' => reference_sha,
        'verified_record_sha256' => record_sha,
        'account_binding_status' => 'VERIFIED_GATE_A_DECIDER_BINDING',
        'bound_author_account_id' => record.fetch('author_account_id'),
        'bound_author_identity_id' => record.fetch('author_identity_id'),
        'bound_gate_a_identity' => 'Daniel Happy Putra',
        'bound_gate_a_capacity' => 'product_owner',
        'verified_at' => '2026-08-29T10:01:30+07:00'
      }
    }
    verified_result = {
      'verification_status' => 'VERIFIED',
      'resolver_id' => reference.fetch('resolver_id'),
      'external_verification_identity_id' => 'codex-platform-trust-anchor-v1',
      'resolved_envelope' => envelope
    }
    review_record = {
      'review_id' => 'independent-review-fixture',
      'reviewer_identity_id' => 'independent-reviewer-fixture',
      'reviewer_display_identity' => 'Independent Reviewer Fixture',
      'reviewer_capacity' => 'independent_technical_security_reviewer',
      'reviewer_kind' => 'independent_not_product_owner_or_executor',
      'reviewer_author_role' => 'reviewer',
      'reviewer_account_id' => 'reviewer-account-fixture',
      'executor_identity_id' => 'repository-executor-fixture',
      'reviewed_adr_sha256' => SOURCE_HASHES.fetch('host_trust_boundary_adr_019'),
      'reviewed_draft_sha256' => draft_sha,
      'review_bytes' => 'PASS exact ADR and draft fixture review',
      'review_sha256' => Digest::SHA256.hexdigest('PASS exact ADR and draft fixture review'),
      'verdict' => 'PASS',
      'reviewed_at' => '2026-08-29T10:01:20+07:00'
    }
    review_record_sha = Digest::SHA256.hexdigest(canonical_json_lf(review_record))
    review_reference = {
      'artifact_type' => 'g0_independent_review_record_reference_v1',
      'schema_version' => 1,
      'review_id' => review_record.fetch('review_id'),
      'record_sha256' => review_record_sha,
      'resolver_id' => 'trusted-independent-review-resolver-v1'
    }
    review_reference_sha = Digest::SHA256.hexdigest(canonical_json_lf(review_reference))
    review_envelope = {
      'artifact_type' => 'g0_resolved_independent_review_record_v1',
      'schema_version' => 1,
      'record' => review_record,
      'record_sha256' => review_record_sha,
      'verified_result_metadata' => {
        'resolver_id' => review_reference.fetch('resolver_id'),
        'resolver_kind' => 'out_of_band_independent_review_platform',
        'verification_method' => 'externally_keyed_attestation',
        'external_verification_identity_id' => 'independent-review-platform-trust-anchor-v1',
        'verification_evidence_sha256' => 'd' * 64,
        'verified_reference_sha256' => review_reference_sha,
        'verified_record_sha256' => review_record_sha,
        'verified_reviewer_identity_id' => review_record.fetch('reviewer_identity_id'),
        'verified_reviewer_account_id' => review_record.fetch('reviewer_account_id'),
        'verified_reviewer_capacity' => review_record.fetch('reviewer_capacity'),
        'verified_reviewer_kind' => review_record.fetch('reviewer_kind'),
        'reviewer_eligibility_status' => 'VERIFIED_ELIGIBLE_INDEPENDENT_REVIEWER',
        'verified_executor_identity_id' => review_record.fetch('executor_identity_id'),
        'verified_at' => '2026-08-29T10:01:40+07:00'
      }
    }
    review_verified_result = {
      'verification_status' => 'VERIFIED',
      'resolver_id' => review_reference.fetch('resolver_id'),
      'external_verification_identity_id' => 'independent-review-platform-trust-anchor-v1',
      'resolved_envelope' => review_envelope
    }
    source_bindings = @draft.fetch('source_bindings').map do |binding|
      binding.slice('role', 'path', 'sha256')
    end.sort_by { |binding| binding.fetch('role').bytes }
    authorization = FUTURE_AUTHORIZATION_KEYS.each_with_object({}) { |key, memo| memo[key] = FUTURE_SCOPE.key?(key) }
    successor = {
      'artifact_type' => 'g0_governance_v2_host_trust_boundary_approval_decision',
      'schema_version' => 1,
      'artifact_id' => 'G0-GOVERNANCE-V2-HOST-TRUST-BOUNDARY-APPROVAL-DECISION-FIXTURE',
      'status' => 'approved_exact_with_required_review_pass',
      'effect' => 'authorizes_local_working_tree_contract_fixture_and_tests_only',
      'data_boundary' => 'synthetic_only',
      'draft_reference' => { 'path' => draft_path, 'sha256' => draft_sha },
      'source_bindings' => source_bindings,
      'independent_review_reference' => review_reference,
      'actor' => { 'identity' => 'Daniel Happy Putra', 'authority_capacity' => 'product_owner' },
      'decision_reference' => "#{DECISION_REFERENCE_PREFIX}#{message_sha}",
      'decision_message' => REQUESTED_REPLY,
      'decision_message_encoding' => 'exact_utf8_no_trailing_newline',
      'decision_message_sha256' => message_sha,
      'external_task_record_reference' => reference,
      'request_presented_at' => record.fetch('request_presented_at'),
      'source_message_at' => record.fetch('source_message_at'),
      'recorded_at' => '2026-08-29T10:02:00+07:00',
      'effective_at' => '2026-08-29T10:03:00+07:00',
      'recorded_at_basis' => 'host_recorder_observed_resolved_external_record',
      'effective_at_basis' => 'host_recorder_first_effect_after_exact_approval_and_bound_review_pass',
      'conditions' => [],
      'approved_scope' => FUTURE_SCOPE,
      'authorization' => authorization,
      'immutability' => { 'record_mutable' => false, 'correction_method' => 'append_new_hash_bound_successor' },
      'secret_handling' => SECRET_HANDLING
    }
    resolver = TrustedResolverFixture.new(
      resolver_id: reference.fetch('resolver_id'),
      verification_identity_id: 'codex-platform-trust-anchor-v1',
      verified_results: { record_sha => verified_result }
    )
    review_resolver = TrustedResolverFixture.new(
      resolver_id: review_reference.fetch('resolver_id'),
      verification_identity_id: 'independent-review-platform-trust-anchor-v1',
      verified_results: { review_record_sha => review_verified_result }
    )
    [successor, resolver, review_resolver]
  end

  def successor_approval_valid?(successor, resolver, review_resolver)
    return false unless resolver && review_resolver
    return false unless successor.keys == FUTURE_TOP_LEVEL_KEYS

    closed = @draft.dig('successor_approval_contract', 'future_successor_schema', 'closed_values')
    closed.each { |key, value| return false unless successor.fetch(key) == value }
    draft_path = DRAFT_PATH.delete_prefix("#{ROOT}/")
    draft_sha = Digest::SHA256.file(DRAFT_PATH).hexdigest
    message_sha = Digest::SHA256.hexdigest(successor.fetch('decision_message').b)
    return false unless successor.fetch('draft_reference').keys == %w[path sha256]
    return false unless successor.fetch('draft_reference') == { 'path' => draft_path, 'sha256' => draft_sha }
    expected_sources = @draft.fetch('source_bindings').map { |binding| binding.slice('role', 'path', 'sha256') }.sort_by { |binding| binding.fetch('role').bytes }
    return false unless successor.fetch('source_bindings') == expected_sources
    return false unless successor.fetch('independent_review_reference').keys == FUTURE_REVIEW_KEYS
    return false unless successor.fetch('actor') == { 'identity' => 'Daniel Happy Putra', 'authority_capacity' => 'product_owner' }
    return false unless successor.fetch('decision_message') == REQUESTED_REPLY
    return false unless successor.fetch('decision_message_sha256') == message_sha
    return false unless successor.fetch('decision_reference') == "#{DECISION_REFERENCE_PREFIX}#{message_sha}"
    return false unless successor.fetch('approved_scope') == FUTURE_SCOPE
    return false unless successor.fetch('authorization').keys == FUTURE_AUTHORIZATION_KEYS
    return false unless successor.fetch('authorization') == FUTURE_AUTHORIZATION_KEYS.each_with_object({}) { |key, memo| memo[key] = FUTURE_SCOPE.key?(key) }
    return false unless successor.fetch('immutability') == { 'record_mutable' => false, 'correction_method' => 'append_new_hash_bound_successor' }
    return false unless successor.fetch('secret_handling') == SECRET_HANDLING

    reference = successor.fetch('external_task_record_reference')
    return false unless reference.keys == EXTERNAL_REFERENCE_KEYS
    return false unless reference.fetch('artifact_type') == 'g0_external_task_record_reference_v1' && reference.fetch('schema_version') == 1
    return false unless reference.fetch('provider_id') == 'codex'
    return false unless reference.fetch('task_id') == '01a02b58-641d-7090-aad4-00871c7ddf47'
    return false unless reference.fetch('message_id') == message_sha
    result = resolver.resolve_verified(reference)
    return false unless result && result.keys == VERIFIED_RESULT_KEYS
    return false unless result.fetch('verification_status') == 'VERIFIED'
    return false unless result.fetch('resolver_id') == resolver.resolver_id
    return false unless result.fetch('external_verification_identity_id') == 'codex-platform-trust-anchor-v1'
    return false unless resolver.verification_identity_id == 'codex-platform-trust-anchor-v1'
    envelope = result.fetch('resolved_envelope')
    return false unless envelope && envelope.keys == RESOLVED_ENVELOPE_KEYS
    return false unless envelope.fetch('artifact_type') == 'g0_resolved_external_task_record_v1' && envelope.fetch('schema_version') == 1
    resolved = envelope.fetch('record')
    verification = envelope.fetch('verified_result_metadata')
    return false unless resolved.keys == RESOLVED_RECORD_KEYS && verification.keys == RESOLVER_VERIFICATION_KEYS
    record_sha = Digest::SHA256.hexdigest(canonical_json_lf(resolved))
    reference_sha = Digest::SHA256.hexdigest(canonical_json_lf(reference))
    return false unless record_sha == reference.fetch('record_sha256') && record_sha == envelope.fetch('record_sha256')
    %w[provider_id task_id message_id event_id account_id].each { |key| return false unless reference.fetch(key) == resolved.fetch(key) }
    return false unless reference.fetch('resolver_id') == resolver.resolver_id
    return false unless verification.fetch('resolver_id') == resolver.resolver_id
    return false unless verification.fetch('external_verification_identity_id') == resolver.verification_identity_id
    return false unless verification.fetch('resolver_kind') == 'out_of_band_host_platform'
    return false unless verification.fetch('verification_method') == 'externally_keyed_attestation'
    return false unless verification.fetch('verified_reference_sha256') == reference_sha
    return false unless verification.fetch('verified_record_sha256') == record_sha
    return false unless verification.fetch('verification_evidence_sha256').match?(/\A[0-9a-f]{64}\z/)
    return false unless verification.fetch('account_binding_status') == 'VERIFIED_GATE_A_DECIDER_BINDING'
    return false unless resolved.fetch('author_role') == 'user'
    return false unless resolved.fetch('account_id') == resolved.fetch('author_account_id')
    return false unless reference.fetch('account_id') == resolved.fetch('author_account_id')
    return false unless resolved.fetch('author_display_identity') == successor.dig('actor', 'identity')
    return false unless verification.fetch('bound_author_account_id') == resolved.fetch('author_account_id')
    return false unless verification.fetch('bound_author_identity_id') == resolved.fetch('author_identity_id')
    return false unless verification.fetch('bound_gate_a_identity') == successor.dig('actor', 'identity')
    return false unless verification.fetch('bound_gate_a_capacity') == successor.dig('actor', 'authority_capacity')
    return false unless resolved.fetch('request_draft_path') == draft_path && resolved.fetch('request_draft_sha256') == draft_sha
    return false unless resolved.fetch('requested_reply_sha256') == Digest::SHA256.hexdigest(REQUESTED_REPLY.b)
    return false unless resolved.fetch('whole_message_bytes') == successor.fetch('decision_message')
    return false unless resolved.fetch('whole_message_sha256') == message_sha
    return false unless successor.fetch('request_presented_at') == resolved.fetch('request_presented_at')
    return false unless successor.fetch('source_message_at') == resolved.fetch('source_message_at')

    review_reference = successor.fetch('independent_review_reference')
    return false unless review_reference.fetch('artifact_type') == 'g0_independent_review_record_reference_v1' && review_reference.fetch('schema_version') == 1
    review_result = review_resolver.resolve_verified(review_reference)
    return false unless review_result && review_result.keys == VERIFIED_RESULT_KEYS
    return false unless review_result.fetch('verification_status') == 'VERIFIED'
    return false unless review_result.fetch('resolver_id') == review_resolver.resolver_id
    return false unless review_result.fetch('external_verification_identity_id') == 'independent-review-platform-trust-anchor-v1'
    return false unless review_resolver.verification_identity_id == 'independent-review-platform-trust-anchor-v1'
    review_envelope = review_result.fetch('resolved_envelope')
    return false unless review_envelope.keys == RESOLVED_ENVELOPE_KEYS
    review_record = review_envelope.fetch('record')
    review_verification = review_envelope.fetch('verified_result_metadata')
    return false unless review_record.keys == REVIEW_RECORD_KEYS && review_verification.keys == REVIEW_VERIFICATION_KEYS
    review_record_sha = Digest::SHA256.hexdigest(canonical_json_lf(review_record))
    review_reference_sha = Digest::SHA256.hexdigest(canonical_json_lf(review_reference))
    return false unless review_record_sha == review_reference.fetch('record_sha256') && review_record_sha == review_envelope.fetch('record_sha256')
    return false unless review_reference.fetch('review_id') == review_record.fetch('review_id')
    return false unless review_verification.fetch('verified_reference_sha256') == review_reference_sha
    return false unless review_verification.fetch('verified_record_sha256') == review_record_sha
    return false unless review_verification.fetch('resolver_id') == review_resolver.resolver_id
    return false unless review_verification.fetch('resolver_kind') == 'out_of_band_independent_review_platform'
    return false unless review_verification.fetch('verification_method') == 'externally_keyed_attestation'
    return false unless review_verification.fetch('verification_evidence_sha256').match?(/\A[0-9a-f]{64}\z/)
    return false unless review_verification.fetch('external_verification_identity_id') == review_resolver.verification_identity_id
    return false unless review_verification.fetch('verified_reviewer_identity_id') == review_record.fetch('reviewer_identity_id')
    return false unless review_verification.fetch('verified_reviewer_account_id') == review_record.fetch('reviewer_account_id')
    return false unless review_verification.fetch('verified_reviewer_capacity') == review_record.fetch('reviewer_capacity')
    return false unless review_verification.fetch('verified_reviewer_kind') == review_record.fetch('reviewer_kind')
    return false unless review_verification.fetch('reviewer_eligibility_status') == 'VERIFIED_ELIGIBLE_INDEPENDENT_REVIEWER'
    return false unless review_verification.fetch('verified_executor_identity_id') == review_record.fetch('executor_identity_id')
    return false unless review_record.fetch('reviewed_adr_sha256') == SOURCE_HASHES.fetch('host_trust_boundary_adr_019')
    return false unless review_record.fetch('reviewed_draft_sha256') == draft_sha
    return false unless Digest::SHA256.hexdigest(review_record.fetch('review_bytes').b) == review_record.fetch('review_sha256')
    return false unless review_record.fetch('verdict') == 'PASS'
    return false unless review_record.fetch('reviewer_capacity') == 'independent_technical_security_reviewer'
    return false unless review_record.fetch('reviewer_kind') == 'independent_not_product_owner_or_executor'
    return false unless review_record.fetch('reviewer_author_role') == 'reviewer'
    return false if review_record.fetch('reviewer_identity_id') == resolved.fetch('author_identity_id')
    return false if review_record.fetch('reviewer_account_id') == resolved.fetch('author_account_id')
    return false if review_record.fetch('reviewer_identity_id') == review_verification.fetch('verified_executor_identity_id')
    return false if [review_record.fetch('reviewer_identity_id'), review_record.fetch('reviewer_display_identity')].include?(successor.dig('actor', 'identity'))

    times = %w[request_presented_at source_message_at recorded_at effective_at].map { |field| parse_explicit_time(successor.fetch(field)) }
    approval_verified_at = parse_explicit_time(verification.fetch('verified_at'))
    reviewed_at = parse_explicit_time(review_record.fetch('reviewed_at'))
    review_verified_at = parse_explicit_time(review_verification.fetch('verified_at'))
    return false unless times[0] < times[1] && times[1] <= approval_verified_at && approval_verified_at <= times[2] && times[2] < times[3]
    return false unless reviewed_at <= review_verified_at && review_verified_at <= times[2] && review_verified_at < times[3]

    true
  rescue KeyError, NoMethodError, TypeError, ArgumentError
    false
  end

  def resolver_with(successor, verified_result)
    reference = successor.fetch('external_task_record_reference')
    TrustedResolverFixture.new(
      resolver_id: reference.fetch('resolver_id'),
      verification_identity_id: 'codex-platform-trust-anchor-v1',
      verified_results: { reference.fetch('record_sha256') => verified_result }
    )
  end

  def review_resolver_with(successor, verified_result)
    reference = successor.fetch('independent_review_reference')
    TrustedResolverFixture.new(
      resolver_id: reference.fetch('resolver_id'),
      verification_identity_id: 'independent-review-platform-trust-anchor-v1',
      verified_results: { reference.fetch('record_sha256') => verified_result }
    )
  end

  def parse_explicit_time(value)
    raise ArgumentError unless value.match?(/[+-]\d{2}:\d{2}\z/)

    Time.iso8601(value)
  end

  def canonical_json_lf(value)
    canonical_json(value) + "\n"
  end

  def canonical_json(value)
    case value
    when Hash
      '{' + value.keys.sort.map { |key| "#{JSON.generate(key)}:#{canonical_json(value.fetch(key))}" }.join(',') + '}'
    when Array
      '[' + value.map { |item| canonical_json(item) }.join(',') + ']'
    when String then JSON.generate(value)
    when Integer then value.to_s
    when TrueClass then 'true'
    when FalseClass then 'false'
    when NilClass then 'null'
    else raise ArgumentError, 'unsupported canonical fixture value'
    end
  end

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end

  def exact_approval_message?(message)
    message.b == REQUESTED_REPLY.b
  end

  def parse_json(raw)
    JSON.parse(raw, object_class: DuplicateKeyRejectingHash)
  end
end
