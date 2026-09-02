# frozen_string_literal: true

require 'minitest/autorun'

class LocalInpatientDocumentationPortabilityHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/rehearse-local-inpatient-documentation-portability.rb')
  WORKER = File.join(ROOT, 'app/Console/Commands/RehearseInpatientDocumentationRaceWorkerCommand.php')

  def setup
    @script = File.read(SCRIPT, encoding: Encoding::UTF_8)
    @worker = File.read(WORKER, encoding: Encoding::UTF_8)
  end

  def test_script_and_dedicated_worker_are_syntactically_valid
    assert system('ruby', '-c', SCRIPT, out: File::NULL, err: File::NULL)
    assert system('/opt/homebrew/bin/php', '-l', WORKER, out: File::NULL, err: File::NULL)
  end

  def test_closed_catalogue_distinguishes_observed_races_from_sequential_state_proofs
    %w[
      same-author-service-day-concurrent-create
      same-expected-version-update
      identical-idempotency-replay
      conflicting-idempotency-replay
    ].each do |scenario|
      assert_includes @script, "'#{scenario}'"
      assert_includes @worker, "'#{scenario}'"
    end

    %w[
      multi-author-same-day-independent-heads
      immutable-placement-snapshot-after-managed-rename
      audit-failure-atomic-rollback
      reset-retains-audit-evidence
      empty-down-reapply
      populated-and-correlated-audit-down-refusal
    ].each { |scenario| assert_includes @script, "'#{scenario}'" }

    assert_includes @script, "'proof_kind' => 'OBSERVED_DATABASE_RACE'"
    assert_includes @script, "'proof_kind' => 'SEQUENTIAL_DURABLE_STATE_PROOF'"
  end

  def test_races_use_two_application_processes_native_wait_observation_and_third_connection
    assert_includes @script, 'Open3.popen3'
    assert_includes @script, "'independent_application_processes' => 2"
    assert_includes @script, 'observe_real_database_wait!'
    assert_includes @script, 'pg_blocking_pids'
    assert_includes @script, 'performance_schema.data_lock_waits'
    assert_includes @script, "action: 'verify'"
    assert_includes @worker, "'durable_third_connection_assertions' => true"
    assert_includes @worker, 'engineNativeSleep'
  end

  def test_exact_engines_and_closed_local_synthetic_boundary_are_enforced
    assert_includes @script, 'postgresql17'
    assert_includes @script, 'mysql8411'
    assert_includes @worker, "'170010'"
    assert_includes @worker, "'8.4.11'"
    assert_includes @worker, "config('simulation.mode') !== 'SIMULATION'"
    assert_includes @worker, "config('simulation.synthetic_only') !== true"
    assert_includes @worker, "['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED']"
    assert_includes @worker, "getenv($key) !== 'false'"
  end

  def test_source_catalog_worker_commands_and_results_are_hash_bound
    assert_includes @script, "'source_bindings'"
    assert_includes @script, "'scenario_catalog_sha256'"
    assert_includes @script, "'worker_sha256'"
    assert_includes @script, "'command_catalog_sha256'"
    assert_includes @script, "'result_catalog_sha256'"
    assert_includes @script, 'Digest::SHA256.file'
    assert_includes @script, 'Digest::SHA256.hexdigest'
    %w[
      app/Support/Inpatient/InpatientDocumentationMutationResult.php
      app/Support/Inpatient/InpatientDocumentationActorPolicy.php
      app/Support/Inpatient/InpatientDocumentationMutationScope.php
      app/Support/Inpatient/InpatientDocumentationSchemaMutationScope.php
      app/Support/Audit/AuditEventSchemaRegistry.php
      app/Support/Audit/AuditRecorder.php
      app/Providers/AppServiceProvider.php
      app/Support/Database/SchemaQualifier.php
    ].each { |path| assert_includes @script, "'#{path}'" }
  end

  def test_evidence_retains_sanitized_commands_ordered_worker_results_and_target_counts
    assert_includes @script, "'command_catalog' => @command_catalog"
    assert_includes @script, "'protocol_result_catalog' => @protocol_catalog"
    assert_includes @script, "'--run-token=<generated-redacted>'"
    assert_includes @script, "'exit_status' => 'PASS'"
    assert_includes @script, "'protocol_order'"
    assert_includes @script, 'NATIVE_WAIT_OBSERVED'
    assert_includes @script, "'durable_assertions' => verified.fetch('assertion_catalog')"
    assert_includes @worker, "'head_count' => 1"
    assert_includes @worker, "'version_rows'"
    assert_includes @worker, "'receipt_rows'"
    assert_includes @worker, "'success_audit_rows'"
    assert_includes @worker, "'denial_audit_rows'"
    refute_includes @script, "'backend_connection_id' =>"
  end

  def test_sequential_proofs_cover_final_rollback_full_snapshot_and_exact_author_tuples
    assert_includes @worker, "'operation' => 'FINALIZE'"
    assert_includes @worker, "'head_state' => 'DRAFT'"
    assert_includes @worker, "'encounter_status' => 'REGISTERED'"
    assert_includes @worker, "'finalize_success_audit_rows' => 0"
    assert_includes @worker, "'finalize_denial_audit_rows' => 0"
    assert_includes @worker, "'display_room_class_snapshots_checked' => true"
    assert_includes @worker, "'ward_public_id_immutable' => true"
    assert_includes @worker, "'bed_public_id_immutable' => true"
    assert_includes @worker, "'exact_author_type_service_day_tuples_unique' => true"
    assert_includes @worker, "'receipt_rows' => 3"
    assert_includes @worker, "'success_audit_rows' => 3"
  end

  def test_cleanup_is_strict_before_sanitized_mode_0600_evidence
    cleanup = @script.index('cleanup!(strict: true)')
    evidence = @script.index('write_documentation_evidence!')
    refute_nil cleanup
    refute_nil evidence
    assert_operator cleanup, :<, evidence
    assert_includes @script, 'validate_evidence_payload!'
    assert_includes @script, 'File.chmod(0o600, path)'
    assert_includes @script, "'database_removed' => true"
    assert_includes @script, "'temporary_server_removed' => true"
    refute_match(/DB_PASSWORD|PGPASSWORD|MYSQL_PWD/, @script.split("'source_bindings'", 2).last)
  end

  def test_down_and_reset_catalogue_are_real_operations_not_claims
    assert_includes @script, "'migrate:rollback'"
    assert_includes @script, "'migrate'"
    assert_includes @worker, 'SyntheticResetService'
    assert_includes @worker, 'reset('
    assert_includes @worker, "'inpatient_clinical_documents'"
    assert_includes @worker, 'AuditEvent::query()'
  end
end
