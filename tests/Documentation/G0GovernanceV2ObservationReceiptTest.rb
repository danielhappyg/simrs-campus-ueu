# frozen_string_literal: true

require 'digest'
require 'fileutils'
require 'json'
require 'minitest/autorun'
require 'open3'
require 'pathname'
require 'stringio'
require 'tmpdir'
require 'time'

require_relative '../../scripts/g0-proportional-governance-v2'
require_relative '../../scripts/generate-g0-proportional-governance-v2'
require_relative '../../scripts/compare-g0-governance-v1-v2'
require_relative '../../scripts/validate-g0-governance'

class G0GovernanceV2ObservationReceiptTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  RECORD_PATH = File.join(ROOT, 'docs/operations/G0_GOVERNANCE_V2_LOCAL_OBSERVATION_2026-08-29.json')
  ADOPTION_PATH = File.join(ROOT, 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json')
  TEMP_PARENT = File.realpath(Dir.tmpdir)
  Core = G0ProportionalGovernanceV2
  Generator = G0ProportionalGovernanceV2Generator
  Comparator = G0GovernanceV1V2Comparator
  Dispatcher = G0GovernanceProfileDispatcher

  TOP_LEVEL_KEYS = %w[
    artifact_type schema_version observation_id observed_at status effect
    source_revision source_bindings generation_observation candidate_bundle
    validation_observations integrity_assertions authority_snapshot
    authorization_boundary verification
  ].freeze
  SOURCE_REVISION_KEYS = %w[
    repository branch observed_head_sha wave worktree_scope commit_effect
  ].freeze
  SOURCE_BINDING_KEYS = %w[role path sha256].freeze
  GENERATION_KEYS = %w[
    command_argv raw_receipt_sha256 raw_receipt_retained
    raw_receipt_exclusion_reason normalized_receipt normalization_contract
    deterministic_regeneration
  ].freeze
  NORMALIZED_GENERATION_RECEIPT_KEYS = %w[
    status candidate_directory bundle_manifest_sha256
  ].freeze
  NORMALIZATION_CONTRACT_KEYS = %w[
    source_raw_receipt_bound_by_sha256 only_normalized_field
    normalized_candidate_directory ephemeral_path_not_committed
    other_fields_byte_meaning_unchanged
  ].freeze
  DETERMINISTIC_REGENERATION_KEYS = %w[
    performed regeneration_raw_receipt_sha256 regeneration_raw_receipt_retained
    regeneration_path_excluded bundle_manifest_sha256
    all_six_candidate_files_byte_identical
  ].freeze
  CANDIDATE_KEYS = %w[
    bundle_id profile status data_boundary effect capability_count
    provisional_engineering_binding_count canonical_order_sha256
    temporary_location retained temporary_evidence_cleaned artifacts
  ].freeze
  ARTIFACT_KEYS = %w[role path sha256].freeze
  OBSERVATION_KEYS = %w[observation command_argv raw_receipt_sha256 raw_receipt].freeze
  RECEIPT_KEYS = Dispatcher::RECEIPT_KEYS.freeze
  VALIDATOR_CONTRACT_KEYS = %w[comparison dispatcher v1 v2].freeze
  INTEGRITY_ASSERTION_KEYS = %w[
    v1_integrity_status v1_historical_file_count v1_historical_files_unchanged
    v2_integrity_status dual_integrity_status comparison_status comparison
    capability_count provisional_engineering_binding_count
    provisional_binding_effect project_g0_status project_g3_status
    project_g3_reason authority_effect activation_effect gate_effect
  ].freeze
  AUTHORITY_SNAPSHOT_KEYS = %w[
    canonical_paths normalized_before_snapshot_sha256
    normalized_after_snapshot_sha256 pointer_sha256_before
    pointer_sha256_after unchanged canonical_pointer_created
    canonical_recovery_marker_created canonical_selection_created
    canonical_activation_decision_created canonical_journal_created
    canonical_lock_created
  ].freeze
  AUTHORITY_PATH_ENTRY_KEYS = %w[path state_before state_after].freeze
  AUTHORIZATION_BOUNDARY_KEYS = %w[
    local_candidate_observation_authorized consumer_activation_authorized
    capability_disposition_authorized application_slice_implementation_authorized
    deployment_authorized hosted_migration_authorized real_patient_data_authorized
    live_integration_authorized domain_acceptance_authorized g0_closure_authorized
    g3_acceptance_authorized external_state_effect
  ].freeze
  VERIFICATION_KEYS = %w[
    test_path test_sha256 test_contract raw_validation_receipts_embedded
    raw_validation_receipt_hash_contract
    generator_path_normalization_was_separate_and_hash_bound
    temporary_artifacts_committed
  ].freeze
  AUTHORITY_PATHS = %w[
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTIONS
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_JOURNAL
    docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTION.lock
  ].freeze
  STABLE_LOCK_PATH = 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_CONSUMER_SELECTION.lock'
  EXPECTED_SOURCE_ROLES = %w[
    adoption_decision governance_v2_contract v1_historical_hash_manifest
    candidate_generator governance_v2_core v1_v2_comparator
    governance_v2_validator_cli governance_v1_validator profile_dispatcher
    consumer_selector_read_only_boundary
  ].freeze
  EXPECTED_SOURCE_PATHS = {
    'adoption_decision' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_ADOPTION_DECISION.json',
    'governance_v2_contract' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V2_CONTRACT.json',
    'v1_historical_hash_manifest' => 'docs/new-simrs-rebuild/phase-0/G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json',
    'candidate_generator' => 'scripts/generate-g0-proportional-governance-v2.rb',
    'governance_v2_core' => 'scripts/g0-proportional-governance-v2.rb',
    'v1_v2_comparator' => 'scripts/compare-g0-governance-v1-v2.rb',
    'governance_v2_validator_cli' => 'scripts/validate-g0-proportional-governance-v2.rb',
    'governance_v1_validator' => 'scripts/validate-parity-governance.rb',
    'profile_dispatcher' => 'scripts/validate-g0-governance.rb',
    'consumer_selector_read_only_boundary' => 'scripts/select-g0-governance-consumer.rb'
  }.freeze
  EXPECTED_OBSERVATIONS = {
    'dual_integrity' => %w[dual integrity candidate PASS dual_candidate_observation_passed],
    'dual_g0' => %w[dual g0 candidate OPEN dual_g0_observation_open],
    'v1_integrity' => ['v1', 'integrity', nil, 'PASS', 'v1_validation_passed'],
    'v2_integrity' => %w[v2 integrity candidate PASS v2_candidate_validation_passed]
  }.freeze

  def setup
    @record = Core.parse_json_file(RECORD_PATH, label: '$.wave7_observation')
  end

  def test_record_has_closed_schema_exact_identity_scope_and_portable_paths
    assert_closed(@record, TOP_LEVEL_KEYS)
    assert_equal 'g0_governance_v2_local_candidate_observation', @record.fetch('artifact_type')
    assert_equal 1, @record.fetch('schema_version')
    assert_equal 'G0-GOVERNANCE-V2-LOCAL-CANDIDATE-OBSERVATION-2026-08-29', @record.fetch('observation_id')
    assert_equal 'complete_candidate_only_local_observation', @record.fetch('status')
    assert_equal 'none_local_observation_only_no_activation_authority_disposition_deployment_migration_acceptance_or_gate_closure', @record.fetch('effect')

    revision = @record.fetch('source_revision')
    assert_closed(revision, SOURCE_REVISION_KEYS)
    assert_equal %w[simrs-campus-ueu main], revision.values_at('repository', 'branch')
    assert_equal '9db0b35ef985cd16dfad1aa99cd7e59bc19df75c', revision.fetch('observed_head_sha')
    assert_equal 'wave_7_candidate_only_local_observation', revision.fetch('wave')
    assert_equal 'canonical_sources_read_only_temporary_candidate_only', revision.fetch('worktree_scope')
    assert_equal 'none_observation_record_is_local_and_uncommitted_at_observation_time', revision.fetch('commit_effect')

    raw = File.binread(RECORD_PATH)
    refute_match(%r{/(?:Users|home)/}, raw)
    refute_includes raw, '/private/tmp'
    refute_match(/\.g0-governance-v2-local-observation\.[A-Za-z0-9]+/, raw)
    assert_includes raw, '<temporary-root>/candidate'
    assert_raises(Core::ParseError) { Core.parse_json('{"duplicate":1,"duplicate":2}', label: '$.duplicate_fixture') }
  end

  def test_exact_source_and_verification_test_hashes_are_current
    bindings = @record.fetch('source_bindings')
    assert_equal EXPECTED_SOURCE_ROLES, bindings.map { |entry| entry.fetch('role') }
    observed_head = @record.dig('source_revision', 'observed_head_sha')
    bindings.each do |entry|
      assert_closed(entry, SOURCE_BINDING_KEYS)
      assert_match(Core::SAFE_RELATIVE_PATH_PATTERN, entry.fetch('path'))
      assert_match(Core::SHA256_PATTERN, entry.fetch('sha256'))
      assert_equal EXPECTED_SOURCE_PATHS.fetch(entry.fetch('role')), entry.fetch('path')
      source_path = File.join(ROOT, entry.fetch('path'))
      source_stat = File.lstat(source_path)
      assert source_stat.file?, entry.fetch('role')
      refute source_stat.symlink?, entry.fetch('role')
      assert_equal entry.fetch('sha256'), Digest::SHA256.file(source_path).hexdigest,
                   entry.fetch('role')
      head_bytes, git_error, git_status = Open3.capture3(
        'git', 'show', "#{observed_head}:#{entry.fetch('path')}", chdir: ROOT
      )
      assert git_status.success?, "#{entry.fetch('role')}: #{git_error}"
      assert_equal entry.fetch('sha256'), Digest::SHA256.hexdigest(head_bytes),
                   "#{entry.fetch('role')} at observed head"
    end

    verification = @record.fetch('verification')
    assert_closed verification, VERIFICATION_KEYS
    assert_equal 'tests/Documentation/G0GovernanceV2ObservationReceiptTest.rb', verification.fetch('test_path')
    assert_match Core::SHA256_PATTERN, verification.fetch('test_sha256')
    assert_equal verification.fetch('test_sha256'), Digest::SHA256.file(__FILE__).hexdigest
    assert verification.fetch('raw_validation_receipts_embedded')
    assert verification.fetch('generator_path_normalization_was_separate_and_hash_bound')
    refute verification.fetch('temporary_artifacts_committed')
    assert_equal 'closed_schema_exact_hashes_duplicate_key_rejection_path_portability_deterministic_regeneration_dual_profile_receipt_replay_pointer_immutability_and_authorization_boundaries', verification.fetch('test_contract')
    assert_equal 'sha256_of_dispatcher_canonical_json_plus_lf', verification.fetch('raw_validation_receipt_hash_contract')
  end

  def test_generation_normalization_and_candidate_hash_contract_are_exact
    generation = @record.fetch('generation_observation')
    assert_closed(generation, GENERATION_KEYS)
    assert_equal [
      'ruby', 'scripts/generate-g0-proportional-governance-v2.rb', '--root',
      '<repository-root>', '--output', '<temporary-root>/candidate'
    ], generation.fetch('command_argv')
    assert_match Core::SHA256_PATTERN, generation.fetch('raw_receipt_sha256')
    refute generation.fetch('raw_receipt_retained')
    assert_equal 'candidate_directory_contains_ephemeral_absolute_path', generation.fetch('raw_receipt_exclusion_reason')
    normalized_receipt = generation.fetch('normalized_receipt')
    assert_closed normalized_receipt, NORMALIZED_GENERATION_RECEIPT_KEYS
    assert_equal({
      'status' => 'generated_pending_candidate',
      'candidate_directory' => '<temporary-root>/candidate',
      'bundle_manifest_sha256' => 'a48e96b39ed519906bf32a14f04fbfcf98ca83b7a791a051db7f98074a17c2fa'
    }, normalized_receipt)
    normalization = generation.fetch('normalization_contract')
    assert_closed normalization, NORMALIZATION_CONTRACT_KEYS
    assert_equal({
      'source_raw_receipt_bound_by_sha256' => true,
      'only_normalized_field' => 'candidate_directory',
      'normalized_candidate_directory' => '<temporary-root>/candidate',
      'ephemeral_path_not_committed' => true,
      'other_fields_byte_meaning_unchanged' => true
    }, normalization)
    regeneration = generation.fetch('deterministic_regeneration')
    assert_closed regeneration, DETERMINISTIC_REGENERATION_KEYS
    assert regeneration.fetch('performed')
    assert_match Core::SHA256_PATTERN, regeneration.fetch('regeneration_raw_receipt_sha256')
    refute regeneration.fetch('regeneration_raw_receipt_retained')
    assert regeneration.fetch('regeneration_path_excluded')
    assert_equal 'a48e96b39ed519906bf32a14f04fbfcf98ca83b7a791a051db7f98074a17c2fa', regeneration.fetch('bundle_manifest_sha256')
    assert regeneration.fetch('all_six_candidate_files_byte_identical')

    candidate = @record.fetch('candidate_bundle')
    assert_closed(candidate, CANDIDATE_KEYS)
    assert_equal 'G0-GOVERNANCE-V2-PENDING-c20e607ee1a35561d7330c1b', candidate.fetch('bundle_id')
    assert_equal %w[v2 candidate_pending_not_active synthetic_only], candidate.values_at('profile', 'status', 'data_boundary')
    assert_equal 'none_no_activation_authority_disposition_deployment_or_acceptance', candidate.fetch('effect')
    assert_equal 268, candidate.fetch('capability_count')
    assert_equal 14, candidate.fetch('provisional_engineering_binding_count')
    assert_equal '6da6f5c39d6ef143b4fcf8a3eaa52218416a45a49b3cd9f54b9bf0f3de5b6750', candidate.fetch('canonical_order_sha256')
    assert_equal '<temporary-root>/candidate', candidate.fetch('temporary_location')
    refute candidate.fetch('retained')
    assert candidate.fetch('temporary_evidence_cleaned')
    candidate.fetch('artifacts').each { |artifact| assert_closed(artifact, ARTIFACT_KEYS) }
    assert_equal Generator::PUBLICATION_ORDER, candidate.fetch('artifacts').map { |entry| entry.fetch('role') }
    candidate.fetch('artifacts').each do |entry|
      assert_equal Generator::FILES.fetch(entry.fetch('role')), entry.fetch('path')
      assert_match Core::SHA256_PATTERN, entry.fetch('sha256')
    end
  end

  def test_embedded_raw_receipts_have_exact_hashes_closed_schema_and_non_authority_fields
    observations = @record.fetch('validation_observations')
    assert_equal EXPECTED_OBSERVATIONS.keys, observations.map { |row| row.fetch('observation') }
    observations.each do |row|
      assert_closed(row, OBSERVATION_KEYS)
      receipt = row.fetch('raw_receipt')
      assert_closed(receipt, RECEIPT_KEYS)
      assert_closed(receipt.fetch('validator_contract'), VALIDATOR_CONTRACT_KEYS)
      assert_equal row.fetch('raw_receipt_sha256'), Digest::SHA256.hexdigest(Dispatcher.canonical_json(receipt) + "\n")
      expected = EXPECTED_OBSERVATIONS.fetch(row.fetch('observation'))
      assert_equal expected, receipt.values_at('profile', 'mode', 'source', 'status', 'reason_code')
      assert_equal 'validate', receipt.fetch('operation')
      assert_equal 'local_operator', receipt.fetch('actor')
      assert receipt.fetch('secret_scan_passed')
      assert_nil receipt.fetch('activation_sha256')
      assert_nil receipt.fetch('prior_pointer_sha256')
      assert_nil receipt.fetch('selection_sha256')
      assert_match Core::SHA256_PATTERN, row.fetch('raw_receipt_sha256')
      row.fetch('command_argv').each { |argument| refute_match(%r{\A/(?:Users|home|private/tmp)/}, argument) }
      started_at = Time.iso8601(receipt.fetch('started_at'))
      finished_at = Time.iso8601(receipt.fetch('finished_at'))
      assert_operator started_at, :<=, finished_at
    end

    latest_finished = observations.map { |row| Time.iso8601(row.dig('raw_receipt', 'finished_at')) }.max
    assert_equal latest_finished, Time.iso8601(@record.fetch('observed_at'))

    candidate_receipts = observations.reject { |row| row.fetch('observation') == 'v1_integrity' }
    candidate_receipts.each do |row|
      receipt = row.fetch('raw_receipt')
      assert_equal '139bf2f952ab5c47cd34a43f80d93224e48386c57f180a799d8f206a9049c0f3', receipt.fetch('adoption_sha256')
      assert_equal 'a48e96b39ed519906bf32a14f04fbfcf98ca83b7a791a051db7f98074a17c2fa', receipt.fetch('bundle_sha256')
    end
  end

  def test_integrity_counts_gates_and_authorization_boundaries_remain_open_and_closed_where_required
    assertions = @record.fetch('integrity_assertions')
    assert_closed assertions, INTEGRITY_ASSERTION_KEYS
    assert_equal %w[PASS PASS PASS PASS], assertions.values_at(
      'v1_integrity_status', 'v2_integrity_status', 'dual_integrity_status', 'comparison_status'
    )
    assert_equal 30, assertions.fetch('v1_historical_file_count')
    assert assertions.fetch('v1_historical_files_unchanged')
    assert_equal 268, assertions.fetch('capability_count')
    assert_equal 14, assertions.fetch('provisional_engineering_binding_count')
    assert_equal 'engineering_only_no_owner_authority_tier_approval_disposition_or_gate_effect', assertions.fetch('provisional_binding_effect')
    assert_equal %w[OPEN OPEN], assertions.values_at('project_g0_status', 'project_g3_status')
    assert_equal 'g0_is_open_and_no_active_consumer_exists', assertions.fetch('project_g3_reason')
    assert_equal 'read_only_v1_v2_migration_parity', assertions.fetch('comparison')
    assert_equal %w[none none none], assertions.values_at('authority_effect', 'activation_effect', 'gate_effect')

    boundary = @record.fetch('authorization_boundary')
    assert_closed boundary, AUTHORIZATION_BOUNDARY_KEYS
    assert boundary.fetch('local_candidate_observation_authorized')
    assert_equal 'none', boundary.fetch('external_state_effect')
    denied = boundary.reject { |key, _value| %w[local_candidate_observation_authorized external_state_effect].include?(key) }
    assert_equal 10, denied.length
    assert denied.values.all? { |value| value == false }
  end

  def test_pointer_selection_decision_and_journal_are_absent_and_unchanged
    snapshot = @record.fetch('authority_snapshot')
    assert_closed snapshot, AUTHORITY_SNAPSHOT_KEYS
    paths = snapshot.fetch('canonical_paths')
    assert_equal AUTHORITY_PATHS, paths.map { |entry| entry.fetch('path') }
    paths.each do |entry|
      assert_closed entry, AUTHORITY_PATH_ENTRY_KEYS
      assert_equal %w[absent absent], entry.values_at('state_before', 'state_after')
      live_path = File.join(ROOT, entry.fetch('path'))
      if entry.fetch('path') == STABLE_LOCK_PATH
        assert_safe_inert_lock_or_absent(live_path)
      else
        assert_equal 'absent', path_state(live_path), entry.fetch('path')
      end
    end
    normalized = paths.map { |entry| { 'path' => entry.fetch('path'), 'state' => 'absent' } }
    sha = Digest::SHA256.hexdigest(JSON.generate(normalized))
    assert_equal sha, snapshot.fetch('normalized_before_snapshot_sha256')
    assert_equal sha, snapshot.fetch('normalized_after_snapshot_sha256')
    assert_nil snapshot.fetch('pointer_sha256_before')
    assert_nil snapshot.fetch('pointer_sha256_after')
    assert snapshot.fetch('unchanged')
    %w[
      canonical_pointer_created canonical_recovery_marker_created
      canonical_selection_created canonical_activation_decision_created
      canonical_journal_created canonical_lock_created
    ].each do |key|
      refute snapshot.fetch(key), key
    end
  end

  def test_fresh_regeneration_and_dispatch_replay_match_record_without_mutating_authority
    before = authority_inventory
    Dir.mktmpdir('g0-wave7-observation-', TEMP_PARENT) do |temp_root|
      File.chmod(0o700, temp_root)
      candidate_path = File.join(temp_root, 'candidate')
      generation = Generator.generate!(root: ROOT, output: candidate_path)
      assert_equal 'generated_pending_candidate', generation.fetch('status')
      assert_equal @record.dig('candidate_bundle', 'artifacts', 5, 'sha256'), generation.fetch('bundle_manifest_sha256')

      expected_artifacts = @record.fetch('candidate_bundle').fetch('artifacts')
      expected_artifacts.each do |entry|
        assert_equal entry.fetch('sha256'), Digest::SHA256.file(File.join(candidate_path, entry.fetch('path'))).hexdigest,
                     entry.fetch('role')
      end
      regenerated_manifest = Core.parse_json_file(
        File.join(candidate_path, Generator::FILES.fetch('bundle_manifest')),
        label: '$.wave7_regenerated_manifest'
      )
      assert_equal @record.dig('candidate_bundle', 'canonical_order_sha256'),
                   regenerated_manifest.fetch('canonical_order_sha256')
      comparison = Comparator.compare!(root: ROOT, candidate_bundle: candidate_path)
      assert_equal ['PASS', 268, 14, 30, 'none', 'none', 'none'], comparison.values_at(
        'status', 'capability_count', 'provisional_engineering_binding_count',
        'v1_historical_file_count', 'authority_effect', 'activation_effect', 'gate_effect'
      )

      replay_observations(candidate_path)
      assert_equal 0o700, File.stat(temp_root).mode & 0o777
      assert_equal 0o700, File.stat(candidate_path).mode & 0o777
    end
    assert_equal before, authority_inventory
  end

  def test_record_contains_no_credential_value_and_only_known_receipt_scan_metadata
    scrubbed = deep_copy(@record)
    scrubbed.fetch('validation_observations').each do |row|
      row.fetch('raw_receipt').delete('secret_scan_passed')
    end
    assert_empty Core.secret_locations(scrubbed)
  end

  private

  def assert_closed(value, keys)
    assert_kind_of Hash, value
    assert_equal keys.sort, value.keys.sort
  end

  def replay_observations(candidate_path)
    recorded = @record.fetch('validation_observations').to_h { |row| [row.fetch('observation'), row.fetch('raw_receipt')] }
    root = Pathname.new(ROOT).realpath
    env = ENV.to_h
    matrix = {
      'v1_integrity' => { profile: 'v1', mode: 'integrity' },
      'v2_integrity' => { profile: 'v2', mode: 'integrity', source: 'candidate', candidate_bundle: candidate_path, adoption_decision: ADOPTION_PATH },
      'dual_integrity' => { profile: 'dual', mode: 'integrity', source: 'candidate', candidate_bundle: candidate_path, adoption_decision: ADOPTION_PATH },
      'dual_g0' => { profile: 'dual', mode: 'g0', source: 'candidate', candidate_bundle: candidate_path, adoption_decision: ADOPTION_PATH }
    }

    matrix.each do |name, options|
      stdout = StringIO.new
      stderr = StringIO.new
      result = Dispatcher.dispatch(options, root, stdout, stderr, env)
      assert_equal 0, result.fetch(:exit_code), "#{name}: #{stderr.string}"
      replay = Dispatcher.build_receipt(options, root, result, '2026-08-29T00:00:00.000000Z', '2026-08-29T00:00:00.000000Z')
      assert_equal normalized_receipt(recorded.fetch(name)), normalized_receipt(replay), name
    end
  end

  def normalized_receipt(receipt)
    receipt.reject { |key, _value| %w[started_at finished_at].include?(key) }
  end

  def authority_inventory
    AUTHORITY_PATHS.to_h do |relative|
      path = File.join(ROOT, relative)
      [relative, path_state(path) == 'absent' ? nil : Digest::SHA256.hexdigest(path_inventory(path))]
    end
  end

  def path_inventory(path)
    stat = File.lstat(path)
    return "symlink:#{File.readlink(path)}" if stat.symlink?
    return File.binread(path) unless stat.directory?

    Dir.children(path).sort.map do |name|
      child = File.join(path, name)
      child_stat = File.lstat(child)
      value = if child_stat.symlink?
                "symlink:#{File.readlink(child)}"
              elsif child_stat.directory?
                path_inventory(child)
              elsif child_stat.file?
                Digest::SHA256.file(child).hexdigest
              else
                "other:#{child_stat.ftype}"
              end
      [name, value]
    end.inspect
  end

  def path_state(path)
    stat = File.lstat(path)
    return 'symlink' if stat.symlink?
    return 'directory' if stat.directory?
    return 'file' if stat.file?

    'other'
  rescue Errno::ENOENT
    'absent'
  end

  def assert_safe_inert_lock_or_absent(path)
    return assert_equal('absent', path_state(path)) if path_state(path) == 'absent'

    stat = File.lstat(path)
    assert stat.file?, 'stable lock must be a regular file'
    refute stat.symlink?, 'stable lock must not be a symlink'
    assert_equal 0o600, stat.mode & 0o777, 'stable lock mode must remain 0600'
    assert_equal 1, stat.nlink, 'stable lock must have exactly one link'
    assert_equal Process.uid, stat.uid, 'stable lock must be owned by the current process user'
    assert_equal 0, stat.size, 'stable lock must contain no bytes and confer no authority'
    assert_equal Digest::SHA256.hexdigest(''), Digest::SHA256.file(path).hexdigest
  end

  def deep_copy(value)
    Marshal.load(Marshal.dump(value))
  end
end
