# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

class G0BatchADecisionReadyReconciliationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  JSON_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_READY_RECONCILIATION_2026-09-02.json'
  )
  MARKDOWN_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_READY_RECONCILIATION_2026-09-02.md'
  )
  MANIFEST_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json')
  COVERAGE_PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R8.json'
  )

  EXPECTED_IDS = %w[
    PAR-ADM-001 PAR-ADM-002 PAR-ADM-003 PAR-ADM-005 PAR-ADM-006
    PAR-ADM-008 PAR-ADM-012 PAR-ADM-013 PAR-ADM-022 PAR-ADM-023
    PAR-ADM-024 PAR-ADM-025 PAR-ADM-032 PAR-ADM-037 PAR-ADM-038
    PAR-ADM-040 PAR-ADM-044 PAR-ADM-045 PAR-HLP-001 PAR-IOT-001
  ].freeze

  EXPECTED_DECISIONS = {
    'PAR-ADM-001' => ['consolidate', 'requirement', 'PAR-ADM-002'],
    'PAR-ADM-002' => ['reproduce', 'canonical_capability', 'workforce_user_account_administration'],
    'PAR-ADM-003' => ['replace', 'canonical_capability', 'versioned_configuration_registry'],
    'PAR-ADM-005' => ['replace', 'canonical_capability', 'deployment_controlled_navigation_registry'],
    'PAR-ADM-006' => ['reproduce', 'canonical_capability', 'workforce_clinician_directory'],
    'PAR-ADM-008' => ['replace', 'canonical_capability', 'versioned_signature_template_registry'],
    'PAR-ADM-012' => ['replace', 'canonical_capability', 'controlled_print_service_configuration'],
    'PAR-ADM-013' => ['reproduce', 'canonical_capability', 'organization_unit_clinic_master'],
    'PAR-ADM-022' => ['replace', 'canonical_capability', 'occupation_terminology_registry'],
    'PAR-ADM-023' => ['replace', 'canonical_capability', 'education_terminology_registry'],
    'PAR-ADM-024' => ['replace', 'canonical_capability', 'privacy_scoped_ethnicity_registry'],
    'PAR-ADM-025' => ['replace', 'canonical_capability', 'language_communication_registry'],
    'PAR-ADM-032' => ['reproduce', 'canonical_capability', 'patient_identity_stewardship'],
    'PAR-ADM-037' => ['replace', 'canonical_capability', 'immutable_audit_viewer'],
    'PAR-ADM-038' => ['replace', 'canonical_capability', 'sandbox_esign_audit_adapter'],
    'PAR-ADM-040' => ['replace', 'canonical_capability', 'integration_message_reconciliation_log'],
    'PAR-ADM-044' => ['replace', 'canonical_capability', 'non_transmitting_satusehat_sandbox_log'],
    'PAR-ADM-045' => ['consolidate', 'requirement', 'PAR-ADM-044'],
    'PAR-HLP-001' => ['replace', 'canonical_capability', 'versioned_indonesian_role_manuals'],
    'PAR-IOT-001' => ['replace', 'canonical_capability', 'synthetic_temperature_gateway']
  }.freeze

  SOURCE_HASHES = {
    'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json' =>
      '59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_SHARED_CONTROLS_PROPOSAL_2026-08-25.md' =>
      '6332704a1dd2262cb0318acc3371d7c0119e46ed3bb9c277e82714589f19cf91',
    'docs/new-simrs-rebuild/phase-0/G0_BATCH_A_DECISION_REGISTER_2026-08-25.json' =>
      'a5c1e01686fdb576026802331d3228df9a6335f6f7176b7c023c459bfc532098',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R8.json' =>
      '3df5165be14946794002cc121e1f3f92f64232d6e39057e9d48d65636dfb4665',
    'docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md' =>
      'dc9068937d678626868aed7ca9fd5f9a94a98cdba8df9de5f07be575e46febb7',
    'docs/vendor-simrs-assessment-2026-08-21/FULL_MENU_TAXONOMY.md' =>
      'f26fcea77d3bf72e7f01eb0bb39621caed9087f044ad2abfc283fe2d93fe483e',
    'docs/vendor-simrs-assessment-2026-08-21/MANUAL_COMPLETE_MAP.md' =>
      '86e7cecae56be8bcaad38cb9a5bf5ea41b276b1203b7d56a3f6f43255776e0bd'
  }.freeze

  def setup
    @record = JSON.parse(File.read(JSON_PATH, encoding: 'UTF-8'))
    @markdown = File.read(MARKDOWN_PATH, encoding: 'UTF-8')
    @manifest = JSON.parse(File.read(MANIFEST_PATH, encoding: 'UTF-8'))
    @coverage = JSON.parse(File.read(COVERAGE_PATH, encoding: 'UTF-8'))
  end

  def test_exact_manifest_scope_and_candidate_dispositions_are_bound
    assert_equal EXPECTED_IDS, @manifest.fetch('batches').fetch('A')
    assert_equal EXPECTED_IDS, @record.dig('scope', 'manifest_ids')
    assert_equal 20, @record.dig('scope', 'manifest_count')
    assert_equal EXPECTED_IDS, @record.fetch('entries').map { |entry| entry.fetch('requirement_id') }

    @record.fetch('entries').each do |entry|
      decision = entry.fetch('proposed_decision')
      target = decision.fetch('canonical_target')
      assert_equal 'pending_candidate_only', decision.fetch('status')
      assert_equal EXPECTED_DECISIONS.fetch(entry.fetch('requirement_id')),
                   [decision.fetch('disposition'), target.fetch('kind'), target.fetch('reference')]
    end
  end

  def test_source_bindings_are_exact_current_regular_files
    bindings = @record.fetch('source_bindings')
    assert_equal SOURCE_HASHES.keys, bindings.map { |binding| binding.fetch('path') }

    bindings.each do |binding|
      path = binding.fetch('path')
      absolute = File.join(ROOT, path)
      assert File.file?(absolute), path
      refute File.symlink?(absolute), path
      assert_equal SOURCE_HASHES.fetch(path), binding.fetch('sha256'), path
      assert_equal binding.fetch('sha256'), Digest::SHA256.file(absolute).hexdigest, path
    end
  end

  def test_engineering_observations_exactly_normalize_current_r8_without_conferring_authority
    coverage = @coverage.fetch('capabilities').select { |row| row.fetch('batch') == 'A' }
                        .to_h { |row| [row.fetch('capability_id'), row] }
    assert_equal EXPECTED_IDS.sort, coverage.keys.sort

    @record.fetch('entries').each do |entry|
      observed = entry.fetch('current_engineering_observation')
      source = coverage.fetch(entry.fetch('requirement_id'))
      engineering = source.fetch('engineering_evidence')

      assert_equal engineering.fetch('runtime_availability'), observed.fetch('runtime_availability')
      assert_equal engineering.fetch('automated_evidence'), observed.fetch('automated_evidence')
      assert_equal engineering.fetch('reconciliation'), observed.fetch('reconciliation')
      assert_equal source.dig('workflow_observation', 'status'), observed.fetch('workflow_status')
      assert_equal engineering.fetch('evidence_paths'), observed.fetch('evidence_paths')
      assert_equal 'none_observation_only', observed.fetch('authority_effect')
      observed.fetch('evidence_paths').each { |path| assert File.file?(File.join(ROOT, path)), path }
      entry.fetch('related_code_paths').each { |path| assert File.file?(File.join(ROOT, path)), path }
    end
  end

  def test_evidence_owner_candidates_and_scenarios_are_decision_ready_but_unresolved
    @record.fetch('entries').each do |entry|
      legacy = entry.fetch('legacy_evidence')
      assert_includes %w[O_STRUCTURAL U_UNRESOLVED], legacy.fetch('evidence_class')
      refute_empty legacy.fetch('normalized_observation')
      refute_empty legacy.fetch('limits')
      legacy.fetch('sources').each { |path| assert File.file?(File.join(ROOT, path)), path }

      authority = entry.fetch('authority_candidates')
      accountable = authority.fetch('accountable')
      refute_empty accountable.fetch('candidate_identity')
      refute_empty accountable.fetch('required_scope')
      assert_equal 'unresolved_no_appointment_evidence', accountable.fetch('appointment_status')
      refute_empty authority.fetch('co_owners')
      authority.fetch('co_owners').each do |co_owner|
        refute_empty co_owner.fetch('authority_domain')
        assert_equal 'unresolved_no_appointment_evidence', co_owner.fetch('appointment_status')
      end

      assert_equal %w[normal denial_correction], entry.fetch('scenarios').keys
      entry.fetch('scenarios').each_value do |scenario|
        assert_equal 'decision_ready_not_executed', scenario.fetch('status')
        refute_empty scenario.fetch('action')
        assert_operator scenario.fetch('assertions').length, :>=, 2
      end
      assert_equal 'pending', entry.fetch('approval').fetch('status')
      assert_nil entry.fetch('approval').fetch('identity')
      assert_nil entry.fetch('approval').fetch('reference')
    end
  end

  def test_authority_and_safety_boundaries_are_fail_closed
    boundary = @record.fetch('authority_boundary')
    assert_equal 'OPEN', boundary.fetch('g0')
    assert_equal 'OPEN', boundary.fetch('g3')
    assert_equal 'pending', boundary.fetch('capability_decisions')
    assert_equal 'unresolved', boundary.fetch('accountable_owner_appointments')
    assert_equal 'unresolved', boundary.fetch('co_owner_appointments')
    assert_equal 'not_authorized', boundary.fetch('activation')
    assert_equal 'not_authorized', boundary.fetch('deployment')
    assert_equal 'not_authorized', boundary.fetch('hosted_migration')
    assert_equal 'not_authorized', boundary.fetch('real_patient_data')
    assert_equal 'not_authorized', boundary.fetch('live_integrations')
    assert_equal 'none_append_only_candidate_pack', boundary.fetch('gate_effect')

    safety = @record.fetch('data_boundary')
    assert_equal 'synthetic_only', safety.fetch('mode')
    assert safety.fetch('secrets_prohibited')
    assert_equal %w[BPJS VClaim SATUSEHAT payment_bank production_devices production_tte],
                 safety.fetch('live_boundaries_disabled')
  end

  def test_human_readable_pack_states_truthful_effect_and_all_twenty_rows
    assert_includes @markdown, 'DECISION-READY CANDIDATE — NOT APPROVED, NOT ACTIVE'
    assert_includes @markdown, 'G0 remains **OPEN**'
    assert_includes @markdown, 'G3 remains **OPEN**'
    assert_includes @markdown, 'No candidate name is an appointment'
    assert_includes @markdown, 'does not activate any capability'
    assert_includes @markdown, 'normal and denial/correction scenarios remain unexecuted acceptance proposals'
    EXPECTED_IDS.each { |id| assert_includes @markdown, "`#{id}`" }
  end
end
