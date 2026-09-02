# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'
require 'pathname'

require_relative '../../scripts/generate-g0-g3-coverage-settlement-successors'

class G0G3CoverageSettlementSuccessorsTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  Generator = G0G3CoverageSettlementSuccessors
  Core = G0ProportionalGovernanceV2

  MAP_SHA256 = '45194af7aaabfda54069e4c22a420340f7b4940751d75485a56f34787e94c7fc'
  LEDGER_SHA256 = '290f60ab0ab060a6fd58920fd53313bade83b0c0584a5dd6de4a24a64d951654'
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
    @immutable_bytes = IMMUTABLE_PATHS.to_h { |path, _sha| [path, File.binread(@root.join(path))] }
    @output_bytes = OUTPUT_PATHS.to_h { |path, _sha| [path, File.binread(@root.join(path))] }
    @map_predecessor = parse(Generator::MAP_PREDECESSOR_PATH)
    @ledger_predecessor = parse(Generator::LEDGER_PREDECESSOR_PATH)
    @map = parse(Generator::MAP_OUTPUT_PATH)
    @ledger = parse(Generator::LEDGER_OUTPUT_PATH)
  end

  def teardown
    @immutable_bytes.each do |relative, bytes|
      assert_equal bytes, File.binread(@root.join(relative)), "must preserve #{relative} byte-for-byte"
      assert_equal IMMUTABLE_PATHS.fetch(relative), Digest::SHA256.file(@root.join(relative)).hexdigest
    end
    @output_bytes.each do |relative, bytes|
      assert_equal bytes, File.binread(@root.join(relative)), "must preserve #{relative} byte-for-byte"
      assert_equal OUTPUT_PATHS.fetch(relative), Digest::SHA256.file(@root.join(relative)).hexdigest
    end
  end

  def test_r7_r9_remain_exact_immutable_historical_successors
    assert_equal 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R6',
                 Generator::MAP_PREDECESSOR_ARTIFACT_ID
    assert_equal 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R7', Generator::MAP_ARTIFACT_ID
    assert_equal 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R8', Generator::LEDGER_PREDECESSOR_ARTIFACT_ID
    assert_equal 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R9', Generator::LEDGER_ARTIFACT_ID
    assert_equal %w[PAR-FIN-001 PAR-FIN-002], Generator::TARGET_CAPABILITY_IDS
    assert_equal %w[E2E-14], Generator::TARGET_WORKFLOW_IDS

    assert_equal MAP_SHA256, Digest::SHA256.file(@root.join(Generator::MAP_OUTPUT_PATH)).hexdigest
    assert_equal LEDGER_SHA256, Digest::SHA256.file(@root.join(Generator::LEDGER_OUTPUT_PATH)).hexdigest
    assert_equal 0o644, File.stat(@root.join(Generator::MAP_OUTPUT_PATH)).mode & 0o777
    assert_equal 0o600, File.stat(@root.join(Generator::LEDGER_OUTPUT_PATH)).mode & 0o777
    assert_equal 1, File.stat(@root.join(Generator::MAP_OUTPUT_PATH)).nlink
    assert_equal 1, File.stat(@root.join(Generator::LEDGER_OUTPUT_PATH)).nlink
    assert_equal Generator.send(:map_predecessor_reference), @map.fetch('superseded_evidence_map')
    assert_equal Generator.send(:ledger_predecessor_reference), @ledger.dig('sources', 'superseded_ledger')
    assert_equal({ 'path' => Generator::MAP_OUTPUT_PATH, 'sha256' => MAP_SHA256 },
                 @ledger.dig('sources', 'engineering_evidence_map_v2'))
  end

  def test_projection_changes_only_two_finance_rows_and_e2e14_evidence_paths
    prior_capabilities = capability_index(@map_predecessor)
    capability_index(@map).each do |id, row|
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

    prior_workflows = workflow_index(@map_predecessor)
    workflow_index(@map).each do |id, row|
      prior = prior_workflows.fetch(id)
      if id == 'E2E-14'
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

  def test_ledger_preserves_all_governance_gate_authority_and_non_target_rows
    %w[
      artifact_type schema_version data_boundary authority_boundary governance_profile_binding
      capability_summary workflow_summary gate_summary
    ].each { |key| assert_equal @ledger_predecessor.fetch(key), @ledger.fetch(key), key }
    assert_equal 'pointer_missing', @ledger.dig('governance_profile_binding', 'reason_code')
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g0', 'status')
    assert_equal 'OPEN', @ledger.dig('gate_summary', 'g3', 'status')
    assert @ledger.fetch('capabilities').all? { |row| row.fetch('governance_decision_pointer').nil? }
    assert @ledger.fetch('capabilities').all? { |row| row.dig('governance', 'implementation_authorized') == false }

    prior = capability_index(@ledger_predecessor)
    mapped = capability_index(@map)
    capability_index(@ledger).each do |id, row|
      unless Generator::TARGET_CAPABILITY_IDS.include?(id)
        assert_equal prior.fetch(id), row, id
        next
      end
      %w[capability_id batch source_decision_pointer governance_decision_pointer governance].each do |key|
        if prior.fetch(id).fetch(key).nil?
          assert_nil row.fetch(key), "#{id}.#{key}"
        else
          assert_equal prior.fetch(id).fetch(key), row.fetch(key), "#{id}.#{key}"
        end
      end
      assert_equal mapped.fetch(id).fetch('engineering_evidence'), row.fetch('engineering_evidence')
      assert_equal mapped.fetch(id).fetch('workflow_observation'), row.fetch('workflow_observation')
    end

    prior_workflows = workflow_index(@ledger_predecessor)
    workflow_index(@ledger).each do |id, row|
      expected = id == 'E2E-14' ? workflow_index(@map).fetch(id) : prior_workflows.fetch(id)
      assert_equal expected, row, id
    end
  end

  def test_historical_settlement_evidence_hashes_modes_and_artifact_claims_remain_bound
    assert_equal Generator::BASE_EVIDENCE_PATHS.sort, Generator::BASE_EVIDENCE_PATHS
    assert_equal Generator::BASE_EVIDENCE_PATHS.uniq, Generator::BASE_EVIDENCE_PATHS
    inputs = @map.fetch('explicit_evidence_inputs').to_h { |row| [row.fetch('path'), row.fetch('sha256')] }
    EVIDENCE_PATHS.each do |relative|
      assert_match(/\A[0-9a-f]{64}\z/, inputs.fetch(relative), relative)
    end
    EXACT_EVIDENCE.each do |relative, sha|
      assert_equal sha, Digest::SHA256.file(@root.join(relative)).hexdigest
      expected_mode = relative == Generator::FINAL_EVIDENCE_PATH ? 0o644 : 0o600
      assert_equal expected_mode, File.stat(@root.join(relative)).mode & 0o777
    end
    [Generator::POSTGRES_ARTIFACT_PATH, Generator::MYSQL_ARTIFACT_PATH].each do |relative|
      artifact = JSON.parse(File.binread(@root.join(relative)))
      assert_equal 'SIMRS_LOCAL_EXACT_CASH_SETTLEMENT_PORTABILITY', artifact.fetch('kind')
      assert_equal 'PASS', artifact.fetch('status')
      assert_equal Generator::EXACT_APPLICATION_SOURCE_SHA256,
                   artifact.dig('source_bindings', 'application_source_sha256')
      assert_equal 17, artifact.fetch('scenarios').length
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

  def test_readme_retains_r7_r9_as_immutable_predecessors_and_keeps_gates_open
    readme = File.binread(@root.join('docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md'))
    assert_includes readme, File.basename(Generator::MAP_OUTPUT_PATH)
    assert_includes readme, File.basename(Generator::LEDGER_OUTPUT_PATH)
    assert_includes readme, MAP_SHA256
    assert_includes readme, LEDGER_SHA256
    assert_match(/R7.*immutable/m, readme)
    assert_match(/R9.*immutable/m, readme)
    assert_includes readme, '`pointer_missing`'
    assert_includes readme, 'G0 and G3 remain `OPEN`'
  end

  private

  def parse(relative)
    Core.parse_json(File.binread(@root.join(relative)), label: relative)
  end

  def capability_index(document)
    document.fetch('capabilities').to_h { |row| [row.fetch('capability_id'), row] }
  end

  def workflow_index(document)
    document.fetch('workflows').to_h { |row| [row.fetch('workflow_id'), row] }
  end

end
