# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-inpatient-discharge-summary-portability'

class LocalInpatientDischargeSummaryPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientDischargeSummaryPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_script_and_embedded_worker_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['inpatient-discharge-summary-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system('/opt/homebrew/bin/php', '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 10_000
  ensure
    worker&.close!
  end

  def test_closed_sixteen_scenario_catalogue_is_exact_and_unique
    assert_equal %w[
      fresh-migration
      empty-down-reapply
      draft-then-final
      terminal-final-refusal
      exact-idempotent-replay
      changed-payload-key-conflict
      same-summary-competing-finalization
      transfer-vs-summary-summary-first
      transfer-vs-summary-transfer-first
      audit-failure-atomic-rollback
      receipt-failure-atomic-rollback
      append-only-engine-refusal
      least-privilege-runtime
      bounded-synthetic-reset
      populated-evidence-down-refusal
      invariant-verification
    ], Harness::SCENARIOS
    assert_equal 16, Harness::SCENARIOS.length
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    assert_equal %w[
      same-summary-competing-finalization
      transfer-vs-summary-summary-first
      transfer-vs-summary-transfer-first
    ], Harness::RACE_SCENARIOS
    Harness::SCENARIOS.each { |scenario| assert_includes @source, "'#{scenario}'" }
  end

  def test_disposable_exact_engines_and_external_connection_refusal_are_inherited
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal '17.10', Harness::POSTGRES_VERSION
    assert_equal '8.4.11', Harness::MYSQL_VERSION

    error = assert_raises(Harness::CommandFailed) do
      harness(confirmed_environment.merge('DB_PASSWORD' => 'must-not-be-consumed')).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_PASSWORD/, error.message)
    error = assert_raises(Harness::CommandFailed) do
      harness(confirmed_environment.merge('DB_URL' => 'postgresql://outside.invalid/database')).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_URL/, error.message)
  end

  def test_fresh_migration_empty_down_reapply_and_populated_refusal_are_real_commands
    assert_includes @source, "recorded_artisan!('migrate:fresh', '--force', '--no-interaction')"
    assert_includes @source, "recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH"
    assert_includes @source, "recorded_artisan!('migrate', '--path='+MIGRATION_PATH"
    assert_includes @source, "expect_rollback_refusal!('correlated audit evidence remains')"
    assert_includes @source, "'fresh_apply' => true"
    assert_includes @source, "'empty_down' => true"
    assert_includes @source, "'reapply' => true"
    assert_includes @source, "'EXPECTED_REFUSAL'"
  end

  def test_source_worker_and_catalogue_are_bound_to_current_bytes
    binding = harness(confirmed_environment).current_discharge_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
    assert_includes @source, "'command_catalog_sha256'"
    assert_includes @source, "'result_catalog_sha256'"
    assert_includes @source, 'assert_unchanged_binding!'
    %w[
      app/Support/Inpatient/InpatientDischargeSummaryService.php
      app/Support/Inpatient/CanonicalInpatientBedOperationLockCoordinator.php
      app/Support/Inpatient/InpatientBedTransferService.php
      app/Support/Inpatient/InpatientLocationSqlWriteGuard.php
      app/Support/Inpatient/InpatientDocumentationSqlWriteGuard.php
      app/Support/Simulation/SyntheticResetService.php
      app/Support/Audit/AuditRecorder.php
      database/migrations/2026_08_31_000600_create_inpatient_discharge_summary_tables.php
      tests/Unit/Inpatient/InpatientLocationSqlWriteGuardTest.php
      tests/Unit/Inpatient/InpatientDocumentationSqlWriteGuardTest.php
    ].each { |path| assert_includes @source, path }
  end

  def test_draft_final_terminal_replay_and_conflict_use_real_service_methods
    assert_includes @worker, 'app(InpatientDischargeSummaryService::class)->saveDraft('
    assert_includes @worker, 'app(InpatientDischargeSummaryService::class)->finalize('
    assert_includes @worker, "must(! $first->replayed && $first->summary->version === 1, 'initial Draft applied')"
    assert_includes @worker, "must($replay->replayed && $replay->resultVersion->version === 1, 'identical retry replayed')"
    assert_includes @worker, "$denial->reason === 'idempotency_key_conflict'"
    assert_includes @worker, "InpatientDischargeSummary::STATE_FINAL"
    assert_includes @worker, "$terminalReasons === ['summary_final', 'summary_final']"
    assert_includes @worker, "InpatientDischargeSummary::DEFINITION_VERSION === 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1'"
    assert_includes @worker, "InpatientDischargeSummaryOperationReceipt::OPERATION_DRAFT_SAVE === 'DISCHARGE_SUMMARY_DRAFT_SAVE'"
    assert_includes @worker, "InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE === 'DISCHARGE_SUMMARY_FINALIZE'"
    assert_includes @worker, "'main_version_rows'"
    assert_includes @worker, "'main_receipt_rows'"
    assert_includes @worker, "'main_success_audit_rows'"
  end

  def test_final_replay_is_bound_to_exact_encounter_summary_version_and_stored_result
    assert_includes @worker, "must($finalReplay->replayed, 'identical Final replay returned')"
    assert_includes @worker, "'Final replay summary binding'"
    assert_includes @worker, "'Final replay version binding'"
    assert_includes @worker, "'Final replay version number binding'"
    assert_includes @worker, "'Final receipt encounter binding'"
    assert_includes @worker, "'Final receipt summary binding'"
    assert_includes @worker, "'Final receipt version binding'"
    assert_includes @worker, "'Final replay key cannot cross encounter binding'"
    assert_includes @worker, "$denial->reason === 'idempotency_key_conflict'"
    assert_includes @worker, "'final_replay_result_binding' => true"
    assert_includes @worker, "'final_cross_encounter_conflict' => true"
    assert_includes @source, "%w[exact_idempotent_replay final_replay_result_binding]"
    assert_includes @source, "%w[changed_payload_key_conflict final_cross_encounter_conflict]"
  end

  def test_real_event_and_legacy_baseline_provenance_are_both_durable
    assert_includes @worker, "'legacy' => $bed('70', 'Baseline Penempatan Lama')"
    assert_includes @worker, "createEncounter($registrar, $ward, $beds['legacy'], 8, $token, false)"
    assert_includes @worker, "'legacy baseline has sequence zero'"
    assert_includes @worker, "'legacy baseline has no event identity'"
    assert_includes @worker, "'legacy baseline has no event type'"
    assert_includes @worker, 'InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT'
    assert_includes @worker, "'legacy baseline is incomplete'"
    assert_includes @worker, "'legacy current bed snapshot coherent'"
    assert_includes @worker, "'legacy_baseline_provenance' => true"

    assert_includes @worker, "'summary-first real event identity persisted'"
    assert_includes @worker, 'InpatientLocationEvent::TYPE_ADMISSION'
    assert_includes @worker, "'summary-first history provenance complete'"
    assert_includes @worker, "'transfer-first event identity persisted'"
    assert_includes @worker, 'InpatientLocationEvent::TYPE_TRANSFER'
    assert_includes @worker, "'transfer-first history provenance complete'"
    assert_includes @worker, "'real_event_provenance_complete' => true"
    assert_includes @worker, '$version->history_baseline === null && $version->history_complete === true'
  end

  def test_retained_cancellation_is_denied_after_real_managed_placement
    assert_includes @worker, 'use App\\Models\\EncounterCancellation;'
    assert_includes @worker, "'cancelled' => $bed('80', 'Episode Dibatalkan')"
    assert_includes @worker, 'EncounterCancellation::query()->create(['
    assert_includes @worker, 'EncounterCancellation::REASON_WRONG_REGISTRATION'
    assert_includes @worker, "'cancelled_at' => now()"
    assert_includes @worker, "$denial->reason === 'encounter_cancelled'"
    assert_includes @worker, "'retained cancellation denied summary mutation'"
    assert_includes @worker, "'cancelled episode has no summary'"
    assert_includes @worker, "'retained_cancellation_denial' => true"
    assert_includes @source, 'legacy_baseline_provenance retained_cancellation_denial'
  end

  def test_competing_finalization_is_two_process_observed_wait_with_third_connection
    assert_includes @source, 'Open3.popen3'
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, 'pg_blocking_pids'
    assert_includes @source, 'performance_schema.data_lock_waits'
    assert_includes @source, "expected = scenario == 'same-summary-competing-finalization' ? %w[APPLIED DENIED].sort"
    assert_includes @worker, "'one_terminal_final' => true"
    assert_includes @worker, "'version_chain_length' => 2"
    assert_includes @source, "action: 'verify_race'"
    assert_includes @source, "'durable_third_connection_assertions' => true"
    assert_includes @source, "'deadlock_observed' => false"
  end

  def test_transfer_summary_orderings_use_real_services_without_harness_bed_prelocks
    assert_includes @worker, "if ($scenario === 'transfer-vs-summary-summary-first')"
    assert_includes @worker, "app(InpatientBedTransferService::class)->transfer("
    assert_includes @worker, "saveDraft($fixture['summary_first_encounter']"
    assert_includes @worker, "saveDraft($fixture['transfer_first_encounter']"
    assert_includes @worker, "'coherent_pre_transfer_snapshot' => true"
    assert_includes @worker, "'coherent_post_transfer_snapshot' => true"
    assert_includes @worker, "'real_event_provenance_complete' => true"
    assert_includes @worker, "$version->location_sequence === 1"
    assert_includes @worker, "$version->location_sequence === 2"
    assert_includes @source, "'harness_bed_prelock' => false"
    refute_includes @worker, 'lockMutexes('
    refute_includes @worker, 'lockForUpdate()'
    refute_includes @worker, 'GET_LOCK('
    refute_includes @worker, 'pg_advisory_lock'
  end

  def test_audit_and_receipt_failures_are_injected_inside_real_atomic_path
    assert_includes @worker, 'new class extends AuditRecorder'
    assert_includes @worker, 'return null;'
    assert_includes @worker, 'installReceiptFailureTrigger()'
    assert_includes @worker, 'idsor_fail_insert_trg'
    assert_equal 2, @worker.scan('InpatientDischargeSummarySchemaMutationScope::run(static function () use ($table): void').length
    install = @worker.split('function installReceiptFailureTrigger(): void', 2).last
      .split('function dropReceiptFailureTrigger(): void', 2).first
    drop = @worker.split('function dropReceiptFailureTrigger(): void', 2).last
      .split('function runAtomicFailures', 2).first
    assert_includes install, 'InpatientDischargeSummarySchemaMutationScope::run('
    assert_includes drop, 'InpatientDischargeSummarySchemaMutationScope::run('
    assert_operator install.index('InpatientDischargeSummarySchemaMutationScope::run('), :<, install.index('CREATE TRIGGER idsor_fail_insert_trg')
    assert_operator drop.index('InpatientDischargeSummarySchemaMutationScope::run('), :<, drop.index('DROP TRIGGER IF EXISTS idsor_fail_insert_trg')
    assert_includes @worker, "'audit_failure_atomic_rollback' => true"
    assert_includes @worker, "'receipt_failure_atomic_rollback' => true"
    assert_includes @worker, "'failure_head_rows' => 0"
    assert_includes @worker, "'failure_version_rows' => 0"
    assert_includes @worker, "'failure_receipt_rows' => 0"
    assert_includes @worker, "'failure_success_audit_rows' => 0"
  end

  def test_atomic_failure_protocol_reports_only_a_closed_sanitized_stage
    expected = %w[
      bootstrap
      dispatch
      audit_service_save
      audit_rollback_verification
      receipt_trigger_create
      receipt_service_save
      receipt_trigger_drop
      receipt_rollback_verification
      sequential_initial_draft
      sequential_draft_replay
      sequential_complete_draft
      sequential_finalize
      sequential_race_drafts
      sequential_final_replay
      sequential_legacy
      sequential_cancelled
      complete
    ]
    allowed_block = @worker.split('function setFailureStage(string $stage): void', 2).last
      .split('function currentFailureStage(): string', 2).first
    assert_equal expected, allowed_block.scan(/^\s*'([a-z_]+)',$/).flatten
    exercised = @worker.scan(/setFailureStage\('([a-z_]+)'\)/).flatten.uniq
    assert_equal expected.drop(1).sort, exercised.sort
    assert_includes @worker, "'failure_stage' => currentFailureStage()"
    assert_includes @source, %q{stage=#{document['failure_stage']}}
    assert_includes @worker, "$GLOBALS['simrs_discharge_failure_stage'] = 'bootstrap'"
    refute_includes allowed_block, '$exception'
    refute_includes allowed_block, 'DB::'
    refute_match(/sql|query|password|credential|connection/i, expected.join(' '))
  end

  def test_append_only_database_refusals_cover_both_immutable_tables
    %w[
      version_update version_delete version_truncate
      receipt_update receipt_delete receipt_truncate
    ].each { |operation| assert_includes @worker, "'#{operation}'" }
    assert_includes @worker, "SchemaQualifier::table('inpatient_discharge_summary_versions')"
    assert_includes @worker, "SchemaQualifier::table('inpatient_discharge_summary_operation_receipts')"
    assert_includes @worker, 'InpatientDischargeSummaryMutationScope::run($attempt)'
    assert_includes @worker, "'runtime_destructive_refusals' => $refused"
    assert_includes @worker, "'owner_database_trigger_refusals' => $refused"
    assert_includes @worker, "'owner_truncate_refusal_kind'"
    assert_includes @worker, "'NOT_SUPPORTED_BY_MYSQL_TRIGGER'"
    assert_includes @worker, "'version_chain_unchanged' => true"
    assert_includes @worker, "'receipt_chain_unchanged' => true"
  end

  def test_owner_runtime_and_reset_identities_enforce_least_privilege
    assert_includes @source, 'provision_runtime_identities!'
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'CREATE ROLE "#{runtime}" LOGIN'
    assert_includes @source, %q{CREATE USER '#{runtime}'@'127.0.0.1'}
    assert_includes @source, "IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT'"
    assert_includes Harness::IMMUTABLE_HISTORY_TABLES, 'inpatient_location_events'
    assert_includes Harness::IMMUTABLE_HISTORY_TABLES, 'inpatient_location_operation_receipts'
    assert_includes @source, "privileges == 'INSERT,SELECT'"
    assert_includes @source, '%w[DROP ALTER TRIGGER CREATE]'
    assert_includes @source, "'runtime_grant_profile' => 'DML_EXCEPT_IMMUTABLE_HISTORY_SELECT_INSERT_ONLY'"
    assert_includes @source, "'reset_identity_separate' => true"
    assert_includes @source, '@runtime_application_environment'
    assert_includes @source, '@reset_application_environment'
    assert_includes @source, 'connection_environment: @reset_application_environment'
  end

  def test_reset_is_bounded_and_retains_audit_before_populated_down_refusal
    assert_includes @worker, 'app(SyntheticResetService::class)->reset(['
    assert_includes @worker, "'bounded_discharge_summary_portability_reset'"
    assert_includes @worker, "'summary_heads_removed' => true"
    assert_includes @worker, "'summary_versions_removed' => true"
    assert_includes @worker, "'summary_receipts_removed' => true"
    assert_includes @worker, "'summary_audit_rows_retained' => true"
    assert_includes @worker, "'reset_audit_retained' => true"
    reset = @source.index("action: 'reset'")
    refusal = @source.index("expect_rollback_refusal!('correlated audit evidence remains')")
    refute_nil reset
    refute_nil refusal
    assert_operator reset, :<, refusal
  end

  def test_durable_invariants_and_cleanup_precede_mode_0600_evidence
    assert_includes @worker, "'duplicate_episode_heads' => $duplicateHeads"
    assert_includes @worker, "'orphan_version_rows' => $orphanVersions"
    assert_includes @worker, "'terminal_final_heads' => $finals"
    cleanup = @source.index('cleanup!(strict: true)')
    evidence = @source.index('write_discharge_evidence!')
    refute_nil cleanup
    refute_nil evidence
    assert_operator cleanup, :<, evidence
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, 'File.chmod(0o600, path)'
    assert_includes @source, "'database_removed' => true"
    assert_includes @source, "'temporary_server_removed' => true"
    assert_includes @source, "'temporary_worker_removed' => true"
  end

  def test_evidence_is_local_only_synthetic_and_contains_no_secret_values
    assert_includes @source, "'claim' => 'LOCAL_DISPOSABLE_INPATIENT_DISCHARGE_SUMMARY_PORTABILITY_ONLY'"
    assert_includes @source, "'hosted_readiness_claim' => false"
    assert_includes @source, "'deployment_claim' => false"
    assert_includes @source, "'owner_acceptance_claim' => false"
    assert_includes @source, "'application_mode' => 'SIMULATION'"
    assert_includes @source, "'synthetic_only' => true"
    assert_includes @source, "'live_integrations_enabled' => false"
    assert_includes @worker, "getenv($flag) === 'false'"
    refute_match(/DEMO_ACCOUNT_PASSWORD\s*=\s*\S+/i, @source)
    refute_match(/postgres(?:ql)?:\/\/[^\s'\"]+:[^\s'\"]+@/i, @source)
  end

  def test_harness_remains_ruby_26_compatible
    refute_includes @source, 'filter_map'
    refute_includes @source, '_1'
  end

  private

  def harness(environment)
    Harness.new(engine: 'postgresql17', environment: environment)
  end

  def confirmed_environment
    {
      Harness::CONFIRMATION_ENV => Harness::CONFIRMATION,
      'SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => LocalPortabilityFullSuiteRehearsal::CONFIRMATION,
    }
  end
end
