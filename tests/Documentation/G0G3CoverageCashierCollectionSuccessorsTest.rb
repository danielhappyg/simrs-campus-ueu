# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'pathname'
require 'securerandom'
require 'tmpdir'

require_relative '../../scripts/generate-g0-g3-coverage-cashier-collection-successors'

class G0G3CoverageCashierCollectionSuccessorsTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  Generator = G0G3CoverageCashierCollectionSuccessors
  Core = G0ProportionalGovernanceV2

  MAP_SHA256 = 'f8785833c7259a798b0185938e6e45c15c1ec3c7e8c7efe46b2d27038f9f84dc'
  LEDGER_SHA256 = 'aedb8ec542b9aeb8111d9877b3355f21d04f5f48adcd3572cdadb95fe5ed0452'
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
    @tmpdir = Pathname.new(Dir.mktmpdir('.g0-g3-cashier-collection-successors-', ROOT)).realpath
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
    FileUtils.remove_entry_secure(@tmpdir) if @tmpdir&.exist?
  end

  def test_r9_r11_are_exact_deterministic_create_only_successors
    assert_equal 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R8', Generator::MAP_PREDECESSOR_ARTIFACT_ID
    assert_equal 'COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-03-R9', Generator::MAP_ARTIFACT_ID
    assert_equal 'G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R10', Generator::LEDGER_PREDECESSOR_ARTIFACT_ID
    assert_equal 'G0-G3-COVERAGE-LEDGER-V2-2026-09-03-R11', Generator::LEDGER_ARTIFACT_ID
    assert_equal %w[PAR-FIN-012], Generator::TARGET_CAPABILITY_IDS
    assert_equal %w[E2E-14], Generator::TARGET_WORKFLOW_IDS
    assert_equal MAP_SHA256, Digest::SHA256.file(@root.join(Generator::MAP_OUTPUT_PATH)).hexdigest
    assert_equal LEDGER_SHA256, Digest::SHA256.file(@root.join(Generator::LEDGER_OUTPUT_PATH)).hexdigest
    assert_equal File.binread(@root.join(Generator::MAP_OUTPUT_PATH)), Generator.serialized_map(root: ROOT)
    assert_equal File.binread(@root.join(Generator::LEDGER_OUTPUT_PATH)), Generator.serialized_ledger(root: ROOT)
    assert Generator.check_map!(root: ROOT)
    assert Generator.check_ledger!(root: ROOT)
    assert Generator.publication_ready?(root: ROOT)
    assert_equal 0o644, File.stat(@root.join(Generator::MAP_OUTPUT_PATH)).mode & 0o777
    assert_equal 0o600, File.stat(@root.join(Generator::LEDGER_OUTPUT_PATH)).mode & 0o777
    assert_equal 1, File.stat(@root.join(Generator::MAP_OUTPUT_PATH)).nlink
    assert_equal 1, File.stat(@root.join(Generator::LEDGER_OUTPUT_PATH)).nlink
    assert_equal Generator.send(:map_predecessor_reference), @map.fetch('superseded_evidence_map')
    assert_equal Generator.send(:ledger_predecessor_reference), @ledger.dig('sources', 'superseded_ledger')
    assert_equal({ 'path' => Generator::MAP_OUTPUT_PATH, 'sha256' => MAP_SHA256 },
                 @ledger.dig('sources', 'engineering_evidence_map_v2'))
  end

  def test_projection_changes_only_par_fin_012_and_e2e14_evidence_paths
    prior_capabilities = capability_index(@map_predecessor)
    capability_index(@map).each do |id, row|
      prior = prior_capabilities.fetch(id)
      if id == 'PAR-FIN-012'
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
        assert_equal (prior.fetch('evidence_paths') + EVIDENCE_PATHS).uniq.sort, row.fetch('evidence_paths')
      else
        assert_equal prior, row, id
      end
    end
  end

  def test_ledger_preserves_every_authority_status_engine_acceptance_and_non_target_row
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
      unless id == 'PAR-FIN-012'
        assert_equal prior.fetch(id), row, id
        next
      end
      %w[capability_id batch source_decision_pointer governance_decision_pointer governance].each do |key|
        expected = prior.fetch(id).fetch(key)
        expected.nil? ? assert_nil(row.fetch(key), "#{id}.#{key}") : assert_equal(expected, row.fetch(key), "#{id}.#{key}")
      end
      assert_equal mapped.fetch(id).fetch('engineering_evidence'), row.fetch('engineering_evidence')
      assert_equal mapped.fetch(id).fetch('workflow_observation'), row.fetch('workflow_observation')
      assert_equal 'NOT_IMPLEMENTED', row.dig('engineering_evidence', 'runtime_availability')
      assert_equal 'NOT_RUN', row.dig('engineering_evidence', 'hosted_uat')
      assert_equal 'PENDING', row.dig('workflow_observation', 'status')
    end

    prior_workflows = workflow_index(@ledger_predecessor)
    mapped_workflows = workflow_index(@map)
    workflow_index(@ledger).each do |id, row|
      assert_equal(id == 'E2E-14' ? mapped_workflows.fetch(id) : prior_workflows.fetch(id), row, id)
    end
  end

  def test_closed_catalogue_and_exact_evidence_hash_modes_links_sources_and_open_claims
    inputs = @map.fetch('explicit_evidence_inputs').to_h { |row| [row.fetch('path'), row.fetch('sha256')] }
    EVIDENCE_PATHS.each do |relative|
      assert File.file?(@root.join(relative)), relative
      assert_equal Digest::SHA256.file(@root.join(relative)).hexdigest, inputs.fetch(relative)
    end
    %w[
      app/Http/Controllers/Finance/FinanceCashierCollectionController.php
      routes/web.php
      resources/js/components/finance/finance-cashier-collection-batch.tsx
      resources/js/pages/kasir/batch-penerimaan-kas/show.tsx
      tests/Feature/Finance/BillingHttpWorkflowTest.php
      tests/Feature/Finance/FinanceCashierCollectionHttpTest.php
      scripts/rehearse-local-append-only-cashier-collection-portability.rb
      docs/operations/T1_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_EVIDENCE_2026-09-03.md
    ].each { |path| assert_includes EVIDENCE_PATHS, path }

    EXACT_EVIDENCE.each do |relative, sha|
      assert_equal sha, Digest::SHA256.file(@root.join(relative)).hexdigest
      expected_mode = relative == Generator::FINAL_EVIDENCE_PATH ? 0o644 : 0o600
      assert_equal expected_mode, File.stat(@root.join(relative)).mode & 0o777
      assert_equal 1, File.stat(@root.join(relative)).nlink
    end

    [Generator::POSTGRES_ARTIFACT_PATH, Generator::MYSQL_ARTIFACT_PATH].each do |relative|
      artifact = JSON.parse(File.binread(@root.join(relative)))
      assert_equal 'SIMRS_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_PORTABILITY', artifact.fetch('kind')
      assert_equal 'PASS', artifact.fetch('status')
      assert_equal Generator::EXACT_APPLICATION_SOURCE_SHA256,
                   artifact.dig('source_bindings', 'application_source_sha256')
      assert_equal 22, artifact.fetch('scenarios').length
      assert artifact.fetch('scenarios').values.all? { |row| row.fetch('status') == 'PASS' }
      assert_equal 'PASS', artifact.dig('sqlite_gate', 'status')
      assert_equal true, artifact.dig('cleanup', 'strict_cleanup_verified')
      %w[owner_acceptance_claim deployment_claim hosted_readiness_claim g0_claim g3_claim].each do |claim|
        assert_equal false, artifact.fetch(claim)
      end
      artifact.dig('source_bindings', 'files').each do |path, sha|
        assert_equal sha, Digest::SHA256.file(@root.join(path)).hexdigest, path
      end
    end
    record = File.binread(@root.join(Generator::FINAL_EVIDENCE_PATH))
    assert_includes record, Generator::EXACT_APPLICATION_SOURCE_SHA256
    assert_includes record, Generator::POSTGRES_ARTIFACT_SHA256
    assert_includes record, Generator::MYSQL_ARTIFACT_SHA256
    assert_includes record, 'G0 and G3 remain **OPEN**'
  end

  def test_create_only_refuses_overwrite_traversal_symlink_and_hardlink_outputs
    map_output = relative(@tmpdir.join('map.json'))
    receipt = Generator.write_map!(root: ROOT, output: map_output)
    bytes = File.binread(@root.join(map_output))
    assert_equal Digest::SHA256.hexdigest(bytes), receipt.fetch('sha256')
    assert_raises(Generator::UsageError) { Generator.write_map!(root: ROOT, output: map_output) }
    assert_equal bytes, File.binread(@root.join(map_output))
    assert_raises(Generator::UsageError) { Generator.write_map!(root: ROOT, output: '../outside.json') }

    symlink_output = @tmpdir.join('symlink.json')
    File.symlink(@root.join(Generator::MAP_PREDECESSOR_PATH), symlink_output)
    assert_raises(Generator::UsageError) { Generator.write_map!(root: ROOT, output: relative(symlink_output)) }
    hardlink_output = @tmpdir.join('hardlink.json')
    File.link(@root.join(Generator::MAP_PREDECESSOR_PATH), hardlink_output)
    assert_raises(Generator::Error) { Generator.write_map!(root: ROOT, output: relative(hardlink_output)) }
  end

  def test_predecessor_and_exact_source_claim_drift_fail_closed
    fixture = build_fixture
    File.open(fixture.join(Generator::MAP_PREDECESSOR_PATH), 'ab') { |file| file.write("drift\n") }
    assert_raises(Generator::Error) { Generator.map_document(root: fixture) }

    fixture = build_fixture
    bound_source = 'app/Support/Finance/FinanceCashierCollectionService.php'
    File.open(fixture.join(bound_source), 'ab') { |file| file.write("drift\n") }
    refute Generator.publication_ready?(root: fixture)
    assert_raises(Generator::Error) { Generator.map_document(root: fixture) }
  end

  def test_readme_designates_r9_r11_current_without_elevating_governance
    readme = File.binread(@root.join('docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md'))

    assert_includes readme, File.basename(Generator::MAP_OUTPUT_PATH)
    assert_includes readme, File.basename(Generator::LEDGER_OUTPUT_PATH)
    assert_includes readme, MAP_SHA256
    assert_includes readme, LEDGER_SHA256
    assert_includes readme, 'current append-only local engineering-evidence map'
    assert_includes readme, 'current schema-v2 local observation'
    assert_includes readme, 'PAR-FIN-012 remains `NOT_IMPLEMENTED`'
    assert_includes readme, '`pointer_missing`'
    assert_includes readme, 'G0 and G3 remain `OPEN`'
    assert_includes readme, 'generate-g0-g3-coverage-cashier-collection-successors.rb --kind map --check'
    assert_includes readme, 'G0G3CoverageCashierCollectionSuccessorsTest.rb'
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

  def relative(path)
    path.relative_path_from(@root).to_s
  end

  def build_fixture
    root = @tmpdir.join("fixture-#{SecureRandom.hex(4)}")
    root.mkpath
    ([Generator::GENERATOR_PATH, Generator::MAP_PREDECESSOR_PATH, Generator::LEDGER_PREDECESSOR_PATH] +
      EVIDENCE_PATHS).uniq.each do |relative|
      target = root.join(relative)
      FileUtils.mkdir_p(target.parent)
      FileUtils.cp(@root.join(relative), target)
    end
    root.realpath
  end
end
