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
    assert_equal '1.3.0', @contract.dig('validator', 'version')
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

  def test_consumer_operation_contract_is_closed_and_adopts_exact_gate_b_schema
    rules = @contract.fetch('consumer_operation_decision_contract')
    assert_equal Validator::CONSUMER_OPERATION_DECISION_KEYS, rules.fetch('exact_top_level_keys')
    assert_equal Validator::CONSUMER_OPERATION_ALLOWED_ENVIRONMENTS, rules.fetch('allowed_environments')
    assert_equal 'approved_immutable_ticket_or_workflow_record', rules.fetch('required_attribution_method')
    assert_equal Validator::CONSUMER_OPERATION_ATTRIBUTION_KEYS,
                 rules.dig('nested_exact_keys', 'decision_attribution')
    assert_equal Validator::CONSUMER_OPERATION_TECHNICAL_EVIDENCE_KEYS,
                 rules.dig('nested_exact_keys', 'technical_evidence')
    assert_equal 'unprovisioned_blocked_external_attestation_required',
                 rules.dig('canonical_trust_state', 'state')
    refute rules.dig('canonical_trust_state', 'repository_local_approval_artifacts_sufficient')
    refute rules.dig('canonical_trust_state', 'signature_implementation_authorized')

    changed = deep_copy(@contract)
    changed['consumer_operation_decision_contract']['allowed_environments'] << 'production'
    assert_validation_error(/exact closed values or order changed/) do
      Validator.validate_contract!(changed, root: ROOT)
    end

    changed = deep_copy(@contract)
    changed['consumer_operation_decision_contract']['canonical_trust_state']['repository_local_approval_artifacts_sufficient'] = true
    assert_validation_error(/fail-closed external trust state changed/) do
      Validator.validate_contract!(changed, root: ROOT)
    end
  end

  def test_consumer_operation_decision_accepts_semantically_bound_isolated_fixture
    with_operation_fixture do |root, decision, now|
      assert Validator.validate_consumer_operation_decision!(
        decision, contract: @contract, root: root, now: now
      )
    end
  end

  def test_consumer_operation_decision_rejects_duplicate_unknown_missing_and_environment_drift
    assert_raises(Validator::ParseError) do
      Validator.parse_json(
        '{"artifact_type":"g0_governance_v2_consumer_operation_decision","operation":"activate","operation":"disable"}',
        label: '$.operation_decision'
      )
    end

    with_operation_fixture do |root, decision, now|
      unknown = deep_copy(decision)
      unknown['unexpected'] = true
      assert_validation_error(/unknown fields unexpected/) do
        Validator.validate_consumer_operation_decision!(unknown, contract: @contract, root: root, now: now)
      end

      missing = deep_copy(decision)
      missing.delete('technical_evidence')
      assert_validation_error(/missing fields technical_evidence/) do
        Validator.validate_consumer_operation_decision!(missing, contract: @contract, root: root, now: now)
      end

      invalid_environment = deep_copy(decision)
      invalid_environment['environment'] = 'production'
      assert_validation_error(/environment is not allowed/) do
        Validator.validate_consumer_operation_decision!(invalid_environment, contract: @contract, root: root, now: now)
      end

      canonical = deep_copy(decision)
      canonical['environment'] = 'local_canonical_checkout'
      assert_validation_error(/canonical external attestation is unprovisioned/) do
        Validator.validate_consumer_operation_decision!(canonical, contract: @contract, root: root, now: now)
      end
    end
  end

  def test_consumer_operation_decision_rejects_secret_message_hash_and_reference_drift
    with_operation_fixture do |root, decision, now|
      secret = deep_copy(decision)
      secret['decision_attribution']['access_token'] = 'do-not-echo-token'
      error = assert_validation_error(/decision_attribution.access_token/) do
        Validator.validate_consumer_operation_decision!(secret, contract: @contract, root: root, now: now)
      end
      refute_includes error.message, 'do-not-echo-token'

      stale_hash = deep_copy(decision)
      stale_hash['decision_attribution']['decision_message_sha256'] = '0' * 64
      assert_validation_error(/exact message byte hash mismatch/) do
        Validator.validate_consumer_operation_decision!(stale_hash, contract: @contract, root: root, now: now)
      end

      stale_reference = deep_copy(decision)
      stale_reference['decision_attribution']['decision_reference'] = 'workflow:GATE-B-OTHER'
      assert_validation_error(/message hash reference mismatch/) do
        Validator.validate_consumer_operation_decision!(stale_reference, contract: @contract, root: root, now: now)
      end
    end
  end

  def test_consumer_operation_decision_rejects_time_expiry_and_attribution_drift
    with_operation_fixture do |root, decision, now|
      early_recorded = deep_copy(decision)
      early_recorded['decision_attribution']['recorded_at'] = (now - 180).iso8601
      assert_validation_error(/predates source message/) do
        Validator.validate_consumer_operation_decision!(early_recorded, contract: @contract, root: root, now: now)
      end

      expired = deep_copy(decision)
      expired['expires_at'] = now.iso8601
      assert_validation_error(/not within its authorization window/) do
        Validator.validate_consumer_operation_decision!(expired, contract: @contract, root: root, now: now)
      end

      invalid_offset = deep_copy(decision)
      invalid_offset['decided_at'] = '2026-08-29T01:59:00'
      assert_validation_error(/explicit offset/) do
        Validator.validate_consumer_operation_decision!(invalid_offset, contract: @contract, root: root, now: now)
      end

      wrong_method = deep_copy(decision)
      wrong_method['decision_attribution']['recorded_at_basis'] = 'protected_repository_decision_record'
      assert_validation_error(/attribution method is not adopted/) do
        Validator.validate_consumer_operation_decision!(wrong_method, contract: @contract, root: root, now: now)
      end
    end
  end

  def test_consumer_operation_decision_rejects_reference_hash_path_prior_and_cross_binding_drift
    with_operation_fixture do |root, decision, now|
      stale_file = deep_copy(decision)
      File.write(File.join(root, 'evidence/preflight.json'), '{"drift":true}')
      assert_validation_error(/referenced byte hash drift/) do
        Validator.validate_consumer_operation_decision!(stale_file, contract: @contract, root: root, now: now)
      end
    end

    with_operation_fixture do |root, decision, now|
      unsafe = deep_copy(decision)
      unsafe['technical_evidence']['canonical_preflight']['path'] = '../outside.json'
      assert_validation_error(/unsafe repository-relative path/) do
        Validator.validate_consumer_operation_decision!(unsafe, contract: @contract, root: root, now: now)
      end

      invalid_prior = deep_copy(decision)
      invalid_prior['prior_state']['expected_prior_pointer_sha256'] = '0' * 64
      assert_validation_error(/invalid closed prior-state representation/) do
        Validator.validate_consumer_operation_decision!(invalid_prior, contract: @contract, root: root, now: now)
      end

      adoption_drift = deep_copy(decision)
      adoption_drift['technical_evidence']['gate_a_adoption'] = decision['technical_evidence']['local_observation']
      assert_validation_error(/adoption reference drift/) do
        Validator.validate_consumer_operation_decision!(adoption_drift, contract: @contract, root: root, now: now)
      end

      candidate_drift = deep_copy(decision)
      candidate_drift['technical_evidence']['candidate_bundle'] = decision['technical_evidence']['local_observation']
      assert_validation_error(/target reference drift/) do
        Validator.validate_consumer_operation_decision!(candidate_drift, contract: @contract, root: root, now: now)
      end
    end
  end

  def test_consumer_operation_decision_rejects_arbitrary_actor_thread_and_source_time
    mutations = {
      identity: ->(row) { row['actor']['identity'] = 'Arbitrary Actor' },
      capacity: ->(row) { row['actor']['authority_capacity'] = 'developer' },
      actor_thread: ->(row) { row['actor']['decision_thread_id'] = 'arbitrary-thread' },
      attribution_thread: lambda do |row|
        row['decision_attribution']['decision_reference'] = row['decision_attribution']['decision_reference'].sub(
          /\Acodex_thread:[^#]+#/, 'codex_thread:arbitrary-thread#'
        )
      end,
      null_source: ->(row) { row['decision_attribution']['source_message_at'] = nil },
      implicit_offset: ->(row) { row['decision_attribution']['source_message_at'] = '2026-08-29T01:58:00' },
      source_decision_mismatch: ->(row) { row['decision_attribution']['source_message_at'] = '2026-08-29T01:57:00Z' }
    }
    mutations.each do |name, mutation|
      with_operation_fixture do |root, decision, now|
        mutation.call(decision)
        assert_raises(Validator::ValidationError, name.to_s) do
          Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
        end
      end
    end
  end

  def test_consumer_operation_decision_rejects_generic_or_mismatched_approval_evidence
    with_operation_fixture do |root, decision, now|
      rewrite_reference_json!(root, decision.fetch('approval_evidence'), { 'status' => 'approved' })
      assert_validation_error(/missing fields/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
    end

    with_operation_fixture do |root, decision, now|
      approval = referenced_json(root, decision.fetch('approval_evidence'))
      approval['scope']['deployment'] = true
      rewrite_reference_json!(root, decision.fetch('approval_evidence'), approval)
      assert_validation_error(/only one consumer selection operation/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
    end

    with_operation_fixture do |root, decision, now|
      approval = referenced_json(root, decision.fetch('approval_evidence'))
      approval['actor']['identity'] = 'Arbitrary Actor'
      rewrite_reference_json!(root, decision.fetch('approval_evidence'), approval)
      assert_validation_error(/cross-binding mismatch/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
    end
  end

  def test_consumer_operation_decision_rejects_generic_and_semantically_drifted_local_or_preflight_evidence
    with_operation_fixture do |root, decision, now|
      reference = decision.fetch('technical_evidence').fetch('local_observation')
      rewrite_reference_json!(root, reference, { 'status' => 'PASS', 'authority_effect' => 'none' })
      assert_validation_error(/missing fields/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
    end

    %w[environment root candidate_bundle validator_contract selector_source prior_state authority_effect expires_at].each do |field|
      with_operation_fixture do |root, decision, now|
        reference = decision.fetch('technical_evidence').fetch('local_observation')
        document = referenced_json(root, reference)
        document[field] = case field
                          when 'environment' then 'local_canonical_checkout'
                          when 'root' then '/tmp/arbitrary-root'
                          when 'authority_effect' then 'authorizes_activation'
                          when 'expires_at' then (Time.iso8601(decision.fetch('decided_at')) - 1).iso8601
                          when 'prior_state' then document.fetch(field).merge('prior_state_reason' => 'missing_pointer')
                          else decision.fetch('technical_evidence').fetch('gate_a_adoption')
                          end
        rewrite_reference_json!(root, reference, document)
        assert_raises(Validator::ValidationError, field) do
          Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
        end
      end
    end

    with_operation_fixture do |root, decision, now|
      reference = decision.fetch('technical_evidence').fetch('canonical_preflight')
      document = referenced_json(root, reference)
      document['checks']['no_authority_effect'] = false
      rewrite_reference_json!(root, reference, document)
      assert_validation_error(/every check must PASS/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
    end
  end

  def test_consumer_operation_decision_rejects_non_independent_or_nonpass_review_evidence
    %w[verdict authority_effect reviewer].each do |field|
      with_operation_fixture do |root, decision, now|
        reference = decision.fetch('technical_evidence').fetch('independent_review')
        document = referenced_json(root, reference)
        case field
        when 'verdict' then document['verdict'] = 'FAIL'
        when 'authority_effect' then document['authority_effect'] = 'authorizes_activation'
        when 'reviewer' then document['reviewer']['identity'] = decision.fetch('actor').fetch('identity')
        end
        rewrite_reference_json!(root, reference, document)
        assert_raises(Validator::ValidationError, field) do
          Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
        end
      end
    end
  end

  def test_consumer_operation_decision_enforces_current_evidence_expiry_order_and_bounded_ttls
    with_operation_fixture do |root, decision, now|
      too_long = deep_copy(decision)
      decided = Time.iso8601(too_long.fetch('decided_at'))
      too_long['expires_at'] = (decided + Validator::CONSUMER_MAXIMUM_DECISION_TTL_SECONDS + 1).iso8601
      assert_validation_error(/decision TTL exceeds/) do
        Validator.validate_consumer_operation_decision!(too_long, contract: @contract, root: root, now: now)
      end
    end

    with_operation_fixture do |root, decision, now|
      local_ref = decision.fetch('technical_evidence').fetch('local_observation')
      local = referenced_json(root, local_ref)
      observed = Time.iso8601(local.fetch('observed_at'))
      local['expires_at'] = (observed + Validator::CONSUMER_MAXIMUM_EVIDENCE_TTL_SECONDS + 1).iso8601
      rewrite_reference_json!(root, local_ref, local)
      assert_validation_error(/evidence TTL exceeds/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
    end

    with_operation_fixture do |root, decision, now|
      local_ref = decision.fetch('technical_evidence').fetch('local_observation')
      local = referenced_json(root, local_ref)
      local['expires_at'] = now.iso8601
      rewrite_reference_json!(root, local_ref, local)
      refresh_review_binding!(root, decision)
      assert_validation_error(/semantic evidence has expired/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
    end

    with_operation_fixture do |root, decision, now|
      preflight_ref = decision.fetch('technical_evidence').fetch('canonical_preflight')
      preflight = referenced_json(root, preflight_ref)
      local = referenced_json(root, decision.fetch('technical_evidence').fetch('local_observation'))
      preflight['observed_at'] = (Time.iso8601(local.fetch('observed_at')) - 1).iso8601
      rewrite_reference_json!(root, preflight_ref, preflight)
      refresh_review_binding!(root, decision)
      assert_validation_error(/evidence observation ordering is invalid/) do
        Validator.validate_consumer_operation_decision!(decision, contract: @contract, root: root, now: now)
      end
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

  def with_operation_fixture
    Dir.mktmpdir('g0-v2-operation-contract') do |root|
      FileUtils.mkdir_p(File.join(root, 'evidence'))
      FileUtils.mkdir_p(File.join(root, 'candidate'))
      FileUtils.mkdir_p(File.join(root, 'docs/new-simrs-rebuild/phase-0'))
      FileUtils.mkdir_p(File.join(root, 'scripts'))
      File.binwrite(File.join(root, 'candidate/G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json'), '{"bundle":"candidate"}')
      File.binwrite(File.join(root, Validator::CONSUMER_VALIDATOR_CONTRACT_PATH),
                    Validator.canonical_json(@contract) + "\n")
      File.binwrite(File.join(root, Validator::CONSUMER_SELECTOR_SOURCE_PATH), '# selector source')
      adoption_reference = @contract.fetch('adopted_sources').fetch('adoption_decision')
      adoption_path = File.join(root, adoption_reference.fetch('path'))
      FileUtils.mkdir_p(File.dirname(adoption_path))
      FileUtils.cp(File.join(ROOT, adoption_reference.fetch('path')), adoption_path)
      adoption_document = JSON.parse(File.binread(adoption_path))

      reference_for = lambda do |relative, bundle: false|
        source = bundle ? File.join(root, relative, Validator::CONSUMER_OPERATION_BUNDLE_MANIFEST) : File.join(root, relative)
        { 'path' => relative, 'sha256' => Digest::SHA256.file(source).hexdigest }
      end
      candidate = {
        'path' => 'candidate',
        'sha256' => Digest::SHA256.file(File.join(root, 'candidate/G0_GOVERNANCE_V2_BUNDLE_MANIFEST.json')).hexdigest
      }
      now = Time.iso8601('2026-08-29T02:00:00Z')
      decided_at = now - 120
      decision_thread_id = adoption_document.dig('decider', 'decision_reference').match(/\Acodex_thread:([^#]+)#/)[1]
      message = 'Approve one exact synthetic-only Gate-B consumer activation operation.'
      message_sha = Digest::SHA256.hexdigest(message.b)
      attribution = {
        'decision_reference' => "codex_thread:#{decision_thread_id}#gate-b#decision-message-sha256:#{message_sha}",
        'decision_message' => message,
        'decision_message_encoding' => 'exact_utf8_bytes_no_normalization',
        'decision_message_sha256' => message_sha,
        'source_message_at' => decided_at.iso8601,
        'recorded_at' => (now - 60).iso8601,
        'recorded_at_basis' => 'approved_immutable_ticket_or_workflow_record'
      }
      prior = {
        'prior_state_reason' => 'initial_state',
        'expected_prior_pointer_sha256' => nil,
        'observed_unreadable_pointer_sha256' => nil
      }
      decision = {
        'artifact_type' => 'g0_governance_v2_consumer_operation_decision',
        'schema_version' => 1,
        'decision_id' => 'G0-V2-GATE-B-FIXTURE-001',
        'status' => 'approved',
        'effect' => 'authorizes_one_consumer_selection_operation',
        'data_boundary' => 'synthetic_only',
        'operation' => 'activate',
        'environment' => 'isolated_test_fixture',
        'actor' => {
          'identity' => adoption_document.dig('decider', 'identity'),
          'authority_capacity' => adoption_document.dig('decider', 'authority_capacity'),
          'decision_thread_id' => decision_thread_id
        },
        'conditions' => ['one_exact_operation_only', 'synthetic_only'],
        'decided_at' => decided_at.iso8601,
        'expires_at' => (now + 3600).iso8601,
        'adoption_decision' => adoption_reference,
        'approval_evidence' => nil,
        'prior_state' => prior,
        'candidate_bundle' => candidate,
        'held_selection' => nil,
        'recover_outcome' => nil,
        'decision_attribution' => attribution,
        'technical_evidence' => nil
      }

      contract_reference = reference_for.call(Validator::CONSUMER_VALIDATOR_CONTRACT_PATH)
      selector_reference = reference_for.call(Validator::CONSUMER_SELECTOR_SOURCE_PATH)
      common = {
        'schema_version' => 1,
        'status' => 'PASS',
        'data_boundary' => 'synthetic_only',
        'environment' => 'isolated_test_fixture',
        'root' => Pathname.new(root).realpath.to_s,
        'observed_at' => (decided_at - 60).iso8601,
        'expires_at' => (now + 3600).iso8601,
        'candidate_bundle' => candidate,
        'validator_contract' => contract_reference,
        'selector_source' => selector_reference,
        'prior_state' => prior,
        'authority_effect' => 'none'
      }
      write_fixture_json(root, 'evidence/observation.json', common.merge(
        'artifact_type' => 'g0_governance_v2_gate_b_local_observation',
        'evidence_id' => 'G0-V2-OBS-FIXTURE',
        'effect' => 'none_observation_only',
        'observed_at' => (decided_at - 180).iso8601
      ))
      write_fixture_json(root, 'evidence/preflight.json', common.merge(
        'artifact_type' => 'g0_governance_v2_gate_b_canonical_preflight',
        'evidence_id' => 'G0-V2-PREFLIGHT-FIXTURE',
        'effect' => 'none_preflight_only',
        'observed_at' => (decided_at - 120).iso8601,
        'checks' => Validator::CONSUMER_PREFLIGHT_CHECK_KEYS.to_h { |key| [key, true] }
      ))
      observation_reference = reference_for.call('evidence/observation.json')
      preflight_reference = reference_for.call('evidence/preflight.json')
      write_fixture_json(root, 'evidence/review.json', common.merge(
        'artifact_type' => 'g0_governance_v2_gate_b_independent_review',
        'evidence_id' => 'G0-V2-REVIEW-FIXTURE',
        'effect' => 'none_review_evidence_only',
        'observed_at' => (decided_at - 60).iso8601,
        'reviewer' => {
          'identity' => 'Independent Fixture Reviewer',
          'capacity' => 'independent_technical_security_reviewer'
        },
        'reviewed_evidence' => {
          'local_observation' => observation_reference,
          'canonical_preflight' => preflight_reference
        },
        'verdict' => 'PASS'
      ))
      decision['technical_evidence'] = {
        'gate_a_adoption' => adoption_reference,
        'local_observation' => observation_reference,
        'candidate_bundle' => candidate,
        'canonical_preflight' => preflight_reference,
        'validator_contract' => contract_reference,
        'selector_source' => selector_reference,
        'independent_review' => reference_for.call('evidence/review.json')
      }
      approval = {
        'artifact_type' => 'g0_governance_v2_gate_b_operation_approval',
        'schema_version' => 1,
        'approval_id' => 'G0-V2-APPROVAL-FIXTURE',
        'scope' => Validator::CONSUMER_APPROVAL_SCOPE_KEYS.to_h { |key| [key, key == 'consumer_selection_operation'] }
      }
      Validator::CONSUMER_APPROVAL_CROSS_EQUAL_KEYS.each { |key| approval[key] = decision.fetch(key) }
      write_fixture_json(root, 'evidence/approval.json', approval)
      decision['approval_evidence'] = reference_for.call('evidence/approval.json')
      yield root, decision, now
    end
  end

  def write_fixture_json(root, relative, document)
    path = File.join(root, relative)
    FileUtils.mkdir_p(File.dirname(path))
    File.binwrite(path, Validator.canonical_json(document) + "\n")
  end

  def referenced_json(root, reference)
    JSON.parse(File.binread(File.join(root, reference.fetch('path'))))
  end

  def rewrite_reference_json!(root, reference, document)
    write_fixture_json(root, reference.fetch('path'), document)
    reference['sha256'] = Digest::SHA256.file(File.join(root, reference.fetch('path'))).hexdigest
  end

  def refresh_review_binding!(root, decision)
    evidence = decision.fetch('technical_evidence')
    review_reference = evidence.fetch('independent_review')
    review = referenced_json(root, review_reference)
    review['reviewed_evidence'] = {
      'local_observation' => evidence.fetch('local_observation'),
      'canonical_preflight' => evidence.fetch('canonical_preflight')
    }
    rewrite_reference_json!(root, review_reference, review)
  end

  def assert_validation_error(pattern, &block)
    error = assert_raises(Validator::ValidationError, &block)
    assert_match pattern, error.message
    error
  end
end
