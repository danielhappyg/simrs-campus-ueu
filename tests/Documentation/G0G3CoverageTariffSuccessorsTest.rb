# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'pathname'
require 'tmpdir'

require_relative '../../scripts/generate-g0-g3-coverage-tariff-successors'

class G0G3CoverageTariffSuccessorsTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  Generator = G0G3CoverageTariffSuccessors
  Core = G0ProportionalGovernanceV2

  MAP_SHA256 = 'c59c15593c0db6f37ef144f95a7d04d22df60404ee7cf2e68562501cc07316f8'
  LEDGER_SHA256 = '1cef27463e0d5f7c887e68d6031671e13b3b7bbf980bd7ed1c01db6e73ec09f2'

  IMMUTABLE_PATHS = {
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json' =>
      '3b1005a31087896b412a8f64a3dbfced2c0ab655be78c32abd7c0743ae3811d5',
    Generator::MAP_PREDECESSOR_PATH => Generator::MAP_PREDECESSOR_SHA256,
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29.json' =>
      '690becdf75a08d17b992d8dad754313f33d0f9c8ea2c692b9b12b8b837f43ac5',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json' =>
      '0b89705741bb052629593b9023f1c8d19c7827489a7e8e6ce6e46af1efd5f1c6',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json' =>
      '0f312713000b0092de313baee2d3cc6d34695c2fefd717c620871268045ac58c',
    Generator::LEDGER_PREDECESSOR_PATH => Generator::LEDGER_PREDECESSOR_SHA256
  }.freeze

  def setup
    @tmpdir = Pathname.new(Dir.mktmpdir('.g0-g3-tariff-successors-', ROOT)).realpath
    @immutable_bytes = IMMUTABLE_PATHS.to_h do |relative, _sha256|
      [relative, File.binread(File.join(ROOT, relative))]
    end
    @map_predecessor = parse(Generator::MAP_PREDECESSOR_PATH)
    @map = parse(Generator::MAP_OUTPUT_PATH)
    @ledger_predecessor = parse(Generator::LEDGER_PREDECESSOR_PATH)
    @ledger = parse(Generator::LEDGER_OUTPUT_PATH)
  end

  def teardown
    @immutable_bytes.each do |relative, bytes|
      path = File.join(ROOT, relative)
      assert_equal bytes, File.binread(path), "successor tests must preserve #{relative} byte-for-byte"
      assert_equal IMMUTABLE_PATHS.fetch(relative), Digest::SHA256.file(path).hexdigest, relative
    end
    FileUtils.remove_entry_secure(@tmpdir) if @tmpdir&.exist?
  end

  def test_committed_successors_remain_exact_immutable_historical_artifacts
    map_path = File.join(ROOT, Generator::MAP_OUTPUT_PATH)
    ledger_path = File.join(ROOT, Generator::LEDGER_OUTPUT_PATH)

    assert_equal MAP_SHA256, Digest::SHA256.file(map_path).hexdigest
    assert_equal LEDGER_SHA256, Digest::SHA256.file(ledger_path).hexdigest
    assert_equal 0o644, File.stat(map_path).mode & 0o777
    assert_equal 0o600, File.stat(ledger_path).mode & 0o777
    assert_equal 1, File.stat(map_path).nlink
    assert_equal 1, File.stat(ledger_path).nlink

    assert_equal Generator::MAP_ARTIFACT_ID, @map.fetch('artifact_id')
    assert_equal Generator::LEDGER_ARTIFACT_ID, @ledger.fetch('artifact_id')
    assert_equal Generator::SNAPSHOT_DATE, @map.fetch('snapshot_date')
    assert_equal Generator::SNAPSHOT_DATE, @ledger.fetch('snapshot_date')
    assert_equal Generator.send(:map_predecessor_reference), @map.fetch('superseded_evidence_map')
    assert_equal Generator.send(:ledger_predecessor_reference), @ledger.dig('sources', 'superseded_ledger')
    assert_equal({ 'path' => Generator::MAP_OUTPUT_PATH, 'sha256' => MAP_SHA256 },
                 @ledger.dig('sources', 'engineering_evidence_map_v2'))
  end

  def test_map_changes_only_the_closed_tariff_capabilities_and_conservative_e2e16_fields
    predecessor_by_id = capability_index(@map_predecessor)
    successor_by_id = capability_index(@map)
    assert_equal predecessor_by_id.keys, successor_by_id.keys

    successor_by_id.each do |capability_id, row|
      predecessor = predecessor_by_id.fetch(capability_id)
      if Generator::TARGET_CAPABILITY_IDS.include?(capability_id)
        assert_equal expected_tariff_engineering, row.fetch('engineering_evidence'), capability_id
        assert_equal({ 'status' => 'PROVISIONAL', 'scenario_ids' => ['E2E-16'] },
                     row.fetch('workflow_observation'), capability_id)
        assert_equal capability_id, predecessor.fetch('capability_id')
      else
        assert_equal predecessor, row, capability_id
      end
    end

    predecessor_workflows = workflow_index(@map_predecessor)
    successor_workflows = workflow_index(@map)
    successor_workflows.each do |workflow_id, row|
      predecessor = predecessor_workflows.fetch(workflow_id)
      if workflow_id == 'E2E-16'
        assert_equal 'PARTIAL', row.fetch('runtime_availability')
        assert_equal 'PARTIAL_PASS', row.fetch('automated_evidence')
        assert_equal predecessor.fetch('database_engine_evidence'), row.fetch('database_engine_evidence')
        assert_equal 'HISTORICAL_PARTIAL', row.fetch('hosted_uat')
        assert_equal 'PARTIAL', row.fetch('reconciliation')
        assert_equal 'PENDING', row.fetch('defect_status')
        assert_equal (predecessor.fetch('evidence_paths') + Generator::NEW_EVIDENCE_PATHS).uniq.sort,
                     row.fetch('evidence_paths')
      else
        assert_equal predecessor, row, workflow_id
      end
    end

    %w[
      artifact_type schema_version data_boundary source_evidence_map
      canonical_order_source capability_defaults workflow_observation_default
    ].each do |key|
      assert_equal @map_predecessor.fetch(key), @map.fetch(key), key
    end
    assert_equal @map_predecessor.dig('provenance', 'historical_engineering_override_count'),
                 @map.dig('provenance', 'historical_engineering_override_count')
  end

  def test_historical_evidence_bindings_remain_closed_after_live_source_advances
    predecessor_inputs = @map_predecessor.fetch('explicit_evidence_inputs').to_h { |row| [row.fetch('path'), row] }
    successor_inputs = @map.fetch('explicit_evidence_inputs').to_h { |row| [row.fetch('path'), row] }

    predecessor_inputs.each do |path, record|
      assert_equal record, successor_inputs.fetch(path), path
    end
    assert_empty Generator::NEW_EVIDENCE_PATHS & predecessor_inputs.keys
    live_drift = Generator::NEW_EVIDENCE_PATHS.count do |relative|
      record = successor_inputs.fetch(relative)
      assert_equal relative, record.fetch('path')
      assert_match(/\A[0-9a-f]{64}\z/, record.fetch('sha256'), relative)
      Digest::SHA256.file(File.join(ROOT, relative)).hexdigest != record.fetch('sha256')
    end
    assert_operator live_drift, :>, 0, 'a successor, not R3 mutation, must record later source bytes'
    paths = @map.fetch('explicit_evidence_inputs').map { |record| record.fetch('path') }
    assert_equal paths.sort, paths
    assert_equal paths.uniq, paths
    assert_equal paths.length, @map.dig('provenance', 'explicit_evidence_input_count')
    assert_equal Generator::GENERATOR_PATH, @map.dig('provenance', 'generator', 'path')
    assert_match(/\A[0-9a-f]{64}\z/, @map.dig('provenance', 'generator', 'sha256'))
  end

  def test_ledger_copies_only_the_new_engineering_observations_and_never_promotes_authority
    %w[
      artifact_type schema_version data_boundary authority_boundary
      governance_profile_binding capability_summary workflow_summary gate_summary
    ].each do |key|
      assert_equal @ledger_predecessor.fetch(key), @ledger.fetch(key), key
    end

    assert_equal 'unavailable', @ledger.dig('governance_profile_binding', 'status')
    assert_equal 'pointer_missing', @ledger.dig('governance_profile_binding', 'reason_code')
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g0', 'status')
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g3', 'status')
    @ledger.dig('gate_summary', 'g3', 'criteria').each_value do |criterion|
      assert_equal 'OPEN', criterion.fetch('status')
      assert_nil criterion.fetch('deployed_commit_sha')
      assert_nil criterion.fetch('evidence_pointer')
      assert_nil criterion.fetch('authority_pointer')
    end

    predecessor_sources = @ledger_predecessor.fetch('sources')
    @ledger.fetch('sources').each do |key, value|
      next if %w[engineering_evidence_map_v2 superseded_ledger].include?(key)

      assert_equal predecessor_sources.fetch(key), value, key
    end

    predecessor_by_id = capability_index(@ledger_predecessor)
    successor_by_id = capability_index(@ledger)
    successor_by_id.each do |capability_id, row|
      predecessor = predecessor_by_id.fetch(capability_id)
      if Generator::TARGET_CAPABILITY_IDS.include?(capability_id)
        %w[capability_id batch source_decision_pointer governance_decision_pointer governance].each do |key|
          expected = predecessor.fetch(key)
          expected.nil? ? assert_nil(row.fetch(key), "#{capability_id}.#{key}") :
            assert_equal(expected, row.fetch(key), "#{capability_id}.#{key}")
        end
        assert_equal expected_tariff_engineering, row.fetch('engineering_evidence')
        assert_equal({ 'status' => 'PROVISIONAL', 'scenario_ids' => ['E2E-16'] },
                     row.fetch('workflow_observation'))
      else
        assert_equal predecessor, row, capability_id
      end
    end
    assert @ledger.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }
    assert @ledger.fetch('capabilities').all? { |row| row.dig('governance', 'implementation_authorized') == false }

    predecessor_workflows = workflow_index(@ledger_predecessor)
    workflow_index(@ledger).each do |workflow_id, row|
      if workflow_id == 'E2E-16'
        assert_equal workflow_index(@map).fetch('E2E-16'), row
      else
        assert_equal predecessor_workflows.fetch(workflow_id), row, workflow_id
      end
    end
  end

  def test_unknown_authority_fields_and_secret_like_content_fail_closed
    injected = deep_copy(@map)
    target = capability_index(injected).fetch('PAR-ADM-011')
    target['owner_approval'] = 'PASS'
    assert_raises(Generator::Error) { Generator.validate_map!(injected, root: ROOT) }

    secret = deep_copy(@map)
    secret.fetch('explicit_evidence_inputs').first['path'] = 'password=should-not-be-here'
    error = assert_raises(Generator::Error) { Generator.validate_map!(secret, root: ROOT) }
    assert_match(/secret-like|differs from closed|unsafe/i, error.message)

    promoted = deep_copy(@ledger)
    capability_index(promoted).fetch('PAR-ADM-011')['governance_decision_pointer'] = { 'invented' => true }
    assert_raises(Generator::Error) { Generator.validate_ledger!(promoted, root: ROOT) }
  end

  def test_historical_publisher_fails_closed_after_bound_source_drift
    map_output = relative(@tmpdir.join('map.json'))
    ledger_output = relative(@tmpdir.join('ledger.json'))
    receipt = Generator.write_map!(root: ROOT, output: map_output)
    published = File.binread(File.join(ROOT, map_output))
    assert_equal Digest::SHA256.hexdigest(published), receipt.fetch('sha256')
    assert_raises(Generator::UsageError) { Generator.write_map!(root: ROOT, output: map_output) }
    assert_raises(Generator::Error) { Generator.write_ledger!(root: ROOT, output: ledger_output) }
    assert_equal published, File.binread(File.join(ROOT, map_output))
    refute File.exist?(File.join(ROOT, ledger_output))
  end

  def test_predecessor_missing_drift_symlink_and_hardlink_fail_closed
    %i[missing drift symlink hardlink].each do |mode|
      fixture = build_map_fixture(mode)
      error = assert_raises(Generator::Error, mode) { Generator.map_document(root: fixture) }
      assert_match(/predecessor|source|symlink|unsafe|unavailable|hash|hard link/i, error.message, mode)
    end
  end

  def test_new_evidence_symlink_and_hardlink_fail_closed
    %i[symlink hardlink].each do |mode|
      fixture = build_map_fixture(:valid)
      relative = Generator::NEW_EVIDENCE_PATHS.first
      target = fixture.join(relative)
      copy = fixture.join("#{relative}.copy")
      FileUtils.cp(target, copy)
      File.unlink(target)
      if mode == :symlink
        File.symlink(copy, target)
      else
        File.link(copy, target)
      end

      error = assert_raises(Generator::Error, mode) { Generator.map_document(root: fixture) }
      assert_match(/symlink|unsafe|hard link/i, error.message, mode)
    end
  end

  def test_readme_retains_r3_r5_as_immutable_predecessors_not_current
    readme = File.read(File.join(ROOT, 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md'))
    assert_includes readme, Generator::MAP_OUTPUT_PATH.split('/').last
    assert_includes readme, Generator::LEDGER_OUTPUT_PATH.split('/').last
    assert_includes readme, MAP_SHA256
    assert_includes readme, LEDGER_SHA256
    assert_match(/R3.*immutable/m, readme)
    assert_match(/R5.*immutable/m, readme)
    assert_includes readme, '`pointer_missing`'
    assert_includes readme, 'G0 and G3 remain `OPEN`'
    assert_match(/does not prove (?:owner|domain) acceptance, hosted UAT, migration, deployment, G0, or G3/i, readme)
  end

  private

  def expected_tariff_engineering
    Generator.send(:tariff_engineering_evidence)
  end

  def parse(relative)
    Core.parse_json(File.binread(File.join(ROOT, relative)), label: relative)
  end

  def capability_index(document)
    document.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
  end

  def workflow_index(document)
    document.fetch('workflows').to_h { |row| [row.fetch('workflow_id'), row] }
  end

  def deep_copy(value)
    JSON.parse(JSON.generate(value))
  end

  def relative(path)
    path.relative_path_from(Pathname.new(ROOT).realpath).to_s
  end

  def build_map_fixture(mode)
    root = @tmpdir.join("fixture-#{mode}-#{SecureRandom.hex(4)}")
    root.mkpath
    required = [Generator::GENERATOR_PATH] + Generator::NEW_EVIDENCE_PATHS
    required.each do |relative|
      destination = root.join(relative)
      FileUtils.mkdir_p(destination.parent)
      FileUtils.cp(File.join(ROOT, relative), destination)
    end
    predecessor = root.join(Generator::MAP_PREDECESSOR_PATH)
    FileUtils.mkdir_p(predecessor.parent)
    unless mode == :missing
      FileUtils.cp(File.join(ROOT, Generator::MAP_PREDECESSOR_PATH), predecessor)
    end

    case mode
    when :drift
      File.open(predecessor, 'ab') { |file| file.write("drift\n") }
    when :symlink
      File.unlink(predecessor)
      File.symlink(File.join(ROOT, Generator::MAP_PREDECESSOR_PATH), predecessor)
    when :hardlink
      copy = root.join('map-predecessor-copy.json')
      FileUtils.cp(File.join(ROOT, Generator::MAP_PREDECESSOR_PATH), copy)
      File.unlink(predecessor)
      File.link(copy, predecessor)
    end
    root.realpath
  end
end
