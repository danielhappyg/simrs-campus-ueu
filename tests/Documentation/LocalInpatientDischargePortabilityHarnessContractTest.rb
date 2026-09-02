# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-inpatient-discharge-portability'

class LocalInpatientDischargePortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientDischargePortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_script_and_embedded_worker_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['inpatient-discharge-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system('/opt/homebrew/bin/php', '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 20_000
  ensure
    worker&.close!
  end

  def test_closed_nineteen_scenario_catalogue_is_exact
    assert_equal %w[
      fresh-migration
      empty-down-reapply
      successful-routine-discharge
      missing-draft-stale-summary-denials
      stale-placement-denial
      exact-idempotent-replay
      changed-payload-key-conflict
      corrupt-replay-binding-denial
      same-discharge-competing-execution
      final-vs-transfer-final-first
      final-vs-transfer-transfer-first
      released-bed-reuse
      audit-failure-atomic-rollback
      receipt-failure-atomic-rollback
      append-only-engine-refusal
      least-privilege-runtime
      bounded-synthetic-reset
      populated-evidence-down-refusal
      invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    assert_equal %w[
      same-discharge-competing-execution
      final-vs-transfer-final-first
      final-vs-transfer-transfer-first
      released-bed-reuse
    ], Harness::RACE_SCENARIOS
  end

  def test_disposable_exact_engines_and_external_connection_refusal
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal '17.10', Harness::POSTGRES_VERSION
    assert_equal '8.4.11', Harness::MYSQL_VERSION
    error = assert_raises(Harness::CommandFailed) do
      harness(confirmed_environment.merge('DB_PASSWORD' => 'must-not-be-consumed')).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_PASSWORD/, error.message)
  end

  def test_migration_lifecycle_populated_refusal_and_source_binding_are_closed
    assert_equal 'database/migrations/2026_08_31_000700_create_inpatient_discharge_tables.php', Harness::MIGRATION_PATH
    assert_includes @source, "recorded_artisan!('migrate:fresh', '--force', '--no-interaction')"
    assert_includes @source, "recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH"
    assert_includes @source, "recorded_artisan!('migrate', '--path='+MIGRATION_PATH"
    assert_includes @source, "expect_rollback_refusal!('correlated audit evidence remains')"
    binding = harness(confirmed_environment).current_discharge_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
    %w[
      app/Support/Inpatient/InpatientDischargeService.php
      app/Support/Inpatient/InpatientDischargeSummaryEvidenceDigest.php
      app/Support/Inpatient/InpatientBedTransferService.php
      app/Support/Inpatient/CanonicalInpatientBedOperationLockCoordinator.php
      app/Support/Registration/InpatientBedClaimGuard.php
      database/migrations/2026_08_31_000700_create_inpatient_discharge_tables.php
      tests/Feature/Inpatient/RoutineInpatientDischargeTest.php
    ].each { |path| assert_includes Harness::SOURCE_PATHS, path }
  end

  def test_real_services_cover_success_denials_replay_conflict_and_corruption
    assert_includes @worker, 'app(InpatientDischargeService::class)->execute('
    assert_includes @worker, 'app(InpatientDischargeSummaryService::class)->finalize('
    assert_includes @worker, 'app(InpatientBedTransferService::class)->transfer('
    assert_includes @worker, 'app(InpatientMasterService::class)->resolveActiveBedForAdmission('
    assert_includes @worker, "'successful_routine_discharge' => true"
    assert_includes @worker, "$missingDenied = $denial->reason === 'summary_missing'"
    assert_includes @worker, "$draftDenied = $denial->reason === 'summary_not_final'"
    assert_includes @worker, "$staleSummaryDenied = $denial->reason === 'stale_summary'"
    assert_includes @worker, "$staleLocationDenied = $denial->reason === 'stale_location'"
    assert_includes @worker, "$conflict = $denial->reason === 'idempotency_key_conflict'"
    assert_includes @worker, "$corruptDenied = $denial->reason === 'receipt_binding_invalid'"
    assert_includes @worker, 'discharge_summary_content_digest'
    assert_includes @worker, 'discharge_summary_provenance_digest'
  end

  def test_races_are_two_real_processes_with_native_wait_and_no_harness_prelocks
    assert_includes @source, 'Open3.popen3'
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, 'pg_blocking_pids'
    assert_includes @source, 'performance_schema.data_lock_waits'
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @source, "'durable_third_connection_assertions' => true"
    assert_includes @source, "'deadlock_observed' => false"
    assert_includes @source, "'harness_bed_prelock' => false"
    assert_includes @worker, "'final_terminal_rule' => true"
    assert_includes @worker, "'released_bed_reused' => true"
    refute_includes @worker, 'GET_LOCK('
    refute_includes @worker, 'pg_advisory_lock'
  end

  def test_atomic_failures_append_only_least_privilege_and_reset_are_real
    assert_includes @worker, 'new class extends AuditRecorder'
    assert_includes @worker, 'installDischargeReceiptFailureTrigger()'
    assert_includes @worker, 'idcor_fail_insert_trg'
    assert_includes @worker, "'failure_bed_claims_retained' => true"
    assert_includes @worker, "SchemaQualifier::table('inpatient_discharges')"
    assert_includes @worker, "SchemaQualifier::table('inpatient_discharge_operation_receipts')"
    assert_includes @worker, 'TRUNCATE TABLE '
    assert_includes @source, "runtime_privileges = IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT'"
    assert_includes Harness::IMMUTABLE_HISTORY_TABLES, 'inpatient_discharges'
    assert_includes Harness::IMMUTABLE_HISTORY_TABLES, 'inpatient_discharge_operation_receipts'
    assert_includes @worker, 'app(SyntheticResetService::class)->reset(['
    assert_includes @worker, "'discharge_rows_removed' => true"
  end

  def test_evidence_is_sanitized_mode_0600_and_cleanup_is_mandatory
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'File.chmod(0o600, path)'
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, "'database_removed' => true"
    assert_includes @source, "'temporary_worker_removed' => true"
    assert_match(/ensure\n\s+terminate_workers!/, @source)
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
  end

  private

  def confirmed_environment
    { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness(environment)
    Harness.new(engine: 'postgresql17', environment: environment)
  end
end
