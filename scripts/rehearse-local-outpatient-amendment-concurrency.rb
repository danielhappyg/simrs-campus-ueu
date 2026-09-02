#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'time'

require_relative 'rehearse-local-portability-full-suite'

class LocalOutpatientAmendmentConcurrencyRehearsal < LocalPortabilityFullSuiteRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_OUTPATIENT_AMENDMENT_CONCURRENCY'
  CONFIRMATION_ENV = 'SIMRS_OUTPATIENT_AMENDMENT_RACE_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-outpatient-amendment-concurrency.rb'
  FOUNDATION_HARNESS_PATH = 'scripts/rehearse-local-portability-full-suite.rb'
  WORKER_PATH = 'app/Console/Commands/RehearseOutpatientAmendmentRaceWorkerCommand.php'
  CONTRACT_TEST = 'tests/Documentation/LocalOutpatientAmendmentConcurrencyHarnessContractTest.rb'
  EVIDENCE_KIND = 'SIMRS_LOCAL_OUTPATIENT_AMENDMENT_CONCURRENCY'
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 10
  SCENARIOS = [
    'same-key-same-digest',
    'same-key-different-digest-across-encounters',
    'competing-decisions',
    'competing-addendum-writes',
    'competing-finalization'
  ].freeze
  FORBIDDEN_ENVIRONMENT = %w[
    DB_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET DB_SCHEMA
    PGHOST PGPORT PGUSER PGPASSWORD PGSERVICE PGSERVICEFILE
    MYSQL_HOST MYSQL_TCP_PORT MYSQL_UNIX_PORT MYSQL_PWD
    POSTGRES17_BIN MYSQL84_BIN PHP_BINARY GIT_BINARY
  ].freeze

  Worker = Struct.new(
    :stdin, :stdout, :stderr, :wait_thread, :stderr_reader, :lines,
    keyword_init: true
  )

  def initialize(engine:, environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @operator_environment = environment.dup
    super(
      engine: engine,
      environment: environment.merge(
        'SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => LocalPortabilityFullSuiteRehearsal::CONFIRMATION
      ),
      runner: runner,
      monotonic_clock: monotonic_clock
    )
    @workers = []
  end

  def run!
    assert_contract!
    execution_bindings = current_amendment_execution_bindings
    engine_binding = prepare_engine!
    @run_token = database_run_token

    migration_started = @clock.call
    run_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(migration_started)
    run_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    assert_no_non_synthetic_patients!

    started = @clock.call
    scenarios = SCENARIOS.to_h { |scenario| [scenario, run_scenario!(scenario)] }
    duration_ms = elapsed_ms(started)

    assert_no_non_synthetic_patients!
    assert_unchanged_binding!(
      'Outpatient amendment concurrency execution bindings',
      execution_bindings,
      current_amendment_execution_bindings
    )

    cleanup!(strict: true)
    evidence_path = write_amendment_evidence!(
      execution_bindings: execution_bindings,
      engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms,
      duration_ms: duration_ms,
      scenarios: scenarios
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_OUTPATIENT_AMENDMENT_CONCURRENCY_ONLY',
      'engine' => @engine,
      'scenario_count' => scenarios.length,
      'evidence_path' => evidence_path
    }
  ensure
    terminate_workers!
    cleanup!
  end

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize disposable local engine creation."
    end

    rejected = FORBIDDEN_ENVIRONMENT.select do |name|
      !@operator_environment.fetch(name, '').to_s.strip.empty?
    end
    unless rejected.empty?
      raise CommandFailed, "Outpatient amendment concurrency rehearsal refuses inherited database or executable overrides: #{rejected.join(', ')}."
    end

    super
    [SCRIPT_PATH, FOUNDATION_HARNESS_PATH, WORKER_PATH, CONTRACT_TEST].each { |path| safe_source_path(path) }
    unless SCENARIOS.length == 5 && SCENARIOS == SCENARIOS.uniq
      raise CommandFailed, 'Outpatient amendment concurrency scenario catalogue must contain exactly five unique scenarios.'
    end
  end

  def application_environment
    super.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false'
    )
  end

  private

  def run_scenario!(scenario)
    prepare = run_worker_command!(action: 'prepare', scenario: scenario, worker: 'A', hold_ms: 0)
    require_protocol!(prepare, 'PREPARED', scenario)

    first = start_worker!(scenario: scenario, worker: 'A', hold_ms: HOLD_MS)
    require_protocol!(await_protocol!(first, 'STARTED'), 'STARTED', scenario, 'A')
    require_protocol!(await_protocol!(first, 'HOLDING'), 'HOLDING', scenario, 'A')

    second = start_worker!(scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    require_protocol!(second_started, 'STARTED', scenario, 'B')
    wait_observed = observe_real_database_wait!(second_started.fetch('backend_connection_id'))

    first_final = await_final!(first)
    second_final = await_final!(second)
    assert_scenario_outcomes!(scenario, first_final, second_final)

    verified = run_worker_command!(action: 'verify', scenario: scenario, worker: 'A', hold_ms: 0)
    require_protocol!(verified, 'VERIFIED', scenario)
    unless verified['durable_third_connection_assertions'] == true
      raise CommandFailed, 'Outpatient amendment concurrency verification omitted third-connection durable assertions.'
    end

    {
      'status' => 'PASS',
      'independent_processes' => 2,
      'outer_transaction_holding_protocol' => true,
      'engine_native_hold' => true,
      'real_database_wait_observed' => wait_observed,
      'durable_third_connection_assertions' => true,
      'outcomes' => [first_final.fetch('outcome'), second_final.fetch('outcome')].sort
    }
  ensure
    terminate_workers!
  end

  def start_worker!(scenario:, worker:, hold_ms:)
    argv = worker_argv(action: 'operate', scenario: scenario, worker: worker, hold_ms: hold_ms)
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(application_environment),
      *argv,
      unsetenv_others: true
    )
    stdin.close
    process = Worker.new(
      stdin: stdin,
      stdout: stdout,
      stderr: stderr,
      wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read },
      lines: []
    )
    @workers << process
    process
  end

  def await_protocol!(worker, state)
    deadline = @clock.call + WAIT_TIMEOUT_SECONDS
    loop do
      existing = worker.lines.find { |line| line['protocol_state'] == state }
      return existing if existing

      remaining = deadline - @clock.call
      raise CommandFailed, "Worker did not reach #{state} before timeout." if remaining <= 0

      ready = IO.select([worker.stdout], nil, nil, [remaining, 0.25].min)
      next unless ready

      line = worker.stdout.gets
      if line.nil?
        raise_worker_failure!(worker, "Worker exited before reaching #{state}.")
      end
      document = parse_worker_line(line)
      if document
        worker.lines << document
        if document['status'] == 'BLOCKED'
          error_class = document.fetch('exception_class', 'unknown')
          fingerprint = document.fetch('exception_fingerprint', 'unknown')
          raise CommandFailed, "Worker reported a blocked result (#{error_class}, #{fingerprint})."
        end
      end
    end
  end

  def await_final!(worker)
    final = await_protocol!(worker, 'COMMITTED')
    status = worker.wait_thread.value
    stderr = worker.stderr_reader.value
    unless status.success?
      reason = @runner.sanitize(stderr)
      suffix = reason.empty? ? '' : ": #{reason}"
      raise CommandFailed, "Outpatient amendment worker failed#{suffix}"
    end
    close_worker!(worker)
    final
  end

  def observe_real_database_wait!(backend_connection_id)
    id = Integer(backend_connection_id.to_s, 10)
    raise CommandFailed, 'Worker returned an invalid backend connection ID.' unless id.positive?

    deadline = @clock.call + 3.0
    loop do
      observed = case @engine
                 when 'postgresql17' then postgres_wait_observed?(id)
                 when 'mysql8411' then mysql_wait_observed?(id)
                 else false
                 end
      return true if observed
      raise CommandFailed, 'No real database lock wait was observed for the competing worker.' if @clock.call >= deadline
      sleep 0.05
    end
  rescue ArgumentError, TypeError
    raise CommandFailed, 'Worker returned an invalid backend connection ID.'
  end

  def postgres_wait_observed?(backend_connection_id)
    output = @runner.run!(
      postgres_psql_arguments(@postgres_database) + [
        '--tuples-only', '--no-align', '--command',
        <<~SQL
          SELECT CASE WHEN wait_event_type = 'Lock'
            AND cardinality(pg_blocking_pids(pid)) > 0 THEN '1' ELSE '0' END
          FROM pg_stat_activity
          WHERE pid = #{backend_connection_id}
        SQL
      ],
      env: postgres_tool_environment
    ).strip
    output == '1'
  end

  def mysql_wait_observed?(backend_connection_id)
    output = @runner.run!(
      mysql_root_arguments + ['--batch', '--skip-column-names'],
      stdin_data: <<~SQL
        SELECT COUNT(*)
        FROM performance_schema.data_lock_waits AS waits
        INNER JOIN performance_schema.threads AS threads
          ON threads.thread_id = waits.requesting_thread_id
        WHERE threads.processlist_id = #{backend_connection_id};
      SQL
    ).strip
    Integer(output, 10).positive?
  rescue ArgumentError
    false
  end

  def run_worker_command!(action:, scenario:, worker:, hold_ms:)
    output = @runner.run!(
      worker_argv(action: action, scenario: scenario, worker: worker, hold_ms: hold_ms),
      env: application_environment
    )
    document = nil
    output.lines.reverse_each do |line|
      parsed = parse_worker_line(line)
      if parsed
        document = parsed
        break
      end
    end
    unless document.is_a?(Hash) && document['status'] == 'PASS'
      raise CommandFailed, "Outpatient amendment #{action} worker returned no passing protocol result."
    end
    document
  end

  def worker_argv(action:, scenario:, worker:, hold_ms:)
    [
      @php_binary,
      File.join(ROOT, 'artisan'),
      'ops:rehearse-outpatient-amendment-race-worker',
      "--run-token=#{@run_token}",
      "--action=#{action}",
      "--scenario=#{scenario}",
      "--worker=#{worker}",
      "--hold-ms=#{hold_ms}",
      '--confirm-local-synthetic',
      '--no-interaction'
    ]
  end

  def parse_worker_line(line)
    document = JSON.parse(line.strip)
    return nil unless document.is_a?(Hash) && document['schema_version'] == 1
    document
  rescue JSON::ParserError
    nil
  end

  def require_protocol!(document, state, scenario, worker = nil)
    valid = document['status'] == 'PASS' &&
      document['protocol_state'] == state &&
      document['scenario'] == scenario
    valid &&= document['worker'] == worker if worker
    raise CommandFailed, "Outpatient amendment worker protocol mismatch at #{state}." unless valid
  end

  def assert_scenario_outcomes!(scenario, first, second)
    require_protocol!(first, 'COMMITTED', scenario, 'A')
    require_protocol!(second, 'COMMITTED', scenario, 'B')
    expected = case scenario
               when 'same-key-same-digest' then %w[APPLIED REPLAYED]
               else %w[APPLIED DENIED]
               end
    actual = [first.fetch('outcome'), second.fetch('outcome')].sort
    unless actual == expected.sort
      raise CommandFailed, "Outpatient amendment race #{scenario} returned unexpected outcomes."
    end

    expected_reason = {
      'same-key-different-digest-across-encounters' => 'idempotency_key_conflict',
      'competing-decisions' => 'request_not_submitted',
      'competing-addendum-writes' => 'stale_version',
      'competing-finalization' => 'request_already_consumed'
    }[scenario]
    if expected_reason
      denied = [first, second].find { |result| result['outcome'] == 'DENIED' }
      unless denied && denied['reason'] == expected_reason
        raise CommandFailed, "Outpatient amendment race #{scenario} returned an unexpected denial reason."
      end
    end
  end

  def raise_worker_failure!(worker, message)
    status = worker.wait_thread.value
    stderr = worker.stderr_reader.value
    reason = @runner.sanitize(stderr)
    suffix = reason.empty? ? '' : ": #{reason}"
    raise CommandFailed, "#{message} exit=#{status.exitstatus}#{suffix}"
  end

  def close_worker!(worker)
    worker.stdout.close unless worker.stdout.closed?
    worker.stderr.close unless worker.stderr.closed?
    @workers.delete(worker)
  end

  def terminate_workers!
    @workers.each do |worker|
      begin
        if worker.wait_thread.alive?
          Process.kill('TERM', worker.wait_thread.pid)
          worker.wait_thread.join(2)
          Process.kill('KILL', worker.wait_thread.pid) if worker.wait_thread.alive?
        end
      rescue Errno::ESRCH, Errno::ECHILD
        nil
      ensure
        worker.stdout.close unless worker.stdout.closed?
        worker.stderr.close unless worker.stderr.closed?
        worker.stderr_reader.join(0.5)
      end
    end
    @workers.clear
  end

  def database_run_token
    database = @engine == 'postgresql17' ? @postgres_database : @mysql_database
    token = database.to_s[/[0-9a-f]{12}\z/]
    raise CommandFailed, 'Disposable database did not expose the closed run-token binding.' unless token
    token
  end

  def current_amendment_execution_bindings
    source_paths = [
      SCRIPT_PATH,
      FOUNDATION_HARNESS_PATH,
      WORKER_PATH,
      'app/Support/Clinical/OutpatientPostClosureAmendmentService.php',
      'app/Models/OutpatientPostClosureAmendmentRequest.php',
      'app/Models/OutpatientClinicalDocumentAddendum.php',
      'app/Models/OutpatientClinicalDocumentAddendumVersion.php',
      'app/Models/OutpatientAmendmentOperationReceipt.php',
      'database/migrations/2026_08_30_000200_create_outpatient_post_closure_amendment_tables.php'
    ]
    hashes = source_paths.to_h do |path|
      source = safe_source_path(path)
      [path, Digest::SHA256.file(source).hexdigest]
    end
    {
      'files' => hashes,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(hashes.sort.to_h)),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS))
    }
  end

  def write_amendment_evidence!(execution_bindings:, engine_binding:, migration_duration_ms:, duration_ms:, scenarios:)
    assert_evidence_directory!
    timestamp = Time.now.utc.strftime('%Y%m%dT%H%M%SZ')
    path = File.join(
      EVIDENCE_DIRECTORY,
      "#{timestamp}-#{@engine}-outpatient-amendment-concurrency-#{SecureRandom.hex(6)}.json"
    )
    head = @runner.run!([@git_binary, '-C', ROOT, 'rev-parse', 'HEAD']).strip
    dirty = !@runner.run!([@git_binary, '-C', ROOT, 'status', '--porcelain']).strip.empty?
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_OUTPATIENT_AMENDMENT_CONCURRENCY_ONLY',
      'hosted_concurrency_claim' => false,
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'baseline_git_sha' => head,
      'working_tree_state' => dirty ? 'UNCOMMITTED_LOCAL_MILESTONE' : 'CLEAN',
      'source_bindings' => execution_bindings,
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'real_patient_data_rows' => 0,
        'live_integrations_enabled' => false,
        'break_glass_mode' => 'off',
        'disposable_local_engine' => true,
        'independent_worker_processes' => true
      },
      'engine' => engine_binding,
      'migration' => {
        'fresh_apply' => 'PASS',
        'duration_ms_observed' => migration_duration_ms
      },
      'scenario_duration_ms_observed' => duration_ms,
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true,
        'temporary_server_removed' => true,
        'temporary_user_state_removed' => true
      },
      'open_boundaries' => [
        'This evidence is limited to disposable local PostgreSQL 17.10 or MySQL 8.4.11 concurrency behavior.',
        'It is not hosted migration, deployment, production, capacity, SLA, clinical acceptance or owner acceptance evidence.',
        'No real patient data or live BPJS, VClaim or SATUSEHAT integration was used.'
      ]
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalOutpatientAmendmentConcurrencyRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end

    result = LocalOutpatientAmendmentConcurrencyRehearsal.new(engine: ARGV.first).run!
    puts JSON.generate(result)
  rescue LocalOutpatientAmendmentConcurrencyRehearsal::CommandFailed => e
    warn "outpatient amendment concurrency rehearsal failed: #{e.message}"
    exit 1
  end
end
