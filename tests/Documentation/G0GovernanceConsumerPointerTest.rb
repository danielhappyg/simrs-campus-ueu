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
require_relative '../../scripts/select-g0-governance-consumer'

class G0GovernanceConsumerPointerTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/select-g0-governance-consumer.rb')
  Core = G0ProportionalGovernanceV2
  Generator = G0ProportionalGovernanceV2Generator
  Selector = G0GovernanceConsumerSelector
  FIXED_TIME = Time.utc(2026, 8, 29, 6, 0, 0)
  TEMP_PARENT = File.realpath(Dir.tmpdir)

  EXTRA_FIXTURE_PATHS = %w[
    docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json
    docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md
    docs/new-simrs-rebuild/G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json
    docs/operations/G0_GOVERNANCE_V2_LOCAL_OBSERVATION_2026-08-29.json
    scripts/select-g0-governance-consumer.rb
  ].freeze

  class << self
    attr_reader :base_fixture

    def ensure_base_fixture!
      return if @base_fixture

      fixture = Dir.mktmpdir('g0-wave5-base-', TEMP_PARENT)
      File.chmod(0o700, fixture)
      paths = (Core::V1_EXPECTED_INVENTORY.map(&:first) + EXTRA_FIXTURE_PATHS).uniq
      paths.each { |relative| copy_fixture_path(fixture, relative) }
      # `verify_v1_manifest!` deliberately reads baseline bytes with `git show`.
      # A plain gitdir file gives the isolated fixture read-only object access
      # without copying or symlinking the repository metadata into the fixture.
      File.binwrite(File.join(fixture, '.git'), "gitdir: #{File.join(ROOT, '.git')}\n")
      phase = File.join(fixture, Selector::PHASE0_RELATIVE_PATH)
      [Selector::SELECTIONS_RELATIVE_PATH, Selector::DECISIONS_RELATIVE_PATH,
       Selector::JOURNAL_RELATIVE_PATH].each { |relative| FileUtils.mkdir_p(File.join(fixture, relative)) }
      preflight = File.join(fixture, 'docs/operations/G0_GOVERNANCE_V2_CANONICAL_PREFLIGHT.json')
      FileUtils.mkdir_p(File.dirname(preflight))
      File.binwrite(preflight, Core.canonical_json({
        'artifact_type' => 'g0_governance_v2_canonical_preflight',
        'schema_version' => 1,
        'status' => 'pass_fixture_only',
        'authority_effect' => 'none'
      }) + "\n")
      Generator.generate!(root: fixture, output: File.join(fixture, 'candidate'))
      raise 'base fixture contains an active pointer' if File.exist?(File.join(fixture, Selector::POINTER_RELATIVE_PATH))
      raise 'base fixture root mode drifted' unless File.stat(fixture).mode & 0o777 == 0o700
      raise 'base fixture phase missing' unless File.directory?(phase)
      @base_fixture = fixture
    rescue StandardError
      FileUtils.remove_entry_secure(fixture) if fixture && File.exist?(fixture)
      raise
    end

    def copy_fixture_path(destination_root, relative)
      source = File.join(ROOT, relative)
      destination = File.join(destination_root, relative)
      FileUtils.mkdir_p(File.dirname(destination))
      FileUtils.cp_r(source, destination, preserve: true)
    end

    def cleanup_base_fixture!
      FileUtils.remove_entry_secure(@base_fixture) if @base_fixture && File.exist?(@base_fixture)
    end
  end

  Minitest.after_run { cleanup_base_fixture! }

  def setup
    self.class.ensure_base_fixture!
    @canonical_before = canonical_authority_inventory
    @root = Dir.mktmpdir('g0-wave5-test-', TEMP_PARENT)
    File.chmod(0o700, @root)
    clone_base_fixture!
  end

  def teardown
    if @canonical_before
      assert_equal @canonical_before, canonical_authority_inventory,
                   'fixture-only selector tests must never mutate canonical checkout authority paths'
    end
    FileUtils.remove_entry_secure(@root) if @root && File.exist?(@root)
  end

  def test_filesystem_probe_root_guard_and_canonical_checkout_prohibition
    assert Selector::CapabilityProbe.prove!(Selector::ReadOnlyResolver.canonical_paths!(Pathname.new(@root)), env: guarded_env)
    assert_raises(Selector::UsageError) do
      Selector::PathGuard.resolve_root!(@root, env: {}, mutation: true)
    end
    File.chmod(0o755, @root)
    assert_raises(Selector::UsageError) do
      Selector::PathGuard.resolve_root!(@root, env: guarded_env, mutation: true)
    end
    File.chmod(0o700, @root)
    error = assert_raises(Selector::UsageError) do
      Selector::PathGuard.resolve_root!(ROOT, env: guarded_env, mutation: true)
    end
    assert_equal 'canonical_checkout_mutation_prohibited', error.reason_code
  end

  def test_canonical_checkout_is_blocked_by_unprovisioned_external_attestation_before_lock_probe_marker_or_receipt
    receipt = File.join(@root, 'canonical-trust-guard-receipt.json')
    options = {
      operation: 'activate',
      activation_decision: File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISION_DRAFT_2026-08-29.json'),
      candidate_bundle: @candidate,
      prior_state: 'initial_state',
      json_receipt: receipt
    }
    before = canonical_authority_inventory
    never_lock = ->(*) { flunk('canonical trust guard reached the stable lock') }
    never_probe = ->(*) { flunk('canonical trust guard reached the capability probe') }
    never_marker = ->(*) { flunk('canonical trust guard reached marker reconciliation') }
    Selector.stub(:with_exclusive_lock, never_lock) do
      Selector::CapabilityProbe.stub(:prove!, never_probe) do
        Selector.stub(:reconcile_recovery_marker!, never_marker) do
          error = assert_raises(Selector::ValidationFailure) do
            Selector.execute!(options, root: ROOT, env: {}, clock: clock)
          end
          assert_equal 'canonical_external_attestation_unprovisioned', error.reason_code
        end
      end
    end
    assert_equal before, canonical_authority_inventory
    refute File.exist?(receipt)
  end

  def test_initial_activation_binds_exact_decision_bundle_actor_environment_expiry_conditions_and_hashes
    decision_path, options, decision = activation_operation
    result = Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
    resolution = Selector::ReadOnlyResolver.resolve_active!(root: @root, env: guarded_env)

    assert_equal Selector::SELECTION_KEYS.sort, result.fetch(:selection).keys.sort
    assert_equal Selector::JOURNAL_KEYS.sort, result.fetch(:journal).keys.sort
    assert_equal Selector::POINTER_KEYS.sort, result.fetch(:pointer).keys.sort
    assert_equal Selector::RECEIPT_KEYS.sort, result.fetch(:receipt).keys.sort
    assert_equal %w[activation active v2], result.fetch(:selection).values_at('kind', 'status', 'profile')
    assert_equal %w[active v2], result.fetch(:pointer).values_at('status', 'profile')
    assert_equal 1, result.fetch(:pointer).fetch('revision')
    assert_nil result.fetch(:pointer).fetch('predecessor_pointer_sha256')
    assert_equal reference(decision_path), result.fetch(:selection).fetch('operation_decision')
    assert_equal reference(decision_path).fetch('sha256'), result.fetch(:receipt).fetch('activation_sha256')
    assert_nil result.fetch(:receipt).fetch('prior_pointer_sha256')
    assert_equal decision.fetch('actor').fetch('identity'), result.fetch(:receipt).fetch('actor')
    assert_equal reference(@candidate), result.fetch(:selection).fetch('selected_bundle')
    assert_equal Digest::SHA256.file(pointer_path).hexdigest, resolution.fetch(:pointer_sha256)
    assert_equal result.fetch(:selection), resolution.fetch(:selection)
    assert_equal Selector::SUCCESS_REASON, result.fetch(:receipt).fetch('reason_code')
    assert result.fetch(:receipt).fetch('secret_scan_passed')
  end

  def test_operation_decision_drift_expiry_prior_state_and_secret_content_fail_before_authority
    mutations = {
      environment: ->(row) { row['environment'] = 'canonical_checkout' },
      actor: ->(row) { row['actor']['identity'] = 'Arbitrary Actor' },
      expiry: ->(row) { row['expires_at'] = (FIXED_TIME - 1).iso8601 },
      conditions: ->(row) { row['conditions'] = ["pass#{'word'}=fixture-sentinel"] },
      adoption: ->(row) { row['adoption_decision']['sha256'] = '0' * 64 },
      prior_state: ->(row) { row['prior_state']['prior_state_reason'] = 'missing_pointer' },
      target_hash: ->(row) { row['candidate_bundle']['sha256'] = '0' * 64 },
      unknown: ->(row) { row['unexpected'] = true }
    }

    mutations.each do |label, mutation|
      reset_fixture!
      decision_path, options, decision = activation_operation(id: "invalid-#{label}")
      mutation.call(decision)
      write_json(decision_path, decision)
      before = authority_inventory(@root)
      error = assert_raises(Selector::ValidationFailure, label.to_s) do
        Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
      end
      refute_includes error.reason_code, 'fixture-sentinel'
      assert_equal before, authority_inventory(@root), label.to_s
      refute File.exist?(options.fetch(:json_receipt)), label.to_s
    end
  end

  def test_static_decision_attribution_evidence_environment_and_target_failures_precede_probe_lock_and_writes
    mutations = {
      pending: ->(row) { row['status'] = 'pending' },
      cross_environment: ->(row) { row['environment'] = 'local_canonical_checkout' },
      stale_evidence: ->(row) { row['technical_evidence']['canonical_preflight']['sha256'] = '0' * 64 },
      forged_attribution: lambda do |row|
        row['decision_attribution']['decision_message'] = "#{row['decision_attribution']['decision_message']} forged"
      end,
      forged_reference: ->(row) { row['decision_attribution']['decision_reference'] = 'ticket:unbound' },
      attribution_basis: ->(row) { row['decision_attribution']['recorded_at_basis'] = 'git_author' },
      secret: ->(row) { row['conditions'] = ["pass#{'word'}=fixture-sentinel"] },
      candidate_mismatch: ->(row) { row['technical_evidence']['candidate_bundle'] = row['technical_evidence']['local_observation'] },
      expired: ->(row) { row['expires_at'] = (FIXED_TIME - 1).iso8601 }
    }

    mutations.each do |label, mutation|
      reset_fixture!
      decision_path, options, decision = activation_operation(id: "static-#{label}")
      mutation.call(decision)
      write_json(decision_path, decision)
      before = authority_inventory(@root)
      lock_before = path_inventory(File.join(@root, Selector::LOCK_RELATIVE_PATH))
      probe = ->(*) { flunk("#{label}: capability probe ran before static rejection") }
      Selector::CapabilityProbe.stub(:prove!, probe) do
        assert_raises(Selector::ValidationFailure, label.to_s) do
          Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
        end
      end
      assert_equal before, authority_inventory(@root), label.to_s
      observed_lock = path_inventory(File.join(@root, Selector::LOCK_RELATIVE_PATH))
      lock_before.nil? ? assert_nil(observed_lock, label.to_s) : assert_equal(lock_before, observed_lock, label.to_s)
      refute File.exist?(options.fetch(:json_receipt)), label.to_s
    end
  end

  def test_stable_lock_existing_state_is_closed_before_probe_or_authority_write
    invalid_lock_states = {
      nonzero: ->(path) { File.binwrite(path, "occupied\n") },
      wrong_mode: lambda do |path|
        File.binwrite(path, '')
        File.chmod(0o644, path)
      end,
      directory: ->(path) { Dir.mkdir(path) }
    }

    invalid_lock_states.each do |label, setup_lock|
      reset_fixture!
      _decision, options, = activation_operation(id: "lock-#{label}")
      lock_path = File.join(@root, Selector::LOCK_RELATIVE_PATH)
      setup_lock.call(lock_path)
      before = authority_inventory(@root)
      probe = ->(*) { flunk("#{label}: capability probe ran with invalid stable lock") }
      Selector::CapabilityProbe.stub(:prove!, probe) do
        error = assert_raises(Selector::ValidationFailure, label.to_s) do
          Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
        end
        assert_equal 'lock_path_invalid', error.reason_code
      end
      assert_equal before, authority_inventory(@root), label.to_s
    end
  end

  def test_full_static_authority_is_revalidated_after_lock_before_probe_or_recovery_reconciliation
    decision_path, options, decision = activation_operation(id: 'post-lock-revalidation')
    evidence_path = File.join(@root, decision.dig('technical_evidence', 'local_observation', 'path'))
    original_lock = Selector.method(:with_exclusive_lock)
    wrapped_lock = lambda do |lock_path, &operation|
      original_lock.call(lock_path) do
        File.binwrite(evidence_path, "{\"artifact_type\":\"tampered-after-lock\"}\n")
        operation.call
      end
    end
    probe = ->(*) { flunk('capability probe ran before post-lock revalidation rejected evidence drift') }
    before = authority_inventory(@root)
    Selector.stub(:with_exclusive_lock, wrapped_lock) do
      Selector::CapabilityProbe.stub(:prove!, probe) do
        error = assert_raises(Selector::ValidationFailure) do
          Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
        end
        assert_equal 'operation_decision_invalid', error.reason_code
      end
    end
    assert_equal before, authority_inventory(@root)
    refute File.exist?(options.fetch(:json_receipt))
    assert File.file?(decision_path)
  end

  def test_operation_and_approval_sha_replay_is_rejected_from_journal_and_durable_orphan_selection
    completed = activate_once!(id: 'history-replay-base')
    paths = Selector::ReadOnlyResolver.canonical_paths!(Pathname.new(@root))
    history = Selector::Journal.load!(paths, Pathname.new(@root))
    operation_reference = completed.fetch(:selection).fetch('operation_decision')
    approval_reference = completed.fetch(:selection).fetch('operation_approval')
    operation_error = assert_raises(Selector::ValidationFailure) do
      Selector::HistoryReplay.assert_unused!(paths, history, operation_reference, {
        'path' => 'different-approval.json', 'sha256' => 'f' * 64
      })
    end
    assert_equal 'operation_decision_replay', operation_error.reason_code
    approval_error = assert_raises(Selector::ValidationFailure) do
      Selector::HistoryReplay.assert_unused!(paths, history, {
        'path' => 'different-decision.json', 'sha256' => 'e' * 64
      }, approval_reference)
    end
    assert_equal 'operation_decision_replay', approval_error.reason_code

    _decision_path, options, document = activation_operation(id: 'durable-orphan', prior: valid_prior)
    fault_env = guarded_env.merge(Selector::TEST_FAULT_ENV => 'after_selection_directory_fsync')
    assert_raises(Selector::DurabilityFailure) do
      Selector.execute!(options, root: @root, env: fault_env, clock: clock)
    end
    replay = assert_raises(Selector::ValidationFailure) do
      Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
    end
    assert_equal 'operation_decision_replay', replay.reason_code

    approval_replay_path = File.join(decisions_dir, 'durable-orphan-approval-replay.json')
    approval_replay = JSON.parse(JSON.generate(document))
    approval_replay['decision_id'] = 'G0-V2-OP-DURABLE-ORPHAN-APPROVAL-REPLAY'
    write_json(approval_replay_path, approval_replay)
    approval_options = options.merge(
      activation_decision: approval_replay_path,
      json_receipt: File.join(@root, 'receipts/durable-orphan-approval-replay.json')
    )
    approval_replay_error = assert_raises(Selector::ValidationFailure) do
      Selector.execute!(approval_options, root: @root, env: guarded_env, clock: clock)
    end
    assert_equal 'operation_decision_replay', approval_replay_error.reason_code
  end

  def test_traversal_intermediate_and_final_symlinks_nonregular_files_and_unsafe_roots_fail_closed
    cases = []
    decision_path, options, = activation_operation(id: 'path-base')
    cases << ['traversal', options.merge(activation_decision: File.join(File.dirname(decision_path), '..', File.basename(decision_path)))]

    outside = Dir.mktmpdir('g0-wave5-outside-', TEMP_PARENT)
    begin
      symlink_decision = File.join(decisions_dir, 'symlink.json')
      File.symlink(decision_path, symlink_decision)
      cases << ['final symlink', options.merge(activation_decision: symlink_decision)]
      nonregular = File.join(decisions_dir, 'directory.json')
      Dir.mkdir(nonregular)
      cases << ['nonregular', options.merge(activation_decision: nonregular)]
      candidate_link = File.join(@root, 'candidate-link')
      File.symlink(@candidate, candidate_link)
      cases << ['candidate symlink', options.merge(candidate_bundle: candidate_link)]

      cases.each do |label, candidate_options|
        before = authority_inventory(@root)
        assert_raises(Selector::ValidationFailure, label) do
          Selector.execute!(candidate_options, root: @root, env: guarded_env, clock: clock)
        end
        assert_equal before, authority_inventory(@root), label
      end

      real_decisions = "#{decisions_dir}.real"
      File.rename(decisions_dir, real_decisions)
      File.symlink(real_decisions, decisions_dir)
      before = authority_inventory(@root)
      assert_raises(Selector::ValidationFailure, 'intermediate symlink') do
        Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
      end
      assert_equal before, authority_inventory(@root)
      File.unlink(decisions_dir)
      File.rename(real_decisions, decisions_dir)

      linked_root = File.join(outside, 'linked-root')
      File.symlink(@root, linked_root)
      assert_raises(Selector::UsageError) do
        Selector.execute!(options, root: linked_root, env: guarded_env, clock: clock)
      end
    ensure
      FileUtils.remove_entry_secure(outside) if File.exist?(outside)
    end
  end

  def test_capability_probe_and_cross_device_failures_create_no_authority
    %w[filesystem_capability_probe_failed filesystem_cross_device].each do |reason|
      reset_fixture!
      _decision_path, options, = activation_operation(id: reason)
      before = authority_inventory(@root)
      failure = ->(*) { raise Selector::CapabilityFailure, reason }
      Selector::CapabilityProbe.stub(:prove!, failure) do
        error = assert_raises(Selector::CapabilityFailure) do
          Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
        end
        assert_equal reason, error.reason_code
      end
      assert_equal before, authority_inventory(@root)
      refute File.exist?(options.fetch(:json_receipt))
    end
  end

  def test_activate_rollback_disable_and_recover_option_matrix_is_closed
    sha = 'a' * 64
    common = { activation_decision: '/fixture/decision.json', json_receipt: '/fixture/receipt.json' }
    valid = [
      common.merge(operation: 'activate', prior_state: 'initial_state', candidate_bundle: '/fixture/candidate'),
      common.merge(operation: 'activate', prior_state: 'valid_pointer', expected_pointer_sha256: sha,
                   candidate_bundle: '/fixture/candidate'),
      common.merge(operation: 'rollback', prior_state: 'valid_pointer', expected_pointer_sha256: sha),
      common.merge(operation: 'disable', prior_state: 'valid_pointer', expected_pointer_sha256: sha),
      common.merge(operation: 'recover', prior_state: 'missing_pointer', recover_outcome: 'held',
                   recover_selection: '/fixture/selection.json', recover_selection_sha256: sha),
      common.merge(operation: 'recover', prior_state: 'unreadable_pointer',
                   observed_unreadable_pointer_sha256: sha, recover_outcome: 'disabled')
    ]
    valid.each { |options| assert Selector.validate_option_matrix!(options), options.inspect }

    invalid = [
      {},
      common.merge(operation: 'activate', prior_state: 'initial_state'),
      common.merge(operation: 'activate', prior_state: 'missing_pointer', candidate_bundle: '/fixture/candidate'),
      common.merge(operation: 'activate', prior_state: 'initial_state', candidate_bundle: '/fixture/candidate',
                   recover_outcome: 'held'),
      common.merge(operation: 'rollback', prior_state: 'valid_pointer'),
      common.merge(operation: 'rollback', prior_state: 'valid_pointer', expected_pointer_sha256: sha,
                   candidate_bundle: '/fixture/candidate'),
      common.merge(operation: 'disable', prior_state: 'initial_state'),
      common.merge(operation: 'disable', prior_state: 'valid_pointer', expected_pointer_sha256: sha,
                   recover_selection: '/fixture/selection.json'),
      common.merge(operation: 'recover', prior_state: 'missing_pointer'),
      common.merge(operation: 'recover', prior_state: 'valid_pointer', expected_pointer_sha256: sha,
                   recover_outcome: 'disabled'),
      common.merge(operation: 'recover', prior_state: 'missing_pointer', recover_outcome: 'held'),
      common.merge(operation: 'recover', prior_state: 'missing_pointer', recover_outcome: 'disabled',
                   recover_selection: '/fixture/selection.json', recover_selection_sha256: sha),
      common.merge(operation: 'recover', prior_state: 'unreadable_pointer', recover_outcome: 'disabled'),
      common.merge(operation: 'recover', prior_state: 'unreadable_pointer',
                   observed_unreadable_pointer_sha256: sha, recover_outcome: 'other')
    ]
    invalid.each do |options|
      assert_raises(Selector::UsageError, options.inspect) { Selector.validate_option_matrix!(options) }
    end
  end

  def test_all_pre_rename_durability_faults_leave_old_pointer_byte_identical
    pre_rename = Selector::FAULT_POINTS.take_while { |point| point != 'after_pointer_rename' }
    pre_rename.each do |point|
      reset_fixture!
      activate_once!(id: "baseline-#{point}")
      old_pointer = File.binread(pointer_path)
      _decision_path, options, = activation_operation(id: "fault-#{point}", prior: valid_prior)
      env = guarded_env.merge(Selector::TEST_FAULT_ENV => point)
      assert_raises(Selector::DurabilityFailure, point) do
        Selector.execute!(options, root: @root, env: env, clock: clock)
      end
      assert_equal old_pointer, File.binread(pointer_path), point
      assert_equal 'active', Selector::ReadOnlyResolver.resolve_active!(root: @root, env: guarded_env)
                                                   .fetch(:pointer).fetch('status'), point
      refute Dir.children(phase_dir).any? { |name| name.match?(/\.tmp\z/) }, point
      refute File.exist?(options.fetch(:json_receipt)), point
      if point == 'after_journal_create'
        paths = Selector::ReadOnlyResolver.canonical_paths!(Pathname.new(@root))
        Selector::Journal.load!(paths, Pathname.new(@root))
      end
    end
  end

  def test_post_rename_faults_fail_closed_until_explicit_recovery
    %w[after_pointer_rename after_pointer_directory_fsync after_pointer_readback].each do |point|
      reset_fixture!
      baseline = activate_once!(id: "post-baseline-#{point}")
      old_pointer = File.binread(pointer_path)
      _decision_path, options, = activation_operation(id: "post-fault-#{point}", prior: valid_prior)
      env = guarded_env.merge(Selector::TEST_FAULT_ENV => point)
      assert_raises(Selector::DurabilityFailure, point) do
        Selector.execute!(options, root: @root, env: env, clock: clock)
      end
      refute_equal old_pointer, File.binread(pointer_path), point
      assert_raises(Selector::ResolutionError, point) do
        Selector::ReadOnlyResolver.resolve_active!(root: @root, env: guarded_env)
      end

      raw = File.binread(pointer_path)
      File.binwrite(pointer_path, raw + " ") unless raw.end_with?(' ')
      observed = Digest::SHA256.file(pointer_path).hexdigest
      target_path = selection_path_for(baseline.fetch(:selection))
      _recover_decision, recover_options, = recovery_operation(
        id: "recover-#{point}", outcome: 'held', prior: unreadable_prior(observed), target: target_path
      )
      recovered = Selector.execute!(recover_options, root: @root, env: guarded_env, clock: clock)
      assert_equal %w[recovery_hold held v2], recovered.fetch(:selection).values_at('kind', 'status', 'profile')
      error = assert_raises(Selector::ResolutionError) do
        Selector::ReadOnlyResolver.resolve_active!(root: @root, env: guarded_env)
      end
      assert_equal 'pointer_held', error.reason_code
    end
  end

  def test_hard_process_crashes_do_not_poison_journal_or_publish_unverified_authority
    activate_once!(id: 'crash-journal-base')
    old_pointer = File.binread(pointer_path)
    _decision, options, = activation_operation(id: 'hard-crash-journal', prior: valid_prior)
    status = run_crashing_child(options, 'after_journal_create')
    assert_equal 77, status.exitstatus
    assert_equal old_pointer, File.binread(pointer_path)
    paths = Selector::ReadOnlyResolver.canonical_paths!(Pathname.new(@root))
    _prior, resolution, = Selector::PriorState.derive!(Pathname.new(@root), paths, env: guarded_env)
    refute_nil resolution, 'restart must ignore/quarantine a partial trailing journal candidate'

    reset_fixture!
    activate_once!(id: 'crash-pointer-base')
    _decision, options, = activation_operation(id: 'hard-crash-pointer', prior: valid_prior)
    status = run_crashing_child(options, 'after_pointer_rename')
    assert_equal 77, status.exitstatus
    error = assert_raises(Selector::ResolutionError) do
      Selector::ReadOnlyResolver.resolve_active!(root: @root, env: guarded_env)
    end
    assert_equal 'pointer_recovery_required', error.reason_code
  end

  def test_prior_state_derivation_records_missing_and_unreadable_facts_without_raw_content
    paths = Selector::ReadOnlyResolver.canonical_paths!(Pathname.new(@root))
    initial, = Selector::PriorState.derive!(Pathname.new(@root), paths, env: guarded_env)
    assert_equal initial_prior, initial

    activate_once!
    File.unlink(pointer_path)
    missing, = Selector::PriorState.derive!(Pathname.new(@root), paths, env: guarded_env)
    assert_equal missing_prior, missing

    raw = "{broken-pointer:pass#{'word'}=fixture-sentinel}\n"
    File.binwrite(pointer_path, raw)
    unreadable, = Selector::PriorState.derive!(Pathname.new(@root), paths, env: guarded_env)
    assert_equal unreadable_prior(Digest::SHA256.hexdigest(raw)), unreadable
    refute_includes JSON.generate(unreadable), raw
  end

  def test_rollback_disable_and_recovery_publish_new_closed_selections_and_never_v1
    first = activate_once!(id: 'activation-one')
    first_path = selection_path_for(first.fetch(:selection))
    activate_once!(id: 'activation-two', prior: valid_prior)
    rollback = rollback_operation(id: 'rollback', target: first_path)
    rolled = Selector.execute!(rollback.fetch(:options), root: @root, env: guarded_env, clock: clock)
    assert_equal %w[rollback_hold held v2], rolled.fetch(:selection).values_at('kind', 'status', 'profile')
    refute_equal reference(first_path), rolled.fetch(:pointer).fetch('selection')
    assert_equal reference(first_path), rolled.fetch(:selection).fetch('held_predecessor_selection')
    assert_equal 'force_g0_g3_open_until_fresh_activation', rolled.fetch(:selection).fetch('gate_effect')
    refute_equal 'v1', rolled.fetch(:selection).fetch('profile')

    reset_fixture!
    activate_once!(id: 'disable-base')
    disabled_op = disable_operation(id: 'disable')
    disabled = Selector.execute!(disabled_op.fetch(:options), root: @root, env: guarded_env, clock: clock)
    assert_equal ['disabled', 'disabled', nil], disabled.fetch(:selection).values_at('kind', 'status', 'profile')
    assert_nil disabled.fetch(:selection).fetch('selected_bundle')
    assert_nil disabled.fetch(:pointer).fetch('profile')

    reset_fixture!
    active = activate_once!(id: 'recover-held-base')
    active_path = selection_path_for(active.fetch(:selection))
    File.unlink(pointer_path)
    held_op = recovery_operation(id: 'recover-held', outcome: 'held', prior: missing_prior, target: active_path)
    held = Selector.execute!(held_op.fetch(1), root: @root, env: guarded_env, clock: clock)
    assert_equal %w[recovery_hold held v2], held.fetch(:selection).values_at('kind', 'status', 'profile')

    reset_fixture!
    activate_once!(id: 'recover-disabled-base')
    File.binwrite(pointer_path, "not-json\n")
    observed = Digest::SHA256.file(pointer_path).hexdigest
    disabled_recovery = recovery_operation(id: 'recover-disabled', outcome: 'disabled', prior: unreadable_prior(observed))
    recovered_disabled = Selector.execute!(disabled_recovery.fetch(1), root: @root, env: guarded_env, clock: clock)
    assert_equal ['disabled', 'disabled', nil], recovered_disabled.fetch(:selection).values_at('kind', 'status', 'profile')
    refute_match(/v1/i, JSON.generate(recovered_disabled))
  end

  def test_stale_authorization_and_journal_hash_chain_tampering_fail_closed
    activate_once!(id: 'chain-one')
    stale_prior = valid_prior
    activate_once!(id: 'chain-two', prior: stale_prior)
    old_pointer = File.binread(pointer_path)
    _decision_path, stale_options, = activation_operation(id: 'stale', prior: stale_prior)
    assert_raises(Selector::ValidationFailure) do
      Selector.execute!(stale_options, root: @root, env: guarded_env, clock: clock)
    end
    assert_equal old_pointer, File.binread(pointer_path)

    records = Dir.children(journal_dir).sort.map { |name| File.join(journal_dir, name) }
    assert_equal 2, records.length
    first_sha = Digest::SHA256.file(records.first).hexdigest
    assert_nil JSON.parse(File.binread(records.first)).fetch('prior_journal_sha256')
    assert_equal first_sha, JSON.parse(File.binread(records.last)).fetch('prior_journal_sha256')
    File.binwrite(records.first, File.binread(records.first) + " ")
    _third_decision, third_options, = activation_operation(id: 'chain-three', prior: valid_prior)
    assert_raises(Selector::ValidationFailure) do
      Selector.execute!(third_options, root: @root, env: guarded_env, clock: clock)
    end
    assert_equal old_pointer, File.binread(pointer_path)
  end

  def test_two_concurrent_processes_have_exactly_one_winner_and_no_loser_artifacts
    _decision_path, options, = activation_operation(id: 'concurrent')
    winner_options = options.merge(json_receipt: File.join(@root, 'receipts', 'winner.json'))
    loser_options = options.merge(json_receipt: File.join(@root, 'receipts', 'loser.json'))
    ready_r, ready_w = IO.pipe
    release_r, release_w = IO.pipe
    result_r, result_w = IO.pipe

    pid = fork do
      ready_r.close
      release_w.close
      result_r.close
      begin
        hook = lambda do |point|
          next unless point == 'after_selection_create'
          ready_w.write('1')
          ready_w.close
          release_r.read(1)
        end
        Selector.execute!(winner_options, root: @root, env: guarded_env, clock: clock, fault_hook: hook)
        result_w.write('PASS')
      rescue StandardError => e
        result_w.write("#{e.class}:#{e.message}")
      ensure
        result_w.close
        exit! 0
      end
    end
    ready_w.close
    release_r.close
    result_w.close
    assert_equal '1', ready_r.read(1)
    loser_out = StringIO.new
    loser_err = StringIO.new
    loser_exit = Selector.run_cli(
      cli_argv(loser_options), stdout: loser_out, stderr: loser_err, env: guarded_env, clock: clock
    )
    assert_equal Selector::EXIT_CONFLICT, loser_exit
    assert_empty loser_out.string
    assert_equal "selection_conflict: #{Selector::CONFLICT_REASON}\n", loser_err.string
    refute File.exist?(loser_options.fetch(:json_receipt))
    release_w.write('1')
    release_w.close
    Process.wait(pid)
    assert_equal 'PASS', result_r.read
    assert_equal 1, Dir.children(selections_dir).length
    assert_equal 1, Dir.children(journal_dir).length
    assert File.file?(winner_options.fetch(:json_receipt))
    refute Dir.children(phase_dir).any? { |name| name.end_with?('.tmp') }
  ensure
    [ready_r, ready_w, release_r, release_w, result_r, result_w].compact.each { |io| io.close unless io.closed? }
    Process.wait(pid) if pid && Process.waitpid(pid, Process::WNOHANG).nil? rescue nil
  end

  def test_exclusive_receipts_stable_cli_exits_and_secret_safe_diagnostics
    _decision_path, options, = activation_operation(id: 'cli')
    File.binwrite(options.fetch(:json_receipt), "occupied\n")
    before = authority_inventory(@root)
    out = StringIO.new
    err = StringIO.new
    exit_code = Selector.run_cli(cli_argv(options), stdout: out, stderr: err, env: guarded_env, clock: clock)
    assert_equal Selector::EXIT_FAILURE, exit_code
    assert_empty out.string
    assert_match(/\Aselection_failed: [a-z0-9_]+\n\z/, err.string)
    assert_equal before, authority_inventory(@root)

    usage_out = StringIO.new
    usage_err = StringIO.new
    usage = Selector.run_cli(['--operation', 'activate'], stdout: usage_out, stderr: usage_err, env: guarded_env, clock: clock)
    assert_equal Selector::EXIT_USAGE, usage
    assert_empty usage_out.string
    assert_equal "usage_error: invalid command-line usage\n", usage_err.string

    File.unlink(options.fetch(:json_receipt))
    success_out = StringIO.new
    success_err = StringIO.new
    success = Selector.run_cli(cli_argv(options), stdout: success_out, stderr: success_err, env: guarded_env, clock: clock)
    assert_equal Selector::EXIT_SUCCESS, success
    assert_empty success_err.string
    assert_equal JSON.parse(success_out.string), JSON.parse(File.binread(options.fetch(:json_receipt)))
  end

  private

  def clone_base_fixture!
    Dir.children(self.class.base_fixture).each do |name|
      FileUtils.cp_r(File.join(self.class.base_fixture, name), File.join(@root, name), preserve: true)
    end
    File.chmod(0o700, @root)
    @candidate = File.join(@root, 'candidate')
    @tick = 0
    FileUtils.mkdir_p(File.join(@root, 'receipts'))
  end

  def reset_fixture!
    Dir.children(@root).each { |name| FileUtils.rm_rf(File.join(@root, name)) }
    clone_base_fixture!
  end

  def guarded_env
    { Selector::TEST_ROOT_GUARD => '1' }
  end

  def clock
    -> { FIXED_TIME + @tick }
  end

  def next_time!
    @tick += 10
    FIXED_TIME + @tick
  end

  def phase_dir
    File.join(@root, Selector::PHASE0_RELATIVE_PATH)
  end

  def pointer_path
    File.join(@root, Selector::POINTER_RELATIVE_PATH)
  end

  def selections_dir
    File.join(@root, Selector::SELECTIONS_RELATIVE_PATH)
  end

  def decisions_dir
    File.join(@root, Selector::DECISIONS_RELATIVE_PATH)
  end

  def journal_dir
    File.join(@root, Selector::JOURNAL_RELATIVE_PATH)
  end

  def contract
    @contract ||= JSON.parse(File.binread(File.join(@root, Selector::CONTRACT_RELATIVE_PATH)))
  end

  def adoption_reference
    contract.fetch('adopted_sources').fetch('adoption_decision')
  end

  def reference(path)
    target = Pathname.new(path)
    sha_path = target.directory? ? target.join(G0GovernanceV1V2Comparator::BUNDLE_FILE) : target
    {
      'path' => target.realpath.relative_path_from(Pathname.new(@root).realpath).to_s,
      'sha256' => Digest::SHA256.file(sha_path).hexdigest
    }
  end

  def initial_prior
    {
      'prior_state_reason' => 'initial_state',
      'expected_prior_pointer_sha256' => nil,
      'observed_unreadable_pointer_sha256' => nil
    }
  end

  def missing_prior
    initial_prior.merge('prior_state_reason' => 'missing_pointer')
  end

  def unreadable_prior(sha)
    {
      'prior_state_reason' => 'unreadable_pointer',
      'expected_prior_pointer_sha256' => nil,
      'observed_unreadable_pointer_sha256' => sha
    }
  end

  def valid_prior
    {
      'prior_state_reason' => 'valid_pointer',
      'expected_prior_pointer_sha256' => Digest::SHA256.file(pointer_path).hexdigest,
      'observed_unreadable_pointer_sha256' => nil
    }
  end

  def activation_operation(id: 'activate', prior: initial_prior)
    build_operation(id: id, operation: 'activate', prior: prior, candidate: reference(@candidate))
  end

  def rollback_operation(id:, target:)
    decision, options, document = build_operation(
      id: id, operation: 'rollback', prior: valid_prior, held: reference(target)
    )
    { decision: decision, options: options, document: document }
  end

  def disable_operation(id:)
    decision, options, document = build_operation(id: id, operation: 'disable', prior: valid_prior)
    { decision: decision, options: options, document: document }
  end

  def recovery_operation(id:, outcome:, prior:, target: nil)
    held = target && reference(target)
    build_operation(id: id, operation: 'recover', prior: prior, held: held, outcome: outcome)
  end

  def build_operation(id:, operation:, prior:, candidate: nil, held: nil, outcome: nil,
                      environment: 'isolated_test_fixture')
    now = next_time!
    path = File.join(decisions_dir, "#{id}.json")
    message = "Approve exactly one #{operation} operation for #{id}."
    message_sha = Digest::SHA256.hexdigest(message)
    adoption = JSON.parse(File.binread(File.join(@root, adoption_reference.fetch('path'))))
    decider = adoption.fetch('decider')
    thread_id = decider.fetch('decision_reference').match(/\Acodex_thread:([^#]+)#/)[1]
    decided_at = now - 90
    expires_at = now + 3600
    actor = {
      'identity' => decider.fetch('identity'),
      'authority_capacity' => decider.fetch('authority_capacity'),
      'decision_thread_id' => thread_id
    }
    attribution = {
      'decision_reference' => "codex_thread:#{thread_id}#gate-b-#{id}#decision-message-sha256:#{message_sha}",
      'decision_message' => message,
      'decision_message_encoding' => Selector::ATTRIBUTION_ENCODING,
      'decision_message_sha256' => message_sha,
      'source_message_at' => decided_at.iso8601,
      'recorded_at' => (now - 60).iso8601,
      'recorded_at_basis' => Selector::ATTRIBUTION_TIME_BASIS
    }
    document = {
      'artifact_type' => 'g0_governance_v2_consumer_operation_decision',
      'schema_version' => 1,
      'decision_id' => "G0-V2-OP-#{id.upcase}",
      'status' => 'approved',
      'effect' => 'authorizes_one_consumer_selection_operation',
      'data_boundary' => 'synthetic_only',
      'operation' => operation,
      'environment' => environment,
      'actor' => actor,
      'conditions' => ['fixture_only', 'no_canonical_checkout_mutation'],
      'decided_at' => decided_at.iso8601,
      'expires_at' => expires_at.iso8601,
      'adoption_decision' => adoption_reference,
      'approval_evidence' => nil,
      'prior_state' => prior,
      'candidate_bundle' => candidate,
      'held_selection' => held,
      'recover_outcome' => outcome,
      'decision_attribution' => attribution,
      'technical_evidence' => nil
    }

    contract_reference = reference(File.join(@root, Selector::CONTRACT_RELATIVE_PATH))
    selector_reference = reference(File.join(@root, 'scripts/select-g0-governance-consumer.rb'))
    candidate_evidence = reference(@candidate)
    common = {
      'schema_version' => 1,
      'status' => 'PASS',
      'data_boundary' => 'synthetic_only',
      'environment' => environment,
      'root' => Pathname.new(@root).realpath.to_s,
      'observed_at' => (decided_at - 60).iso8601,
      'expires_at' => expires_at.iso8601,
      'candidate_bundle' => candidate_evidence,
      'validator_contract' => contract_reference,
      'selector_source' => selector_reference,
      'prior_state' => prior,
      'authority_effect' => 'none'
    }
    observation_path = File.join(@root, "docs/operations/gate-b-#{id}-observation.json")
    preflight_path = File.join(@root, "docs/operations/gate-b-#{id}-preflight.json")
    review_path = File.join(@root, "docs/operations/gate-b-#{id}-review.json")
    write_json(observation_path, common.merge(
      'artifact_type' => 'g0_governance_v2_gate_b_local_observation',
      'evidence_id' => "G0-V2-OBS-#{id.upcase}",
      'effect' => 'none_observation_only',
      'observed_at' => (decided_at - 180).iso8601
    ))
    write_json(preflight_path, common.merge(
      'artifact_type' => 'g0_governance_v2_gate_b_canonical_preflight',
      'evidence_id' => "G0-V2-PREFLIGHT-#{id.upcase}",
      'effect' => 'none_preflight_only',
      'observed_at' => (decided_at - 120).iso8601,
      'checks' => Core::CONSUMER_PREFLIGHT_CHECK_KEYS.to_h { |key| [key, true] }
    ))
    observation_reference = reference(observation_path)
    preflight_reference = reference(preflight_path)
    write_json(review_path, common.merge(
      'artifact_type' => 'g0_governance_v2_gate_b_independent_review',
      'evidence_id' => "G0-V2-REVIEW-#{id.upcase}",
      'effect' => 'none_review_evidence_only',
      'observed_at' => (decided_at - 60).iso8601,
      'reviewer' => {
        'identity' => 'Independent Fixture Reviewer',
        'capacity' => 'independent_technical_security_reviewer'
      },
      'reviewed_evidence' => {
        'local_observation' => observation_reference,
        'canonical_preflight' => preflight_reference
      },
      'verdict' => 'PASS'
    ))
    document['technical_evidence'] = {
      'gate_a_adoption' => adoption_reference,
      'local_observation' => observation_reference,
      'candidate_bundle' => candidate_evidence,
      'canonical_preflight' => preflight_reference,
      'validator_contract' => contract_reference,
      'selector_source' => selector_reference,
      'independent_review' => reference(review_path)
    }
    approval_path = File.join(decisions_dir, "#{id}-approval.json")
    approval = {
      'artifact_type' => 'g0_governance_v2_gate_b_operation_approval',
      'schema_version' => 1,
      'approval_id' => "G0-V2-APPROVAL-#{id.upcase}",
      'scope' => Core::CONSUMER_APPROVAL_SCOPE_KEYS.to_h { |key| [key, key == 'consumer_selection_operation'] }
    }
    Core::CONSUMER_APPROVAL_CROSS_EQUAL_KEYS.each { |key| approval[key] = document.fetch(key) }
    write_json(approval_path, approval)
    document['approval_evidence'] = reference(approval_path)
    write_json(path, document)
    receipt = File.join(@root, 'receipts', "#{id}.json")
    options = {
      operation: operation,
      activation_decision: path,
      prior_state: prior.fetch('prior_state_reason'),
      json_receipt: receipt
    }
    options[:candidate_bundle] = @candidate if candidate
    options[:expected_pointer_sha256] = prior.fetch('expected_prior_pointer_sha256') if prior.fetch('expected_prior_pointer_sha256')
    options[:observed_unreadable_pointer_sha256] = prior.fetch('observed_unreadable_pointer_sha256') if prior.fetch('observed_unreadable_pointer_sha256')
    if operation == 'recover'
      options[:recover_outcome] = outcome
      if held
        options[:recover_selection] = File.join(@root, held.fetch('path'))
        options[:recover_selection_sha256] = held.fetch('sha256')
      end
    end
    [path, options, document]
  end

  def activate_once!(id: 'activate', prior: initial_prior)
    _decision, options, = activation_operation(id: id, prior: prior)
    Selector.execute!(options, root: @root, env: guarded_env, clock: clock)
  end

  def selection_path_for(selection)
    File.join(selections_dir, "#{selection.fetch('selection_id').downcase}.json")
  end

  def run_crashing_child(options, point)
    pid = fork do
      hook = ->(seen) { exit! 77 if seen == point }
      Selector.execute!(options, root: @root, env: guarded_env, clock: clock, fault_hook: hook)
      exit! 78
    end
    Process.wait2(pid).last
  end

  def write_json(path, document)
    File.binwrite(path, Core.canonical_json(document) + "\n")
  end

  def cli_argv(options)
    argv = []
    argv += ['--operation', options.fetch(:operation)]
    argv += ['--activation-decision', options.fetch(:activation_decision)]
    argv += ['--candidate-bundle', options.fetch(:candidate_bundle)] if options[:candidate_bundle]
    argv += ['--prior-state', options.fetch(:prior_state)]
    argv += ['--expected-pointer-sha256', options.fetch(:expected_pointer_sha256)] if options[:expected_pointer_sha256]
    argv += ['--observed-unreadable-pointer-sha256', options.fetch(:observed_unreadable_pointer_sha256)] if options[:observed_unreadable_pointer_sha256]
    argv += ['--recover-outcome', options.fetch(:recover_outcome)] if options[:recover_outcome]
    argv += ['--recover-selection', options.fetch(:recover_selection)] if options[:recover_selection]
    argv += ['--recover-selection-sha256', options.fetch(:recover_selection_sha256)] if options[:recover_selection_sha256]
    argv += ['--root', @root, '--json-receipt', options.fetch(:json_receipt)]
    argv
  end

  def authority_inventory(root)
    relative_paths = [
      Selector::POINTER_RELATIVE_PATH,
      Selector::SELECTIONS_RELATIVE_PATH,
      Selector::DECISIONS_RELATIVE_PATH,
      Selector::JOURNAL_RELATIVE_PATH,
      Selector::RECOVERY_MARKER_RELATIVE_PATH
    ]
    relative_paths.to_h { |relative| [relative, path_inventory(File.join(root, relative))] }
  end

  def canonical_authority_inventory
    authority_inventory(ROOT).merge(
      Selector::LOCK_RELATIVE_PATH => path_inventory(File.join(ROOT, Selector::LOCK_RELATIVE_PATH))
    )
  end

  def path_inventory(path)
    return nil unless File.exist?(path) || File.symlink?(path)
    stat = File.lstat(path)
    return { type: :symlink, target: File.readlink(path) } if stat.symlink?
    return { type: :file, sha256: Digest::SHA256.file(path).hexdigest, mode: stat.mode & 0o777 } if stat.file?
    return { type: :non_directory, mode: stat.mode & 0o777 } unless stat.directory?

    Dir.glob(File.join(path, '**', '*'), File::FNM_DOTMATCH).reject { |entry| %w[. ..].include?(File.basename(entry)) }.sort.to_h do |entry|
      relative = Pathname.new(entry).relative_path_from(Pathname.new(path)).to_s
      [relative, path_inventory(entry)]
    end
  end
end
