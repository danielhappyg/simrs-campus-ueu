#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'optparse'
require 'pathname'
require 'securerandom'
require 'time'
require 'tmpdir'

require_relative 'g0-proportional-governance-v2'
require_relative 'generate-g0-proportional-governance-v2'

# Produces non-authoritative Gate-B observation and preflight evidence for one
# already-retained governance-v2 candidate. It never calls the selector and it
# refuses to run unless the canonical authority state is still initial.
module G0GovernanceV2GateBEvidence
  class Error < StandardError; end
  class UsageError < Error; end

  Core = G0ProportionalGovernanceV2
  CandidateGenerator = G0ProportionalGovernanceV2Generator

  CANONICAL_ROOT = Pathname.new(File.expand_path('..', __dir__)).realpath.freeze
  TEST_ROOT_GUARD = 'G0_GOVERNANCE_V2_TEST_ROOT'
  SELECTOR_TEST_FAULT = 'G0_GOVERNANCE_V2_TEST_FAULT'
  CONTRACT_PATH = Core::CONSUMER_VALIDATOR_CONTRACT_PATH
  SELECTOR_PATH = Core::CONSUMER_SELECTOR_SOURCE_PATH
  OPERATIONS_PATH = 'docs/operations'
  PHASE0_PATH = 'docs/new-simrs-rebuild/phase-0'
  CANDIDATES_PATH = "#{PHASE0_PATH}/G0_GOVERNANCE_V2_CANDIDATES"
  MANIFEST_NAME = Core::CONSUMER_OPERATION_BUNDLE_MANIFEST
  OUTPUT_NAME_PATTERN = /\AG0_GOVERNANCE_V2_GATE_B_(?:LOCAL_OBSERVATION|CANONICAL_PREFLIGHT)_[A-Za-z0-9._-]+\.json\z/.freeze
  ID_PATTERN = /\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/.freeze
  RUNTIME_UNSET = Object.new.freeze
  # Authority directories may be private, group-readable/executable, or
  # world-readable/executable. Group/world write is never accepted.
  SAFE_DIRECTORY_MODES = [0o700, 0o750, 0o755].freeze
  POINTER_AUTHORITY_PATHS = %W[
    #{PHASE0_PATH}/G0_GOVERNANCE_CONSUMER_POINTER.json
    #{PHASE0_PATH}/G0_GOVERNANCE_CONSUMER_POINTER_RECOVERY_REQUIRED.json
  ].freeze
  SCAFFOLDS = {
    "#{PHASE0_PATH}/G0_GOVERNANCE_CONSUMER_SELECTIONS" => '92904e2d96641f14fc173384f29bfa7220c5b81ef3f8a7a1f1e459be026b2772',
    "#{PHASE0_PATH}/G0_GOVERNANCE_V2_ACTIVATION_DECISIONS" => '9ad69888686d6481d5f7ac8311a6ad3e447c34839b47a76760ce972e8234a932',
    "#{PHASE0_PATH}/G0_GOVERNANCE_CONSUMER_JOURNAL" => 'eba0660498094dbaa267bbf0e88e5aa8587582ce4b246caa463b1cff9e455daa',
    CANDIDATES_PATH => '275256c8d09190cc061c0d27667bf1411e9142d97bbcb31653a458ca2d3ddd41'
  }.freeze
  AUTHORITY_PATHS = (POINTER_AUTHORITY_PATHS + SCAFFOLDS.keys).freeze
  PREFLIGHT_CHECKS = Core::CONSUMER_PREFLIGHT_CHECK_KEYS.freeze

  module_function

  def generate!(root:, candidate:, observation_output:, preflight_output:,
                observation_id:, preflight_id:, observation_observed_at:,
                preflight_observed_at:, expires_at:, env: RUNTIME_UNSET, now: RUNTIME_UNSET,
                capability_probe: RUNTIME_UNSET)
    canonical_request = canonical_root_requested?(root)
    if canonical_request
      unless env.equal?(RUNTIME_UNSET) && now.equal?(RUNTIME_UNSET) && capability_probe.equal?(RUNTIME_UNSET)
        raise Error, 'canonical runtime dependency injection rejected'
      end
      root_path, fixture = validate_root!(root)
      runtime_env = ENV
      runtime_now = Time.now.utc
      runtime_probe = method(:filesystem_capability_probe!)
    else
      runtime_env = env.equal?(RUNTIME_UNSET) ? ENV : env
      runtime_now = now.equal?(RUNTIME_UNSET) ? Time.now.utc : now
      runtime_probe = capability_probe.equal?(RUNTIME_UNSET) ? method(:filesystem_capability_probe!) : capability_probe
      root_path, fixture = validate_root!(root, env: runtime_env)
    end
    contract_path = strict_source_file!(root_path, CONTRACT_PATH, 'contract_invalid')
    selector_path = strict_source_file!(root_path, SELECTOR_PATH, 'selector_source_invalid')
    contract = Core.parse_json_file(contract_path, label: '$.contract')
    Core.validate_contract!(contract, root: root_path.to_s)
    validate_closed_trust_state!(contract)
    candidate_state = validate_candidate!(root_path, candidate)
    candidate_path = candidate_state.fetch(:path)
    authority_directory_state = validate_initial_authority_state!(root_path, candidate_path)
    observation_path = validate_new_output!(root_path, observation_output, 'observation_output_invalid')
    preflight_path = validate_new_output!(root_path, preflight_output, 'preflight_output_invalid')
    raise Error, 'evidence outputs must be distinct' if observation_path == preflight_path
    validate_id!(observation_id, 'observation_id_invalid')
    validate_id!(preflight_id, 'preflight_id_invalid')
    observation_time, preflight_time, expiry_time = validate_times!(
      observation_observed_at, preflight_observed_at, expires_at, runtime_now
    )

    probe_result = runtime_probe.call(capability_directories(root_path))
    validate_capability_result!(probe_result)
    validate_initial_authority_state!(root_path, candidate_path)
    revalidate_authority_directories!(root_path, authority_directory_state)
    revalidate_candidate!(root_path, candidate_state)

    candidate_reference = {
      'path' => relative_path(root_path, candidate_path),
      'sha256' => Digest::SHA256.file(candidate_path.join(MANIFEST_NAME)).hexdigest
    }
    contract_reference = reference(root_path, contract_path)
    selector_reference = reference(root_path, selector_path)
    prior_state = {
      'prior_state_reason' => 'initial_state',
      'expected_prior_pointer_sha256' => nil,
      'observed_unreadable_pointer_sha256' => nil
    }
    common = {
      'schema_version' => 1,
      'status' => 'PASS',
      'data_boundary' => 'synthetic_only',
      'environment' => 'local_canonical_checkout',
      'root' => root_path.to_s,
      'expires_at' => expires_at,
      'candidate_bundle' => candidate_reference,
      'validator_contract' => contract_reference,
      'selector_source' => selector_reference,
      'prior_state' => prior_state,
      'authority_effect' => 'none'
    }
    observation = common.merge(
      'artifact_type' => 'g0_governance_v2_gate_b_local_observation',
      'evidence_id' => observation_id,
      'effect' => 'none_observation_only',
      'observed_at' => observation_observed_at
    )
    preflight = common.merge(
      'artifact_type' => 'g0_governance_v2_gate_b_canonical_preflight',
      'evidence_id' => preflight_id,
      'effect' => 'none_preflight_only',
      'observed_at' => preflight_observed_at,
      'checks' => {
        'candidate_retained_direct_child' => true,
        'candidate_manifest_hash_valid' => true,
        'validator_contract_hash_valid' => Digest::SHA256.file(contract_path).hexdigest == contract_reference.fetch('sha256'),
        'selector_source_hash_valid' => Digest::SHA256.file(selector_path).hexdigest == selector_reference.fetch('sha256'),
        'prior_state_matches' => prior_state.fetch('prior_state_reason') == 'initial_state',
        'no_authority_effect' => observation.fetch('authority_effect') == 'none',
        'filesystem_capabilities_supported' => probe_result.values.all?(true),
        'no_test_controls' => runtime_env.keys.grep(/\AG0_GOVERNANCE_V2_TEST_/).reject { |key| key == TEST_ROOT_GUARD }.empty? &&
          (!fixture || runtime_env[TEST_ROOT_GUARD] == '1')
      }
    )
    validate_evidence_documents!(
      observation, preflight, root_path: root_path, contract: contract,
      observation_time: observation_time, preflight_time: preflight_time,
      expiry_time: expiry_time
    )
    bytes = [Core.canonical_json(observation) + "\n", Core.canonical_json(preflight) + "\n"]
    validate_initial_authority_state!(root_path, candidate_path)
    revalidate_authority_directories!(root_path, authority_directory_state)
    revalidate_candidate!(root_path, candidate_state)
    created = []
    begin
      exclusive_write!(observation_path, bytes.fetch(0))
      created << observation_path
      exclusive_write!(preflight_path, bytes.fetch(1))
      created << preflight_path
      fsync_directory!(observation_path.parent)
      fsync_directory!(preflight_path.parent) unless preflight_path.parent == observation_path.parent
    rescue StandardError
      created.reverse_each { |path| File.unlink(path) if path.file? && !path.symlink? }
      raise
    end
    {
      'status' => 'gate_b_evidence_created_no_authority',
      'effect' => 'none_evidence_only',
      'fixture_test_guard_used' => fixture,
      'candidate_bundle' => candidate_reference,
      'local_observation' => reference(root_path, observation_path),
      'canonical_preflight' => reference(root_path, preflight_path)
    }
  rescue Core::Error, CandidateGenerator::Error => e
    raise Error, e.message
  rescue SystemCallError, IOError => e
    raise Error, "filesystem operation failed: #{e.class}"
  end

  def canonical_root_requested?(root)
    raw = root.to_s
    return false if raw.empty? || raw.include?("\0")

    Pathname.new(raw).expand_path.realpath == CANONICAL_ROOT
  rescue SystemCallError, ArgumentError
    false
  end

  def validate_root!(root, env: RUNTIME_UNSET)
    raw = root.to_s
    raise UsageError, 'root_invalid' if raw.empty? || raw.include?("\0")
    path = Pathname.new(raw).expand_path
    reject_symlink_components!(path)
    stat = path.lstat
    raise UsageError, 'root_invalid' unless stat.directory? && !stat.symlink? && stat.uid == Process.uid
    real = path.realpath
    canonical = real == CANONICAL_ROOT
    if canonical
      raise Error, 'canonical runtime dependency injection rejected' unless env.equal?(RUNTIME_UNSET)
      runtime_env = ENV
    else
      runtime_env = env.equal?(RUNTIME_UNSET) ? ENV : env
    end
    raise UsageError, 'environment_invalid' unless runtime_env.respond_to?(:keys) && runtime_env.respond_to?(:[])
    test_controls = runtime_env.keys.grep(/\AG0_GOVERNANCE_V2_TEST_/).reject { |key| key == TEST_ROOT_GUARD }
    raise Error, 'test controls rejected' unless test_controls.empty? && runtime_env[SELECTOR_TEST_FAULT].nil?
    if canonical
      raise Error, 'test controls rejected' if runtime_env[TEST_ROOT_GUARD]
      [real, false]
    else
      temporary = Pathname.new(Dir.tmpdir).realpath
      unless inside?(real, temporary) && real != temporary && (stat.mode & 0o777) == 0o700 &&
             runtime_env[TEST_ROOT_GUARD] == '1'
        raise UsageError, 'fixture root requires the existing test guard'
      end
      [real, true]
    end
  rescue SystemCallError, ArgumentError
    raise UsageError, 'root_invalid'
  end

  def validate_closed_trust_state!(contract)
    trust = contract.dig('consumer_operation_decision_contract', 'canonical_trust_state')
    unless trust.is_a?(Hash) && trust.fetch('state') == 'unprovisioned_blocked_external_attestation_required' &&
           trust.fetch('canonical_environment') == 'local_canonical_checkout' &&
           trust.fetch('repository_local_approval_artifacts_sufficient') == false &&
           trust.fetch('signature_implementation_authorized') == false
      raise Error, 'canonical trust state invalid'
    end
  rescue KeyError
    raise Error, 'canonical trust state invalid'
  end

  def validate_initial_authority_state!(root, candidate_path)
    POINTER_AUTHORITY_PATHS.each do |relative|
      path = root.join(relative)
      begin
        path.lstat
        raise Error, 'canonical authority state is not initial'
      rescue Errno::ENOENT
        next
      end
    end
    phase0 = strict_directory!(root, PHASE0_PATH, 'phase0 directory invalid')
    directory_state = { PHASE0_PATH => authority_directory_identity(phase0.lstat) }
    devices = [phase0.lstat.dev]
    SCAFFOLDS.each do |relative, expected_readme_sha|
      directory = strict_directory!(root, relative, 'authority scaffold invalid')
      directory_state[relative] = authority_directory_identity(directory.lstat)
      devices << directory.lstat.dev
      allowed = ['README.md']
      allowed << candidate_path.basename.to_s if relative == CANDIDATES_PATH
      unless directory.children.map { |entry| entry.basename.to_s }.sort == allowed.sort
        raise Error, 'authority scaffold contains an unexpected entry'
      end
      readme = directory.join('README.md')
      validate_scaffold_readme!(readme, expected_readme_sha)
      if relative == CANDIDATES_PATH && candidate_path.parent != directory
        raise Error, 'candidate scaffold does not contain the exact retained candidate'
      end
    end
    raise Error, 'authority scaffold crosses filesystem devices' unless devices.uniq.length == 1
    root.glob('docs/new-simrs-rebuild/**/*.lock').each { |lock| validate_inert_lock!(lock) }
    directory_state.freeze
  end

  def revalidate_authority_directories!(root, expected_state)
    expected_paths = ([PHASE0_PATH] + SCAFFOLDS.keys).uniq.sort
    unless expected_state.is_a?(Hash) && expected_state.keys.sort == expected_paths
      raise Error, 'authority directory state invalid'
    end
    expected_paths.each do |relative|
      directory = strict_directory!(root, relative, 'authority directory changed during preflight')
      unless authority_directory_identity(directory.lstat) == expected_state.fetch(relative)
        raise Error, 'authority directory changed during preflight'
      end
    end
    true
  rescue KeyError, SystemCallError
    raise Error, 'authority directory changed during preflight'
  end

  def authority_directory_identity(stat)
    {
      dev: stat.dev,
      ino: stat.ino,
      nlink: stat.nlink,
      uid: stat.uid,
      mode: stat.mode & 0o777
    }
  end

  def validate_scaffold_readme!(path, expected_sha)
    reject_symlink_components!(path)
    stat = path.lstat
    bytes = File.binread(path)
    unless stat.file? && !stat.symlink? && stat.uid == Process.uid && stat.nlink == 1 &&
           (stat.mode & 0o022).zero? && Digest::SHA256.hexdigest(bytes) == expected_sha &&
           bytes.start_with?('# ') && !path.basename.to_s.end_with?('.json')
      raise Error, 'authority scaffold README is invalid'
    end
    Core.assert_secret_free!({ 'documentation' => bytes }, label: '$.authority_scaffold_readme')
    true
  rescue Core::Error, SystemCallError
    raise Error, 'authority scaffold README is invalid'
  end

  def validate_inert_lock!(path)
    reject_symlink_components!(path)
    stat = path.lstat
    unless stat.file? && !stat.symlink? && stat.uid == Process.uid && stat.nlink == 1 &&
           (stat.mode & 0o777) == 0o600 && stat.size.zero?
      raise Error, 'stable lock is not private and inert'
    end
    true
  rescue SystemCallError
    raise Error, 'stable lock is invalid'
  end

  def validate_candidate!(root, raw_candidate)
    parent = strict_directory!(root, CANDIDATES_PATH, 'retained candidate parent invalid')
    candidate = Pathname.new(raw_candidate.to_s).expand_path
    reject_symlink_components!(candidate)
    stat = candidate.lstat
    unless stat.directory? && !stat.symlink? && stat.uid == Process.uid && candidate.realpath.parent == parent &&
           candidate.realpath == candidate && (stat.mode & 0o777) == 0o700 && stat.nlink.positive? &&
           stat.dev == parent.lstat.dev
      raise Error, 'retained candidate must be one direct canonical child'
    end
    candidate.children.each { |child| strict_candidate_file!(candidate, child) }
    CandidateGenerator.verify_retained!(root: root.to_s, path: candidate.to_s)
    {
      path: candidate,
      directory_identity: candidate_directory_identity(stat),
      artifact_hashes: candidate_artifact_hashes(candidate)
    }
  rescue SystemCallError
    raise Error, 'retained candidate unavailable'
  end

  def revalidate_candidate!(root, state)
    path = state.fetch(:path)
    reject_symlink_components!(path)
    stat = path.lstat
    unless stat.directory? && !stat.symlink? && candidate_directory_identity(stat) == state.fetch(:directory_identity) &&
           candidate_artifact_hashes(path) == state.fetch(:artifact_hashes)
      raise Error, 'retained candidate changed during preflight'
    end
    path.children.each { |child| strict_candidate_file!(path, child) }
    CandidateGenerator.verify_retained!(root: root.to_s, path: path.to_s)
    true
  rescue KeyError, SystemCallError
    raise Error, 'retained candidate changed during preflight'
  end

  def candidate_directory_identity(stat)
    {
      dev: stat.dev,
      ino: stat.ino,
      nlink: stat.nlink,
      uid: stat.uid,
      mode: stat.mode & 0o777
    }
  end

  def candidate_artifact_hashes(candidate)
    expected = CandidateGenerator::FILES.values.sort
    unless candidate.children.map { |entry| entry.basename.to_s }.sort == expected
      raise Error, 'retained candidate is incomplete'
    end
    expected.to_h do |name|
      path = candidate.join(name)
      [name, Digest::SHA256.file(path).hexdigest]
    end
  end

  def strict_candidate_file!(candidate, path)
    reject_symlink_components!(path)
    stat = path.lstat
    unless stat.file? && !stat.symlink? && stat.uid == Process.uid && stat.nlink == 1 &&
           (stat.mode & 0o022).zero? && path.parent == candidate
      raise Error, 'retained candidate contains an unsafe file'
    end
    true
  end

  def validate_new_output!(root, raw_output, reason)
    operations = strict_directory!(root, OPERATIONS_PATH, 'operations directory invalid')
    path = Pathname.new(raw_output.to_s).expand_path
    raise Error, reason unless path.parent == operations && OUTPUT_NAME_PATTERN.match?(path.basename.to_s)
    reject_symlink_components!(path.parent)
    begin
      path.lstat
      raise Error, 'evidence output already exists'
    rescue Errno::ENOENT
      path
    end
  end

  def validate_id!(value, reason)
    raise Error, reason unless value.is_a?(String) && ID_PATTERN.match?(value)
  end

  def validate_times!(observation_raw, preflight_raw, expiry_raw, now)
    observation = explicit_time!(observation_raw)
    preflight = explicit_time!(preflight_raw)
    expiry = explicit_time!(expiry_raw)
    current = now.is_a?(String) ? explicit_time!(now) : now
    raise Error, 'now_invalid' unless current.is_a?(Time)
    unless observation <= preflight && preflight <= current && current < expiry &&
           expiry - observation <= Core::CONSUMER_MAXIMUM_EVIDENCE_TTL_SECONDS &&
           expiry - preflight <= Core::CONSUMER_MAXIMUM_EVIDENCE_TTL_SECONDS
      raise Error, 'evidence time window invalid'
    end
    [observation, preflight, expiry]
  end

  def explicit_time!(value)
    unless value.is_a?(String) && value.match?(/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/)
      raise Error, 'explicit RFC3339 timestamp required'
    end
    Time.iso8601(value)
  rescue ArgumentError
    raise Error, 'invalid RFC3339 timestamp'
  end

  def validate_evidence_documents!(observation, preflight, root_path:, contract:,
                                   observation_time:, preflight_time:, expiry_time:)
    Core.assert_closed_schema!(observation, required: Core::CONSUMER_EVIDENCE_COMMON_KEYS,
                               label: '$.local_observation')
    Core.assert_closed_schema!(preflight, required: Core::CONSUMER_EVIDENCE_COMMON_KEYS + ['checks'],
                               label: '$.canonical_preflight')
    Core.assert_closed_schema!(preflight.fetch('checks'), required: PREFLIGHT_CHECKS,
                               label: '$.canonical_preflight.checks')
    Core.assert_secret_free!(observation, label: '$.local_observation')
    Core.assert_secret_free!(preflight, label: '$.canonical_preflight')
    semantic = contract.dig('consumer_operation_decision_contract', 'semantic_evidence_contracts')
    unless observation.fetch('artifact_type') == semantic.fetch('local_observation_artifact_type') &&
           observation.fetch('effect') == semantic.fetch('local_observation_effect') &&
           preflight.fetch('artifact_type') == semantic.fetch('canonical_preflight_artifact_type') &&
           preflight.fetch('effect') == semantic.fetch('canonical_preflight_effect') &&
           [observation, preflight].all? do |document|
             document.fetch('status') == 'PASS' && document.fetch('authority_effect') == 'none' &&
               document.fetch('environment') == 'local_canonical_checkout' && document.fetch('root') == root_path.to_s
           end && PREFLIGHT_CHECKS.all? { |key| preflight.fetch('checks').fetch(key) == true } &&
           observation_time <= preflight_time && preflight_time < expiry_time
      raise Error, 'closed evidence semantics invalid'
    end
    true
  rescue KeyError
    raise Error, 'closed evidence semantics invalid'
  end

  def capability_directories(root)
    ([PHASE0_PATH] + SCAFFOLDS.keys).uniq.map do |relative|
      strict_directory!(root, relative, 'capability directory invalid')
    end
  end

  def validate_capability_result!(result)
    required = %i[
      same_device exclusive_create advisory_lock file_fsync directory_fsync
      hardlink rename readback cleanup
    ]
    unless result.is_a?(Hash) && result.keys.sort == required.sort && result.values.all?(true)
      raise Error, 'filesystem capability probe failed'
    end
    true
  end

  def filesystem_capability_probe!(directories)
    strict_directories = directories.map { |directory| Pathname.new(directory.to_s).realpath }
    results = {
      same_device: strict_directories.map { |directory| directory.lstat.dev }.uniq.length == 1,
      exclusive_create: true,
      advisory_lock: true,
      file_fsync: true,
      directory_fsync: true,
      hardlink: true,
      rename: true,
      readback: true,
      cleanup: false
    }
    all_paths = []
    flags = File::WRONLY | File::CREAT | File::EXCL
    flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
    bytes = "gate-b-evidence-capability-probe\n"
    strict_directories.each do |directory|
      token = SecureRandom.hex(12)
      paths = %w[source replacement hardlink lock].to_h do |name|
        [name, directory.join(".g0-gate-b-evidence-probe-#{name}-#{token}")]
      end
      all_paths.concat(paths.values)
      File.open(paths.fetch('source'), flags, 0o600) { |file| file.write(bytes); file.flush; file.fsync }
      File.open(paths.fetch('replacement'), flags, 0o600) { |file| file.write(bytes); file.flush; file.fsync }
      lock_flags = File::RDWR | File::CREAT | File::EXCL
      lock_flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
      File.open(paths.fetch('lock'), lock_flags, 0o600) do |lock|
        results[:advisory_lock] &&= !!lock.flock(File::LOCK_EX | File::LOCK_NB)
      end
      File.link(paths.fetch('replacement'), paths.fetch('hardlink'))
      results[:hardlink] &&= paths.fetch('replacement').lstat.ino == paths.fetch('hardlink').lstat.ino
      results[:readback] &&= File.binread(paths.fetch('hardlink')) == bytes
      File.rename(paths.fetch('replacement'), paths.fetch('source'))
      results[:rename] &&= !paths.fetch('replacement').exist?
      results[:readback] &&= File.binread(paths.fetch('source')) == bytes
      fsync_directory!(directory)
    end
    results[:exclusive_create] &&= all_paths.all? { |path| path.file? || !path.exist? }
    results
  rescue Error
    raise
  rescue SystemCallError, IOError, NotImplementedError
    raise Error, 'filesystem capability probe failed'
  ensure
    Array(all_paths).each do |path|
      File.unlink(path) if path.exist? && path.file? && !path.symlink?
    rescue SystemCallError
      nil
    end
    if defined?(results) && results
      results[:cleanup] = Array(all_paths).none? { |path| path.exist? || path.symlink? }
    end
  end

  def strict_source_file!(root, relative, reason)
    path = root.join(relative)
    reject_symlink_components!(path)
    stat = path.lstat
    unless stat.file? && !stat.symlink? && stat.uid == Process.uid && stat.nlink == 1 &&
           (stat.mode & 0o022).zero? && inside?(path.realpath, root)
      raise Error, reason
    end
    path.realpath
  rescue SystemCallError
    raise Error, reason
  end

  def strict_directory!(root, relative, reason)
    path = root.join(relative)
    reject_symlink_components!(path)
    stat = path.lstat
    unless stat.directory? && !stat.symlink? && stat.uid == Process.uid && stat.nlink.positive? &&
           SAFE_DIRECTORY_MODES.include?(stat.mode & 0o777) && inside?(path.realpath, root)
      raise Error, reason
    end
    path.realpath
  rescue SystemCallError
    raise Error, reason
  end

  def reject_symlink_components!(path)
    current = Pathname.new(path.to_s).expand_path
    chain = []
    loop do
      chain << current
      break if current.root?
      current = current.parent
    end
    chain.reverse_each do |component|
      begin
        raise Error, 'symlink path rejected' if component.lstat.symlink?
      rescue Errno::ENOENT
        next
      end
    end
    true
  end

  def inside?(path, root)
    value = Pathname.new(path.to_s).expand_path
    base = Pathname.new(root.to_s).expand_path
    value == base || value.to_s.start_with?("#{base}#{File::SEPARATOR}")
  end

  def relative_path(root, path)
    Pathname.new(path.to_s).realpath.relative_path_from(root.realpath).to_s
  rescue ArgumentError
    raise Error, 'path escapes root'
  end

  def reference(root, path)
    { 'path' => relative_path(root, path), 'sha256' => Digest::SHA256.file(path).hexdigest }
  end

  def exclusive_write!(path, bytes)
    flags = File::WRONLY | File::CREAT | File::EXCL
    flags |= File::NOFOLLOW if File.const_defined?(:NOFOLLOW)
    File.open(path, flags, 0o600) { |file| file.write(bytes); file.flush; file.fsync }
    true
  rescue Errno::EEXIST, Errno::ELOOP
    raise Error, 'evidence output already exists'
  end

  def fsync_directory!(path)
    File.open(path, File::RDONLY) { |directory| directory.fsync }
  rescue SystemCallError, IOError
    raise Error, 'directory fsync failed'
  end

  def run_cli(argv, stdout: $stdout, stderr: $stderr, env: ENV)
    options = {}
    parser = OptionParser.new do |opts|
      opts.on('--root PATH') { |value| options[:root] = value }
      opts.on('--candidate PATH') { |value| options[:candidate] = value }
      opts.on('--observation-output PATH') { |value| options[:observation_output] = value }
      opts.on('--preflight-output PATH') { |value| options[:preflight_output] = value }
      opts.on('--observation-id ID') { |value| options[:observation_id] = value }
      opts.on('--preflight-id ID') { |value| options[:preflight_id] = value }
      opts.on('--observation-observed-at TIME') { |value| options[:observation_observed_at] = value }
      opts.on('--preflight-observed-at TIME') { |value| options[:preflight_observed_at] = value }
      opts.on('--expires-at TIME') { |value| options[:expires_at] = value }
    end
    parser.parse!(argv)
    required = %i[root candidate observation_output preflight_output observation_id preflight_id
                  observation_observed_at preflight_observed_at expires_at]
    raise UsageError, 'missing required option' unless argv.empty? && required.all? { |key| options.key?(key) }
    result = if canonical_root_requested?(options.fetch(:root))
               generate!(**options)
             else
               generate!(**options, env: env)
             end
    stdout.write(JSON.generate(result) + "\n")
    0
  rescue OptionParser::ParseError, UsageError
    stderr.write("usage_error: invalid command-line usage\n")
    2
  rescue Error
    stderr.write("evidence_error: Gate-B evidence preflight rejected\n")
    1
  end
end

exit G0GovernanceV2GateBEvidence.run_cli(ARGV) if $PROGRAM_NAME == __FILE__
