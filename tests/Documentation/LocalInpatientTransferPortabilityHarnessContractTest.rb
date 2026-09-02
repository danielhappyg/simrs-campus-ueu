# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require 'rbconfig'

require_relative '../../scripts/rehearse-local-inpatient-transfer-portability'

class LocalInpatientTransferPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientTransferPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
  end

  def test_script_and_embedded_worker_are_syntactically_valid
    script = File.join(Harness::ROOT, Harness::SCRIPT_PATH)
    assert system(RbConfig.ruby, '-c', script, out: File::NULL, err: File::NULL)

    worker = Tempfile.new(['inpatient-transfer-contract-', '.php'])
    worker.write(Harness::WORKER_SOURCE)
    worker.flush
    assert system('/opt/homebrew/bin/php', '-l', worker.path, out: File::NULL, err: File::NULL)
    refute_includes @source, '**races'
    assert_includes @source, 'races.each { |scenario, result| scenarios[scenario] = result }'
  ensure
    worker&.close!
  end

  def test_closed_scenario_catalogue_covers_every_required_portability_proof
    assert_equal %w[
      fresh-migration
      empty-down-reapply
      populated-event-down-refusal
      populated-receipt-down-refusal
      successful-atomic-transfer
      exact-idempotent-replay
      occupied-target-denial
      append-only-database-trigger-refusal
      truthful-legacy-baseline
      audit-failure-atomic-rollback
      receipt-failure-atomic-rollback
      same-encounter-competing-transfer
      two-encounters-same-target
      admission-vs-transfer-transfer-first
      admission-vs-transfer-admission-first
      target-retirement-transfer-first
      target-retirement-retirement-first
      whole-ward-retirement-vs-transfer
      whole-ward-retirement-vs-documentation
      source-retirement-during-claim
      opposite-direction-swaps
      documentation-vs-transfer-document-first
      documentation-vs-transfer-transfer-first
      bounded-synthetic-reset
      correlated-audit-down-refusal
      invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS

    Harness::SCENARIOS.each { |scenario| assert_includes @source, "'#{scenario}'" }
  end

  def test_exact_disposable_engines_and_external_connection_refusal_are_inherited
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

  def test_fresh_migration_and_source_hash_binding_are_real_commands
    binding = harness(confirmed_environment).current_transfer_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end

    assert_includes @source, "recorded_artisan!('migrate:fresh', '--force', '--no-interaction')"
    assert_includes @source, "recorded_artisan!('db:seed', '--class=Database\\\\Seeders\\\\RbacSeeder'"
    assert_includes @source, "'fresh_apply' => 'PASS'"
    assert_includes @source, "'worker_source_sha256'"
    assert_includes @source, "'command_catalog_sha256'"
    assert_includes @source, "'result_catalog_sha256'"
  end

  def test_success_idempotent_race_and_occupied_target_use_the_real_transfer_service
    worker = Harness::WORKER_SOURCE
    assert_includes worker, 'InpatientLocationMutationScope::run(static fn (): Encounter'
    assert_includes worker, 'InpatientLocationMutationScope::run(fn (): Encounter'
    assert_includes worker, 'app(InpatientBedTransferService::class)->transfer('
    assert_includes worker, "must($first->replayed === false, 'first transfer applied')"
    assert_includes worker, 'function runIdempotentRace(array $fixture, string $token): array'
    assert_includes worker, "'outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED'"
    assert_includes @source, 'run_unassisted_idempotent_race!'
    assert_includes @source, "outcomes == %w[APPLIED REPLAYED]"
    assert_includes @source, "'proof_kind' => 'UNASSISTED_TWO_PROCESS_SAME_KEY_SERVICE_RACE'"
    assert_includes @source, "'harness_prelock' => false"
    assert_includes worker, "$denial->reason === 'target_occupied'"
    assert_includes worker, "'event_rows_after_replay' => 1"
    assert_includes worker, "'receipt_rows_after_replay' => 1"
    assert_includes worker, "'success_audit_rows_after_replay' => 1"
    refute_includes worker, "must($replay->replayed === true, 'exact replay returned')"
  end

  def test_race_matrix_uses_two_processes_native_wait_and_durable_third_connection
    assert_includes @source, 'Open3.popen3'
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, 'pg_blocking_pids'
    assert_includes @source, 'performance_schema.data_lock_waits'
    assert_includes @source, "'deadlock_observed' => false"
    assert_includes @source, 'expected_race_outcomes(scenario)'
    assert_includes @source, "action: 'verify_race'"
    assert_includes @source, "'durable_third_connection_assertions' => true"
    assert_includes Harness::WORKER_SOURCE, 'raceLockCodes($fixture, $scenario)'
    assert_includes Harness::WORKER_SOURCE, "'target_occupied'"
    Harness::RACE_SCENARIOS.each { |scenario| assert_includes @source, scenario }
  end

  def test_same_key_and_documentation_races_use_owner_delays_but_no_harness_prelock
    worker = Harness::WORKER_SOURCE
    assert_includes worker, 'function installDelayTrigger(array $fixture, string $scenario): array'
    assert_includes worker, 'pg_sleep(3.5)'
    assert_includes worker, 'DO SLEEP(3.5)'
    assert_includes @source, 'observe_service_delay!'
    assert_includes @source, "wait_event='PgSleep'"
    assert_includes @source, "STATE='User sleep'"
    assert_includes @source, "action: 'idempotent_race'"
    assert_includes @source, "action: 'verify_idempotent_race'"
    assert_includes @source, 'run_unassisted_documentation_race!'
    assert_includes worker, "if (str_starts_with($scenario, 'documentation-vs-transfer'))"
    assert_includes worker, 'return $operation();'
    refute_includes worker, "'documentation-vs-transfer-document-first' => ['document_df_source']"
    refute_includes worker, "'documentation-vs-transfer-transfer-first' => ['document_tf_source']"
    assert_includes worker, "'coherent_source_snapshot'"
    assert_includes worker, "'coherent_target_snapshot'"
    assert_includes @source, "'deadlock_observed' => false"
  end

  def test_inconsistent_claim_lock_order_subproof_uses_real_services_and_durable_readback
    worker = Harness::WORKER_SOURCE
    assert_equal 26, Harness::SCENARIOS.length
    assert_includes worker, "'claim_order_x' => $bed('D0', 'Urutan Klaim X')"
    assert_includes worker, "'claim_order_y' => $bed('D1', 'Urutan Klaim Y')"
    assert_includes worker, "static fn () => $claimOrder->update(['bed_code' => $beds['claim_order_x']->code])"
    assert_includes worker, 'function runInconsistentClaimRace(array $fixture, string $token, string $worker, int $holdMs): array'
    assert_includes worker, "resolveActiveBedForAdmission($fixture['claim_order_x'])"
    assert_includes worker, "$fixture, 'claim_order_y',"
    assert_includes worker, "protocol('HOLDING')"
    assert_includes worker, 'function verifyInconsistentClaimRace(array $fixture): array'
    assert_includes worker, "'inconsistent_claim_y_id_preserved'"
    assert_includes worker, "'inconsistent_claim_x_code_preserved'"
    assert_includes worker, "'claim_order_bed_x_unchanged'"
    assert_includes worker, "'claim_order_bed_y_unchanged'"
    assert_includes @source, 'run_inconsistent_claim_order_race!'
    assert_includes @source, "outcomes == %w[DENIED DENIED]"
    assert_includes @source, "%w[bed_occupied target_occupied]"
    assert_includes @source, "'claim_lock_method_exercised' => 'InpatientBedClaimGuard::lockClaimsAfterCanonicalMutexes'"
    assert_includes @source, "'inconsistent_claim_lock_order_subproof' => inconsistent_claim_order"
    assert_includes @source, "'harness_prelock' => false"
    assert_includes @source, "'deadlock_observed' => false"
  end

  def test_append_only_proof_reaches_database_triggers_for_update_and_delete_on_both_tables
    worker = Harness::WORKER_SOURCE
    assert_includes worker, "SchemaQualifier::table('inpatient_location_events')"
    assert_includes worker, "SchemaQualifier::table('inpatient_location_operation_receipts')"
    assert_includes worker, 'InpatientLocationMutationScope::run($attempt)'
    %w[event_update event_delete receipt_update receipt_delete event_truncate].each do |operation|
      assert_includes worker, "'#{operation}'"
    end
    assert_includes worker, "'runtime_destructive_refusals' => $refusals"
    assert_includes worker, "'owner_postgresql_database_trigger_refusals' => $refusals"
    assert_includes worker, "'event unchanged'"
    assert_includes worker, "'receipt unchanged'"
  end

  def test_retirement_admission_swap_and_documentation_races_have_durable_assertions
    worker = Harness::WORKER_SOURCE
    %w[
      source_retirement_denied_while_claimed
      ward_retirement_denied_on_recheck
      coherent_source_snapshot
      coherent_target_snapshot
      original_claims_preserved
      one_target_claim
    ].each { |assertion| assert_includes worker, "'#{assertion}'" }
    assert_includes worker, 'resolveActiveBedForAdmission'
    assert_includes worker, 'retireBed('
    assert_includes worker, 'retireWard('
    assert_includes worker, 'InpatientDocumentationService::class'
  end

  def test_atomic_failure_legacy_and_down_refusal_proofs_are_real_operations
    worker = Harness::WORKER_SOURCE
    assert_includes worker, 'new class extends AuditRecorder'
    assert_includes worker, 'installReceiptFailureTrigger()'
    assert_includes worker, "'audit_failure_atomic_rollback' => true"
    assert_includes worker, "'receipt_failure_atomic_rollback' => true"
    assert_includes worker, "'LEGACY_CURRENT_PLACEMENT'"
    assert_includes worker, "'backfill_performed' => false"
    assert_includes @source, "'migrate:rollback'"
    assert_includes @source, 'expect_rollback_refusal!'
    assert_includes @source, "'correlated audit evidence remains'"
  end

  def test_owner_runtime_and_reset_identities_enforce_history_and_ddl_boundaries
    worker = Harness::WORKER_SOURCE
    assert_includes worker, "'event_truncate'"
    assert_includes worker, "'TRUNCATE TABLE '"
    assert_includes @source, 'provision_runtime_identities!'
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'CREATE ROLE "#{runtime}" LOGIN'
    assert_includes @source, %q{CREATE USER '#{runtime}'@'127.0.0.1'}
    assert_includes @source, "IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT'"
    assert_includes @source, 'GRANT #{runtime_privileges} ON TABLE'
    assert_includes @source, 'GRANT #{runtime_privileges} ON `#{@mysql_database}`.`#{table}`'
    assert_includes @source, 'SHOW GRANTS FOR'
    assert_includes @source, '%w[DROP ALTER TRIGGER CREATE]'
    assert_includes @source, "'runtime_grant_profile' => 'DML_EXCEPT_IMMUTABLE_HISTORY_SELECT_INSERT_ONLY'"
    assert_includes @source, "'reset_identity_separate' => true"
    assert_includes @source, '@runtime_application_environment = runtime_environment'
    assert_includes @source, '@reset_application_environment = reset_environment'
    assert_includes @source, "connection_environment: @reset_application_environment"
    assert_includes worker, "'event_trigger_drop'"
    assert_includes worker, "'RUNTIME_PRIVILEGE'"
    assert_includes worker, "'DATABASE_TRIGGER'"
    assert_includes worker, "'owner_event_truncate_trigger'"
    assert_includes @source, "privileges == 'INSERT,SELECT'"
    assert_includes worker, "'DROP TRIGGER ile_immutable_update_trg'"
    assert_includes worker, "'event_delete_after_reset_marker_forgery'"
    assert_includes worker, "'runtime app user cannot forge the reset escape'"
    assert_includes worker, "'reset_marker_forgery_refused' => $markerForgeryRefused"

    refute_includes @source, 'reduce_mysql_runtime_privileges!'
    refute_includes @source, "runtime_privileges += ', TRUNCATE'"
    refute_includes @source, "'runtime_grant_profile' => 'SELECT_INSERT_UPDATE_DELETE_ONLY'"
    refute_match(/GRANT SELECT, INSERT, UPDATE, DELETE ON `#\{@mysql_database\}`\.\* TO '#\{runtime\}'/, @source)
    refute_match(/GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA .* TO "#\{runtime\}"/, @source)
  end

  def test_harness_remains_ruby_26_compatible
    refute_includes @source, 'filter_map'
    refute_includes @source, '_1'
  end

  def test_invariant_catalog_checks_claim_sequence_event_receipt_audit_and_reason_separation
    worker = Harness::WORKER_SOURCE
    assert_includes worker, "'no duplicate active bed claim'"
    assert_includes worker, "'location sequence is monotonic and gapless'"
    assert_includes worker, "'receipt references exact event'"
    assert_includes worker, "'event receipt audit consistency'"
    assert_includes worker, "! array_key_exists('reason', $metadata)"
    assert_includes worker, "$audit->reason === null"
    assert_includes worker, "'event_receipt_audit_consistency' => true"
    assert_includes worker, "'reason_absent_from_success_audit' => true"
  end

  def test_bounded_reset_removes_location_chain_retains_audit_and_clears_escape_marker
    worker = Harness::WORKER_SOURCE
    assert_includes worker, 'app(SyntheticResetService::class)->reset(['
    assert_includes worker, "'reset removed location events'"
    assert_includes worker, "'reset removed location receipts'"
    assert_includes worker, "'reset retained transfer audit evidence'"
    assert_includes worker, "COALESCE(@simrs_synthetic_reset, 0)"
    assert_includes worker, "current_setting('simrs.synthetic_reset', true)"
    assert_includes worker, "'reset_marker_cleared' => true"
  end

  def test_local_only_boundary_secret_sanitization_cleanup_and_evidence_permissions
    assert_includes @source, "'APP_MODE'" unless @source.include?("'application_mode' => 'SIMULATION'")
    assert_includes @source, "'application_mode' => 'SIMULATION'"
    assert_includes @source, "'synthetic_only' => true"
    assert_includes @source, "'live_integrations_enabled' => false"
    assert_includes @source, "getenv($key) !== 'false'"
    assert_includes @source, 'cleanup!(strict: true)'
    assert_includes @source, 'remove_worker_file!'
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, "%w[fixture backend_connection_id].include?(key)"
    assert_includes @source, "reject { |key, _value| key == 'backend_connection_id' }"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
    refute_match(/(?:DB_PASSWORD|PGPASSWORD|MYSQL_PWD)\s*=\s*[^'\"\s]+/, @source)
  end

  def test_cli_accepts_exactly_one_supported_engine_without_starting_a_database
    script = File.join(Harness::ROOT, Harness::SCRIPT_PATH)
    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, script)
    assert_equal 64, status.exitstatus

    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, script, 'postgresql17', 'mysql8411')
    assert_equal 64, status.exitstatus
  end

  private

  def confirmed_environment
    {
      Harness::CONFIRMATION_ENV => Harness::CONFIRMATION,
      'SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => '',
      'DB_URL' => '',
    }
  end

  def harness(environment)
    Harness.new(engine: 'postgresql17', environment: environment)
  end
end
