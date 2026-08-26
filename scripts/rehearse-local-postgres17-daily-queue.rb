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

class LocalPostgresDailyQueueRehearsal
  ROOT = File.expand_path('..', __dir__)
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_POSTGRES17_QUEUE_ALLOCATION'
  DATABASE_PATTERN = /\Asimrs_queuealloc_[0-9a-f]{12}\z/
  REHEARSAL_QUEUE_DATE = '2030-01-15'
  START_BARRIER_KEY = 8_260_015
  COMMIT_WORKERS = 16
  ROLLBACK_WORKER = 1001
  WAITING_COMMIT_WORKER = 1002
  QUEUE_MIGRATION_PATH = 'database/migrations/2026_08_26_000200_create_daily_queue_allocator.php'
  EVIDENCE_DIRECTORY = File.join(ROOT, 'storage/app/queue-allocation-rehearsals')
  CONTRACT_TEST = 'tests/Documentation/LocalPostgresDailyQueueHarnessContractTest.rb'
  SOURCE_FILES = %w[
    app/Console/Commands/RehearseDailyQueueAllocationWorkerCommand.php
    app/Http/Controllers/Outpatient/OutpatientRegistrationController.php
    app/Http/Controllers/Emergency/EmergencyRegistrationController.php
    app/Http/Controllers/Inpatient/InpatientRegistrationController.php
    app/Models/DailyQueueCounter.php
    app/Models/Encounter.php
    app/Support/Audit/AuditEventSchemaRegistry.php
    app/Support/Operations/ExpectedQueueAllocationRehearsalRollback.php
    app/Support/Registration/DailyQueueAllocation.php
    app/Support/Registration/DailyQueueAllocator.php
    database/migrations/2026_08_26_000200_create_daily_queue_allocator.php
    database/seeders/TeachingCensusSeeder.php
    tests/Feature/Registration/DailyQueueAllocatorTest.php
    tests/Feature/Database/DailyQueueAllocatorMigrationTest.php
    tests/Unit/Registration/DailyQueueAllocatorTransactionTest.php
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
        .gsub(/simrs_queuealloc_[0-9a-f]{12}/, '<disposable-db>')
        .gsub(%r{postgres(?:ql)?://\S+}i, '<redacted-dsn>')
        .gsub(/(password|secret|token)\s*[=:]\s*\S+/i, '\\1=<redacted>')
        .slice(0, 300)
    end
  end

  def initialize(environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @environment = environment
    @runner = runner
    @clock = monotonic_clock || -> { Process.clock_gettime(Process::CLOCK_MONOTONIC) }
    @created_database = nil
    @barrier_session = nil
    @application_key = "base64:#{Base64.strict_encode64(SecureRandom.random_bytes(32))}"
    @demo_password = SecureRandom.hex(24)
  end

  def run!
    assert_operator_confirmation!
    configure_connection!
    assert_fixed_contracts!
    verify_tools_and_server!

    @run_token = SecureRandom.hex(6)
    @database = "simrs_queuealloc_#{@run_token}"
    assert_database_name!(@database)
    assert_database_absent!(@database)
    create_database!
    create_private_schema!
    migrate_and_seed!
    assert_seed_boundary!

    started = @clock.call
    commit_phase = run_commit_contention!
    rollback_phase = run_rollback_reuse!
    migration_round_trip = verify_migration_round_trip!
    elapsed = elapsed_ms(started)
    source_revision = source_revision!

    cleanup!(strict: true)
    evidence_path = write_evidence!(
      commit_phase: commit_phase,
      rollback_phase: rollback_phase,
      migration_round_trip: migration_round_trip,
      elapsed_ms: elapsed,
      source_revision: source_revision
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_POSTGRESQL17_CONCURRENCY_ONLY',
      'evidence_path' => evidence_path,
      'commit_workers' => COMMIT_WORKERS,
      'committed_queue_high_water' => COMMIT_WORKERS + 1,
      'rollback_reuse_proven' => true
    }
  ensure
    release_start_barrier!
    cleanup!
  end

  private

  def assert_operator_confirmation!
    return if @environment['SIMRS_QUEUE_ALLOC_REHEARSAL_CONFIRM'] == CONFIRMATION

    raise CommandFailed,
          "Set SIMRS_QUEUE_ALLOC_REHEARSAL_CONFIRM=#{CONFIRMATION} to authorize disposable local database creation."
  end

  def configure_connection!
    @host = @environment.fetch('PGHOST', '').strip
    @host = '127.0.0.1' if @host.empty?
    allowed_hosts = ['127.0.0.1', 'localhost', '::1', '/tmp', '/private/tmp']
    raise CommandFailed, 'Daily queue rehearsal refuses a non-local PostgreSQL host.' unless allowed_hosts.include?(@host)

    @port = @environment.fetch('PGPORT', '5432')
    unless @port.match?(/\A[0-9]{1,5}\z/) && @port.to_i.between?(1, 65_535)
      raise CommandFailed, 'Daily queue rehearsal requires a valid local PostgreSQL port.'
    end

    @user = @environment.fetch('PGUSER', '').strip
    @user = Etc.getpwuid.name if @user.empty?
    unless @user.match?(/\A[A-Za-z_][A-Za-z0-9_.-]{0,62}\z/)
      raise CommandFailed, 'Daily queue rehearsal requires a simple PostgreSQL role name.'
    end
  end

  def assert_fixed_contracts!
    raise CommandFailed, 'Daily queue rehearsal must run from its repository-owned location.' unless File.realpath(ROOT) == ROOT

    (SOURCE_FILES + [CONTRACT_TEST]).each do |relative_path|
      path = File.join(ROOT, relative_path)
      raise CommandFailed, "Required rehearsal source #{relative_path} is missing." unless File.file?(path)
      raise CommandFailed, "Required rehearsal source #{relative_path} must not be a symlink." if File.symlink?(path)
    end
  end

  def verify_tools_and_server!
    %w[psql createdb dropdb php git].each do |tool|
      @runner.run!([tool, '--version'], env: postgres_environment)
    end

    version_num = @runner.run!(
      psql_arguments('postgres') + ['--tuples-only', '--no-align', '--command', 'SHOW server_version_num'],
      env: postgres_environment
    ).strip
    raise CommandFailed, 'Daily queue rehearsal requires PostgreSQL major version 17.' unless version_num.match?(/\A17[0-9]{4}\z/)

    server_is_local = @runner.run!(
      psql_arguments('postgres') + [
        '--tuples-only', '--no-align', '--command',
        <<~SQL
          SELECT inet_server_addr() IS NULL
              OR inet_server_addr() <<= inet '127.0.0.0/8'
              OR inet_server_addr() = inet '::1'
        SQL
      ],
      env: postgres_environment
    ).strip
    unless server_is_local == 't'
      raise CommandFailed, 'Daily queue rehearsal refuses a PostgreSQL server whose actual address is not local.'
    end
  end

  def assert_database_name!(database)
    raise CommandFailed, 'Generated daily queue database name failed its closed pattern.' unless DATABASE_PATTERN.match?(database)
  end

  def assert_database_absent!(database)
    assert_database_name!(database)
    output = @runner.run!(
      psql_arguments('postgres') + [
        '--tuples-only', '--no-align', '--command',
        "SELECT datname FROM pg_database WHERE datname LIKE 'simrs_queuealloc_%' ORDER BY datname"
      ],
      env: postgres_environment
    )
    existing = output.lines.map(&:strip).reject(&:empty?)
    raise CommandFailed, 'Daily queue rehearsal refuses a pre-existing generated database.' if existing.include?(database)
  end

  def create_database!
    @runner.run!(connection_arguments('createdb') + ['--maintenance-db=postgres', @database], env: postgres_environment)
    @created_database = @database
  end

  def create_private_schema!
    @runner.run!(psql_arguments(@database) + ['--command', 'CREATE SCHEMA laravel;'], env: postgres_environment)
  end

  def migrate_and_seed!
    run_artisan!('migrate', '--force', '--no-interaction')
    run_artisan!('db:seed', '--force', '--no-interaction')
  end

  def assert_seed_boundary!
    output = scalar_sql(<<~SQL)
      SELECT count(*)
      FROM laravel.patients
      WHERE is_synthetic = FALSE
    SQL
    raise CommandFailed, 'Daily queue rehearsal seed crossed the synthetic-only boundary.' unless integer!(output) == 0
  end

  def run_commit_contention!
    counter_absent_before_start = assert_rehearsal_queue_absent!
    acquire_start_barrier!
    workers = (1..COMMIT_WORKERS).map do |worker|
      start_worker(worker: worker, mode: 'commit', hold_ms: 20, barrier: true)
    end

    waiting = wait_until!(10, 'All commit workers did not reach the simultaneous start barrier.') do
      commit_barrier_waiter_count == COMMIT_WORKERS
    end
    release_start_barrier!
    results = await_workers!(workers, 45)

    queue_numbers = results.map { |result| integer!(result.fetch('queue_number')) }
    backend_pids = results.map { |result| integer!(result.fetch('backend_pid')) }
    unless results.all? { |result| result['status'] == 'PASS' && result['committed'] == true && result['rolled_back'] == false }
      raise CommandFailed, 'A commit contention worker did not return its reviewed PASS state.'
    end
    unless queue_numbers.sort == (1..COMMIT_WORKERS).to_a
      raise CommandFailed, 'Commit contention did not produce one contiguous daily queue sequence.'
    end
    unless backend_pids.uniq.length == COMMIT_WORKERS
      raise CommandFailed, 'Commit contention workers did not use independent PostgreSQL backend sessions.'
    end

    stats = queue_stats!
    unless stats == {
      'encounters' => COMMIT_WORKERS,
      'distinct_numbers' => COMMIT_WORKERS,
      'minimum' => 1,
      'maximum' => COMMIT_WORKERS,
      'counter' => COMMIT_WORKERS,
      'audit_events' => COMMIT_WORKERS,
      'non_synthetic_patients' => 0
    }
      raise CommandFailed, 'Committed PostgreSQL queue state did not match the reviewed contention contract.'
    end

    {
      'workers' => COMMIT_WORKERS,
      'independent_backend_sessions' => backend_pids.uniq.length,
      'counter_absent_before_start' => counter_absent_before_start,
      'simultaneous_barrier_waiters_observed' => waiting,
      'queue_numbers_contiguous' => true,
      'database_uniqueness_preserved' => true,
      'stats' => stats,
      'status' => 'PASS'
    }
  end

  def assert_rehearsal_queue_absent!
    values = csv_sql(<<~SQL).split(',').map(&:strip).map { |value| integer!(value) }
      SELECT
        (SELECT count(*) FROM laravel.daily_queue_counters
          WHERE queue_date = DATE '#{REHEARSAL_QUEUE_DATE}'),
        (SELECT count(*) FROM laravel.encounters
          WHERE queue_date = DATE '#{REHEARSAL_QUEUE_DATE}')
    SQL
    unless values == [0, 0]
      raise CommandFailed, 'Daily queue rehearsal date was not empty before the first-row contention phase.'
    end

    true
  end

  def commit_barrier_waiter_count
    worker_names = (1..COMMIT_WORKERS).map do |worker|
      "'simrs_queuealloc_#{@run_token}_#{format('%04d', worker)}'"
    end.join(', ')

    integer!(scalar_sql(<<~SQL))
      SELECT count(DISTINCT activity.application_name)
      FROM pg_locks AS locks
      INNER JOIN pg_stat_activity AS activity ON activity.pid = locks.pid
      WHERE locks.locktype = 'advisory'
        AND locks.database = (SELECT oid FROM pg_database WHERE datname = current_database())
        AND locks.classid = 0
        AND locks.objid = #{START_BARRIER_KEY}
        AND locks.objsubid = 1
        AND locks.mode = 'ShareLock'
        AND locks.granted = FALSE
        AND activity.datname = current_database()
        AND activity.application_name IN (#{worker_names})
    SQL
  end

  def run_rollback_reuse!
    rollback_worker = start_worker(worker: ROLLBACK_WORKER, mode: 'rollback', hold_ms: 3000, barrier: false)
    rollback_sleep_observed = wait_until!(8, 'Rollback worker did not reach its in-transaction hold state.') do
      activity_count(ROLLBACK_WORKER, "wait_event = 'PgSleep'").positive?
    end

    waiting_worker = start_worker(worker: WAITING_COMMIT_WORKER, mode: 'commit', hold_ms: 0, barrier: false)
    lock_wait_observed = wait_until!(8, 'Commit worker was not observed waiting on the allocator lock.') do
      activity_count(WAITING_COMMIT_WORKER, "wait_event_type = 'Lock'").positive?
    end

    rolled_back, committed = await_workers!([rollback_worker, waiting_worker], 15)
    expected_number = COMMIT_WORKERS + 1

    unless [
      rolled_back['status'] == 'PASS',
      rolled_back['rolled_back'] == true,
      rolled_back['committed'] == false,
      integer!(rolled_back.fetch('queue_number')) == expected_number
    ].all?
      raise CommandFailed, 'Rollback worker did not return the expected uncommitted allocation state.'
    end
    unless [
      committed['status'] == 'PASS',
      committed['committed'] == true,
      committed['rolled_back'] == false,
      integer!(committed.fetch('queue_number')) == expected_number
    ].all?
      raise CommandFailed, 'Waiting worker did not reuse the rolled-back queue number.'
    end
    if integer!(rolled_back.fetch('backend_pid')) == integer!(committed.fetch('backend_pid'))
      raise CommandFailed, 'Rollback reuse workers did not use independent PostgreSQL backend sessions.'
    end

    stats = queue_stats!
    unless stats == {
      'encounters' => expected_number,
      'distinct_numbers' => expected_number,
      'minimum' => 1,
      'maximum' => expected_number,
      'counter' => expected_number,
      'audit_events' => expected_number,
      'non_synthetic_patients' => 0
    }
      raise CommandFailed, 'Post-rollback PostgreSQL queue state did not match the reviewed contract.'
    end

    rollback_persistence = integer!(scalar_sql(<<~SQL))
      SELECT count(*)
      FROM laravel.encounters
      WHERE booking_code = 'SYNTH-QA-#{@run_token}-#{format('%04d', ROLLBACK_WORKER)}'
    SQL
    committed_persistence = integer!(scalar_sql(<<~SQL))
      SELECT count(*)
      FROM laravel.encounters
      WHERE booking_code = 'SYNTH-QA-#{@run_token}-#{format('%04d', WAITING_COMMIT_WORKER)}'
    SQL
    rolled_back_resource_id = rehearsal_resource_id!(rolled_back)
    committed_resource_id = rehearsal_resource_id!(committed)
    rollback_audit_persistence = integer!(scalar_sql(<<~SQL))
      SELECT count(*)
      FROM laravel.audit_events
      WHERE action = 'patient.register'
        AND resource_type = 'encounter'
        AND resource_id = '#{rolled_back_resource_id}'
    SQL
    committed_audit_persistence = integer!(scalar_sql(<<~SQL))
      SELECT count(*)
      FROM laravel.audit_events
      WHERE action = 'patient.register'
        AND resource_type = 'encounter'
        AND resource_id = '#{committed_resource_id}'
    SQL
    unless [
      rollback_persistence.zero?,
      committed_persistence == 1,
      rollback_audit_persistence.zero?,
      committed_audit_persistence == 1
    ].all?
      raise CommandFailed, 'Rollback worker persistence boundary did not match the reviewed contract.'
    end

    {
      'rollback_transaction_hold_observed' => rollback_sleep_observed,
      'waiting_worker_lock_wait_observed' => lock_wait_observed,
      'independent_backend_sessions' => 2,
      'rolled_back_number_reused' => true,
      'rolled_back_encounter_absent' => true,
      'rolled_back_audit_absent' => true,
      'committed_counter_high_water' => expected_number,
      'stats' => stats,
      'status' => 'PASS'
    }
  end

  def verify_migration_round_trip!
    run_artisan!('migrate:rollback', "--path=#{QUEUE_MIGRATION_PATH}", '--force', '--no-interaction')
    after_rollback = csv_sql(<<~SQL).split(',').map(&:strip)
      SELECT
        to_regclass('laravel.daily_queue_counters') IS NULL,
        NOT EXISTS (
          SELECT 1 FROM information_schema.columns
          WHERE table_schema = 'laravel' AND table_name = 'encounters' AND column_name = 'queue_date'
        )
    SQL
    unless after_rollback == %w[t t]
      raise CommandFailed, 'Daily queue migration rollback did not remove its schema.'
    end

    run_artisan!('migrate', "--path=#{QUEUE_MIGRATION_PATH}", '--force', '--no-interaction')
    after_reapply = csv_sql(<<~SQL).split(',').map(&:strip)
      SELECT
        to_regclass('laravel.daily_queue_counters') IS NOT NULL,
        EXISTS (
          SELECT 1 FROM information_schema.columns
          WHERE table_schema = 'laravel' AND table_name = 'encounters'
            AND column_name = 'queue_date' AND is_nullable = 'NO'
        ),
        EXISTS (
          SELECT 1 FROM pg_constraint
          WHERE conname = 'encounters_queue_date_number_unique'
        )
    SQL
    unless after_reapply == %w[t t t]
      raise CommandFailed, 'Daily queue migration reapply did not restore its required invariants.'
    end

    expected_number = COMMIT_WORKERS + 1
    stats = queue_stats!
    unless [
      stats['encounters'] == expected_number,
      stats['distinct_numbers'] == expected_number,
      stats['minimum'] == 1,
      stats['maximum'] == expected_number,
      stats['counter'] == expected_number,
      stats['non_synthetic_patients'] == 0
    ].all?
      raise CommandFailed, 'Daily queue migration reapply did not preserve a valid synthetic queue state.'
    end

    {
      'rollback_removed_counter_table' => true,
      'rollback_removed_queue_date' => true,
      'reapply_restored_non_null_queue_date' => true,
      'reapply_restored_unique_constraint' => true,
      'reapply_rebuilt_counter_high_water' => true,
      'status' => 'PASS'
    }
  end

  def acquire_start_barrier!
    raise CommandFailed, 'Daily queue start barrier is already active.' if @barrier_session

    stdin, stdout, stderr, wait_thread = @runner.start!(
      psql_arguments(@database) + ['--quiet', '--tuples-only', '--no-align'],
      env: postgres_environment
    )
    stderr_thread = Thread.new { stderr.read }
    @barrier_session = [stdin, stdout, stderr, wait_thread, stderr_thread]
    stdin.puts("SELECT pg_advisory_lock(#{START_BARRIER_KEY});")
    stdin.puts("SELECT 'BARRIER_READY';")
    stdin.flush

    ready = Timeout.timeout(8) do
      stdout.each_line.find { |line| line.strip == 'BARRIER_READY' }
    end
    raise CommandFailed, 'Daily queue start barrier did not become ready.' unless ready
  rescue Timeout::Error
    raise CommandFailed, 'Daily queue start barrier timed out.'
  end

  def release_start_barrier!
    return unless @barrier_session

    stdin, stdout, stderr, wait_thread, stderr_thread = @barrier_session
    stdin.puts("SELECT pg_advisory_unlock(#{START_BARRIER_KEY});") unless stdin.closed?
    stdin.puts('\\q') unless stdin.closed?
    stdin.close unless stdin.closed?
    Timeout.timeout(8) { wait_thread.value }
    stderr_thread.join(1)
    stdout.close unless stdout.closed?
    stderr.close unless stderr.closed?
    @barrier_session = nil
  rescue StandardError
    if @barrier_session
      wait_thread = @barrier_session[3]
      Process.kill('TERM', wait_thread.pid) if wait_thread&.alive?
      @barrier_session = nil
    end
  end

  def start_worker(worker:, mode:, hold_ms:, barrier:)
    Thread.new do
      arguments = [
        'ops:rehearse-daily-queue-worker',
        "--run-token=#{@run_token}",
        "--worker=#{worker}",
        "--mode=#{mode}",
        "--hold-ms=#{hold_ms}",
        '--confirm-local-synthetic',
        '--no-ansi'
      ]
      arguments << '--barrier' if barrier
      parse_worker_result!(run_artisan!(*arguments))
    end
  end

  def await_workers!(workers, timeout_seconds)
    Timeout.timeout(timeout_seconds) { workers.map(&:value) }
  rescue Timeout::Error
    raise CommandFailed, 'Daily queue worker phase exceeded its wall-clock timeout.'
  end

  def parse_worker_result!(output)
    line = output.lines.map(&:strip).reject(&:empty?).last
    result = JSON.parse(line || '')
    raise CommandFailed, 'Daily queue worker returned a non-PASS result.' unless result['status'] == 'PASS'

    result
  rescue JSON::ParserError
    raise CommandFailed, 'Daily queue worker returned malformed JSON.'
  end

  def activity_count(worker, predicate)
    application_name = "simrs_queuealloc_#{@run_token}_#{format('%04d', worker)}"
    integer!(scalar_sql(<<~SQL))
      SELECT count(*)
      FROM pg_stat_activity
      WHERE datname = current_database()
        AND application_name = '#{application_name}'
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

  def queue_stats!
    values = csv_sql(<<~SQL).split(',').map(&:strip)
      SELECT
        count(*),
        count(DISTINCT e.queue_number),
        min(e.queue_number),
        max(e.queue_number),
        (SELECT last_number FROM laravel.daily_queue_counters WHERE queue_date = DATE '#{REHEARSAL_QUEUE_DATE}'),
        (
          SELECT count(*) FROM laravel.audit_events
          WHERE action = 'patient.register'
            AND metadata ->> 'queue_date' = '#{REHEARSAL_QUEUE_DATE}'
        ),
        (SELECT count(*) FROM laravel.patients WHERE is_synthetic = FALSE)
      FROM laravel.encounters AS e
      WHERE e.queue_date = DATE '#{REHEARSAL_QUEUE_DATE}'
        AND e.booking_code LIKE 'SYNTH-QA-#{@run_token}-%'
    SQL
    raise CommandFailed, 'Daily queue statistics output was malformed.' unless values.length == 7

    {
      'encounters' => integer!(values[0]),
      'distinct_numbers' => integer!(values[1]),
      'minimum' => integer!(values[2]),
      'maximum' => integer!(values[3]),
      'counter' => integer!(values[4]),
      'audit_events' => integer!(values[5]),
      'non_synthetic_patients' => integer!(values[6])
    }
  end

  def scalar_sql(sql)
    @runner.run!(
      psql_arguments(@database) + ['--tuples-only', '--no-align', '--command', sql],
      env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
    ).strip
  end

  def csv_sql(sql)
    @runner.run!(
      psql_arguments(@database) + ['--tuples-only', '--no-align', '--field-separator', ',', '--command', sql],
      env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
    ).strip
  end

  def integer!(value)
    return value if value.is_a?(Integer)

    Integer(value, 10)
  rescue ArgumentError, TypeError
    raise CommandFailed, 'PostgreSQL returned a malformed numeric rehearsal result.'
  end

  def rehearsal_resource_id!(result)
    value = result.fetch('encounter_public_id')
    unless value.is_a?(String) && value.match?(/\A[0-9A-HJKMNP-TV-Z]{26}\z/)
      raise CommandFailed, 'Daily queue worker returned a malformed encounter resource identifier.'
    end

    value
  rescue KeyError
    raise CommandFailed, 'Daily queue worker omitted its encounter resource identifier.'
  end

  def source_revision!
    hashes = SOURCE_FILES.to_h do |relative_path|
      [relative_path, Digest::SHA256.file(File.join(ROOT, relative_path)).hexdigest]
    end

    {
      'head' => @runner.run!(['git', '-C', ROOT, 'rev-parse', 'HEAD']).strip,
      'working_tree_dirty' => !@runner.run!(['git', '-C', ROOT, 'status', '--porcelain']).strip.empty?,
      'migration_file_set_sha256' => migration_file_set_sha256,
      'source_contract_sha256' => Digest::SHA256.hexdigest(
        hashes.map { |path, digest| "#{path} #{digest}" }.join("\n")
      ),
      'harness_sha256' => Digest::SHA256.file(__FILE__).hexdigest,
      'contract_test_sha256' => Digest::SHA256.file(File.join(ROOT, CONTRACT_TEST)).hexdigest
    }
  end

  def migration_file_set_sha256
    rows = Dir[File.join(ROOT, 'database/migrations/*.php')].sort.map do |path|
      "#{File.basename(path)} #{Digest::SHA256.file(path).hexdigest}"
    end
    Digest::SHA256.hexdigest(rows.join("\n"))
  end

  def write_evidence!(commit_phase:, rollback_phase:, migration_round_trip:, elapsed_ms:, source_revision:)
    FileUtils.mkdir_p(EVIDENCE_DIRECTORY, mode: 0o700)
    evidence = {
      'schema_version' => 1,
      'kind' => 'SIMRS_LOCAL_POSTGRESQL_DAILY_QUEUE_CONCURRENCY_REHEARSAL',
      'status' => 'PASS',
      'captured_at' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_POSTGRESQL17_CONCURRENCY_ONLY',
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'database_engine' => 'postgresql',
        'database_major' => 17,
        'application_schema' => 'laravel',
        'queue_timezone' => 'Asia/Jakarta',
        'disposable_database' => true,
        'hosted_concurrency_claim' => false,
        'mysql_8_4_claim' => false,
        'real_patient_data_rows' => 0
      },
      'source_revision' => source_revision,
      'commit_contention' => commit_phase,
      'rollback_reuse' => rollback_phase,
      'migration_round_trip' => migration_round_trip,
      'elapsed_ms' => elapsed_ms,
      'temporary_database_removed_before_evidence' => true,
      'interpretation' => 'Independent local PostgreSQL 17 processes prove allocator serialization and rollback reuse only; hosted and MySQL 8.4 acceptance remain separate gates.'
    }
    digest = Digest::SHA256.hexdigest(JSON.generate(evidence))
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{digest[0, 12]}.json")
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    path
  end

  def run_artisan!(*arguments)
    @runner.run!(['php', File.join(ROOT, 'artisan'), *arguments], env: application_environment)
  end

  def application_environment
    {
      'APP_ENV' => 'testing',
      'APP_KEY' => @application_key,
      'APP_MODE' => 'SIMULATION',
      'APP_SYNTHETIC_ONLY' => 'true',
      'DEMO_SEED_ENABLED' => 'true',
      'DEMO_ACCOUNT_PASSWORD' => @demo_password,
      'SIMRS_QUEUE_ALLOC_REHEARSAL_CONFIRM' => CONFIRMATION,
      'DB_CONNECTION' => 'pgsql',
      'DB_URL' => '',
      'DB_HOST' => @host,
      'DB_PORT' => @port,
      'DB_DATABASE' => @database,
      'DB_USERNAME' => @user,
      'DB_PASSWORD' => @environment.fetch('PGPASSWORD', ''),
      'DB_SCHEMA' => 'laravel',
      'DB_SSLMODE' => 'disable',
      'CACHE_STORE' => 'array',
      'SESSION_DRIVER' => 'array',
      'QUEUE_CONNECTION' => 'sync',
      'LOG_CHANNEL' => 'stderr'
    }
  end

  def psql_arguments(database)
    connection_arguments('psql') + ['--no-psqlrc', '--set', 'ON_ERROR_STOP=1', '--dbname', database]
  end

  def connection_arguments(tool)
    [tool, '--host', @host, '--port', @port, '--username', @user]
  end

  def postgres_environment
    environment = { 'PGCONNECT_TIMEOUT' => '5', 'PGSSLMODE' => 'disable' }
    environment['PGPASSWORD'] = @environment['PGPASSWORD'] if @environment.key?('PGPASSWORD')
    environment
  end

  def elapsed_ms(started)
    ((@clock.call - started) * 1000).round
  end

  def cleanup!(strict: false)
    return unless @created_database && DATABASE_PATTERN.match?(@created_database)

    arguments = connection_arguments('dropdb') + [
      '--if-exists', '--force', '--maintenance-db=postgres', @created_database
    ]
    if strict
      @runner.run!(arguments, env: postgres_environment)
      assert_database_absent!(@created_database)
    else
      @runner.run_cleanup(arguments, env: postgres_environment)
    end
    @created_database = nil
  rescue StandardError
    raise if strict
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    result = LocalPostgresDailyQueueRehearsal.new.run!
    puts JSON.pretty_generate(result)
  rescue LocalPostgresDailyQueueRehearsal::CommandFailed => e
    warn "BLOCKED: #{e.message}"
    exit 1
  end
end
