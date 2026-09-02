# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'
require 'pathname'

require_relative '../../scripts/generate-g0-g3-coverage-accommodation-successors'

class G0G3CoverageAccommodationSuccessorsTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  Generator = G0G3CoverageAccommodationSuccessors
  Core = G0ProportionalGovernanceV2

  MAP_SHA256 = 'd50430bb982df8d8dfb0a789ab0b2b0c8211557ed1d46d42e51f7126cc22cc08'
  LEDGER_SHA256 = '3df5165be14946794002cc121e1f3f92f64232d6e39057e9d48d65636dfb4665'
  EXACT_EVIDENCE = {
    Generator::FINAL_EVIDENCE_PATH => Generator::FINAL_EVIDENCE_SHA256,
    Generator::POSTGRES_ARTIFACT_PATH => Generator::POSTGRES_ARTIFACT_SHA256,
    Generator::MYSQL_ARTIFACT_PATH => Generator::MYSQL_ARTIFACT_SHA256,
  }.freeze
  EVIDENCE_PATHS = (Generator::BASE_EVIDENCE_PATHS + EXACT_EVIDENCE.keys).uniq.sort.freeze

  IMMUTABLE_PATHS = {
    Generator::MAP_PREDECESSOR_PATH => Generator::MAP_PREDECESSOR_SHA256,
    Generator::LEDGER_PREDECESSOR_PATH => Generator::LEDGER_PREDECESSOR_SHA256,
  }.freeze
  OUTPUT_PATHS = {
    Generator::MAP_OUTPUT_PATH => MAP_SHA256,
    Generator::LEDGER_OUTPUT_PATH => LEDGER_SHA256,
  }.freeze

  def setup
    @root = Pathname.new(ROOT).realpath
    @immutable_bytes = IMMUTABLE_PATHS.to_h { |path, _sha| [path, File.binread(File.join(ROOT, path))] }
    @output_bytes = OUTPUT_PATHS.to_h { |path, _sha| [path, File.binread(File.join(ROOT, path))] }
    @map_predecessor = parse(@root, Generator::MAP_PREDECESSOR_PATH)
    @ledger_predecessor = parse(@root, Generator::LEDGER_PREDECESSOR_PATH)
    @map = parse(@root, Generator::MAP_OUTPUT_PATH)
    @ledger = parse(@root, Generator::LEDGER_OUTPUT_PATH)
  end

  def teardown
    @immutable_bytes.each do |relative, bytes|
      path = File.join(ROOT, relative)
      assert_equal bytes, File.binread(path), "must preserve #{relative} byte-for-byte"
      assert_equal IMMUTABLE_PATHS.fetch(relative), Digest::SHA256.file(path).hexdigest
    end
    @output_bytes.each do |relative, bytes|
      path = File.join(ROOT, relative)
      assert_equal bytes, File.binread(path), "must preserve published #{relative} byte-for-byte"
      assert_equal OUTPUT_PATHS.fetch(relative), Digest::SHA256.file(path).hexdigest
    end
  end

  def test_r6_r8_remain_exact_immutable_historical_successors
    assert_equal 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R5',
                 Generator::MAP_PREDECESSOR_ARTIFACT_ID
    assert_equal 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R6', Generator::MAP_ARTIFACT_ID
    assert_equal 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R7', Generator::LEDGER_PREDECESSOR_ARTIFACT_ID
    assert_equal 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R8', Generator::LEDGER_ARTIFACT_ID
    assert_equal %w[PAR-REG-001 PAR-ADM-009 PAR-ADM-033 PAR-FIN-001 PAR-FIN-002],
                 Generator::TARGET_CAPABILITY_IDS
    assert_equal %w[E2E-14 E2E-16], Generator::TARGET_WORKFLOW_IDS

    map_path = @root.join(Generator::MAP_OUTPUT_PATH)
    ledger_path = @root.join(Generator::LEDGER_OUTPUT_PATH)
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

  def test_closed_projection_changes_only_bounded_accommodation_evidence
    assert_bounded_map_projection(@map_predecessor, @map)
    assert_bounded_ledger_projection(@ledger_predecessor, @map, @ledger)
  end

  def test_historical_evidence_paths_and_exact_engine_artifact_claims_remain_bound
    assert_equal Generator::BASE_EVIDENCE_PATHS.sort, Generator::BASE_EVIDENCE_PATHS
    assert_equal Generator::BASE_EVIDENCE_PATHS.uniq, Generator::BASE_EVIDENCE_PATHS
    Generator::BASE_EVIDENCE_PATHS.each do |relative|
      record = @map.fetch('explicit_evidence_inputs').find { |row| row.fetch('path') == relative }
      refute_nil record, relative
      assert_match(/\A[0-9a-f]{64}\z/, record.fetch('sha256'), relative)
    end
    refute_includes Generator::BASE_EVIDENCE_PATHS, Generator::FINAL_EVIDENCE_PATH

    successor_inputs = @map.fetch('explicit_evidence_inputs').to_h { |row| [row.fetch('path'), row] }
    EXACT_EVIDENCE.each do |relative, sha|
      path = @root.join(relative)
      assert_equal sha, Digest::SHA256.file(path).hexdigest
      assert_equal sha, successor_inputs.fetch(relative).fetch('sha256')
      expected_mode = relative == Generator::FINAL_EVIDENCE_PATH ? 0o644 : 0o600
      assert_equal expected_mode, File.stat(path).mode & 0o777
    end
    [Generator::POSTGRES_ARTIFACT_PATH, Generator::MYSQL_ARTIFACT_PATH].each do |relative|
      artifact = JSON.parse(File.binread(@root.join(relative)))
      assert_equal 'PASS', artifact.fetch('status')
      assert_equal Generator::EXACT_APPLICATION_SOURCE_SHA256,
                   artifact.dig('source_bindings', 'application_source_sha256')
      assert_equal 28, artifact.fetch('scenarios').length
      assert artifact.fetch('scenarios').values.all? { |row| row.fetch('status') == 'PASS' }
      assert_equal true, artifact.dig('cleanup', 'strict_cleanup_verified')
      %w[owner_acceptance_claim deployment_claim g0_claim g3_claim].each do |claim|
        assert_equal false, artifact.fetch(claim)
      end
    end
    paths = @map.fetch('explicit_evidence_inputs').map { |row| row.fetch('path') }
    assert_equal paths.sort, paths
    assert_equal paths.uniq, paths
    assert_equal paths.length, @map.dig('provenance', 'explicit_evidence_input_count')

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

  def test_readme_retains_r6_r8_as_immutable_predecessors_and_keeps_gates_open
    readme = File.binread(@root.join('docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md'))
    assert_includes readme, File.basename(Generator::MAP_OUTPUT_PATH)
    assert_includes readme, File.basename(Generator::LEDGER_OUTPUT_PATH)
    assert_includes readme, MAP_SHA256
    assert_includes readme, LEDGER_SHA256
    assert_match(/R6.*immutable/m, readme)
    assert_match(/R8.*immutable/m, readme)
    assert_includes readme, '`pointer_missing`'
    assert_includes readme, 'G0 and G3 remain `OPEN`'
  end

  private

  def assert_bounded_map_projection(predecessor, successor)
    prior_capabilities = capability_index(predecessor)
    capability_index(successor).each do |id, row|
      prior = prior_capabilities.fetch(id)
      if Generator::TARGET_CAPABILITY_IDS.include?(id)
        assert_equal prior.fetch('workflow_observation'), row.fetch('workflow_observation')
        (G0G3CoverageTariffSuccessors::ENGINEERING_KEYS - ['evidence_paths']).each do |key|
          assert_equal prior.dig('engineering_evidence', key), row.dig('engineering_evidence', key), "#{id}.#{key}"
        end
        assert_equal (prior.dig('engineering_evidence', 'evidence_paths') + EVIDENCE_PATHS).uniq.sort,
                     row.dig('engineering_evidence', 'evidence_paths')
      else
        assert_equal prior, row, id
      end
    end

    prior_workflows = workflow_index(predecessor)
    workflow_index(successor).each do |id, row|
      prior = prior_workflows.fetch(id)
      if Generator::TARGET_WORKFLOW_IDS.include?(id)
        (G0G3CoverageTariffSuccessors::ENGINEERING_KEYS - ['evidence_paths']).each do |key|
          assert_equal prior.fetch(key), row.fetch(key), "#{id}.#{key}"
        end
        assert_equal (prior.fetch('evidence_paths') + EVIDENCE_PATHS).uniq.sort,
                     row.fetch('evidence_paths')
      else
        assert_equal prior, row, id
      end
    end
  end

  def assert_bounded_ledger_projection(predecessor, map, successor)
    %w[
      artifact_type schema_version data_boundary authority_boundary governance_profile_binding
      capability_summary workflow_summary gate_summary
    ].each { |key| assert_equal predecessor.fetch(key), successor.fetch(key), key }
    assert_equal 'pointer_missing', successor.dig('governance_profile_binding', 'reason_code')
    assert_equal 'OPEN', successor.dig('gate_summary', 'g0', 'status')
    assert_equal 'OPEN', successor.dig('gate_summary', 'g3', 'status')
    assert successor.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }
    assert successor.fetch('capabilities').all? do |row|
      row.dig('governance', 'implementation_authorized') == false
    end

    prior_capabilities = capability_index(predecessor)
    map_capabilities = capability_index(map)
    capability_index(successor).each do |id, row|
      if Generator::TARGET_CAPABILITY_IDS.include?(id)
        prior = prior_capabilities.fetch(id)
        %w[capability_id batch source_decision_pointer governance_decision_pointer governance].each do |key|
          expected = prior.fetch(key)
          expected.nil? ? assert_nil(row.fetch(key), "#{id}.#{key}") :
            assert_equal(expected, row.fetch(key), "#{id}.#{key}")
        end
        assert_equal map_capabilities.fetch(id).fetch('engineering_evidence'), row.fetch('engineering_evidence')
        assert_equal map_capabilities.fetch(id).fetch('workflow_observation'), row.fetch('workflow_observation')
      else
        assert_equal prior_capabilities.fetch(id), row, id
      end
    end
  end

  def parse(root, relative)
    Core.parse_json(File.binread(root.join(relative)), label: relative)
  end

  def capability_index(document)
    document.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
  end

  def workflow_index(document)
    document.fetch('workflows').to_h { |row| [row.fetch('workflow_id'), row] }
  end

end
