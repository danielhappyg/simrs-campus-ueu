# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

require_relative '../../scripts/generate-g0-g3-coverage-laboratory-successors'

class G0G3CoverageLaboratorySuccessorsTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  Generator = G0G3CoverageLaboratorySuccessors
  Core = G0ProportionalGovernanceV2

  MAP_SHA256 = '07bef2b234f31336eb730dce4d035b830b91dc7995c2f979f2ba59be0558a921'
  LEDGER_SHA256 = '3163e1054d83c17b6938149e6a5af934bfc0bdc333226697fed8bde178dcad23'
  FINAL_EVIDENCE_SHA256 = '0729f7d9de36960ad631acf2ddcbd04cf1a3d41be95188e7df0f4886a4ae25c7'
  SOURCE_SHA256 = '7ac7e49b2a1577ad307fa0a37ae68beadb6b2c39962df66ffaf5ae5f0aacc74e'
  EXACT_ARTIFACTS = {
    'storage/app/portability-rehearsals/20260902T052603Z-postgresql17-laboratory-tariff-source-fcfeacb4735a.json' =>
      'b7403d60e0e667e61d5d53ff1575e3bbdca8717ac86d90379573235b80c71efa',
    'storage/app/portability-rehearsals/20260902T052935Z-mysql8411-laboratory-tariff-source-eab90648c5b1.json' =>
      '720a838f46555d20db554404935ccebabd43c310c724ad53ae92c1e960bf0c64',
  }.freeze
  IMMUTABLE_PATHS = {
    Generator::MAP_PREDECESSOR_PATH => Generator::MAP_PREDECESSOR_SHA256,
    Generator::LEDGER_PREDECESSOR_PATH => Generator::LEDGER_PREDECESSOR_SHA256,
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29_R2.json' =>
      '581c0d5eccdd42b818270b3d214e600e33ac12bf6d1895143e644be43783f27f',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R4.json' =>
      '473cba5aec79a09621dda979ebbf0b4ec4c456aaede5bac159291c58062cd8a4',
  }.freeze
  OUTPUT_PATHS = {
    Generator::MAP_OUTPUT_PATH => MAP_SHA256,
    Generator::LEDGER_OUTPUT_PATH => LEDGER_SHA256,
  }.freeze

  def setup
    @immutable_bytes = IMMUTABLE_PATHS.to_h { |path, _| [path, File.binread(File.join(ROOT, path))] }
    @output_bytes = OUTPUT_PATHS.to_h { |path, _| [path, File.binread(File.join(ROOT, path))] }
    @map_predecessor = parse(Generator::MAP_PREDECESSOR_PATH)
    @ledger_predecessor = parse(Generator::LEDGER_PREDECESSOR_PATH)
    @map = parse(Generator::MAP_OUTPUT_PATH)
    @ledger = parse(Generator::LEDGER_OUTPUT_PATH)
  end

  def teardown
    @immutable_bytes.each do |relative, bytes|
      path = File.join(ROOT, relative)
      assert_equal bytes, File.binread(path), "must preserve #{relative} byte-for-byte"
      assert_equal IMMUTABLE_PATHS.fetch(relative), Digest::SHA256.file(path).hexdigest
    end
    @output_bytes.each do |relative, bytes|
      path = File.join(ROOT, relative)
      assert_equal bytes, File.binread(path), "must preserve historical #{relative} byte-for-byte"
      assert_equal OUTPUT_PATHS.fetch(relative), Digest::SHA256.file(path).hexdigest
    end
  end

  def test_historical_successors_are_exact_immutable_create_only_artifacts
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
    assert_equal Generator.send(:map_predecessor_reference), @map.fetch('superseded_evidence_map')
    assert_equal Generator.send(:ledger_predecessor_reference), @ledger.dig('sources', 'superseded_ledger')
    assert_equal({ 'path' => Generator::MAP_OUTPUT_PATH, 'sha256' => MAP_SHA256 },
                 @ledger.dig('sources', 'engineering_evidence_map_v2'))
  end

  def test_map_changes_only_closed_evidence_paths_for_four_capabilities_and_e2e16
    predecessors = capability_index(@map_predecessor)
    capability_index(@map).each do |id, row|
      prior = predecessors.fetch(id)
      if Generator::TARGET_CAPABILITY_IDS.include?(id)
        assert_equal prior.fetch('workflow_observation'), row.fetch('workflow_observation')
        engineering = row.fetch('engineering_evidence')
        prior_engineering = prior.fetch('engineering_evidence')
        (G0G3CoverageTariffSuccessors::ENGINEERING_KEYS - ['evidence_paths']).each do |key|
          assert_equal prior_engineering.fetch(key), engineering.fetch(key), "#{id}.#{key}"
        end
        assert_equal (prior_engineering.fetch('evidence_paths') + Generator::EVIDENCE_PATHS).uniq.sort,
                     engineering.fetch('evidence_paths')
      else
        assert_equal prior, row, id
      end
    end

    workflow_index(@map).each do |id, row|
      prior = workflow_index(@map_predecessor).fetch(id)
      if id == Generator::TARGET_WORKFLOW_ID
        (G0G3CoverageTariffSuccessors::ENGINEERING_KEYS - ['evidence_paths']).each do |key|
          assert_equal prior.fetch(key), row.fetch(key), "#{id}.#{key}"
        end
        assert_equal (prior.fetch('evidence_paths') + Generator::EVIDENCE_PATHS).uniq.sort,
                     row.fetch('evidence_paths')
      else
        assert_equal prior, row, id
      end
    end
  end

  def test_historical_evidence_inputs_retain_recorded_laboratory_hashes_and_artifact_claims
    predecessor = @map_predecessor.fetch('explicit_evidence_inputs').to_h { |row| [row.fetch('path'), row] }
    successor = @map.fetch('explicit_evidence_inputs').to_h { |row| [row.fetch('path'), row] }
    Generator::EVIDENCE_PATHS.each do |relative|
      assert_equal relative, successor.fetch(relative).fetch('path')
      assert_match(/\A[0-9a-f]{64}\z/, successor.fetch(relative).fetch('sha256'), relative)
    end
    untouched = predecessor.keys - Generator::EVIDENCE_PATHS
    untouched.each { |relative| assert_equal predecessor.fetch(relative), successor.fetch(relative), relative }
    final_path = 'docs/operations/T1_LOCAL_LABORATORY_TARIFF_SOURCE_EVIDENCE_2026-09-02.md'
    assert_equal FINAL_EVIDENCE_SHA256, successor.fetch(final_path).fetch('sha256')
    assert_equal FINAL_EVIDENCE_SHA256, Digest::SHA256.file(File.join(ROOT, final_path)).hexdigest
    assert_equal 0o644, File.stat(File.join(ROOT, final_path)).mode & 0o777
    EXACT_ARTIFACTS.each do |relative, sha|
      assert_equal sha, successor.fetch(relative).fetch('sha256'), relative
      assert_equal sha, Digest::SHA256.file(File.join(ROOT, relative)).hexdigest, relative
      assert_equal 0o600, File.stat(File.join(ROOT, relative)).mode & 0o777
      artifact = JSON.parse(File.binread(File.join(ROOT, relative)))
      assert_equal SOURCE_SHA256, artifact.dig('source_bindings', 'application_source_sha256')
      assert_equal 22, artifact.fetch('scenarios').length
      assert artifact.fetch('scenarios').values.all? { |row| row.fetch('status') == 'PASS' }
    end
    paths = @map.fetch('explicit_evidence_inputs').map { |row| row.fetch('path') }
    assert_equal paths.sort, paths
    assert_equal paths.uniq, paths
    assert_equal paths.length, @map.dig('provenance', 'explicit_evidence_input_count')
  end

  def test_ledger_preserves_every_governance_authority_hosted_deployment_and_gate_fact
    %w[
      artifact_type schema_version data_boundary authority_boundary governance_profile_binding
      capability_summary workflow_summary gate_summary
    ].each { |key| assert_equal @ledger_predecessor.fetch(key), @ledger.fetch(key), key }
    assert_equal 'unavailable', @ledger.dig('governance_profile_binding', 'status')
    assert_equal 'pointer_missing', @ledger.dig('governance_profile_binding', 'reason_code')
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g0', 'status')
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g3', 'status')
    assert @ledger.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }
    assert @ledger.fetch('capabilities').all? { |row| row.dig('governance', 'implementation_authorized') == false }

    prior = capability_index(@ledger_predecessor)
    capability_index(@ledger).each do |id, row|
      if Generator::TARGET_CAPABILITY_IDS.include?(id)
        %w[capability_id batch source_decision_pointer governance_decision_pointer governance].each do |key|
          expected = prior.fetch(id).fetch(key)
          expected.nil? ? assert_nil(row.fetch(key), "#{id}.#{key}") :
            assert_equal(expected, row.fetch(key), "#{id}.#{key}")
        end
        assert_equal capability_index(@map).fetch(id).fetch('engineering_evidence'), row.fetch('engineering_evidence')
      else
        assert_equal prior.fetch(id), row, id
      end
    end
  end

  def test_historical_artifacts_contain_no_authority_or_secret_smuggling
    assert @map.fetch('capabilities').all? do |row|
      (row.keys & %w[owner_approval governance_decision_pointer deployment_claim]).empty?
    end
    assert @ledger.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }
    serialized = JSON.generate([@map, @ledger])
    refute_match(/password\s*[:=]/i, serialized)
    refute_match(%r{(?:postgres(?:ql)?|mysql)://}i, serialized)
  end

  def test_readme_retains_r5_r7_as_immutable_predecessors_and_keeps_gates_open
    readme = File.read(File.join(ROOT, 'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md'))
    assert_includes readme, File.basename(Generator::MAP_OUTPUT_PATH)
    assert_includes readme, File.basename(Generator::LEDGER_OUTPUT_PATH)
    assert_includes readme, MAP_SHA256
    assert_includes readme, LEDGER_SHA256
    assert_match(/R5.*immutable/m, readme)
    assert_match(/R7.*immutable/m, readme)
    assert_includes readme, '`pointer_missing`'
    assert_includes readme, 'G0 and G3 remain `OPEN`'
  end

  private

  def parse(relative)
    Core.parse_json(File.binread(File.join(ROOT, relative)), label: relative)
  end

  def capability_index(document)
    document.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
  end

  def workflow_index(document)
    document.fetch('workflows').to_h { |row| [row.fetch('workflow_id'), row] }
  end

end
