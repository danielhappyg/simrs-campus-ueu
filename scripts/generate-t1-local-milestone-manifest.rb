#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'open3'
require 'pathname'
require 'tempfile'

require_relative 'rehearse-local-portability-full-suite'

module T1LocalMilestoneManifest
  class ContractError < StandardError; end

  class DuplicateKeyHash < Hash
    def []=(key, value)
      raise JSON::ParserError, "duplicate JSON object key #{key.inspect}" if key?(key)

      super
    end
  end

  ROOT = File.expand_path('..', __dir__).freeze
  REQUIRED_SHA = 'd04b35f1f85ab0e6e56818d08c374f3a5bb3cb88'
  MANIFEST_PATH = 'docs/operations/T1_LOCAL_MILESTONE_MANIFEST_2026-08-26.json'
  APPROVAL_PACK_PATH = 'docs/operations/T1_LOCAL_MILESTONE_APPROVAL_PACK_2026-08-26.md'
  GENERATOR_PATH = 'scripts/generate-t1-local-milestone-manifest.rb'
  TEST_PATH = 'tests/Documentation/T1LocalMilestoneManifestTest.rb'
  GIT_BINARY = '/usr/bin/git'

  PROTECTED_PATHS = [
    { 'path' => 'deliverables/', 'kind' => 'prefix' },
    { 'path' => 'docs/legacy-visual-field-capture/', 'kind' => 'prefix' },
    { 'path' => 'docs/operations/UAT_20260820_002_003_DRAFT_ORDER_REMEDIATION.md', 'kind' => 'exact' },
    { 'path' => 'lang/', 'kind' => 'prefix' }
  ].freeze

  LOCAL_EVIDENCE = [
    {
      'evidence_id' => 'postgresql_17_daily_queue_concurrency',
      'path' => 'storage/app/queue-allocation-rehearsals/20260826T145253Z-e1653e619733.json',
      'claim_boundary' => 'Local disposable PostgreSQL 17 atomic daily queue allocation and rollback-reuse evidence only; not hosted, MySQL 8.4, capacity or SLA evidence.'
    },
    {
      'evidence_id' => 'postgresql_17_query_plans',
      'path' => 'storage/app/query-plan-rehearsals/20260826T144520Z-f4b60367a444.json',
      'claim_boundary' => 'Local single-user PostgreSQL 17 query-plan topology evidence only; not hosted latency, concurrency, capacity or SLA evidence.'
    },
    {
      'evidence_id' => 'postgresql_17_recovery',
      'path' => 'storage/app/recovery-rehearsals/20260826T143840Z-6f2d1cc12247.json',
      'claim_boundary' => 'Local same-host disposable PostgreSQL 17 backup and restore evidence only; not an approved RPO, RTO or hosted disaster-recovery claim.'
    },
    {
      'evidence_id' => 'postgresql_17_current_manifest_portability',
      'path' => 'storage/app/portability-rehearsals/20260826T212541Z-postgresql17-87c90954250d.json',
      'claim_boundary' => 'Harness-owned local disposable PostgreSQL 17.10 Unix-socket cluster: fresh migration, full application suite and eight focused workflow slices for bound current bytes only; not hosted, load, contention, PHP 8.3 or owner-acceptance evidence.'
    },
    {
      'evidence_id' => 'mysql_8_4_current_manifest_portability',
      'path' => 'storage/app/portability-rehearsals/20260826T212710Z-mysql8411-7d45f8dbe886.json',
      'claim_boundary' => 'Local disposable exact MySQL 8.4.11 fresh migration, full application suite and eight focused workflow slices for bound current bytes only; not hosted, load, contention, PHP 8.3 or owner-acceptance evidence.'
    }
  ].freeze

  PORTABILITY_EVIDENCE_IDS = {
    'postgresql_17_current_manifest_portability' => 'postgresql',
    'mysql_8_4_current_manifest_portability' => 'mysql'
  }.freeze
  PORTABILITY_TOP_LEVEL_KEYS = %w[
    schema_version kind status recorded_at_utc claim hosted_readiness_claim
    deployment_claim owner_acceptance_claim baseline_git_sha working_tree_state
    local_manifest backend_execution_source_set migration_set harness_sha256
    workflow_test_catalog_sha256 php_version boundary engine migration full_suite
    workflow_slices cleanup open_boundaries
  ].freeze
  PORTABILITY_SHARED_BINDINGS = %w[
    local_manifest backend_execution_source_set migration_set harness_sha256
    workflow_test_catalog_sha256
  ].freeze
  SHA256_PATTERN = /\A[0-9a-f]{64}\z/.freeze

  SAFE_SEGMENT = /\A[A-Za-z0-9._@+\-]+\z/.freeze
  SENSITIVE_PATH = /(?:\A|\/)(?:\.env(?:\.|\z)|id_(?:rsa|ed25519)(?:\.|\z)|credentials?(?:\.|\z)|secrets?(?:\.|\z)|[^\/]+\.(?:key|pem|p12|pfx)\z)/i.freeze
  SECRET_PATTERNS = [
    /-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----\r?\n[A-Za-z0-9+\/=\r\n]{80,}-----END [A-Z0-9 ]*PRIVATE KEY-----/,
    /\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/,
    /\bBearer[ \t]+[A-Za-z0-9._~+\/-]{32,}={0,2}\b/i,
    /\bBasic[ \t]+[A-Za-z0-9+\/]{32,}={0,2}\b/i,
    %r{(?:https?|postgres(?:ql)?|mysql)://[^\s/:@]+:[^\s/@]+@}i,
    /\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/
  ].freeze

  module_function

  def git(*arguments)
    stdout, stderr, status = Open3.capture3(GIT_BINARY, '-C', ROOT, *arguments)
    raise ContractError, "git #{arguments.join(' ')} failed: #{stderr.strip}" unless status.success?

    stdout
  end

  def assert_repository!
    raise ContractError, 'generator must remain inside its fixed repository root' unless File.realpath(ROOT) == ROOT
    top = git('rev-parse', '--show-toplevel').strip
    raise ContractError, 'fixed repository root does not match git top level' unless File.realpath(top) == ROOT

    head = git('rev-parse', 'HEAD').strip
    origin_main = git('rev-parse', 'origin/main').strip
    raise ContractError, "HEAD must equal #{REQUIRED_SHA}" unless head == REQUIRED_SHA
    raise ContractError, "origin/main must equal #{REQUIRED_SHA}" unless origin_main == REQUIRED_SHA

    _stdout, _stderr, staged = Open3.capture3(GIT_BINARY, '-C', ROOT, 'diff', '--cached', '--quiet', '--exit-code')
    raise ContractError, 'staging area must be empty' unless staged.success?
  end

  def protected_path?(path)
    PROTECTED_PATHS.any? do |entry|
      entry['kind'] == 'exact' ? path == entry['path'] : path.start_with?(entry['path'])
    end
  end

  def validate_relative_path!(path)
    raise ContractError, 'candidate path must be valid UTF-8' unless path.encoding == Encoding::UTF_8 && path.valid_encoding?
    raise ContractError, "unsafe or suspicious candidate path #{path.inspect}" if path.empty? || path.start_with?('/') || path.include?("\\")
    segments = path.split('/', -1)
    if segments.any? { |segment| segment.empty? || segment == '.' || segment == '..' || !segment.match?(SAFE_SEGMENT) || segment.start_with?('-') }
      raise ContractError, "unsafe or suspicious candidate path #{path.inspect}"
    end
    raise ContractError, "secret-looking candidate filename #{path.inspect}" if path.match?(SENSITIVE_PATH)

    expanded = File.expand_path(path, ROOT)
    raise ContractError, "candidate path escapes repository #{path.inspect}" unless expanded.start_with?("#{ROOT}#{File::SEPARATOR}")

    expanded
  end

  def parse_status(raw)
    raw = raw.dup.force_encoding(Encoding::BINARY)
    raise ContractError, 'git status output must be NUL terminated' unless raw.empty? || raw.end_with?("\0")

    fields = raw.split("\0", -1)
    fields.pop
    rows = []
    until fields.empty?
      entry = fields.shift
      raise ContractError, 'malformed git status entry' unless entry.bytesize >= 4 && entry.getbyte(2) == 32
      status = entry.byteslice(0, 2)
      path = entry.byteslice(3, entry.bytesize - 3).force_encoding(Encoding::UTF_8)
      if status.include?('R') || status.include?('C')
        raise ContractError, 'malformed rename/copy status entry' if fields.empty?
        fields.shift
      end
      raise ContractError, "staged or unsupported git status #{status.inspect} for #{path.inspect}" unless [' M', ' D', '??'].include?(status)

      rows << [status, path]
    end
    rows
  end

  def reject_secret_bytes!(bytes, label)
    raise ContractError, "secret-looking material in #{label}" if SECRET_PATTERNS.any? { |pattern| bytes.match?(pattern) }
  end

  def safe_worktree_bytes(path)
    expanded = validate_relative_path!(path)
    cursor = ROOT
    Pathname.new(path).each_filename do |segment|
      cursor = File.join(cursor, segment)
      raise ContractError, "candidate path uses symlink #{path.inspect}" if File.symlink?(cursor)
    end
    stat = File.lstat(expanded)
    raise ContractError, "candidate must be a regular file #{path.inspect}" unless stat.file?
    real = File.realpath(expanded)
    raise ContractError, "candidate path escapes repository #{path.inspect}" unless real.start_with?("#{ROOT}#{File::SEPARATOR}")

    bytes = File.binread(expanded)
    reject_secret_bytes!(bytes, path)
    bytes
  rescue SystemCallError => e
    raise ContractError, "cannot safely read candidate #{path.inspect}: #{e.message}"
  end


  def parse_json_object!(bytes, label)
    document = JSON.parse(bytes, object_class: DuplicateKeyHash, allow_duplicate_key: false)
    raise ContractError, "#{label} must contain one JSON object" unless document.is_a?(Hash)

    normalize_json_value(document)
  rescue JSON::ParserError => e
    raise ContractError, "malformed #{label}: #{e.message}"
  end

  def normalize_json_value(value)
    case value
    when Hash
      value.to_h { |key, child| [key, normalize_json_value(child)] }
    when Array
      value.map { |child| normalize_json_value(child) }
    else
      value
    end
  end

  def assert_exact_keys!(value, expected, label)
    raise ContractError, "#{label} must be a JSON object" unless value.is_a?(Hash)
    return if value.keys.sort == expected.sort

    raise ContractError, "#{label} keys do not match the closed contract"
  end

  def assert_exact_value!(value, expected, label)
    return if value == expected

    raise ContractError, "#{label} must equal #{expected.inspect}"
  end

  def assert_positive_integer!(value, label)
    return if value.is_a?(Integer) && value.positive?

    raise ContractError, "#{label} must be a positive integer"
  end

  def assert_sha256!(value, label)
    return if value.is_a?(String) && value.match?(SHA256_PATTERN)

    raise ContractError, "#{label} must be a lowercase SHA-256"
  end

  def validate_result_aggregate!(aggregate, label)
    assert_exact_keys!(aggregate, %w[tests passed skipped assertions duration_ms_reported duration_ms_observed], label)
    %w[tests passed skipped assertions].each do |key|
      value = aggregate[key]
      unless value.is_a?(Integer) && value >= 0
        raise ContractError, "#{label}.#{key} must be a non-negative integer"
      end
    end
    assert_positive_integer!(aggregate['tests'], "#{label}.tests")
    assert_positive_integer!(aggregate['assertions'], "#{label}.assertions")
    assert_positive_integer!(aggregate['duration_ms_reported'], "#{label}.duration_ms_reported")
    assert_positive_integer!(aggregate['duration_ms_observed'], "#{label}.duration_ms_observed")
    unless aggregate['passed'] + aggregate['skipped'] == aggregate['tests']
      raise ContractError, "#{label} does not account for every test as passed or skipped"
    end
  end

  def validate_portability_engine!(document, expected_engine, label)
    engine = document['engine']
    raise ContractError, "#{label}.engine must be a JSON object" unless engine.is_a?(Hash)

    case expected_engine
    when 'postgresql'
      legacy_keys = %w[engine engine_version engine_major application_schema disposable_database loopback_only]
      isolated_keys = %w[engine engine_version engine_major application_schema disposable_database isolated_server local_unix_socket_only]
      unless [legacy_keys.sort, isolated_keys.sort].include?(engine.keys.sort)
        raise ContractError, "#{label}.engine keys do not match a recognized PostgreSQL portability contract"
      end
      assert_exact_value!(engine['engine'], 'postgresql', "#{label}.engine.engine")
      assert_exact_value!(engine['engine_major'], 17, "#{label}.engine.engine_major")
      assert_exact_value!(engine['engine_version'], '170010', "#{label}.engine.engine_version")
      assert_exact_value!(engine['application_schema'], 'laravel', "#{label}.engine.application_schema")
      assert_exact_value!(engine['disposable_database'], true, "#{label}.engine.disposable_database")
      if engine.key?('isolated_server')
        assert_exact_value!(engine['isolated_server'], true, "#{label}.engine.isolated_server")
        assert_exact_value!(engine['local_unix_socket_only'], true, "#{label}.engine.local_unix_socket_only")
      else
        assert_exact_value!(engine['loopback_only'], true, "#{label}.engine.loopback_only")
      end
    when 'mysql'
      assert_exact_keys!(engine, %w[engine engine_version engine_major storage_engine application_schema disposable_database isolated_server loopback_only binary_logging_enabled trusted_function_creators], "#{label}.engine")
      assert_exact_value!(engine['engine'], 'mysql', "#{label}.engine.engine")
      assert_exact_value!(engine['engine_version'], '8.4.11', "#{label}.engine.engine_version")
      assert_exact_value!(engine['engine_major'], 8, "#{label}.engine.engine_major")
      assert_exact_value!(engine['storage_engine'], 'InnoDB', "#{label}.engine.storage_engine")
      assert_exact_value!(engine['application_schema'], 'database', "#{label}.engine.application_schema")
      %w[disposable_database isolated_server loopback_only binary_logging_enabled trusted_function_creators].each do |key|
        assert_exact_value!(engine[key], true, "#{label}.engine.#{key}")
      end
    else
      raise ContractError, "unsupported configured portability engine #{expected_engine.inspect}"
    end
  end

  def validate_portability_document!(document, evidence_id)
    label = "portability evidence #{evidence_id}"
    assert_exact_keys!(document, PORTABILITY_TOP_LEVEL_KEYS, label)
    assert_exact_value!(document['schema_version'], 1, "#{label}.schema_version")
    assert_exact_value!(document['kind'], 'SIMRS_LOCAL_PORTABILITY_FULL_SUITE', "#{label}.kind")
    assert_exact_value!(document['status'], 'PASS', "#{label}.status")
    assert_exact_value!(document['claim'], 'LOCAL_DISPOSABLE_EXACT_ENGINE_ONLY', "#{label}.claim")
    assert_exact_value!(document['hosted_readiness_claim'], false, "#{label}.hosted_readiness_claim")
    assert_exact_value!(document['deployment_claim'], false, "#{label}.deployment_claim")
    assert_exact_value!(document['owner_acceptance_claim'], false, "#{label}.owner_acceptance_claim")
    assert_exact_value!(document['baseline_git_sha'], REQUIRED_SHA, "#{label}.baseline_git_sha")
    unless document['recorded_at_utc'].is_a?(String) && document['recorded_at_utc'].match?(/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/)
      raise ContractError, "#{label}.recorded_at_utc must be a UTC second timestamp"
    end
    unless %w[CLEAN UNCOMMITTED_LOCAL_MILESTONE].include?(document['working_tree_state'])
      raise ContractError, "#{label}.working_tree_state is outside the closed local contract"
    end
    unless document['php_version'].is_a?(String) && document['php_version'].match?(/\A[0-9]+\.[0-9]+\.[0-9]+\z/)
      raise ContractError, "#{label}.php_version must be an exact semantic version"
    end

    manifest = document['local_manifest']
    assert_exact_keys!(manifest, %w[artifact_id sha256 candidate_count], "#{label}.local_manifest")
    assert_exact_value!(manifest['artifact_id'], 'T1-LOCAL-MILESTONE-2026-08-26', "#{label}.local_manifest.artifact_id")
    assert_sha256!(manifest['sha256'], "#{label}.local_manifest.sha256")
    assert_positive_integer!(manifest['candidate_count'], "#{label}.local_manifest.candidate_count")

    %w[backend_execution_source_set migration_set].each do |binding_name|
      binding = document[binding_name]
      assert_exact_keys!(binding, %w[file_count sha256], "#{label}.#{binding_name}")
      assert_positive_integer!(binding['file_count'], "#{label}.#{binding_name}.file_count")
      assert_sha256!(binding['sha256'], "#{label}.#{binding_name}.sha256")
    end
    assert_sha256!(document['harness_sha256'], "#{label}.harness_sha256")
    assert_sha256!(document['workflow_test_catalog_sha256'], "#{label}.workflow_test_catalog_sha256")

    boundary = document['boundary']
    assert_exact_keys!(boundary, %w[application_mode synthetic_only break_glass_mode production_integrations_configured disposable_local_engine], "#{label}.boundary")
    assert_exact_value!(boundary['application_mode'], 'SIMULATION', "#{label}.boundary.application_mode")
    assert_exact_value!(boundary['synthetic_only'], true, "#{label}.boundary.synthetic_only")
    assert_exact_value!(boundary['break_glass_mode'], 'off', "#{label}.boundary.break_glass_mode")
    assert_exact_value!(boundary['production_integrations_configured'], false, "#{label}.boundary.production_integrations_configured")
    assert_exact_value!(boundary['disposable_local_engine'], true, "#{label}.boundary.disposable_local_engine")

    migration = document['migration']
    assert_exact_keys!(migration, %w[fresh_apply duration_ms_observed], "#{label}.migration")
    assert_exact_value!(migration['fresh_apply'], 'PASS', "#{label}.migration.fresh_apply")
    assert_positive_integer!(migration['duration_ms_observed'], "#{label}.migration.duration_ms_observed")
    validate_result_aggregate!(document['full_suite'], "#{label}.full_suite")

    workflow_slices = document['workflow_slices']
    assert_exact_keys!(workflow_slices, LocalPortabilityFullSuiteRehearsal::WORKFLOW_SLICES.keys, "#{label}.workflow_slices")
    workflow_slices.each do |workflow_id, aggregate|
      validate_result_aggregate!(aggregate, "#{label}.workflow_slices.#{workflow_id}")
    end

    cleanup = document['cleanup']
    assert_exact_keys!(cleanup, %w[database_removed temporary_server_removed], "#{label}.cleanup")
    assert_exact_value!(cleanup['database_removed'], true, "#{label}.cleanup.database_removed")
    expected_temporary_server_removed = PORTABILITY_EVIDENCE_IDS.fetch(evidence_id) == 'mysql' || document.fetch('engine').key?('isolated_server')
    assert_exact_value!(cleanup['temporary_server_removed'], expected_temporary_server_removed, "#{label}.cleanup.temporary_server_removed")
    boundaries = document['open_boundaries']
    unless boundaries.is_a?(Array) && !boundaries.empty? && boundaries.all? { |entry| entry.is_a?(String) && !entry.empty? }
      raise ContractError, "#{label}.open_boundaries must be a non-empty string array"
    end
    validate_portability_engine!(document, PORTABILITY_EVIDENCE_IDS.fetch(evidence_id), label)
  end

  # The evidence records bind the byte-current manifest that authorized their
  # pre-run inventory. Requiring that SHA to equal the post-run manifest would
  # be circular because the final manifest adds the new evidence paths/hashes.
  # We therefore require the two records to share the same well-formed pre-run
  # manifest provenance, but compare every executable binding to the live pure
  # binding API owned by the rehearsal harness.
  def validate_portability_evidence_documents!(documents, enforce_currentness: true, live_bindings: nil)
    assert_exact_keys!(documents, PORTABILITY_EVIDENCE_IDS.keys, 'configured portability evidence set')
    documents.each { |evidence_id, document| validate_portability_document!(document, evidence_id) }

    reference_id = PORTABILITY_EVIDENCE_IDS.keys.first
    reference = documents.fetch(reference_id)
    PORTABILITY_SHARED_BINDINGS.each do |binding_name|
      documents.each do |evidence_id, document|
        next if evidence_id == reference_id
        unless document[binding_name] == reference[binding_name]
          raise ContractError, "portability evidence shared binding #{binding_name} disagrees between records"
        end
      end
    end

    return true unless enforce_currentness

    live = live_bindings || LocalPortabilityFullSuiteRehearsal.current_execution_bindings
    %w[backend_execution_source_set migration_set harness_sha256 workflow_test_catalog_sha256].each do |binding_name|
      unless reference[binding_name] == live[binding_name]
        raise ContractError, "stale portability evidence: #{binding_name} does not match the live harness-defined execution binding"
      end
    end
    postgresql = documents.fetch('postgresql_17_current_manifest_portability').fetch('engine')
    unless postgresql['isolated_server'] == true && postgresql['local_unix_socket_only'] == true
      raise ContractError, 'stale portability evidence: PostgreSQL must use the harness-owned isolated Unix-socket cluster contract'
    end
    true
  rescue LocalPortabilityFullSuiteRehearsal::CommandFailed => e
    raise ContractError, "cannot compute live portability binding: #{e.message}"
  end

  def deleted_head_bytes(path)
    validate_relative_path!(path)
    listing = git('ls-tree', '-z', 'HEAD', '--', path)
    fields = listing.split("\0", -1)
    fields.pop
    raise ContractError, "deleted candidate is not exactly one HEAD entry #{path.inspect}" unless fields.length == 1
    match = fields.first.match(/\A(100644|100755) blob ([0-9a-f]{40})\t(.+)\z/m)
    raise ContractError, "deleted candidate is not a regular HEAD blob #{path.inspect}" unless match && match[3] == path

    bytes = git('cat-file', 'blob', match[2])
    reject_secret_bytes!(bytes, "HEAD:#{path}")
    bytes
  end

  def candidate_inventory(status_bytes)
    excluded_count = 0
    seen = {}
    records = []
    parse_status(status_bytes).each do |status, path|
      if protected_path?(path)
        excluded_count += 1
        next
      end
      next if path == MANIFEST_PATH

      validate_relative_path!(path)
      raise ContractError, "duplicate candidate path #{path.inspect}" if seen[path]
      seen[path] = true
      state, basis, bytes = case status
                            when ' M'
                              ['modified', 'worktree_bytes', safe_worktree_bytes(path)]
                            when ' D'
                              ['deleted', 'head_bytes', deleted_head_bytes(path)]
                            when '??'
                              ['untracked', 'worktree_bytes', safe_worktree_bytes(path)]
                            end
      records << {
        'path' => path,
        'state' => state,
        'sha256' => Digest::SHA256.hexdigest(bytes),
        'sha256_basis' => basis
      }
    end
    [records.sort_by { |record| record['path'] }, excluded_count]
  end

  def local_evidence_inventory(enforce_portability_currentness: true)
    portability_documents = {}
    inventory = LOCAL_EVIDENCE.map do |source|
      path = source['path']
      raise ContractError, "local evidence path unexpectedly protected #{path.inspect}" if protected_path?(path)
      bytes = safe_worktree_bytes(path)
      _stdout, _stderr, ignored = Open3.capture3(GIT_BINARY, '-C', ROOT, 'check-ignore', '--quiet', '--', path)
      raise ContractError, "local evidence must remain ignored #{path.inspect}" unless ignored.success?
      mode = File.stat(File.join(ROOT, path)).mode & 0o777
      raise ContractError, "local evidence must remain mode 0600 #{path.inspect}" unless mode == 0o600

      if PORTABILITY_EVIDENCE_IDS.key?(source['evidence_id'])
        portability_documents[source['evidence_id']] = parse_json_object!(bytes, "portability evidence #{path}")
      end

      source.merge(
        'sha256' => Digest::SHA256.hexdigest(bytes),
        'ignored' => true,
        'content_in_manifest' => false
      )
    end
    validate_portability_evidence_documents!(portability_documents, enforce_currentness: enforce_portability_currentness)
    inventory
  end

  def build(enforce_portability_currentness: true, inventory_only: false)
    if inventory_only == enforce_portability_currentness
      raise ContractError, 'manifest mode must be either current publication or non-claiming inventory bootstrap'
    end
    assert_repository!
    status_bytes = git('status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none')
    candidates, excluded_count = candidate_inventory(status_bytes)
    counts = %w[modified untracked deleted].to_h { |state| [state, candidates.count { |record| record['state'] == state }] }
    {
      'schema_version' => 1,
      'artifact_id' => 'T1-LOCAL-MILESTONE-2026-08-26',
      'snapshot_date' => '2026-08-26',
      'classification' => 'LOCAL',
      'deployment_status' => 'NOT_DEPLOYED',
      'repository' => {
        'required_sha' => REQUIRED_SHA,
        'head' => REQUIRED_SHA,
        'origin_main' => REQUIRED_SHA,
        'staging_empty' => true
      },
      'scope' => {
        'candidate_count' => candidates.length,
        'state_counts' => counts,
        'manifest_self_hash_excluded' => true,
        'protected_status_entry_count' => excluded_count,
        'protected_paths' => PROTECTED_PATHS,
        'protected_path_policy' => 'Excluded before file inspection; never opened, hashed or added to the candidate inventory.'
      },
      'candidate_files' => candidates,
      'ignored_local_evidence' => local_evidence_inventory(enforce_portability_currentness: enforce_portability_currentness),
      'actions_performed' => {
        'publication' => false,
        'deployment' => false,
        'hosted_migration' => false,
        'github_actions_run' => false
      },
      'open_boundaries' => {
        'G0' => 'OPEN: formal institutional authority, named owner decisions and governance verification remain incomplete.',
        'G3' => 'OPEN: the current coverage ledger keeps the formal gate open; runtime, automated evidence, hosted UAT, reconciliation and owner acceptance remain incomplete.',
        'exact_engine_portability' => inventory_only ?
          'UNVERIFIED_FOR_BOOTSTRAP: local evidence structure and conservative claims are valid, but executable-binding currentness is deliberately not claimed; ordinary --check remains required after fresh rehearsals.' :
          'PASS locally for the bound backend, migration and workflow-catalog digests on PostgreSQL 17 and exact MySQL 8.4.11 under the recorded host PHP; the shared pre-run manifest binding is provenance, while hosted execution, PHP 8.3 parity, contention/load and owner acceptance remain open.',
        'hosted_uat' => 'NOT_RUN for this local milestone; no authenticated role-based hosted UAT claim is made.',
        'owner_acceptance' => 'NOT_ACCEPTED for this local milestone; reference presence and engineering evidence are not owner approval.',
        'security_findings' => 'OPEN/UNRECONCILED for this local milestone: no P0/P1 closure claim is made for dirty bytes, and the previously recorded blocking hosted legacy audit row remains a cutover precondition.'
      }
    }
  end

  def build_inventory
    build(enforce_portability_currentness: false, inventory_only: true)
  end

  def serialized(enforce_portability_currentness: true, inventory_only: false)
    JSON.pretty_generate(build(enforce_portability_currentness: enforce_portability_currentness, inventory_only: inventory_only)) + "\n"
  end

  def write!(inventory_only: false)
    bytes = inventory_only ? serialized(enforce_portability_currentness: false, inventory_only: true) : serialized
    target = File.join(ROOT, MANIFEST_PATH)
    Tempfile.create(['t1-local-milestone-manifest', '.json'], File.dirname(target)) do |file|
      file.binmode
      file.write(bytes)
      file.flush
      file.fsync
      File.chmod(0o644, file.path)
      File.rename(file.path, target)
    end
    true
  end

  def write_inventory!
    write!(inventory_only: true)
  end

  def check!
    target = File.join(ROOT, MANIFEST_PATH)
    raise ContractError, "manifest missing: #{MANIFEST_PATH}" unless File.file?(target) && !File.symlink?(target)
    actual = File.binread(target)
    expected = serialized
    raise ContractError, "stale manifest: run ruby #{GENERATOR_PATH} --write" unless actual == expected

    true
  end


  def verify_inventory_bytes!(actual)
    expected = serialized(enforce_portability_currentness: false, inventory_only: true)
    unless actual == expected
      raise ContractError, "stale inventory-bootstrap manifest: run ruby #{GENERATOR_PATH} --write-inventory"
    end

    true
  end

  def check_inventory!
    target = File.join(ROOT, MANIFEST_PATH)
    raise ContractError, "manifest missing: #{MANIFEST_PATH}" unless File.file?(target) && !File.symlink?(target)

    verify_inventory_bytes!(File.binread(target))
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    actions = %w[--write --check --write-inventory --check-inventory]
    unless ARGV.length == 1 && actions.include?(ARGV.first)
      raise T1LocalMilestoneManifest::ContractError, 'usage: generate-t1-local-milestone-manifest.rb (--write|--check|--write-inventory|--check-inventory)'
    end
    message = case ARGV.first
              when '--write'
                T1LocalMilestoneManifest.write!
                'wrote deterministic LOCAL/NOT_DEPLOYED milestone manifest'
              when '--check'
                T1LocalMilestoneManifest.check!
                'milestone manifest is current'
              when '--write-inventory'
                T1LocalMilestoneManifest.write_inventory!
                'wrote inventory-bootstrap LOCAL/NOT_DEPLOYED manifest without a portability-currentness claim'
              when '--check-inventory'
                T1LocalMilestoneManifest.check_inventory!
                'inventory-bootstrap manifest is byte-current; portability currentness is not claimed'
              end
    puts message
  rescue T1LocalMilestoneManifest::ContractError => e
    warn "ERROR: #{e.message}"
    exit 1
  end
end
