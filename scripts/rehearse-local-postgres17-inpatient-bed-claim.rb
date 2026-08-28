#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'etc'
require 'fileutils'
require 'json'
require 'open3'
require 'pathname'
require 'securerandom'
require 'time'
require 'timeout'
require 'tmpdir'

class LocalPostgresInpatientBedClaimRehearsal
  ROOT = File.expand_path('..', __dir__)
  CONFIRMATION = 'YES_DISPOSABLE_OWNED_POSTGRES17_INPATIENT_BED_CLAIM'
  DATABASE_PATTERN = /\Asimrs_bedclaim_[0-9a-f]{12}\z/
  TEMPORARY_ROOT_PATTERN = /\Asimrs-bedclaim-[A-Za-z0-9._-]+\z/
  POSTGRES_VERSION = '17.10'
  EVIDENCE_DIRECTORY = File.join(ROOT, 'storage/app/inpatient-bed-claim-rehearsals')
  CONTRACT_TEST = 'tests/Documentation/LocalPostgresInpatientBedClaimHarnessContractTest.rb'
  WORKER_COMMAND = 'app/Console/Commands/RehearseInpatientBedClaimWorkerCommand.php'
  EXECUTABLE_NAMES = %w[initdb postgres pg_ctl psql createdb dropdb php git].freeze
  EXECUTABLE_OVERRIDE_KEYS = %w[
    INITDB POSTGRES POSTGRES_BIN POSTGRESQL_BIN PG_CTL PSQL CREATEDB DROPDB
    PHP_BINARY GIT_BINARY
  ].freeze
  SOURCE_FILES = %w[
    app/Console/Commands/RehearseInpatientBedClaimWorkerCommand.php
    app/Models/DailyQueueCounter.php
    app/Models/Encounter.php
    app/Models/InpatientBedClaimMutex.php
    app/Models/Patient.php
    app/Support/Audit/AuditRecorder.php
    app/Support/Registration/DailyQueueAllocator.php
    app/Support/Registration/InpatientBedClaimGuard.php
    app/Support/Registration/InpatientBedUnavailable.php
    database/migrations/2026_08_21_000300_create_outpatient_core_tables.php
    database/migrations/2026_08_21_000600_add_inpatient_encounter_fields.php
    database/migrations/2026_08_26_000200_create_daily_queue_allocator.php
    database/migrations/2026_08_28_000100_create_inpatient_bed_claim_mutexes.php
    database/seeders/DatabaseSeeder.php
    database/seeders/DemoActorsSeeder.php
    scripts/rehearse-local-postgres17-inpatient-bed-claim.rb
    tests/Documentation/LocalPostgresInpatientBedClaimHarnessContractTest.rb
    tests/Feature/Inpatient/InpatientBedClaimGuardTest.php
    tests/Feature/Inpatient/InpatientFlowTest.php
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
      _stdout, _stderr, status = Open3.capture3(
        safe_environment.merge(env),
        *argv,
        unsetenv_others: true
      )
      raise CommandFailed, "#{File.basename(argv.fetch(0))} unexpectedly succeeded." if status.success?

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
        .gsub(/simrs_bedclaim_[0-9a-f]{12}/, '<disposable-db>')
        .gsub(%r{postgres(?:ql)?://\S+}i, '<redacted-dsn>')
        .gsub(/(password|secret|token)\s*[=:]\s*\S+/i, '\\1=<redacted>')
        .slice(0, 300)
    end
  end

  def initialize(environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @environment = environment
    @runner = runner
    @clock = monotonic_clock || -> { Process.clock_gettime(Process::CLOCK_MONOTONIC) }
    @application_key = "base64:#{Base64.strict_encode64(SecureRandom.random_bytes(32))}"
    @demo_password = SecureRandom.hex(24)
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
    create_owned_cluster!
    verify_owned_cluster_boundary!

    @run_token = SecureRandom.hex(6)
    @database = "simrs_bedclaim_#{@run_token}"
    assert_database_name!(@database)
    assert_database_absent!(@database)
    create_database!
    create_private_schema!
    migrate_and_seed!
    assert_seed_boundary!

    started = @clock.call
    same_bed = run_same_bed_phase!
    distinct_beds = run_distinct_bed_phase!
    queue_serialization = run_queue_serialization_phase!
    source_revision = source_revision!
    elapsed = elapsed_ms(started)

    cleanup!(strict: true)
    evidence_path = write_evidence!(
      same_bed: same_bed,
      distinct_beds: distinct_beds,
      queue_serialization: queue_serialization,
      source_revision: source_revision,
      elapsed_ms: elapsed
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_HARNESS_OWNED_POSTGRESQL17_10_CONCURRENCY_ONLY',
      'evidence_path' => evidence_path,
      'same_bed_one_commit_one_reject' => true,
      'distinct_bed_parallel_progress' => true,
      'daily_queue_serialization_observed' => true
    }
  ensure
    cleanup!
  end

  private

  def assert_operator_confirmation!
    return if @environment['SIMRS_BED_CLAIM_REHEARSAL_CONFIRM'] == CONFIRMATION

    raise CommandFailed,
          "Set SIMRS_BED_CLAIM_REHEARSAL_CONFIRM=#{CONFIRMATION} to authorize the disposable owned cluster."
  end

  def assert_environment_boundary!
    rejected = @environment.keys.select do |name|
      (name == 'DB_URL' || name.start_with?('PG') || EXECUTABLE_OVERRIDE_KEYS.include?(name)) &&
        !@environment.fetch(name, '').to_s.empty?
    end
    return if rejected.empty?

    raise CommandFailed,
          "Bed-claim rehearsal refuses inherited database or executable overrides: #{rejected.sort.join(', ')}."
  end

  def assert_fixed_contracts!
    raise CommandFailed, 'Bed-claim rehearsal must run from its repository-owned location.' unless File.realpath(ROOT) == ROOT

    (SOURCE_FILES - ['scripts/rehearse-local-postgres17-inpatient-bed-claim.rb']).each do |relative_path|
      path = File.join(ROOT, relative_path)
      raise CommandFailed, "Required bed-claim source #{relative_path} is missing." unless File.file?(path)
      raise CommandFailed, "Required bed-claim source #{relative_path} must not be a symlink." if File.symlink?(path)
    end
  end

  def resolve_and_verify_tools!
    EXECUTABLE_NAMES.each { |name| @tools[name] = resolve_executable!(name) }

    postgres_tools = %w[initdb postgres pg_ctl psql createdb dropdb]
    postgres_tools.each do |name|
      output = @runner.run!([tool(name), '--version'])
      unless output.match?(/\b#{Regexp.escape(POSTGRES_VERSION)}\b/)
        raise CommandFailed, "Bed-claim rehearsal requires #{name} from PostgreSQL #{POSTGRES_VERSION}."
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
    @temporary_root = Dir.mktmpdir('simrs-bedclaim-', '/tmp')
    assert_temporary_root!(@temporary_root)
    File.chmod(0o700, @temporary_root)
    @data_directory = File.join(@temporary_root, 'cluster')
    @socket_directory = File.join(@temporary_root, 'socket')
    FileUtils.mkdir(@socket_directory, mode: 0o700)
    @port = SecureRandom.random_number(20_000) + 30_000
    @cluster_user = Etc.getpwuid.name
    unless @cluster_user.match?(/\A[A-Za-z_][A-Za-z0-9_.-]{0,62}\z/)
      raise CommandFailed, 'Bed-claim rehearsal requires a simple local operating-system user name.'
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
    raise CommandFailed, 'Generated bed-claim database name failed its closed pattern.' unless DATABASE_PATTERN.match?(database)
  end

  def assert_database_absent!(database)
    assert_database_name!(database)
    existing = scalar_sql('postgres', "SELECT count(*) FROM pg_database WHERE datname = '#{database}'")
    raise CommandFailed, 'Bed-claim rehearsal refuses a pre-existing generated database.' unless integer!(existing).zero?
  end

  def create_database!
    @runner.run!(connection_arguments('createdb') + ['--maintenance-db=postgres', @database], env: postgres_environment)
    @created_database = @database
  end

  def create_private_schema!
    sql!(@database, 'CREATE SCHEMA laravel;')
  end

  def migrate_and_seed!
    run_artisan!('migrate', '--force', '--no-interaction')
    run_artisan!('db:seed', '--force', '--no-interaction')
  end

  def assert_seed_boundary!
    non_synthetic = scalar_sql(@database, 'SELECT count(*) FROM laravel.patients WHERE is_synthetic = FALSE')
    raise CommandFailed, 'Bed-claim rehearsal seed crossed the synthetic-only boundary.' unless integer!(non_synthetic).zero?

    queue_rows = csv_sql(@database, <<~SQL).split(',').map(&:strip).map { |value| integer!(value) }
      SELECT
        (SELECT count(*) FROM laravel.daily_queue_counters WHERE queue_date = DATE '2030-02-22'),
        (SELECT count(*) FROM laravel.encounters WHERE queue_date = DATE '2030-02-22')
    SQL
    raise CommandFailed, 'Bed-claim rehearsal queue date is not isolated.' unless queue_rows == [0, 0]
  end

  def run_same_bed_phase!
    first = start_worker(phase: 'same', worker: 'A', bed: 'SYNTH-BC-SAME-A', hold_ms: 3000)
    first_sleep = wait_until!(8, 'Same-bed first worker did not reach its in-transaction hold.') do
      activity_count('same', 'A', "wait_event = 'PgSleep'").positive?
    end
    first_pid = activity_pid!('same', 'A')

    second = start_worker(phase: 'same', worker: 'B', bed: 'SYNTH-BC-SAME-A', hold_ms: 0)
    lock_wait = wait_until!(8, 'Same-bed second worker was not observed waiting on the real bed lock.') do
      activity_count('same', 'B', "wait_event_type = 'Lock'").positive?
    end
    second_pid = activity_pid!('same', 'B')
    blocker_relationship = blocking_pids(second_pid).include?(first_pid)
    raise CommandFailed, 'Same-bed lock waiter was not blocked by the first bed claimant.' unless blocker_relationship

    committed, rejected = await_workers!([first, second], 15)
    assert_worker_result!(committed, phase: 'same', worker: 'A', outcome: 'COMMITTED')
    assert_worker_result!(rejected, phase: 'same', worker: 'B', outcome: 'REJECTED_OCCUPIED')
    assert_independent_backends!(committed, rejected)

    occupancy = active_bed_count('SYNTH-BC-SAME-A')
    raise CommandFailed, 'Same-bed phase did not retain exactly one active encounter.' unless occupancy == 1

    {
      'first_claim_hold_observed' => first_sleep,
      'second_claim_lock_wait_observed' => lock_wait,
      'first_claim_was_blocker' => blocker_relationship,
      'independent_backend_sessions' => true,
      'committed_claims' => 1,
      'rejected_occupied_claims' => 1,
      'active_encounters_for_bed' => occupancy,
      'status' => 'PASS'
    }
  end

  def run_distinct_bed_phase!
    first = start_worker(phase: 'distinct', worker: 'A', bed: 'SYNTH-BC-DISTINCT-A', hold_ms: 3500)
    first_sleep = wait_until!(8, 'Distinct-bed first worker did not reach its in-transaction hold.') do
      activity_count('distinct', 'A', "wait_event = 'PgSleep'").positive?
    end

    second = start_worker(phase: 'distinct', worker: 'B', bed: 'SYNTH-BC-DISTINCT-B', hold_ms: 0)
    lock_wait_observed = false
    deadline = @clock.call + 1.75
    until !second.alive?
      lock_wait_observed ||= activity_count('distinct', 'B', "wait_event_type = 'Lock'").positive?
      raise CommandFailed, 'Distinct-bed second worker did not progress while the first bed lock was held.' if @clock.call >= deadline

      sleep 0.02
    end
    second_result = second.value
    first_still_holding = activity_count('distinct', 'A', "wait_event = 'PgSleep'").positive?
    raise CommandFailed, 'Distinct-bed second worker completed only after the first released its bed lock.' unless first_still_holding
    raise CommandFailed, 'Distinct-bed second worker encountered a bed-mutex lock wait.' if lock_wait_observed

    first_result = await_workers!([first], 10).fetch(0)
    assert_worker_result!(first_result, phase: 'distinct', worker: 'A', outcome: 'COMMITTED')
    assert_worker_result!(second_result, phase: 'distinct', worker: 'B', outcome: 'COMMITTED')
    assert_independent_backends!(first_result, second_result)
    occupancy = %w[SYNTH-BC-DISTINCT-A SYNTH-BC-DISTINCT-B].sum { |bed| active_bed_count(bed) }
    raise CommandFailed, 'Distinct-bed phase did not persist both independent claims.' unless occupancy == 2

    {
      'first_claim_hold_observed' => first_sleep,
      'second_committed_while_first_still_holding' => first_still_holding,
      'bed_mutex_lock_wait_observed' => false,
      'bed_mutex_blocker_observed' => false,
      'independent_backend_sessions' => true,
      'committed_claims' => 2,
      'active_encounters_across_distinct_beds' => occupancy,
      'status' => 'PASS'
    }
  end

  def run_queue_serialization_phase!
    first = start_worker(
      phase: 'queue', worker: 'A', bed: 'SYNTH-BC-QUEUE-A', hold_ms: 3000, allocate_queue: true
    )
    first_sleep = wait_until!(8, 'Queue first worker did not reach its in-transaction hold.') do
      activity_count('queue', 'A', "wait_event = 'PgSleep'").positive?
    end
    first_pid = activity_pid!('queue', 'A')

    second = start_worker(
      phase: 'queue', worker: 'B', bed: 'SYNTH-BC-QUEUE-B', hold_ms: 0, allocate_queue: true
    )
    queue_wait = wait_until!(8, 'Queue second worker was not observed waiting on daily counter serialization.') do
      activity_count(
        'queue', 'B',
        "wait_event_type = 'Lock' AND query LIKE '%daily_queue_counters%'"
      ).positive?
    end
    second_pid = activity_pid!('queue', 'B')
    counter_blocker_relationship = blocking_pids(second_pid).include?(first_pid)
    raise CommandFailed, 'Daily counter waiter was not blocked by the first full-registration transaction.' unless counter_blocker_relationship

    first_result, second_result = await_workers!([first, second], 15)
    assert_worker_result!(first_result, phase: 'queue', worker: 'A', outcome: 'COMMITTED')
    assert_worker_result!(second_result, phase: 'queue', worker: 'B', outcome: 'COMMITTED')
    assert_independent_backends!(first_result, second_result)
    queue_numbers = [first_result, second_result].map { |result| integer!(result.fetch('queue_number')) }.sort
    raise CommandFailed, 'Full-registration queue phase did not allocate a contiguous sequence.' unless queue_numbers == [1, 2]

    counter = integer!(scalar_sql(
      @database,
      "SELECT last_number FROM laravel.daily_queue_counters WHERE queue_date = DATE '2030-02-22'"
    ))
    raise CommandFailed, 'Daily queue counter did not preserve its committed high-water mark.' unless counter == 2

    {
      'first_registration_hold_observed' => first_sleep,
      'daily_counter_lock_wait_observed' => queue_wait,
      'daily_counter_blocker_relationship_observed' => counter_blocker_relationship,
      'accepted_daily_counter_serialization' => true,
      'independent_backend_sessions' => true,
      'committed_registrations' => 2,
      'queue_numbers_contiguous' => true,
      'counter_high_water' => counter,
      'status' => 'PASS'
    }
  end

  def start_worker(phase:, worker:, bed:, hold_ms:, allocate_queue: false)
    Thread.new do
      arguments = [
        'ops:rehearse-inpatient-bed-claim-worker',
        "--run-token=#{@run_token}",
        "--phase=#{phase}",
        "--worker=#{worker}",
        "--bed-code=#{bed}",
        "--hold-ms=#{hold_ms}",
        '--confirm-owned-local-synthetic',
        '--no-ansi'
      ]
      arguments << '--allocate-queue' if allocate_queue
      parse_worker_result!(run_artisan!(*arguments))
    end
  end

  def parse_worker_result!(output)
    line = output.lines.map(&:strip).reject(&:empty?).last
    result = JSON.parse(line || '')
    raise CommandFailed, 'Bed-claim worker returned a non-PASS result.' unless result['status'] == 'PASS'

    result
  rescue JSON::ParserError
    raise CommandFailed, 'Bed-claim worker returned malformed JSON.'
  end

  def assert_worker_result!(result, phase:, worker:, outcome:)
    unless result['status'] == 'PASS' && result['phase'] == phase &&
           result['worker'] == worker && result['outcome'] == outcome
      raise CommandFailed, 'Bed-claim worker result did not match its closed phase contract.'
    end
  end

  def assert_independent_backends!(*results)
    pids = results.map { |result| integer!(result.fetch('backend_pid')) }
    raise CommandFailed, 'Bed-claim workers did not use independent PostgreSQL backends.' unless pids.uniq.length == pids.length
  end

  def await_workers!(workers, timeout_seconds)
    Timeout.timeout(timeout_seconds) { workers.map(&:value) }
  rescue Timeout::Error
    raise CommandFailed, 'Bed-claim worker phase exceeded its wall-clock timeout.'
  end

  def application_name(phase, worker)
    "simrs_bedclaim_#{@run_token}_#{phase}_#{worker.downcase}"
  end

  def activity_count(phase, worker, predicate)
    integer!(scalar_sql(@database, <<~SQL))
      SELECT count(*)
      FROM pg_stat_activity
      WHERE datname = current_database()
        AND application_name = '#{application_name(phase, worker)}'
        AND #{predicate}
    SQL
  end

  def activity_pid!(phase, worker)
    value = scalar_sql(@database, <<~SQL)
      SELECT pid
      FROM pg_stat_activity
      WHERE datname = current_database()
        AND application_name = '#{application_name(phase, worker)}'
    SQL
    integer!(value)
  end

  def blocking_pids(pid)
    value = scalar_sql(@database, "SELECT array_to_string(pg_blocking_pids(#{integer!(pid)}), ',')")
    return [] if value.empty?

    value.split(',').map { |item| integer!(item) }
  end

  def active_bed_count(bed)
    integer!(scalar_sql(@database, <<~SQL))
      SELECT count(*)
      FROM laravel.encounters
      WHERE care_setting = 'INPATIENT'
        AND bed_code = '#{bed}'
        AND status <> 'CLOSED'
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

  def run_artisan!(*arguments)
    @runner.run!([tool('php'), File.join(ROOT, 'artisan'), *arguments], env: application_environment)
  end

  def application_environment
    {
      'APP_ENV' => 'testing',
      'APP_KEY' => @application_key,
      'APP_URL' => 'http://simrs-bed-claim.invalid',
      'APP_MODE' => 'SIMULATION',
      'APP_SYNTHETIC_ONLY' => 'true',
      'DEMO_SEED_ENABLED' => 'true',
      'DEMO_ACCOUNT_PASSWORD' => @demo_password,
      'SIMRS_BED_CLAIM_REHEARSAL_CONFIRM' => CONFIRMATION,
      'DB_CONNECTION' => 'pgsql',
      'DB_URL' => '',
      'DB_HOST' => @socket_directory,
      'DB_PORT' => @port.to_s,
      'DB_DATABASE' => @database,
      'DB_USERNAME' => @cluster_user,
      'DB_PASSWORD' => '',
      'DB_SCHEMA' => 'laravel',
      'DB_SSLMODE' => 'disable',
      'CACHE_STORE' => 'array',
      'SESSION_DRIVER' => 'array',
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
    raise CommandFailed, 'PostgreSQL returned a malformed numeric bed-claim rehearsal result.'
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
      raise CommandFailed, "Bed-claim source #{relative_path} disappeared during rehearsal." unless File.file?(path)

      [relative_path, Digest::SHA256.file(path).hexdigest]
    end
    migration_rows = Dir[File.join(ROOT, 'database/migrations/*.php')].sort.map do |path|
      "#{File.basename(path)} #{Digest::SHA256.file(path).hexdigest}"
    end

    {
      'head' => @runner.run!([tool('git'), '-C', ROOT, 'rev-parse', 'HEAD']).strip,
      'working_tree_dirty' => !@runner.run!([tool('git'), '-C', ROOT, 'status', '--porcelain']).strip.empty?,
      'files' => hashes,
      'source_contract_sha256' => Digest::SHA256.hexdigest(
        hashes.map { |path, digest| "#{path} #{digest}" }.join("\n")
      ),
      'migration_file_set_sha256' => Digest::SHA256.hexdigest(migration_rows.join("\n"))
    }
  end

  def write_evidence!(same_bed:, distinct_beds:, queue_serialization:, source_revision:, elapsed_ms:)
    FileUtils.mkdir_p(EVIDENCE_DIRECTORY, mode: 0o700)
    File.chmod(0o700, EVIDENCE_DIRECTORY)
    evidence = {
      'schema_version' => 1,
      'kind' => 'SIMRS_LOCAL_POSTGRESQL_INPATIENT_BED_CLAIM_CONCURRENCY_REHEARSAL',
      'status' => 'PASS',
      'captured_at' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_HARNESS_OWNED_POSTGRESQL17_10_CONCURRENCY_ONLY',
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'external_integrations_enabled' => false,
        'database_engine' => 'postgresql',
        'database_version' => POSTGRES_VERSION,
        'application_schema' => 'laravel',
        'harness_owned_cluster' => true,
        'cluster_directory_mode' => '0700',
        'socket_directory_mode' => '0700',
        'tcp_listening' => false,
        'host_authentication_rules' => 'reject',
        'disposable_database' => true,
        'hosted_concurrency_claim' => false,
        'real_patient_data_rows' => 0
      },
      'source_revision' => source_revision,
      'same_bed' => same_bed,
      'distinct_beds' => distinct_beds,
      'full_registration_queue' => queue_serialization,
      'elapsed_ms' => elapsed_ms,
      'generated_database_removed_before_evidence' => true,
      'owned_cluster_removed_before_evidence' => true,
      'interpretation' => 'Independent PHP processes on a disposable owned PostgreSQL 17.10 cluster prove same-bed exclusion and distinct-bed progress; daily queue counter waiting is recorded separately as accepted registration serialization. Hosted acceptance remains a separate gate.'
    }
    digest = Digest::SHA256.hexdigest(JSON.generate(evidence))
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{digest[0, 12]}.json")
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
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
        strict ? @runner.run!(arguments, env: postgres_environment) : @runner.run_cleanup(arguments, env: postgres_environment)
        _stdout, _stderr, wait_thread, stdout_reader, stderr_reader = @postgres_process
        Timeout.timeout(8) { wait_thread.value }
        stdout_reader.join(1)
        stderr_reader.join(1)
        @postgres_process = nil
      rescue StandardError => exception
        cleanup_error ||= exception
      end
    end

    if @temporary_root
      begin
        assert_temporary_root!(@temporary_root)
        FileUtils.remove_entry_secure(@temporary_root) if File.exist?(@temporary_root)
        raise CommandFailed, 'Owned PostgreSQL cluster directory survived cleanup.' if strict && File.exist?(@temporary_root)

        @temporary_root = nil
      rescue StandardError => exception
        cleanup_error ||= exception
      end
    end

    raise CommandFailed, "Bed-claim rehearsal cleanup failed; no PASS evidence was written: #{cleanup_error.message}" if strict && cleanup_error
  end

  def assert_temporary_root!(path)
    unless File.basename(path).match?(TEMPORARY_ROOT_PATTERN) && File.directory?(path) && !File.symlink?(path)
      raise CommandFailed, 'Bed-claim rehearsal temporary root failed its closed cleanup boundary.'
    end

    parent = File.realpath(File.dirname(path))
    allowed_parent = File.realpath('/tmp')
    raise CommandFailed, 'Bed-claim rehearsal temporary root is outside the operating-system temp directory.' unless parent == allowed_parent
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    result = LocalPostgresInpatientBedClaimRehearsal.new.run!
    puts JSON.pretty_generate(result)
  rescue LocalPostgresInpatientBedClaimRehearsal::CommandFailed => exception
    warn "BLOCKED: #{exception.message}"
    exit 1
  end
end
