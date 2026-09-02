# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'
require 'time'

class G0GovernanceV2ProcessSimplificationDecisionTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  DECISION_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_PROCESS_SIMPLIFICATION_DECISION_2026-08-30.json'
  )

  DECISION_MESSAGE = 'I revoke the ADR-019 exact-wording approval requirement. Simplify the governance process and continue local implementation under the established synthetic-data, no-secrets, no-live-integration, and no-premature-push/deployment boundaries.'
  DECISION_MESSAGE_SHA256 = 'f2c97d3f6cd6537611c6d70aef2789d2f8ca8bd1ea3623cd5d9e5fe5ce02bb92'
  DECISION_ARTIFACT_SHA256 = '93c98f3c0f06296ce6f4449efd7e73426470e8a400d23181a6970a4b4273a16b'

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id status effect data_boundary recorded_at
    recorded_at_basis source_bindings decision revocation continuing_authority
    authorization preserved_state immutability secret_handling
  ].freeze
  SOURCE_KEYS = %w[role path sha256 recorded_status].freeze
  ACTOR_KEYS = %w[identity authority_capacity attribution_basis].freeze
  DECISION_KEYS = %w[
    actor decision_reference decision_message decision_message_encoding
    decision_message_byte_length decision_message_sha256 source_message_at
    platform_attribution
  ].freeze
  PLATFORM_ATTRIBUTION_KEYS = %w[
    status message_id event_id account_id external_verification_identity_id
  ].freeze
  REVOCATION_KEYS = %w[
    revoked_requirement retired_requested_reply_sha256 retired_draft_sha256
    retirement_effect adr_019_effect external_trust_effect
  ].freeze
  CONTINUING_AUTHORITY_KEYS = %w[
    governance_v2_local_implementation governance_process_simplification
    implementation_basis authority_expansion later_authority_rule
  ].freeze
  AUTHORIZATION_KEYS = %w[
    governance_v2_local_implementation
    governance_process_simplification_local_implementation adr_019_approval
    closed_machine_contract_creation native_root_executable_implementation
    external_trust_implementation canonical_mutation canonical_resolution
    request_import host_provisioning human_key_enrollment consumer_activation
    capability_disposition slice_implementation commit push pull_request release
    deployment migration real_patient_data live_integration domain_acceptance
    g0_closure g3_acceptance
  ].freeze
  PRESERVED_STATE_KEYS = %w[
    immutable_predecessors host_trust_approval_draft activation_decision_draft
    consumer_pointer consumer_selection owner_dispositions g0 g3
    deployment_and_domain_acceptance
  ].freeze
  SECRET_HANDLING = {
    'credentials_permitted' => false,
    'tokens_permitted' => false,
    'private_keys_permitted' => false,
    'connection_strings_permitted' => false
  }.freeze
  SOURCE_PATHS = {
    'gate_a_adoption_decision' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json',
    'adopted_adr_018' => 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md',
    'proposed_adr_019' => 'docs/adr/ADR-019-HOST-OWNED-G0-GOVERNANCE-TRUST-BOUNDARY.md',
    'external_trust_provisioning_proposal' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_EXTERNAL_TRUST_PROVISIONING_PROPOSAL_2026-08-29.md',
    'retired_exact_wording_approval_draft' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_HOST_TRUST_BOUNDARY_APPROVAL_DECISION_DRAFT_2026-08-29.json',
    'activation_decision_draft' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISION_DRAFT_2026-08-29.json'
  }.freeze
  SOURCE_HASHES = {
    'gate_a_adoption_decision' => '139bf2f952ab5c47cd34a43f80d93224e48386c57f180a799d8f206a9049c0f3',
    'adopted_adr_018' => 'cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962',
    'proposed_adr_019' => '288f8c25fe506643750f2202831b7c1e5345a805702d85d4b6393b76794cd272',
    'external_trust_provisioning_proposal' => 'a5397de2333c9e107cc78175b09031450122bcada14c17afc5e30d48eccd6eea',
    'retired_exact_wording_approval_draft' => '6a9672108fce9c8408e24576d4e5aeecba87af79b379f1e5681a81c1efc6057f',
    'activation_decision_draft' => '182de2a2c7a673d03a0de306ee8bbc9e17539f94955c5ae9fa1df344ed3f5230'
  }.freeze

  class DuplicateKeyRejectingHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON key: #{key}" if key?(key)

      super
    end
  end

  def setup
    @raw = File.binread(DECISION_PATH)
    @decision = parse_json(@raw)
  end

  def test_is_closed_effective_and_narrow
    assert_equal DECISION_ARTIFACT_SHA256, Digest::SHA256.hexdigest(@raw)
    assert_equal TOP_LEVEL_KEYS, @decision.keys
    assert_equal 'g0_governance_v2_process_simplification_decision', @decision.fetch('artifact_type')
    assert_equal 1, @decision.fetch('schema_version')
    assert_equal 'G0-GOVERNANCE-V2-PROCESS-SIMPLIFICATION-DECISION-2026-08-30', @decision.fetch('artifact_id')
    assert_equal 'effective_append_only_process_simplification', @decision.fetch('status')
    assert_equal 'revokes_adr_019_exact_wording_approval_requirement_and_reaffirms_existing_gate_a_local_governance_v2_implementation_only', @decision.fetch('effect')
    assert_equal 'synthetic_only', @decision.fetch('data_boundary')
    assert_equal 'first_local_record_after_product_owner_instruction_source_timestamp_unavailable', @decision.fetch('recorded_at_basis')
    assert Time.iso8601(@decision.fetch('recorded_at'))
  end

  def test_exact_message_bytes_are_hash_bound_without_fabricated_platform_identity_or_time
    decision = @decision.fetch('decision')
    assert_equal DECISION_KEYS, decision.keys
    assert_equal ACTOR_KEYS, decision.fetch('actor').keys
    assert_equal ['Daniel Happy Putra', 'product_owner'], decision.fetch('actor').values_at('identity', 'authority_capacity')

    gate_a = parse_json(File.binread(File.join(ROOT, SOURCE_PATHS.fetch('gate_a_adoption_decision'))))
    assert_equal gate_a.dig('decider', 'identity'), decision.dig('actor', 'identity')
    assert_equal gate_a.dig('decider', 'authority_capacity'), decision.dig('actor', 'authority_capacity')
    assert_equal DECISION_MESSAGE, decision.fetch('decision_message')
    assert_equal 'exact_utf8_no_trailing_newline', decision.fetch('decision_message_encoding')
    assert_equal DECISION_MESSAGE.bytesize, decision.fetch('decision_message_byte_length')
    assert_equal DECISION_MESSAGE_SHA256, Digest::SHA256.hexdigest(decision.fetch('decision_message').b)
    assert_equal DECISION_MESSAGE_SHA256, decision.fetch('decision_message_sha256')
    assert_equal "codex_thread:01a02b58-641d-7090-aad4-00871c7ddf47#decision-message-sha256:#{DECISION_MESSAGE_SHA256}", decision.fetch('decision_reference')
    assert_nil decision.fetch('source_message_at')

    platform = decision.fetch('platform_attribution')
    assert_equal PLATFORM_ATTRIBUTION_KEYS, platform.keys
    assert_equal 'not_asserted_unavailable', platform.fetch('status')
    PLATFORM_ATTRIBUTION_KEYS.drop(1).each { |key| assert_nil platform.fetch(key), key }
  end

  def test_all_bound_predecessors_are_exact_and_immutable
    bindings = @decision.fetch('source_bindings')
    assert_equal SOURCE_PATHS.keys, bindings.map { |binding| binding.fetch('role') }
    bindings.each do |binding|
      assert_equal SOURCE_KEYS, binding.keys
      role = binding.fetch('role')
      assert_equal SOURCE_PATHS.fetch(role), binding.fetch('path')
      assert_equal SOURCE_HASHES.fetch(role), binding.fetch('sha256')
      path = File.join(ROOT, binding.fetch('path'))
      assert File.file?(path), path
      refute File.symlink?(path), path
      assert_equal 1, File.stat(path).nlink, path
      assert_equal SOURCE_HASHES.fetch(role), Digest::SHA256.file(path).hexdigest
    end
  end

  def test_revocation_retires_only_the_exact_wording_path_and_does_not_approve_adr_019
    revocation = @decision.fetch('revocation')
    assert_equal REVOCATION_KEYS, revocation.keys
    assert_equal 'adr_019_exact_wording_product_owner_reply_requirement', revocation.fetch('revoked_requirement')
    assert_equal SOURCE_HASHES.fetch('retired_exact_wording_approval_draft'), revocation.fetch('retired_draft_sha256')
    historical_draft = parse_json(File.binread(File.join(ROOT, SOURCE_PATHS.fetch('retired_exact_wording_approval_draft'))))
    assert_equal Digest::SHA256.hexdigest(historical_draft.fetch('requested_reply')), revocation.fetch('retired_requested_reply_sha256')
    assert_equal 'exact_wording_constraint_removed_for_any_future_adr_019_consideration_without_satisfying_approving_reinterpreting_or_mutating_the_historical_draft', revocation.fetch('retirement_effect')
    assert_equal 'remains_proposed_not_approved_not_authoritative_no_effect', revocation.fetch('adr_019_effect')
    assert_equal 'remains_unapproved_unprovisioned_and_not_authoritative', revocation.fetch('external_trust_effect')

    adr_019 = File.binread(File.join(ROOT, SOURCE_PATHS.fetch('proposed_adr_019')))
    assert_includes adr_019, 'Status: **PROPOSED / NOT APPROVED / NOT AUTHORITATIVE / NO EFFECT**'
    assert_equal 'pending_exact_attributable_product_owner_approval_and_separate_review', historical_draft.fetch('status')
    assert_equal 'none_draft_only_non_authoritative_no_activation_or_implementation_authority', historical_draft.fetch('effect')
  end

  def test_only_existing_local_governance_and_narrow_simplification_authority_are_true
    continuing = @decision.fetch('continuing_authority')
    assert_equal CONTINUING_AUTHORITY_KEYS, continuing.keys
    assert_equal 'continues_under_exact_gate_a_adoption_and_adr_018_boundary', continuing.fetch('governance_v2_local_implementation')
    assert_equal 'authorized_only_as_local_working_tree_governance_v2_implementation_under_the_same_boundary', continuing.fetch('governance_process_simplification')
    assert_equal 'none_beyond_local_governance_v2_process_simplification', continuing.fetch('authority_expansion')

    authorization = @decision.fetch('authorization')
    assert_equal AUTHORIZATION_KEYS, authorization.keys
    assert_equal true, authorization.fetch('governance_v2_local_implementation')
    assert_equal true, authorization.fetch('governance_process_simplification_local_implementation')
    prohibited = AUTHORIZATION_KEYS - %w[
      governance_v2_local_implementation governance_process_simplification_local_implementation
    ]
    prohibited.each { |key| assert_equal false, authorization.fetch(key), key }
  end

  def test_activation_owner_gate_release_and_domain_state_are_unchanged
    preserved = @decision.fetch('preserved_state')
    assert_equal PRESERVED_STATE_KEYS, preserved.keys
    assert_equal 'unchanged_and_not_reinterpreted', preserved.fetch('immutable_predecessors')
    assert_equal 'pending_not_approval_ready_no_effect', preserved.fetch('activation_decision_draft')
    assert_equal 'no_effect_no_creation_or_mutation_authorized', preserved.fetch('consumer_pointer')
    assert_equal 'no_effect_no_creation_or_mutation_authorized', preserved.fetch('consumer_selection')
    assert_equal 'no_effect_separate_owner_decisions_required', preserved.fetch('owner_dispositions')
    assert_equal 'no_effect_no_closure_or_pass_claim', preserved.fetch('g0')
    assert_equal 'no_effect_no_acceptance_or_pass_claim', preserved.fetch('g3')
    assert_equal 'no_effect_separate_decisions_required', preserved.fetch('deployment_and_domain_acceptance')

    activation = parse_json(File.binread(File.join(ROOT, SOURCE_PATHS.fetch('activation_decision_draft'))))
    assert_equal 'pending_gate_b_decision_preparation_not_approval_ready', activation.fetch('status')
    assert_equal 'none_draft_only_no_consumer_activation_or_canonical_mutation', activation.fetch('effect')
    assert activation.fetch('authorization').values.none?
  end

  def test_immutable_secret_free_and_closed_at_every_level
    assert_equal({
      'record_mutable' => false,
      'correction_method' => 'append_new_hash_bound_successor_decision'
    }, @decision.fetch('immutability'))
    assert_equal SECRET_HANDLING, @decision.fetch('secret_handling')
    assert_equal REVOCATION_KEYS, @decision.fetch('revocation').keys
    assert_equal CONTINUING_AUTHORITY_KEYS, @decision.fetch('continuing_authority').keys
    assert_equal AUTHORIZATION_KEYS, @decision.fetch('authorization').keys
    assert_equal PRESERVED_STATE_KEYS, @decision.fetch('preserved_state').keys
    refute_match(/(?:password\s*[=:]|api[_-]?key\s*[=:]|bearer\s+[a-z0-9._-]{12,}|postgres(?:ql)?:\/\/|mysql:\/\/)/i, @raw)
    assert @raw.end_with?("\n")
    refute @raw.end_with?("\n\n")
  end

  def test_duplicate_keys_are_rejected
    duplicate = @raw.sub(
      '"schema_version": 1,',
      '"schema_version": 1, "schema_version": 1,'
    )
    assert_raises(JSON::ParserError) { parse_json(duplicate) }
  end

  private

  def parse_json(bytes)
    JSON.parse(bytes, object_class: DuplicateKeyRejectingHash)
  end
end
