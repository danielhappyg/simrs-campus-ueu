# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'pathname'
require 'stringio'
require 'tmpdir'

require_relative '../../scripts/generate-g0-governance-v2-gate-b-evidence'

class G0GovernanceV2GateBEvidenceTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  TEMP_PARENT = File.realpath(Dir.tmpdir)
  Evidence = G0GovernanceV2GateBEvidence
  Core = G0ProportionalGovernanceV2
  CandidateGenerator = G0ProportionalGovernanceV2Generator
  OBSERVED_AT = '2026-08-29T13:00:00+07:00'
  PREFLIGHT_AT = '2026-08-29T13:01:00+07:00'
  EXPIRES_AT = '2026-08-29T14:00:00+07:00'
  NOW = Time.iso8601('2026-08-29T13:02:00+07:00')

  def setup
    @root = Dir.mktmpdir('g0-gate-b-evidence-', TEMP_PARENT)
    File.chmod(0o700, @root)
    copy_fixture_sources!
    FileUtils.mkdir_p(File.join(@root, Evidence::OPERATIONS_PATH))
    receipt = CandidateGenerator.generate_retained!(
      root: @root, retained_name: 'gate-b-evidence-fixture'
    )
    @candidate = File.join(@root, receipt.fetch('candidate_directory'))
    @observation = File.join(
      @root, Evidence::OPERATIONS_PATH,
      'G0_GOVERNANCE_V2_GATE_B_LOCAL_OBSERVATION_FIXTURE.json'
    )
    @preflight = File.join(
      @root, Evidence::OPERATIONS_PATH,
      'G0_GOVERNANCE_V2_GATE_B_CANONICAL_PREFLIGHT_FIXTURE.json'
    )
  end

  def teardown
    FileUtils.remove_entry_secure(@root) if @root && File.exist?(@root)
  end

  def test_creates_exact_closed_non_authority_artifacts_and_preserves_inventory
    before = protected_inventory
    result = generate!
    after = protected_inventory

    assert_equal before, after
    assert_equal 'gate_b_evidence_created_no_authority', result.fetch('status')
    assert_equal 'none_evidence_only', result.fetch('effect')
    assert result.fetch('fixture_test_guard_used')
    observation = read_json(@observation)
    preflight = read_json(@preflight)
    assert_equal Core::CONSUMER_EVIDENCE_COMMON_KEYS.sort, observation.keys.sort
    assert_equal (Core::CONSUMER_EVIDENCE_COMMON_KEYS + ['checks']).sort, preflight.keys.sort
    assert_equal Core::CONSUMER_PREFLIGHT_CHECK_KEYS.sort, preflight.fetch('checks').keys.sort
    assert preflight.fetch('checks').values.all?(true)
    assert_equal %w[PASS none local_canonical_checkout], observation.values_at('status', 'authority_effect', 'environment')
    assert_equal %w[PASS none local_canonical_checkout], preflight.values_at('status', 'authority_effect', 'environment')
    assert_equal Pathname.new(@root).realpath.to_s, observation.fetch('root')
    assert_equal observation.fetch('root'), preflight.fetch('root')
    assert_equal initial_prior, observation.fetch('prior_state')
    assert_equal observation.fetch('prior_state'), preflight.fetch('prior_state')
    assert_equal reference(@candidate, bundle: true), observation.fetch('candidate_bundle')
    assert_equal observation.fetch('candidate_bundle'), preflight.fetch('candidate_bundle')
    assert_equal 0o600, File.stat(@observation).mode & 0o777
    assert_equal 0o600, File.stat(@preflight).mode & 0o777
    assert_empty probe_files
    pointer_paths = Evidence::POINTER_AUTHORITY_PATHS.map { |relative| File.join(@root, relative) }
    refute pointer_paths.any? { |path| File.exist?(path) || File.symlink?(path) }
  end

  def test_same_explicit_ids_and_times_produce_identical_bytes
    generate!
    first = [File.binread(@observation), File.binread(@preflight)]
    File.unlink(@observation)
    File.unlink(@preflight)
    generate!
    second = [File.binread(@observation), File.binread(@preflight)]
    assert_equal first, second
  end

  def test_outputs_are_create_only_and_partial_publication_is_rolled_back
    sentinel = "do-not-overwrite\n"
    File.binwrite(@observation, sentinel)
    error = assert_raises(Evidence::Error) { generate! }
    assert_match(/already exists/, error.message)
    assert_equal sentinel, File.binread(@observation)
    refute File.exist?(@preflight)

    File.unlink(@observation)
    probe = lambda do |_directory|
      raise Evidence::Error, 'unsupported filesystem fixture'
    end
    assert_raises(Evidence::Error) { generate!(capability_probe: probe) }
    refute File.exist?(@observation)
    refute File.exist?(@preflight)
    assert_empty probe_files
  end

  def test_unexpected_authority_paths_fail_before_output
    Evidence::AUTHORITY_PATHS.each do |relative|
      path = File.join(@root, relative)
      if Evidence::SCAFFOLDS.key?(relative)
        path = File.join(path, 'unexpected-authority.json')
        File.binwrite(path, "{}\n")
      else
        FileUtils.mkdir_p(File.dirname(path))
        File.binwrite(path, "unexpected-authority\n")
      end
      assert_raises(Evidence::Error, relative) { generate! }
      refute File.exist?(@observation), relative
      refute File.exist?(@preflight), relative
      FileUtils.rm_rf(path)
    end
  end

  def test_scaffold_readmes_are_exact_and_no_extra_or_json_entries_are_permitted
    relative, = Evidence::SCAFFOLDS.first
    directory = File.join(@root, relative)
    readme = File.join(directory, 'README.md')
    original = File.binread(readme)
    File.binwrite(readme, original + " ")
    assert_raises(Evidence::Error) { generate! }
    File.binwrite(readme, original)

    extra = File.join(directory, 'extra.json')
    File.binwrite(extra, "{}\n")
    assert_raises(Evidence::Error) { generate! }
    File.unlink(extra)

    linked = File.join(directory, 'README-linked.md')
    File.link(readme, linked)
    assert_raises(Evidence::Error) { generate! }
    refute File.exist?(@observation)
    refute File.exist?(@preflight)
  end

  def test_authority_directories_reject_unsafe_modes
    ([Evidence::PHASE0_PATH] + Evidence::SCAFFOLDS.keys).uniq.each do |relative|
      directory = File.join(@root, relative)
      original_mode = File.stat(directory).mode & 0o777
      File.chmod(0o777, directory)
      error = assert_raises(Evidence::Error, relative) { generate! }
      assert_match(/directory|scaffold|parent/, error.message, relative)
      refute File.exist?(@observation), relative
      refute File.exist?(@preflight), relative
      File.chmod(original_mode, directory)
    end
  end

  def test_lock_must_be_absent_or_private_inert_zero_byte_single_link
    lock = File.join(@root, Evidence::PHASE0_PATH, 'fixture.lock')
    File.binwrite(lock, '')
    File.chmod(0o600, lock)
    generate!
    File.unlink(@observation)
    File.unlink(@preflight)

    File.chmod(0o644, lock)
    assert_raises(Evidence::Error) { generate! }
    File.chmod(0o600, lock)
    File.binwrite(lock, 'occupied')
    assert_raises(Evidence::Error) { generate! }
    File.binwrite(lock, '')
    linked = File.join(@root, Evidence::PHASE0_PATH, 'fixture-linked.lock')
    File.link(lock, linked)
    assert_raises(Evidence::Error) { generate! }
    refute File.exist?(@observation)
    refute File.exist?(@preflight)
  end

  def test_incomplete_hash_drift_symlink_hardlink_and_unsafe_mode_fail_closed
    artifact = File.join(@candidate, CandidateGenerator::FILES.fetch('authority_register'))
    original = File.binread(artifact)
    File.binwrite(artifact, original + " ")
    assert_raises(Evidence::Error) { generate! }
    File.binwrite(artifact, original)

    File.chmod(0o664, artifact)
    assert_raises(Evidence::Error) { generate! }
    File.chmod(0o644, artifact)

    link = File.join(@candidate, 'hardlink.json')
    File.link(artifact, link)
    assert_raises(Evidence::Error) { generate! }
    File.unlink(link)

    removed = File.join(@candidate, CandidateGenerator::FILES.fetch('owner_register'))
    removed_bytes = File.binread(removed)
    File.unlink(removed)
    assert_raises(Evidence::Error) { generate! }
    File.binwrite(removed, removed_bytes)

    outside = Dir.mktmpdir('g0-gate-b-outside-', TEMP_PARENT)
    symlink = File.join(@root, Evidence::CANDIDATES_PATH, 'symlink-candidate')
    File.symlink(outside, symlink)
    assert_raises(Evidence::Error) do
      generate!(candidate: symlink)
    end
  ensure
    FileUtils.remove_entry_secure(outside) if outside && File.exist?(outside)
  end

  def test_test_controls_bad_times_secret_like_ids_and_unsafe_outputs_are_rejected
    assert_raises(Evidence::UsageError) { generate!(env: {}) }
    fault_env = guarded_env.merge(Evidence::SELECTOR_TEST_FAULT => 'after_selection_create')
    assert_raises(Evidence::Error) { generate!(env: fault_env) }
    assert_raises(Evidence::Error) { generate!(observation_id: 'pass' + 'word=secret') }
    assert_raises(Evidence::Error) { generate!(expires_at: PREFLIGHT_AT) }
    assert_raises(Evidence::Error) do
      generate!(preflight_observed_at: '2026-08-29T13:03:00+07:00')
    end
    assert_raises(Evidence::Error) { generate!(observation_observed_at: '2026-08-29T13:00:00') }
    unsafe = File.join(@root, Evidence::OPERATIONS_PATH, '../G0_GOVERNANCE_V2_GATE_B_LOCAL_OBSERVATION_BAD.json')
    assert_raises(Evidence::Error) { generate!(observation_output: unsafe) }
    refute File.exist?(@observation)
    refute File.exist?(@preflight)
  end

  def test_candidate_replacement_race_is_rejected_immediately_before_publication
    original_candidate = @candidate
    displaced = File.join(@root, 'displaced-candidate')
    race_probe = lambda do |directories|
      result = Evidence.filesystem_capability_probe!(directories)
      File.rename(original_candidate, displaced)
      FileUtils.cp_r(displaced, original_candidate, preserve: true)
      File.chmod(0o700, original_candidate)
      result
    end
    before = protected_inventory
    error = assert_raises(Evidence::Error) do
      generate!(capability_probe: race_probe)
    end
    assert_match(/changed during preflight/, error.message)
    assert_equal before, protected_inventory
    refute File.exist?(@observation)
    refute File.exist?(@preflight)
    assert_empty probe_files
  ensure
    FileUtils.remove_entry_secure(displaced) if displaced && File.exist?(displaced)
  end

  def test_authority_directory_replacement_race_is_rejected_before_publication
    relative = Evidence::SCAFFOLDS.keys.first
    original = File.join(@root, relative)
    displaced = File.join(@root, 'displaced-authority-scaffold')
    race_probe = lambda do |directories|
      result = Evidence.filesystem_capability_probe!(directories)
      File.rename(original, displaced)
      FileUtils.cp_r(displaced, original, preserve: true)
      result
    end
    before = protected_inventory
    error = assert_raises(Evidence::Error) do
      generate!(capability_probe: race_probe)
    end
    assert_match(/directory changed during preflight/, error.message)
    assert_equal before, protected_inventory
    refute File.exist?(@observation)
    refute File.exist?(@preflight)
    assert_empty probe_files
  ensure
    if displaced && File.exist?(displaced)
      FileUtils.remove_entry_secure(original) if File.exist?(original)
      File.rename(displaced, original)
    end
  end

  def test_direct_ruby_canonical_calls_reject_all_runtime_injection_before_effect
    args = canonical_bypass_args
    before = canonical_authority_inventory

    assert_raises(Evidence::Error) { Evidence.generate!(**args, env: {}) }
    assert_raises(Evidence::Error) { Evidence.generate!(**args, now: NOW) }
    probe_called = false
    probe = lambda do |_directories|
      probe_called = true
      raise 'canonical injected probe must never run'
    end
    assert_raises(Evidence::Error) { Evidence.generate!(**args, capability_probe: probe) }
    assert_raises(Evidence::Error) { Evidence.validate_root!(ROOT, env: {}) }

    refute probe_called
    assert_equal before, canonical_authority_inventory
    refute File.exist?(args.fetch(:observation_output))
    refute File.exist?(args.fetch(:preflight_output))
  end

  def test_cli_is_closed_and_never_echoes_rejected_content
    out = StringIO.new
    err = StringIO.new
    status = Evidence.run_cli(['--root', @root], stdout: out, stderr: err, env: guarded_env)
    assert_equal 2, status
    assert_empty out.string
    assert_equal "usage_error: invalid command-line usage\n", err.string

    out = StringIO.new
    err = StringIO.new
    argv = cli_args
    assert_equal 0, Evidence.run_cli(argv, stdout: out, stderr: err, env: guarded_env)
    assert_empty err.string
    assert_equal 'gate_b_evidence_created_no_authority', JSON.parse(out.string).fetch('status')
  end

  private

  def generate!(overrides = {})
    Evidence.generate!(**{
      root: @root,
      candidate: @candidate,
      observation_output: @observation,
      preflight_output: @preflight,
      observation_id: 'G0-V2-GATE-B-OBS-FIXTURE',
      preflight_id: 'G0-V2-GATE-B-PREFLIGHT-FIXTURE',
      observation_observed_at: OBSERVED_AT,
      preflight_observed_at: PREFLIGHT_AT,
      expires_at: EXPIRES_AT,
      env: guarded_env,
      now: NOW
    }.merge(overrides))
  end

  def guarded_env
    { Evidence::TEST_ROOT_GUARD => '1' }
  end

  def cli_args
    invocation_time = Time.now.utc
    [
      '--root', @root,
      '--candidate', @candidate,
      '--observation-output', @observation,
      '--preflight-output', @preflight,
      '--observation-id', 'G0-V2-GATE-B-OBS-FIXTURE',
      '--preflight-id', 'G0-V2-GATE-B-PREFLIGHT-FIXTURE',
      '--observation-observed-at', (invocation_time - 2).iso8601(6),
      '--preflight-observed-at', (invocation_time - 1).iso8601(6),
      '--expires-at', (invocation_time + 300).iso8601(6)
    ]
  end

  def canonical_bypass_args
    suffix = "#{Process.pid}-#{object_id}"
    {
      root: ROOT,
      candidate: File.join(ROOT, Evidence::CANDIDATES_PATH, 'injection-must-fail-before-candidate-validation'),
      observation_output: File.join(
        ROOT, Evidence::OPERATIONS_PATH,
        "G0_GOVERNANCE_V2_GATE_B_LOCAL_OBSERVATION_BYPASS_#{suffix}.json"
      ),
      preflight_output: File.join(
        ROOT, Evidence::OPERATIONS_PATH,
        "G0_GOVERNANCE_V2_GATE_B_CANONICAL_PREFLIGHT_BYPASS_#{suffix}.json"
      ),
      observation_id: 'G0-V2-GATE-B-OBS-CANONICAL-BYPASS',
      preflight_id: 'G0-V2-GATE-B-PREFLIGHT-CANONICAL-BYPASS',
      observation_observed_at: OBSERVED_AT,
      preflight_observed_at: PREFLIGHT_AT,
      expires_at: EXPIRES_AT
    }
  end

  def copy_fixture_sources!
    contract = JSON.parse(File.binread(File.join(ROOT, Evidence::CONTRACT_PATH)))
    paths = Core::V1_EXPECTED_INVENTORY.map(&:first)
    paths.concat(contract.fetch('adopted_sources').values.map { |reference| reference.fetch('path') })
    paths.concat([
      Evidence::CONTRACT_PATH,
      Evidence::SELECTOR_PATH,
      CandidateGenerator::V1_MANIFEST_PATH,
      CandidateGenerator::EVIDENCE_MAP_PATH
    ])
    paths.concat(Evidence::SCAFFOLDS.keys.map { |relative| "#{relative}/README.md" })
    paths.uniq.each do |relative|
      source = File.join(ROOT, relative)
      destination = File.join(@root, relative)
      FileUtils.mkdir_p(File.dirname(destination))
      FileUtils.cp(source, destination, preserve: true)
    end
    File.binwrite(File.join(@root, '.git'), "gitdir: #{File.join(ROOT, '.git')}\n")
  end

  def read_json(path)
    Core.parse_json_file(path)
  end

  def initial_prior
    {
      'prior_state_reason' => 'initial_state',
      'expected_prior_pointer_sha256' => nil,
      'observed_unreadable_pointer_sha256' => nil
    }
  end

  def reference(path, bundle: false)
    target = Pathname.new(path).realpath
    source = bundle ? target.join(Evidence::MANIFEST_NAME) : target
    {
      'path' => target.relative_path_from(Pathname.new(@root).realpath).to_s,
      'sha256' => Digest::SHA256.file(source).hexdigest
    }
  end

  def authority_paths
    Evidence::AUTHORITY_PATHS.map { |relative| File.join(@root, relative) }
  end

  def protected_inventory
    paths = authority_paths + [@candidate]
    paths.to_h { |path| [path, path_inventory(path)] }
  end

  def canonical_authority_inventory
    paths = Evidence::AUTHORITY_PATHS.map { |relative| File.join(ROOT, relative) }
    paths.to_h { |path| [path, path_inventory(path)] }
  end

  def path_inventory(path)
    return nil unless File.exist?(path) || File.symlink?(path)
    stat = File.lstat(path)
    return { type: :symlink, target: File.readlink(path) } if stat.symlink?
    return { type: :file, sha256: Digest::SHA256.file(path).hexdigest, mode: stat.mode & 0o777 } if stat.file?
    Dir.children(path).sort.to_h { |name| [name, path_inventory(File.join(path, name))] }
  end

  def probe_files
    Dir.glob(File.join(@root, '**', '.g0-gate-b-evidence-probe-*'))
  end
end
