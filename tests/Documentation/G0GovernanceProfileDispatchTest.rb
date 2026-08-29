# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'open3'
require 'pathname'
require 'rbconfig'
require 'stringio'
require 'tmpdir'

require_relative '../../scripts/g0-proportional-governance-v2'
require_relative '../../scripts/generate-g0-proportional-governance-v2'
require_relative '../../scripts/validate-g0-governance'

class G0GovernanceProfileDispatchTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PHASE = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0')
  GENERATOR = File.join(ROOT, 'scripts/generate-g0-proportional-governance-v2.rb')
  V1_VALIDATOR = File.join(ROOT, 'scripts/validate-parity-governance.rb')
  V2_VALIDATOR = File.join(ROOT, 'scripts/validate-g0-proportional-governance-v2.rb')
  DISPATCHER = File.join(ROOT, 'scripts/validate-g0-governance.rb')
  ADOPTION = File.join(PHASE, 'G0_GOVERNANCE_V2_ADOPTION_DECISION.json')
  V1_MANIFEST = File.join(PHASE, 'G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json')
  Core = G0ProportionalGovernanceV2
  Dispatcher = G0GovernanceProfileDispatcher
  Resolver = G0GovernanceConsumerSelector::ReadOnlyResolver
  ResolutionError = G0GovernanceConsumerSelector::ResolutionError

  RECEIPT_KEYS = %w[
    schema_version operation_id operation profile mode source status reason_code
    message actor started_at finished_at planning_baseline adoption_sha256
    activation_sha256 prior_pointer_sha256 selection_sha256 bundle_sha256
    validator_contract secret_scan_passed
  ].freeze

  class << self
    attr_reader :fixture_root, :candidate

    def ensure_fixture!
      return if @candidate

      @fixture_root = Dir.mktmpdir('g0-wave4-dispatch-', '/private/tmp')
      @candidate = File.join(@fixture_root, 'candidate')
      stdout, stderr, status = Open3.capture3(
        RbConfig.ruby, GENERATOR, '--root', ROOT, '--output', @candidate,
        chdir: ROOT
      )
      unless status.exitstatus == 0 && stderr.empty? && JSON.parse(stdout).fetch('status') == 'generated_pending_candidate'
        raise "Wave 4 generator fixture failed (exit #{status.exitstatus})"
      end
    end

    def cleanup_fixture!
      FileUtils.remove_entry_secure(@fixture_root) if @fixture_root && File.exist?(@fixture_root)
    end
  end

  Minitest.after_run { cleanup_fixture! }

  def setup
    self.class.ensure_fixture!
    @candidate = self.class.candidate
    @repo_tmp = Dir.mktmpdir('.g0-wave4-receipts-', ROOT)
    @v1_before_each_test = v1_current_hashes
  end

  def teardown
    assert_equal @v1_before_each_test, v1_current_hashes, 'Wave 4 observation must preserve every historical v1 byte'
    FileUtils.remove_entry_secure(@repo_tmp) if @repo_tmp && File.exist?(@repo_tmp)
  end

  def test_real_generator_v2_cli_and_dual_dispatch_end_to_end_are_observational
    v1_before = v1_current_hashes
    candidate_before = directory_hashes(@candidate)
    authority_paths_before = authority_path_inventory

    integrity = run_cli(
      V2_VALIDATOR, '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION
    )
    assert_equal 0, integrity.fetch(:exit), integrity
    assert_empty integrity.fetch(:stderr)
    assert_receipt(parse_stdout_receipt(integrity), profile: 'v2', mode: 'integrity', status: 'PASS')

    g0 = run_cli(
      V2_VALIDATOR, '--mode', 'g0', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION
    )
    assert_equal 0, g0.fetch(:exit), g0
    assert_receipt(parse_stdout_receipt(g0), profile: 'v2', mode: 'g0', status: 'OPEN')

    dual_receipt_path = File.join(@repo_tmp, 'dual-e2e.json')
    dual = run_dispatch(
      '--profile', 'dual', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', dual_receipt_path
    )
    assert_equal 0, dual.fetch(:exit), dual
    assert_empty dual.fetch(:stderr)
    assert_receipt(JSON.parse(File.binread(dual_receipt_path)), profile: 'dual', mode: 'integrity', status: 'PASS')

    assert_equal v1_before, v1_current_hashes
    assert_equal candidate_before, directory_hashes(@candidate)
    assert_equal authority_paths_before, authority_path_inventory
  end

  def test_dispatcher_v1_is_subprocess_equivalent_and_never_silently_selects_v2
    %w[integrity g0].each do |mode|
      direct = run_cli(V1_VALIDATOR, '--mode', mode)
      delegated = run_dispatch('--profile', 'v1', '--mode', mode)
      assert_equal direct, delegated, "v1 #{mode} delegation must preserve exact exit/stdout/stderr"
    end

    no_profile = run_dispatch('--mode', 'integrity')
    assert_equal 2, no_profile.fetch(:exit)
    assert_empty no_profile.fetch(:stdout)
    assert_match(/usage_error:/, no_profile.fetch(:stderr))

    implicit = run_dispatch
    assert_equal 2, implicit.fetch(:exit)
    assert_empty implicit.fetch(:stdout)
    assert_match(/usage_error:/, implicit.fetch(:stderr))
  end

  def test_valid_all_pending_candidate_is_integrity_pass_but_project_g0_open
    %w[v2 dual].each do |profile|
      integrity_receipt_path = File.join(@repo_tmp, "#{profile}-integrity.json")
      integrity = run_dispatch(
        '--profile', profile, '--mode', 'integrity', '--source', 'candidate',
        '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
        '--json-receipt', integrity_receipt_path
      )
      assert_equal 0, integrity.fetch(:exit), "#{profile}: #{integrity.inspect}"
      integrity_receipt = profile == 'v2' ? parse_stdout_receipt(integrity) : JSON.parse(File.binread(integrity_receipt_path))
      assert_receipt(integrity_receipt, profile: profile, mode: 'integrity', status: 'PASS')

      g0_receipt_path = File.join(@repo_tmp, "#{profile}-g0.json")
      g0 = run_dispatch(
        '--profile', profile, '--mode', 'g0', '--source', 'candidate',
        '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
        '--json-receipt', g0_receipt_path
      )
      assert_equal 0, g0.fetch(:exit), "#{profile}: #{g0.inspect}"
      receipt = profile == 'v2' ? parse_stdout_receipt(g0) : JSON.parse(File.binread(g0_receipt_path))
      assert_receipt(receipt, profile: profile, mode: 'g0', status: 'OPEN')
      assert_match(/open/i, receipt.fetch('reason_code'))
    end
  end

  def test_active_invalid_pointer_states_fail_closed_under_test_root_guard
    v1_before = v1_current_hashes
    authority_paths_before = authority_path_inventory

    with_selector_layout do |root|
      %w[pointer_missing pointer_unreadable pointer_contract_invalid pointer_recovery_required].each do |reason|
        pointer = File.join(root, G0GovernanceConsumerSelector::POINTER_RELATIVE_PATH)
        marker = File.join(root, G0GovernanceConsumerSelector::RECOVERY_MARKER_RELATIVE_PATH)
        File.binwrite(pointer, "{malformed\n") if reason == 'pointer_unreadable'
        File.binwrite(pointer, "{}\n") if reason == 'pointer_contract_invalid'
        File.binwrite(marker, "recovery required\n") if reason == 'pointer_recovery_required'

        %w[v2 dual].product(%w[integrity g0]).each do |profile, mode|
          argv = [
            '--profile', profile, '--mode', mode, '--source', 'active', '--root', root,
          ]
          receipt_path = nil
          if reason == 'pointer_missing' && profile == 'v2' && mode == 'integrity'
            receipt_path = File.join(root, 'active-missing-receipt.json')
            argv.concat(['--json-receipt', receipt_path])
          end
          result = run_dispatch(*argv, env: { 'G0_GOVERNANCE_V2_TEST_ROOT' => '1' })
          assert_equal 1, result.fetch(:exit), [reason, profile, mode, result]
          assert_equal "validation_failed: active_source_#{reason}\n", result.fetch(:stderr)
          receipt = JSON.parse(result.fetch(:stdout))
          assert_receipt(receipt, profile: profile, mode: mode, status: 'FAIL', source: 'active')
          assert_equal "active_#{reason}", receipt.fetch('reason_code')
          if receipt_path
            assert_equal result.fetch(:stdout), File.binread(receipt_path)
            assert_equal 0o600, File.stat(receipt_path).mode & 0o777
          end
        end

        File.unlink(pointer) if File.exist?(pointer)
        File.unlink(marker) if File.exist?(marker)
      end
    end

    assert_equal v1_before, v1_current_hashes
    assert_equal authority_paths_before, authority_path_inventory
  end

  def test_active_held_and_disabled_force_g0_open_but_fail_integrity_without_exposing_hashes
    authority_paths_before = authority_path_inventory

    with_selector_layout do |root|
      %w[pointer_held pointer_disabled].each do |reason|
        Resolver.stub(:resolve_active!, ->(**_arguments) { raise ResolutionError, reason }) do
          %w[v2 dual].each do |profile|
            integrity = run_dispatch_in_process(
              '--profile', profile, '--mode', 'integrity', '--source', 'active', '--root', root,
              env: { 'G0_GOVERNANCE_V2_TEST_ROOT' => '1' }
            )
            assert_equal 1, integrity.fetch(:exit), integrity
            assert_receipt(JSON.parse(integrity.fetch(:stdout)), profile: profile, mode: 'integrity', status: 'FAIL', source: 'active')

            g0 = run_dispatch_in_process(
              '--profile', profile, '--mode', 'g0', '--source', 'active', '--root', root,
              env: { 'G0_GOVERNANCE_V2_TEST_ROOT' => '1' }
            )
            assert_equal 0, g0.fetch(:exit), g0
            assert_empty g0.fetch(:stderr)
            receipt = JSON.parse(g0.fetch(:stdout))
            assert_receipt(receipt, profile: profile, mode: 'g0', status: 'OPEN', source: 'active')
            assert_equal "active_#{reason}_g0_open", receipt.fetch('reason_code')
          end
        end
      end
    end

    assert_equal authority_paths_before, authority_path_inventory
  end

  def test_active_dispatch_delegates_once_to_shared_resolver_and_matches_candidate_validation
    authority_paths_before = authority_path_inventory
    bundle_manifest = File.join(@candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('bundle_manifest'))
    resolution = {
      bundle_path: Pathname.new(@candidate),
      bundle_sha256: Digest::SHA256.file(bundle_manifest).hexdigest,
      adoption_path: Pathname.new(ADOPTION),
      adoption_sha256: Digest::SHA256.file(ADOPTION).hexdigest,
      operation_decision_sha256: 'a' * 64,
      pointer_sha256: 'b' * 64,
      pointer: { 'predecessor_pointer_sha256' => 'e' * 64 },
      selection_sha256: 'c' * 64,
      validator_contract: {
        'name' => 'g0_proportional_governance_v2', 'version' => '1.0.0',
        'path' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json',
        'sha256' => Digest::SHA256.file(File.join(PHASE, 'G0_GOVERNANCE_V2_CONTRACT.json')).hexdigest
      }
    }
    calls = []

    Resolver.stub(:resolve_active!, lambda { |root:, env:|
      calls << [root.to_s, env['G0_GOVERNANCE_V2_TEST_ROOT']]
      resolution
    }) do
      %w[v2 dual].product(%w[integrity g0]).each do |profile, mode|
        candidate = run_dispatch(
          '--profile', profile, '--mode', mode, '--source', 'candidate',
          '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION
        )
        active = run_dispatch_in_process('--profile', profile, '--mode', mode, '--source', 'active')
        assert_equal candidate.fetch(:exit), active.fetch(:exit), [profile, mode, active]
        assert_empty active.fetch(:stderr)
        candidate_receipt = JSON.parse(candidate.fetch(:stdout))
        assert_equal 'validate-g0-governance/1.0.0-wave4-candidate-only', candidate_receipt.fetch('validator_contract').fetch('dispatcher')
        receipt = JSON.parse(active.fetch(:stdout))
        assert_receipt(receipt, profile: profile, mode: mode, status: mode == 'g0' ? 'OPEN' : 'PASS', source: 'active')
        assert_equal resolution.fetch(:adoption_sha256), receipt.fetch('adoption_sha256')
        assert_equal resolution.fetch(:operation_decision_sha256), receipt.fetch('activation_sha256')
        assert_equal resolution.fetch(:pointer).fetch('predecessor_pointer_sha256'), receipt.fetch('prior_pointer_sha256')
        assert_equal resolution.fetch(:selection_sha256), receipt.fetch('selection_sha256')
        assert_equal resolution.fetch(:bundle_sha256), receipt.fetch('bundle_sha256')
        assert_equal 'validate-g0-governance/1.1.0-wave5-active-read-only', receipt.fetch('validator_contract').fetch('dispatcher')
        assert_equal resolution.fetch(:validator_contract), receipt.fetch('validator_contract').fetch('active_resolver')
        assert_empty Core.secret_locations(receipt.reject { |key, _value| key == 'secret_scan_passed' })
      end
    end

    assert_equal 8, calls.length
    assert calls.all? { |root, guard| root == ROOT && guard.nil? }, calls.inspect

    sequence = [resolution, resolution.merge(pointer_sha256: 'd' * 64)]
    Resolver.stub(:resolve_active!, ->(**_arguments) { sequence.shift }) do
      changed = run_dispatch_in_process('--profile', 'v2', '--mode', 'integrity', '--source', 'active')
      assert_equal 1, changed.fetch(:exit), changed
      assert_equal "validation_failed: active_source_snapshot_changed\n", changed.fetch(:stderr)
      changed_receipt = JSON.parse(changed.fetch(:stdout))
      assert_receipt(changed_receipt, profile: 'v2', mode: 'integrity', status: 'FAIL', source: 'active')
      assert_equal 'active_snapshot_changed', changed_receipt.fetch('reason_code')
    end

    assert_equal authority_paths_before, authority_path_inventory
  end

  def test_exit_codes_and_closed_option_matrix_reject_duplicate_unknown_irrelevant_and_conflicting_inputs
    common = ['--candidate-bundle', @candidate, '--adoption-decision', ADOPTION]
    invalid_argv = [
      [],
      ['--mode', 'integrity'],
      ['--profile', 'unknown'],
      ['--profile', 'v1', '--source', 'candidate'],
      ['--profile', 'v1', '--candidate-bundle', @candidate],
      ['--profile', 'v1', '--adoption-decision', ADOPTION],
      ['--profile', 'v2'],
      ['--profile', 'v2', '--source', 'candidate', '--adoption-decision', ADOPTION],
      ['--profile', 'v2', '--source', 'candidate', '--candidate-bundle', @candidate],
      ['--profile', 'v2', '--source', 'active', *common],
      ['--profile', 'dual', '--source', 'active', *common],
      ['--profile', 'v2', '--mode', 'unknown', '--source', 'candidate', *common],
      ['--profile', 'v1', 'unexpected-position'],
      ['--profile', 'v1', '--unknown-option'],
      ['--profile', 'v1', '--profile', 'v1'],
      ['--profile', 'v1', '--mode', 'integrity', '--mode', 'integrity'],
      ['--profile', 'v2', '--source', 'candidate', '--source', 'candidate', *common],
      ['--profile', 'v2', '--source', 'candidate', '--candidate-bundle', @candidate,
       '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION],
      ['--profile', 'v2', '--source', 'candidate', '--candidate-bundle', @candidate,
       '--adoption-decision', ADOPTION, '--adoption-decision', ADOPTION],
      ['--profile', 'v1', '--root', ROOT, '--root', ROOT],
      ['--profile', 'v1', '--json-receipt', File.join(@repo_tmp, 'a.json'),
       '--json-receipt', File.join(@repo_tmp, 'b.json')]
    ]

    invalid_argv.each do |argv|
      result = run_dispatch(*argv)
      assert_equal 2, result.fetch(:exit), "#{argv.inspect}: #{result.inspect}"
      assert_empty result.fetch(:stdout), argv.inspect
      assert_match(/\Ausage_error:/, result.fetch(:stderr), argv.inspect)
    end

    success = run_dispatch('--profile', 'v1', '--mode', 'integrity')
    assert_equal 0, success.fetch(:exit)
    contract_failure = run_dispatch('--profile', 'v2', '--mode', 'integrity', '--source', 'active')
    assert_equal 1, contract_failure.fetch(:exit)
  end

  def test_candidate_adoption_and_hash_drift_fail_closed
    mutations = {
      'artifact hash drift' => lambda do |candidate|
        path = File.join(candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('authority_register'))
        File.binwrite(path, File.binread(path) + " \n")
      end,
      'manifest adoption drift' => lambda do |candidate|
        path = File.join(candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('bundle_manifest'))
        document = JSON.parse(File.binread(path))
        document.fetch('adoption_decision')['sha256'] = '0' * 64
        File.binwrite(path, Core.canonical_json(document) + "\n")
      end,
      'validator contract drift' => lambda do |candidate|
        path = File.join(candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('bundle_manifest'))
        document = JSON.parse(File.binread(path))
        document.fetch('validator')['contract_sha256'] = 'f' * 64
        File.binwrite(path, Core.canonical_json(document) + "\n")
      end
    }

    mutations.each do |name, mutation|
      with_candidate_copy do |candidate|
        mutation.call(candidate)
        result = run_dispatch(
          '--profile', 'v2', '--mode', 'integrity', '--source', 'candidate',
          '--candidate-bundle', candidate, '--adoption-decision', ADOPTION
        )
        assert_equal 1, result.fetch(:exit), "#{name}: #{result.inspect}"
        assert_receipt(parse_failure_receipt(result), profile: 'v2', mode: 'integrity', status: 'FAIL')
        assert_match(/validation_failed:/, result.fetch(:stderr))
      end
    end

    drifted_adoption = File.join(@repo_tmp, 'drifted-adoption.json')
    adoption = JSON.parse(File.binread(ADOPTION))
    adoption['status'] = 'pending'
    File.binwrite(drifted_adoption, Core.canonical_json(adoption) + "\n")
    result = run_dispatch(
      '--profile', 'v2', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', drifted_adoption
    )
    assert_equal 1, result.fetch(:exit), result
    assert_receipt(parse_failure_receipt(result), profile: 'v2', mode: 'integrity', status: 'FAIL')
    assert_match(/validation_failed:/, result.fetch(:stderr))
  end

  def test_malformed_secret_symlink_and_nonregular_candidate_or_adoption_paths_fail_without_echo
    sentinel = 'DO_NOT_ECHO_WAVE4_SECRET_71de'
    with_candidate_copy do |candidate|
      path = File.join(candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('authority_register'))
      File.binwrite(path, %({"artifact_type":"x","password_#{sentinel}":"#{sentinel}"))
      result = candidate_integrity(candidate)
      assert_equal 1, result.fetch(:exit)
      refute_includes result.fetch(:stdout), sentinel
      refute_includes result.fetch(:stderr), sentinel
    end

    with_candidate_copy do |candidate|
      path = File.join(candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('authority_register'))
      File.binwrite(path, '{malformed')
      result = candidate_integrity(candidate)
      assert_equal 1, result.fetch(:exit)
      assert_match(/validation_failed:/, result.fetch(:stderr))
    end

    with_candidate_copy do |candidate|
      path = File.join(candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('authority_register'))
      File.unlink(path)
      File.symlink(File.join(@candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('authority_register')), path)
      assert_equal 1, candidate_integrity(candidate).fetch(:exit)
    end

    with_candidate_copy do |candidate|
      path = File.join(candidate, G0ProportionalGovernanceV2Generator::FILES.fetch('authority_register'))
      File.unlink(path)
      Dir.mkdir(path)
      assert_equal 1, candidate_integrity(candidate).fetch(:exit)
    end

    candidate_file = File.join(@repo_tmp, 'candidate-file')
    File.binwrite(candidate_file, "not a directory\n")
    assert_equal 1, candidate_integrity(candidate_file).fetch(:exit)

    candidate_link = File.join(@repo_tmp, 'candidate-link')
    File.symlink(@candidate, candidate_link)
    assert_equal 1, candidate_integrity(candidate_link).fetch(:exit)

    adoption_link = File.join(@repo_tmp, 'adoption-link.json')
    File.symlink(ADOPTION, adoption_link)
    result = run_dispatch(
      '--profile', 'v2', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', adoption_link
    )
    assert_equal 1, result.fetch(:exit)
  end

  def test_receipts_are_closed_secret_free_exclusive_and_path_guarded
    standalone_path = File.join(@repo_tmp, 'standalone.json')
    standalone = run_cli(
      V2_VALIDATOR, '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', standalone_path
    )
    assert_equal 0, standalone.fetch(:exit), standalone
    assert_equal standalone.fetch(:stdout), File.binread(standalone_path)
    assert_equal 0o600, File.stat(standalone_path).mode & 0o777
    standalone_original = File.binread(standalone_path)
    standalone_overwrite = run_cli(
      V2_VALIDATOR, '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', standalone_path
    )
    assert_equal 1, standalone_overwrite.fetch(:exit)
    assert_equal standalone_original, File.binread(standalone_path)

    receipt_path = File.join(@repo_tmp, 'observation.json')
    first = run_dispatch(
      '--profile', 'dual', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', receipt_path
    )
    assert_equal 0, first.fetch(:exit), first
    receipt = JSON.parse(File.binread(receipt_path))
    assert_receipt(receipt, profile: 'dual', mode: 'integrity', status: 'PASS')
    assert_empty Core.secret_locations(receipt.reject { |key, _value| key == 'secret_scan_passed' })
    assert_equal 0o600, File.stat(receipt_path).mode & 0o777
    original = File.binread(receipt_path)

    second = run_dispatch(
      '--profile', 'dual', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', receipt_path
    )
    assert_equal 1, second.fetch(:exit), second
    assert_equal original, File.binread(receipt_path)

    outside = File.join(self.class.fixture_root, 'outside-receipt.json')
    outside_result = run_dispatch(
      '--profile', 'v2', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', outside
    )
    assert_equal 1, outside_result.fetch(:exit)
    refute File.exist?(outside)

    target = File.join(@repo_tmp, 'target.json')
    File.binwrite(target, "owned\n")
    symlink = File.join(@repo_tmp, 'receipt-link.json')
    File.symlink(target, symlink)
    linked = run_dispatch(
      '--profile', 'v2', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', symlink
    )
    assert_equal 1, linked.fetch(:exit)
    assert_equal "owned\n", File.binread(target)

    directory = File.join(@repo_tmp, 'receipt-directory')
    Dir.mkdir(directory)
    nonregular = run_dispatch(
      '--profile', 'v2', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', @candidate, '--adoption-decision', ADOPTION,
      '--json-receipt', directory
    )
    assert_equal 1, nonregular.fetch(:exit)
  end

  def test_noncanonical_root_is_rejected_without_test_guard_and_allowed_only_with_explicit_guard
    result = run_dispatch('--profile', 'v1', '--mode', 'integrity', '--root', self.class.fixture_root)
    assert_equal 2, result.fetch(:exit)
    assert_match(/usage_error:/, result.fetch(:stderr))

    # The guard permits fixture-root selection as CLI usage, but the deliberately
    # incomplete fixture still fails validation rather than falling back to the
    # canonical checkout.
    guarded = run_cli(
      DISPATCHER, '--profile', 'v1', '--mode', 'integrity', '--root', self.class.fixture_root,
      env: { 'G0_GOVERNANCE_V2_TEST_ROOT' => '1' }
    )
    refute_equal 0, guarded.fetch(:exit)
  end

  def test_dispatcher_uses_one_shared_read_only_resolver_and_contains_no_pointer_or_activation_mutation_logic
    forbidden = [
      /G0_GOVERNANCE_CONSUMER_POINTER/,
      /POINTER_RELATIVE_PATH/,
      /resolve_pointer!/,
      /\.execute!\(/,
      /File\.rename/,
      /FileUtils/,
      /LOCK_EX/,
      /\.flock\(/
    ]
    [V2_VALIDATOR, DISPATCHER].each do |path|
      source = File.binread(path)
      forbidden.each do |pattern|
        refute_match pattern, source, "#{File.basename(path)} must not implement Wave 5 pointer/activation mechanics"
      end
    end

    dispatcher_source = File.binread(DISPATCHER)
    assert_includes dispatcher_source, "require_relative 'select-g0-governance-consumer'"
    assert_equal 1, dispatcher_source.scan('ReadOnlyResolver.resolve_active!').length
  end

  private

  def run_dispatch(*argv, env: {})
    run_cli(DISPATCHER, *argv, env: env)
  end

  def run_dispatch_in_process(*argv, env: {})
    stdout = StringIO.new
    stderr = StringIO.new
    exit_code = Dispatcher.run_cli(
      argv, stdout: stdout, stderr: stderr,
      env: ENV.to_h.merge(env)
    )
    { stdout: stdout.string, stderr: stderr.string, exit: exit_code }
  end

  def run_cli(script, *argv, env: {})
    stdout, stderr, status = Open3.capture3(env, RbConfig.ruby, script, *argv, chdir: ROOT)
    { stdout: stdout, stderr: stderr, exit: status.exitstatus }
  end

  def candidate_integrity(candidate)
    run_dispatch(
      '--profile', 'v2', '--mode', 'integrity', '--source', 'candidate',
      '--candidate-bundle', candidate, '--adoption-decision', ADOPTION
    )
  end

  def parse_stdout_receipt(result)
    assert_empty result.fetch(:stderr), result.inspect
    lines = result.fetch(:stdout).lines
    assert_equal 1, lines.length, result.inspect
    JSON.parse(lines.fetch(0))
  end

  def parse_failure_receipt(result)
    lines = result.fetch(:stdout).lines
    assert_equal 1, lines.length, result.inspect
    JSON.parse(lines.fetch(0))
  end

  def assert_receipt(receipt, profile:, mode:, status:, source: 'candidate')
    assert_equal RECEIPT_KEYS.sort, receipt.keys.sort
    assert_equal 1, receipt.fetch('schema_version')
    assert_equal profile, receipt.fetch('profile')
    assert_equal mode, receipt.fetch('mode')
    assert_equal source, receipt.fetch('source')
    assert_equal status, receipt.fetch('status')
    assert_equal true, receipt.fetch('secret_scan_passed')
    assert_empty Core.secret_locations(receipt.reject { |key, _value| key == 'secret_scan_passed' })
    assert_equal 64, receipt.fetch('adoption_sha256').length if receipt.fetch('adoption_sha256')
    if source == 'candidate' || status == 'FAIL' || (source == 'active' && status == 'OPEN' && receipt.fetch('adoption_sha256').nil?)
      %w[activation_sha256 prior_pointer_sha256 selection_sha256].each do |field|
        assert_nil receipt.fetch(field)
      end
    end
    receipt
  end

  def with_selector_layout
    Dir.mktmpdir('g0-wave5-dispatch-root-', '/private/tmp') do |root|
      File.chmod(0o700, root)
      [
        G0GovernanceConsumerSelector::PHASE0_RELATIVE_PATH,
        G0GovernanceConsumerSelector::SELECTIONS_RELATIVE_PATH,
        G0GovernanceConsumerSelector::DECISIONS_RELATIVE_PATH,
        G0GovernanceConsumerSelector::JOURNAL_RELATIVE_PATH
      ].each { |relative| FileUtils.mkdir_p(File.join(root, relative)) }
      yield root
    end
  end

  def v1_current_hashes
    manifest = Core.parse_json_file(V1_MANIFEST)
    manifest.fetch('files').to_h do |entry|
      path = File.join(ROOT, entry.fetch('path'))
      [entry.fetch('path'), Digest::SHA256.file(path).hexdigest]
    end
  end

  def directory_hashes(directory)
    Dir.children(directory).sort.to_h do |name|
      path = File.join(directory, name)
      [name, Digest::SHA256.file(path).hexdigest]
    end
  end

  def authority_path_inventory
    patterns = %w[
      G0_GOVERNANCE_CONSUMER_POINTER.json
      G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json
      G0_GOVERNANCE_CONSUMER_SELECTIONS
      G0_GOVERNANCE_V2_ACTIVATION_DECISIONS
      G0_GOVERNANCE_CONSUMER_JOURNAL
      G0_GOVERNANCE_CONSUMER_SELECTION.lock
    ]
    Dir.glob(File.join(PHASE, '**', '*'), File::FNM_DOTMATCH).select do |path|
      patterns.any? { |pattern| File.basename(path).include?(pattern) }
    end.sort.to_h do |path|
      stat = File.lstat(path)
      digest = stat.file? ? Digest::SHA256.file(path).hexdigest : nil
      [path, [stat.ftype, digest]]
    end
  end

  def with_candidate_copy
    Dir.mktmpdir('g0-wave4-candidate-copy-', '/private/tmp') do |temp|
      candidate = File.join(temp, 'candidate')
      FileUtils.cp_r(@candidate, candidate)
      yield candidate
    end
  end
end
