# frozen_string_literal: true

require 'json'
require 'minitest/autorun'
require_relative '../../scripts/generate-g0-g3-coverage-ledger'

class G0G3CoverageLedgerTest < Minitest::Test
  SNAPSHOT_DATE = '2026-08-26'

  def setup
    @compiler = G0G3CoverageLedger.new(snapshot_date: SNAPSHOT_DATE)
    @overlay = JSON.parse(File.read(File.join(G0G3CoverageLedger::ROOT, G0G3CoverageLedger::EVIDENCE_MAP_PATH)))
  end

  def test_generates_exact_capability_and_workflow_universes
    ledger = @compiler.build

    assert_equal 268, ledger.fetch('capabilities').length
    assert_equal 268, ledger.fetch('capabilities').map { |row| row.fetch('requirement_id') }.uniq.length
    assert_equal G0G3CoverageLedger::EXPECTED_COUNTS, ledger.dig('capability_summary', 'by_batch')
    assert_equal G0G3CoverageLedger::E2E_IDS, ledger.fetch('workflows').map { |row| row.fetch('workflow_id') }
    assert_equal 'OPEN', ledger.dig('gate_summary', 'status')
  end

  def test_defaults_do_not_inflate_build_or_acceptance
    ledger = @compiler.build

    assert_equal 256, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'runtime_availability') == 'NOT_IMPLEMENTED' }
    assert_equal 12, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'runtime_availability') == 'PARTIAL' }
    assert_equal 256, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'owner_acceptance') == 'NOT_READY' }
    assert_equal 12, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'owner_acceptance') == 'NOT_ACCEPTED' }
    assert_equal 0, ledger.dig('workflow_summary', 'runtime_availability', 'IMPLEMENTED')
    assert_equal 0, ledger.dig('workflow_summary', 'automated_evidence', 'COMPLETE_PASS')
    assert_equal 0, ledger.dig('workflow_summary', 'owner_acceptance', 'PASS')
  end

  def test_workflow_acceptance_distinguishes_not_accepted_from_not_ready
    workflows = @compiler.build.fetch('workflows').to_h { |row| [row.fetch('workflow_id'), row.dig('engineering_evidence', 'owner_acceptance')] }

    assert_equal %w[E2E-01 E2E-03 E2E-05 E2E-12 E2E-16], workflows.select { |_id, status| status == 'NOT_ACCEPTED' }.keys
    assert_equal %w[E2E-02 E2E-04 E2E-06 E2E-07 E2E-08 E2E-09 E2E-10 E2E-11 E2E-13 E2E-14 E2E-15], workflows.select { |_id, status| status == 'NOT_READY' }.keys
  end

  def test_per_capability_governance_is_pointer_and_reference_presence_only
    row = @compiler.build.fetch('capabilities').first

    assert_equal %w[batch decision_pointer engineering_evidence reference_presence requirement_id workflow_binding], row.keys.sort
    refute_includes row.keys, 'owner'
    refute_includes row.keys, 'disposition'
    refute_includes row.keys, 'approval'
    assert_equal %w[PENDING RECORDED], row.fetch('reference_presence').values.uniq.sort
  end

  def test_workflow_links_are_bidirectional
    ledger = @compiler.build
    capabilities = ledger.fetch('capabilities').to_h { |row| [row.fetch('requirement_id'), row] }

    ledger.fetch('workflows').each do |workflow|
      workflow.fetch('capability_ids').each do |requirement_id|
        assert_includes capabilities.fetch(requirement_id).dig('workflow_binding', 'scenario_ids'), workflow.fetch('workflow_id')
      end
    end
  end

  def test_unmapped_capability_remains_pending_and_empty
    row = @compiler.build.fetch('capabilities').find { |item| item.fetch('requirement_id') == 'PAR-ADM-003' }

    assert_equal({ 'status' => 'PENDING', 'scenario_ids' => [], 'authority_reference' => nil }, row.fetch('workflow_binding'))
  end

  def test_exact_twelve_provisional_bindings_have_no_cross_domain_batch_leakage
    expected = {
      'PAR-REG-003' => %w[E2E-01 E2E-03], 'PAR-CLN-004' => %w[E2E-03],
      'PAR-CLN-006' => %w[E2E-03 E2E-05], 'PAR-RMIK-001' => %w[E2E-03 E2E-12],
      'PAR-REG-002' => %w[E2E-01 E2E-02], 'PAR-CLN-002' => %w[E2E-02],
      'PAR-CLN-003' => %w[E2E-02], 'PAR-REG-001' => %w[E2E-01 E2E-04],
      'PAR-CLN-005' => %w[E2E-04], 'PAR-ADM-001' => %w[E2E-16],
      'PAR-ADM-002' => %w[E2E-16], 'PAR-ADM-037' => %w[E2E-16]
    }
    capabilities = @compiler.build.fetch('capabilities')
    provisional = capabilities.select { |row| row.dig('workflow_binding', 'status') == 'PROVISIONAL' }

    assert_equal expected.keys.sort, provisional.map { |row| row.fetch('requirement_id') }.sort
    provisional.each do |row|
      assert_equal expected.fetch(row.fetch('requirement_id')), row.dig('workflow_binding', 'scenario_ids')
      assert_nil row.dig('workflow_binding', 'authority_reference')
    end
    assert capabilities.reject { |row| expected.key?(row.fetch('requirement_id')) }.all? { |row| row.dig('workflow_binding', 'scenario_ids').empty? }
  end

  def test_bg03_capability_evidence_remains_partial_unaccepted_and_unpublished
    rows = @compiler.build.fetch('capabilities').select do |row|
      %w[PAR-ADM-001 PAR-ADM-002 PAR-ADM-037].include?(row.fetch('requirement_id'))
    end

    assert_equal 3, rows.length
    rows.each do |row|
      evidence = row.fetch('engineering_evidence')

      assert_equal 'PARTIAL', evidence.fetch('runtime_availability')
      assert_equal 'PARTIAL_PASS', evidence.fetch('automated_evidence')
      assert_equal 'PASS', evidence.dig('database_engine_evidence', 'sqlite')
      assert_equal 'PASS', evidence.dig('database_engine_evidence', 'postgresql_17')
      assert_equal 'PASS', evidence.dig('database_engine_evidence', 'mysql_8_4')
      assert_equal 'NOT_RUN', evidence.fetch('hosted_uat')
      assert_equal 'NOT_ACCEPTED', evidence.fetch('owner_acceptance')
      assert_equal 'PROVISIONAL', row.dig('workflow_binding', 'status')
      assert_equal ['E2E-16'], row.dig('workflow_binding', 'scenario_ids')
      assert_nil row.dig('workflow_binding', 'authority_reference')
      assert_equal 'RECORDED', row.dig('reference_presence', 'decision_object')
      assert_includes evidence.fetch('evidence_paths'), 'docs/operations/T1_LOCAL_CURRENT_MANIFEST_PORTABILITY_EVIDENCE_2026-08-27.md'
      assert %w[accountable_owner_reference approval_reference release_evidence_reference].all? do |key|
        row.dig('reference_presence', key) == 'PENDING'
      end
    end
  end

  def test_emergency_current_execution_record_includes_dual_exact_engine_evidence
    emergency = @compiler.build.fetch('workflows').find { |row| row.fetch('workflow_id') == 'E2E-02' }
    evidence = emergency.fetch('engineering_evidence')

    assert_equal 'PARTIAL', evidence.fetch('runtime_availability')
    assert_equal 'PARTIAL_PASS', evidence.fetch('automated_evidence')
    assert_equal 'PASS', evidence.dig('database_engine_evidence', 'sqlite')
    assert_equal 'PASS', evidence.dig('database_engine_evidence', 'postgresql_17')
    assert_equal 'PASS', evidence.dig('database_engine_evidence', 'mysql_8_4')
    assert_equal 'NOT_RUN', evidence.fetch('hosted_uat')
    assert_equal 'NOT_READY', evidence.fetch('owner_acceptance')
    assert_includes evidence.fetch('evidence_paths'), 'docs/operations/T1_LOCAL_CURRENT_MANIFEST_PORTABILITY_EVIDENCE_2026-08-27.md'
  end

  def test_inpatient_database_evidence_distinguishes_exact_mysql_8_4_from_mysql_9_7_compatibility
    inpatient = @compiler.build.fetch('workflows').find { |row| row.fetch('workflow_id') == 'E2E-04' }
    database = inpatient.dig('engineering_evidence', 'database_engine_evidence')

    assert_equal 'PASS', database.fetch('sqlite')
    assert_equal 'PASS', database.fetch('postgresql_17')
    assert_equal 'PASS', database.fetch('mysql_8_4')
    assert_equal 'COMPATIBILITY_ONLY', database.fetch('mysql_other')
    assert_includes inpatient.dig('engineering_evidence', 'evidence_paths'), 'docs/operations/T1_LOCAL_MYSQL84_INTEGRATION_EVIDENCE_2026-08-26.md'
    assert_equal 'NOT_READY', inpatient.dig('engineering_evidence', 'owner_acceptance')
  end

  def test_outpatient_current_execution_record_includes_exact_mysql_8_4_evidence
    outpatient = @compiler.build.fetch('workflows').find { |row| row.fetch('workflow_id') == 'E2E-03' }
    evidence = outpatient.fetch('engineering_evidence')

    assert_equal 'PARTIAL_PASS', evidence.fetch('automated_evidence')
    assert_equal 'PASS', evidence.dig('database_engine_evidence', 'sqlite')
    assert_equal 'PASS', evidence.dig('database_engine_evidence', 'postgresql_17')
    assert_equal 'PASS', evidence.dig('database_engine_evidence', 'mysql_8_4')
    assert_equal 'COMPATIBILITY_ONLY', evidence.dig('database_engine_evidence', 'mysql_other')
    assert_includes evidence.fetch('evidence_paths'), 'docs/operations/T1_CONTINUOUS_OUTPATIENT_TEACHING_JOURNEY_EVIDENCE_2026-08-26.md'
    assert_includes evidence.fetch('evidence_paths'), 'docs/operations/T1_LOCAL_MYSQL84_INTEGRATION_EVIDENCE_2026-08-26.md'
    assert_equal 'NOT_ACCEPTED', evidence.fetch('owner_acceptance')
  end

  def test_current_generated_ledger_is_not_stale
    assert @compiler.check!
    assert_raises(G0G3CoverageLedger::ContractError) { @compiler.check_serialized!("{}\n") }
  end

  def test_rejects_malformed_and_duplicate_json
    assert_raises(G0G3CoverageLedger::ContractError) { @compiler.parse_json('{', 'fixture') }
    error = assert_raises(G0G3CoverageLedger::ContractError) { @compiler.parse_json('{"a":1,"a":2}', 'fixture') }
    assert_match(/duplicate JSON object key/, error.message)
  end

  def test_rejects_unknown_capability_override
    overlay = deep_copy(@overlay)
    overlay.fetch('capability_overrides')['PAR-FAKE-999'] = deep_copy(overlay.fetch('capability_overrides').values.first)

    error = assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: overlay) }
    assert_match(/unknown capability override IDs/, error.message)
  end

  def test_rejects_missing_or_duplicate_e2e_rows
    missing = deep_copy(@overlay)
    missing.fetch('workflows').pop
    assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: missing) }

    duplicate = deep_copy(@overlay)
    duplicate.fetch('workflows')[-1]['workflow_id'] = 'E2E-15'
    assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: duplicate) }
  end

  def test_rejects_invalid_vocabulary
    overlay = deep_copy(@overlay)
    overlay.fetch('workflows').first['owner_acceptance'] = 'PASS_WITHOUT_OWNER'

    error = assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: overlay) }
    assert_match(/invalid value/, error.message)
  end

  def test_rejects_absolute_traversal_and_missing_evidence_paths
    ['/tmp/outside.json', '../outside.json', 'docs/does-not-exist.json'].each do |path|
      overlay = deep_copy(@overlay)
      overlay.fetch('workflows').first['evidence_paths'] = [path]
      assert_raises(G0G3CoverageLedger::ContractError, path) { @compiler.build(overlay: overlay) }
    end
  end

  def test_rejects_dec_013_prototype_evidence_paths
    paths = [
      'docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_RECORD_2026-08-20_DRAFT.md',
      'docs/product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md',
      'docs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION.md',
      'docs/operations/OUTPATIENT_LAB_ACCESS_CONTROL_VALIDATION_2026-07-24.md'
    ]

    paths.each do |path|
      overlay = deep_copy(@overlay)
      overlay.fetch('workflows').first['evidence_paths'] = [path]
      error = assert_raises(G0G3CoverageLedger::ContractError, path) { @compiler.build(overlay: overlay) }
      assert_match(/forbidden legacy\/prototype evidence path/, error.message)
    end
  end

  def test_rejects_secret_looking_values
    overlay = deep_copy(@overlay)
    overlay.fetch('workflows').first['note'] = 'https://example.invalid/callback?client_secret=should-never-appear'

    error = assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: overlay) }
    assert_match(/secret-looking value/, error.message)
  end

  def test_engineering_overlay_cannot_promote_formal_gate
    overlay = deep_copy(@overlay)
    inflate_evidence!(overlay.fetch('capability_defaults'))
    overlay.fetch('capability_overrides').each_value { |override| inflate_evidence!(override.fetch('engineering_evidence')) }
    overlay.fetch('workflows').each { |workflow| inflate_evidence!(workflow) }

    gate = @compiler.build(overlay: overlay).fetch('gate_summary')
    assert_equal 'OPEN', gate.fetch('status')
    assert_includes gate.fetch('reason_codes'), 'FORMAL_GOVERNANCE_NOT_VERIFIED'
    assert_includes gate.fetch('reason_codes'), 'FORMAL_GATE_DECISION_NOT_VERIFIED'
  end

  def test_source_inventory_binds_all_required_authoritative_files
    ledger = @compiler.build
    sources = ledger.fetch('sources').to_h { |row| [row.fetch('source_id'), row] }

    %w[manifest decision_register_A decision_register_B decision_register_C decision_register_D decision_register_E decision_register_F decision_register_G identity_registry authority_policy appointment_register decision_session_register e2e_catalogue release_index].each do |source_id|
      assert_match(/\A[0-9a-f]{64}\z/, sources.fetch(source_id).fetch('sha256'))
    end
  end

  private

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end

  def inflate_evidence!(evidence)
    evidence['runtime_availability'] = 'IMPLEMENTED'
    evidence['automated_evidence'] = 'COMPLETE_PASS'
    evidence['database_engine_evidence'].keys.each { |engine| evidence['database_engine_evidence'][engine] = 'PASS' }
    evidence['hosted_uat'] = 'PASS'
    evidence['reconciliation'] = 'COMPLETE'
    evidence['defect_status'] = 'NONE_RECORDED'
    evidence['owner_acceptance'] = 'PASS'
  end
end
