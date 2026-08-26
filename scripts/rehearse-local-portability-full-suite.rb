#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'etc'
require 'fileutils'
require 'find'
require 'json'
require 'open3'
require 'rbconfig'
require 'securerandom'
require 'socket'
require 'time'
require 'tmpdir'

class LocalPortabilityFullSuiteRehearsal
  ROOT = File.expand_path('..', __dir__).freeze
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_EXACT_ENGINE_SUITE'
  ENGINES = %w[postgresql17 mysql8411].freeze
  POSTGRES_DATABASE_PATTERN = /\Asimrs_portability_pg_[0-9a-f]{12}\z/
  MYSQL_DATABASE_PATTERN = /\Asimrs_portability_my_[0-9a-f]{12}\z/
  MYSQL_USER_PATTERN = /\Asimrs_p_[0-9a-f]{12}\z/
  POSTGRES_VERSION = '17.10'
  POSTGRES_DEFAULT_BIN = '/opt/homebrew/opt/postgresql@17/bin'
  MYSQL_VERSION = '8.4.11'
  MYSQL_DEFAULT_BIN = '/opt/homebrew/opt/mysql@8.4/bin'
  PHP_DEFAULT_BINARY = '/opt/homebrew/bin/php'
  GIT_DEFAULT_BINARY = '/usr/bin/git'
  MANIFEST_PATH = 'docs/operations/T1_LOCAL_MILESTONE_MANIFEST_2026-08-26.json'
  MANIFEST_CHECKER = 'scripts/generate-t1-local-milestone-manifest.rb'
  EVIDENCE_DIRECTORY = File.join(ROOT, 'storage/app/portability-rehearsals').freeze

  WORKFLOW_SLICES = {
    'E2E-01' => %w[
      tests/Feature/Outpatient/OutpatientFlowTest.php
      tests/Feature/Emergency/EmergencyFlowTest.php
      tests/Feature/Inpatient/InpatientFlowTest.php
      tests/Feature/Registration/DailyQueueAllocatorTest.php
      tests/Feature/Database/DailyQueueAllocatorMigrationTest.php
    ],
    'E2E-02' => %w[
      tests/Feature/Emergency/EmergencyFlowTest.php
      tests/Feature/Emergency/ContinuousEmergencyTeachingJourneyTest.php
      tests/Feature/Clinical/LockedClinicalEntryWriterTest.php
    ],
    'E2E-03' => %w[
      tests/Feature/Outpatient/ContinuousOutpatientTeachingJourneyTest.php
    ],
    'E2E-04' => %w[
      tests/Feature/Inpatient/ContinuousInpatientTeachingJourneyTest.php
    ],
    'E2E-05' => %w[
      tests/Feature/Outpatient/OutpatientLabFlowTest.php
      tests/Feature/Outpatient/OutpatientLifecycleContractTest.php
    ],
    'E2E-12' => %w[
      tests/Feature/Outpatient/StructuredOutpatientDocumentationTest.php
    ],
    'E2E-15' => %w[
      tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php
    ],
    'E2E-16' => %w[
      tests/Feature/Authorization/RoleCapabilityDenialTest.php
      tests/Feature/Authorization/ReconcileRebuildAdminCommandTest.php
      tests/Feature/Audit/AuditRecorderSafetyTest.php
      tests/Feature/RequestCorrelationTest.php
      tests/Feature/PrivilegedAccess/PrivilegedAccessShadowResolverTest.php
      tests/Feature/PrivilegedAccess/ProtectedSecurityFactsDatabaseTest.php
      tests/Feature/PrivilegedAccess/SecurityLedgerWriterTest.php
    ]
  }.freeze

  SOURCE_DIRECTORIES = %w[
    app
    config
    database/factories
    database/migrations
    database/seeders
    routes
    tests
  ].freeze
  SOURCE_FILES = %w[
    artisan
    bootstrap/app.php
    bootstrap/providers.php
    composer.json
    composer.lock
    phpunit.xml
    scripts/rehearse-local-portability-full-suite.rb
  ].freeze

  SENSITIVE_KEYS = /\A(?:password|secret|token|dsn|database_name|database_user|host|port|pid|socket|raw_output)\z/i
  LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', '/tmp', '/private/tmp'].freeze

  class CommandFailed < StandardError; end

  class Runner
    SAFE_PATH = '/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin'.freeze
    SAFE_INHERITED_ENVIRONMENT = %w[TMPDIR LANG LC_ALL TZ USER LOGNAME].freeze

    def run!(argv, env: {}, stdin_data: '')
      stdout, stderr, status = Open3.capture3(
        safe_environment.merge(env),
        *argv,
        stdin_data: stdin_data,
        unsetenv_others: true
      )
      return stdout if status.success?

      reason = sanitize(stderr.empty? ? stdout : stderr)
      suffix = reason.empty? ? '' : ": #{reason}"
      raise CommandFailed, "#{File.basename(argv.fetch(0))} failed with exit status #{status.exitstatus}#{suffix}"
    end

    def run_cleanup(argv, env: {}, stdin_data: '')
      Open3.capture3(
        safe_environment.merge(env),
        *argv,
        stdin_data: stdin_data,
        unsetenv_others: true
      )
    rescue StandardError
      nil
    end

    def process_environment(extra = {})
      safe_environment.merge(extra)
    end

    def sanitize(value)
      value.to_s.lines.map(&:strip).reject(&:empty?).last(20).join(' | ')
        .gsub(/\e\[[0-9;?]*[ -\/]*[@-~]/, '')
        .gsub(/simrs_portability_(?:pg|my)_[0-9a-f]{12}/, '<disposable-db>')
        .gsub(/simrs_p_[0-9a-f]{12}/, '<disposable-user>')
        .gsub(%r{(?:postgres(?:ql)?|mysql)://\S+}i, '<redacted-dsn>')
        .gsub(/(password|secret|token)\s*[=:]\s*\S+/i, '\\1=<redacted>')
        .slice(0, 1_200)
    end

    private

    def safe_environment
      SAFE_INHERITED_ENVIRONMENT.each_with_object({ 'PATH' => SAFE_PATH }) do |name, safe|
        safe[name] = ENV[name] if ENV.key?(name)
      end
    end
  end

  def self.current_execution_bindings
    harness = allocate
    harness.instance_variable_set(:@runner, Runner.new)
    harness.current_execution_bindings
  end

  def initialize(engine:, environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @engine = engine
    @environment = environment
    @runner = runner
    @clock = monotonic_clock || -> { Process.clock_gettime(Process::CLOCK_MONOTONIC) }
    @application_key = "base64:#{Base64.strict_encode64(SecureRandom.random_bytes(32))}"
    @php_binary = trusted_executable(PHP_DEFAULT_BINARY, 'PHP')
    @ruby_binary = trusted_executable(RbConfig.ruby, 'Ruby')
    @git_binary = trusted_executable(GIT_DEFAULT_BINARY, 'Git')
    @postgres_database = nil
    @postgres_pid = nil
    @postgres_temp_directory = nil
    @postgres_log_handle = nil
    @mysql_database = nil
    @mysql_user = nil
    @mysql_password = nil
    @mysql_temp_directory = nil
    @mysql_pid = nil
    @mysql_log_handle = nil
  end

  def run!
    assert_contract!
    execution_bindings = current_execution_bindings
    manifest_binding = current_manifest_binding
    engine_binding = prepare_engine!

    migration_started = @clock.call
    run_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(migration_started)

    full_suite = run_test_suite!([])
    workflow_results = WORKFLOW_SLICES.transform_values { |paths| run_test_suite!(paths) }
    assert_no_non_synthetic_patients!

    assert_unchanged_binding!('Execution source bindings', execution_bindings, current_execution_bindings)
    assert_unchanged_binding!('Local milestone manifest', manifest_binding, current_manifest_binding)

    cleanup!(strict: true)
    evidence_path = write_evidence!(
      manifest_binding: manifest_binding,
      execution_bindings: execution_bindings,
      engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms,
      full_suite: full_suite,
      workflow_results: workflow_results
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_EXACT_ENGINE_ONLY',
      'engine' => @engine,
      'evidence_path' => evidence_path,
      'full_suite' => full_suite,
      'workflow_count' => workflow_results.length
    }
  ensure
    cleanup!
  end

  def assert_contract!
    raise CommandFailed, "engine must be one of #{ENGINES.join(', ')}" unless ENGINES.include?(@engine)
    unless @environment['SIMRS_PORTABILITY_REHEARSAL_CONFIRM'] == CONFIRMATION
      raise CommandFailed, "Set SIMRS_PORTABILITY_REHEARSAL_CONFIRM=#{CONFIRMATION} to authorize disposable local database creation."
    end
    raise CommandFailed, 'Portability rehearsal refuses any DB_URL.' unless @environment.fetch('DB_URL', '').strip.empty?
    forbidden_postgres = %w[PGHOST PGPORT PGUSER PGPASSWORD].select { |name| !@environment.fetch(name, '').strip.empty? }
    unless forbidden_postgres.empty?
      raise CommandFailed, "Portability rehearsal creates its own PostgreSQL cluster and refuses #{forbidden_postgres.join(', ')}."
    end
    %w[POSTGRES17_BIN MYSQL84_BIN].each do |name|
      raise CommandFailed, "Portability rehearsal refuses executable override #{name}." unless @environment.fetch(name, '').strip.empty?
    end
    raise CommandFailed, 'Portability rehearsal must run from its repository-owned path.' unless File.realpath(ROOT) == ROOT

    required_paths = SOURCE_FILES + WORKFLOW_SLICES.values.flatten + [MANIFEST_PATH, MANIFEST_CHECKER]
    required_paths.uniq.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Workflow slice IDs must be the exact approved current set.' unless WORKFLOW_SLICES.keys == %w[E2E-01 E2E-02 E2E-03 E2E-04 E2E-05 E2E-12 E2E-15 E2E-16]
  end

  def application_environment
    database = case @engine
               when 'postgresql17' then postgres_application_environment
               when 'mysql8411' then mysql_application_environment
               else raise CommandFailed, 'Unsupported engine.'
               end

    {
      'APP_ENV' => 'testing',
      'APP_KEY' => @application_key,
      'APP_MODE' => 'SIMULATION',
      'APP_SYNTHETIC_ONLY' => 'true',
      'APP_MAINTENANCE_DRIVER' => 'file',
      'BCRYPT_ROUNDS' => '4',
      'BROADCAST_CONNECTION' => 'null',
      'CACHE_STORE' => 'array',
      'QUEUE_CONNECTION' => 'sync',
      'SESSION_DRIVER' => 'array',
      'MAIL_MAILER' => 'array',
      'PULSE_ENABLED' => 'false',
      'TELESCOPE_ENABLED' => 'false',
      'NIGHTWATCH_ENABLED' => 'false',
      'DEMO_SEED_ENABLED' => 'false',
      'BREAK_GLASS_MODE' => 'off',
      'BREAK_GLASS_GLOBAL_DISABLED' => 'true',
      'BREAK_GLASS_SESSION_HMAC_KEY' => '',
      'VERCEL_GIT_COMMIT_SHA' => '',
      'POSTMARK_API_KEY' => '',
      'RESEND_API_KEY' => '',
      'AWS_ACCESS_KEY_ID' => '',
      'AWS_SECRET_ACCESS_KEY' => '',
      'SLACK_BOT_USER_OAUTH_TOKEN' => '',
      'DB_URL' => '',
      'LOG_CHANNEL' => 'stderr'
    }.merge(database)
  end

  def backend_source_binding
    records = source_paths.map do |path|
      relative = path.delete_prefix("#{ROOT}/")
      [relative, Digest::SHA256.file(path).hexdigest]
    end
    {
      'file_count' => records.length,
      'sha256' => Digest::SHA256.hexdigest(JSON.generate(records))
    }
  end

  def current_execution_bindings
    migration_records = Dir.glob(File.join(ROOT, 'database/migrations/*.php')).sort.map do |migration|
      [File.basename(migration), Digest::SHA256.file(migration).hexdigest]
    end
    {
      'backend_execution_source_set' => backend_source_binding,
      'migration_set' => {
        'file_count' => migration_records.length,
        'sha256' => Digest::SHA256.hexdigest(JSON.generate(migration_records))
      },
      'harness_sha256' => Digest::SHA256.file(File.join(ROOT, 'scripts/rehearse-local-portability-full-suite.rb')).hexdigest,
      'workflow_test_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(WORKFLOW_SLICES))
    }
  end

  def sanitize_evidence!(value, path = '$')
    case value
    when Hash
      value.each do |key, child|
        raise CommandFailed, "Evidence contains forbidden key at #{path}.#{key}." if key.to_s.match?(SENSITIVE_KEYS)
        sanitize_evidence!(child, "#{path}.#{key}")
      end
    when Array
      value.each_with_index { |child, index| sanitize_evidence!(child, "#{path}[#{index}]") }
    when String
      raise CommandFailed, "Evidence contains a connection string at #{path}." if value.match?(%r{(?:postgres(?:ql)?|mysql)://}i)
      raise CommandFailed, "Evidence contains a generated database identifier at #{path}." if value.match?(/simrs_portability_(?:pg|my)_[0-9a-f]{12}/)
      raise CommandFailed, "Evidence contains a generated user identifier at #{path}." if value.match?(/simrs_p_[0-9a-f]{12}/)
    end
    value
  end

  private

  def assert_unchanged_binding!(label, before, after)
    raise CommandFailed, "#{label} changed during the portability rehearsal." unless before == after
  end

  def trusted_executable(path, label)
    real = File.realpath(path)
    stat = File.lstat(real)
    unless stat.file? && File.executable?(real) && !stat.symlink?
      raise CommandFailed, "#{label} executable is not a trusted regular executable."
    end
    real
  rescue SystemCallError => e
    raise CommandFailed, "Cannot resolve trusted #{label} executable: #{@runner.sanitize(e.message)}"
  end

  def trusted_binary_directory(path, required_tools, label)
    real = File.realpath(path)
    stat = File.lstat(real)
    raise CommandFailed, "#{label} binary directory is not trusted." unless stat.directory? && !stat.symlink?
    required_tools.each { |tool| trusted_executable(File.join(real, tool), "#{label} #{tool}") }
    real
  rescue SystemCallError => e
    raise CommandFailed, "Cannot resolve trusted #{label} binary directory: #{@runner.sanitize(e.message)}"
  end

  def assert_evidence_directory!
    parent = safe_source_path('storage/app', file_only: false)
    raise CommandFailed, 'Evidence parent path escapes the repository.' unless File.realpath(parent).start_with?("#{ROOT}/")
    File.mkdir(EVIDENCE_DIRECTORY, 0o700) unless File.exist?(EVIDENCE_DIRECTORY)
    stat = File.lstat(EVIDENCE_DIRECTORY)
    unless stat.directory? && !stat.symlink? && File.realpath(EVIDENCE_DIRECTORY).start_with?("#{ROOT}/") && (stat.mode & 0o777) == 0o700
      raise CommandFailed, 'Portability evidence directory must be a repository-contained, non-symlink mode-0700 directory.'
    end
  rescue SystemCallError => e
    raise CommandFailed, "Cannot validate portability evidence directory: #{@runner.sanitize(e.message)}"
  end

  def prepare_engine!
    case @engine
    when 'postgresql17' then prepare_postgresql!
    when 'mysql8411' then prepare_mysql!
    else raise CommandFailed, 'Unsupported engine.'
    end
  end

  def prepare_postgresql!
    @postgres_bin = trusted_binary_directory(
      POSTGRES_DEFAULT_BIN,
      %w[postgres initdb pg_isready psql createdb dropdb],
      'PostgreSQL 17'
    )
    postgres_version = @runner.run!([postgres_tool('postgres'), '--version']).strip
    initdb_version = @runner.run!([postgres_tool('initdb'), '--version']).strip
    unless postgres_version.include?("PostgreSQL) #{POSTGRES_VERSION}") && initdb_version.include?("PostgreSQL) #{POSTGRES_VERSION}")
      raise CommandFailed, "PostgreSQL rehearsal requires exactly #{POSTGRES_VERSION}."
    end

    @postgres_temp_directory = Dir.mktmpdir('sp17-', '/private/tmp')
    File.chmod(0o700, @postgres_temp_directory)
    @postgres_data_directory = File.join(@postgres_temp_directory, 'data')
    @postgres_socket_directory = File.join(@postgres_temp_directory, 'socket')
    @postgres_log_path = File.join(@postgres_temp_directory, 'postgres.log')
    FileUtils.mkdir(@postgres_socket_directory, mode: 0o700)
    @postgres_user = 'simrs_portability_owner'
    @postgres_port = available_loopback_port.to_s

    @runner.run!([
      postgres_tool('initdb'), '--no-sync', '--encoding=UTF8', '--locale=C',
      "--username=#{@postgres_user}", '--auth-local=trust', '--auth-host=reject',
      "--pgdata=#{@postgres_data_directory}"
    ])
    @postgres_log_handle = File.open(@postgres_log_path, File::WRONLY | File::CREAT | File::TRUNC, 0o600)
    @postgres_pid = Process.spawn(
      @runner.process_environment,
      postgres_tool('postgres'), '-D', @postgres_data_directory,
      '-k', @postgres_socket_directory, '-h', '', '-p', @postgres_port,
      '-c', 'unix_socket_permissions=0700',
      out: @postgres_log_handle,
      err: @postgres_log_handle,
      unsetenv_others: true
    )
    @postgres_host = @postgres_socket_directory
    wait_for_postgresql!

    version_num = @runner.run!(
      postgres_psql_arguments('postgres') + ['--tuples-only', '--no-align', '--command', 'SHOW server_version_num'],
      env: postgres_tool_environment
    ).strip
    raise CommandFailed, "PostgreSQL rehearsal requires exactly #{POSTGRES_VERSION}." unless version_num == '170010'

    @postgres_database = "simrs_portability_pg_#{SecureRandom.hex(6)}"
    assert_postgres_database_name!(@postgres_database)
    assert_postgres_database_absent!(@postgres_database)
    @runner.run!(postgres_connection_arguments('createdb') + ['--maintenance-db=postgres', @postgres_database], env: postgres_tool_environment)
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--command', 'CREATE SCHEMA laravel;'], env: postgres_tool_environment)

    {
      'engine' => 'postgresql',
      'engine_version' => version_num,
      'engine_major' => 17,
      'application_schema' => 'laravel',
      'disposable_database' => true,
      'isolated_server' => true,
      'local_unix_socket_only' => true
    }
  end

  def prepare_mysql!
    @mysql_bin = trusted_binary_directory(MYSQL_DEFAULT_BIN, %w[mysqld mysql mysqladmin], 'MySQL 8.4')
    version_output = @runner.run!([mysql_tool('mysqld'), '--version'])
    raise CommandFailed, "MySQL rehearsal requires exactly #{MYSQL_VERSION}." unless version_output.include?("Ver #{MYSQL_VERSION}")

    @mysql_temp_directory = Dir.mktmpdir('sp84-', '/private/tmp')
    File.chmod(0o700, @mysql_temp_directory)
    @mysql_data_directory = File.join(@mysql_temp_directory, 'data')
    @mysql_socket = File.join(@mysql_temp_directory, 'mysql.sock')
    @mysql_pid_file = File.join(@mysql_temp_directory, 'mysqld.pid')
    @mysql_log_path = File.join(@mysql_temp_directory, 'mysqld.log')
    @mysql_binlog_path = File.join(@mysql_temp_directory, 'binlog')
    FileUtils.mkdir_p(@mysql_data_directory, mode: 0o700)

    basedir = File.expand_path('..', @mysql_bin)
    @runner.run!([
      mysql_tool('mysqld'), '--no-defaults', '--initialize-insecure',
      "--basedir=#{basedir}", "--datadir=#{@mysql_data_directory}"
    ])

    @mysql_log_handle = File.open(@mysql_log_path, File::WRONLY | File::CREAT | File::TRUNC, 0o600)
    @mysql_port = available_loopback_port
    server_arguments = [
      mysql_tool('mysqld'), '--no-defaults', "--basedir=#{basedir}", "--datadir=#{@mysql_data_directory}",
      '--bind-address=127.0.0.1', "--port=#{@mysql_port}", "--socket=#{@mysql_socket}",
      "--pid-file=#{@mysql_pid_file}", "--log-error=#{@mysql_log_path}", '--skip-name-resolve',
      '--mysqlx=0', "--log-bin=#{@mysql_binlog_path}", '--log-bin-trust-function-creators=1'
    ]
    @mysql_pid = Process.spawn(
      @runner.process_environment,
      *server_arguments,
      out: @mysql_log_handle,
      err: @mysql_log_handle,
      unsetenv_others: true
    )
    wait_for_mysql!

    @mysql_database = "simrs_portability_my_#{SecureRandom.hex(6)}"
    @mysql_user = "simrs_p_#{SecureRandom.hex(6)}"
    @mysql_password = SecureRandom.hex(24)
    assert_mysql_database_name!(@mysql_database)
    raise CommandFailed, 'Generated MySQL user failed its closed pattern.' unless @mysql_user.match?(MYSQL_USER_PATTERN)
    create_sql = <<~SQL
      CREATE DATABASE `#{@mysql_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE USER '#{@mysql_user}'@'127.0.0.1' IDENTIFIED BY '#{@mysql_password}';
      GRANT ALL PRIVILEGES ON `#{@mysql_database}`.* TO '#{@mysql_user}'@'127.0.0.1';
      FLUSH PRIVILEGES;
    SQL
    @runner.run!(mysql_root_arguments, stdin_data: create_sql)

    {
      'engine' => 'mysql',
      'engine_version' => MYSQL_VERSION,
      'engine_major' => 8,
      'storage_engine' => 'InnoDB',
      'application_schema' => 'database',
      'disposable_database' => true,
      'isolated_server' => true,
      'loopback_only' => true,
      'binary_logging_enabled' => true,
      'trusted_function_creators' => true
    }
  end

  def run_artisan!(*arguments)
    @runner.run!([@php_binary, File.join(ROOT, 'artisan'), *arguments], env: application_environment)
  end

  def run_test_suite!(paths)
    started = @clock.call
    output = run_artisan!('test', *paths)
    result = parse_phpunit_result(output)
    result['duration_ms_observed'] = elapsed_ms(started)
    result
  end

  def parse_phpunit_result(output)
    document = nil
    output.lines.reverse_each do |candidate|
      parsed = JSON.parse(candidate.strip)
      if parsed.is_a?(Hash) && parsed['tool'] == 'phpunit'
        document = parsed
        break
      end
    rescue JSON::ParserError
      next
    end
    return parse_standard_phpunit_result(output) unless document

    unless document['tool'] == 'phpunit' && document['result'] == 'passed'
      raise CommandFailed, 'PHP test command did not return a passing aggregate result.'
    end
    %w[tests passed assertions duration_ms].each do |key|
      raise CommandFailed, "PHP test aggregate is missing #{key}." unless document[key].is_a?(Integer) && document[key] >= 0
    end
    {
      'tests' => document.fetch('tests'),
      'passed' => document.fetch('passed'),
      'skipped' => document.fetch('skipped', 0),
      'assertions' => document.fetch('assertions'),
      'duration_ms_reported' => document.fetch('duration_ms')
    }
  end

  def parse_standard_phpunit_result(output)
    plain = output.gsub(/\e\[[0-9;?]*[ -\/]*[@-~]/, '')
    tests_line = plain.lines.reverse_each.find { |line| line.include?('Tests:') }
    duration_line = plain.lines.reverse_each.find { |line| line.include?('Duration:') }
    passed = tests_line&.match(/([0-9,]+)\s+passed\b/)&.captures&.first&.delete(',')&.to_i
    skipped = tests_line&.match(/([0-9,]+)\s+skipped\b/)&.captures&.first&.delete(',')&.to_i || 0
    assertions = tests_line&.match(/\(([0-9,]+)\s+assertions?\)/)&.captures&.first&.delete(',')&.to_i
    seconds = duration_line&.match(/Duration:\s*([0-9]+(?:\.[0-9]+)?)s/)&.captures&.first&.to_f
    unless passed && assertions && seconds&.positive?
      summary = @runner.sanitize(output)
      suffix = summary.empty? ? '' : ": #{summary}"
      raise CommandFailed, "PHP test command returned no parseable aggregate result#{suffix}"
    end

    {
      'tests' => passed + skipped,
      'passed' => passed,
      'skipped' => skipped,
      'assertions' => assertions,
      'duration_ms_reported' => (seconds * 1000).round
    }
  end

  def assert_no_non_synthetic_patients!
    count = case @engine
            when 'postgresql17'
              @runner.run!(
                postgres_psql_arguments(@postgres_database) + [
                  '--tuples-only', '--no-align', '--command',
                  'SELECT count(*) FROM laravel.patients WHERE is_synthetic = FALSE'
                ],
                env: postgres_tool_environment
              ).strip
            when 'mysql8411'
              @runner.run!(mysql_root_arguments + [@mysql_database, '--batch', '--skip-column-names'], stdin_data: 'SELECT count(*) FROM patients WHERE is_synthetic = 0;').strip
            end
    raise CommandFailed, 'Portability rehearsal detected a non-synthetic patient row.' unless count == '0'
  end

  def current_manifest_binding
    @runner.run!([@ruby_binary, File.join(ROOT, MANIFEST_CHECKER), '--check-inventory'])
    path = safe_source_path(MANIFEST_PATH)
    manifest = JSON.parse(File.read(path))
    unless manifest['classification'] == 'LOCAL' && manifest['deployment_status'] == 'NOT_DEPLOYED'
      raise CommandFailed, 'Portability rehearsal requires a LOCAL/NOT_DEPLOYED manifest.'
    end
    unless manifest.fetch('actions_performed', {}).values.none?
      raise CommandFailed, 'Portability rehearsal refuses a manifest that records external actions.'
    end
    {
      'artifact_id' => manifest.fetch('artifact_id'),
      'sha256' => Digest::SHA256.file(path).hexdigest,
      'candidate_count' => manifest.dig('scope', 'candidate_count')
    }
  rescue JSON::ParserError, KeyError
    raise CommandFailed, 'Local milestone manifest is malformed.'
  end

  def source_paths
    paths = SOURCE_FILES.map { |relative| safe_source_path(relative) }
    SOURCE_DIRECTORIES.each do |relative_directory|
      directory = safe_source_path(relative_directory, file_only: false)
      Find.find(directory) do |candidate|
        next if candidate == directory
        stat = File.lstat(candidate)
        raise CommandFailed, "Backend source set uses a symlink: #{candidate.delete_prefix("#{ROOT}/")}." if stat.symlink?
        paths << candidate if stat.file?
      end
    end
    paths.uniq.sort
  end

  def safe_source_path(relative, file_only: true)
    unless relative.is_a?(String) && !relative.empty? && !relative.start_with?('/') && !relative.include?("\0")
      raise CommandFailed, 'Unsafe backend source path.'
    end
    parts = relative.split('/')
    raise CommandFailed, 'Unsafe backend source path.' if parts.any? { |part| part.empty? || part == '.' || part == '..' }
    expanded = File.expand_path(relative, ROOT)
    raise CommandFailed, 'Backend source path escapes repository.' unless expanded.start_with?("#{ROOT}/")
    cursor = ROOT
    parts.each do |part|
      cursor = File.join(cursor, part)
      raise CommandFailed, "Backend source path uses symlink: #{relative}." if File.symlink?(cursor)
    end
    stat = File.lstat(expanded)
    if file_only
      raise CommandFailed, "Backend source file is missing: #{relative}." unless stat.file?
    else
      raise CommandFailed, "Backend source directory is missing: #{relative}." unless stat.directory?
    end
    expanded
  rescue SystemCallError => e
    raise CommandFailed, "Cannot inspect backend source path: #{@runner.sanitize(e.message)}"
  end

  def write_evidence!(manifest_binding:, execution_bindings:, engine_binding:, migration_duration_ms:, full_suite:, workflow_results:)
    assert_evidence_directory!
    attempt = SecureRandom.hex(6)
    timestamp = Time.now.utc.strftime('%Y%m%dT%H%M%SZ')
    path = File.join(EVIDENCE_DIRECTORY, "#{timestamp}-#{@engine}-#{attempt}.json")
    head = @runner.run!([@git_binary, '-C', ROOT, 'rev-parse', 'HEAD']).strip
    dirty = !@runner.run!([@git_binary, '-C', ROOT, 'status', '--porcelain']).strip.empty?
    php_version = @runner.run!([@php_binary, '-r', 'echo PHP_VERSION;']).strip
    evidence = {
      'schema_version' => 1,
      'kind' => 'SIMRS_LOCAL_PORTABILITY_FULL_SUITE',
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_EXACT_ENGINE_ONLY',
      'hosted_readiness_claim' => false,
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'baseline_git_sha' => head,
      'working_tree_state' => dirty ? 'UNCOMMITTED_LOCAL_MILESTONE' : 'CLEAN',
      'local_manifest' => manifest_binding,
      'backend_execution_source_set' => execution_bindings.fetch('backend_execution_source_set'),
      'migration_set' => execution_bindings.fetch('migration_set'),
      'harness_sha256' => execution_bindings.fetch('harness_sha256'),
      'workflow_test_catalog_sha256' => execution_bindings.fetch('workflow_test_catalog_sha256'),
      'php_version' => php_version,
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'break_glass_mode' => 'off',
        'production_integrations_configured' => false,
        'disposable_local_engine' => true
      },
      'engine' => engine_binding,
      'migration' => {
        'fresh_apply' => 'PASS',
        'duration_ms_observed' => migration_duration_ms
      },
      'full_suite' => full_suite,
      'workflow_slices' => workflow_results,
      'cleanup' => {
        'database_removed' => true,
        'temporary_server_removed' => true
      },
      'open_boundaries' => [
        'This is local disposable engine evidence, not hosted UAT, production, capacity, SLA or failure-domain evidence.',
        'Host PHP may differ from the PHP 8.3 CI target.',
        'Passing mapped slices does not make an incomplete workflow complete or owner accepted.',
        'No cancellation, amendment, triage scale, disposition, transfer, discharge, medication, billing or integration semantics are approved by this rehearsal.'
      ]
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    path
  end

  def postgres_application_environment
    raise CommandFailed, 'PostgreSQL disposable database is not ready.' unless @postgres_database&.match?(POSTGRES_DATABASE_PATTERN)
    {
      'DB_CONNECTION' => 'pgsql',
      'DB_HOST' => @postgres_host,
      'DB_PORT' => @postgres_port,
      'DB_DATABASE' => @postgres_database,
      'DB_USERNAME' => @postgres_user,
      'DB_PASSWORD' => '',
      'DB_SCHEMA' => 'laravel',
      'DB_SSLMODE' => 'disable'
    }
  end

  def mysql_application_environment
    raise CommandFailed, 'MySQL disposable database is not ready.' unless @mysql_database&.match?(MYSQL_DATABASE_PATTERN)
    {
      'DB_CONNECTION' => 'mysql',
      'DB_HOST' => '127.0.0.1',
      'DB_PORT' => @mysql_port.to_s,
      'DB_DATABASE' => @mysql_database,
      'DB_USERNAME' => @mysql_user,
      'DB_PASSWORD' => @mysql_password,
      'DB_SOCKET' => '',
      'DB_CHARSET' => 'utf8mb4',
      'DB_COLLATION' => 'utf8mb4_unicode_ci'
    }
  end

  def postgres_connection_arguments(tool)
    [postgres_tool(tool), '--host', @postgres_host, '--port', @postgres_port, '--username', @postgres_user]
  end

  def postgres_psql_arguments(database)
    postgres_connection_arguments('psql') + ['--no-psqlrc', '--set', 'ON_ERROR_STOP=1', '--dbname', database]
  end

  def postgres_tool_environment
    { 'PGCONNECT_TIMEOUT' => '5', 'PGSSLMODE' => 'disable' }
  end

  def assert_postgres_database_name!(name)
    raise CommandFailed, 'Generated PostgreSQL database name failed its closed pattern.' unless name.match?(POSTGRES_DATABASE_PATTERN)
  end

  def assert_postgres_database_absent!(name)
    assert_postgres_database_name!(name)
    escaped = name.gsub("'", "''")
    output = @runner.run!(
      postgres_psql_arguments('postgres') + [
        '--tuples-only', '--no-align', '--command',
        "SELECT count(*) FROM pg_database WHERE datname = '#{escaped}'"
      ],
      env: postgres_tool_environment
    ).strip
    raise CommandFailed, 'Generated PostgreSQL database already exists.' unless output == '0'
  end

  def mysql_tool(name)
    File.join(@mysql_bin, name)
  end

  def postgres_tool(name)
    File.join(@postgres_bin, name)
  end

  def mysql_root_arguments
    [mysql_tool('mysql'), '--no-defaults', '--protocol=socket', "--socket=#{@mysql_socket}", '--user=root']
  end

  def wait_for_postgresql!
    deadline = @clock.call + 30
    loop do
      _stdout, _stderr, status = Open3.capture3(
        @runner.process_environment,
        postgres_tool('pg_isready'), '--host', @postgres_socket_directory,
        '--port', @postgres_port, '--username', @postgres_user, '--dbname', 'postgres',
        unsetenv_others: true
      )
      return if status.success?
      if @postgres_pid && Process.waitpid(@postgres_pid, Process::WNOHANG)
        @postgres_pid = nil
        raise CommandFailed, 'Isolated PostgreSQL 17 server exited before readiness.'
      end
      raise CommandFailed, 'Isolated PostgreSQL 17 server did not become ready.' if @clock.call >= deadline
      sleep 0.1
    end
  end

  def wait_for_mysql!
    deadline = @clock.call + 30
    loop do
      _stdout, _stderr, status = Open3.capture3(
        @runner.process_environment,
        mysql_tool('mysqladmin'), '--no-defaults', '--protocol=socket', "--socket=#{@mysql_socket}", '--user=root', 'ping',
        unsetenv_others: true
      )
      return if status.success?
      if @mysql_pid && Process.waitpid(@mysql_pid, Process::WNOHANG)
        @mysql_pid = nil
        raise CommandFailed, 'Isolated MySQL 8.4 server exited before readiness.'
      end
      raise CommandFailed, 'Isolated MySQL 8.4 server did not become ready.' if @clock.call >= deadline
      sleep 0.1
    end
  end

  def available_loopback_port
    server = TCPServer.new('127.0.0.1', 0)
    server.addr.fetch(1)
  ensure
    server&.close
  end

  def assert_mysql_database_name!(name)
    raise CommandFailed, 'Generated MySQL database name failed its closed pattern.' unless name.match?(MYSQL_DATABASE_PATTERN)
  end

  def validate_port!(value)
    unless value.match?(/\A[0-9]{1,5}\z/) && value.to_i.between?(1, 65_535)
      raise CommandFailed, 'Portability rehearsal requires a valid local port.'
    end
  end

  def elapsed_ms(started)
    ((@clock.call - started) * 1000).round
  end

  def cleanup!(strict: false)
    failures = []
    if @postgres_database&.match?(POSTGRES_DATABASE_PATTERN)
      begin
        arguments = postgres_connection_arguments('dropdb') + ['--if-exists', '--force', '--maintenance-db=postgres', @postgres_database]
        if strict
          @runner.run!(arguments, env: postgres_tool_environment)
          assert_postgres_database_absent!(@postgres_database)
        else
          @runner.run_cleanup(arguments, env: postgres_tool_environment)
        end
      rescue StandardError => e
        failures << e
      end
    end

    if @postgres_pid
      begin
        stop_child_process!(@postgres_pid)
      rescue StandardError => e
        failures << e
      ensure
        @postgres_pid = nil
      end
    end
    @postgres_log_handle&.close unless @postgres_log_handle&.closed?
    @postgres_log_handle = nil

    if @postgres_temp_directory
      begin
        remove_disposable_directory!(@postgres_temp_directory, 'sp17-')
        @postgres_temp_directory = nil
      rescue StandardError => e
        failures << e
      end
    end
    @postgres_database = nil if @postgres_temp_directory.nil?
    @postgres_user = nil if @postgres_temp_directory.nil?

    if @mysql_pid
      begin
        stop_child_process!(@mysql_pid)
      rescue StandardError => e
        failures << e
      ensure
        @mysql_pid = nil
      end
    end
    @mysql_log_handle&.close unless @mysql_log_handle&.closed?
    @mysql_log_handle = nil
    @mysql_password = nil
    @mysql_user = nil
    @mysql_database = nil

    if @mysql_temp_directory
      begin
        remove_disposable_directory!(@mysql_temp_directory, 'sp84-')
        @mysql_temp_directory = nil
      rescue StandardError => e
        failures << e
      end
    end

    raise failures.first if strict && failures.any?

    failures.each do |failure|
      warn "portability cleanup incomplete: #{@runner.sanitize(failure.message)}"
    end
  end

  def stop_child_process!(pid)
    Process.kill('TERM', pid)
    deadline = Process.clock_gettime(Process::CLOCK_MONOTONIC) + 10
    loop do
      return if Process.waitpid(pid, Process::WNOHANG)
      if Process.clock_gettime(Process::CLOCK_MONOTONIC) >= deadline
        Process.kill('KILL', pid)
        Process.waitpid(pid)
        return
      end
      sleep 0.1
    end
  rescue Errno::ESRCH, Errno::ECHILD
    nil
  end

  def remove_disposable_directory!(path, prefix)
    expected_parent = File.realpath('/private/tmp')
    parent = File.realpath(File.dirname(path))
    unless parent == expected_parent && File.basename(path).start_with?(prefix)
      raise CommandFailed, 'Refusing to remove an unexpected disposable database directory.'
    end
    exists = File.exist?(path) || File.symlink?(path)
    FileUtils.remove_entry_secure(path) if exists
    raise CommandFailed, 'Disposable database directory cleanup did not complete.' if File.exist?(path) || File.symlink?(path)
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalPortabilityFullSuiteRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    result = LocalPortabilityFullSuiteRehearsal.new(engine: ARGV.first).run!
    puts JSON.generate(result)
  rescue LocalPortabilityFullSuiteRehearsal::CommandFailed => e
    warn "portability rehearsal failed: #{e.message}"
    exit 1
  end
end
