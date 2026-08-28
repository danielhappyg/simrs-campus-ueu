# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require_relative '../../scripts/rehearse-local-postgres17-inpatient-bed-claim'

class LocalPostgresInpatientBedClaimHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/rehearse-local-postgres17-inpatient-bed-claim.rb')
  WORKER = File.join(ROOT, 'app/Console/Commands/RehearseInpatientBedClaimWorkerCommand.php')
  GUARD = File.join(ROOT, 'app/Support/Registration/InpatientBedClaimGuard.php')
  ALLOCATOR = File.join(ROOT, 'app/Support/Registration/DailyQueueAllocator.php')

  class NoCommandRunner
    def run!(*args, **kwargs)
      raise "Unexpected command before safety boundary: #{args.inspect} #{kwargs.inspect}"
    end

    def run_cleanup(*); end
  end

  def test_script_and_worker_are_syntactically_valid
    _stdout, ruby_stderr, ruby_status = Open3.capture3('ruby', '-c', SCRIPT)
    _stdout, php_stderr, php_status = Open3.capture3('php', '-l', WORKER)

    assert ruby_status.success?, ruby_stderr
    assert php_status.success?, php_stderr
  end

  def test_explicit_confirmation_is_required_before_any_command
    rehearsal = LocalPostgresInpatientBedClaimRehearsal.new(environment: {}, runner: NoCommandRunner.new)

    error = assert_raises(LocalPostgresInpatientBedClaimRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/SIMRS_BED_CLAIM_REHEARSAL_CONFIRM/, error.message)
  end

  def test_inherited_database_and_executable_overrides_are_rejected_before_any_command
    base = {
      'SIMRS_BED_CLAIM_REHEARSAL_CONFIRM' => LocalPostgresInpatientBedClaimRehearsal::CONFIRMATION,
      'PATH' => ENV.fetch('PATH')
    }

    %w[DB_URL PGHOST PGSERVICE PGPASSWORD POSTGRES_BIN PSQL PHP_BINARY].each do |name|
      rehearsal = LocalPostgresInpatientBedClaimRehearsal.new(
        environment: base.merge(name => 'untrusted-override'),
        runner: NoCommandRunner.new
      )
      error = assert_raises(LocalPostgresInpatientBedClaimRehearsal::CommandFailed) { rehearsal.run! }
      assert_match(/refuses inherited database or executable overrides/, error.message)
      assert_includes error.message, name
    end
  end

  def test_runner_does_not_inherit_database_or_executable_overrides
    originals = %w[DB_URL PGHOST PGSERVICE PGPASSWORD POSTGRES_BIN PSQL].to_h { |name| [name, ENV[name]] }
    originals.each_key { |name| ENV[name] = 'must-not-cross-runner-boundary' }

    output = LocalPostgresInpatientBedClaimRehearsal::Runner.new.run!([
      'ruby', '-e',
      "print %w[DB_URL PGHOST PGSERVICE PGPASSWORD POSTGRES_BIN PSQL].select { |key| ENV.key?(key) }.join(',')"
    ])

    assert_equal '', output
  ensure
    originals&.each { |name, value| value.nil? ? ENV.delete(name) : ENV[name] = value }
  end

  def test_harness_owns_a_mode_0700_unix_socket_cluster_with_tcp_and_host_auth_disabled
    source = File.read(SCRIPT)

    assert_equal '17.10', LocalPostgresInpatientBedClaimRehearsal::POSTGRES_VERSION
    assert_includes source, "'--auth-local=trust', '--auth-host=reject'"
    assert_includes source, "'-c', 'listen_addresses='"
    assert_includes source, "'-c', 'unix_socket_permissions=0700'"
    assert_includes source, 'permission_bits(@temporary_root) == 0o700'
    assert_includes source, 'permission_bits(@data_directory) == 0o700'
    assert_includes source, 'permission_bits(@socket_directory) == 0o700'
    assert_includes source, "bool_and(auth_method = 'reject')"
    assert_includes source, "'--host', '127.0.0.1'"
    assert_includes source, '@runner.expect_failure!'
    refute_match(/OptionParser|--host=|--database=|POSTGRES_BIN.*fetch/, source)
  end

  def test_application_workers_are_forced_into_the_private_synthetic_no_egress_boundary
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, "'APP_MODE' => 'SIMULATION'"
    assert_includes harness, "'APP_SYNTHETIC_ONLY' => 'true'"
    assert_includes harness, "'DB_SCHEMA' => 'laravel'"
    assert_includes harness, "'DB_URL' => ''"
    assert_includes harness, "'MAIL_MAILER' => 'array'"
    assert_includes harness, "'QUEUE_CONNECTION' => 'sync'"
    assert_includes harness, "'BPJS_INTEGRATION_ENABLED' => 'false'"
    assert_includes harness, "'VCLAIM_ENABLED' => 'false'"
    assert_includes harness, "'SATUSEHAT_ENABLED' => 'false'"
    assert_includes worker, "config('simulation.mode') !== 'SIMULATION'"
    assert_includes worker, "SchemaQualifier::primarySchema() !== 'laravel'"
    assert_includes worker, "config('mail.default') !== 'array'"
    assert_includes worker, "where('is_synthetic', false)->exists()"
  end

  def test_same_bed_phase_requires_one_commit_one_reject_after_an_observed_real_lock_wait
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, "phase: 'same', worker: 'A'"
    assert_includes harness, "phase: 'same', worker: 'B'"
    assert_includes harness, "wait_event_type = 'Lock'"
    assert_includes harness, 'blocking_pids(second_pid).include?(first_pid)'
    assert_includes harness, "outcome: 'COMMITTED'"
    assert_includes harness, "outcome: 'REJECTED_OCCUPIED'"
    refute_includes harness, "rejected.fetch('elapsed_ms')"
    assert_includes worker, '$bedClaimGuard->assertAvailable($bedCode)'
    assert_includes worker, 'catch (InpatientBedUnavailable)'
  end

  def test_distinct_bed_phase_requires_b_to_commit_while_a_still_holds_without_a_mutex_blocker
    source = File.read(SCRIPT)

    assert_includes source, "bed: 'SYNTH-BC-DISTINCT-A', hold_ms: 3500"
    assert_includes source, "bed: 'SYNTH-BC-DISTINCT-B', hold_ms: 0"
    assert_includes source, "activity_count('distinct', 'B', \"wait_event_type = 'Lock'\")"
    assert_includes source, "activity_count('distinct', 'A', \"wait_event = 'PgSleep'\")"
    assert_includes source, "'second_committed_while_first_still_holding' => first_still_holding"
    assert_includes source, "'bed_mutex_blocker_observed' => false"
  end

  def test_full_registration_phase_separately_accepts_only_daily_counter_serialization
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, "phase: 'queue'"
    assert_includes harness, "query LIKE '%daily_queue_counters%'"
    assert_includes harness, "'accepted_daily_counter_serialization' => true"
    assert_includes harness, 'queue_numbers == [1, 2]'
    assert_includes worker, '$dailyQueueAllocator->allocate($registeredAt)'
    assert_includes worker, '$auditRecorder->record('
    assert_includes worker, "DB::scalar('SELECT pg_backend_pid()')"
    assert_includes File.read(GUARD), 'assertAvailable'
    assert_includes File.read(ALLOCATOR), 'lockForUpdate()'
  end

  def test_cleanup_is_closed_exact_and_precedes_aggregate_evidence
    source = File.read(SCRIPT)

    assert_includes source, 'DATABASE_PATTERN.match?(@created_database)'
    assert_includes source, "'--if-exists', '--force', '--maintenance-db=postgres', @created_database"
    assert_includes source, 'assert_database_absent!(@created_database)'
    assert_includes source, 'FileUtils.remove_entry_secure(@temporary_root)'
    assert_includes source, 'cleanup!(strict: true)'
    assert_operator source.index('cleanup!(strict: true)'), :<, source.index('evidence_path = write_evidence!')
    refute_includes source, 'migrate:fresh'
    refute_includes source, 'simulation:reset'
  end

  def test_evidence_is_mode_0600_aggregate_only_and_contains_exact_source_hashes
    source = File.read(SCRIPT)
    evidence_method = source[/def write_evidence!.*?^  end/m]

    refute_nil evidence_method
    assert_includes source, "'files' => hashes"
    assert_includes source, 'Digest::SHA256.file(path).hexdigest'
    assert_includes evidence_method, "perm: 0o600"
    assert_includes evidence_method, 'File.chmod(0o600, path)'
    assert_includes evidence_method, "'hosted_concurrency_claim' => false"
    assert_includes evidence_method, "'real_patient_data_rows' => 0"
    refute_includes evidence_method, '@database'
    refute_includes evidence_method, '@run_token'
    refute_includes evidence_method, 'backend_pid'
    refute_includes evidence_method, 'bed_code'
    refute_includes evidence_method, 'DEMO_ACCOUNT_PASSWORD'
    refute_includes evidence_method, 'DB_PASSWORD'
  end
end
