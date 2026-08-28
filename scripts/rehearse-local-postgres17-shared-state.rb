#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'etc'
require 'fileutils'
require 'json'
require 'open3'
require 'securerandom'
require 'time'
require 'timeout'
require 'tmpdir'

class LocalPostgresSharedStateRehearsal
  ROOT = File.expand_path('..', __dir__)
  CONFIRMATION = 'YES_DISPOSABLE_OWNED_POSTGRES17_SHARED_STATE'
  DATABASE_PATTERN = /\Asimrs_shared_[0-9a-f]{12}\z/
  TEMPORARY_ROOT_PATTERN = /\Asimrs-shared-state-[A-Za-z0-9._-]+\z/
  POSTGRES_VERSION = '17.10'
  EVIDENCE_DIRECTORY = File.join(ROOT, 'storage/app/shared-state-rehearsals')
  CONTRACT_TEST = 'tests/Documentation/LocalPostgresSharedStateHarnessContractTest.rb'
  WORKER_COMMAND = 'app/Console/Commands/RehearseSharedStateWorkerCommand.php'
  EXECUTABLE_NAMES = %w[initdb postgres pg_ctl psql createdb dropdb php git].freeze
  EXECUTABLE_OVERRIDE_KEYS = %w[
    INITDB POSTGRES POSTGRES_BIN POSTGRESQL_BIN PG_CTL PSQL CREATEDB DROPDB
    PHP_BINARY GIT_BINARY
  ].freeze
  INHERITED_STATE_OVERRIDE_KEYS = %w[DB_URL DATABASE_URL].freeze
  INHERITED_STATE_OVERRIDE_PREFIXES = %w[PG REDIS MEMCACHED SUPABASE].freeze
  SOURCE_FILES = %w[
    composer.lock
    app/Console/Commands/RehearseSharedStateWorkerCommand.php
    app/Providers/AppServiceProvider.php
    app/Support/Database/SchemaQualifier.php
    config/app.php
    config/cache.php
    config/database.php
    config/session.php
    database/migrations/0001_01_01_000000_create_users_table.php
    database/migrations/0001_01_01_000001_create_cache_table.php
    scripts/rehearse-local-postgres17-shared-state.rb
    tests/Documentation/LocalPostgresSharedStateHarnessContractTest.rb
    tests/Feature/SharedMaintenanceIndependentProcessTest.php
    tests/Feature/VercelSharedMaintenanceModeTest.php
    vendor/composer/installed.php
    vendor/laravel/framework/src/Illuminate/Cache/DatabaseLock.php
    vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php
    vendor/laravel/framework/src/Illuminate/Database/Connectors/PostgresConnector.php
    vendor/laravel/framework/src/Illuminate/Foundation/CacheBasedMaintenanceMode.php
    vendor/laravel/framework/src/Illuminate/Session/DatabaseSessionHandler.php
    vendor/laravel/framework/src/Illuminate/Session/EncryptedStore.php
  ].freeze

  class CommandFailed < StandardError; end

  class Runner
    SAFE_INHERITED_ENVIRONMENT = %w[PATH TMPDIR LANG LC_ALL TZ USER LOGNAME].freeze

    def run!(argv, env: {}, stdin_data: '')
      stdout, stderr, status = Open3.capture3(
        safe_environment.merge(env),
        *argv,
        stdin_data: stdin_data,
        unsetenv_others: true
      )
      return stdout if status.success?

      reason = sanitize(stderr)
      suffix = reason.empty? ? '' : ": #{reason}"
      raise CommandFailed, "#{File.basename(argv.fetch(0))} failed with exit status #{status.exitstatus}#{suffix}"
    end

    def expect_failure!(argv, env: {})
      stdout, _stderr, status = Open3.capture3(
        safe_environment.merge(env),
        *argv,
        unsetenv_others: true
      )
      raise CommandFailed, "#{File.basename(argv.fetch(0))} unexpectedly succeeded." if status.success?

      stdout
    end

    def expect_blocked_failure!(argv, env: {})
      stdout = expect_failure!(argv, env: env)
      raise CommandFailed, 'Expected failure did not emit the closed BLOCKED result.' unless stdout.include?('SHARED_STATE_REHEARSAL_WORKER_FAILED')

      true
    end

    def start!(argv, env: {})
      Open3.popen3(safe_environment.merge(env), *argv, unsetenv_others: true)
    end

    def run_cleanup(argv, env: {})
      Open3.capture3(safe_environment.merge(env), *argv, unsetenv_others: true)
    rescue StandardError
      nil
    end

    private

    def safe_environment
      SAFE_INHERITED_ENVIRONMENT.each_with_object({}) do |name, safe|
        safe[name] = ENV[name] if ENV.key?(name)
      end
    end

    def sanitize(stderr)
      stderr.lines.map(&:strip).find { |line| !line.empty? }.to_s
        .gsub(/simrs_shared_[0-9a-f]{12}/, '<disposable-db>')
        .gsub(%r{postgres(?:ql)?://\S+}i, '<redacted-dsn>')
        .gsub(/(password|secret|token|key)\s*[=:]\s*\S+/i, '\\1=<redacted>')
        .slice(0, 300)
    end
  end

  def initialize(environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @environment = environment
    @runner = runner
    @clock = monotonic_clock || -> { Process.clock_gettime(Process::CLOCK_MONOTONIC) }
    @application_key = "base64:#{Base64.strict_encode64(SecureRandom.random_bytes(32))}"
    @divergent_application_key = "base64:#{Base64.strict_encode64(SecureRandom.random_bytes(32))}"
    @created_database = nil
    @temporary_root = nil
    @postgres_process = nil
    @tools = {}
  end

  def run!
    assert_operator_confirmation!
    assert_environment_boundary!
    assert_fixed_contracts!
    resolve_and_verify_tools!
    source_revision_before = source_revision!
    create_owned_cluster!
    verify_owned_cluster_boundary!

    @run_token = SecureRandom.hex(6)
    @database = "simrs_shared_#{@run_token}"
    assert_database_name!(@database)
    assert_database_absent!(@database)
    create_database!
    create_private_schema!
    migrate!
    assert_private_framework_tables!

    started = @clock.call
    boundary = run_boundary_phase!
    maintenance = run_maintenance_phase!
    counter = run_counter_phase!
    lock = run_lock_phase!
    session = run_session_phase!
    divergence = run_divergence_phase!
    source_revision_after = source_revision!
    assert_source_revision_stable!(source_revision_before, source_revision_after)
    source_revision = source_revision_after.merge('stable_during_execution' => true)
    elapsed = elapsed_ms(started)

    cleanup!(strict: true)
    evidence_path = write_evidence!(
      boundary: boundary,
      maintenance: maintenance,
      counter: counter,
      lock: lock,
      session: session,
      divergence: divergence,
      source_revision: source_revision,
      elapsed_ms: elapsed
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_HARNESS_OWNED_POSTGRESQL17_10_SHARED_STATE_ONLY',
      'evidence_path' => evidence_path,
      'shared_maintenance' => true,
      'shared_atomic_counter_and_lock' => true,
      'shared_encrypted_session' => true,
      'divergence_failures_observed' => true
    }
  ensure
    cleanup!
  end

  private

  def assert_operator_confirmation!
    return if @environment['SIMRS_SHARED_STATE_REHEARSAL_CONFIRM'] == CONFIRMATION

    raise CommandFailed,
          "Set SIMRS_SHARED_STATE_REHEARSAL_CONFIRM=#{CONFIRMATION} to authorize the disposable owned cluster."
  end

  def assert_environment_boundary!
    rejected = @environment.keys.select do |name|
      (INHERITED_STATE_OVERRIDE_KEYS.include?(name) ||
        INHERITED_STATE_OVERRIDE_PREFIXES.any? { |prefix| name.start_with?(prefix) } ||
        EXECUTABLE_OVERRIDE_KEYS.include?(name)) &&
        !@environment.fetch(name, '').to_s.empty?
    end
    return if rejected.empty?

    raise CommandFailed,
          "Shared-state rehearsal refuses inherited state, database, or executable overrides: #{rejected.sort.join(', ')}."
  end

  def assert_fixed_contracts!
    raise CommandFailed, 'Shared-state rehearsal must run from its repository-owned location.' unless File.realpath(ROOT) == ROOT

    (SOURCE_FILES - ['scripts/rehearse-local-postgres17-shared-state.rb']).each do |relative_path|
      path = File.join(ROOT, relative_path)
      raise CommandFailed, "Required shared-state source #{relative_path} is missing." unless File.file?(path)
      raise CommandFailed, "Required shared-state source #{relative_path} must not be a symlink." if File.symlink?(path)
    end
  end

  def resolve_and_verify_tools!
    EXECUTABLE_NAMES.each { |name| @tools[name] = resolve_executable!(name) }

    postgres_tools = %w[initdb postgres pg_ctl psql createdb dropdb]
    postgres_tools.each do |name|
      output = @runner.run!([tool(name), '--version'])
      unless output.match?(/\b#{Regexp.escape(POSTGRES_VERSION)}\b/)
        raise CommandFailed, "Shared-state rehearsal requires #{name} from PostgreSQL #{POSTGRES_VERSION}."
      end
    end
    bindirs = postgres_tools.map { |name| File.dirname(tool(name)) }.uniq
    raise CommandFailed, 'PostgreSQL rehearsal executables must resolve from one immutable toolchain.' unless bindirs.one?

    @runner.run!([tool('php'), '--version'])
    @runner.run!([tool('git'), '--version'])
  end

  def resolve_executable!(name)
    path = nil
    @environment.fetch('PATH', '').split(File::PATH_SEPARATOR).each do |directory|
      candidate = File.join(directory, name)
      next unless File.file?(candidate) && File.executable?(candidate)

      path = candidate
      break
    end
    raise CommandFailed, "Required executable #{name} was not found on PATH." unless path

    File.realpath(path)
  rescue Errno::ENOENT
    raise CommandFailed, "Required executable #{name} could not be resolved."
  end

  def tool(name)
    @tools.fetch(name)
  end

  def create_owned_cluster!
    @temporary_root = Dir.mktmpdir('simrs-shared-state-', '/tmp')
    assert_temporary_root!(@temporary_root)
    File.chmod(0o700, @temporary_root)
    @data_directory = File.join(@temporary_root, 'cluster')
    @socket_directory = File.join(@temporary_root, 'socket')
    @application_storage_directory = File.join(@temporary_root, 'laravel-storage')
    @bootstrap_cache_directory = File.join(@temporary_root, 'bootstrap-cache')
    @compiled_views_directory = File.join(@temporary_root, 'compiled-views')
    FileUtils.mkdir(@socket_directory, mode: 0o700)
    [
      @application_storage_directory,
      File.join(@application_storage_directory, 'framework'),
      @bootstrap_cache_directory,
      @compiled_views_directory
    ].each do |directory|
      FileUtils.mkdir_p(directory, mode: 0o700)
      File.chmod(0o700, directory)
    end
    @port = SecureRandom.random_number(20_000) + 30_000
    @cluster_user = Etc.getpwuid.name
    unless @cluster_user.match?(/\A[A-Za-z_][A-Za-z0-9_.-]{0,62}\z/)
      raise CommandFailed, 'Shared-state rehearsal requires a simple local operating-system user name.'
    end

    @runner.run!([
      tool('initdb'), '--pgdata', @data_directory, '--encoding=UTF8', '--no-locale',
      '--auth-local=trust', '--auth-host=reject', '--username', @cluster_user
    ])
    File.chmod(0o700, @data_directory)

    stdin, stdout, stderr, wait_thread = @runner.start!([
      tool('postgres'), '-D', @data_directory,
      '-k', @socket_directory,
      '-p', @port.to_s,
      '-c', 'listen_addresses=',
      '-c', 'unix_socket_permissions=0700',
      '-c', 'logging_collector=off',
      '-c', 'fsync=off',
      '-c', 'synchronous_commit=off',
      '-c', 'max_connections=30'
    ], env: postgres_environment)
    stdin.close
    stdout_reader = Thread.new { stdout.read }
    stderr_reader = Thread.new { stderr.read }
    @postgres_process = [stdout, stderr, wait_thread, stdout_reader, stderr_reader]

    wait_until!(12, 'Harness-owned PostgreSQL did not become ready.') do
      next false unless wait_thread.alive?

      begin
        scalar_sql('postgres', 'SELECT 1') == '1'
      rescue CommandFailed
        false
      end
    end
  end

  def verify_owned_cluster_boundary!
    unless permission_bits(@temporary_root) == 0o700 &&
           permission_bits(@data_directory) == 0o700 &&
           permission_bits(@socket_directory) == 0o700
      raise CommandFailed, 'Harness-owned PostgreSQL directories must be mode 0700.'
    end

    values = csv_sql('postgres', <<~SQL).split(',').map(&:strip)
      SELECT
        current_setting('server_version_num'),
        current_setting('listen_addresses') = '',
        current_setting('unix_socket_directories') = '#{sql_literal(@socket_directory)}',
        current_setting('unix_socket_permissions') = '0700',
        inet_server_addr() IS NULL
    SQL
    unless values == %w[170010 t t t t]
      raise CommandFailed, 'Harness-owned PostgreSQL runtime boundary did not match the closed contract.'
    end

    hba = csv_sql('postgres', <<~SQL).split(',').map(&:strip)
      SELECT
        count(*) FILTER (WHERE type LIKE 'host%'),
        bool_and(auth_method = 'reject') FILTER (WHERE type LIKE 'host%'),
        count(*) FILTER (WHERE type = 'local' AND auth_method = 'trust'),
        count(*) FILTER (WHERE error IS NOT NULL)
      FROM pg_hba_file_rules
    SQL
    unless integer!(hba[0]).positive? && hba[1] == 't' && integer!(hba[2]).positive? && integer!(hba[3]).zero?
      raise CommandFailed, 'Harness-owned PostgreSQL pg_hba.conf must trust local sockets and reject every host rule.'
    end

    @runner.expect_failure!([
      tool('psql'), '--no-psqlrc', '--host', '127.0.0.1', '--port', @port.to_s,
      '--username', @cluster_user, '--dbname', 'postgres', '--command', 'SELECT 1'
    ], env: postgres_environment.merge('PGCONNECT_TIMEOUT' => '1'))
  end

  def assert_database_name!(database)
    raise CommandFailed, 'Generated shared-state database name failed its closed pattern.' unless DATABASE_PATTERN.match?(database)
  end

  def assert_database_absent!(database)
    assert_database_name!(database)
    existing = scalar_sql('postgres', "SELECT count(*) FROM pg_database WHERE datname = '#{database}'")
    raise CommandFailed, 'Shared-state rehearsal refuses a pre-existing generated database.' unless integer!(existing).zero?
  end

  def create_database!
    @runner.run!(connection_arguments('createdb') + ['--maintenance-db=postgres', @database], env: postgres_environment)
    @created_database = @database
  end

  def create_private_schema!
    sql!(@database, 'CREATE SCHEMA laravel;')
  end

  def migrate!
    run_artisan!('migrate', '--force', '--no-interaction')
  end

  def assert_private_framework_tables!
    values = csv_sql(@database, <<~SQL).split(',').map(&:strip)
      SELECT
        to_regclass('laravel.cache')::text,
        to_regclass('laravel.cache_locks')::text,
        to_regclass('laravel.sessions')::text,
        to_regclass('public.cache') IS NULL,
        to_regclass('public.cache_locks') IS NULL,
        to_regclass('public.sessions') IS NULL
    SQL
    unless values == ['laravel.cache', 'laravel.cache_locks', 'laravel.sessions', 't', 't', 't']
      raise CommandFailed, 'Framework shared-state tables did not migrate only into the private laravel schema.'
    end
  end

  def run_boundary_phase!
    result = run_worker!(phase: 'boundary', worker: 'A', expect: 'ready')
    assert_worker_result!(result, phase: 'boundary', worker: 'A', outcome: 'BOUNDARY_READY')

    {
      'private_framework_tables' => result['framework_tables_private'] == true,
      'independent_process_boundary' => true,
      'status' => 'PASS'
    }
  end

  def run_maintenance_phase!
    inactive_before = run_worker!(phase: 'maintenance-read', worker: 'A', expect: 'inactive')
    run_artisan!('down', '--retry=60', '--no-interaction')
    active = run_worker!(phase: 'maintenance-read', worker: 'B', expect: 'active')
    divergent = run_worker!(
      phase: 'maintenance-read', worker: 'C', expect: 'inactive', environment: divergent_prefix_environment
    )
    marker_rows = integer!(scalar_sql(
      @database,
      "SELECT count(*) FROM laravel.cache WHERE key = '#{cache_prefix}illuminate:foundation:down'"
    ))
    raise CommandFailed, 'Shared maintenance phase did not persist exactly one base-prefix marker.' unless marker_rows == 1

    run_artisan!('up', '--no-interaction')
    inactive_after = run_worker!(phase: 'maintenance-read', worker: 'A', expect: 'inactive')

    {
      'inactive_before' => inactive_before['maintenance_active'] == false,
      'active_from_fresh_process' => active['maintenance_active'] == true,
      'divergent_prefix_partition_observed' => divergent['maintenance_active'] == false,
      'one_shared_marker_row' => marker_rows == 1,
      'inactive_after_fresh_process_up' => inactive_after['maintenance_active'] == false,
      'independent_backend_sessions' => independent_backend_pids?(
        inactive_before, active, divergent, inactive_after
      ),
      'status' => 'PASS'
    }
  ensure
    begin
      run_artisan!('up', '--no-interaction') if @created_database && @postgres_process
    rescue StandardError
      nil
    end
  end

  def run_counter_phase!
    initialized = run_worker!(phase: 'counter-init', worker: 'A', expect: 'zero')
    first = start_worker(phase: 'counter-increment', worker: 'A', expect: 'incremented')
    second = start_worker(phase: 'counter-increment', worker: 'B', expect: 'incremented')
    first_result, second_result = await_workers!([first, second], 12)
    assert_worker_result!(first_result, phase: 'counter-increment', worker: 'A', outcome: 'COUNTER_INCREMENTED')
    assert_worker_result!(second_result, phase: 'counter-increment', worker: 'B', outcome: 'COUNTER_INCREMENTED')
    assert_independent_backends!(first_result, second_result)
    values = [first_result, second_result].map { |result| integer!(result.fetch('counter_value')) }.sort
    raise CommandFailed, 'Shared counter increments did not produce the atomic sequence 1,2.' unless values == [1, 2]

    retained = run_worker!(phase: 'counter-read', worker: 'C', expect: 'two')
    rows = integer!(scalar_sql(
      @database,
      "SELECT count(*) FROM laravel.cache WHERE key = '#{cache_prefix}shared-counter-#{@run_token}'"
    ))
    raise CommandFailed, 'Shared counter did not retain exactly one prefixed database row.' unless rows == 1

    {
      'initialized_zero' => initialized['counter_value'] == 0,
      'atomic_increment_sequence' => values,
      'retained_value' => retained['counter_value'],
      'one_prefixed_counter_row' => rows == 1,
      'independent_backend_sessions' => true,
      'status' => 'PASS'
    }
  end

  def run_lock_phase!
    holder = start_worker(phase: 'lock-holder', worker: 'A', expect: 'held', hold_ms: 3000)
    hold_observed = wait_until!(8, 'Shared lock holder did not reach its bounded PostgreSQL sleep.') do
      activity_count('lock_holder', 'A', "wait_event = 'PgSleep'").positive?
    end
    contender = run_worker!(phase: 'lock-attempt', worker: 'B', expect: 'rejected')
    holder_still_active = activity_count('lock_holder', 'A', "wait_event = 'PgSleep'").positive?
    raise CommandFailed, 'Lock contender completed only after the holder released its lock.' unless holder_still_active
    lock_rows_during_hold = integer!(scalar_sql(
      @database,
      "SELECT count(*) FROM laravel.cache_locks WHERE key = '#{cache_prefix}shared-lock-#{@run_token}' AND expiration > extract(epoch FROM now())"
    ))
    raise CommandFailed, 'Shared lock row was not visible while the holder was active.' unless lock_rows_during_hold == 1

    holder_result = await_workers!([holder], 10).fetch(0)
    assert_worker_result!(holder_result, phase: 'lock-holder', worker: 'A', outcome: 'LOCK_HELD_RELEASED')
    assert_worker_result!(contender, phase: 'lock-attempt', worker: 'B', outcome: 'LOCK_REJECTED')
    assert_independent_backends!(holder_result, contender)
    lock_rows_after = integer!(scalar_sql(
      @database,
      "SELECT count(*) FROM laravel.cache_locks WHERE key = '#{cache_prefix}shared-lock-#{@run_token}'"
    ))
    raise CommandFailed, 'Shared lock row survived required release.' unless lock_rows_after.zero?

    {
      'holder_sleep_observed' => hold_observed,
      'contender_rejected_while_holder_active' => contender['lock_acquired'] == false && holder_still_active,
      'one_lock_row_during_hold' => lock_rows_during_hold == 1,
      'lock_row_removed_after_release' => lock_rows_after.zero?,
      'independent_backend_sessions' => true,
      'status' => 'PASS'
    }
  end

  def run_session_phase!
    written = run_worker!(phase: 'session-write', worker: 'A', expect: 'written')
    present = run_worker!(phase: 'session-read', worker: 'B', expect: 'present')
    absent = run_worker!(
      phase: 'session-read', worker: 'C', expect: 'absent', environment: divergent_key_environment
    )
    session_id = @run_token + ('0' * 28)
    values = csv_sql(@database, <<~SQL).split(',').map(&:strip)
      SELECT
        count(*),
        count(*) FILTER (WHERE length(payload) > 100),
        count(*) FILTER (WHERE payload LIKE '%SIMRS_SHARED_SESSION_%')
      FROM laravel.sessions
      WHERE id = '#{session_id}'
    SQL
    unless values.map { |value| integer!(value) } == [1, 1, 0]
      raise CommandFailed, 'Encrypted shared session row did not match its closed storage contract.'
    end

    {
      'session_written_from_process_a' => written['session_encrypted'] == true,
      'session_read_from_process_b' => present['session_marker_present'] == true,
      'divergent_key_cannot_read_marker' => absent['session_marker_present'] == false,
      'one_encrypted_database_row' => true,
      'independent_backend_sessions' => independent_backend_pids?(written, present, absent),
      'status' => 'PASS'
    }
  end

  def run_divergence_phase!
    schema_failure = expect_worker_failure!(
      phase: 'boundary', worker: 'C', expect: 'ready', environment: { 'DB_SCHEMA' => 'preview_probe' }
    )

    {
      'cache_prefix_partition_observed' => true,
      'application_key_session_rejection_observed' => true,
      'wrong_schema_failed_closed' => schema_failure,
      'status' => 'PASS'
    }
  end

  def start_worker(phase:, worker:, expect:, hold_ms: 0, environment: {})
    Thread.new do
      run_worker!(
        phase: phase,
        worker: worker,
        expect: expect,
        hold_ms: hold_ms,
        environment: environment
      )
    end
  end

  def run_worker!(phase:, worker:, expect:, hold_ms: 0, environment: {})
    parse_worker_result!(run_artisan!(
      'ops:rehearse-shared-state-worker',
      "--run-token=#{@run_token}",
      "--phase=#{phase}",
      "--worker=#{worker}",
      "--expect=#{expect}",
      "--hold-ms=#{hold_ms}",
      '--confirm-owned-local-synthetic',
      '--no-ansi',
      environment: environment
    ))
  end

  def expect_worker_failure!(phase:, worker:, expect:, environment: {})
    arguments = worker_arguments(phase: phase, worker: worker, expect: expect, hold_ms: 0)
    @runner.expect_blocked_failure!(
      [tool('php'), File.join(ROOT, 'artisan'), *arguments],
      env: application_environment.merge(environment)
    )
  end

  def worker_arguments(phase:, worker:, expect:, hold_ms:)
    [
      'ops:rehearse-shared-state-worker',
      "--run-token=#{@run_token}",
      "--phase=#{phase}",
      "--worker=#{worker}",
      "--expect=#{expect}",
      "--hold-ms=#{hold_ms}",
      '--confirm-owned-local-synthetic',
      '--no-ansi'
    ]
  end

  def parse_worker_result!(output)
    line = output.lines.map(&:strip).reject(&:empty?).last
    result = JSON.parse(line || '')
    raise CommandFailed, 'Shared-state worker returned a non-PASS result.' unless result['status'] == 'PASS'

    result
  rescue JSON::ParserError
    raise CommandFailed, 'Shared-state worker returned malformed JSON.'
  end

  def assert_worker_result!(result, phase:, worker:, outcome:)
    unless result['status'] == 'PASS' && result['phase'] == phase &&
           result['worker'] == worker && result['outcome'] == outcome
      raise CommandFailed, 'Shared-state worker result did not match its closed phase contract.'
    end
  end

  def assert_independent_backends!(*results)
    pids = results.map { |result| integer!(result.fetch('backend_pid')) }
    raise CommandFailed, 'Shared-state workers did not use independent PostgreSQL backends.' unless pids.uniq.length == pids.length
  end

  def independent_backend_pids?(*results)
    pids = results.map { |result| integer!(result.fetch('backend_pid')) }
    pids.uniq.length == pids.length
  end

  def await_workers!(workers, timeout_seconds)
    Timeout.timeout(timeout_seconds) { workers.map(&:value) }
  rescue Timeout::Error
    raise CommandFailed, 'Shared-state worker phase exceeded its wall-clock timeout.'
  end

  def activity_count(phase, worker, predicate)
    integer!(scalar_sql(@database, <<~SQL))
      SELECT count(*)
      FROM pg_stat_activity
      WHERE datname = current_database()
        AND application_name = 'simrs_shared_#{@run_token}_#{phase}_#{worker.downcase}'
        AND #{predicate}
    SQL
  end

  def wait_until!(seconds, message)
    deadline = @clock.call + seconds
    loop do
      return true if yield
      raise CommandFailed, message if @clock.call >= deadline

      sleep 0.05
    end
  end

  def run_artisan!(*arguments, environment: {})
    @runner.run!(
      [tool('php'), File.join(ROOT, 'artisan'), *arguments],
      env: application_environment.merge(environment)
    )
  end

  def application_environment
    {
      'APP_ENV' => 'testing',
      'APP_KEY' => @application_key,
      'APP_PREVIOUS_KEYS' => '',
      'APP_NAME' => 'SIMRS Shared State Rehearsal',
      'APP_URL' => 'http://simrs-shared-state.invalid',
      'APP_MODE' => 'SIMULATION',
      'APP_SYNTHETIC_ONLY' => 'true',
      'LARAVEL_STORAGE_PATH' => @application_storage_directory,
      'APP_CONFIG_CACHE' => File.join(@bootstrap_cache_directory, 'config.php'),
      'APP_EVENTS_CACHE' => File.join(@bootstrap_cache_directory, 'events.php'),
      'APP_PACKAGES_CACHE' => File.join(@bootstrap_cache_directory, 'packages.php'),
      'APP_ROUTES_CACHE' => File.join(@bootstrap_cache_directory, 'routes.php'),
      'APP_SERVICES_CACHE' => File.join(@bootstrap_cache_directory, 'services.php'),
      'VIEW_COMPILED_PATH' => @compiled_views_directory,
      'APP_MAINTENANCE_DRIVER' => 'cache',
      'APP_MAINTENANCE_STORE' => 'database',
      'SIMRS_SHARED_STATE_REHEARSAL_CONFIRM' => CONFIRMATION,
      'DB_CONNECTION' => 'pgsql',
      'DB_URL' => '',
      'DB_HOST' => @socket_directory,
      'DB_PORT' => @port.to_s,
      'DB_DATABASE' => @database,
      'DB_USERNAME' => @cluster_user,
      'DB_PASSWORD' => '',
      'DB_SCHEMA' => 'laravel',
      'DB_SSLMODE' => 'disable',
      'CACHE_STORE' => 'database',
      'DB_CACHE_CONNECTION' => 'pgsql',
      'DB_CACHE_TABLE' => 'cache',
      'CACHE_PREFIX' => cache_prefix,
      'SESSION_DRIVER' => 'database',
      'SESSION_CONNECTION' => 'pgsql',
      'SESSION_TABLE' => 'sessions',
      'SESSION_ENCRYPT' => 'true',
      'QUEUE_CONNECTION' => 'sync',
      'MAIL_MAILER' => 'array',
      'FILESYSTEM_DISK' => 'local',
      'LOG_CHANNEL' => 'stderr',
      'BREAK_GLASS_GLOBAL_DISABLED' => 'true',
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false',
      'EXTERNAL_HTTP_ENABLED' => 'false'
    }
  end

  def divergent_prefix_environment
    { 'CACHE_PREFIX' => "simrs_shared_#{@run_token}_divergent_" }
  end

  def divergent_key_environment
    { 'APP_KEY' => @divergent_application_key, 'APP_PREVIOUS_KEYS' => '' }
  end

  def cache_prefix
    "simrs_shared_#{@run_token}_"
  end

  def psql_arguments(database)
    connection_arguments('psql') + ['--no-psqlrc', '--set', 'ON_ERROR_STOP=1', '--dbname', database]
  end

  def connection_arguments(name)
    [tool(name), '--host', @socket_directory, '--port', @port.to_s, '--username', @cluster_user]
  end

  def postgres_environment
    {
      'PGCONNECT_TIMEOUT' => '3',
      'PGSSLMODE' => 'disable',
      'PGHOST' => @socket_directory,
      'PGPORT' => @port.to_s,
      'PGUSER' => @cluster_user
    }
  end

  def sql!(database, sql)
    @runner.run!(psql_arguments(database) + ['--command', sql], env: postgres_environment)
  end

  def scalar_sql(database, sql)
    @runner.run!(
      psql_arguments(database) + ['--tuples-only', '--no-align', '--command', sql],
      env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
    ).strip
  end

  def csv_sql(database, sql)
    @runner.run!(
      psql_arguments(database) + ['--tuples-only', '--no-align', '--field-separator', ',', '--command', sql],
      env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
    ).strip
  end

  def integer!(value)
    return value if value.is_a?(Integer)

    Integer(value, 10)
  rescue ArgumentError, TypeError
    raise CommandFailed, 'PostgreSQL returned a malformed numeric shared-state result.'
  end

  def sql_literal(value)
    value.gsub("'", "''")
  end

  def permission_bits(path)
    File.stat(path).mode & 0o777
  end

  def elapsed_ms(started)
    ((@clock.call - started) * 1000).round
  end

  def source_revision!
    hashes = SOURCE_FILES.to_h do |relative_path|
      path = File.join(ROOT, relative_path)
      raise CommandFailed, "Shared-state source #{relative_path} disappeared during rehearsal." unless File.file?(path)

      [relative_path, Digest::SHA256.file(path).hexdigest]
    end
    migration_rows = Dir[File.join(ROOT, 'database/migrations/*.php')].sort.map do |path|
      "#{File.basename(path)} #{Digest::SHA256.file(path).hexdigest}"
    end

    {
      'head' => @runner.run!([tool('git'), '-C', ROOT, 'rev-parse', 'HEAD']).strip,
      'working_tree_dirty' => !@runner.run!([tool('git'), '-C', ROOT, 'status', '--porcelain']).strip.empty?,
      'laravel_framework' => laravel_framework_identity!,
      'files' => hashes,
      'source_contract_sha256' => Digest::SHA256.hexdigest(
        hashes.map { |path, digest| "#{path} #{digest}" }.join("\n")
      ),
      'migration_file_set_sha256' => Digest::SHA256.hexdigest(migration_rows.join("\n"))
    }
  end

  def laravel_framework_identity!
    lock = JSON.parse(File.read(File.join(ROOT, 'composer.lock')))
    package = lock.fetch('packages').find { |candidate| candidate['name'] == 'laravel/framework' }
    raise CommandFailed, 'composer.lock does not contain the required Laravel framework package.' unless package

    reference = package.fetch('dist', {}).fetch('reference', '')
    unless package.fetch('version', '').match?(/\Av[0-9]+\.[0-9]+\.[0-9]+\z/) &&
           reference.match?(/\A[0-9a-f]{40}\z/)
      raise CommandFailed, 'Laravel framework identity in composer.lock is malformed.'
    end

    runtime = JSON.parse(@runner.run!([
      tool('php'), '-r',
      'require $argv[1]; echo json_encode([Composer\\InstalledVersions::getPrettyVersion("laravel/framework"), Composer\\InstalledVersions::getReference("laravel/framework")], JSON_THROW_ON_ERROR);',
      File.join(ROOT, 'vendor/autoload.php')
    ]))
    unless runtime == [package.fetch('version'), reference]
      raise CommandFailed, 'Installed Laravel runtime identity does not match composer.lock.'
    end

    {
      'version' => package.fetch('version'),
      'dist_reference' => reference,
      'runtime_installed_match' => true
    }
  rescue JSON::ParserError, KeyError
    raise CommandFailed, 'composer.lock could not provide a closed Laravel framework identity.'
  end

  def assert_source_revision_stable!(before_revision, after_revision)
    stable_keys = %w[head laravel_framework files source_contract_sha256 migration_file_set_sha256]
    stable = stable_keys.all? { |key| before_revision.fetch(key) == after_revision.fetch(key) }
    raise CommandFailed, 'Shared-state source or dependency bytes changed during execution.' unless stable
  end

  def write_evidence!(boundary:, maintenance:, counter:, lock:, session:, divergence:, source_revision:, elapsed_ms:)
    prepare_evidence_directory!
    evidence = {
      'schema_version' => 1,
      'kind' => 'SIMRS_LOCAL_POSTGRESQL_SHARED_APPLICATION_STATE_REHEARSAL',
      'status' => 'PASS',
      'captured_at' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_HARNESS_OWNED_POSTGRESQL17_10_SHARED_STATE_ONLY',
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'external_integrations_enabled' => false,
        'database_engine' => 'postgresql',
        'database_version' => POSTGRES_VERSION,
        'application_schema' => 'laravel',
        'cache_store' => 'database',
        'session_store' => 'database',
        'session_encrypted' => true,
        'maintenance_driver' => 'cache',
        'maintenance_store' => 'database',
        'harness_owned_cluster' => true,
        'cluster_directory_mode' => '0700',
        'socket_directory_mode' => '0700',
        'tcp_listening' => false,
        'host_authentication_rules' => 'reject',
        'disposable_database' => true,
        'hosted_shared_state_claim' => false,
        'supabase_pooler_claim' => false,
        'real_patient_data_rows' => 0
      },
      'source_revision' => source_revision,
      'framework_table_boundary' => boundary,
      'maintenance' => maintenance,
      'cache_counter' => counter,
      'cache_lock' => lock,
      'encrypted_session' => session,
      'divergence' => divergence,
      'elapsed_ms' => elapsed_ms,
      'generated_database_removed_before_evidence' => true,
      'owned_cluster_removed_before_evidence' => true,
      'interpretation' => 'Independent PHP processes on a disposable owned PostgreSQL 17.10 cluster share the private-schema maintenance marker, atomic cache counter and lock, and encrypted database session under identical bindings. Deliberate prefix, key, and schema divergence is observed separately. Vercel and Supabase hosted acceptance remain separate gates.'
    }
    digest = Digest::SHA256.hexdigest(JSON.generate(evidence))
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{digest[0, 12]}.json")
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end

  def prepare_evidence_directory!(directory = EVIDENCE_DIRECTORY, parent = File.dirname(EVIDENCE_DIRECTORY))
    expanded_parent = File.expand_path(parent)
    expanded_directory = File.expand_path(directory)
    unless File.dirname(expanded_directory) == expanded_parent &&
           File.basename(expanded_directory).match?(/\A[a-z0-9-]+\z/)
      raise CommandFailed, 'Shared-state evidence directory is outside its closed parent.'
    end

    parent_stat = File.lstat(expanded_parent)
    unless parent_stat.directory? && !parent_stat.symlink? && File.realpath(expanded_parent) == expanded_parent
      raise CommandFailed, 'Shared-state evidence parent must be a real directory without symlink traversal.'
    end

    directory_stat = begin
      File.lstat(expanded_directory)
    rescue Errno::ENOENT
      Dir.mkdir(expanded_directory, 0o700)
      File.lstat(expanded_directory)
    end
    unless directory_stat.directory? && !directory_stat.symlink?
      raise CommandFailed, 'Shared-state evidence path must be a real directory, never a symlink.'
    end

    unless File.realpath(expanded_directory) == expanded_directory
      raise CommandFailed, 'Shared-state evidence directory resolved outside its closed path.'
    end

    File.chmod(0o700, expanded_directory)
  rescue Errno::ENOENT, Errno::EACCES, Errno::ELOOP => exception
    raise CommandFailed, "Shared-state evidence directory validation failed: #{exception.class.name}."
  end

  def cleanup!(strict: false)
    cleanup_error = nil

    if @created_database && DATABASE_PATTERN.match?(@created_database) && @postgres_process
      arguments = connection_arguments('dropdb') + [
        '--if-exists', '--force', '--maintenance-db=postgres', @created_database
      ]
      begin
        if strict
          @runner.run!(arguments, env: postgres_environment)
          assert_database_absent!(@created_database)
        else
          @runner.run_cleanup(arguments, env: postgres_environment)
        end
        @created_database = nil
      rescue StandardError => exception
        cleanup_error ||= exception
      end
    end

    if @postgres_process
      begin
        arguments = [tool('pg_ctl'), '-D', @data_directory, '-m', 'immediate', '-w', 'stop']
        @runner.run!(arguments, env: postgres_environment)
        _stdout, _stderr, wait_thread, stdout_reader, stderr_reader = @postgres_process
        Timeout.timeout(8) { wait_thread.value }
        stdout_reader.join(1)
        stderr_reader.join(1)
        @postgres_process = nil
      rescue StandardError => exception
        cleanup_error ||= exception
        begin
          terminate_owned_postgres_process!
        rescue StandardError => termination_exception
          cleanup_error ||= termination_exception
        end
      end
    end

    if @temporary_root
      begin
        if @postgres_process
          raise CommandFailed, 'Owned PostgreSQL process survived cleanup; its directory was preserved.'
        end

        assert_temporary_root!(@temporary_root)
        FileUtils.remove_entry_secure(@temporary_root) if File.exist?(@temporary_root)
        raise CommandFailed, 'Owned PostgreSQL cluster directory survived cleanup.' if strict && File.exist?(@temporary_root)

        @temporary_root = nil
      rescue StandardError => exception
        cleanup_error ||= exception
      end
    end

    if strict && cleanup_error
      raise CommandFailed, "Shared-state rehearsal cleanup failed; no PASS evidence was written: #{cleanup_error.message}"
    end


    warn 'CLEANUP_WARNING: owned PostgreSQL cleanup was incomplete; no PASS claim is valid.' if !strict && cleanup_error
  end

  def terminate_owned_postgres_process!
    return unless @postgres_process

    _stdout, _stderr, wait_thread, stdout_reader, stderr_reader = @postgres_process
    if wait_thread.alive?
      begin
        Process.kill('TERM', wait_thread.pid)
      rescue Errno::ESRCH
        nil
      end

      begin
        Timeout.timeout(3) { wait_thread.value }
      rescue Timeout::Error
        begin
          Process.kill('KILL', wait_thread.pid)
        rescue Errno::ESRCH
          nil
        end
        Timeout.timeout(3) { wait_thread.value }
      end
    end

    raise CommandFailed, 'Owned PostgreSQL process survived direct termination.' if wait_thread.alive?

    stdout_reader.join(1)
    stderr_reader.join(1)
    @postgres_process = nil
  end

  def assert_temporary_root!(path)
    unless File.basename(path).match?(TEMPORARY_ROOT_PATTERN) && File.directory?(path) && !File.symlink?(path)
      raise CommandFailed, 'Shared-state rehearsal temporary root failed its closed cleanup boundary.'
    end

    parent = File.realpath(File.dirname(path))
    allowed_parent = File.realpath('/tmp')
    unless parent == allowed_parent
      raise CommandFailed, 'Shared-state rehearsal temporary root is outside the operating-system temp directory.'
    end
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    result = LocalPostgresSharedStateRehearsal.new.run!
    puts JSON.pretty_generate(result)
  rescue LocalPostgresSharedStateRehearsal::CommandFailed => exception
    warn "BLOCKED: #{exception.message}"
    exit 1
  end
end
