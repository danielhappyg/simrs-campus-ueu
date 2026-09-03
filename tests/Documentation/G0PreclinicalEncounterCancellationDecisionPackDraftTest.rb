# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

require_relative '../../scripts/g0-proportional-governance-v2'

class G0PreclinicalEncounterCancellationDecisionPackDraftTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  DRAFT_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-0/PAR_PRECLINICAL_ENCOUNTER_CANCELLATION_DECISION_PACK_DRAFT_2026-08-29.json'
  )
  CONTRACT_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json')
  LEDGER_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json')
  Core = G0ProportionalGovernanceV2

  class PackError < StandardError; end

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id status effect data_boundary
    created_date authority_boundary source_bindings family_scope derived_tier
    primary_capability_rows reporting_dependencies affected_dependencies
    excluded_domains decision_questions recommended_contract denial_contract
    audit_contract reconciliation_contract recovery_contract required_scenarios
    owner_authorities independent_control application_truth authorization
    non_effect_rules secret_handling
  ].freeze
  SOURCE_BINDING_KEYS = %w[role path sha256 source_status authority_effect].freeze
  FAMILY_KEYS = %w[
    family_id title care_settings primary_capability_ids affected_dependency_ids
    reporting_dependency_ids workflow_ids row_decision_mode
    family_inheritance_permitted
  ].freeze
  DERIVED_TIER_KEYS = %w[
    declared_tier derivation_status consequence_flags tier_approval_status
    tier_decision_reference
  ].freeze
  PRIMARY_ROW_KEYS = %w[
    requirement_id batch entry_index entry_index_base entry_sha256
    current_decision_status current_disposition proposed_family_role
    event_required event_id event_status event_owner_assignment_status
    event_owner_identity event_owner_capacity event_decision event_decided_at
    event_implementation_authorized
  ].freeze
  REPORTING_KEYS = %w[disposition_boundary rows v1_source_id_defect].freeze
  REPORTING_ROW_KEYS = %w[
    requirement_id batch entry_index entry_index_base entry_sha256
    current_decision_status current_disposition governance_disposed
  ].freeze
  REPORTING_DEFECT_KEYS = %w[
    defect_id status historical_v1_bytes_mutable corrections
    resolution_event_id resolution_owner_identity resolution_decision
    resolution_implementation_authorized
  ].freeze
  REPORTING_CORRECTION_KEYS = %w[
    requirement_id observed_v1_source_key observed_v1_requirement_id
    observed_v1_source_entity required_v2_source_key required_v2_requirement_id
    required_v2_source_entity resolution_status resolution_decision
  ].freeze
  DEPENDENCY_KEYS = %w[
    requirement_id relationship reversal_included decision_effect
  ].freeze
  EXCLUDED_DOMAIN_KEYS = %w[
    domain capability_range policy disposition_effect
  ].freeze
  DECISION_KEYS = %w[
    decision_id topic recommendation required_authorities status recorded_choice
    decision_reference implementation_authorized
  ].freeze
  RECOMMENDED_KEYS = %w[
    transition terminal_state eligible_when immutable_cancellation_fact
    preserved_history active_projection_effect historical_projection_effect
    automatic_reversal_effect external_effect
  ].freeze
  DENIAL_KEYS = %w[
    authorization_denial business_denials validation_http_status
    mutation_on_denial free_text_in_denial_audit
  ].freeze
  AUTH_DENIAL_KEYS = %w[code http_status business_state_disclosed].freeze
  BUSINESS_DENIAL_KEYS = %w[code http_status].freeze
  AUDIT_KEYS = %w[
    success_action success_outcome denial_outcome atomic_with_mutation safe_metadata
    excluded_metadata request_correlation_location audit_approval_status
  ].freeze
  RECONCILIATION_KEYS = %w[
    status one_to_one_controls state_controls history_controls projection_controls
    retry_and_recovery_controls reporting_source_id_defect_must_be_resolved
  ].freeze
  RECOVERY_KEYS = %w[
    status pre_fact_empty_migration_rollback post_fact_runtime_rollback
    ordinary_rollback_may_delete_populated_history restore_validation
    synthetic_reset_exception recovery_approval_status
  ].freeze
  SCENARIO_KEYS = %w[scenario_id kind status].freeze
  OWNER_KEYS = %w[
    capacity authority_id domain_scope status institutional_id display_name
    appointment_reference decision_reference decision_recorded
    implementation_authorized
  ].freeze
  INDEPENDENT_KEYS = %w[
    required required_capacity status institutional_id display_name
    appointment_reference review_reference decision_recorded
    implementation_authorized separation_rule
  ].freeze
  TRUTH_KEYS = %w[finding_id status finding decision_effect evidence_bindings].freeze
  EVIDENCE_BINDING_KEYS = %w[path sha256].freeze
  SECRET_KEYS = %w[
    credentials_permitted passwords_permitted tokens_permitted
    private_keys_permitted connection_strings_permitted
  ].freeze
  AUTHORIZATION_KEYS = %w[
    owner_appointments owner_decisions_recorded primary_capability_dispositions
    reporting_capability_dispositions tier_accepted independent_control_accepted
    implementation application_slice governance_consumer_activation
    canonical_pointer_or_selector_mutation deployment hosted_migration
    real_patient_data live_integration g0_closure g3_acceptance
  ].freeze

  EXPECTED_SOURCE_PATHS = {
    'functional_requirement_pack' => 'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md',
    'companion_architecture_decision' => 'docs/operations/ADR_CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_2026-08-27.md',
    'governance_v2_proposal' => 'docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md',
    'governance_v2_adr' => 'docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md',
    'governance_v2_contract' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json',
    'engineering_evidence_map_v2' => 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json',
    'coverage_ledger_v2' => 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json',
    'batch_b_registration_source_register' => 'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json',
    'batch_g_reporting_source_register' => 'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json'
  }.freeze
  EXPECTED_SOURCE_HASHES = {
    'functional_requirement_pack' => '72de10f581d27fbe4dc0ce296f35c257cc7eea4d7a88f7ed15e284ed5573f6b5',
    'companion_architecture_decision' => '9d5d0042fe79eaa80fb6f7f9441ee36f35a4e43f20db0bad3737ce7cbebb1335',
    'governance_v2_proposal' => 'f5c635006f878a68c4b0775be5262115fe38e185562d3be558e02d8b28c38695',
    'governance_v2_adr' => 'cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962',
    'governance_v2_contract' => 'b74fd990cb0a12692dc78bb808f688bab99924bf8f8ae80b109e72d9ba4d9bba',
    'engineering_evidence_map_v2' => '3b1005a31087896b412a8f64a3dbfced2c0ab655be78c32abd7c0743ae3811d5',
    'coverage_ledger_v2' => '0f312713000b0092de313baee2d3cc6d34695c2fefd717c620871268045ac58c',
    'batch_b_registration_source_register' => '3db0a698be4f7729f24f997dbbc54e69c7c98442237fb7a9d4b12358971eeb1f',
    'batch_g_reporting_source_register' => '53bef20b3f509c543213e649b2d5f6644e9dbe00a7dae34d209c57a93137612e'
  }.freeze
  PRIMARY_HASHES = {
    'PAR-REG-001' => [3, 'ec52cbc9333b5ecdd4cd13bf1c42d519ad9154ef8db7f645214ee3157aef69de'],
    'PAR-REG-002' => [4, '7eab54bff057697be92e115691871e73dba8ca47b62079d9e64436c6d5540a99'],
    'PAR-REG-003' => [5, '11878c077e7d65dccd1bc186998333de632106b06999e71240c0c7c1a296d873']
  }.freeze
  REPORTING_HASHES = {
    'PAR-RPT-007' => [9, '494490dfc53835d803a9759ef555cec791e6030d4d42a015e34376f1bb8e3502'],
    'PAR-RPT-013' => [15, '3f4065fbbb301a6c88c2cc5cf2291c5b111b90338b6e81a474865e581ae26ff4'],
    'PAR-RPT-016' => [18, '39662ce62fb4a9922072729dbbef32e1a616c35a679a34d848c0a1bbce080b48'],
    'PAR-RPT-017' => [19, 'c287df04d43eb34f1405a973548d036a60d55e9a66ed5b059d64caa9ff853701'],
    'PAR-RPT-019' => [21, 'd0e73fc1cc512c398ffbe2ece98c1a44658ef0f9b504b0f2118eef0ee19c1c5c']
  }.freeze
  AFFECTED_IDS = %w[
    PAR-CLN-002 PAR-CLN-003 PAR-CLN-004 PAR-CLN-005 PAR-CLN-006 PAR-CLN-007
    PAR-RMIK-001 PAR-RMIK-002 PAR-ADM-001 PAR-ADM-002 PAR-ADM-037
  ].freeze
  WORKFLOW_IDS = %w[E2E-01 E2E-02 E2E-03 E2E-04 E2E-12 E2E-15 E2E-16].freeze

  def setup
    @draft = Core.parse_json_file(DRAFT_PATH, label: '$.cancellation_draft')
    @contract = Core.parse_json_file(CONTRACT_PATH, label: '$.governance_v2_contract')
    @ledger = Core.parse_json_file(LEDGER_PATH, label: '$.coverage_ledger')
  end

  def test_closed_proposed_pending_no_effect_identity_and_types
    validate_closed_schema!(@draft)
    assert_equal 'par_preclinical_encounter_cancellation_decision_pack_draft', @draft.fetch('artifact_type')
    assert_equal 1, @draft.fetch('schema_version')
    assert_equal 'PAR-PRECLINICAL-ENCOUNTER-CANCELLATION-DECISION-PACK-DRAFT-2026-08-29', @draft.fetch('artifact_id')
    assert_equal 'PROPOSED_PENDING_OWNER_AND_CONTROL_DECISIONS', @draft.fetch('status')
    assert_equal 'none_decision_preparation_only', @draft.fetch('effect')
    assert_equal 'synthetic_only', @draft.fetch('data_boundary')
    assert_equal '2026-08-29', @draft.fetch('created_date')
    assert_kind_of String, @draft.fetch('authority_boundary')
  end

  def test_every_source_is_exact_regular_file_hash_bound
    bindings = @draft.fetch('source_bindings')
    assert_equal EXPECTED_SOURCE_PATHS.keys, bindings.map { |row| row.fetch('role') }

    bindings.each do |binding|
      assert_closed_order(binding, SOURCE_BINDING_KEYS)
      role = binding.fetch('role')
      assert_equal EXPECTED_SOURCE_PATHS.fetch(role), binding.fetch('path')
      assert_equal EXPECTED_SOURCE_HASHES.fetch(role), binding.fetch('sha256')
      assert_match Core::SAFE_RELATIVE_PATH_PATTERN, binding.fetch('path')
      path = File.join(ROOT, binding.fetch('path'))
      stat = File.lstat(path)
      assert stat.file? && !stat.symlink?, binding.fetch('path')
      assert_equal binding.fetch('sha256'), Digest::SHA256.file(path).hexdigest
      assert_kind_of String, binding.fetch('source_status')
      assert_kind_of String, binding.fetch('authority_effect')
    end
  end

  def test_primary_rows_are_the_only_covered_rows_and_each_requires_its_own_null_event
    family = @draft.fetch('family_scope')
    assert_equal PRIMARY_HASHES.keys, family.fetch('primary_capability_ids')
    refute family.fetch('family_inheritance_permitted')
    assert_equal 'one_explicit_event_per_primary_row', family.fetch('row_decision_mode')

    rows = @draft.fetch('primary_capability_rows')
    assert_equal PRIMARY_HASHES.keys, rows.map { |row| row.fetch('requirement_id') }
    rows.each do |row|
      assert_closed_order row, PRIMARY_ROW_KEYS
      assert_equal 'B', row.fetch('batch')
      assert_equal 0, row.fetch('entry_index_base')
      assert_equal PRIMARY_HASHES.fetch(row.fetch('requirement_id')), row.values_at('entry_index', 'entry_sha256')
      assert_equal %w[pending pending], row.values_at('current_decision_status', 'current_disposition')
      assert row.fetch('event_required')
      assert_equal %w[pending pending], row.values_at('event_status', 'event_owner_assignment_status')
      %w[event_id event_owner_identity event_owner_capacity event_decision event_decided_at].each do |key|
        assert_nil row.fetch(key), "#{row.fetch('requirement_id')} #{key}"
      end
      refute row.fetch('event_implementation_authorized')
    end

    assert_source_rows(@draft.fetch('primary_capability_rows'), 'docs/new-simrs-rebuild/phase-0/G0_BATCH_B_DECISION_REGISTER_2026-08-25.json')
  end

  def test_reporting_dependencies_remain_pending_undisposed_and_hash_bound
    reporting = @draft.fetch('reporting_dependencies')
    rows = reporting.fetch('rows')
    assert_equal REPORTING_HASHES.keys, rows.map { |row| row.fetch('requirement_id') }
    rows.each do |row|
      assert_closed_order row, REPORTING_ROW_KEYS
      assert_equal 'G', row.fetch('batch')
      assert_equal 0, row.fetch('entry_index_base')
      assert_equal REPORTING_HASHES.fetch(row.fetch('requirement_id')), row.values_at('entry_index', 'entry_sha256')
      assert_equal %w[pending pending], row.values_at('current_decision_status', 'current_disposition')
      refute row.fetch('governance_disposed')
    end
    assert_source_rows(rows, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json')
  end

  def test_v1_reporting_source_id_swaps_are_explicit_unresolved_v2_corrections
    defect = @draft.dig('reporting_dependencies', 'v1_source_id_defect')
    assert_closed_order defect, REPORTING_DEFECT_KEYS
    assert_equal 'G0-V1-REPORTING-SOURCE-ID-AND-CLINICAL-LINEAGE-DEFECTS', defect.fetch('defect_id')
    assert_equal 'unresolved_requires_v2_correction_before_any_reporting_disposition_or_implementation', defect.fetch('status')
    refute defect.fetch('historical_v1_bytes_mutable')
    actual_corrections = defect.fetch('corrections').map do |row|
      assert_closed_order row, REPORTING_CORRECTION_KEYS
      row.values_at(*REPORTING_CORRECTION_KEYS)
    end
    assert_equal [
      ['PAR-RPT-007', 'B_RJ', 'PAR-REG-002', 'outpatient_encounter_version', 'B_RJ', 'PAR-REG-003', 'outpatient_encounter_version', 'pending_v2_correction_event', nil],
      ['PAR-RPT-013', 'B_IGD', 'PAR-REG-003', 'emergency_encounter_version', 'B_IGD', 'PAR-REG-002', 'emergency_encounter_version', 'pending_v2_correction_event', nil],
      ['PAR-RPT-013', 'C_RJ', 'PAR-CLN-004', 'final_outpatient_record_version', 'C_IGD', 'PAR-CLN-003', 'emergency_clinical_entry', 'pending_owner_choice_correct_to_igd_or_remove_if_registration_only', nil],
      ['PAR-RPT-016', 'B_RJ', 'PAR-REG-002', 'outpatient_encounter_version', 'B_RJ', 'PAR-REG-003', 'outpatient_encounter_version', 'pending_v2_correction_event', nil],
      ['PAR-RPT-019', 'B_RJ', 'PAR-REG-002', 'outpatient_encounter_version', 'B_RJ', 'PAR-REG-003', 'outpatient_encounter_version', 'pending_v2_correction_event', nil],
      ['PAR-RPT-019', 'B_IGD', 'PAR-REG-003', 'emergency_encounter_version', 'B_IGD', 'PAR-REG-002', 'emergency_encounter_version', 'pending_v2_correction_event', nil]
    ], actual_corrections
    %w[resolution_event_id resolution_owner_identity resolution_decision].each { |key| assert_nil defect.fetch(key) }
    refute defect.fetch('resolution_implementation_authorized')

    batch_g = JSON.parse(File.read(File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_BATCH_G_DECISION_REGISTER_2026-08-25.json')))
    defect.fetch('corrections').each do |correction|
      source_roles = batch_g.fetch('entries').find do |entry|
        entry.fetch('requirement_id') == correction.fetch('requirement_id')
      end.dig('definition_contract', 'source_roles')
      observed = source_roles.find { |source| source.fetch('source_key') == correction.fetch('observed_v1_source_key') }
      assert_equal correction.fetch('observed_v1_requirement_id'), observed.fetch('requirement_id')
      assert_equal correction.fetch('observed_v1_source_entity'), observed.fetch('entity')
      refute_equal correction.fetch('required_v2_requirement_id'), observed.fetch('requirement_id')
      assert_nil correction.fetch('resolution_decision')
    end
  end

  def test_complete_consequence_map_derives_t3_and_is_not_approved
    tier = @draft.fetch('derived_tier')
    assert_closed_order tier, DERIVED_TIER_KEYS
    expected_flags = @contract.dig('closed_values', 'consequence_flags')
    assert_equal expected_flags, tier.fetch('consequence_flags').keys
    assert tier.fetch('consequence_flags').values.all? { |value| value == true || value == false }
    assert_equal 'T3_INDEPENDENT_CONTROL', Core.derived_tier(tier.fetch('consequence_flags'), @contract)
    assert_equal 'T3_INDEPENDENT_CONTROL', tier.fetch('declared_tier')
    assert_equal 'pending', tier.fetch('tier_approval_status')
    assert_nil tier.fetch('tier_decision_reference')
  end

  def test_affected_dependencies_workflows_and_excluded_ranges_are_exact
    assert_equal AFFECTED_IDS, @draft.dig('family_scope', 'affected_dependency_ids')
    assert_equal WORKFLOW_IDS, @draft.dig('family_scope', 'workflow_ids')
    dependencies = @draft.fetch('affected_dependencies')
    assert_equal AFFECTED_IDS, dependencies.map { |row| row.fetch('requirement_id') }
    dependencies.each do |row|
      assert_closed_order row, DEPENDENCY_KEYS
      refute row.fetch('reversal_included')
      assert_kind_of String, row.fetch('relationship')
      assert_kind_of String, row.fetch('decision_effect')
    end
    assert_equal 'explicitly_excluded_reversal_dependency_only', dependencies.find { |row| row.fetch('requirement_id') == 'PAR-CLN-007' }.fetch('decision_effect')

    exclusions = @draft.fetch('excluded_domains')
    actual_exclusions = exclusions.map do |row|
      assert_closed_order row, EXCLUDED_DOMAIN_KEYS
      assert_equal 'none', row.fetch('disposition_effect')
      row.values_at('domain', 'capability_range')
    end
    assert_equal [
      ['BPJS_AND_LIVE_CLAIM_CONNECTIVITY', 'PAR-BPJS-001..PAR-BPJS-002'],
      ['CLAIMS', 'PAR-CLM-001..PAR-CLM-006'],
      ['PHARMACY', 'PAR-PHA-001..PAR-PHA-020'],
      ['PHARMACY_IBS', 'PAR-ORP-001'],
      ['INVENTORY', 'PAR-PWH-001..PAR-PWH-023'],
      ['BILLING_AND_FINANCE', 'PAR-FIN-001..PAR-FIN-019']
    ], actual_exclusions
    validate_excluded_capability_universe!(exclusions)

    missing = deep_copy(exclusions).reject { |row| row.fetch('domain') == 'PHARMACY_IBS' }
    assert_raises(PackError) { validate_excluded_capability_universe!(missing) }
    unknown = deep_copy(exclusions)
    unknown.find { |row| row.fetch('domain') == 'PHARMACY_IBS' }['capability_range'] = 'PAR-ORP-999'
    assert_raises(PackError) { validate_excluded_capability_universe!(unknown) }
    duplicate = deep_copy(exclusions) << deep_copy(exclusions.first)
    assert_raises(PackError) { validate_excluded_capability_universe!(duplicate) }
    overlap = deep_copy(exclusions) << {
      'domain' => 'PHARMACY_OVERLAP', 'capability_range' => 'PAR-PHA-010..PAR-PHA-012',
      'policy' => 'invalid_overlap_fixture', 'disposition_effect' => 'none'
    }
    assert_raises(PackError) { validate_excluded_capability_universe!(overlap) }
  end

  def test_all_twenty_owner_choices_are_null_pending_and_unauthorized
    questions = @draft.fetch('decision_questions')
    assert_equal (1..20).map { |number| format('CAN-%02d', number) }, questions.map { |row| row.fetch('decision_id') }
    questions.each do |row|
      assert_closed_order row, DECISION_KEYS
      assert_equal 'pending', row.fetch('status')
      assert_nil row.fetch('recorded_choice')
      assert_nil row.fetch('decision_reference')
      refute row.fetch('implementation_authorized')
      refute_empty row.fetch('required_authorities')
      assert_kind_of String, row.fetch('topic')
      assert_kind_of String, row.fetch('recommendation')
    end
    authority_ids = @draft.fetch('owner_authorities').map { |row| row.fetch('authority_id') }
    assert_equal authority_ids.uniq, authority_ids
    questions.flat_map { |row| row.fetch('required_authorities') }.uniq.each do |authority_id|
      assert_equal 1, authority_ids.count(authority_id), "unresolved authority reference #{authority_id}"
    end
  end

  def test_recommended_terminal_denial_audit_reconciliation_recovery_and_scenarios_are_closed
    recommendation = @draft.fetch('recommended_contract')
    assert_equal 'REGISTERED->CANCELLED', recommendation.fetch('transition')
    assert_equal 'CANCELLED', recommendation.fetch('terminal_state')
    assert_includes recommendation.fetch('immutable_cancellation_fact'), 'one_fact_per_encounter'
    assert_equal 'none_hard_block_if_any_dependent_fact_exists', recommendation.fetch('automatic_reversal_effect')
    assert_equal 'none', recommendation.fetch('external_effect')

    denial = @draft.fetch('denial_contract')
    assert_equal 'authorization.denied', denial.dig('authorization_denial', 'code')
    assert_equal 403, denial.dig('authorization_denial', 'http_status')
    expected_codes = %w[
      encounter_not_registered clinical_activity_exists diagnostic_activity_exists
      rm_activity_exists downstream_activity_exists idempotency_key_conflict already_cancelled
    ]
    assert_equal expected_codes, denial.fetch('business_denials').map { |row| row.fetch('code') }
    assert denial.fetch('business_denials').all? { |row| row.fetch('http_status') == 409 }
    refute denial.fetch('mutation_on_denial')
    refute denial.fetch('free_text_in_denial_audit')

    audit = @draft.fetch('audit_contract')
    assert_equal %w[encounter.cancel SUCCESS DENIED], audit.values_at('success_action', 'success_outcome', 'denial_outcome')
    assert audit.fetch('atomic_with_mutation')
    assert_equal 'pending', audit.fetch('audit_approval_status')
    refute_includes audit.fetch('safe_metadata'), 'free_text_note'
    assert_includes audit.fetch('excluded_metadata'), 'free_text_note'

    assert_equal 'pending_owner_and_reporting_definition', @draft.dig('reconciliation_contract', 'status')
    assert @draft.dig('reconciliation_contract', 'reporting_source_id_defect_must_be_resolved')
    assert_equal 'pending_independent_control_decision', @draft.dig('recovery_contract', 'status')
    refute @draft.dig('recovery_contract', 'ordinary_rollback_may_delete_populated_history')
    assert_equal 13, @draft.fetch('required_scenarios').length
    assert @draft.fetch('required_scenarios').all? { |row| row.fetch('status') == 'required_not_run' }
  end

  def test_all_owner_authority_and_independent_control_records_are_unresolved
    owners = @draft.fetch('owner_authorities')
    assert_equal 16, owners.length
    authority_ids = owners.map { |owner| owner.fetch('authority_id') }
    assert_equal authority_ids.uniq, authority_ids
    owners.each do |owner|
      assert_closed_order owner, OWNER_KEYS
      assert_includes @contract.dig('closed_values', 'authority_capacities'), owner.fetch('capacity')
      assert_equal 'pending', owner.fetch('status')
      %w[institutional_id display_name appointment_reference decision_reference].each { |key| assert_nil owner.fetch(key) }
      refute owner.fetch('decision_recorded')
      refute owner.fetch('implementation_authorized')
    end

    control = @draft.fetch('independent_control')
    assert control.fetch('required')
    assert_equal 'independent_control_authority', control.fetch('required_capacity')
    assert_equal 'pending', control.fetch('status')
    %w[institutional_id display_name appointment_reference review_reference].each { |key| assert_nil control.fetch(key) }
    refute control.fetch('decision_recorded')
    refute control.fetch('implementation_authorized')
  end

  def test_application_truth_is_observation_only_and_contains_required_findings
    truths = @draft.fetch('application_truth')
    assert_equal (1..7).map { |number| format('CAN-TRUTH-%02d', number) }, truths.map { |row| row.fetch('finding_id') }
    truths.each do |row|
      assert_closed_order row, TRUTH_KEYS
      assert_includes %w[observed_current_application evidence_boundary], row.fetch('status')
      assert_kind_of String, row.fetch('finding')
      assert_kind_of String, row.fetch('decision_effect')
      bindings = row.fetch('evidence_bindings')
      refute_empty bindings if row.fetch('status') == 'observed_current_application'
      bindings.each do |binding|
        assert_closed_order binding, EVIDENCE_BINDING_KEYS
        path = File.join(ROOT, binding.fetch('path'))
        assert File.file?(path)
        refute File.symlink?(path)
        assert_equal Digest::SHA256.file(path).hexdigest, binding.fetch('sha256')
      end
    end
    combined = truths.map { |row| row.fetch('finding') }.join(' ')
    assert_includes combined, 'consumed by POST /pendaftaran/kunjungan/{encounter}/batalkan'
    assert_includes combined, 'authorizes before encounter lookup'
    assert_includes combined, 'Encounter defines CANCELLED as a terminal state'
    assert_includes combined, 'cancellation fact rejects update and ordinary-workflow deletion'
    assert_includes combined, 'already use encounter-first locking through LockedClinicalEntryWriter'
    assert_includes combined, 'explicit CANCELLED denial and audit coverage'
    assert_includes combined, 'do not prove backend cancellation rules'
  end

  def test_every_authorization_and_secret_permission_is_false_and_no_secret_is_present
    assert @draft.fetch('authorization').values.all? { |value| value == false }
    assert @draft.fetch('secret_handling').values.all? { |value| value == false }
    scrubbed = deep_copy(@draft)
    scrubbed.delete('secret_handling')
    assert_empty Core.secret_locations(scrubbed)

    tainted = deep_copy(scrubbed)
    tainted.fetch('non_effect_rules') << 'password=must-not-be-stored'
    refute_empty Core.secret_locations(tainted)
  end

  def test_duplicate_keys_unknown_fields_and_non_inference_drift_fail_closed
    assert_raises(Core::ParseError) do
      Core.parse_json('{"status":"PROPOSED","status":"APPROVED"}', label: '$.duplicate_fixture')
    end

    unknown = deep_copy(@draft)
    unknown['unexpected'] = true
    assert_raises(PackError) { validate_closed_schema!(unknown) }

    nested_unknown = deep_copy(@draft)
    nested_unknown.fetch('primary_capability_rows').first['family_approval'] = true
    assert_raises(PackError) { validate_closed_schema!(nested_unknown) }

    inferred = deep_copy(@draft)
    inferred.fetch('family_scope')['family_inheritance_permitted'] = true
    assert_raises(PackError) { validate_closed_schema!(inferred) }

    approved = deep_copy(@draft)
    approved.fetch('decision_questions').first['recorded_choice'] = 'approve'
    assert_raises(PackError) { validate_closed_schema!(approved) }

    unresolved_authority = deep_copy(@draft)
    unresolved_authority.fetch('decision_questions').first.fetch('required_authorities') << 'missing_authority'
    assert_raises(PackError) { validate_closed_schema!(unresolved_authority) }

    duplicate_authority = deep_copy(@draft)
    duplicate_authority.fetch('owner_authorities').last['authority_id'] = duplicate_authority.fetch('owner_authorities').first.fetch('authority_id')
    assert_raises(PackError) { validate_closed_schema!(duplicate_authority) }
  end

  private

  def validate_closed_schema!(draft)
    closed! draft, TOP_LEVEL_KEYS, '$'
    draft.fetch('source_bindings').each { |row| closed! row, SOURCE_BINDING_KEYS, '$.source_bindings[]' }
    closed! draft.fetch('family_scope'), FAMILY_KEYS, '$.family_scope'
    closed! draft.fetch('derived_tier'), DERIVED_TIER_KEYS, '$.derived_tier'
    draft.fetch('primary_capability_rows').each { |row| closed! row, PRIMARY_ROW_KEYS, '$.primary_capability_rows[]' }
    closed! draft.fetch('reporting_dependencies'), REPORTING_KEYS, '$.reporting_dependencies'
    draft.dig('reporting_dependencies', 'rows').each { |row| closed! row, REPORTING_ROW_KEYS, '$.reporting_dependencies.rows[]' }
    closed! draft.dig('reporting_dependencies', 'v1_source_id_defect'), REPORTING_DEFECT_KEYS, '$.reporting_dependencies.v1_source_id_defect'
    draft.dig('reporting_dependencies', 'v1_source_id_defect', 'corrections').each do |row|
      closed! row, REPORTING_CORRECTION_KEYS, '$.reporting_dependencies.v1_source_id_defect.corrections[]'
    end
    draft.fetch('affected_dependencies').each { |row| closed! row, DEPENDENCY_KEYS, '$.affected_dependencies[]' }
    draft.fetch('excluded_domains').each { |row| closed! row, EXCLUDED_DOMAIN_KEYS, '$.excluded_domains[]' }
    draft.fetch('decision_questions').each { |row| closed! row, DECISION_KEYS, '$.decision_questions[]' }
    closed! draft.fetch('recommended_contract'), RECOMMENDED_KEYS, '$.recommended_contract'
    closed! draft.fetch('denial_contract'), DENIAL_KEYS, '$.denial_contract'
    closed! draft.dig('denial_contract', 'authorization_denial'), AUTH_DENIAL_KEYS, '$.denial_contract.authorization_denial'
    draft.dig('denial_contract', 'business_denials').each { |row| closed! row, BUSINESS_DENIAL_KEYS, '$.denial_contract.business_denials[]' }
    closed! draft.fetch('audit_contract'), AUDIT_KEYS, '$.audit_contract'
    closed! draft.fetch('reconciliation_contract'), RECONCILIATION_KEYS, '$.reconciliation_contract'
    closed! draft.fetch('recovery_contract'), RECOVERY_KEYS, '$.recovery_contract'
    draft.fetch('required_scenarios').each { |row| closed! row, SCENARIO_KEYS, '$.required_scenarios[]' }
    draft.fetch('owner_authorities').each { |row| closed! row, OWNER_KEYS, '$.owner_authorities[]' }
    closed! draft.fetch('independent_control'), INDEPENDENT_KEYS, '$.independent_control'
    draft.fetch('application_truth').each do |row|
      closed! row, TRUTH_KEYS, '$.application_truth[]'
      row.fetch('evidence_bindings').each { |binding| closed! binding, EVIDENCE_BINDING_KEYS, '$.application_truth[].evidence_bindings[]' }
    end
    closed! draft.fetch('authorization'), AUTHORIZATION_KEYS, '$.authorization'
    closed! draft.fetch('secret_handling'), SECRET_KEYS, '$.secret_handling'

    raise PackError, 'family inheritance prohibited' unless draft.dig('family_scope', 'family_inheritance_permitted') == false
    raise PackError, 'decisions must remain null' unless draft.fetch('decision_questions').all? { |row| row.fetch('recorded_choice').nil? }
    raise PackError, 'authorization must remain false' unless draft.fetch('authorization').values.all? { |value| value == false }
    validate_excluded_capability_universe!(draft.fetch('excluded_domains'))
    authority_ids = draft.fetch('owner_authorities').map { |row| row.fetch('authority_id') }
    raise PackError, 'owner authority ids must be unique' unless authority_ids.uniq.length == authority_ids.length
    allowed_capacities = @contract.dig('closed_values', 'authority_capacities')
    raise PackError, 'owner authority capacity is outside the adopted contract' unless draft.fetch('owner_authorities').all? { |row| allowed_capacities.include?(row.fetch('capacity')) }
    required_authorities = draft.fetch('decision_questions').flat_map { |row| row.fetch('required_authorities') }
    raise PackError, 'decision authority reference is unresolved' unless required_authorities.all? { |id| authority_ids.count(id) == 1 }
    raise PackError, 'owner state must remain pending/null/false' unless draft.fetch('owner_authorities').all? do |row|
      row.fetch('status') == 'pending' && row.fetch('institutional_id').nil? && row.fetch('display_name').nil? &&
        row.fetch('decision_reference').nil? && row.fetch('decision_recorded') == false && row.fetch('implementation_authorized') == false
    end
    true
  rescue KeyError, NoMethodError => e
    raise PackError, e.message
  end

  def closed!(value, keys, label)
    raise PackError, "#{label}: not an object" unless value.is_a?(Hash)
    raise PackError, "#{label}: keys/order changed" unless value.keys == keys
  end

  def assert_closed_order(value, keys)
    assert_kind_of Hash, value
    assert_equal keys, value.keys
  end

  def assert_source_rows(rows, relative_register_path)
    register = JSON.parse(File.read(File.join(ROOT, relative_register_path)))
    rows.each do |pointer|
      entry = register.fetch('entries').fetch(pointer.fetch('entry_index'))
      assert_equal pointer.fetch('requirement_id'), entry.fetch('requirement_id')
      assert_equal pointer.fetch('entry_sha256'), Digest::SHA256.hexdigest(Core.canonical_json(entry))
    end
  end

  def validate_excluded_capability_universe!(rows)
    rows.each { |row| closed! row, EXCLUDED_DOMAIN_KEYS, '$.excluded_domains[]' }
    capability_ids = rows.flat_map { |row| expand_capability_range(row.fetch('capability_range')) }
    universe = @ledger.fetch('capabilities').map { |row| row.fetch('capability_id') }
    unknown = capability_ids - universe
    raise PackError, "unknown excluded capabilities #{unknown.inspect}" unless unknown.empty?
    raise PackError, 'duplicate or overlapping excluded capabilities' unless capability_ids.uniq.length == capability_ids.length

    expected = [
      'PAR-BPJS-001..PAR-BPJS-002', 'PAR-CLM-001..PAR-CLM-006',
      'PAR-PHA-001..PAR-PHA-020', 'PAR-ORP-001',
      'PAR-PWH-001..PAR-PWH-023', 'PAR-FIN-001..PAR-FIN-019'
    ].flat_map { |range| expand_capability_range(range) }
    raise PackError, 'excluded capability boundary is incomplete or expanded' unless capability_ids.sort == expected.sort
  end

  def expand_capability_range(value)
    return [value] unless value.include?('..')

    first_id, last_id = value.split('..', 2)
    first_match = first_id.match(/\A(.+-)(\d{3})\z/)
    last_match = last_id.match(/\A(.+-)(\d{3})\z/)
    raise PackError, "invalid capability range #{value}" unless first_match && last_match && first_match[1] == last_match[1]

    (first_match[2].to_i..last_match[2].to_i).map { |number| format('%s%03d', first_match[1], number) }
  end

  def deep_copy(value)
    JSON.parse(JSON.generate(value))
  end
end
