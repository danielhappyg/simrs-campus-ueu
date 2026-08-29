#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'open3'
require 'pathname'
require 'time'

# Shared, read-only validation primitives for proportional G0 governance v2.
#
# This namespace intentionally does not depend on, subclass, or add branches to
# ParityGovernanceValidator. Governance v1 remains historical evidence with its
# own unchanged validator. Mutation, generation, migration, and consumer
# selection belong to later waves and are deliberately absent here.
# Project-G0 PASS derivation is also intentionally absent until the expanded
# 268-row validator can independently resolve canonical order, owner authority,
# evidence, review, and source hashes; this core never trusts caller-supplied
# booleans to promote a gate.
module G0ProportionalGovernanceV2
  class Error < StandardError; end
  class ParseError < Error; end
  class ValidationError < Error; end

  SHA256_PATTERN = /\A[0-9a-f]{64}\z/.freeze
  SAFE_RELATIVE_PATH_PATTERN = /\A(?!\/)(?!.*(?:\A|\/)\.\.(?:\/|\z))[^\0]+\z/.freeze
  CONTRACT_CANONICAL_SHA256 = 'c39a585227e50415d40c92a466e9547b622deee289e4ad2d8058f638b60f7028'

  # JSON's object_class hook is invoked for every object, including nested
  # objects. Rejecting a repeated assignment here therefore closes duplicate
  # keys recursively rather than only at the document root.
  class DuplicateKeyHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON object key #{key.inspect}" if key?(key)

      super
    end
  end

  module_function

  def parse_json(source, label: 'JSON')
    JSON.parse(source, object_class: DuplicateKeyHash, create_additions: false)
  rescue JSON::ParserError => e
    raise ParseError, "#{label}: #{e.message}"
  end

  def parse_json_file(path, label: nil)
    parse_json(File.binread(path), label: label || path.to_s)
  rescue SystemCallError => e
    raise ValidationError, "#{label || path}: cannot read artifact (#{e.class})"
  end

  def canonical_json(value)
    JSON.generate(canonical_value(value))
  end

  def canonical_sha256(value)
    Digest::SHA256.hexdigest(canonical_json(value))
  end

  def canonical_value(value, path = '$')
    case value
    when Hash
      unless value.keys.all? { |key| key.is_a?(String) }
        raise ValidationError, "#{path}: canonical JSON object keys must be strings"
      end

      value.keys.sort.each_with_object({}) do |key, normalized|
        normalized[key] = canonical_value(value.fetch(key), "#{path}.#{key}")
      end
    when Array
      value.each_with_index.map { |entry, index| canonical_value(entry, "#{path}[#{index}]") }
    when String, Integer, TrueClass, FalseClass, NilClass
      value
    when Float
      raise ValidationError, "#{path}: non-finite number is not canonical JSON" unless value.finite?

      value
    else
      raise ValidationError, "#{path}: unsupported canonical JSON type #{value.class}"
    end
  end

  def assert_closed_schema!(value, required:, optional: [], label: '$')
    assert_type!(value, Hash, label: label)
    unless required.is_a?(Array) && optional.is_a?(Array) &&
           (required + optional).all? { |key| key.is_a?(String) } &&
           (required & optional).empty? && (required + optional).uniq.length == required.length + optional.length
      raise ArgumentError, 'schema keys must be unique string arrays with no required/optional overlap'
    end

    actual = value.keys
    unknown = actual - required - optional
    missing = required - actual
    raise ValidationError, "#{label}: unknown fields #{unknown.sort.join(', ')}" unless unknown.empty?
    raise ValidationError, "#{label}: missing fields #{missing.join(', ')}" unless missing.empty?

    true
  end

  def assert_type!(value, expected, label: '$')
    valid = if expected == :boolean
              value == true || value == false
            elsif expected == :sha256
              value.is_a?(String) && SHA256_PATTERN.match?(value)
            elsif expected == :nonempty_string
              value.is_a?(String) && !value.strip.empty?
            elsif expected.is_a?(Array)
              expected.any? { |candidate| type_matches?(value, candidate) }
            else
              value.is_a?(expected)
            end
    return true if valid

    description = expected.is_a?(Array) ? expected.join(' or ') : expected
    raise ValidationError, "#{label}: expected #{description}, got #{value.class}"
  end

  def type_matches?(value, expected)
    case expected
    when :boolean then value == true || value == false
    when :sha256 then value.is_a?(String) && SHA256_PATTERN.match?(value)
    when :nonempty_string then value.is_a?(String) && !value.strip.empty?
    when Class then value.is_a?(expected)
    else false
    end
  end
  private_class_method :type_matches?

  # Reports locations only. Secret values must never be copied into diagnostics.
  def secret_locations(value, path: '$')
    locations = []
    walk_json(value, path) do |entry, location, key|
      if key && sensitive_key?(key) && !empty_secret_value?(entry)
        locations << location
      elsif entry.is_a?(String) && secret_like_string?(entry)
        locations << location
      end
    end
    locations.uniq
  end

  def assert_secret_free!(value, label: '$')
    locations = secret_locations(value, path: label)
    return true if locations.empty?

    raise ValidationError, "#{label}: secret-like content at #{locations.join(', ')}"
  end

  CONTRACT_KEYS = %w[
    artifact_type schema_version contract_id profile validator adopted_sources
    boundary universe closed_values governance_transitions owner_outcome_rules
    tier_derivation authority_rules family_decision_rules
    correction_history_rules consumer_operation_decision_contract gate_rules
    separation_rules
  ].freeze
  CONTRACT_VALIDATOR_KEYS = %w[
    contract_name version closed_schema unknown_fields duplicate_keys wrong_types
    secret_like_content
  ].freeze
  CONTRACT_SOURCE_KEYS = %w[path sha256].freeze
  CONTRACT_BOUNDARY_KEYS = %w[
    data_boundary required_app_mode real_patient_data_authorized
    live_integrations_authorized prohibited_live_integrations
    v2_decision_can_override_boundary future_boundary_change_requires
  ].freeze
  CONTRACT_UNIVERSE_KEYS = %w[
    required_capability_count requirement_id_pattern canonical_order_source
    dependency_order stable_identity_required exactly_one_expanded_row_per_capability
    missing_ids duplicate_ids unknown_ids
  ].freeze
  CONTRACT_CLOSED_VALUES_KEYS = %w[
    batches governance_states terminal_governance_states
    nonterminal_governance_states owner_states owner_outcomes
    canonical_dispositions engineering_states evidence_states acceptance_states
    tiers consequence_flags t2_consequence_flags t3_consequence_flags
    t2_independent_review_trigger_flags authority_capacities
    accepted_attribution_methods
  ].freeze
  CONTRACT_TRANSITION_KEYS = %w[from to].freeze
  CONTRACT_OUTCOME_RULE_KEYS = %w[
    owner_outcome allowed_dispositions governance_state g0_terminal
    required_deferral_fields
  ].freeze
  CONTRACT_TIER_KEYS = %w[
    precedence_high_to_low t3_when_any_true t2_when_any_true_and_no_t3
    t1_when_all_false declared_tier_must_equal_derived_tier every_flag_required
    flag_values_must_be_boolean unknown_flags conflicting_declarations
  ].freeze
  CONTRACT_AUTHORITY_KEYS = %w[
    all_tiers_require t1 t2 t3 independent_reviewer_must_be_distinct_from
    product_and_domain_capacity_same_person non_owner_substitutes_forbidden
    invalid_authority_records invalid_authority_effect
  ].freeze
  CONTRACT_T1_KEYS = %w[tier require_all_materially_affected_co_owners independent_review].freeze
  CONTRACT_T23_KEYS = %w[
    tier require_all_materially_affected_co_owners independent_review
    independent_control_authority_capacity_required
  ].freeze
  CONTRACT_SAME_PERSON_KEYS = %w[
    allowed_only_when_both_capacities_and_scopes_explicitly_recorded
    counts_as_two_people_for_quorum
  ].freeze
  CONTRACT_FAMILY_KEYS = %w[
    covered_requirement_ids_must_be_enumerated required_identical_dimensions
    family_tier_rule mixed_risk_member_requires_per_row_exception
    per_row_exception_requires_own_owner_record
    per_row_exception_overrides_family_default
    expanded_rows_must_retain_complete_consequence_map silent_inheritance
  ].freeze
  CONTRACT_CORRECTION_KEYS = %w[
    append_only predecessor_event_sha256_required supersession_reference_required
    forked_history broken_history historical_bytes_mutable
  ].freeze
  CONTRACT_CONSUMER_OPERATION_KEYS = %w[
    schema_version artifact_type status effect data_boundary exact_top_level_keys
    nested_exact_keys allowed_operations allowed_environments
    required_attribution_method gate_a_decider_binding_rules decision_message_rules
    technical_evidence_reference_rules approval_evidence_contract
    semantic_evidence_contracts canonical_trust_state timestamp_and_expiry_rules
  ].freeze
  CONSUMER_OPERATION_DECISION_KEYS = %w[
    artifact_type schema_version decision_id status effect data_boundary
    operation environment actor conditions decided_at expires_at
    adoption_decision approval_evidence prior_state candidate_bundle held_selection recover_outcome
    decision_attribution technical_evidence
  ].freeze
  CONSUMER_OPERATION_ACTOR_KEYS = %w[identity authority_capacity decision_thread_id].freeze
  CONSUMER_OPERATION_PRIOR_STATE_KEYS = %w[
    prior_state_reason expected_prior_pointer_sha256
    observed_unreadable_pointer_sha256
  ].freeze
  CONSUMER_OPERATION_REFERENCE_KEYS = %w[path sha256].freeze
  CONSUMER_OPERATION_ATTRIBUTION_KEYS = %w[
    decision_reference decision_message decision_message_encoding
    decision_message_sha256 source_message_at recorded_at recorded_at_basis
  ].freeze
  CONSUMER_OPERATION_TECHNICAL_EVIDENCE_KEYS = %w[
    gate_a_adoption local_observation candidate_bundle canonical_preflight
    validator_contract selector_source independent_review
  ].freeze
  CONTRACT_CONSUMER_NESTED_KEYS = %w[
    actor prior_state reference decision_attribution technical_evidence
  ].freeze
  CONTRACT_DECISION_MESSAGE_RULE_KEYS = %w[
    encoding minimum_bytes sha256_basis reference_binding
    reference_hash_fragment_prefix
  ].freeze
  CONTRACT_TECHNICAL_EVIDENCE_RULE_KEYS = %w[
    all_exact_fields_required repository_relative_paths_only
    regular_files_or_candidate_bundle_manifest_only sha256_basis
  ].freeze
  CONTRACT_GATE_A_DECIDER_RULE_KEYS = %w[
    adoption_source actor_identity_source actor_capacity_source thread_id_source
    required_capacity arbitrary_actor_or_thread
  ].freeze
  CONTRACT_APPROVAL_EVIDENCE_KEYS = %w[
    artifact_type schema_version status effect exact_top_level_keys scope_exact_keys
    only_consumer_selection_operation_true cross_equal_operation_fields
  ].freeze
  CONSUMER_APPROVAL_EVIDENCE_KEYS = %w[
    artifact_type schema_version approval_id status effect data_boundary operation
    environment actor conditions decided_at expires_at prior_state candidate_bundle
    held_selection recover_outcome decision_attribution scope
  ].freeze
  CONSUMER_APPROVAL_SCOPE_KEYS = %w[
    consumer_selection_operation candidate_retention capability_disposition
    slice_implementation deployment hosted_migration real_patient_data
    live_integration domain_acceptance g0_closure g3_acceptance
  ].freeze
  CONSUMER_APPROVAL_CROSS_EQUAL_KEYS = %w[
    status effect data_boundary operation environment actor conditions decided_at
    expires_at prior_state candidate_bundle held_selection recover_outcome
    decision_attribution
  ].freeze
  CONTRACT_SEMANTIC_EVIDENCE_KEYS = %w[
    schema_version common_exact_keys local_observation_artifact_type
    local_observation_effect canonical_preflight_artifact_type canonical_preflight_effect
    independent_review_artifact_type independent_review_effect status authority_effect
    canonical_preflight_check_keys independent_reviewer_exact_keys
    independent_reviewed_evidence_exact_keys independent_reviewer_capacity
    independent_review_verdict fresh_at_decision_time
  ].freeze
  CONTRACT_CANONICAL_TRUST_STATE_KEYS = %w[
    state canonical_environment canonical_operation_policy
    repository_local_approval_artifacts_sufficient required_external_attestations
    trust_anchor_status signature_implementation_authorized fixture_environment
    fixture_requires_existing_test_guard
  ].freeze
  CONSUMER_EVIDENCE_COMMON_KEYS = %w[
    artifact_type schema_version evidence_id status effect data_boundary environment
    root observed_at expires_at candidate_bundle validator_contract selector_source
    prior_state authority_effect
  ].freeze
  CONSUMER_PREFLIGHT_CHECK_KEYS = %w[
    candidate_retained_direct_child candidate_manifest_hash_valid
    validator_contract_hash_valid selector_source_hash_valid prior_state_matches
    no_authority_effect filesystem_capabilities_supported no_test_controls
  ].freeze
  CONSUMER_REVIEWER_KEYS = %w[identity capacity].freeze
  CONSUMER_REVIEWED_EVIDENCE_KEYS = %w[local_observation canonical_preflight].freeze
  CONSUMER_INDEPENDENT_REVIEW_KEYS = (
    CONSUMER_EVIDENCE_COMMON_KEYS + %w[reviewer reviewed_evidence verdict]
  ).freeze
  CONTRACT_TIMESTAMP_RULE_KEYS = %w[
    format source_message_at_nullable_when_unavailable recorded_at_required
    recorded_at_not_before_source_message_at_when_present
    decided_at_must_equal_source_message_at expires_at_strictly_after_decided_at
    maximum_decision_ttl_seconds maximum_evidence_ttl_seconds
    evidence_expiry_valid_when evidence_observation_order
    authorization_valid_when
  ].freeze
  CONSUMER_OPERATION_ALLOWED_OPERATIONS = %w[activate rollback disable recover].freeze
  CONSUMER_OPERATION_ALLOWED_ENVIRONMENTS = %w[
    isolated_test_fixture local_canonical_checkout
  ].freeze
  CONSUMER_OPERATION_ATTRIBUTION_METHOD = 'approved_immutable_ticket_or_workflow_record'
  CONSUMER_OPERATION_MESSAGE_ENCODING = 'exact_utf8_bytes_no_normalization'
  CONSUMER_OPERATION_MESSAGE_REFERENCE_PREFIX = '#decision-message-sha256:'
  CONSUMER_OPERATION_BUNDLE_MANIFEST = 'G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json'
  CONSUMER_MAXIMUM_DECISION_TTL_SECONDS = 7200
  CONSUMER_MAXIMUM_EVIDENCE_TTL_SECONDS = 7200
  CONSUMER_SELECTOR_SOURCE_PATH = 'scripts/select-g0-governance-consumer.rb'
  CONSUMER_VALIDATOR_CONTRACT_PATH = 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json'
  CONTRACT_GATE_KEYS = %w[
    project_g0_open_value project_g0_pass_value project_g0_pass_requires
    incomplete_or_invalid_project_g0 slice_authorization_closes_project_g0
    authorized_slice_authorizes_only_bounded_synthetic_build
    authorized_slice_does_not_authorize g3_requires_g0_pass
    g3_additional_requirements
  ].freeze
  CONTRACT_SEPARATION_KEYS = %w[
    state_dimensions_are_independent owner_approval_proves_implementation
    implementation_proves_owner_acceptance deployment_proves_g3
    engineering_overlay_can_confer_owner_authority
    engineering_overlay_can_promote_g0 engineering_overlay_can_promote_g3
    adoption_authorizes_consumer_activation adoption_authorizes_capability_disposition
    adoption_authorizes_slice_implementation adoption_authorizes_deployment
    adoption_authorizes_g3_acceptance
  ].freeze

  def validate_contract!(contract, root:)
    assert_closed_schema!(contract, required: CONTRACT_KEYS, label: '$.contract')
    assert_secret_free!(contract, label: '$.contract')
    assert_type!(root, :nonempty_string, label: '$.root')
    root_path = Pathname.new(root).expand_path
    raise ValidationError, '$.root: must be an existing directory' unless root_path.directory?

    unless contract.fetch('artifact_type') == 'g0_governance_v2_contract' &&
           contract.fetch('schema_version') == 1 && contract.fetch('contract_id') == 'G0-GOVERNANCE-V2-CONTRACT-1' &&
           contract.fetch('profile') == 'v2'
      raise ValidationError, '$.contract: wrong artifact identity, schema version, or profile'
    end

    validator = contract.fetch('validator')
    assert_closed_schema!(validator, required: CONTRACT_VALIDATOR_KEYS, label: '$.contract.validator')
    unless validator.fetch('contract_name') == 'g0_proportional_governance_v2' && validator.fetch('version') == '1.3.0' &&
           validator.fetch('closed_schema') == true &&
           validator.values_at('unknown_fields', 'duplicate_keys', 'wrong_types') == %w[reject reject reject] &&
           validator.fetch('secret_like_content') == 'reject_without_echoing_value'
      raise ValidationError, '$.contract.validator: fail-closed controls changed'
    end

    sources = contract.fetch('adopted_sources')
    assert_closed_schema!(sources, required: %w[proposal architecture adoption_decision], label: '$.contract.adopted_sources')
    sources.each do |name, source|
      source_label = "$.contract.adopted_sources.#{name}"
      assert_closed_schema!(source, required: CONTRACT_SOURCE_KEYS, label: source_label)
      path = source.fetch('path')
      unless path.is_a?(String) && SAFE_RELATIVE_PATH_PATTERN.match?(path)
        raise ValidationError, "#{source_label}.path: unsafe repository-relative path"
      end
      assert_type!(source.fetch('sha256'), :sha256, label: "#{source_label}.sha256")
      full_path = safe_regular_file_under_root!(root_path, path, label: source_label)
      unless Digest::SHA256.file(full_path).hexdigest == source.fetch('sha256')
        raise ValidationError, "#{source_label}: adopted source hash mismatch"
      end
    end

    validate_contract_boundary!(contract.fetch('boundary'))
    validate_contract_universe!(contract.fetch('universe'))
    validate_contract_closed_values!(contract.fetch('closed_values'))
    validate_contract_transitions!(contract)
    validate_contract_outcomes!(contract)
    validate_contract_tier_rules!(contract)
    validate_contract_authority_rules!(contract)
    validate_contract_family_rules!(contract.fetch('family_decision_rules'))
    validate_contract_correction_rules!(contract.fetch('correction_history_rules'))
    validate_contract_consumer_operation_rules!(contract)
    validate_contract_gate_rules!(contract.fetch('gate_rules'))
    validate_contract_separation_rules!(contract.fetch('separation_rules'))
    unless canonical_sha256(contract) == CONTRACT_CANONICAL_SHA256
      raise ValidationError, '$.contract: semantic drift from accepted machine contract'
    end
    true
  end

  def validate_contract_boundary!(boundary)
    assert_closed_schema!(boundary, required: CONTRACT_BOUNDARY_KEYS, label: '$.contract.boundary')
    %w[real_patient_data_authorized live_integrations_authorized v2_decision_can_override_boundary].each do |key|
      assert_type!(boundary.fetch(key), :boolean, label: "$.contract.boundary.#{key}")
    end
    unless boundary.fetch('data_boundary') == 'synthetic_only' && boundary.fetch('required_app_mode') == 'SIMULATION' &&
           boundary.values_at('real_patient_data_authorized', 'live_integrations_authorized', 'v2_decision_can_override_boundary').all?(false)
      raise ValidationError, '$.contract.boundary: synthetic/no-live boundary changed'
    end
    assert_unique_string_array!(boundary.fetch('prohibited_live_integrations'), label: '$.contract.boundary.prohibited_live_integrations', allow_empty: false)
    assert_unique_string_array!(boundary.fetch('future_boundary_change_requires'), label: '$.contract.boundary.future_boundary_change_requires', allow_empty: false)
    unless boundary.fetch('prohibited_live_integrations') == PROHIBITED_LIVE_INTEGRATIONS &&
           boundary.fetch('future_boundary_change_requires') == %w[separate_governance_instrument_outside_v2 explicit_user_authorization]
      raise ValidationError, '$.contract.boundary: prohibited integrations or future-change authority changed'
    end
  end
  private_class_method :validate_contract_boundary!

  def validate_contract_universe!(universe)
    assert_closed_schema!(universe, required: CONTRACT_UNIVERSE_KEYS, label: '$.contract.universe')
    unless universe.fetch('required_capability_count') == 268 && universe.fetch('requirement_id_pattern') == '\\APAR-[A-Z]+-[0-9]{3}\\z'
      raise ValidationError, '$.contract.universe: closed 268-capability identity contract changed'
    end
    unless universe.fetch('canonical_order_source') == 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json'
      raise ValidationError, '$.contract.universe.canonical_order_source: unexpected canonical source'
    end
    assert_unique_string_array!(universe.fetch('dependency_order'), label: '$.contract.universe.dependency_order', allow_empty: false)
    unless universe.fetch('dependency_order') == ('A'..'G').to_a
      raise ValidationError, '$.contract.universe.dependency_order: expected A through G'
    end
    %w[stable_identity_required exactly_one_expanded_row_per_capability].each do |key|
      raise ValidationError, "$.contract.universe.#{key}: must be true" unless universe.fetch(key) == true
    end
    %w[missing_ids duplicate_ids unknown_ids].each do |key|
      raise ValidationError, "$.contract.universe.#{key}: must reject" unless universe.fetch(key) == 'reject'
    end
  end
  private_class_method :validate_contract_universe!

  def validate_contract_closed_values!(closed)
    assert_closed_schema!(closed, required: CONTRACT_CLOSED_VALUES_KEYS, label: '$.contract.closed_values')
    CONTRACT_CLOSED_VALUES_KEYS.each do |key|
      assert_unique_string_array!(closed.fetch(key), label: "$.contract.closed_values.#{key}", allow_empty: false)
    end
    unless closed.fetch('batches') == ('A'..'G').to_a && closed.fetch('tiers') == %w[T1_STANDARD T2_DOMAIN_CRITICAL T3_INDEPENDENT_CONTROL]
      raise ValidationError, '$.contract.closed_values: batch or tier order changed'
    end
    unless (closed.fetch('terminal_governance_states') + closed.fetch('nonterminal_governance_states')).sort == closed.fetch('governance_states').sort &&
           (closed.fetch('terminal_governance_states') & closed.fetch('nonterminal_governance_states')).empty?
      raise ValidationError, '$.contract.closed_values: terminal/nonterminal state partition is invalid'
    end
    flags = closed.fetch('consequence_flags')
    unless (closed.fetch('t2_consequence_flags') + closed.fetch('t3_consequence_flags')) == flags &&
           (closed.fetch('t2_consequence_flags') & closed.fetch('t3_consequence_flags')).empty? &&
           (closed.fetch('t2_independent_review_trigger_flags') - closed.fetch('t2_consequence_flags')).empty?
      raise ValidationError, '$.contract.closed_values: consequence flag partition is invalid'
    end
    unless closed.fetch('authority_capacities').include?('independent_control_authority')
      raise ValidationError, '$.contract.closed_values.authority_capacities: missing independent control capacity'
    end
  end
  private_class_method :validate_contract_closed_values!

  def validate_contract_transitions!(contract)
    transitions = contract.fetch('governance_transitions')
    assert_type!(transitions, Array, label: '$.contract.governance_transitions')
    states = contract.fetch('closed_values').fetch('governance_states')
    from_states = []
    transitions.each_with_index do |transition, index|
      label = "$.contract.governance_transitions[#{index}]"
      assert_closed_schema!(transition, required: CONTRACT_TRANSITION_KEYS, label: label)
      assert_type!(transition.fetch('from'), :nonempty_string, label: "#{label}.from")
      assert_unique_string_array!(transition.fetch('to'), label: "#{label}.to", allow_empty: false)
      raise ValidationError, "#{label}: unknown state" unless states.include?(transition.fetch('from')) && (transition.fetch('to') - states).empty?
      raise ValidationError, "#{label}.from: duplicate transition source" if from_states.include?(transition.fetch('from'))
      from_states << transition.fetch('from')
    end
    terminal = contract.fetch('closed_values').fetch('terminal_governance_states')
    unless (from_states & terminal).empty?
      raise ValidationError, '$.contract.governance_transitions: terminal state cannot have outgoing transition'
    end
  end
  private_class_method :validate_contract_transitions!

  def validate_contract_outcomes!(contract)
    rules = contract.fetch('owner_outcome_rules')
    assert_type!(rules, Array, label: '$.contract.owner_outcome_rules')
    outcomes = contract.fetch('closed_values').fetch('owner_outcomes')
    dispositions = contract.fetch('closed_values').fetch('canonical_dispositions')
    states = contract.fetch('closed_values').fetch('governance_states')
    rules.each_with_index do |rule, index|
      label = "$.contract.owner_outcome_rules[#{index}]"
      assert_closed_schema!(rule, required: CONTRACT_OUTCOME_RULE_KEYS, label: label)
      raise ValidationError, "#{label}.owner_outcome: unknown" unless outcomes.include?(rule.fetch('owner_outcome'))
      assert_unique_string_array!(rule.fetch('allowed_dispositions'), label: "#{label}.allowed_dispositions")
      raise ValidationError, "#{label}.allowed_dispositions: unknown" unless (rule.fetch('allowed_dispositions') - dispositions).empty?
      raise ValidationError, "#{label}.governance_state: unknown" unless states.include?(rule.fetch('governance_state'))
      assert_type!(rule.fetch('g0_terminal'), :boolean, label: "#{label}.g0_terminal")
      assert_unique_string_array!(rule.fetch('required_deferral_fields'), label: "#{label}.required_deferral_fields")
    end
  end
  private_class_method :validate_contract_outcomes!

  def validate_contract_tier_rules!(contract)
    rules = contract.fetch('tier_derivation')
    assert_closed_schema!(rules, required: CONTRACT_TIER_KEYS, label: '$.contract.tier_derivation')
    unless rules.fetch('precedence_high_to_low') == contract.fetch('closed_values').fetch('tiers').reverse &&
           rules.fetch('t3_when_any_true') == 'closed_values.t3_consequence_flags' &&
           rules.fetch('t2_when_any_true_and_no_t3') == 'closed_values.t2_consequence_flags' &&
           rules.fetch('t1_when_all_false') == 'closed_values.consequence_flags'
      raise ValidationError, '$.contract.tier_derivation: precedence or flag source changed'
    end
    %w[declared_tier_must_equal_derived_tier every_flag_required flag_values_must_be_boolean].each do |key|
      raise ValidationError, "$.contract.tier_derivation.#{key}: must be true" unless rules.fetch(key) == true
    end
    %w[unknown_flags conflicting_declarations].each do |key|
      raise ValidationError, "$.contract.tier_derivation.#{key}: must reject" unless rules.fetch(key) == 'reject'
    end
  end
  private_class_method :validate_contract_tier_rules!

  def validate_contract_authority_rules!(contract)
    rules = contract.fetch('authority_rules')
    assert_closed_schema!(rules, required: CONTRACT_AUTHORITY_KEYS, label: '$.contract.authority_rules')
    assert_unique_string_array!(rules.fetch('all_tiers_require'), label: '$.contract.authority_rules.all_tiers_require', allow_empty: false)
    assert_closed_schema!(rules.fetch('t1'), required: CONTRACT_T1_KEYS, label: '$.contract.authority_rules.t1')
    %w[t2 t3].each { |key| assert_closed_schema!(rules.fetch(key), required: CONTRACT_T23_KEYS, label: "$.contract.authority_rules.#{key}") }
    assert_closed_schema!(rules.fetch('product_and_domain_capacity_same_person'), required: CONTRACT_SAME_PERSON_KEYS, label: '$.contract.authority_rules.product_and_domain_capacity_same_person')
    assert_unique_string_array!(rules.fetch('independent_reviewer_must_be_distinct_from'), label: '$.contract.authority_rules.independent_reviewer_must_be_distinct_from', allow_empty: false)
    assert_unique_string_array!(rules.fetch('non_owner_substitutes_forbidden'), label: '$.contract.authority_rules.non_owner_substitutes_forbidden', allow_empty: false)
    assert_unique_string_array!(rules.fetch('invalid_authority_records'), label: '$.contract.authority_rules.invalid_authority_records', allow_empty: false)
    unless rules.fetch('t1').fetch('tier') == contract.fetch('closed_values').fetch('tiers').fetch(0) &&
           rules.fetch('t2').fetch('tier') == contract.fetch('closed_values').fetch('tiers').fetch(1) &&
           rules.fetch('t3').fetch('tier') == contract.fetch('closed_values').fetch('tiers').fetch(2) &&
           rules.fetch('t3').fetch('independent_control_authority_capacity_required') == true &&
           rules.fetch('invalid_authority_effect') == 'fail_closed'
      raise ValidationError, '$.contract.authority_rules: tier authority rule changed'
    end
  end
  private_class_method :validate_contract_authority_rules!

  def validate_contract_family_rules!(rules)
    assert_closed_schema!(rules, required: CONTRACT_FAMILY_KEYS, label: '$.contract.family_decision_rules')
    assert_unique_string_array!(rules.fetch('required_identical_dimensions'), label: '$.contract.family_decision_rules.required_identical_dimensions', allow_empty: false)
    %w[covered_requirement_ids_must_be_enumerated mixed_risk_member_requires_per_row_exception per_row_exception_requires_own_owner_record per_row_exception_overrides_family_default expanded_rows_must_retain_complete_consequence_map].each do |key|
      raise ValidationError, "$.contract.family_decision_rules.#{key}: must be true" unless rules.fetch(key) == true
    end
    unless rules.fetch('family_tier_rule') == 'maximum_derived_member_tier' && rules.fetch('silent_inheritance') == 'reject'
      raise ValidationError, '$.contract.family_decision_rules: tier or inheritance rule changed'
    end
  end
  private_class_method :validate_contract_family_rules!

  def validate_contract_correction_rules!(rules)
    assert_closed_schema!(rules, required: CONTRACT_CORRECTION_KEYS, label: '$.contract.correction_history_rules')
    %w[append_only predecessor_event_sha256_required supersession_reference_required].each do |key|
      raise ValidationError, "$.contract.correction_history_rules.#{key}: must be true" unless rules.fetch(key) == true
    end
    unless rules.values_at('forked_history', 'broken_history') == %w[reject reject] && rules.fetch('historical_bytes_mutable') == false
      raise ValidationError, '$.contract.correction_history_rules: fail-closed history rule changed'
    end
  end
  private_class_method :validate_contract_correction_rules!

  def validate_contract_consumer_operation_rules!(contract)
    rules = contract.fetch('consumer_operation_decision_contract')
    label = '$.contract.consumer_operation_decision_contract'
    assert_closed_schema!(rules, required: CONTRACT_CONSUMER_OPERATION_KEYS, label: label)

    unless rules.values_at('schema_version', 'artifact_type', 'status', 'effect', 'data_boundary') == [
      1,
      'g0_governance_v2_consumer_operation_decision',
      'approved',
      'authorizes_one_consumer_selection_operation',
      'synthetic_only'
    ]
      raise ValidationError, "#{label}: operation-decision identity or effect changed"
    end

    assert_exact_string_array!(rules.fetch('exact_top_level_keys'), CONSUMER_OPERATION_DECISION_KEYS,
                               label: "#{label}.exact_top_level_keys")
    nested = rules.fetch('nested_exact_keys')
    assert_closed_schema!(nested, required: CONTRACT_CONSUMER_NESTED_KEYS, label: "#{label}.nested_exact_keys")
    {
      'actor' => CONSUMER_OPERATION_ACTOR_KEYS,
      'prior_state' => CONSUMER_OPERATION_PRIOR_STATE_KEYS,
      'reference' => CONSUMER_OPERATION_REFERENCE_KEYS,
      'decision_attribution' => CONSUMER_OPERATION_ATTRIBUTION_KEYS,
      'technical_evidence' => CONSUMER_OPERATION_TECHNICAL_EVIDENCE_KEYS
    }.each do |key, expected|
      assert_exact_string_array!(nested.fetch(key), expected, label: "#{label}.nested_exact_keys.#{key}")
    end
    assert_exact_string_array!(rules.fetch('allowed_operations'), CONSUMER_OPERATION_ALLOWED_OPERATIONS,
                               label: "#{label}.allowed_operations")
    assert_exact_string_array!(rules.fetch('allowed_environments'), CONSUMER_OPERATION_ALLOWED_ENVIRONMENTS,
                               label: "#{label}.allowed_environments")
    unless rules.fetch('required_attribution_method') == CONSUMER_OPERATION_ATTRIBUTION_METHOD &&
           contract.fetch('closed_values').fetch('accepted_attribution_methods').include?(CONSUMER_OPERATION_ATTRIBUTION_METHOD)
      raise ValidationError, "#{label}.required_attribution_method: not the adopted attribution method"
    end

    binding = rules.fetch('gate_a_decider_binding_rules')
    assert_closed_schema!(binding, required: CONTRACT_GATE_A_DECIDER_RULE_KEYS,
                          label: "#{label}.gate_a_decider_binding_rules")
    unless binding == {
      'adoption_source' => 'adopted_sources.adoption_decision',
      'actor_identity_source' => 'decider.identity',
      'actor_capacity_source' => 'decider.authority_capacity',
      'thread_id_source' => 'decider.decision_reference_codex_thread_prefix',
      'required_capacity' => 'product_owner',
      'arbitrary_actor_or_thread' => 'reject'
    }
      raise ValidationError, "#{label}.gate_a_decider_binding_rules: Gate-A decider binding changed"
    end

    message = rules.fetch('decision_message_rules')
    assert_closed_schema!(message, required: CONTRACT_DECISION_MESSAGE_RULE_KEYS,
                          label: "#{label}.decision_message_rules")
    unless message == {
      'encoding' => CONSUMER_OPERATION_MESSAGE_ENCODING,
      'minimum_bytes' => 1,
      'sha256_basis' => 'exact_decision_message_utf8_bytes',
      'reference_binding' => 'decision_reference_must_end_with_hash_fragment',
      'reference_hash_fragment_prefix' => CONSUMER_OPERATION_MESSAGE_REFERENCE_PREFIX
    }
      raise ValidationError, "#{label}.decision_message_rules: exact byte/hash/reference rules changed"
    end

    evidence = rules.fetch('technical_evidence_reference_rules')
    assert_closed_schema!(evidence, required: CONTRACT_TECHNICAL_EVIDENCE_RULE_KEYS,
                          label: "#{label}.technical_evidence_reference_rules")
    unless evidence == {
      'all_exact_fields_required' => true,
      'repository_relative_paths_only' => true,
      'regular_files_or_candidate_bundle_manifest_only' => true,
      'sha256_basis' => 'exact_referenced_file_bytes'
    }
      raise ValidationError, "#{label}.technical_evidence_reference_rules: evidence binding changed"
    end

    approval = rules.fetch('approval_evidence_contract')
    assert_closed_schema!(approval, required: CONTRACT_APPROVAL_EVIDENCE_KEYS,
                          label: "#{label}.approval_evidence_contract")
    unless approval.values_at('artifact_type', 'schema_version', 'status', 'effect') == [
      'g0_governance_v2_gate_b_operation_approval', 1, 'approved',
      'authorizes_one_consumer_selection_operation'
    ]
      raise ValidationError, "#{label}.approval_evidence_contract: approval identity or effect changed"
    end
    assert_exact_string_array!(approval.fetch('exact_top_level_keys'), CONSUMER_APPROVAL_EVIDENCE_KEYS,
                               label: "#{label}.approval_evidence_contract.exact_top_level_keys")
    assert_exact_string_array!(approval.fetch('scope_exact_keys'), CONSUMER_APPROVAL_SCOPE_KEYS,
                               label: "#{label}.approval_evidence_contract.scope_exact_keys")
    assert_exact_string_array!(approval.fetch('cross_equal_operation_fields'), CONSUMER_APPROVAL_CROSS_EQUAL_KEYS,
                               label: "#{label}.approval_evidence_contract.cross_equal_operation_fields")
    unless approval.fetch('only_consumer_selection_operation_true') == true
      raise ValidationError, "#{label}.approval_evidence_contract: approval scope changed"
    end

    semantic = rules.fetch('semantic_evidence_contracts')
    assert_closed_schema!(semantic, required: CONTRACT_SEMANTIC_EVIDENCE_KEYS,
                          label: "#{label}.semantic_evidence_contracts")
    unless semantic.values_at(
      'schema_version', 'local_observation_artifact_type', 'local_observation_effect',
      'canonical_preflight_artifact_type', 'canonical_preflight_effect',
      'independent_review_artifact_type', 'independent_review_effect', 'status',
      'authority_effect', 'independent_reviewer_capacity', 'independent_review_verdict',
      'fresh_at_decision_time'
    ) == [
      1, 'g0_governance_v2_gate_b_local_observation', 'none_observation_only',
      'g0_governance_v2_gate_b_canonical_preflight', 'none_preflight_only',
      'g0_governance_v2_gate_b_independent_review', 'none_review_evidence_only',
      'PASS', 'none', 'independent_technical_security_reviewer', 'PASS', true
    ]
      raise ValidationError, "#{label}.semantic_evidence_contracts: semantic evidence identity changed"
    end
    assert_exact_string_array!(semantic.fetch('common_exact_keys'), CONSUMER_EVIDENCE_COMMON_KEYS,
                               label: "#{label}.semantic_evidence_contracts.common_exact_keys")
    assert_exact_string_array!(semantic.fetch('canonical_preflight_check_keys'), CONSUMER_PREFLIGHT_CHECK_KEYS,
                               label: "#{label}.semantic_evidence_contracts.canonical_preflight_check_keys")
    assert_exact_string_array!(semantic.fetch('independent_reviewer_exact_keys'), CONSUMER_REVIEWER_KEYS,
                               label: "#{label}.semantic_evidence_contracts.independent_reviewer_exact_keys")
    assert_exact_string_array!(semantic.fetch('independent_reviewed_evidence_exact_keys'),
                               CONSUMER_REVIEWED_EVIDENCE_KEYS,
                               label: "#{label}.semantic_evidence_contracts.independent_reviewed_evidence_exact_keys")

    trust = rules.fetch('canonical_trust_state')
    assert_closed_schema!(trust, required: CONTRACT_CANONICAL_TRUST_STATE_KEYS,
                          label: "#{label}.canonical_trust_state")
    unless trust == {
      'state' => 'unprovisioned_blocked_external_attestation_required',
      'canonical_environment' => 'local_canonical_checkout',
      'canonical_operation_policy' => 'reject_before_lock_probe_marker_or_write',
      'repository_local_approval_artifacts_sufficient' => false,
      'required_external_attestations' => %w[product_owner independent_technical_security_reviewer],
      'trust_anchor_status' => 'absent_not_approved_not_provisioned',
      'signature_implementation_authorized' => false,
      'fixture_environment' => 'isolated_test_fixture',
      'fixture_requires_existing_test_guard' => true
    }
      raise ValidationError, "#{label}.canonical_trust_state: fail-closed external trust state changed"
    end

    timestamps = rules.fetch('timestamp_and_expiry_rules')
    assert_closed_schema!(timestamps, required: CONTRACT_TIMESTAMP_RULE_KEYS,
                          label: "#{label}.timestamp_and_expiry_rules")
    unless timestamps == {
      'format' => 'rfc3339_with_explicit_offset',
      'source_message_at_nullable_when_unavailable' => false,
      'recorded_at_required' => true,
      'recorded_at_not_before_source_message_at_when_present' => true,
      'decided_at_must_equal_source_message_at' => true,
      'expires_at_strictly_after_decided_at' => true,
      'maximum_decision_ttl_seconds' => CONSUMER_MAXIMUM_DECISION_TTL_SECONDS,
      'maximum_evidence_ttl_seconds' => CONSUMER_MAXIMUM_EVIDENCE_TTL_SECONDS,
      'evidence_expiry_valid_when' => 'now_lt_every_semantic_evidence_expires_at',
      'evidence_observation_order' => 'local_observation_lte_canonical_preflight_lte_independent_review_lte_source_message_at',
      'authorization_valid_when' => 'decided_at_lte_now_and_now_lt_expires_at'
    }
      raise ValidationError, "#{label}.timestamp_and_expiry_rules: timestamp or expiry semantics changed"
    end
    true
  end
  private_class_method :validate_contract_consumer_operation_rules!

  def validate_contract_gate_rules!(rules)
    assert_closed_schema!(rules, required: CONTRACT_GATE_KEYS, label: '$.contract.gate_rules')
    assert_unique_string_array!(rules.fetch('project_g0_pass_requires'), label: '$.contract.gate_rules.project_g0_pass_requires', allow_empty: false)
    assert_unique_string_array!(rules.fetch('authorized_slice_does_not_authorize'), label: '$.contract.gate_rules.authorized_slice_does_not_authorize', allow_empty: false)
    assert_unique_string_array!(rules.fetch('g3_additional_requirements'), label: '$.contract.gate_rules.g3_additional_requirements', allow_empty: false)
    unless rules.fetch('project_g0_open_value') == 'OPEN' && rules.fetch('project_g0_pass_value') == 'PASS' &&
           rules.fetch('incomplete_or_invalid_project_g0') == 'OPEN' &&
           rules.fetch('slice_authorization_closes_project_g0') == false &&
           rules.fetch('authorized_slice_authorizes_only_bounded_synthetic_build') == true &&
           rules.fetch('g3_requires_g0_pass') == true
      raise ValidationError, '$.contract.gate_rules: gate separation changed'
    end
  end
  private_class_method :validate_contract_gate_rules!

  def validate_contract_separation_rules!(rules)
    assert_closed_schema!(rules, required: CONTRACT_SEPARATION_KEYS, label: '$.contract.separation_rules')
    assert_unique_string_array!(rules.fetch('state_dimensions_are_independent'), label: '$.contract.separation_rules.state_dimensions_are_independent', allow_empty: false)
    boolean_keys = CONTRACT_SEPARATION_KEYS - ['state_dimensions_are_independent']
    boolean_keys.each do |key|
      assert_type!(rules.fetch(key), :boolean, label: "$.contract.separation_rules.#{key}")
      raise ValidationError, "$.contract.separation_rules.#{key}: must remain false" unless rules.fetch(key) == false
    end
  end
  private_class_method :validate_contract_separation_rules!

  def validate_consumer_operation_decision!(decision, contract:, root:, now: nil, label: '$.operation_decision')
    validate_contract_consumer_operation_rules!(contract)
    assert_closed_schema!(decision, required: CONSUMER_OPERATION_DECISION_KEYS, label: label)
    assert_secret_free!(decision, label: label)

    rules = contract.fetch('consumer_operation_decision_contract')
    unless decision.values_at('schema_version', 'artifact_type', 'status', 'effect', 'data_boundary') == [
      rules.fetch('schema_version'), rules.fetch('artifact_type'), rules.fetch('status'),
      rules.fetch('effect'), rules.fetch('data_boundary')
    ]
      raise ValidationError, "#{label}: operation-decision identity or authority effect is invalid"
    end
    assert_type!(decision.fetch('decision_id'), :nonempty_string, label: "#{label}.decision_id")
    operation = decision.fetch('operation')
    environment = decision.fetch('environment')
    unless CONSUMER_OPERATION_ALLOWED_OPERATIONS.include?(operation)
      raise ValidationError, "#{label}.operation: unknown operation"
    end
    unless CONSUMER_OPERATION_ALLOWED_ENVIRONMENTS.include?(environment)
      raise ValidationError, "#{label}.environment: environment is not allowed"
    end
    if environment == 'local_canonical_checkout'
      trust_state = rules.fetch('canonical_trust_state').fetch('state')
      unless trust_state == 'unprovisioned_blocked_external_attestation_required'
        raise ValidationError, "#{label}.environment: canonical trust state is invalid"
      end
      raise ValidationError, "#{label}.environment: canonical external attestation is unprovisioned"
    end

    actor = decision.fetch('actor')
    assert_closed_schema!(actor, required: CONSUMER_OPERATION_ACTOR_KEYS, label: "#{label}.actor")
    CONSUMER_OPERATION_ACTOR_KEYS.each do |key|
      assert_type!(actor.fetch(key), :nonempty_string, label: "#{label}.actor.#{key}")
    end
    assert_unique_string_array!(decision.fetch('conditions'), label: "#{label}.conditions", allow_empty: false)

    root_path = Pathname.new(root).expand_path
    raise ValidationError, "#{label}: repository root must be an existing directory" unless root_path.directory?
    root_path = root_path.realpath
    adoption = validate_consumer_reference!(decision.fetch('adoption_decision'), root_path,
                                            label: "#{label}.adoption_decision")
    unless adoption == contract.fetch('adopted_sources').fetch('adoption_decision')
      raise ValidationError, "#{label}.adoption_decision: must be the immutable adopted Gate-A decision"
    end
    adoption_document = load_consumer_json_reference!(adoption, root_path,
                                                       label: "#{label}.adoption_decision")
    prior = decision.fetch('prior_state')
    validate_consumer_prior_state!(prior, label: "#{label}.prior_state")
    candidate = validate_optional_consumer_reference!(decision.fetch('candidate_bundle'), root_path,
                                                       label: "#{label}.candidate_bundle", allow_bundle: true)
    held = validate_optional_consumer_reference!(decision.fetch('held_selection'), root_path,
                                                  label: "#{label}.held_selection")
    outcome = decision.fetch('recover_outcome')
    valid_targets = case operation
                    when 'activate' then !candidate.nil? && held.nil? && outcome.nil?
                    when 'rollback' then candidate.nil? && !held.nil? && outcome.nil?
                    when 'disable' then candidate.nil? && held.nil? && outcome.nil?
                    when 'recover'
                      (outcome == 'held' && candidate.nil? && !held.nil?) ||
                        (outcome == 'disabled' && candidate.nil? && held.nil?)
                    end
    raise ValidationError, "#{label}: operation target references are inconsistent" unless valid_targets

    attribution = decision.fetch('decision_attribution')
    assert_closed_schema!(attribution, required: CONSUMER_OPERATION_ATTRIBUTION_KEYS,
                          label: "#{label}.decision_attribution")
    message = attribution.fetch('decision_message')
    assert_type!(message, :nonempty_string, label: "#{label}.decision_attribution.decision_message")
    unless message.encoding == Encoding::UTF_8 && message.valid_encoding? && message.bytesize >= 1
      raise ValidationError, "#{label}.decision_attribution.decision_message: expected valid nonempty UTF-8 bytes"
    end
    unless attribution.fetch('decision_message_encoding') == CONSUMER_OPERATION_MESSAGE_ENCODING
      raise ValidationError, "#{label}.decision_attribution.decision_message_encoding: exact-byte encoding rule changed"
    end
    message_sha = attribution.fetch('decision_message_sha256')
    assert_type!(message_sha, :sha256, label: "#{label}.decision_attribution.decision_message_sha256")
    unless Digest::SHA256.hexdigest(message.b) == message_sha
      raise ValidationError, "#{label}.decision_attribution.decision_message_sha256: exact message byte hash mismatch"
    end
    decision_reference = attribution.fetch('decision_reference')
    assert_type!(decision_reference, :nonempty_string, label: "#{label}.decision_attribution.decision_reference")
    unless decision_reference.end_with?("#{CONSUMER_OPERATION_MESSAGE_REFERENCE_PREFIX}#{message_sha}")
      raise ValidationError, "#{label}.decision_attribution.decision_reference: message hash reference mismatch"
    end
    unless attribution.fetch('recorded_at_basis') == CONSUMER_OPERATION_ATTRIBUTION_METHOD
      raise ValidationError, "#{label}.decision_attribution.recorded_at_basis: attribution method is not adopted"
    end

    bind_gate_a_decider!(adoption_document, actor: actor, attribution: attribution,
                         label: label)

    decided_at = parse_explicit_rfc3339!(decision.fetch('decided_at'), label: "#{label}.decided_at")
    expires_at = parse_explicit_rfc3339!(decision.fetch('expires_at'), label: "#{label}.expires_at")
    recorded_at = parse_explicit_rfc3339!(attribution.fetch('recorded_at'),
                                          label: "#{label}.decision_attribution.recorded_at")
    source_time = parse_explicit_rfc3339!(
      attribution.fetch('source_message_at'), label: "#{label}.decision_attribution.source_message_at"
    )
    unless decided_at == source_time
      raise ValidationError, "#{label}: decided_at must equal source_message_at"
    end
    if recorded_at < source_time
      raise ValidationError, "#{label}.decision_attribution.recorded_at: predates source message"
    end
    raise ValidationError, "#{label}.expires_at: must be strictly after decided_at" unless expires_at > decided_at
    if expires_at - decided_at > CONSUMER_MAXIMUM_DECISION_TTL_SECONDS
      raise ValidationError, "#{label}.expires_at: decision TTL exceeds the bounded maximum"
    end
    current = nil
    unless now.nil?
      current = now.is_a?(String) ? parse_explicit_rfc3339!(now, label: "#{label}.now") : now
      raise ValidationError, "#{label}.now: expected Time or RFC3339 string" unless current.is_a?(Time)
      unless decided_at <= current && current < expires_at
        raise ValidationError, "#{label}: decision is not within its authorization window"
      end
    end

    approval_reference = validate_consumer_reference!(decision.fetch('approval_evidence'), root_path,
                                                       label: "#{label}.approval_evidence")
    approval = load_consumer_json_reference!(approval_reference, root_path,
                                             label: "#{label}.approval_evidence")
    validate_consumer_approval_evidence!(approval, decision: decision, contract: contract,
                                         label: "#{label}.approval_evidence_document")

    evidence = decision.fetch('technical_evidence')
    assert_closed_schema!(evidence, required: CONSUMER_OPERATION_TECHNICAL_EVIDENCE_KEYS,
                          label: "#{label}.technical_evidence")
    evidence.each do |key, reference|
      validate_consumer_reference!(reference, root_path,
                                   label: "#{label}.technical_evidence.#{key}",
                                   allow_bundle: key == 'candidate_bundle')
    end
    unless evidence.fetch('gate_a_adoption') == adoption
      raise ValidationError, "#{label}.technical_evidence.gate_a_adoption: adoption reference drift"
    end
    if operation == 'activate' && evidence.fetch('candidate_bundle') != candidate
      raise ValidationError, "#{label}.technical_evidence.candidate_bundle: target reference drift"
    end
    validate_consumer_semantic_evidence!(
      evidence, decision: decision, contract: contract, root_path: root_path,
      decided_at: decided_at, current: current, label: "#{label}.technical_evidence"
    )
    true
  rescue KeyError => e
    raise ValidationError, "#{label}: missing required field #{e.key}"
  end


  def bind_gate_a_decider!(adoption, actor:, attribution:, label:)
    decider = adoption.fetch('decider')
    assert_type!(decider, Hash, label: "#{label}.adoption_decision.decider")
    identity = decider.fetch('identity')
    capacity = decider.fetch('authority_capacity')
    reference = decider.fetch('decision_reference')
    [identity, capacity, reference].each_with_index do |value, index|
      assert_type!(value, :nonempty_string, label: "#{label}.adoption_decision.decider[#{index}]")
    end
    match = reference.match(/\Acodex_thread:([^#]+)#/)
    raise ValidationError, "#{label}.adoption_decision.decider: invalid immutable Gate-A thread" unless match
    expected = {
      'identity' => identity,
      'authority_capacity' => capacity,
      'decision_thread_id' => match[1]
    }
    unless capacity == 'product_owner' && actor == expected
      raise ValidationError, "#{label}.actor: must match immutable adopted Gate-A decider"
    end
    unless attribution.fetch('decision_reference').start_with?("codex_thread:#{match[1]}#")
      raise ValidationError, "#{label}.decision_attribution.decision_reference: Gate-A decision thread mismatch"
    end
  rescue KeyError => e
    raise ValidationError, "#{label}.adoption_decision.decider: missing required field #{e.key}"
  end
  private_class_method :bind_gate_a_decider!

  def validate_consumer_approval_evidence!(approval, decision:, contract:, label:)
    rules = contract.fetch('consumer_operation_decision_contract').fetch('approval_evidence_contract')
    assert_closed_schema!(approval, required: CONSUMER_APPROVAL_EVIDENCE_KEYS, label: label)
    assert_secret_free!(approval, label: label)
    unless approval.values_at('artifact_type', 'schema_version', 'status', 'effect') ==
           rules.values_at('artifact_type', 'schema_version', 'status', 'effect')
      raise ValidationError, "#{label}: approval identity or effect is invalid"
    end
    assert_type!(approval.fetch('approval_id'), :nonempty_string, label: "#{label}.approval_id")
    CONSUMER_APPROVAL_CROSS_EQUAL_KEYS.each do |key|
      unless approval.fetch(key) == decision.fetch(key)
        raise ValidationError, "#{label}.#{key}: approval/operation cross-binding mismatch"
      end
    end
    scope = approval.fetch('scope')
    assert_closed_schema!(scope, required: CONSUMER_APPROVAL_SCOPE_KEYS, label: "#{label}.scope")
    CONSUMER_APPROVAL_SCOPE_KEYS.each do |key|
      assert_type!(scope.fetch(key), :boolean, label: "#{label}.scope.#{key}")
    end
    unless scope.fetch('consumer_selection_operation') == true &&
           (CONSUMER_APPROVAL_SCOPE_KEYS - ['consumer_selection_operation']).all? { |key| scope.fetch(key) == false }
      raise ValidationError, "#{label}.scope: only one consumer selection operation may be authorized"
    end
  end
  private_class_method :validate_consumer_approval_evidence!

  def validate_consumer_semantic_evidence!(evidence, decision:, contract:, root_path:, decided_at:, current:, label:)
    semantic = contract.fetch('consumer_operation_decision_contract').fetch('semantic_evidence_contracts')
    expected_contract = evidence.fetch('validator_contract')
    unless expected_contract.fetch('path') == CONSUMER_VALIDATOR_CONTRACT_PATH &&
           load_consumer_json_reference!(expected_contract, root_path,
                                         label: "#{label}.validator_contract") == contract
      raise ValidationError, "#{label}.validator_contract: validator contract identity mismatch"
    end
    unless evidence.fetch('selector_source').fetch('path') == CONSUMER_SELECTOR_SOURCE_PATH
      raise ValidationError, "#{label}.selector_source: selector source identity mismatch"
    end

    local = load_consumer_json_reference!(evidence.fetch('local_observation'), root_path,
                                          label: "#{label}.local_observation")
    preflight = load_consumer_json_reference!(evidence.fetch('canonical_preflight'), root_path,
                                              label: "#{label}.canonical_preflight")
    review = load_consumer_json_reference!(evidence.fetch('independent_review'), root_path,
                                           label: "#{label}.independent_review")
    local_times = validate_common_semantic_evidence!(
      local, expected_type: semantic.fetch('local_observation_artifact_type'),
      expected_effect: semantic.fetch('local_observation_effect'), decision: decision,
      evidence: evidence, root_path: root_path, decided_at: decided_at,
      label: "#{label}.local_observation_document"
    )
    preflight_times = validate_common_semantic_evidence!(
      preflight, expected_type: semantic.fetch('canonical_preflight_artifact_type'),
      expected_effect: semantic.fetch('canonical_preflight_effect'), decision: decision,
      evidence: evidence, root_path: root_path, decided_at: decided_at,
      label: "#{label}.canonical_preflight_document", extra_keys: ['checks']
    )
    checks = preflight.fetch('checks')
    assert_closed_schema!(checks, required: CONSUMER_PREFLIGHT_CHECK_KEYS,
                          label: "#{label}.canonical_preflight_document.checks")
    unless CONSUMER_PREFLIGHT_CHECK_KEYS.all? { |key| checks.fetch(key) == true }
      raise ValidationError, "#{label}.canonical_preflight_document.checks: every check must PASS"
    end
    review_times = validate_common_semantic_evidence!(
      review, expected_type: semantic.fetch('independent_review_artifact_type'),
      expected_effect: semantic.fetch('independent_review_effect'), decision: decision,
      evidence: evidence, root_path: root_path, decided_at: decided_at,
      label: "#{label}.independent_review_document",
      extra_keys: %w[reviewer reviewed_evidence verdict]
    )
    reviewer = review.fetch('reviewer')
    assert_closed_schema!(reviewer, required: CONSUMER_REVIEWER_KEYS,
                          label: "#{label}.independent_review_document.reviewer")
    unless reviewer.fetch('identity').is_a?(String) && !reviewer.fetch('identity').strip.empty? &&
           reviewer.fetch('capacity') == semantic.fetch('independent_reviewer_capacity') &&
           reviewer.fetch('identity') != decision.fetch('actor').fetch('identity')
      raise ValidationError, "#{label}.independent_review_document.reviewer: reviewer is not independent"
    end
    reviewed = review.fetch('reviewed_evidence')
    assert_closed_schema!(reviewed, required: CONSUMER_REVIEWED_EVIDENCE_KEYS,
                          label: "#{label}.independent_review_document.reviewed_evidence")
    unless reviewed == {
      'local_observation' => evidence.fetch('local_observation'),
      'canonical_preflight' => evidence.fetch('canonical_preflight')
    } && review.fetch('verdict') == semantic.fetch('independent_review_verdict')
      raise ValidationError, "#{label}.independent_review_document: review evidence or verdict mismatch"
    end
    observed_times = [local_times.first, preflight_times.first, review_times.first]
    unless observed_times.each_cons(2).all? { |earlier, later| earlier <= later } &&
           review_times.first <= decided_at
      raise ValidationError, "#{label}: evidence observation ordering is invalid"
    end
    if current && [local_times.last, preflight_times.last, review_times.last].any? { |expiry| current >= expiry }
      raise ValidationError, "#{label}: semantic evidence has expired"
    end
  end
  private_class_method :validate_consumer_semantic_evidence!

  def validate_common_semantic_evidence!(document, expected_type:, expected_effect:, decision:, evidence:,
                                         root_path:, decided_at:, label:, extra_keys: [])
    assert_closed_schema!(document, required: CONSUMER_EVIDENCE_COMMON_KEYS + extra_keys, label: label)
    assert_secret_free!(document, label: label)
    unless document.values_at('artifact_type', 'schema_version', 'status', 'effect', 'data_boundary',
                               'environment', 'root', 'authority_effect') == [
      expected_type, 1, 'PASS', expected_effect, 'synthetic_only', decision.fetch('environment'),
      root_path.to_s, 'none'
    ]
      raise ValidationError, "#{label}: semantic evidence identity, environment, root, or authority changed"
    end
    assert_type!(document.fetch('evidence_id'), :nonempty_string, label: "#{label}.evidence_id")
    unless document.fetch('candidate_bundle') == evidence.fetch('candidate_bundle') &&
           document.fetch('validator_contract') == evidence.fetch('validator_contract') &&
           document.fetch('selector_source') == evidence.fetch('selector_source') &&
           document.fetch('prior_state') == decision.fetch('prior_state')
      raise ValidationError, "#{label}: semantic evidence cross-binding mismatch"
    end
    observed = parse_explicit_rfc3339!(document.fetch('observed_at'), label: "#{label}.observed_at")
    expires = parse_explicit_rfc3339!(document.fetch('expires_at'), label: "#{label}.expires_at")
    unless observed <= decided_at && decided_at < expires
      raise ValidationError, "#{label}: evidence is not fresh at decision time"
    end
    if expires - observed > CONSUMER_MAXIMUM_EVIDENCE_TTL_SECONDS
      raise ValidationError, "#{label}: evidence TTL exceeds the bounded maximum"
    end
    [observed, expires]
  end
  private_class_method :validate_common_semantic_evidence!

  def load_consumer_json_reference!(reference, root_path, label:)
    validate_consumer_reference!(reference, root_path, label: label)
    path = safe_regular_file_under_root!(root_path, reference.fetch('path'), label: label)
    parse_json(File.binread(path), label: label)
  end
  private_class_method :load_consumer_json_reference!

  def validate_consumer_prior_state!(prior, label: '$.prior_state')
    assert_closed_schema!(prior, required: CONSUMER_OPERATION_PRIOR_STATE_KEYS, label: label)
    state = prior.fetch('prior_state_reason')
    expected = prior.fetch('expected_prior_pointer_sha256')
    unreadable = prior.fetch('observed_unreadable_pointer_sha256')
    valid = case state
            when 'valid_pointer'
              expected.is_a?(String) && SHA256_PATTERN.match?(expected) && unreadable.nil?
            when 'initial_state', 'missing_pointer'
              expected.nil? && unreadable.nil?
            when 'unreadable_pointer'
              expected.nil? && unreadable.is_a?(String) && SHA256_PATTERN.match?(unreadable)
            else
              false
            end
    raise ValidationError, "#{label}: invalid closed prior-state representation" unless valid

    true
  end

  def validate_consumer_reference!(reference, root_path, label:, allow_bundle: false)
    assert_closed_schema!(reference, required: CONSUMER_OPERATION_REFERENCE_KEYS, label: label)
    path = reference.fetch('path')
    unless path.is_a?(String) && SAFE_RELATIVE_PATH_PATTERN.match?(path)
      raise ValidationError, "#{label}.path: unsafe repository-relative path"
    end
    sha = reference.fetch('sha256')
    assert_type!(sha, :sha256, label: "#{label}.sha256")
    target = root_path.join(path)
    source = if allow_bundle && target.directory?
               safe_regular_file_under_root!(root_path, File.join(path, CONSUMER_OPERATION_BUNDLE_MANIFEST), label: label)
             else
               safe_regular_file_under_root!(root_path, path, label: label)
             end
    unless Digest::SHA256.file(source).hexdigest == sha
      raise ValidationError, "#{label}: referenced byte hash drift"
    end
    reference
  end
  private_class_method :validate_consumer_reference!

  def validate_optional_consumer_reference!(reference, root_path, label:, allow_bundle: false)
    return nil if reference.nil?

    validate_consumer_reference!(reference, root_path, label: label, allow_bundle: allow_bundle)
  end
  private_class_method :validate_optional_consumer_reference!

  def parse_explicit_rfc3339!(value, label:)
    unless value.is_a?(String) && value.match?(/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/)
      raise ValidationError, "#{label}: expected RFC3339 timestamp with explicit offset"
    end
    Time.iso8601(value)
  rescue ArgumentError
    raise ValidationError, "#{label}: invalid RFC3339 timestamp"
  end
  private_class_method :parse_explicit_rfc3339!

  def assert_unique_string_array!(value, label:, allow_empty: true)
    assert_type!(value, Array, label: label)
    raise ValidationError, "#{label}: must not be empty" if !allow_empty && value.empty?
    value.each_with_index { |entry, index| assert_type!(entry, :nonempty_string, label: "#{label}[#{index}]") }
    raise ValidationError, "#{label}: duplicate value" unless value.uniq == value
    true
  end
  private_class_method :assert_unique_string_array!

  def assert_exact_string_array!(value, expected, label:)
    assert_unique_string_array!(value, label: label, allow_empty: false)
    raise ValidationError, "#{label}: exact closed values or order changed" unless value == expected

    true
  end
  private_class_method :assert_exact_string_array!

  def walk_json(value, path, key = nil, &block)
    yield(value, path, key)
    case value
    when Hash
      value.each { |child_key, child| walk_json(child, "#{path}.#{child_key}", child_key, &block) }
    when Array
      value.each_with_index { |child, index| walk_json(child, "#{path}[#{index}]", nil, &block) }
    end
  end
  private_class_method :walk_json

  def sensitive_key?(key)
    key.to_s.match?(/(?:\A|_)(?:password|passwd|pwd|secret|access_token|refresh_token|api_key|private_key|client_secret|connection_string|database_url|recovery_key)(?:\z|_)/i)
  end
  private_class_method :sensitive_key?

  def empty_secret_value?(value)
    value.nil? || value == false || (value.respond_to?(:empty?) && value.empty?) ||
      %w[reject reject_without_echoing_value prohibited not_permitted none].include?(value)
  end
  private_class_method :empty_secret_value?

  def secret_like_string?(value)
    value.match?(/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/) ||
      value.match?(%r{\A[a-z][a-z0-9+.-]*://[^/@\s:]+:[^/@\s]+@}i) ||
      value.match?(/\b(?:password|passwd|pwd|client_secret|api_key|access_token|refresh_token)\s*[:=]\s*[^\s,;]+/i) ||
      value.match?(/\bAKIA[0-9A-Z]{16}\b/) ||
      value.match?(/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/)
  end
  private_class_method :secret_like_string?

  def validate_consequence_map!(consequences, contract, label: '$.consequences')
    flags = contract.fetch('closed_values').fetch('consequence_flags')
    assert_closed_schema!(consequences, required: flags, label: label)
    consequences.each do |flag, value|
      assert_type!(value, :boolean, label: "#{label}.#{flag}")
    end
    true
  end

  def derived_tier(consequences, contract)
    validate_consequence_map!(consequences, contract)
    closed = contract.fetch('closed_values')
    t3_flags = closed.fetch('t3_consequence_flags')
    t2_flags = closed.fetch('t2_consequence_flags')

    tiers = contract.fetch('closed_values').fetch('tiers')
    return tiers.fetch(2) if t3_flags.any? { |flag| consequences.fetch(flag) }
    return tiers.fetch(1) if t2_flags.any? { |flag| consequences.fetch(flag) }

    tiers.fetch(0)
  end

  def validate_declared_tier!(declared_tier, consequences, contract, label: '$.declared_tier')
    tiers = contract.fetch('closed_values').fetch('tiers')
    assert_type!(declared_tier, :nonempty_string, label: label)
    raise ValidationError, "#{label}: unknown tier" unless tiers.include?(declared_tier)

    derived = derived_tier(consequences, contract)
    declared_rank = tiers.index(declared_tier)
    derived_rank = tiers.index(derived)
    if contract.fetch('tier_derivation').fetch('declared_tier_must_equal_derived_tier') && declared_tier != derived
      raise ValidationError, "#{label}: declared tier must equal deterministically derived tier"
    elsif declared_rank < derived_rank
      raise ValidationError, "#{label}: declared tier is below deterministically derived tier"
    end

    derived
  end

  def independent_review_required?(consequences, contract)
    tier = derived_tier(consequences, contract)
    tiers = contract.fetch('closed_values').fetch('tiers')
    return true if tier == tiers.fetch(2)

    triggers = contract.fetch('closed_values').fetch('t2_independent_review_trigger_flags')
    tier == tiers.fetch(1) && triggers.any? { |flag| consequences.fetch(flag) }
  end

  # Bindings use identity IDs rather than display names. The same person may
  # hold product and domain capacities, but an independent reviewer may not be
  # the executor, an author, or any approver. T3 also requires the exact
  # independent-control capacity declared by the machine contract.
  def validate_authority_independence!(consequences:, declared_tier:, product_authority:,
                                       domain_authority:, executor_identity:, author_identities:,
                                       approver_identities:, materially_affected_co_owner_identities: [],
                                       independent_reviewer: nil, contract:)
    validate_owner_authority!(product_authority, expected_capacity: 'product_authority', label: '$.authority.product')
    validate_owner_authority!(domain_authority, expected_capacity: 'accountable_domain_authority', label: '$.authority.domain')
    assert_type!(executor_identity, :nonempty_string, label: '$.authority.executor_identity')
    [author_identities, approver_identities].each_with_index do |identities, index|
      assert_type!(identities, Array, label: "$.authority.identity_sets[#{index}]")
      identities.each_with_index do |identity, identity_index|
        assert_type!(identity, :nonempty_string, label: "$.authority.identity_sets[#{index}][#{identity_index}]")
      end
      raise ValidationError, "$.authority.identity_sets[#{index}]: duplicate identity" unless identities.uniq == identities
    end
    assert_type!(materially_affected_co_owner_identities, Array, label: '$.authority.materially_affected_co_owner_identities')
    materially_affected_co_owner_identities.each_with_index do |identity, index|
      assert_type!(identity, :nonempty_string, label: "$.authority.materially_affected_co_owner_identities[#{index}]")
    end
    unless materially_affected_co_owner_identities.uniq == materially_affected_co_owner_identities
      raise ValidationError, '$.authority.materially_affected_co_owner_identities: duplicate identity'
    end

    derived = validate_declared_tier!(declared_tier, consequences, contract)
    required_approvers = [product_authority.fetch('identity'), domain_authority.fetch('identity')]
    if %w[T2_DOMAIN_CRITICAL T3_INDEPENDENT_CONTROL].include?(derived)
      required_approvers.concat(materially_affected_co_owner_identities)
    end
    missing_approvers = required_approvers.uniq - approver_identities
    unless missing_approvers.empty?
      raise ValidationError, '$.authority.approver_identities: missing required owner approval'
    end
    review_required = independent_review_required?(consequences, contract)
    if !review_required && independent_reviewer.nil?
      return derived
    end

    raise ValidationError, '$.authority.independent_reviewer: required by consequence tier/triggers' if independent_reviewer.nil?
    assert_closed_schema!(independent_reviewer, required: %w[identity capacity], label: '$.authority.independent_reviewer')
    reviewer_identity = independent_reviewer.fetch('identity')
    reviewer_capacity = independent_reviewer.fetch('capacity')
    assert_type!(reviewer_identity, :nonempty_string, label: '$.authority.independent_reviewer.identity')
    assert_type!(reviewer_capacity, :nonempty_string, label: '$.authority.independent_reviewer.capacity')
    unless contract.fetch('closed_values').fetch('authority_capacities').include?(reviewer_capacity)
      raise ValidationError, '$.authority.independent_reviewer.capacity: unknown authority capacity'
    end

    excluded = [executor_identity, *author_identities, *approver_identities].uniq
    if excluded.include?(reviewer_identity)
      raise ValidationError, '$.authority.independent_reviewer.identity: must be distinct from executor, authors, and approvers'
    end

    if derived == contract.fetch('closed_values').fetch('tiers').fetch(2)
      required_capacity = 'independent_control_authority'
      unless reviewer_capacity == required_capacity
        raise ValidationError, '$.authority.independent_reviewer.capacity: wrong capacity for T3'
      end
    end

    derived
  end

  def validate_owner_authority!(authority, expected_capacity:, label:)
    assert_closed_schema!(authority, required: %w[identity capacity scopes], label: label)
    assert_type!(authority.fetch('identity'), :nonempty_string, label: "#{label}.identity")
    unless authority.fetch('capacity') == expected_capacity
      raise ValidationError, "#{label}.capacity: expected #{expected_capacity}"
    end
    assert_unique_string_array!(authority.fetch('scopes'), label: "#{label}.scopes", allow_empty: false)
    true
  end
  private_class_method :validate_owner_authority!

  FAMILY_SHARED_FIELDS = %w[
    owners disposition target_pattern evidence_boundary conditions
    acceptance_contract consequence_map derived_tier downstream_effects
    independent_review_requirement
  ].freeze
  FAMILY_ROW_KEYS = ['requirement_id', *FAMILY_SHARED_FIELDS].freeze

  def validate_family_decision!(family, member_rows, contract, root:, label: '$.family')
    assert_closed_schema!(family, required: %w[member_ids declared_tier exception_ids], label: label)
    assert_type!(family.fetch('member_ids'), Array, label: "#{label}.member_ids")
    assert_type!(family.fetch('exception_ids'), Array, label: "#{label}.exception_ids")
    member_ids = family.fetch('member_ids')
    exception_ids = family.fetch('exception_ids')
    raise ValidationError, "#{label}.member_ids: must not be empty" if member_ids.empty?
    raise ValidationError, "#{label}.member_ids: duplicate ID" unless member_ids.uniq == member_ids
    raise ValidationError, "#{label}.exception_ids: duplicate ID" unless exception_ids.uniq == exception_ids
    requirement_pattern = Regexp.new(contract.fetch('universe').fetch('requirement_id_pattern'))
    unless member_ids.all? { |id| id.is_a?(String) && requirement_pattern.match?(id) } &&
           exception_ids.all? { |id| id.is_a?(String) && requirement_pattern.match?(id) }
      raise ValidationError, "#{label}: member and exception IDs must be canonical requirement IDs"
    end
    canonical_ids = canonical_requirement_ids(contract, root: root)
    unless (member_ids - canonical_ids).empty?
      raise ValidationError, "#{label}.member_ids: unknown capability ID"
    end
    unless (exception_ids - member_ids).empty?
      raise ValidationError, "#{label}.exception_ids: exception is not a family member"
    end

    assert_type!(member_rows, Array, label: "#{label}.rows")
    rows_by_id = {}
    member_rows.each_with_index do |row, index|
      assert_type!(row, Hash, label: "#{label}.rows[#{index}]")
      id = row.fetch('requirement_id') { raise ValidationError, "#{label}.rows[#{index}]: missing requirement_id" }
      raise ValidationError, "#{label}.rows: duplicate requirement_id" if rows_by_id.key?(id)
      rows_by_id[id] = row
    end
    unless rows_by_id.keys == member_ids
      raise ValidationError, "#{label}.rows: must exactly match member_ids in declared order"
    end

    member_rows.each_with_index do |row, index|
      assert_closed_schema!(row, required: FAMILY_ROW_KEYS, optional: ['own_owner_record_id'], label: "#{label}.rows[#{index}]")
      unless row.fetch('requirement_id').is_a?(String) && requirement_pattern.match?(row.fetch('requirement_id'))
        raise ValidationError, "#{label}.rows[#{index}].requirement_id: invalid canonical requirement ID"
      end
      assert_unique_string_array!(row.fetch('owners'), label: "#{label}.rows[#{index}].owners", allow_empty: false)
      unless contract.fetch('closed_values').fetch('canonical_dispositions').include?(row.fetch('disposition'))
        raise ValidationError, "#{label}.rows[#{index}].disposition: unknown canonical disposition"
      end
      assert_type!(row.fetch('target_pattern'), :nonempty_string, label: "#{label}.rows[#{index}].target_pattern")
      unless row.fetch('evidence_boundary') == 'synthetic_only'
        raise ValidationError, "#{label}.rows[#{index}].evidence_boundary: must remain synthetic_only"
      end
      %w[conditions acceptance_contract downstream_effects].each do |field|
        assert_unique_string_array!(row.fetch(field), label: "#{label}.rows[#{index}].#{field}")
      end
      assert_type!(row.fetch('independent_review_requirement'), :boolean, label: "#{label}.rows[#{index}].independent_review_requirement")
      derived = derived_tier(row.fetch('consequence_map'), contract)
      unless row.fetch('derived_tier') == derived
        raise ValidationError, "#{label}.rows[#{index}].derived_tier: does not match consequences"
      end
      expected_review = independent_review_required?(row.fetch('consequence_map'), contract)
      unless row.fetch('independent_review_requirement') == expected_review
        raise ValidationError, "#{label}.rows[#{index}].independent_review_requirement: does not match consequences"
      end
      if exception_ids.include?(row.fetch('requirement_id')) &&
         (!row.key?('own_owner_record_id') || !row['own_owner_record_id'].is_a?(String) || row['own_owner_record_id'].strip.empty?)
        raise ValidationError, "#{label}.rows[#{index}]: exception requires its own owner record"
      end
      if !exception_ids.include?(row.fetch('requirement_id')) && row.key?('own_owner_record_id')
        raise ValidationError, "#{label}.rows[#{index}].own_owner_record_id: permitted only for an enumerated exception"
      end
    end

    inherited_rows = member_rows.reject { |row| exception_ids.include?(row.fetch('requirement_id')) }
    if inherited_rows.length > 1
      baseline = inherited_rows.first
      FAMILY_SHARED_FIELDS.each do |field|
        next if inherited_rows.all? { |row| canonical_json(row.fetch(field)) == canonical_json(baseline.fetch(field)) }

        raise ValidationError, "#{label}: mixed inherited family field #{field} requires explicit exceptions"
      end
    end

    tiers = contract.fetch('closed_values').fetch('tiers')
    maximum = member_rows.map { |row| row.fetch('derived_tier') }.max_by { |tier| tiers.index(tier) || -1 }
    unless family.fetch('declared_tier') == maximum
      raise ValidationError, "#{label}.declared_tier: must equal maximum derived member tier"
    end

    true
  end

  def canonical_requirement_ids(contract, root:)
    root_path = Pathname.new(root).expand_path
    raise ValidationError, '$.root: must be an existing directory' unless root_path.directory?
    path = contract.fetch('universe').fetch('canonical_order_source')
    source = safe_regular_file_under_root!(root_path, path, label: '$.contract.universe.canonical_order_source')
    expected_sha = V1_EXPECTED_INVENTORY.to_h.fetch(path)
    unless Digest::SHA256.file(source).hexdigest == expected_sha
      raise ValidationError, '$.contract.universe.canonical_order_source: historical byte hash drift'
    end
    manifest = parse_json_file(source, label: '$.contract.universe.canonical_order_source')
    assert_closed_schema!(manifest, required: %w[schema_version purpose source_baseline dependency_order expected_counts batches], label: '$.canonical_order_source')
    dependency_order = contract.fetch('universe').fetch('dependency_order')
    unless manifest.fetch('schema_version') == 1 && manifest.fetch('dependency_order') == dependency_order
      raise ValidationError, '$.canonical_order_source: schema or dependency order changed'
    end
    assert_closed_schema!(manifest.fetch('expected_counts'), required: dependency_order, label: '$.canonical_order_source.expected_counts')
    assert_closed_schema!(manifest.fetch('batches'), required: dependency_order, label: '$.canonical_order_source.batches')
    ids = dependency_order.flat_map do |batch|
      batch_ids = manifest.fetch('batches').fetch(batch)
      assert_unique_string_array!(batch_ids, label: "$.canonical_order_source.batches.#{batch}", allow_empty: false)
      unless batch_ids.length == manifest.fetch('expected_counts').fetch(batch)
        raise ValidationError, "$.canonical_order_source.batches.#{batch}: count mismatch"
      end
      batch_ids
    end
    unless ids.length == contract.fetch('universe').fetch('required_capability_count') && ids.uniq == ids
      raise ValidationError, '$.canonical_order_source: capability universe count or uniqueness mismatch'
    end
    ids.freeze
  end

  # Every non-root event must reference an earlier event by both ID and exact
  # canonical hash. Each predecessor may be consumed once, preventing forks.
  def validate_correction_history!(events, label: '$.events')
    assert_type!(events, Array, label: label)
    raise ValidationError, "#{label}: must contain at least one event" if events.empty?
    seen = {}
    successor_by_predecessor = {}

    events.each_with_index do |event, index|
      assert_type!(event, Hash, label: "#{label}[#{index}]")
      %w[event_id predecessor_event_id predecessor_event_sha256 supersedes_event_id].each do |key|
        raise ValidationError, "#{label}[#{index}]: missing fields #{key}" unless event.key?(key)
      end
      event_id = event.fetch('event_id')
      assert_type!(event_id, :nonempty_string, label: "#{label}[#{index}].event_id")
      raise ValidationError, "#{label}: duplicate event_id" if seen.key?(event_id)

      predecessor_id = event.fetch('predecessor_event_id')
      predecessor_sha = event.fetch('predecessor_event_sha256')
      supersedes_id = event.fetch('supersedes_event_id')
      if index.zero?
        unless predecessor_id.nil? && predecessor_sha.nil? && supersedes_id.nil?
          raise ValidationError, "#{label}[0]: root predecessor/supersession fields must be null"
        end
      else
        assert_type!(predecessor_id, :nonempty_string, label: "#{label}[#{index}].predecessor_event_id")
        assert_type!(predecessor_sha, :sha256, label: "#{label}[#{index}].predecessor_event_sha256")
        predecessor = seen[predecessor_id]
        raise ValidationError, "#{label}[#{index}]: predecessor must exist earlier" unless predecessor
        unless supersedes_id == predecessor_id
          raise ValidationError, "#{label}[#{index}].supersedes_event_id: must bind the predecessor event"
        end
        if successor_by_predecessor.key?(predecessor_id)
          raise ValidationError, "#{label}[#{index}]: correction history forks at predecessor"
        end
        unless predecessor_sha == canonical_sha256(predecessor)
          raise ValidationError, "#{label}[#{index}]: predecessor hash mismatch"
        end
        successor_by_predecessor[predecessor_id] = event_id
      end
      seen[event_id] = event
    end

    true
  end

  V1_MANIFEST_KEYS = %w[
    artifact_type schema_version artifact_id status effect purpose profile
    planning_baseline inventory_contract historical_meaning boundary files
  ].freeze
  V1_BASELINE_KEYS = %w[
    git_commit repository_origin byte_source source_adr_path source_adr_sha256
    source_inventory_section
  ].freeze
  V1_INVENTORY_KEYS = %w[
    closed ordered expected_file_count path_selection glob_semantics
    directory_walk_semantics later_working_tree_bytes_are_not_sources
    later_commit_bytes_are_not_sources
  ].freeze
  V1_HISTORICAL_KEYS = %w[
    immutable profile_status reinterpretation_permitted owner_authority_effect
    consumer_selection_effect gate_effect
  ].freeze
  V1_BOUNDARY_KEYS = %w[
    data_scope real_patient_data_authorized live_integrations_authorized
    prohibited_live_integrations
  ].freeze
  V1_FILE_KEYS = %w[order path sha256].freeze
  V1_PLANNING_BASELINE = 'ad326cf2e9b36e6864e029adba10bd0d86a0cbc4'
  ACCEPTED_ADR_SHA256 = 'cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962'
  V1_REPOSITORY_ORIGIN = 'https://github.com/danielhappyg/simrs-campus-ueu.git'
  V1_PURPOSE = 'Preserve the exact ordered byte hashes of the closed governance-v1 artifact and test inventory at the accepted ADR-018 planning baseline.'
  PROHIBITED_LIVE_INTEGRATIONS = %w[
    BPJS VClaim SATUSEHAT payment LIS PACS device other_production_integration
  ].freeze
  V1_EXPECTED_PATHS = [
    'docs/new-simrs-rebuild/phase-0/PARITY_MATRIX_BASELINE.json',
    'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json',
    *('A'..'G').map { |batch| "docs/new-simrs-rebuild/phase-0/G0_BATCH_#{batch}_DECISION_REGISTER_2026-08-25.json" },
    *('A'..'G').map { |batch| "docs/new-simrs-rebuild/phase-0/G0_BATCH_#{batch}_DECISION_EVIDENCE_2026-08-25/README.md" },
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json',
    'docs/new-simrs-rebuild/phase-0/G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_AUTHORITY_POLICY_2026-08-25.json',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json',
    'docs/new-simrs-rebuild/phase-0/G0_DECISION_SESSION_REGISTER_2026-08-25.json',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_DECISION_EVIDENCE_2026-08-25/README.md',
    'docs/new-simrs-rebuild/phase-0/G0_OWNER_APPOINTMENT_PACK_2026-08-25.md',
    'docs/new-simrs-rebuild/phase-0/G0_S0_INSTITUTIONAL_AUTHORITY_INTAKE_2026-08-26.md',
    'scripts/validate-parity-governance.rb',
    'scripts/generate-g0-owner-governance-snapshot.rb',
    'scripts/validate-g0-s0-intake.rb',
    'tests/Documentation/ParityGovernanceValidatorTest.rb',
    'tests/Documentation/G0OwnerGovernanceSnapshotGeneratorTest.rb',
    'tests/Documentation/G0S0IntakeContractTest.rb'
  ].freeze
  V1_EXPECTED_SHA256 = %w[
    38e5889889ba23b5b7e4b59af9e669161b87b5c5b6e8c7cb3113f086c3af11ae
    59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca
    a5c1e01686fdb576026802331d3228df9a6335f6f7176b7c023c459bfc532098
    3db0a698be4f7729f24f997dbbc54e69c7c98442237fb7a9d4b12358971eeb1f
    7b97e8cc5169ff848dc5ca524281f93ad5b42b0a2134acc52cf91368cc580775
    da057e99d9d7d2349662f532605efec87edf220deb885783852088a2975c6287
    10778f021fc23f7fac0d3dfbdaacd8574cc424aaa76788afd7a26ba9f426a93b
    d2e78446dc2aa84162c8229dec8ab809bd45ace51a8fc6a313da95286503b795
    53bef20b3f509c543213e649b2d5f6644e9dbe00a7dae34d209c57a93137612e
    1f5b1c82fd342bddbc1da3949542be026d0909052749715878e10d0af21bcf2c
    0cd2059ce381dcd65bda6ae00a08a43d2b4592771a34dba2277b2725a25e004e
    f95417dfb63f2d65202577a78245d3f19405e30770fba0fd95c4659691e4781f
    7144bc8b22f25beab7e51b0840450b52a4e9365fb02cc91bd139f8e88231cc10
    19e8037d03dc9baa37f7653c3e8b411ddfb010affcd97c1ed428fc980516286d
    e03dacd43c04cfb6c58ff952334a42cae0f7505c2ff030c7f376d9348fa5aa54
    ffa4f1870ca91184437962698c47b70a41fcc0df1fb3d13facdf5193c705c5da
    3ab2abd46fdcbbd7f13ff6bd87857834c88e1cc373894bbe78fa54ae0a440ee0
    ba0cc0002a23a6fb04fa645c7c8089e8e5ce8d5a3ca7752fac023ae304eeb206
    bd43a7f0fbfc6f3a6f571c6077f7ac4722cd241006358c7fd325c0a27c37cc75
    85697fd4c1979f6447e1e0b1ab0cf761ca8c8755941c9059795aea95cb77f008
    a86576e0bb80171b275a1f7b7d8dde92ecf3bcc0856a4b386481ef68746117b3
    025f05437688815077754d57e82010441329dcaeeb4ce843c2888427770954e3
    9beb1ce52abec72ab829d479e0d4835409ca8356b3217818bb04286a663310b1
    3b67978695b8729463d52e6bb6063713bcd70fb763e418381372f8c30e545a90
    251cd15d947ea14b517f56ac23aee143faa91d59ce349d96756c8e88378cf65f
    a6c0e229123e30d02777a6f085c2da94062ada22e7a1676cea6ed77c1fab3a86
    d90e5452c63d61162e7683a2999fea89a3cb2e76c25048af3e907e03eb165ee5
    92441766a024eabe9462767f58d4985b87a5da9a636c22d67c4cab973c35e182
    a9f28a2fe4d682274aa1c18a71144c850c1f3a0c42423a6d7673a191dcffda51
    0d3de612c2a937434530c4c1d02477df57afcf264877475c3dbc1a63126b7be7
  ].freeze
  V1_EXPECTED_INVENTORY = V1_EXPECTED_PATHS.zip(V1_EXPECTED_SHA256).freeze

  def verify_v1_manifest!(manifest, root:)
    assert_closed_schema!(manifest, required: V1_MANIFEST_KEYS, label: '$.v1_manifest')
    assert_secret_free!(manifest, label: '$.v1_manifest')
    assert_type!(root, :nonempty_string, label: '$.root')
    root_path = Pathname.new(root).expand_path
    raise ValidationError, '$.root: must be an existing directory' unless root_path.directory?

    assert_type!(manifest.fetch('schema_version'), Integer, label: '$.v1_manifest.schema_version')
    raise ValidationError, '$.v1_manifest.schema_version: expected 1' unless manifest.fetch('schema_version') == 1
    unless manifest.fetch('artifact_type') == 'g0_governance_v1_historical_hash_manifest' &&
           manifest.fetch('artifact_id') == 'G0-GOVERNANCE-V1-HISTORICAL-HASH-MANIFEST' &&
           manifest.fetch('status') == 'immutable_historical_integrity_reference' &&
           manifest.fetch('effect') == 'none_no_activation_or_authority' && manifest.fetch('purpose') == V1_PURPOSE &&
           manifest.fetch('profile') == 'v1'
      raise ValidationError, '$.v1_manifest: historical identity or authority effect changed'
    end

    baseline = manifest.fetch('planning_baseline')
    inventory = manifest.fetch('inventory_contract')
    meaning = manifest.fetch('historical_meaning')
    boundary = manifest.fetch('boundary')
    files = manifest.fetch('files')
    assert_closed_schema!(baseline, required: V1_BASELINE_KEYS, label: '$.v1_manifest.planning_baseline')
    assert_closed_schema!(inventory, required: V1_INVENTORY_KEYS, label: '$.v1_manifest.inventory_contract')
    assert_closed_schema!(meaning, required: V1_HISTORICAL_KEYS, label: '$.v1_manifest.historical_meaning')
    assert_closed_schema!(boundary, required: V1_BOUNDARY_KEYS, label: '$.v1_manifest.boundary')
    assert_type!(files, Array, label: '$.v1_manifest.files')

    expected_count = inventory.fetch('expected_file_count')
    assert_type!(expected_count, Integer, label: '$.v1_manifest.inventory_contract.expected_file_count')
    raise ValidationError, '$.v1_manifest.files: wrong closed inventory count' unless files.length == expected_count
    unless inventory.values_at('closed', 'ordered', 'later_working_tree_bytes_are_not_sources', 'later_commit_bytes_are_not_sources').all?(true)
      raise ValidationError, '$.v1_manifest.inventory_contract: preservation controls must be true'
    end
    unless inventory.fetch('path_selection') == 'explicit_exact_paths_only' &&
           inventory.fetch('glob_semantics') == 'prohibited' && inventory.fetch('directory_walk_semantics') == 'prohibited'
      raise ValidationError, '$.v1_manifest.inventory_contract: inventory must be explicit and closed'
    end
    unless meaning == {
      'immutable' => true,
      'profile_status' => 'historical_never_activated',
      'reinterpretation_permitted' => false,
      'owner_authority_effect' => 'none',
      'consumer_selection_effect' => 'none',
      'gate_effect' => 'none'
    }
      raise ValidationError, '$.v1_manifest.historical_meaning: historical authority meaning changed'
    end
    unless boundary.fetch('data_scope') == 'synthetic_only' && boundary.fetch('real_patient_data_authorized') == false &&
           boundary.fetch('live_integrations_authorized') == false &&
           boundary.fetch('prohibited_live_integrations') == PROHIBITED_LIVE_INTEGRATIONS
      raise ValidationError, '$.v1_manifest.boundary: synthetic/no-live boundary changed'
    end

    commit = baseline.fetch('git_commit')
    unless commit.is_a?(String) && commit.match?(/\A[0-9a-f]{40}\z/)
      raise ValidationError, '$.v1_manifest.planning_baseline.git_commit: expected full git SHA'
    end
    unless commit == V1_PLANNING_BASELINE
      raise ValidationError, '$.v1_manifest.planning_baseline.git_commit: accepted planning baseline changed'
    end
    unless baseline.fetch('repository_origin') == V1_REPOSITORY_ORIGIN &&
           baseline.fetch('source_adr_path') == 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md' &&
           baseline.fetch('source_inventory_section') == 'Closed v1 preservation inventory at the planning baseline'
      raise ValidationError, '$.v1_manifest.planning_baseline: repository or accepted ADR inventory source changed'
    end
    assert_type!(baseline.fetch('source_adr_sha256'), :sha256, label: '$.v1_manifest.planning_baseline.source_adr_sha256')
    unless baseline.fetch('source_adr_sha256') == ACCEPTED_ADR_SHA256
      raise ValidationError, '$.v1_manifest.planning_baseline.source_adr_sha256: accepted ADR changed'
    end
    unless baseline.fetch('byte_source') == 'git_show_exact_path_at_planning_baseline'
      raise ValidationError, '$.v1_manifest.planning_baseline.byte_source: must use exact git-show bytes'
    end

    inventory_pairs = []
    files.each_with_index do |entry, index|
      entry_label = "$.v1_manifest.files[#{index}]"
      assert_closed_schema!(entry, required: V1_FILE_KEYS, label: entry_label)
      unless entry.fetch('order') == index + 1
        raise ValidationError, "#{entry_label}.order: inventory order must be contiguous from 1"
      end
      path = entry.fetch('path')
      sha = entry.fetch('sha256')
      unless path.is_a?(String) && SAFE_RELATIVE_PATH_PATTERN.match?(path)
        raise ValidationError, "#{entry_label}.path: unsafe repository-relative path"
      end
      assert_type!(sha, :sha256, label: "#{entry_label}.sha256")
      raise ValidationError, "#{entry_label}.path: duplicate inventory path" if inventory_pairs.any? { |pair| pair.first == path }
      inventory_pairs << [path, sha]
    end

    unless inventory_pairs == V1_EXPECTED_INVENTORY
      raise ValidationError, '$.v1_manifest.files: accepted ordered historical path/hash inventory changed'
    end

    files.each_with_index do |entry, index|
      entry_label = "$.v1_manifest.files[#{index}]"
      path = entry.fetch('path')
      sha = entry.fetch('sha256')
      baseline_bytes = git_show(root_path.to_s, commit, path, label: entry_label)
      unless Digest::SHA256.hexdigest(baseline_bytes) == sha
        raise ValidationError, "#{entry_label}: planning-baseline byte hash mismatch"
      end
      verify_working_tree_source!(root_path, path, sha, label: entry_label)
    end

    adr_path = baseline.fetch('source_adr_path')
    unless adr_path.is_a?(String) && SAFE_RELATIVE_PATH_PATTERN.match?(adr_path)
      raise ValidationError, '$.v1_manifest.planning_baseline.source_adr_path: unsafe path'
    end
    adr_working_path = safe_regular_file_under_root!(root_path, adr_path, label: '$.v1_manifest.planning_baseline.source_adr_path')
    adr_sha = Digest::SHA256.file(adr_working_path).hexdigest
    unless adr_sha == baseline.fetch('source_adr_sha256')
      raise ValidationError, '$.v1_manifest.planning_baseline.source_adr_sha256: accepted ADR hash mismatch'
    end

    true
  end

  def git_show(root, revision, path, label:)
    stdout, _stderr, status = Open3.capture3({ 'GIT_NO_REPLACE_OBJECTS' => '1' }, 'git', 'show', "#{revision}:#{path}", chdir: root)
    return stdout.b if status.success?

    raise ValidationError, "#{label}: git source bytes unavailable"
  end
  private_class_method :git_show

  def safe_regular_file_under_root!(root_path, relative_path, label:)
    root_lstat = root_path.lstat
    raise ValidationError, "#{label}: repository root must not be a symlink" if root_lstat.symlink?

    current = root_path
    Pathname.new(relative_path).each_filename.with_index do |component, index|
      current = current.join(component)
      stat = current.lstat
      raise ValidationError, "#{label}: symlinked path component" if stat.symlink?
      final = index == Pathname.new(relative_path).each_filename.to_a.length - 1
      if final
        raise ValidationError, "#{label}: source is not a regular file" unless stat.file?
      else
        raise ValidationError, "#{label}: parent component is not a directory" unless stat.directory?
      end
    rescue SystemCallError
      raise ValidationError, "#{label}: source path is unavailable"
    end

    unless current.realpath.to_s.start_with?("#{root_path.realpath}#{File::SEPARATOR}")
      raise ValidationError, "#{label}: source resolves outside repository root"
    end
    current
  end
  private_class_method :safe_regular_file_under_root!

  def verify_working_tree_source!(root_path, relative_path, expected_sha256, label:)
    working_path = safe_regular_file_under_root!(root_path, relative_path, label: label)
    unless Digest::SHA256.file(working_path).hexdigest == expected_sha256
      raise ValidationError, "#{label}: working-tree byte hash drift"
    end
    true
  end
  private_class_method :verify_working_tree_source!
end
