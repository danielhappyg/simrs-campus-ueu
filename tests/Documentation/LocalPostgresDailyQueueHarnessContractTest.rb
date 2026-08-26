# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require_relative '../../scripts/rehearse-local-postgres17-daily-queue'

class LocalPostgresDailyQueueHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/rehearse-local-postgres17-daily-queue.rb')
  WORKER = File.join(ROOT, 'app/Console/Commands/RehearseDailyQueueAllocationWorkerCommand.php')
  ALLOCATOR = File.join(ROOT, 'app/Support/Registration/DailyQueueAllocator.php')
  MIGRATION = File.join(ROOT, 'database/migrations/2026_08_26_000200_create_daily_queue_allocator.php')

  class NoCommandRunner
    def run!(*args, **kwargs)
      raise "Unexpected command before safety boundary: #{args.inspect} #{kwargs.inspect}"
    end

    def run_cleanup(*); end
  end

  def test_script_is_valid_ruby
    _stdout, stderr, status = Open3.capture3('ruby', '-c', SCRIPT)

    assert status.success?, stderr
  end

  def test_explicit_confirmation_is_required_before_any_command
    rehearsal = LocalPostgresDailyQueueRehearsal.new(environment: {}, runner: NoCommandRunner.new)

    error = assert_raises(LocalPostgresDailyQueueRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/SIMRS_QUEUE_ALLOC_REHEARSAL_CONFIRM/, error.message)
  end

  def test_non_local_host_is_rejected_before_any_command
    rehearsal = LocalPostgresDailyQueueRehearsal.new(
      environment: {
        'SIMRS_QUEUE_ALLOC_REHEARSAL_CONFIRM' => LocalPostgresDailyQueueRehearsal::CONFIRMATION,
        'PGHOST' => 'db.example.invalid'
      },
      runner: NoCommandRunner.new
    )

    error = assert_raises(LocalPostgresDailyQueueRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/non-local PostgreSQL host/, error.message)
  end

  def test_runner_does_not_inherit_libpq_host_override
    original = ENV['PGHOSTADDR']
    ENV['PGHOSTADDR'] = '203.0.113.8'

    output = LocalPostgresDailyQueueRehearsal::Runner.new.run!(
      ['ruby', '-e', "print ENV.key?('PGHOSTADDR') ? ENV.fetch('PGHOSTADDR') : 'absent'"]
    )

    assert_equal 'absent', output
  ensure
    original.nil? ? ENV.delete('PGHOSTADDR') : ENV['PGHOSTADDR'] = original
  end

  def test_closed_database_namespace_and_verified_cleanup_precede_evidence
    source = File.read(SCRIPT)

    assert_includes source, 'simrs_queuealloc_#{@run_token}'
    assert_includes source, 'DATABASE_PATTERN.match?(database)'
    assert_includes source, '@created_database = @database'
    assert_includes source, 'cleanup!(strict: true)'
    assert_operator source.index('cleanup!(strict: true)'), :<, source.index('evidence_path = write_evidence!')
    refute_match(/OptionParser|--database|--root|SIMRS_QUEUE_ALLOC_EVIDENCE_DIR/, source)
    refute_includes source, 'migrate:fresh'
    refute_includes source, '--clean'
    refute_includes source, 'simulation:reset'
  end

  def test_harness_and_worker_bind_postgres17_local_private_synthetic_boundary
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, "'APP_MODE' => 'SIMULATION'"
    assert_includes harness, "'APP_SYNTHETIC_ONLY' => 'true'"
    assert_includes harness, "'DB_SCHEMA' => 'laravel'"
    assert_includes harness, "'DB_URL' => ''"
    assert_includes harness, 'inet_server_addr() IS NULL'
    assert_includes worker, "getenv('SIMRS_QUEUE_ALLOC_REHEARSAL_CONFIRM')"
    assert_includes worker, "option('confirm-local-synthetic')"
    assert_includes worker, 'SHOW server_version_num'
    assert_includes worker, "SchemaQualifier::primarySchema() !== 'laravel'"
    assert_includes worker, 'inet_server_addr() IS NULL'
    assert_includes worker, "where('is_synthetic', false)->exists()"
  end

  def test_real_allocator_runs_in_independent_php_worker_processes
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_equal 16, LocalPostgresDailyQueueRehearsal::COMMIT_WORKERS
    assert_includes harness, "Thread.new do"
    assert_includes harness, "'ops:rehearse-daily-queue-worker'"
    assert_includes harness, "'independent_backend_sessions'"
    assert_includes worker, 'DB::transaction(function () use ('
    assert_includes worker, '$allocator->allocate($registeredAt)'
    assert_includes worker, '$auditRecorder->record('
    assert_includes worker, "DB::scalar('SELECT pg_backend_pid()')"
  end

  def test_advisory_lock_is_only_a_simultaneous_start_barrier
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)
    allocator = File.read(ALLOCATOR)

    assert_includes harness, 'pg_advisory_lock('
    assert_includes harness, "locks.locktype = 'advisory'"
    assert_includes harness, 'locks.database = (SELECT oid FROM pg_database WHERE datname = current_database())'
    assert_includes harness, "locks.mode = 'ShareLock'"
    assert_includes harness, "locks.granted = FALSE"
    assert_includes harness, 'locks.objid = #{START_BARRIER_KEY}'
    assert_includes harness, 'activity.application_name IN (#{worker_names})'
    assert_includes harness, 'commit_barrier_waiter_count == COMMIT_WORKERS'
    assert_includes worker, 'pg_advisory_xact_lock_shared'
    assert_includes allocator, 'lockForUpdate()'
    refute_includes allocator, 'advisory'
    refute_includes allocator, "max('queue_number')"
  end

  def test_first_row_contention_requires_an_explicitly_empty_rehearsal_date
    source = File.read(SCRIPT)

    assert_includes source, 'counter_absent_before_start = assert_rehearsal_queue_absent!'
    assert_includes source, "WHERE queue_date = DATE '\#{REHEARSAL_QUEUE_DATE}'"
    assert_includes source, 'unless values == [0, 0]'
    assert_includes source, "'counter_absent_before_start' => counter_absent_before_start"
  end

  def test_rollback_reuse_requires_an_observed_waiter_and_absent_rolled_back_writes
    source = File.read(SCRIPT)

    assert_includes source, "wait_event = 'PgSleep'"
    assert_includes source, "wait_event_type = 'Lock'"
    assert_includes source, "mode: 'rollback', hold_ms: 3000"
    assert_includes source, 'rollback_audit_persistence.zero?'
    assert_includes source, 'committed_audit_persistence == 1'
    assert_includes source, "resource_id = '\#{rolled_back_resource_id}'"
    assert_includes source, 'rehearsal_resource_id!(rolled_back)'
    assert_includes source, "'rolled_back_number_reused' => true"
    assert_includes source, "'rolled_back_encounter_absent' => true"
    assert_includes source, "'rolled_back_audit_absent' => true"
  end

  def test_database_unique_constraint_and_migration_round_trip_are_required
    harness = File.read(SCRIPT)
    migration = File.read(MIGRATION)

    assert_includes migration, "unique(['queue_date', 'queue_number']"
    assert_includes migration, "date('queue_date')->nullable(false)->change()"
    assert_includes harness, "run_artisan!('migrate:rollback', \"--path=\#{QUEUE_MIGRATION_PATH}\""
    assert_includes harness, "run_artisan!('migrate', \"--path=\#{QUEUE_MIGRATION_PATH}\""
    assert_includes harness, 'encounters_queue_date_number_unique'
    assert_includes harness, "'reapply_restored_unique_constraint' => true"
  end

  def test_evidence_is_aggregate_only_and_does_not_overclaim
    source = File.read(SCRIPT)
    evidence_method = source[/def write_evidence!.*?^  end/m]

    refute_nil evidence_method
    assert_includes evidence_method, "'hosted_concurrency_claim' => false"
    assert_includes evidence_method, "'mysql_8_4_claim' => false"
    assert_includes evidence_method, "'real_patient_data_rows' => 0"
    refute_includes evidence_method, '@database'
    refute_includes evidence_method, '@run_token'
    refute_includes evidence_method, 'backend_pid'
    refute_includes evidence_method, 'PGPASSWORD'
    refute_includes evidence_method, 'DB_PASSWORD'
    refute_includes evidence_method, 'DEMO_ACCOUNT_PASSWORD'
  end
end
