# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'open3'
require 'pathname'
require 'tmpdir'

require_relative '../../scripts/generate-g0-g3-coverage-evidence-map-v2'

class G0G3CoverageEvidenceMapV2Test < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/generate-g0-g3-coverage-evidence-map-v2.rb')
  DATED_OUTPUT_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json'
  DATED_OUTPUT_SHA256 = '3b1005a31087896b412a8f64a3dbfced2c0ab655be78c32abd7c0743ae3811d5'
  HISTORICAL_MAP_PATH = 'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json'
  ORDER_SOURCE_PATH = 'docs/new-simrs-rebuild/phase-0/G0_PARITY_BATCH_MANIFEST.json'
  Generator = G0G3CoverageEvidenceMapV2
  Core = G0ProportionalGovernanceV2

  HISTORICAL_SOURCE_HASHES = {
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-26.json' =>
      '94d96ae65143c3636a83bac8dee6c4ac887483dd7d10a904ed161b9fc3a38865',
    HISTORICAL_MAP_PATH =>
      '3cfcc9898f4b541cea85cd448638fc300cae48eb5172c3dffd598925d9244ea0',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-26.json' =>
      '91162e60df901b0635f72e73bea5ca62d8f36e916c20c36ca2af33d9af90936f',
    'docs/new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_2026-08-27.json' =>
      'd043e4ce3d2893bd19f543a971561b57964926a385969f0a2e7266e943196cbf'
  }.freeze

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version artifact_id snapshot_date data_boundary
    source_evidence_map canonical_order_source explicit_evidence_inputs
    capability_defaults workflow_observation_default capabilities workflows provenance
  ].freeze
  SOURCE_KEYS = %w[path sha256].freeze
  CAPABILITY_KEYS = %w[capability_id engineering_evidence workflow_observation].freeze
  ENGINEERING_KEYS = %w[
    runtime_availability automated_evidence database_engine_evidence hosted_uat
    reconciliation defect_status evidence_paths
  ].freeze
  DATABASE_ENGINE_KEYS = %w[sqlite postgresql_17 mysql_8_4 mysql_other].freeze
  WORKFLOW_OBSERVATION_KEYS = %w[status scenario_ids].freeze
  WORKFLOW_KEYS = (%w[workflow_id] + ENGINEERING_KEYS).freeze
  PROVENANCE_KEYS = %w[
    generator source_byte_hash canonical_json capability_count
    historical_engineering_override_count workflow_count explicit_evidence_input_count
  ].freeze

  # Normalize punctuation, whitespace, separators, and case before matching so
  # nested aliases such as "Owner-Approval", "G 0", or "consumerPointer"
  # cannot bypass the engineering-only boundary.
  FORBIDDEN_KEY_TOKENS = %w[
    governance owner approver approval signoff decision verdict outcome
    disposition consequence riskclass risklevel tier reviewer controlreviewer
    pointer selector selection consumerbinding gate authority acceptance accepted
    authorization role g0 g3
  ].freeze

  def setup
    @tmpdir = Dir.mktmpdir('.g0-g3-evidence-v2-', ROOT)
    @historical = Core.parse_json_file(File.join(ROOT, HISTORICAL_MAP_PATH))
    @order_source = Core.parse_json_file(File.join(ROOT, ORDER_SOURCE_PATH))
    @inputs = explicit_inputs_from_historical_map
  end

  def teardown
    FileUtils.remove_entry(@tmpdir) if File.exist?(@tmpdir)
  end

  def test_two_clean_runs_are_byte_identical_closed_and_preserve_historical_sources
    before = historical_source_bytes
    first = File.join(@tmpdir, 'first.json')
    second = File.join(@tmpdir, 'second.json')

    first_receipt = generate(first)
    second_receipt = generate(second)

    assert_equal File.binread(first), File.binread(second)
    assert_equal Digest::SHA256.file(first).hexdigest, first_receipt.fetch('sha256')
    assert_equal first_receipt.fetch('sha256'), second_receipt.fetch('sha256')
    assert File.binread(first).end_with?("\n")
    refute File.binread(first).end_with?("\n\n")
    assert_equal before, historical_source_bytes
    assert_historical_hashes
    assert_v1_manifest_hashes

    document = Core.parse_json_file(first)
    assert_equal TOP_LEVEL_KEYS.sort, document.keys.sort
    assert_equal 'g0_g3_coverage_evidence_map_v2', document.fetch('artifact_type')
    assert_equal 2, document.fetch('schema_version')
    assert_equal 'synthetic_only', document.fetch('data_boundary')
    assert_no_forbidden_keys(document)
  end

  def test_dated_artifact_is_exactly_a_clean_deterministic_rerun
    dated = File.join(ROOT, DATED_OUTPUT_PATH)
    assert File.file?(dated)
    assert_equal DATED_OUTPUT_SHA256, Digest::SHA256.file(dated).hexdigest
    Generator.validate_generated_document!(Core.parse_json_file(dated), root: ROOT)

    rerun = File.join(@tmpdir, 'dated-rerun.json')
    receipt = generate(rerun)
    assert_equal DATED_OUTPUT_SHA256, receipt.fetch('sha256')
    assert_equal File.binread(dated), File.binread(rerun)
  end

  def test_exact_source_hashes_explicit_inputs_and_canonical_268_order_are_bound
    output = File.join(@tmpdir, 'map.json')
    generate(output)
    document = Core.parse_json_file(output)

    assert_equal SOURCE_KEYS.sort, document.fetch('source_evidence_map').keys.sort
    assert_equal HISTORICAL_MAP_PATH, document.dig('source_evidence_map', 'path')
    assert_equal HISTORICAL_SOURCE_HASHES.fetch(HISTORICAL_MAP_PATH),
                 document.dig('source_evidence_map', 'sha256')
    assert_equal SOURCE_KEYS.sort, document.fetch('canonical_order_source').keys.sort
    assert_equal ORDER_SOURCE_PATH, document.dig('canonical_order_source', 'path')
    assert_equal Digest::SHA256.file(File.join(ROOT, ORDER_SOURCE_PATH)).hexdigest,
                 document.dig('canonical_order_source', 'sha256')

    expected_inputs = @inputs.map { |entry| entry.slice('path', 'sha256') }
    assert_equal expected_inputs, document.fetch('explicit_evidence_inputs')
    assert_equal expected_inputs.map { |entry| entry.fetch('path') }.sort,
                 expected_inputs.map { |entry| entry.fetch('path') }
    document.fetch('explicit_evidence_inputs').each_with_index do |entry, index|
      assert_equal SOURCE_KEYS.sort, entry.keys.sort, index
      assert_equal Digest::SHA256.file(File.join(ROOT, entry.fetch('path'))).hexdigest,
                   entry.fetch('sha256'), entry.fetch('path')
    end

    expected_ids = ('A'..'G').flat_map { |batch| @order_source.fetch('batches').fetch(batch) }
    capabilities = document.fetch('capabilities')
    assert_equal 268, capabilities.length
    assert_equal expected_ids, capabilities.map { |entry| entry.fetch('capability_id') }
    assert_equal 268, capabilities.map { |entry| entry.fetch('capability_id') }.uniq.length
    assert_equal 14, capabilities.count { |entry| entry.dig('workflow_observation', 'status') == 'PROVISIONAL' }
  end

  def test_every_engineering_object_has_an_exact_closed_schema_and_no_authority_dimension
    output = File.join(@tmpdir, 'map.json')
    generate(output)
    document = Core.parse_json_file(output)

    document.fetch('capabilities').each_with_index do |capability, index|
      assert_equal CAPABILITY_KEYS.sort, capability.keys.sort, index
      assert_engineering_schema(capability.fetch('engineering_evidence'), "capabilities[#{index}]")
      assert_equal WORKFLOW_OBSERVATION_KEYS.sort, capability.fetch('workflow_observation').keys.sort, index
      assert capability.dig('workflow_observation', 'status').match?(/\A(?:PENDING|PROVISIONAL)\z/)
      assert capability.dig('workflow_observation', 'scenario_ids').is_a?(Array)
    end
    assert_engineering_schema(document.fetch('capability_defaults'), 'capability_defaults')
    assert_equal WORKFLOW_OBSERVATION_KEYS.sort, document.fetch('workflow_observation_default').keys.sort
    assert_equal (1..16).map { |index| format('E2E-%02d', index) },
                 document.fetch('workflows').map { |workflow| workflow.fetch('workflow_id') }
    document.fetch('workflows').each_with_index do |workflow, index|
      assert_equal WORKFLOW_KEYS.sort, workflow.keys.sort, index
      assert_engineering_schema(workflow.slice(*ENGINEERING_KEYS), "workflows[#{index}]")
    end
    provenance = document.fetch('provenance')
    assert_equal PROVENANCE_KEYS.sort, provenance.keys.sort
    assert_equal SOURCE_KEYS.sort, provenance.fetch('generator').keys.sort
    assert_equal Generator::GENERATOR_PATH, provenance.dig('generator', 'path')
    assert_equal Digest::SHA256.file(SCRIPT).hexdigest, provenance.dig('generator', 'sha256')
    assert_equal 'sha256_raw_bytes', provenance.fetch('source_byte_hash')
    assert_equal 'utf8_sorted_object_keys_compact_single_lf', provenance.fetch('canonical_json')
    assert_equal 268, provenance.fetch('capability_count')
    assert_equal 14, provenance.fetch('historical_engineering_override_count')
    assert_equal 16, provenance.fetch('workflow_count')
    assert_equal @inputs.length, provenance.fetch('explicit_evidence_input_count')
    assert_no_forbidden_keys(document)
  end

  def test_recursive_forbidden_governance_field_injections_are_rejected_in_disguised_forms
    output = File.join(@tmpdir, 'map.json')
    generate(output)
    baseline = Core.parse_json_file(output)
    aliases = {
      'governance' => 'GovernanceState',
      'owner' => 'service OWNER name',
      'approver' => 'domainApprover',
      'approval' => 'owner-approval',
      'signoff' => 'clinical Sign_Off',
      'decision' => 'DECISION.reference',
      'verdict' => 'releaseVerdict',
      'outcome' => 'business_outcome',
      'disposition' => 'canonicalDisposition',
      'consequence' => 'consequence_map',
      'risk class' => 'riskClass',
      'risk level' => 'risk-level',
      'tier' => 'risk TIER',
      'reviewer' => 'independentReviewer',
      'control reviewer' => 'control-reviewer',
      'pointer' => 'consumer-pointer',
      'selector' => 'activeSelector',
      'selection' => 'activeSelection',
      'consumer binding' => 'consumerBinding',
      'gate' => 'release_gate',
      'authority' => 'domainAuthority',
      'acceptance' => 'ownerAcceptance',
      'accepted' => 'acceptedAt',
      'authorization' => 'implementationAuthorization',
      'role' => 'serviceRole',
      'g0' => 'G-0 status',
      'g3' => 'G 3 status'
    }

    aliases.each_with_index do |(token, key), index|
      changed = deep_copy(baseline)
      changed.fetch('capabilities').fetch(index).fetch('engineering_evidence')['nested'] = {
        'array' => [{ key => 'must-not-be-emitted' }]
      }
      error = assert_raises(Generator::Error, token) do
        Generator.validate_generated_document!(changed, root: ROOT)
      end
      assert_match(/forbidden governance-capable field/, error.message, token)
      refute_includes error.message, 'must-not-be-emitted'
    end
  end

  def test_unknown_missing_wrong_type_duplicate_and_semantic_promotions_fail_closed
    output = File.join(@tmpdir, 'map.json')
    generate(output)
    baseline = Core.parse_json_file(output)
    mutations = {
      'unknown top-level field' => ->(copy) { copy['unexpected'] = true },
      'missing top-level field' => ->(copy) { copy.delete('data_boundary') },
      'wrong schema version type' => ->(copy) { copy['schema_version'] = '2' },
      'missing capability' => ->(copy) { copy['capabilities'].pop },
      'duplicate capability' => ->(copy) { copy['capabilities'][1]['capability_id'] = copy['capabilities'][0]['capability_id'] },
      'reordered capability' => ->(copy) { copy['capabilities'][0], copy['capabilities'][1] = copy['capabilities'][1], copy['capabilities'][0] },
      'unknown engineering field' => ->(copy) { copy['capabilities'][0]['engineering_evidence']['invented'] = true },
      'promoted workflow status' => ->(copy) { copy['capabilities'][0]['workflow_observation']['status'] = 'APPROVED' },
      'unknown workflow field' => ->(copy) { copy['workflows'][0]['invented'] = true },
      'unbound extra input' => ->(copy) { copy['explicit_evidence_inputs'] << { 'path' => 'invented', 'sha256' => '0' * 64 } }
    }

    mutations.each do |name, mutation|
      changed = deep_copy(baseline)
      mutation.call(changed)
      assert_raises(Generator::Error, name) do
        Generator.validate_generated_document!(changed, root: ROOT)
      end
    end

    duplicate = File.join(@tmpdir, 'duplicate.json')
    File.binwrite(duplicate, %({"schema_version":2,"schema_version":2}\n))
    assert_raises(Core::ParseError) { Core.parse_json_file(duplicate) }
  end

  def test_unsafe_symlinked_non_regular_missing_and_stale_evidence_are_rejected_before_output
    assert_generation_failure('../outside', Generator::UsageError)
    assert_generation_failure('/absolute', Generator::UsageError)
    assert_generation_failure('missing-evidence.txt', Generator::Error)

    fixture_root = build_fixture_root('fixture-root')
    input = @inputs.first
    source = File.join(fixture_root, input.fetch('path'))
    File.unlink(source)
    File.symlink(File.join(ROOT, input.fetch('path')), source)
    linked_output = File.join(fixture_root, 'symlink-source.json')
    assert_raises(Generator::Error) do
      Generator.generate!(root: fixture_root, output: linked_output, current_evidence_paths: [])
    end
    refute File.exist?(linked_output)

    File.unlink(source)
    Dir.mkdir(source)
    directory_output = File.join(fixture_root, 'directory-source.json')
    assert_raises(Generator::Error) do
      Generator.generate!(root: fixture_root, output: directory_output, current_evidence_paths: [])
    end
    refute File.exist?(directory_output)

    output_parent = File.join(@tmpdir, 'output-parent')
    linked_parent = File.join(@tmpdir, 'linked-parent')
    Dir.mkdir(output_parent)
    File.symlink(output_parent, linked_parent)
    assert_raises(Generator::UsageError) do
      Generator.generate!(root: ROOT, output: File.join(linked_parent, 'map.json'), current_evidence_paths: [])
    end
  end

  def test_stale_future_invalid_date_and_toctou_swaps_fail_before_publication
    stale_root = build_fixture_root('stale-root')
    stale_output = File.join(stale_root, 'baseline.json')
    Generator.generate!(root: stale_root, output: stale_output, current_evidence_paths: [])
    stale_document = Core.parse_json_file(stale_output)
    stale_source = File.join(stale_root, @inputs.first.fetch('path'))
    File.open(stale_source, 'ab') { |file| file.write("stale\n") }
    assert_raises(Generator::Error) do
      Generator.validate_generated_document!(stale_document, root: stale_root)
    end

    date_root = build_fixture_root('date-root')
    future_path = 'docs/operations/WAVE3_CURRENT_EVIDENCE_2026-08-30.md'
    invalid_date_path = 'docs/operations/WAVE3_CURRENT_EVIDENCE_2026-02-30.md'
    [future_path, invalid_date_path].each do |relative|
      absolute = File.join(date_root, relative)
      FileUtils.mkdir_p(File.dirname(absolute))
      File.binwrite(absolute, "bounded engineering evidence\n")
      output = File.join(date_root, "#{File.basename(relative)}.json")
      assert_raises(Generator::Error, relative) do
        Generator.generate!(root: date_root, output: output, current_evidence_paths: [relative])
      end
      refute File.exist?(output)
    end

    swap_root = build_fixture_root('swap-root')
    swapped = false
    relative = @inputs.first.fetch('path')
    source = File.join(swap_root, relative)
    hook = lambda do |read_relative|
      next unless read_relative == relative && !swapped

      File.unlink(source)
      File.symlink(File.join(ROOT, relative), source)
      swapped = true
    end
    swap_output = File.join(swap_root, 'toctou.json')
    assert_raises(Generator::Error) do
      Generator.generate!(
        root: swap_root,
        output: swap_output,
        current_evidence_paths: [],
        source_read_hook: hook
      )
    end
    assert swapped
    refute File.exist?(swap_output)
  end

  def test_only_explicit_current_evidence_is_added_and_bound_by_exact_raw_byte_hash
    fixture_root = build_fixture_root('explicit-root')
    explicit_path = 'docs/operations/WAVE3_CURRENT_EVIDENCE_2026-08-29.md'
    unlisted_path = 'docs/operations/WAVE3_UNLISTED_EVIDENCE_2026-08-29.md'
    explicit_bytes = "explicit bounded engineering evidence\n"
    [explicit_path, unlisted_path].each do |relative|
      absolute = File.join(fixture_root, relative)
      FileUtils.mkdir_p(File.dirname(absolute))
      File.binwrite(absolute, relative == explicit_path ? explicit_bytes : "must not be discovered\n")
    end

    output = File.join(fixture_root, 'explicit.json')
    Generator.generate!(
      root: fixture_root,
      output: output,
      current_evidence_paths: [explicit_path]
    )
    document = Core.parse_json_file(output)
    inputs = document.fetch('explicit_evidence_inputs')
    explicit = inputs.find { |entry| entry.fetch('path') == explicit_path }
    refute_nil explicit
    assert_equal Digest::SHA256.hexdigest(explicit_bytes), explicit.fetch('sha256')
    refute inputs.any? { |entry| entry.fetch('path') == unlisted_path }
    assert_equal @inputs.length + 1, inputs.length
  end

  def test_nested_secret_rejection_reports_location_without_value_echo_and_writes_nothing
    sentinel = 'DO_NOT_ECHO_WAVE3_SECRET_84f1'
    output = File.join(@tmpdir, 'baseline.json')
    generate(output)
    changed = Core.parse_json_file(output)
    changed.fetch('capabilities').first.fetch('engineering_evidence')['metadata'] = [
      { "password=#{sentinel}" => 'hidden' }
    ]
    error = assert_raises(Generator::Error) do
      Generator.validate_generated_document!(changed, root: ROOT)
    end
    assert_match(/secret-like content/i, error.message)
    refute_includes error.message, sentinel

    secret_output = File.join(@tmpdir, 'secret-cli.json')
    stdout, stderr, status = Open3.capture3(
      RbConfig.ruby, SCRIPT, '--output', secret_output, '--evidence', sentinel,
      chdir: ROOT
    )
    assert_equal 1, status.exitstatus
    refute_includes stdout, sentinel
    refute_includes stderr, sentinel
    refute File.exist?(secret_output)
  end

  def test_existing_output_and_injected_prepublication_failure_never_leave_usable_output
    output = File.join(@tmpdir, 'existing.json')
    File.binwrite(output, 'caller-owned')
    assert_raises(Generator::UsageError) { generate(output) }
    assert_equal 'caller-owned', File.binread(output)

    faulted = File.join(@tmpdir, 'faulted.json')
    assert_raises(Generator::Error) do
      Generator.generate!(
        root: ROOT,
        output: faulted,
        current_evidence_paths: [],
        fault_after: :after_stage_validation
      )
    end
    refute File.exist?(faulted)
    refute Dir.children(@tmpdir).any? { |name| name.start_with?('.faulted.json.stage-') }
  end

  def test_cli_requires_explicit_inputs_and_has_stable_exit_classes_without_secret_echo
    _stdout, stderr, status = Open3.capture3(RbConfig.ruby, SCRIPT, chdir: ROOT)
    assert_equal 2, status.exitstatus
    assert_match(/Usage:/, stderr)

    duplicate_a = File.join(@tmpdir, 'duplicate-a.json')
    duplicate_b = File.join(@tmpdir, 'duplicate-b.json')
    _stdout, _stderr, status = Open3.capture3(
      RbConfig.ruby, SCRIPT, '--output', duplicate_a, '--output', duplicate_b,
      chdir: ROOT
    )
    assert_equal 2, status.exitstatus
    refute File.exist?(duplicate_a)
    refute File.exist?(duplicate_b)

    output = File.join(@tmpdir, 'cli.json')
    args = ['--output', output]
    stdout, stderr, status = Open3.capture3(RbConfig.ruby, SCRIPT, *args, chdir: ROOT)
    assert_equal 0, status.exitstatus
    assert File.file?(output)
    assert_match(/"status":"generated_engineering_evidence_map"/, stdout)
    assert_empty stderr

    stale_output = File.join(@tmpdir, 'stale.json')
    stale_args = ['--output', stale_output, '--evidence', 'missing-evidence-path']
    _stdout, stderr, status = Open3.capture3(RbConfig.ruby, SCRIPT, *stale_args, chdir: ROOT)
    assert_equal 1, status.exitstatus
    assert_match(/generation failed/, stderr)
    refute File.exist?(stale_output)
  end

  private

  def generate(output)
    Generator.generate!(root: ROOT, output: output, current_evidence_paths: [])
  end

  def explicit_inputs_from_historical_map
    paths = []
    paths.concat(@historical.fetch('capability_defaults').fetch('evidence_paths'))
    @historical.fetch('capability_overrides').each_value do |entry|
      paths.concat(entry.fetch('engineering_evidence').fetch('evidence_paths'))
    end
    @historical.fetch('workflows').each { |entry| paths.concat(entry.fetch('evidence_paths')) }
    paths.uniq.sort.map do |path|
      { 'path' => path, 'sha256' => Digest::SHA256.file(File.join(ROOT, path)).hexdigest }
    end
  end

  def historical_source_bytes
    paths = (HISTORICAL_SOURCE_HASHES.keys + v1_manifest_paths).uniq
    paths.to_h { |path| [path, File.binread(File.join(ROOT, path))] }
  end

  def assert_historical_hashes
    HISTORICAL_SOURCE_HASHES.each do |path, expected|
      assert_equal expected, Digest::SHA256.file(File.join(ROOT, path)).hexdigest, path
    end
  end

  def v1_manifest_paths
    manifest = Core.parse_json_file(
      File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json')
    )
    manifest.fetch('files').map { |entry| entry.fetch('path') }
  end

  def assert_v1_manifest_hashes
    manifest = Core.parse_json_file(
      File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json')
    )
    assert_equal 30, manifest.fetch('files').length
    manifest.fetch('files').each do |entry|
      path = entry.fetch('path')
      assert_equal entry.fetch('sha256'), Digest::SHA256.file(File.join(ROOT, path)).hexdigest, path
    end
  end

  def build_fixture_root(name)
    fixture_root = File.join(@tmpdir, name)
    relative_script = SCRIPT.delete_prefix("#{ROOT}/")
    paths = ([HISTORICAL_MAP_PATH, ORDER_SOURCE_PATH, relative_script] + @inputs.map { |entry| entry.fetch('path') }).uniq
    paths.each do |path|
      destination = File.join(fixture_root, path)
      FileUtils.mkdir_p(File.dirname(destination))
      FileUtils.cp(File.join(ROOT, path), destination)
    end
    fixture_root
  end

  def assert_engineering_schema(value, label)
    assert_equal ENGINEERING_KEYS.sort, value.keys.sort, label
    assert_equal DATABASE_ENGINE_KEYS.sort, value.fetch('database_engine_evidence').keys.sort, label
    assert value.fetch('evidence_paths').is_a?(Array), label
  end

  def assert_no_forbidden_keys(value, path = '$')
    case value
    when Hash
      value.each do |key, child|
        normalized = key.to_s.downcase.gsub(/[^a-z0-9]/, '')
        token = FORBIDDEN_KEY_TOKENS.find { |candidate| normalized.include?(candidate) }
        refute token, "#{path}.#{key}: forbidden engineering-map field token #{token}"
        assert_no_forbidden_keys(child, "#{path}.#{key}")
      end
    when Array
      value.each_with_index { |child, index| assert_no_forbidden_keys(child, "#{path}[#{index}]") }
    end
  end

  def assert_generation_failure(path, error_class)
    output = File.join(@tmpdir, "rejected-#{Dir.children(@tmpdir).length}.json")
    assert_raises(error_class) do
      Generator.generate!(root: ROOT, output: output, current_evidence_paths: [path])
    end
    refute File.exist?(output)
  end

  def deep_copy(value)
    JSON.parse(JSON.generate(value))
  end
end
