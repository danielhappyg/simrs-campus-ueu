#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'time'

require_relative 'rehearse-local-portability-full-suite'

class LocalInpatientDocumentationPortabilityRehearsal < LocalPortabilityFullSuiteRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_DOCUMENTATION_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_INPATIENT_DOCUMENTATION_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-inpatient-documentation-portability.rb'
  FOUNDATION_PATH = 'scripts/rehearse-local-portability-full-suite.rb'
  WORKER_PATH = 'app/Console/Commands/RehearseInpatientDocumentationRaceWorkerCommand.php'
  CONTRACT_PATH = 'tests/Documentation/LocalInpatientDocumentationPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_08_30_000400_create_inpatient_longitudinal_documentation_tables.php'
  FEATURE_PATH = 'tests/Feature/Inpatient/StructuredInpatientLongitudinalDocumentationTest.php'
  EVIDENCE_KIND = 'SIMRS_LOCAL_INPATIENT_DOCUMENTATION_PORTABILITY'
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 10

  RACE_SCENARIOS = [
    'same-author-service-day-concurrent-create',
    'same-expected-version-update',
    'identical-idempotency-replay',
    'conflicting-idempotency-replay'
  ].freeze
  STATE_SCENARIOS = [
    'multi-author-same-day-independent-heads',
    'immutable-placement-snapshot-after-managed-rename',
    'audit-failure-atomic-rollback',
    'reset-retains-audit-evidence'
  ].freeze
  MIGRATION_SCENARIOS = [
    'empty-down-reapply',
    'populated-and-correlated-audit-down-refusal'
  ].freeze
  SCENARIOS = (RACE_SCENARIOS + STATE_SCENARIOS + MIGRATION_SCENARIOS).freeze
  FORBIDDEN_ENVIRONMENT = %w[
    DB_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET DB_SCHEMA
    PGHOST PGPORT PGUSER PGPASSWORD PGSERVICE PGSERVICEFILE MYSQL_HOST MYSQL_TCP_PORT MYSQL_PWD
    POSTGRES17_BIN MYSQL84_BIN PHP_BINARY GIT_BINARY
  ].freeze

  Worker = Struct.new(:stdin, :stdout, :stderr, :wait_thread, :stderr_reader, :lines, keyword_init: true)

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
    @command_catalog = []
    @result_catalog = []
    @protocol_catalog = []
  end

  def run!
    assert_contract!
    bindings = current_documentation_bindings
    engine_binding = prepare_engine!
    @run_token = database_run_token
    migration_started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(migration_started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    assert_no_non_synthetic_patients!

    scenarios = {}
    scenarios['empty-down-reapply'] = run_empty_down_reapply!
    scenarios['populated-and-correlated-audit-down-refusal'] = run_populated_refusal!
    RACE_SCENARIOS.each { |scenario| scenarios[scenario] = run_race!(scenario) }
    STATE_SCENARIOS.first(3).each { |scenario| scenarios[scenario] = run_state_proof!(scenario) }
    scenarios['reset-retains-audit-evidence'] = run_state_proof!('reset-retains-audit-evidence')
    scenarios['populated-and-correlated-audit-down-refusal']['correlated_audit_after_reset'] =
      expect_rollback_refusal!('correlated audit evidence remains')

    assert_no_non_synthetic_patients!
    assert_unchanged_binding!('Inpatient documentation execution bindings', bindings, current_documentation_bindings)
    cleanup!(strict: true)
    evidence_path = write_documentation_evidence!(
      bindings: bindings,
      engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms,
      scenarios: scenarios
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_DOCUMENTATION_PORTABILITY_ONLY',
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
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    unless rejected.empty?
      raise CommandFailed, "Documentation portability rehearsal refuses inherited overrides: #{rejected.join(', ')}."
    end
    super
    [SCRIPT_PATH, FOUNDATION_PATH, WORKER_PATH, CONTRACT_PATH, MIGRATION_PATH, FEATURE_PATH]
      .each { |path| safe_source_path(path) }
    unless SCENARIOS.length == 10 && SCENARIOS.uniq.length == 10
      raise CommandFailed, 'Documentation portability catalogue must contain exactly ten unique scenarios.'
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

  def run_empty_down_reapply!
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    {
      'status' => 'PASS',
      'proof_kind' => 'SEQUENTIAL_DURABLE_STATE_PROOF',
      'empty_down' => 'PASS',
      'reapply' => 'PASS'
    }
  end

  def run_race!(scenario)
    prepare = run_worker_command!(action: 'prepare', scenario: scenario, worker: 'A', hold_ms: 0)
    require_protocol!(prepare, 'PREPARED', scenario)
    @protocol_catalog << safe_protocol_result(prepare)
    first = start_worker!(scenario: scenario, worker: 'A', hold_ms: HOLD_MS)
    require_protocol!(await_protocol!(first, 'STARTED'), 'STARTED', scenario, 'A')
    require_protocol!(await_protocol!(first, 'HOLDING'), 'HOLDING', scenario, 'A')
    second = start_worker!(scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    require_protocol!(second_started, 'STARTED', scenario, 'B')
    wait_observed = observe_real_database_wait!(second_started.fetch('backend_connection_id'))
    first_final = await_final!(first)
    second_final = await_final!(second)
    assert_race_outcomes!(scenario, first_final, second_final)
    verified = run_worker_command!(action: 'verify', scenario: scenario, worker: 'A', hold_ms: 0)
    require_protocol!(verified, 'VERIFIED', scenario)
    raise CommandFailed, 'Third-connection assertions are absent.' unless verified['durable_third_connection_assertions'] == true

    result = {
      'status' => 'PASS',
      'proof_kind' => 'OBSERVED_DATABASE_RACE',
      'independent_application_processes' => 2,
      'outer_transaction_holding_protocol' => true,
      'real_database_wait_observed' => wait_observed,
      'durable_third_connection_assertions' => true,
      'outcomes' => [first_final.fetch('outcome'), second_final.fetch('outcome')].sort,
      'protocol_order' => %w[
        A_STARTED A_HOLDING B_STARTED NATIVE_WAIT_OBSERVED A_COMMITTED B_COMMITTED
        THIRD_CONNECTION_VERIFIED
      ],
      'workers' => [safe_protocol_result(first_final), safe_protocol_result(second_final)],
      'durable_assertions' => verified.fetch('assertion_catalog')
    }
    @protocol_catalog.concat([safe_protocol_result(first_final), safe_protocol_result(second_final), safe_protocol_result(verified)])
    @result_catalog << [scenario, result]
    result
  ensure
    terminate_workers!
  end

  def run_state_proof!(scenario)
    result = run_worker_command!(action: 'prove', scenario: scenario, worker: 'A', hold_ms: 0)
    require_protocol!(result, 'PROVED', scenario)
    proof = {
      'status' => 'PASS',
      'proof_kind' => 'SEQUENTIAL_DURABLE_STATE_PROOF',
      'durable_third_connection_assertions' => result['durable_third_connection_assertions'] == true,
      'durable_assertions' => result.fetch('assertion_catalog')
    }
    @protocol_catalog << safe_protocol_result(result)
    @result_catalog << [scenario, proof]
    proof
  end

  def run_populated_refusal!
    fixture = run_worker_command!(action: 'prove', scenario: 'populated-down-refusal-fixture', worker: 'A', hold_ms: 0)
    require_protocol!(fixture, 'PROVED', 'populated-down-refusal-fixture')
    populated = expect_rollback_refusal!('retained document evidence exists')
    cleanup = run_worker_command!(action: 'prove', scenario: 'populated-down-refusal-cleanup', worker: 'A', hold_ms: 0)
    require_protocol!(cleanup, 'PROVED', 'populated-down-refusal-cleanup')
    {
      'status' => 'PASS',
      'proof_kind' => 'SEQUENTIAL_DURABLE_STATE_PROOF',
      'populated_structures' => populated
    }
  end

  def expect_rollback_refusal!(expected)
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(
      @runner.process_environment(application_environment),
      @php_binary, File.join(ROOT, 'artisan'), *arguments,
      unsetenv_others: true
    )
    raise CommandFailed, 'Populated documentation migration unexpectedly rolled back.' if status.success?
    combined = stdout+stderr
    unless combined.gsub(/\s+/, '').include?(expected.gsub(/\s+/, ''))
      diagnostic = combined.lines.first(20).join
      raise CommandFailed, "Rollback failed for an unexpected reason: #{@runner.sanitize(diagnostic)}"
    end
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def recorded_artisan!(*arguments)
    @command_catalog << ['artisan', *arguments]
    run_artisan!(*arguments)
    @result_catalog << [['artisan', *arguments], 'PASS']
  end

  def start_worker!(scenario:, worker:, hold_ms:)
    argv = worker_argv(action: 'operate', scenario: scenario, worker: worker, hold_ms: hold_ms)
    @command_catalog << logical_worker_command('operate', scenario, worker, hold_ms)
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(application_environment), *argv, unsetenv_others: true
    )
    stdin.close
    process = Worker.new(
      stdin: stdin, stdout: stdout, stderr: stderr, wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read }, lines: []
    )
    @workers << process
    process
  end

  def run_worker_command!(action:, scenario:, worker:, hold_ms:)
    @command_catalog << logical_worker_command(action, scenario, worker, hold_ms)
    output, stderr, status = Open3.capture3(
      @runner.process_environment(application_environment),
      *worker_argv(action: action, scenario: scenario, worker: worker, hold_ms: hold_ms),
      unsetenv_others: true
    )
    unless status.success?
      diagnostic = stderr.lines.reverse.find { |line| line.include?('[idoc-rehearsal]') }
      raise CommandFailed, "Documentation #{action} worker failed: #{@runner.sanitize(diagnostic || stderr)}"
    end
    document = output.lines.reverse_each.lazy.map { |line| parse_worker_line(line) }.find(&:itself)
    unless document.is_a?(Hash) && document['status'] == 'PASS'
      raise CommandFailed, "Documentation #{action} worker returned no passing protocol result."
    end
    document
  end

  def worker_argv(action:, scenario:, worker:, hold_ms:)
    [
      @php_binary, File.join(ROOT, 'artisan'), 'ops:rehearse-inpatient-documentation-worker',
      "--run-token=#{@run_token}", "--action=#{action}", "--scenario=#{scenario}",
      "--worker=#{worker}", "--hold-ms=#{hold_ms}", '--confirm-local-synthetic', '--no-interaction'
    ]
  end

  def logical_worker_command(action, scenario, worker, hold_ms)
    [
      'php', 'artisan', 'ops:rehearse-inpatient-documentation-worker',
      '--run-token=<generated-redacted>', "--action=#{action}", "--scenario=#{scenario}",
      "--worker=#{worker}", "--hold-ms=#{hold_ms}", '--confirm-local-synthetic', '--no-interaction'
    ]
  end

  def safe_protocol_result(document)
    document.slice(
      'schema_version', 'status', 'protocol_state', 'scenario', 'worker',
      'outcome', 'reason', 'elapsed_ms', 'durable_third_connection_assertions',
      'assertion_catalog'
    ).merge('exit_status' => 'PASS')
  end

  def await_protocol!(worker, state)
    deadline = @clock.call + WAIT_TIMEOUT_SECONDS
    loop do
      existing = worker.lines.find { |line| line['protocol_state'] == state }
      return existing if existing
      remaining = deadline - @clock.call
      raise CommandFailed, "Worker did not reach #{state}." if remaining <= 0
      next unless IO.select([worker.stdout], nil, nil, [remaining, 0.25].min)
      line = worker.stdout.gets
      raise_worker_failure!(worker, "Worker exited before #{state}.") if line.nil?
      document = parse_worker_line(line)
      next unless document
      worker.lines << document
      if document['status'] == 'BLOCKED'
        raise CommandFailed, "Worker #{document['worker']} blocked in #{document['scenario']} (#{document['exception_class']}, #{document['exception_fingerprint']})."
      end
    end
  end

  def await_final!(worker)
    final = await_protocol!(worker, 'COMMITTED')
    status = worker.wait_thread.value
    stderr = worker.stderr_reader.value
    unless status.success?
      reason = @runner.sanitize(stderr)
      raise CommandFailed, "Documentation worker failed#{reason.empty? ? '' : ": #{reason}"}"
    end
    close_worker!(worker)
    final
  end

  def observe_real_database_wait!(connection_id)
    id = Integer(connection_id.to_s, 10)
    deadline = @clock.call + 3.0
    loop do
      observed = @engine == 'postgresql17' ? postgres_wait_observed?(id) : mysql_wait_observed?(id)
      return true if observed
      raise CommandFailed, 'No native database lock wait was observed.' if @clock.call >= deadline
      sleep 0.05
    end
  rescue ArgumentError, TypeError
    raise CommandFailed, 'Worker returned an invalid backend connection ID.'
  end

  def postgres_wait_observed?(id)
    output = @runner.run!(
      postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command',
        "SELECT CASE WHEN wait_event_type='Lock' AND cardinality(pg_blocking_pids(pid))>0 THEN '1' ELSE '0' END FROM pg_stat_activity WHERE pid=#{id}"],
      env: postgres_tool_environment
    ).strip
    output == '1'
  end

  def mysql_wait_observed?(id)
    output = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: <<~SQL).strip
      SELECT COUNT(*) FROM performance_schema.data_lock_waits AS waits
      INNER JOIN performance_schema.threads AS threads ON threads.thread_id = waits.requesting_thread_id
      WHERE threads.processlist_id = #{id};
    SQL
    Integer(output, 10).positive?
  rescue ArgumentError
    false
  end

  def assert_race_outcomes!(scenario, first, second)
    require_protocol!(first, 'COMMITTED', scenario, 'A')
    require_protocol!(second, 'COMMITTED', scenario, 'B')
    expected = scenario == 'identical-idempotency-replay' ? %w[APPLIED REPLAYED] : %w[APPLIED DENIED]
    actual = [first.fetch('outcome'), second.fetch('outcome')].sort
    raise CommandFailed, "Unexpected outcomes for #{scenario}." unless actual == expected.sort
    expected_reason = scenario == 'conflicting-idempotency-replay' ? 'idempotency_key_conflict' : 'stale_version'
    if expected.include?('DENIED')
      denied = [first, second].find { |item| item['outcome'] == 'DENIED' }
      raise CommandFailed, "Unexpected denial for #{scenario}." unless denied && denied['reason'] == expected_reason
    end
  end

  def parse_worker_line(line)
    document = JSON.parse(line.strip)
    document if document.is_a?(Hash) && document['schema_version'] == 1
  rescue JSON::ParserError
    nil
  end

  def require_protocol!(document, state, scenario, worker = nil)
    valid = document['status'] == 'PASS' && document['protocol_state'] == state && document['scenario'] == scenario
    valid &&= document['worker'] == worker if worker
    raise CommandFailed, "Documentation worker protocol mismatch at #{state}." unless valid
  end

  def raise_worker_failure!(worker, message)
    status = worker.wait_thread.value
    reason = @runner.sanitize(worker.stderr_reader.value)
    raise CommandFailed, "#{message} exit=#{status.exitstatus}#{reason.empty? ? '' : ": #{reason}"}"
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
    database.to_s[/[0-9a-f]{12}\z/] || raise(CommandFailed, 'Disposable database lacks a run-token binding.')
  end

  def current_documentation_bindings
    paths = [
      SCRIPT_PATH, FOUNDATION_PATH, WORKER_PATH, CONTRACT_PATH, MIGRATION_PATH, FEATURE_PATH,
      'app/Support/Inpatient/InpatientDocumentationService.php',
      'app/Support/Inpatient/InpatientDocumentationMutationResult.php',
      'app/Support/Inpatient/InpatientDocumentationActorPolicy.php',
      'app/Support/Inpatient/InpatientDocumentationMutationScope.php',
      'app/Support/Inpatient/InpatientDocumentationSqlWriteGuard.php',
      'app/Support/Inpatient/InpatientDocumentationSchemaMutationScope.php',
      'app/Support/Audit/AuditEventSchemaRegistry.php',
      'app/Support/Audit/AuditRecorder.php',
      'app/Providers/AppServiceProvider.php',
      'app/Support/Database/SchemaQualifier.php',
      'app/Models/InpatientClinicalDocument.php',
      'app/Models/InpatientClinicalDocumentVersion.php',
      'app/Models/InpatientDocumentOperationReceipt.php',
      'app/Support/Simulation/SyntheticResetService.php'
    ]
    files = paths.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_sha256' => files.fetch(WORKER_PATH),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS))
    }
  end

  def validate_evidence_payload!(payload)
    sanitize_evidence!(payload)
  end

  def write_documentation_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY,
                     "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-inpatient-documentation-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_DOCUMENTATION_PORTABILITY_ONLY',
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'source_bindings' => bindings.merge(
        'command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),
        'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))
      ),
      'command_catalog' => @command_catalog,
      'protocol_result_catalog' => @protocol_catalog,
      'boundary' => {
        'application_mode' => 'SIMULATION', 'synthetic_only' => true,
        'live_integrations_enabled' => false, 'disposable_local_engine' => true
      },
      'engine' => engine_binding,
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true, 'temporary_server_removed' => true,
        'temporary_user_state_removed' => true
      }
    }
    validate_evidence_payload!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalInpatientDocumentationPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalInpatientDocumentationPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalInpatientDocumentationPortabilityRehearsal::CommandFailed => e
    warn "inpatient documentation portability rehearsal failed: #{e.message}"
    exit 1
  end
end
