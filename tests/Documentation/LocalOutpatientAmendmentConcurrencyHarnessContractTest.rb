# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require_relative '../../scripts/rehearse-local-outpatient-amendment-concurrency'

class LocalOutpatientAmendmentConcurrencyHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/rehearse-local-outpatient-amendment-concurrency.rb')
  WORKER = File.join(ROOT, 'app/Console/Commands/RehearseOutpatientAmendmentRaceWorkerCommand.php')
  SERVICE = File.join(ROOT, 'app/Support/Clinical/OutpatientPostClosureAmendmentService.php')

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

  def test_explicit_closed_confirmation_is_required_before_any_command
    rehearsal = LocalOutpatientAmendmentConcurrencyRehearsal.new(
      engine: 'postgresql17',
      environment: {},
      runner: NoCommandRunner.new
    )

    error = assert_raises(LocalOutpatientAmendmentConcurrencyRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/SIMRS_OUTPATIENT_AMENDMENT_RACE_CONFIRM/, error.message)
  end

  def test_inherited_database_and_executable_overrides_are_rejected_before_any_command
    base = {
      LocalOutpatientAmendmentConcurrencyRehearsal::CONFIRMATION_ENV =>
        LocalOutpatientAmendmentConcurrencyRehearsal::CONFIRMATION
    }

    %w[DB_URL DB_HOST DB_PASSWORD PGHOST PGPASSWORD MYSQL_PWD POSTGRES17_BIN PHP_BINARY].each do |name|
      rehearsal = LocalOutpatientAmendmentConcurrencyRehearsal.new(
        engine: 'postgresql17',
        environment: base.merge(name => 'untrusted-override'),
        runner: NoCommandRunner.new
      )
      error = assert_raises(LocalOutpatientAmendmentConcurrencyRehearsal::CommandFailed) { rehearsal.run! }
      assert_match(/refuses inherited database or executable overrides/, error.message)
      assert_includes error.message, name
    end
  end

  def test_catalogue_contains_exactly_the_five_approved_races
    assert_equal [
      'same-key-same-digest',
      'same-key-different-digest-across-encounters',
      'competing-decisions',
      'competing-addendum-writes',
      'competing-finalization'
    ], LocalOutpatientAmendmentConcurrencyRehearsal::SCENARIOS

    worker = File.read(WORKER)
    LocalOutpatientAmendmentConcurrencyRehearsal::SCENARIOS.each do |scenario|
      assert_includes worker, "'#{scenario}'"
    end
  end

  def test_workers_are_independent_processes_with_outer_transaction_holding_protocol
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, 'Open3.popen3('
    assert_includes harness, "await_protocol!(first, 'HOLDING')"
    assert_includes harness, "await_protocol!(second, 'STARTED')"
    assert_includes worker, "'protocol_state' => 'STARTED'"
    assert_includes worker, "'protocol_state' => 'HOLDING'"
    assert_includes worker, "'protocol_state' => 'COMMITTED'"
    assert_includes worker, 'DB::transaction(function () use ('
    assert_includes worker, "DB::selectOne('SELECT pg_sleep(?)'"
    assert_includes worker, "DB::selectOne('SELECT SLEEP(?)'"
    refute_includes File.read(SERVICE), 'rehearsalHold'
    refute_includes File.read(SERVICE), 'pg_sleep'
  end

  def test_harness_observes_real_native_waits_on_both_exact_engines
    source = File.read(SCRIPT)

    assert_equal '17.10', LocalOutpatientAmendmentConcurrencyRehearsal::POSTGRES_VERSION
    assert_equal '8.4.11', LocalOutpatientAmendmentConcurrencyRehearsal::MYSQL_VERSION
    assert_includes source, "wait_event_type = 'Lock'"
    assert_includes source, 'pg_blocking_pids(pid)'
    assert_includes source, 'performance_schema.data_lock_waits'
    assert_includes source, 'requesting_thread_id'
    assert_includes source, 'threads.processlist_id'
    assert_includes source, "'real_database_wait_observed' => wait_observed"
  end

  def test_fresh_third_connection_performs_exact_durable_assertions
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, "action: 'verify'"
    assert_includes harness, "'durable_third_connection_assertions' => true"
    assert_includes worker, "'protocol_state' => 'VERIFIED'"
    assert_includes worker, 'OutpatientPostClosureAmendmentRequest::STATE_APPROVED'
    assert_includes worker, 'OutpatientPostClosureAmendmentRequest::STATE_CONSUMED'
    assert_includes worker, 'OutpatientClinicalDocumentAddendum::STATE_DRAFT'
    assert_includes worker, 'OutpatientClinicalDocumentAddendum::STATE_FINAL'
    assert_includes worker, "'idempotency_key_conflict'"
    assert_includes worker, "'request_not_submitted'"
    assert_includes worker, "'stale_version'"
    assert_includes worker, "'request_already_consumed'"
  end

  def test_application_boundary_is_simulation_only_non_egress_and_no_live_integrations
    harness = File.read(SCRIPT)
    foundation = File.read(File.join(ROOT, 'scripts/rehearse-local-portability-full-suite.rb'))
    worker = File.read(WORKER)

    assert_includes foundation, "'APP_MODE' => 'SIMULATION'"
    assert_includes foundation, "'APP_SYNTHETIC_ONLY' => 'true'"
    assert_includes foundation, "'MAIL_MAILER' => 'array'"
    assert_includes foundation, "'QUEUE_CONNECTION' => 'sync'"
    assert_includes harness, "'BPJS_INTEGRATION_ENABLED' => 'false'"
    assert_includes harness, "'VCLAIM_ENABLED' => 'false'"
    assert_includes harness, "'SATUSEHAT_ENABLED' => 'false'"
    assert_includes worker, "config('simulation.mode') !== 'SIMULATION'"
    assert_includes worker, "where('is_synthetic', false)->exists()"
    assert_includes worker, "config('break_glass.mode') !== 'off'"
    assert_includes worker, "getenv($key) !== 'false'"
  end

  def test_cleanup_is_strict_and_precedes_sanitized_mode_0600_evidence
    source = File.read(SCRIPT)
    evidence_method = source[/def write_amendment_evidence!.*?^  end/m]

    refute_nil evidence_method
    assert_includes source, 'cleanup!(strict: true)'
    assert_operator source.index('cleanup!(strict: true)'), :<, source.index('evidence_path = write_amendment_evidence!')
    assert_includes source, 'terminate_workers!'
    assert_includes evidence_method, 'sanitize_evidence!(evidence)'
    assert_includes evidence_method, "perm: 0o600"
    assert_includes evidence_method, 'File.chmod(0o600, path)'
    assert_includes evidence_method, "'hosted_concurrency_claim' => false"
    assert_includes evidence_method, "'real_patient_data_rows' => 0"
    refute_includes evidence_method, '@run_token'
    refute_includes evidence_method, '@postgres_database'
    refute_includes evidence_method, '@mysql_database'
    refute_includes evidence_method, 'backend_connection_id'
    refute_includes evidence_method, 'DB_PASSWORD'
  end

  def test_blocked_worker_diagnostics_are_fingerprint_only
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)
    blocked_method = worker[/private function emitBlockedResult.*?^    }/m]

    refute_nil blocked_method
    assert_includes blocked_method, "'exception_class' => $exception::class"
    assert_includes blocked_method, "'exception_fingerprint' => hash('sha256'"
    refute_includes blocked_method, 'getMessage()'
    refute_includes blocked_method, 'getTrace'
    assert_includes harness, "document['status'] == 'BLOCKED'"
    assert_includes harness, "document.fetch('exception_fingerprint', 'unknown')"
  end
end
