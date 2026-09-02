# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require 'tempfile'

require_relative '../../scripts/rehearse-local-inpatient-master-portability'

class LocalInpatientMasterPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientMasterPortabilityRehearsal
  SCRIPT = File.join(Harness::ROOT, Harness::SCRIPT_PATH)
  PROJECTION = File.join(Harness::ROOT, 'app/Support/Inpatient/InpatientOccupancyProjection.php')

  class NoCommandRunner
    def run!(*args, **kwargs)
      raise "Unexpected command before safety boundary: #{args.inspect} #{kwargs.inspect}"
    end

    def run_cleanup(*); end
  end

  def test_ruby_harness_and_embedded_php_worker_are_syntactically_valid
    _stdout, ruby_stderr, ruby_status = Open3.capture3(RbConfig.ruby, '-c', SCRIPT)
    assert ruby_status.success?, ruby_stderr

    Tempfile.create(['inpatient-master-worker', '.php']) do |file|
      file.write(Harness::PHP_WORKER)
      file.flush
      _stdout, php_stderr, php_status = Open3.capture3('php', '-l', file.path)
      assert php_status.success?, php_stderr
    end
  end

  def test_embedded_worker_reads_every_closed_protocol_binding_before_validation
    worker = Harness::PHP_WORKER

    %w[
      SIMRS_MASTER_SCENARIO
      SIMRS_MASTER_ACTION
      SIMRS_MASTER_WORKER
      SIMRS_MASTER_RUN_TOKEN
      SIMRS_MASTER_HOLD_MS
      SIMRS_MASTER_SIGNAL_PATH
    ].each { |name| assert_includes worker, "getenv('#{name}')" }
  end

  def test_explicit_closed_confirmation_is_required_before_any_command
    rehearsal = Harness.new(engine: 'postgresql17', environment: {}, runner: NoCommandRunner.new)
    error = assert_raises(Harness::CommandFailed) { rehearsal.run! }
    assert_match(/SIMRS_INPATIENT_MASTER_PORTABILITY_CONFIRM/, error.message)
  end

  def test_inherited_database_and_executable_overrides_are_rejected_before_any_command
    base = { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION }

    %w[DB_URL DB_HOST DB_PASSWORD PGHOST PGPASSWORD MYSQL_PWD POSTGRES17_BIN PHP_BINARY].each do |name|
      rehearsal = Harness.new(
        engine: 'postgresql17',
        environment: base.merge(name => 'untrusted-override'),
        runner: NoCommandRunner.new
      )
      error = assert_raises(Harness::CommandFailed) { rehearsal.run! }
      assert_match(/refuses inherited database or executable overrides/, error.message)
      assert_includes error.message, name
    end
  end

  def test_catalogue_contains_five_real_races_and_four_portability_scenarios
    assert_equal %w[
      duplicate-normalized-code
      same-expected-version-update
      identical-replay
      conflicting-replay
      admission-vs-retirement
    ], Harness::RACE_SCENARIOS
    assert_equal Harness::RACE_SCENARIOS + %w[
      database-constraints
      census-repeatable-read
      empty-down
      populated-evidence-down-refusal
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
  end

  def test_exact_opt_in_engines_and_isolated_foundation_are_reused
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal '17.10', Harness::POSTGRES_VERSION
    assert_equal '8.4.11', Harness::MYSQL_VERSION

    foundation = File.read(File.join(Harness::ROOT, Harness::FOUNDATION_HARNESS_PATH))
    assert_includes foundation, "Dir.mktmpdir('sp17-', '/private/tmp')"
    assert_includes foundation, "Dir.mktmpdir('sp84-', '/private/tmp')"
    assert_includes foundation, "'-h', ''"
    assert_includes foundation, "'--bind-address=127.0.0.1'"
  end

  def test_races_use_independent_processes_native_holds_wait_observation_and_third_connection_verify
    source = File.read(SCRIPT)
    worker = Harness::PHP_WORKER

    assert_includes source, 'Open3.popen3('
    assert_includes source, "await_protocol!(first, 'HOLDING')"
    assert_includes source, "await_protocol!(second, 'STARTED')"
    assert_includes worker, "'protocol_state' => 'STARTED'"
    assert_includes worker, "'protocol_state' => 'HOLDING'"
    assert_includes worker, "'protocol_state' => 'COMMITTED'"
    assert_includes worker, "DB::selectOne('SELECT pg_sleep(?)'"
    assert_includes worker, "DB::selectOne('SELECT SLEEP(?)'"
    assert_includes worker, "if ($worker === 'A') {"
    assert_includes worker, '} else {'
    assert_includes worker, '$outcome = $perform();'
    assert_includes source, "wait_event_type = 'Lock'"
    assert_includes source, 'pg_blocking_pids(pid)'
    assert_includes source, 'performance_schema.data_lock_waits'
    assert_includes source, "action: 'verify'"
    assert_includes source, "'durable_third_connection_assertions' => true"
  end

  def test_worker_exercises_every_required_master_race_through_real_services
    worker = Harness::PHP_WORKER

    assert_includes worker, 'strtolower($wardCode)'
    assert_includes worker, '$service->updateWard('
    assert_includes worker, "'identical-replay'"
    assert_includes worker, "'conflicting-replay'"
    assert_includes worker, '$service->resolveActiveBedForAdmission('
    assert_includes worker, 'app(InpatientBedClaimGuard::class)->assertAvailable('
    assert_includes worker, '$service->retireBed('
    assert_includes worker, "'duplicate_code'"
    assert_includes worker, "'stale_version'"
    assert_includes worker, "'idempotency_key_conflict'"
    assert_includes worker, "'bed_occupied'"
  end

  def test_replay_and_update_durable_assertions_are_target_scoped_to_version_receipt_and_audit
    source = File.read(SCRIPT)
    worker = Harness::PHP_WORKER

    assert_includes worker, "->where('actor_user_id', $admin->id)->where('operation', $operation)->where('idempotency_key', $key)->count()"
    assert_includes worker, "->where('action', $action)->where('resource_id', $resourceId)->count()"
    assert_includes worker, "$targetVersionCount === 1"
    assert_includes worker, "$targetAuditCount === 1"
    assert_includes worker, "!InpatientWard::query()->where('code', $wardCode.'-B')->exists()"
    assert_includes worker, "$targetVersionCount === 2"
    assert_includes worker, "'duplicate-normalized-code' => 'duplicate-a'"
    assert_includes worker, "$receiptCount('WARD_UPDATE', 'update-a') === 1"
    assert_includes source, "'target_assertions' => verified.fetch('target_assertions')"
  end

  def test_admission_retirement_exercises_and_records_both_legal_commit_orderings
    source = File.read(SCRIPT)
    worker = Harness::PHP_WORKER

    assert_includes worker, "if ($action === 'verify-admission-wins')"
    assert_includes worker, "if ($action === 'retirement-first')"
    assert_includes worker, "if ($action === 'admission-after-retirement')"
    assert_includes worker, "in_array($denial->reasonCode, ['master_retired', 'bed_retired'], true)"
    admission_index = source.index("action: 'verify-admission-wins'")
    retirement_index = source.index("action: 'retirement-first'")
    denied_index = source.index("action: 'admission-after-retirement'")
    final_verify_index = source.index("verified = run_worker_command!(action: 'verify'", denied_index)
    refute_nil admission_index
    refute_nil retirement_index
    refute_nil denied_index
    refute_nil final_verify_index
    assert_operator admission_index, :<, retirement_index
    assert_operator retirement_index, :<, denied_index
    assert_operator denied_index, :<, final_verify_index
    assert_includes source, "'admission_wins' => {"
    assert_includes source, "'proof_kind' => 'observed_independent_process_lock_race'"
    assert_includes source, "'retirement_wins_sequential_state_proof' => {"
    assert_includes source, "'proof_kind' => 'retirement_commit_then_fresh_admission_denial'"
    assert_includes source, "'subsequent_admission_outcome' => 'DENIED'"
  end

  def test_database_constraints_and_safe_down_paths_are_real_engine_operations
    source = File.read(SCRIPT)
    worker = Harness::PHP_WORKER

    %w[ward-state-check ward-version-check bed-foreign-key receipt-result-type-check reservation-master-type-check].each do |check|
      assert_includes worker, "'#{check}'"
    end
    assert_includes source, "run_artisan!('migrate:rollback', \"--path=\#{MIGRATION_PATH}\""
    assert_includes source, "run_artisan!('migrate', \"--path=\#{MIGRATION_PATH}\""
    assert_includes source, "expect_rollback_refusal!('verify-populated-refusal', 'raw_populated_retained')"
    assert_includes source, "expect_rollback_refusal!('verify-audit-refusal', 'correlated_audit_retained')"
    assert_includes worker, "'raw_populated_retained' => true"
    assert_includes worker, "'correlated_audit_retained' => true"
    assert_includes worker, 'app(SyntheticResetService::class)->reset('
    assert_includes worker, "'master_chains_removed' => true"
    assert_includes worker, "'code_reservation_retained' => true"
    assert_includes worker, "'old_code_reuse_refused' => true"
  end

  def test_census_projection_runs_actual_repeatable_read_read_only_syntax_on_both_engines
    source = File.read(SCRIPT)
    projection = File.read(PROJECTION)
    worker = Harness::PHP_WORKER

    assert_includes worker, 'extends App\\Support\\Inpatient\\InpatientOccupancyProjection'
    assert_includes worker, 'protected function afterClaimsSnapshotRead(): void'
    assert_includes worker, "'protocol_state' => 'CLAIMS_READ'"
    assert_includes worker, "$service->updateWard($actor(), $ward()->public_id, 'Bangsal Setelah Commit'"
    assert_includes worker, "'q' => '', 'ward_code' => '', 'service_class' => '', 'occupancy_state' => '', 'master_state' => ''"
    assert_includes source, "scenarios['census-repeatable-read'] = run_census_projection!"
    assert_includes source, "await_protocol!(observer, 'CLAIMS_READ')"
    assert_includes source, "require_protocol!(renamed, 'COMMITTED'"
    assert_includes source, "await_exit_protocol!(observer, 'VERIFIED')"
    assert_includes source, "'repeatable_read_snapshot_observed' => true"
    assert_includes source, "'observed_prechange_master' => true"
    assert_includes worker, "static fn (array $row): bool => data_get($row, 'code') === $wardCode"
    assert_includes worker, '$targetMatchCount === 1'
    assert_includes worker, "'target_ward_code' => $wardCode"
    assert_includes source, "verified['target_match_count'] == 1"
    assert_includes source, "'target_identity' => { 'type' => 'immutable_ward_code'"
    assert_includes source, "'exact_target_matched' => true"
    refute_includes worker, "data_get($projection, 'wards.0.display_name')"
    assert_includes projection, "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY"
    assert_includes projection, "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY"
  end

  def test_execution_binding_includes_mutation_projection_read_model_controller_and_audit_boundaries
    required = %w[
      app/Support/Inpatient/InpatientMasterMutationScope.php
      app/Support/Inpatient/InpatientMasterDirectWriteScope.php
      app/Support/Inpatient/InpatientMasterSqlWriteGuard.php
      app/Support/Inpatient/InpatientMasterService.php
      app/Support/Inpatient/InpatientOccupancyProjection.php
      app/Support/Inpatient/InpatientWardReadModel.php
      app/Http/Controllers/Inpatient/InpatientWardBedMasterController.php
      app/Http/Controllers/Inpatient/InpatientRegistrationController.php
      app/Http/Controllers/Inpatient/InpatientExaminationController.php
      app/Support/Audit/AuditRecorder.php
      app/Support/Audit/AuditEventSchemaRegistry.php
      database/migrations/2026_08_21_000100_create_rebuild_foundation_tables.php
      database/migrations/2026_08_25_000300_expand_audit_actor_attribution.php
    ]
    required.each { |path| assert_includes Harness::SOURCE_PATHS, path }
  end

  def test_census_signal_uses_owned_directory_exclusive_create_and_verified_cleanup
    source = File.read(SCRIPT)
    worker = Harness::PHP_WORKER

    assert_includes source, "Dir.mktmpdir('simrs-inpatient-master-signal-', '/private/tmp')"
    assert_includes source, 'File.chmod(0o700, directory)'
    assert_includes source, 'File.realpath(directory) == directory'
    assert_includes worker, "fopen($signalPath, 'x')"
    assert_includes worker, 'is_link($signalPath)'
    assert_includes worker, '(fileperms($signalPath) & 0777) !== 0600'
    assert_includes source, 'cleanup_census_signal_directory!(strict: true)'
    assert_includes source, "Dir.children(directory)"
    assert_includes source, "Dir.rmdir(directory)"
  end

  def test_pass_evidence_binds_actual_contract_migration_rbac_focused_suite_and_engine_commands
    source = File.read(SCRIPT)
    evidence_method = source[/def write_inpatient_master_evidence!.*?^  end/m]

    refute_nil evidence_method
    assert_includes source, 'contract_suite = run_contract_suite!'
    assert_includes source, 'focused_suite = run_test_suite!([FOCUSED_TEST_PATH])'
    assert_includes evidence_method, "'verification_commands'"
    assert_includes evidence_method, 'ruby #{CONTRACT_TEST}'
    assert_includes evidence_method, 'php artisan migrate:fresh --force --no-interaction'
    assert_includes evidence_method, 'php artisan db:seed --class=Database\\\\Seeders\\\\RbacSeeder --force --no-interaction'
    assert_includes evidence_method, 'php artisan test #{FOCUSED_TEST_PATH}'
    assert_includes evidence_method, '#{CONFIRMATION_ENV}=#{CONFIRMATION} ruby #{SCRIPT_PATH} #{@engine}'
    assert_includes evidence_method, "'result' => { 'status' => 'PASS' }"
  end

  def test_simulation_non_egress_and_no_live_integration_boundary_is_closed
    source = File.read(SCRIPT)
    foundation = File.read(File.join(Harness::ROOT, Harness::FOUNDATION_HARNESS_PATH))
    worker = Harness::PHP_WORKER

    assert_includes foundation, "'APP_MODE' => 'SIMULATION'"
    assert_includes foundation, "'APP_SYNTHETIC_ONLY' => 'true'"
    assert_includes foundation, "'MAIL_MAILER' => 'array'"
    assert_includes source, "'BPJS_INTEGRATION_ENABLED' => 'false'"
    assert_includes source, "'VCLAIM_ENABLED' => 'false'"
    assert_includes source, "'SATUSEHAT_ENABLED' => 'false'"
    assert_includes worker, "config('simulation.mode') !== 'SIMULATION'"
    assert_includes worker, "Patient::query()->where('is_synthetic', false)->exists()"
  end

  def test_cleanup_precedes_sanitized_mode_0600_evidence_and_protocol_never_persists_connections
    source = File.read(SCRIPT)
    evidence_method = source[/def write_inpatient_master_evidence!.*?^  end/m]

    refute_nil evidence_method
    assert_includes source, 'cleanup!(strict: true)'
    assert_operator source.index('cleanup!(strict: true)'), :<, source.index('evidence_path = write_inpatient_master_evidence!')
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
    refute_match(/git\s+(?:add|commit|push)/, source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, source)
  end

  def test_blocked_worker_diagnostics_are_class_and_fingerprint_only
    worker = Harness::PHP_WORKER
    blocked = worker[/catch \(Throwable \$exception\).*?exit\(1\);/m]

    refute_nil blocked
    assert_includes blocked, "'exception_class' => $exception::class"
    assert_includes blocked, "'exception_fingerprint' => hash('sha256'"
    refute_includes blocked, "'exception_message'"
    refute_includes blocked, 'getTrace'
  end

  def test_cli_accepts_exactly_one_supported_engine
    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, SCRIPT)
    assert_equal 64, status.exitstatus

    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, SCRIPT, 'postgresql17', 'mysql8411')
    assert_equal 64, status.exitstatus
  end
end
