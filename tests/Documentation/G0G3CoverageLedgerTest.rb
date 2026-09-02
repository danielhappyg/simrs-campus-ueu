# frozen_string_literal: true

require 'json'
require 'digest'
require 'fileutils'
require 'minitest/autorun'
require 'open3'
require 'pathname'
require 'rbconfig'
require 'tmpdir'
require_relative '../../scripts/generate-g0-proportional-governance-v2'
require_relative '../../scripts/generate-g0-g3-coverage-ledger'

class G0G3CoverageLedgerTest < Minitest::Test
  SNAPSHOT_DATE = '2026-08-27'

  def setup
    @compiler = G0G3CoverageLedger.new(snapshot_date: SNAPSHOT_DATE)
    @overlay = JSON.parse(File.read(File.join(G0G3CoverageLedger::ROOT, G0G3CoverageLedger::EVIDENCE_MAP_PATH)))
  end

  def test_generates_exact_capability_and_workflow_universes
    ledger = @compiler.build

    assert_equal 'G0-G3-COVERAGE-LEDGER-2026-08-27', ledger.fetch('artifact_id')
    assert_equal SNAPSHOT_DATE, ledger.fetch('snapshot_date')
    assert_equal 268, ledger.fetch('capabilities').length
    assert_equal 268, ledger.fetch('capabilities').map { |row| row.fetch('requirement_id') }.uniq.length
    assert_equal G0G3CoverageLedger::EXPECTED_COUNTS, ledger.dig('capability_summary', 'by_batch')
    assert_equal G0G3CoverageLedger::E2E_IDS, ledger.fetch('workflows').map { |row| row.fetch('workflow_id') }
    assert_equal 'OPEN', ledger.dig('gate_summary', 'status')
  end

  def test_defaults_do_not_inflate_build_or_acceptance
    ledger = @compiler.build

    assert_equal 254, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'runtime_availability') == 'NOT_IMPLEMENTED' }
    assert_equal 14, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'runtime_availability') == 'PARTIAL' }
    assert_equal 256, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'owner_acceptance') == 'NOT_READY' }
    assert_equal 12, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'owner_acceptance') == 'NOT_ACCEPTED' }
    assert_equal 2, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'owner_acceptance') == 'NOT_READY' && row.dig('engineering_evidence', 'runtime_availability') == 'PARTIAL' }
    assert_equal 6, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'hosted_uat') == 'HISTORICAL_PARTIAL' }
    assert_equal 262, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'hosted_uat') == 'NOT_RUN' }
    assert_equal 11, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'reconciliation') == 'PARTIAL' }
    assert_equal 257, ledger.fetch('capabilities').count { |row| row.dig('engineering_evidence', 'reconciliation') == 'NOT_STARTED' }
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

  def test_exact_fourteen_provisional_bindings_have_no_cross_domain_batch_leakage
    expected = {
      'PAR-REG-003' => %w[E2E-01 E2E-03], 'PAR-CLN-004' => %w[E2E-03],
      'PAR-CLN-006' => %w[E2E-03 E2E-05], 'PAR-RMIK-001' => %w[E2E-03 E2E-12],
      'PAR-REG-002' => %w[E2E-01 E2E-02], 'PAR-CLN-002' => %w[E2E-02],
      'PAR-CLN-003' => %w[E2E-02], 'PAR-REG-001' => %w[E2E-01 E2E-04],
      'PAR-CLN-005' => %w[E2E-04], 'PAR-ADM-001' => %w[E2E-16],
      'PAR-ADM-002' => %w[E2E-16], 'PAR-ADM-037' => %w[E2E-16],
      'PAR-RPT-016' => %w[E2E-15], 'PAR-RPT-019' => %w[E2E-15]
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

  def test_exact_reporting_capabilities_are_provisionally_bound_to_e2e_15
    expected_paths = %w[
      routes/web.php
      app/Http/Controllers/Outpatient/OutpatientRecapController.php
      resources/js/pages/pendaftaran/rekap.tsx
      tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php
      tests/Feature/Outpatient/ContinuousOutpatientTeachingJourneyTest.php
      docs/operations/T1_OPERATIONAL_PAGINATION_EXPORT_EVIDENCE_2026-08-26.md
      docs/operations/T1_LOCAL_CURRENT_MANIFEST_PORTABILITY_EVIDENCE_2026-08-27.md
      docs/operations/T1_CONTINUOUS_OUTPATIENT_TEACHING_JOURNEY_EVIDENCE_2026-08-26.md
      docs/operations/TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md
    ]
    rows = @compiler.build.fetch('capabilities').select { |row| %w[PAR-RPT-016 PAR-RPT-019].include?(row.fetch('requirement_id')) }

    assert_equal 2, rows.length
    rows.each do |row|
      evidence = row.fetch('engineering_evidence')

      assert_equal 'PARTIAL', evidence.fetch('runtime_availability')
      assert_equal 'PARTIAL_PASS', evidence.fetch('automated_evidence')
      assert_equal({ 'sqlite' => 'PASS', 'postgresql_17' => 'PASS', 'mysql_8_4' => 'PASS', 'mysql_other' => 'COMPATIBILITY_ONLY' }, evidence.fetch('database_engine_evidence'))
      assert_equal 'HISTORICAL_PARTIAL', evidence.fetch('hosted_uat')
      assert_equal 'PARTIAL', evidence.fetch('reconciliation')
      assert_equal 'PENDING', evidence.fetch('defect_status')
      assert_equal 'NOT_READY', evidence.fetch('owner_acceptance')
      assert_equal expected_paths, evidence.fetch('evidence_paths')
      assert_equal({ 'status' => 'PROVISIONAL', 'scenario_ids' => ['E2E-15'], 'authority_reference' => nil }, row.fetch('workflow_binding'))
      assert_equal 'PENDING', row.dig('reference_presence', 'accountable_owner_reference')
      assert_equal 'PENDING', row.dig('reference_presence', 'approval_reference')
      assert_equal 'PENDING', row.dig('reference_presence', 'release_evidence_reference')
    end
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
    assert_includes evidence.fetch('evidence_paths'), 'tests/Feature/Emergency/ContinuousEmergencyTeachingJourneyTest.php'
    assert_includes evidence.fetch('evidence_paths'), 'docs/operations/T1_CONTINUOUS_EMERGENCY_SCAFFOLD_EVIDENCE_2026-08-27.md'
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

  def test_serialization_normalizes_empty_collections_across_json_runtimes
    serialized = @compiler.serialized

    refute_match(/\[\n\s*\]/, serialized)
    refute_match(/\{\n\s*\}/, serialized)
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

  def test_rejects_artifact_id_or_snapshot_date_mismatch
    artifact_mismatch = deep_copy(@overlay)
    artifact_mismatch['artifact_id'] = 'G0-G3-COVERAGE-EVIDENCE-MAP-2026-08-26'
    assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: artifact_mismatch) }

    date_mismatch = deep_copy(@overlay)
    date_mismatch['snapshot_date'] = '2026-08-26'
    assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: date_mismatch) }
  end

  def test_rejects_evidence_later_than_snapshot_without_rejecting_historical_links
    historical = deep_copy(@overlay)
    historical.fetch('workflows').first['evidence_paths'] = ['docs/operations/TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md']
    assert @compiler.build(overlay: historical)

    future = deep_copy(@overlay)
    future.fetch('workflows').first['evidence_paths'] = ['docs/operations/HYPOTHETICAL_EVIDENCE_2026-08-28.md']
    error = assert_raises(G0G3CoverageLedger::ContractError) { @compiler.build(overlay: future) }
    assert_match(/later than snapshot 2026-08-27/, error.message)
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

    %w[evidence_map manifest decision_register_A decision_register_B decision_register_C decision_register_D decision_register_E decision_register_F decision_register_G identity_registry authority_policy appointment_register decision_session_register e2e_catalogue release_index].each do |source_id|
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

# Keep the R4 adversarial suites outside the already-large contract class while
# ensuring the established CI entry point executes them as one closed ledger suite.
require_relative 'G0G3CoverageLedgerR4AppendOnlyPublicationTest'
require_relative 'G0G3CoverageLedgerR4SecurityTest'

class G0G3CoverageLedgerV2Test < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  V2 = G0G3CoverageLedgerV2
  Core = G0ProportionalGovernanceV2
  Generator = G0ProportionalGovernanceV2Generator
  Selector = G0GovernanceConsumerSelector
  SCRIPT = File.join(ROOT, 'scripts/generate-g0-g3-coverage-ledger.rb')

  HISTORICAL_V1_HASHES = {
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-26.json' =>
      '94d96ae65143c3636a83bac8dee6c4ac887483dd7d10a904ed161b9fc3a38865',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json' =>
      '3cfcc9898f4b541cea85cd448638fc300cae48eb5172c3dffd598925d9244ea0',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-26.json' =>
      '91162e60df901b0635f72e73bea5ca62d8f36e916c20c36ca2af33d9af90936f',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-27.json' =>
      'd043e4ce3d2893bd19f543a971561b57964926a385969f0a2e7266e943196cbf'
  }.freeze

  class << self
    attr_reader :fixture_root, :candidate

    def ensure_candidate!
      return if @candidate

      @fixture_root = Dir.mktmpdir('.g0-g3-ledger-v2-', ROOT)
      @candidate = File.join(@fixture_root, 'candidate')
      Generator.generate!(root: ROOT, output: @candidate)
      selection_dir = File.join(@fixture_root, 'selection')
      FileUtils.mkdir_p(selection_dir)
      File.binwrite(File.join(selection_dir, 'active.json'), "fixture selection\n")
    end

    def cleanup_candidate!
      FileUtils.remove_entry_secure(@fixture_root) if @fixture_root && File.exist?(@fixture_root)
    end
  end

  Minitest.after_run { cleanup_candidate! }

  def setup
    # R2 is now an immutable historical engineering-map predecessor. The
    # successor contract independently verifies its exact bytes; these retained
    # R4 tests exercise ledger governance behavior against that frozen map
    # rather than re-projecting it from today's changed application sources.
    @evidence_map_validator = V2::EvidenceMap.method(:validate_generated_document!)
    V2::EvidenceMap.define_singleton_method(:validate_generated_document!) { |_document, root:, **_keywords| !root.nil? }
    @historical_before = historical_v1_bytes
    @original_before = File.binread(File.join(ROOT, V2::ORIGINAL_OUTPUT_PATH))
    @r2_before = File.binread(File.join(ROOT, V2::R2_OUTPUT_PATH))
    @predecessor_before = File.binread(File.join(ROOT, V2::PREDECESSOR_OUTPUT_PATH))
    self.class.ensure_candidate!
    @candidate = self.class.candidate
    @fixture_root = self.class.fixture_root
    @test_tmpdir = Dir.mktmpdir('.g0-g3-ledger-v2-case-', ROOT)
  end

  def teardown
    V2::EvidenceMap.define_singleton_method(:validate_generated_document!, @evidence_map_validator)
    assert_equal @historical_before, historical_v1_bytes,
                 'schema-v2 ledger tests must preserve every historical schema-v1 byte'
    assert_equal @original_before, File.binread(File.join(ROOT, V2::ORIGINAL_OUTPUT_PATH)),
                 'R4 tests must preserve the original schema-v2 observation byte-for-byte'
    assert_equal V2::ORIGINAL_SHA256,
                 Digest::SHA256.file(File.join(ROOT, V2::ORIGINAL_OUTPUT_PATH)).hexdigest
    assert_equal @r2_before, File.binread(File.join(ROOT, V2::R2_OUTPUT_PATH)),
                 'R4 tests must preserve the R2 schema-v2 observation byte-for-byte'
    assert_equal V2::R2_SHA256,
                 Digest::SHA256.file(File.join(ROOT, V2::R2_OUTPUT_PATH)).hexdigest
    assert_equal @predecessor_before, File.binread(File.join(ROOT, V2::PREDECESSOR_OUTPUT_PATH)),
                 'R4 tests must preserve the R3 schema-v2 predecessor byte-for-byte'
    assert_equal V2::PREDECESSOR_SHA256,
                 Digest::SHA256.file(File.join(ROOT, V2::PREDECESSOR_OUTPUT_PATH)).hexdigest
    FileUtils.remove_entry_secure(@test_tmpdir) if @test_tmpdir && File.exist?(@test_tmpdir)
  end

  def test_unavailable_schema_v2_is_deterministic_exact_and_authority_empty
    first = V2.serialized
    second = V2.serialized
    ledger = Core.parse_json(first, label: 'schema-v2 ledger')

    assert_equal first, second
    assert first.end_with?("\n")
    refute first.end_with?("\n\n")
    assert_equal V2::TOP_LEVEL_KEYS.sort, ledger.keys.sort
    assert_equal V2::ARTIFACT_ID, ledger.fetch('artifact_id')
    assert_equal V2::SOURCE_KEYS.sort, ledger.fetch('sources').keys.sort
    assert_equal V2.send(:superseded_ledger_reference), ledger.dig('sources', 'superseded_ledger')
    assert_equal ['g0', 'g3'], ledger.fetch('gate_summary').keys.sort
    assert_equal %w[OPEN OPEN], ledger.fetch('gate_summary').values_at('g0', 'g3').map { |gate| gate.fetch('status') }
    assert_equal 'unavailable', ledger.dig('governance_profile_binding', 'status')
    assert_equal 'pointer_missing', ledger.dig('governance_profile_binding', 'reason_code')
    assert_equal 268, ledger.fetch('capabilities').length
    assert_equal 268, ledger.fetch('capabilities').map { |row| row.fetch('capability_id') }.uniq.length
    assert ledger.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }
    assert ledger.fetch('capabilities').all? { |row| row.fetch('governance') == pending_governance }
    assert_equal 0, ledger.dig('capability_summary', 'governance_pointer_present')
    assert_equal 'OPEN', ledger.dig('gate_summary', 'g3', 'criteria', 'current_exact_sha_engineering_map', 'status')
    assert_equal 'OPEN', ledger.dig('gate_summary', 'g3', 'criteria', 'owner_acceptance', 'status')
    assert_nil ledger.dig('gate_summary', 'g3', 'criteria', 'owner_acceptance', 'authority_pointer')
    assert_empty Core.secret_locations(ledger)
    if File.file?(File.join(ROOT, V2::OUTPUT_PATH))
      assert_equal first, File.binread(File.join(ROOT, V2::OUTPUT_PATH))
      assert V2.check!
    else
      error = assert_raises(V2::Error) { V2.check! }
      assert_match(/schema-v2 ledger: (?:path )?unavailable/, error.message)
    end
    assert_historical_hashes
  end

  def test_exact_268_source_pointers_and_active_pointer_selection_bundle_register_bindings
    ledger = build_active
    expanded = read_candidate(Generator::FILES.fetch('expanded_decision_register'))
    binding = ledger.fetch('governance_profile_binding')
    manifest_path = File.join(@candidate, Generator::FILES.fetch('bundle_manifest'))
    expanded_path = File.join(@candidate, Generator::FILES.fetch('expanded_decision_register'))
    gate_path = File.join(@candidate, Generator::FILES.fetch('gate_register'))

    assert_equal V2::PROFILE_BINDING_KEYS.sort, binding.keys.sort
    assert_equal 'active', binding.fetch('status')
    assert_nil binding.fetch('reason_code')
    assert_equal 7, binding.fetch('pointer_revision')
    assert_equal fixture_sha('pointer'), binding.fetch('pointer_sha256')
    assert_equal relative(File.join(@fixture_root, 'selection/active.json')), binding.fetch('selection_path')
    assert_equal fixture_sha('selection'), binding.fetch('selection_sha256')
    assert_equal relative(manifest_path), binding.fetch('bundle_manifest_path')
    assert_equal Digest::SHA256.file(manifest_path).hexdigest, binding.fetch('bundle_manifest_sha256')
    assert_equal relative(expanded_path), binding.fetch('expanded_register_path')
    assert_equal Digest::SHA256.file(expanded_path).hexdigest, binding.fetch('expanded_register_sha256')
    assert_equal relative(gate_path), binding.fetch('gate_register_path')
    assert_equal Digest::SHA256.file(gate_path).hexdigest, binding.fetch('gate_register_sha256')

    rows = ledger.fetch('capabilities')
    assert_equal expanded.fetch('canonical_order'), rows.map { |row| row.fetch('capability_id') }
    assert_equal 268, rows.length
    rows.zip(expanded.fetch('entries')).each_with_index do |(row, expanded_row), index|
      assert_equal expanded_row.fetch('source_decision_pointer'), row.fetch('source_decision_pointer'), index
      pointer = row.fetch('governance_decision_pointer')
      assert_equal V2::GOVERNANCE_POINTER_KEYS.sort, pointer.keys.sort, index
      assert_equal index, pointer.fetch('entry_index'), index
      assert_equal 0, pointer.fetch('entry_index_base'), index
      assert_equal Core.canonical_sha256(expanded_row), pointer.fetch('entry_sha256'), index
      assert_equal binding.fetch('expanded_register_sha256'), pointer.fetch('expanded_register_sha256'), index
      assert_equal binding.fetch('selection_sha256'), pointer.fetch('selection_sha256'), index
      assert_equal binding.fetch('bundle_manifest_sha256'), pointer.fetch('bundle_manifest_sha256'), index
      assert_nil pointer.fetch('decision_event_id'), index
      assert_nil pointer.fetch('decision_event_sha256'), index
    end
    assert_equal 268, ledger.dig('capability_summary', 'governance_pointer_present')
    assert_equal 'OPEN', ledger.dig('gate_summary', 'g0', 'status')
    assert_equal 'OPEN', ledger.dig('gate_summary', 'g3', 'status')
  end

  def test_held_disabled_missing_unreadable_recovery_and_invalid_pointer_states_force_open
    reasons = %w[
      pointer_missing pointer_held pointer_disabled pointer_unreadable
      pointer_recovery_required pointer_contract_invalid selection_contract_invalid
      bundle_contract_invalid
    ]

    reasons.each do |reason|
      V2.stub(:resolve_governance, [nil, reason]) do
        ledger = V2.build
        assert_equal ['unavailable', reason], ledger.fetch('governance_profile_binding').values_at('status', 'reason_code'), reason
        assert_equal %w[OPEN OPEN], ledger.fetch('gate_summary').values_at('g0', 'g3').map { |gate| gate.fetch('status') }, reason
        assert ledger.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }, reason
        assert ledger.fetch('capabilities').all? { |row| row.fetch('governance') == pending_governance }, reason
      end
    end
  end

  def test_active_snapshot_change_fails_instead_of_publishing_an_open_reinterpretation
    first = active_resolution
    second = first.merge(pointer_sha256: fixture_sha('changed pointer'))
    sequence = [first, second]
    resolver = ->(**_keywords) { sequence.shift }

    error = assert_raises(V2::Error) { V2.build(resolver: resolver) }
    assert_match(/active snapshot changed/i, error.message)
  end

  def test_independent_g0_recomputation_rejects_gate_mismatch_source_drift_missing_duplicate_and_order
    mutations = {
      'gate mismatch' => lambda do |candidate|
        path = File.join(candidate, Generator::FILES.fetch('gate_register'))
        gate = JSON.parse(File.binread(path))
        gate['project_g0'] = 'PASS'
        File.binwrite(path, Core.canonical_json(gate) + "\n")
      end,
      'source pointer drift' => lambda do |candidate|
        mutate_expanded(candidate) { |expanded| expanded.fetch('entries').first.fetch('source_decision_pointer')['entry_sha256'] = '0' * 64 }
      end,
      'missing capability' => lambda do |candidate|
        mutate_expanded(candidate) { |expanded| expanded.fetch('entries').pop }
      end,
      'duplicate capability' => lambda do |candidate|
        mutate_expanded(candidate) { |expanded| expanded.fetch('entries')[1] = deep_copy(expanded.fetch('entries')[0]) }
      end,
      'reordered capability' => lambda do |candidate|
        mutate_expanded(candidate) do |expanded|
          expanded.fetch('entries')[0], expanded.fetch('entries')[1] = expanded.fetch('entries')[1], expanded.fetch('entries')[0]
        end
      end
    }

    mutations.each do |label, mutation|
      with_candidate_copy(label) do |candidate|
        mutation.call(candidate)
        error = assert_raises(V2::Error, label) { V2.build(resolver: constant_resolver(candidate)) }
        assert_match(/(?:gate|source pointer|capability universe)/i, error.message, label)
      end
    end
  end

  def test_stale_serialized_hash_order_missing_duplicate_and_unknown_changes_are_rejected
    baseline = V2.serialized
    mutations = {
      'stale hash' => ->(doc) { doc.fetch('sources').fetch('engineering_evidence_map_v2')['sha256'] = '0' * 64 },
      'order' => ->(doc) { doc.fetch('capabilities')[0], doc.fetch('capabilities')[1] = doc.fetch('capabilities')[1], doc.fetch('capabilities')[0] },
      'missing' => ->(doc) { doc.fetch('capabilities').pop },
      'duplicate' => ->(doc) { doc.fetch('capabilities')[1] = deep_copy(doc.fetch('capabilities')[0]) },
      'unknown' => ->(doc) { doc['invented_authority'] = true }
    }

    mutations.each do |label, mutation|
      directory = Dir.mktmpdir('.g0-g3-ledger-check-', ROOT)
      begin
        relative_output = relative_new(File.join(directory, 'ledger.json'))
        document = JSON.parse(baseline)
        mutation.call(document)
        File.binwrite(File.join(ROOT, relative_output), Core.canonical_json(document) + "\n")
        assert_raises(V2::Error, label) { V2.check!(output: relative_output) }
      ensure
        FileUtils.remove_entry_secure(directory) if File.exist?(directory)
      end
    end
  end

  def test_schema_v2_write_is_create_only_safe_and_never_mutates_pointer_or_selection
    directory = Dir.mktmpdir('.g0-g3-ledger-write-', ROOT)
    begin
      output = relative_new(File.join(directory, 'ledger.json'))
      pointer_inventory = governance_selection_inventory
      assert V2.write!(output: output)
      assert_equal V2.serialized, File.binread(File.join(ROOT, output))
      assert_equal 0o600, File.stat(File.join(ROOT, output)).mode & 0o777
      assert_raises(V2::Error) { V2.write!(output: output) }
      assert_equal pointer_inventory, governance_selection_inventory
      selector_lock = File.join(ROOT, Selector::LOCK_RELATIVE_PATH)
      assert File.file?(selector_lock)
      refute File.symlink?(selector_lock)
      assert_equal 0o600, File.stat(selector_lock).mode & 0o777

      %w[../escape.json /tmp/absolute.json].each do |unsafe|
        assert_raises(V2::Error, unsafe) { V2.write!(output: unsafe) }
      end

      linked = File.join(directory, 'linked-output')
      outside = Dir.mktmpdir('g0-g3-ledger-outside-')
      begin
        File.symlink(outside, linked)
        assert_raises(V2::Error) { V2.write!(output: relative_new(File.join(linked, 'ledger.json'))) }
      ensure
        FileUtils.remove_entry_secure(outside) if File.exist?(outside)
      end
    ensure
      FileUtils.remove_entry_secure(directory) if File.exist?(directory)
    end
  end

  def test_binding_change_between_serialization_and_publication_fails_without_output
    output = fixture_relative('race-ledger.json')
    changed = false
    resolver = lambda do |**_keywords|
      active_resolution.merge(pointer_sha256: fixture_sha(changed ? 'race-after' : 'race-before'))
    end
    before_publish = -> { changed = true }
    pointer_inventory = governance_selection_inventory

    error = assert_raises(V2::Error) do
      V2.write!(output: output, resolver: resolver, before_publish: before_publish)
    end
    assert_match(/active snapshot changed before ledger publication/i, error.message)
    refute File.exist?(File.join(ROOT, output))
    refute File.symlink?(File.join(ROOT, output))
    assert_equal pointer_inventory, governance_selection_inventory
  end

  def test_schema_v2_cli_contract_failure_is_sanitized_one_line_without_backtrace
    unless File.file?(File.join(ROOT, V2::OUTPUT_PATH))
      error = assert_raises(V2::Error) { V2.check! }
      assert_match(/schema-v2 ledger: (?:path )?unavailable/, error.message)
      return
    end

    stdout, stderr, status = Open3.capture3(
      RbConfig.ruby, SCRIPT, '--write', '--snapshot-date', V2::SNAPSHOT_DATE,
      '--schema-version', '2', chdir: ROOT
    )

    assert_equal 1, status.exitstatus
    assert_empty stdout
    assert_match(/\A(?:schema-v2 ledger already exists|\$\.engineering_evidence_map_v2: generated document differs from exact engineering projection)\n\z/,
                 stderr)
    refute_match(/generate-g0-g3-coverage-ledger\.rb:\d+|Traceback|backtrace/i, stderr)
  end

  def test_engineering_and_hosted_evidence_cannot_confer_owner_authority_or_g0
    rows = V2.build.fetch('capabilities')
    assert rows.any? { |row| row.dig('engineering_evidence', 'runtime_availability') == 'PARTIAL' }
    assert rows.all? { |row| row.fetch('governance_decision_pointer').nil? }
    assert rows.all? { |row| row.fetch('governance') == pending_governance }

    evidence = V2::G3_CRITERIA_KEYS.to_h do |criterion|
      [criterion, g3_input(criterion)]
    end
    ledger = V2.build(g3_evidence: evidence)
    assert_equal 'OPEN', ledger.dig('gate_summary', 'g0', 'status')
    assert_equal 'OPEN', ledger.dig('gate_summary', 'g3', 'status')
    assert_equal 'G0_OPEN', ledger.dig('gate_summary', 'g3', 'reason_codes').first
    assert ledger.dig('gate_summary', 'g3', 'criteria').values.all? { |criterion| criterion.fetch('status') == 'PASS' }
    assert ledger.fetch('capabilities').all? { |row| row.fetch('governance') == pending_governance }
  end

  def test_g3_rejects_arbitrary_stale_cross_sha_secret_and_non_attributable_evidence
    readme = reference('docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md')
    arbitrary = { 'evidence_pointer' => readme, 'authority_pointer' => nil }
    assert_raises(V2::Error) { V2.build(g3_evidence: { 'hosted_role_based_uat' => arbitrary }) }

    stale = g3_input('security')
    stale.fetch('evidence_pointer')['sha256'] = '0' * 64
    assert_raises(V2::Error) { V2.build(g3_evidence: { 'security' => stale }) }

    mismatched = {
      'security' => g3_input('security', deployed_sha: 'a' * 40),
      'performance' => g3_input('performance', deployed_sha: 'b' * 40)
    }
    assert_raises(V2::Error) { V2.build(g3_evidence: mismatched) }

    owner_without_authority = g3_input('owner_acceptance')
    owner_without_authority['authority_pointer'] = nil
    assert_raises(V2::Error) { V2.build(g3_evidence: { 'owner_acceptance' => owner_without_authority }) }

    engineering_as_authority = g3_input('owner_acceptance')
    engineering_as_authority['authority_pointer'] = reference(V2::EVIDENCE_MAP_PATH)
    assert_raises(V2::Error) { V2.build(g3_evidence: { 'owner_acceptance' => engineering_as_authority }) }

    secret_path = fixture_relative('g3-secret.json')
    secret_record = g3_record('recovery', 'a' * 40)
    secret_record['password'] = 'fixture-secret-must-not-echo'
    write_fixture_json(secret_path, secret_record)
    secret_input = { 'evidence_pointer' => reference(secret_path), 'authority_pointer' => nil }
    error = assert_raises(V2::Error) { V2.build(g3_evidence: { 'recovery' => secret_input }) }
    refute_includes error.message, 'fixture-secret-must-not-echo'
  end

  private

  def active_resolution(candidate = @candidate)
    manifest = File.join(candidate, Generator::FILES.fetch('bundle_manifest'))
    selection = File.join(@fixture_root, 'selection/active.json')
    {
      root: Pathname.new(ROOT).realpath,
      pointer: { 'revision' => 7 },
      pointer_sha256: fixture_sha('pointer'),
      selection_path: Pathname.new(selection),
      selection_sha256: fixture_sha('selection'),
      bundle_path: Pathname.new(candidate),
      bundle_sha256: Digest::SHA256.file(manifest).hexdigest,
      adoption_sha256: fixture_sha('adoption'),
      validator_contract: {
        'name' => 'g0_proportional_governance_v2',
        'path' => V2::CONTRACT_PATH,
        'sha256' => Digest::SHA256.file(File.join(ROOT, V2::CONTRACT_PATH)).hexdigest,
        'version' => Core.parse_json_file(File.join(ROOT, V2::CONTRACT_PATH), label: '$.contract').dig('validator', 'version')
      }
    }
  end

  def build_active(candidate = @candidate)
    V2.build(resolver: constant_resolver(candidate))
  end

  def constant_resolver(candidate)
    resolution = active_resolution(candidate)
    ->(**_keywords) { resolution }
  end

  def read_candidate(name, candidate = @candidate)
    Core.parse_json_file(File.join(candidate, name), label: name)
  end

  def mutate_expanded(candidate)
    path = File.join(candidate, Generator::FILES.fetch('expanded_decision_register'))
    expanded = JSON.parse(File.binread(path))
    yield expanded
    File.binwrite(path, Core.canonical_json(expanded) + "\n")
  end

  def with_candidate_copy(label)
    directory = Dir.mktmpdir(".g0-g3-ledger-#{label.gsub(/\W+/, '-')}-", ROOT)
    candidate = File.join(directory, 'candidate')
    FileUtils.cp_r(@candidate, candidate, preserve: true)
    yield candidate
  ensure
    FileUtils.remove_entry_secure(directory) if directory && File.exist?(directory)
  end

  def pending_governance
    {
      'governance_state' => 'PENDING', 'owner_state' => 'DRAFT',
      'owner_assignment_status' => 'pending', 'decision_status' => 'pending',
      'owner_record_id' => nil, 'decision_event_id' => nil,
      'canonical_disposition' => nil, 'derived_tier' => nil,
      'g0_terminal' => false, 'implementation_authorized' => false
    }
  end

  def g3_input(criterion, deployed_sha: 'a' * 40)
    evidence_path = fixture_relative("g3-#{criterion}-#{deployed_sha[0, 8]}.json")
    write_fixture_json(evidence_path, g3_record(criterion, deployed_sha))
    evidence = reference(evidence_path)
    authority = nil
    if criterion == 'owner_acceptance'
      authority_path = fixture_relative("g3-owner-authority-#{deployed_sha[0, 8]}.json")
      acceptance = {
        'artifact_type' => 'g3_owner_acceptance_decision',
        'schema_version' => 1,
        'decision_id' => "G3-OWNER-ACCEPTANCE-#{deployed_sha[0, 8]}",
        'status' => 'approved',
        'snapshot_date' => V2::SNAPSHOT_DATE,
        'data_boundary' => 'synthetic_only',
        'deployed_commit_sha' => deployed_sha,
        'institutional_id' => 'fixture-product-authority',
        'capacity' => 'product_authority',
        'accepted_criteria' => V2::G3_CRITERIA_KEYS,
        'evidence_reference' => evidence,
        'authority_effect' => 'confers_g3_owner_acceptance_only'
      }
      write_fixture_json(authority_path, acceptance)
      authority = reference(authority_path)
    end
    { 'evidence_pointer' => evidence, 'authority_pointer' => authority }
  end

  def g3_record(criterion, deployed_sha)
    evidence_reference = if criterion == 'current_exact_sha_engineering_map'
                           reference(V2::EVIDENCE_MAP_PATH)
                         else
                           reference('docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json')
                         end
    {
      'artifact_type' => 'g3_release_evidence_record',
      'schema_version' => 1,
      'criterion' => criterion,
      'covered_criteria' => [criterion],
      'status' => 'PASS',
      'snapshot_date' => V2::SNAPSHOT_DATE,
      'data_boundary' => 'synthetic_only',
      'deployed_commit_sha' => deployed_sha,
      'evidence_references' => [evidence_reference],
      'authority_effect' => 'none_engineering_or_assurance_evidence_only'
    }
  end

  def fixture_relative(name)
    relative_new(File.join(@test_tmpdir, name))
  end

  def write_fixture_json(relative_path, document)
    File.binwrite(File.join(ROOT, relative_path), Core.canonical_json(document) + "\n")
  end

  def reference(relative_path)
    { 'path' => relative_path, 'sha256' => Digest::SHA256.file(File.join(ROOT, relative_path)).hexdigest }
  end

  def governance_selection_inventory
    roots = [
      Selector::POINTER_RELATIVE_PATH, Selector::RECOVERY_MARKER_RELATIVE_PATH,
      Selector::SELECTIONS_RELATIVE_PATH, Selector::DECISIONS_RELATIVE_PATH,
      Selector::JOURNAL_RELATIVE_PATH
    ].map { |relative_path| File.join(ROOT, relative_path) }
    paths = roots.flat_map do |root_path|
      next [] unless File.exist?(root_path) || File.symlink?(root_path)

      [root_path] + (File.directory?(root_path) && !File.symlink?(root_path) ? Dir.glob(File.join(root_path, '**', '*'), File::FNM_DOTMATCH) : [])
    end
    paths.sort.to_h do |path|
      [path, File.file?(path) && !File.symlink?(path) ? Digest::SHA256.file(path).hexdigest : File.lstat(path).ftype]
    end
  end

  def historical_v1_bytes
    HISTORICAL_V1_HASHES.keys.to_h { |relative_path| [relative_path, File.binread(File.join(ROOT, relative_path))] }
  end

  def assert_historical_hashes
    HISTORICAL_V1_HASHES.each do |relative_path, expected_sha|
      assert_equal expected_sha, Digest::SHA256.file(File.join(ROOT, relative_path)).hexdigest, relative_path
    end
  end

  def fixture_sha(value)
    Digest::SHA256.hexdigest(value)
  end

  def relative(path)
    Pathname.new(path).realpath.relative_path_from(Pathname.new(ROOT).realpath).to_s
  end

  def relative_new(path)
    Pathname.new(path).expand_path.relative_path_from(Pathname.new(ROOT).realpath).to_s
  end

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end
end
