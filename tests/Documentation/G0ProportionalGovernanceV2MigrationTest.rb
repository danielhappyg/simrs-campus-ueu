# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'pathname'
require 'stringio'
require 'tmpdir'

require_relative '../../scripts/generate-g0-proportional-governance-v2'
require_relative '../../scripts/compare-g0-governance-v1-v2'

class G0ProportionalGovernanceV2MigrationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  CONTRACT_PATH = File.join(PHASE, 'G0_GOVERNANCE_V2_CONTRACT.json')
  V1_MANIFEST_PATH = File.join(PHASE, 'G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json')
  TEMP_PARENT = File.realpath(Dir.tmpdir)
  Generator = G0ProportionalGovernanceV2Generator
  Comparator = G0GovernanceV1V2Comparator
  Core = G0ProportionalGovernanceV2

  class << self
    attr_reader :fixture_root, :candidate

    def ensure_fixture!
      return if @candidate

      @fixture_root = Dir.mktmpdir('g0-v2-migration-', TEMP_PARENT)
      @candidate = File.join(@fixture_root, 'candidate')
      Generator.generate!(root: ROOT, output: @candidate)
    end

    def cleanup_fixture!
      FileUtils.remove_entry_secure(@fixture_root) if @fixture_root && File.exist?(@fixture_root)
    end
  end

  Minitest.after_run { cleanup_fixture! }

  def setup
    self.class.ensure_fixture!
    @candidate = self.class.candidate
    @contract = Core.parse_json_file(CONTRACT_PATH)
    @expected_rows = build_expected_rows
  end

  def test_real_generator_to_comparator_end_to_end_is_read_only_and_exact
    before = v1_current_hashes
    receipt = Comparator.compare!(root: ROOT, candidate_bundle: @candidate)

    assert_equal 'PASS', receipt.fetch('status')
    assert_equal 268, receipt.fetch('capability_count')
    assert_equal 14, receipt.fetch('provisional_engineering_binding_count')
    assert_equal 30, receipt.fetch('v1_historical_file_count')
    assert_equal %w[none none none], receipt.values_at('authority_effect', 'activation_effect', 'gate_effect')
    assert_equal before, v1_current_hashes
    refute File.exist?(File.join(PHASE, 'G0_GOVERNANCE_CONSUMER_POINTER.json'))
  end

  def test_exact_268_identity_order_source_hash_scenario_dependency_and_pending_parity_rejects_one_field_drift
    expanded = load_candidate.fetch(Comparator::EXPANDED_FILE)
    mutations = {
      'missing row' => ->(copy) { copy['entries'].pop },
      'extra row' => ->(copy) { copy['entries'] << deep_copy(copy['entries'].last) },
      'duplicate ID' => ->(copy) { copy['entries'][1]['requirement_id'] = copy['entries'][0]['requirement_id'] },
      'row order' => ->(copy) { copy['entries'][0], copy['entries'][1] = copy['entries'][1], copy['entries'][0] },
      'canonical order' => ->(copy) { copy['canonical_order'][0], copy['canonical_order'][1] = copy['canonical_order'][1], copy['canonical_order'][0] },
      'batch' => ->(copy) { copy['entries'][0]['batch'] = 'B' },
      'register path' => ->(copy) { copy['entries'][0]['source_decision_pointer']['path'] = 'elsewhere.json' },
      'register SHA' => ->(copy) { copy['entries'][0]['source_decision_pointer']['source_sha256'] = '0' * 64 },
      'register ID' => ->(copy) { copy['entries'][0]['source_decision_pointer']['register_id'] = 'invented' },
      'row index base' => ->(copy) { copy['entries'][0]['source_decision_pointer']['entry_index_base'] = 1 },
      'row index' => ->(copy) { copy['entries'][0]['source_decision_pointer']['entry_index'] = 1 },
      'historical row SHA' => ->(copy) { copy['entries'][0]['source_decision_pointer']['entry_sha256'] = '0' * 64 },
      'v2 row SHA' => ->(copy) { copy['entries'][0]['source_decision_pointer']['entry_v2_canonical_sha256'] = '0' * 64 },
      'nested scenario' => ->(copy) { copy['entries'][0]['synthetic_scenarios']['normal']['status'] = 'approved' },
      'appointment dependency' => ->(copy) { copy['entries'][0]['appointment_dependencies'][0]['status'] = 'approved' },
      'v1 decision pending fact' => ->(copy) { copy['entries'][0]['source_pending_fragments']['decision']['status'] = 'approved' },
      'v1 owner pending fact' => ->(copy) { copy['entries'][0]['source_pending_fragments']['accountable_owner']['identity'] = 'fabricated' },
      'v1 approval pending fact' => ->(copy) { copy['entries'][0]['source_pending_fragments']['approval']['status'] = 'approved' },
      'co-owner intent' => ->(copy) { copy['entries'][0]['source_pending_fragments']['co_owners'] = [] },
      'synthetic boundary' => ->(copy) { copy['entries'][0]['boundary']['real_patient_data_authorized'] = true },
      'live integration boundary' => ->(copy) { copy['entries'][0]['boundary']['live_integrations_authorized'] = true },
      'governance promotion' => ->(copy) { copy['entries'][0]['governance_state'] = 'AUTHORIZED_FOR_SYNTHETIC_BUILD' },
      'owner promotion' => ->(copy) { copy['entries'][0]['owner_state'] = 'APPROVED' },
      'tier inference' => ->(copy) { copy['entries'][0]['derived_tier'] = 'T1_STANDARD' },
      'implementation authority' => ->(copy) { copy['entries'][0]['implementation_authorized'] = true },
      'unknown row field' => ->(copy) { copy['entries'][0]['invented'] = true }
    }

    %w[upstream_requirement_dependencies dependency_gates intra_batch_dependencies source_dependencies].each do |field|
      index = expanded.fetch('entries').index { |row| !row.dig('upstream_dependencies', field).empty? }
      next unless index
      mutations["dependency #{field}"] = lambda do |copy|
        copy['entries'][index]['upstream_dependencies'][field] = []
      end
    end

    mutations.each do |name, mutation|
      changed = deep_copy(expanded)
      mutation.call(changed)
      error = assert_raises(Comparator::ComparisonError, Core::ValidationError, name) do
        Comparator.validate_expanded!(changed, @expected_rows, @contract)
      end
      refute_empty error.message, name
    end
  end

  def test_all_14_provisional_bindings_are_exact_and_engineering_only
    expanded = load_candidate.fetch(Comparator::EXPANDED_FILE)
    provisional = expanded.fetch('entries').select do |row|
      row.dig('provisional_engineering_binding', 'status') == 'PROVISIONAL'
    end
    assert_equal 14, provisional.length
    provisional.each do |row|
      binding = row.fetch('provisional_engineering_binding')
      assert_nil binding.fetch('authority_reference')
      assert_equal 'engineering_only', binding.fetch('comparison_dimension')
      assert_equal %w[none none none none none],
                   binding.values_at('owner_authority_effect', 'tier_effect', 'approval_effect', 'disposition_effect', 'gate_effect')
      assert_nil row.fetch('consequence_map')
      assert_nil row.fetch('derived_tier')
      assert_nil row.fetch('canonical_disposition')
      refute row.fetch('g0_terminal')
    end

    %w[status scenario_ids authority_reference comparison_dimension owner_authority_effect tier_effect approval_effect disposition_effect gate_effect].each do |field|
      changed = deep_copy(expanded)
      row = changed.fetch('entries').find { |entry| entry.dig('provisional_engineering_binding', 'status') == 'PROVISIONAL' }
      value = row.fetch('provisional_engineering_binding').fetch(field)
      row.fetch('provisional_engineering_binding')[field] = value.is_a?(Array) ? value.reverse + ['E2E-99'] : 'promoted'
      assert_raises(Comparator::ComparisonError, Core::ValidationError, field) do
        Comparator.validate_expanded!(changed, @expected_rows, @contract)
      end
    end
  end

  def test_self_consistent_hashes_cannot_hide_authority_owner_event_or_gate_promotion
    mutations = {
      Comparator::AUTHORITY_FILE => ->(doc) { doc['authorities'] << { 'identity' => 'fabricated' } },
      Comparator::OWNER_FILE => ->(doc) { doc['owners'] << { 'identity' => 'fabricated' } },
      Comparator::EVENT_FILE => ->(doc) { doc['events'] << { 'decision' => 'fabricated' } },
      Comparator::GATE_FILE => ->(doc) { doc['project_g0'] = 'PASS' }
    }

    mutations.each do |file, mutation|
      with_candidate_copy do |candidate|
        rewrite_candidate_artifact(candidate, file, &mutation)
        error = assert_raises(Comparator::ComparisonError, file) do
          Comparator.compare!(root: ROOT, candidate_bundle: candidate)
        end
        assert_match(/pending|authority|promotion|derivation|empty/i, error.message)
      end
    end
  end

  def test_bundle_identity_is_recomputed_not_pattern_trusted
    with_candidate_copy do |candidate|
      manifest_path = File.join(candidate, Comparator::BUNDLE_FILE)
      manifest = deep_copy(Core.parse_json_file(manifest_path))
      manifest['bundle_id'] = 'G0-GOVERNANCE-V2-PENDING-' + ('f' * 24)
      File.binwrite(manifest_path, Core.canonical_json(manifest) + "\n")

      error = assert_raises(Comparator::ComparisonError) do
        Comparator.compare!(root: ROOT, candidate_bundle: candidate)
      end
      assert_match(/identity/, error.message)
    end
  end

  def test_historical_v1_canonical_helpers_match_on_normalization_and_reject_unsafe_numbers
    decomposed = "e\u0301"
    fixture = {
      'text' => decomposed,
      'timestamp' => '2026-08-25T10:30:00+07:00',
      'integer' => (2**63) - 1,
      'nested' => [{ 'z' => nil, 'a' => true }]
    }
    generator_sha = Generator.send(:owner_canonical_sha256, fixture)
    comparator_sha = Digest::SHA256.hexdigest(Comparator.v1_owner_canonical_json(fixture))
    assert_equal generator_sha, comparator_sha

    normalized = JSON.parse(Comparator.v1_owner_canonical_json(fixture))
    assert_equal "é", normalized.fetch('text')
    assert_equal '2026-08-25T03:30:00Z', normalized.fetch('timestamp')

    [1.5, 2**63].each do |unsafe|
      value = { 'unsafe' => unsafe }
      assert_raises(Generator::Error) { Generator.send(:owner_canonical_sha256, value) }
      assert_raises(Comparator::ComparisonError) { Comparator.v1_owner_canonical_json(value) }
    end
  end

  def test_partial_extra_stale_symlink_and_unknown_candidate_states_are_unusable
    with_candidate_copy do |candidate|
      File.unlink(File.join(candidate, Comparator::GATE_FILE))
      assert_raises(Comparator::ComparisonError) { Comparator.load_closed_candidate!(Pathname.new(candidate)) }
    end
    with_candidate_copy do |candidate|
      File.binwrite(File.join(candidate, '.incomplete'), "incomplete\n")
      assert_raises(Comparator::ComparisonError) { Comparator.load_closed_candidate!(Pathname.new(candidate)) }
    end
    with_candidate_copy do |candidate|
      path = File.join(candidate, Comparator::AUTHORITY_FILE)
      File.binwrite(path, File.binread(path) + " \n")
      assert_raises(Comparator::ComparisonError) { Comparator.compare!(root: ROOT, candidate_bundle: candidate) }
    end
    with_candidate_copy do |candidate|
      path = File.join(candidate, Comparator::AUTHORITY_FILE)
      File.unlink(path)
      File.symlink(File.join(@candidate, Comparator::AUTHORITY_FILE), path)
      assert_raises(Comparator::ComparisonError) { Comparator.load_closed_candidate!(Pathname.new(candidate)) }
    end
    with_candidate_copy do |candidate|
      path = File.join(candidate, Comparator::AUTHORITY_FILE)
      File.unlink(path)
      Dir.mkdir(path)
      assert_raises(Comparator::ComparisonError) { Comparator.load_closed_candidate!(Pathname.new(candidate)) }
    end

    Dir.mktmpdir('g0-v2-candidate-link-', TEMP_PARENT) do |temp|
      link = File.join(temp, 'candidate-link')
      File.symlink(@candidate, link)
      assert_raises(Comparator::ComparisonError) { Comparator.compare!(root: ROOT, candidate_bundle: link) }
    end
  end

  def test_duplicate_keys_malformed_json_and_secret_sentinels_fail_without_echo
    sentinel = 'DO_NOT_ECHO_MIGRATION_SECRET_7f4c'
    raw_cases = [
      %({"artifact_type":"g0","password_#{sentinel}":"x","password_#{sentinel}":"y"}),
      %({"artifact_type":"g0","value":#{sentinel}})
    ]
    raw_cases.each do |raw|
      with_candidate_copy do |candidate|
        File.binwrite(File.join(candidate, Comparator::AUTHORITY_FILE), raw)
        stdout = StringIO.new
        stderr = StringIO.new
        assert_equal 1, Comparator.run_cli(['--candidate-bundle', candidate], stdout: stdout, stderr: stderr)
        refute_includes stdout.string, sentinel
        refute_includes stderr.string, sentinel
      end
    end
    with_candidate_copy do |candidate|
      path = File.join(candidate, Comparator::AUTHORITY_FILE)
      document = deep_copy(Core.parse_json_file(path))
      document["password_#{sentinel}"] = sentinel
      File.binwrite(path, Core.canonical_json(document) + "\n")
      stdout = StringIO.new
      stderr = StringIO.new
      assert_equal 1, Comparator.run_cli(['--candidate-bundle', candidate], stdout: stdout, stderr: stderr)
      refute_includes stdout.string, sentinel
      refute_includes stderr.string, sentinel
      assert_includes stderr.string, 'secret-like content rejected'
    end

    stdout = StringIO.new
    stderr = StringIO.new
    assert_equal 2, Comparator.run_cli(["--unknown-#{sentinel}"], stdout: stdout, stderr: stderr)
    refute_includes stdout.string, sentinel
    refute_includes stderr.string, sentinel
  end

  def test_cli_exit_codes_are_stable_and_duplicate_options_fail_usage
    stdout = StringIO.new
    stderr = StringIO.new
    assert_equal 0, Comparator.run_cli(['--candidate-bundle', @candidate], stdout: stdout, stderr: stderr)
    assert_includes stdout.string, '"status":"PASS"'
    assert_empty stderr.string

    stdout = StringIO.new
    stderr = StringIO.new
    assert_equal 2, Comparator.run_cli([], stdout: stdout, stderr: stderr)
    assert_includes stderr.string, 'usage_error'

    stdout = StringIO.new
    stderr = StringIO.new
    assert_equal 2,
                 Comparator.run_cli(['--candidate-bundle', @candidate, '--candidate-bundle', @candidate], stdout: stdout, stderr: stderr)
    assert_includes stderr.string, 'usage_error'
  end

  private

  def build_expected_rows
    root = Pathname.new(ROOT)
    source_manifest = Comparator.parse_bound_source(root, Comparator::SOURCE_MANIFEST_PATH,
                                                    Comparator::SOURCE_MANIFEST_SHA256, '$.test.source_manifest')
    evidence_map = Comparator.parse_bound_source(root, Comparator::EVIDENCE_MAP_PATH,
                                                 Comparator::EVIDENCE_MAP_SHA256, '$.test.evidence_map')
    owner_policy_sha = Core::V1_EXPECTED_INVENTORY.to_h.fetch(Comparator::OWNER_POLICY_PATH)
    owner_policy = Comparator.parse_bound_source(root, Comparator::OWNER_POLICY_PATH,
                                                 owner_policy_sha, '$.test.owner_policy')
    Comparator.expected_rows(root, @contract, source_manifest, evidence_map, owner_policy)
  end

  def load_candidate
    Comparator.load_closed_candidate!(Pathname.new(@candidate))
  end

  def v1_current_hashes
    manifest = Core.parse_json_file(V1_MANIFEST_PATH)
    manifest.fetch('files').to_h do |entry|
      [entry.fetch('path'), Digest::SHA256.file(File.join(ROOT, entry.fetch('path'))).hexdigest]
    end
  end

  def with_candidate_copy
    Dir.mktmpdir('g0-v2-migration-copy-', TEMP_PARENT) do |temp|
      candidate = File.join(temp, 'candidate')
      FileUtils.cp_r(@candidate, candidate)
      yield candidate
    end
  end

  def rewrite_candidate_artifact(candidate, filename)
    path = File.join(candidate, filename)
    document = deep_copy(Core.parse_json_file(path))
    yield document
    File.binwrite(path, Core.canonical_json(document) + "\n")

    manifest_path = File.join(candidate, Comparator::BUNDLE_FILE)
    manifest = deep_copy(Core.parse_json_file(manifest_path))
    artifact = manifest.fetch('generated_artifacts').find { |entry| entry.fetch('path') == filename }
    artifact['sha256'] = Digest::SHA256.file(path).hexdigest
    File.binwrite(manifest_path, Core.canonical_json(manifest) + "\n")
  end

  def deep_copy(value)
    case value
    when Hash
      value.each_with_object({}) { |(key, child), copy| copy[key] = deep_copy(child) }
    when Array
      value.map { |child| deep_copy(child) }
    else
      value
    end
  end
end
