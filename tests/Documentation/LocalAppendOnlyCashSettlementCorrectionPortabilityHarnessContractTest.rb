# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-append-only-cash-settlement-correction-portability'

class LocalAppendOnlyCashSettlementCorrectionPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalAppendOnlyCashSettlementCorrectionPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['cash-settlement-correction-portability-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 12_000
  ensure
    worker&.close!
  end

  def test_closed_catalogue_covers_every_authorized_exact_engine_scenario
    assert_equal %w[
      fresh-migration empty-down-reapply migration-failure-guard-reinstall
      failed-install-preserves-lifetime-uniqueness
      exact-runtime-grants exact-role-denials database-check-and-append-only-refusal
      request-reject request-approve-refund-complete-replacement
      idempotent-replay changed-payload-conflict real-wait-single-durable-case
      double-review-refusal double-refund-refusal
      audit-failure-atomic-rollback predecessor-refund-net-cash-snapshot
      refund-receipt-integrity recovery-relevant-column-presence
      retained-evidence-rollback-refusal third-connection-net-cash-readback
      bounded-reset strict-cleanup
    ], Harness::SCENARIOS
    assert_equal 22, Harness::SCENARIOS.length
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
  end

  def test_real_competing_process_wait_is_observed_not_sequentially_simulated
    race = @worker.split('function requestRace', 2).last.split('function completeAndReplace', 2).first
    assert_includes race, "lockForUpdate()->sole()"
    assert_includes race, "protocol('HOLDING'"
    assert_includes race, "'lock_order_trace' => ['encounters']"
    assert_includes @source, 'Open3.popen3'
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, "outcomes == %w[APPLIED REPLAYED]"
    assert_includes @source, "'independent_application_processes' => 2"
  end

  def test_migration_failure_preserves_old_uniques_and_reinstalls_guards
    failure = @worker.split('function failureSafety', 2).last.split('function privilegeGuards', 2).first
    assert_includes failure, '$migration->down()'
    assert_includes failure, 'forced installation failure'
    assert_includes failure, "'fcs_bill_version_uq'"
    assert_includes failure, "'fcs_bill_version_number_uq'"
    assert_includes failure, 'guards reinstalled after failed up'
    assert_includes failure, "'guard_reinstalled' => true"
    assert_includes failure, '$migration->up()'
  end

  def test_constraints_triggers_least_privilege_and_role_denials_are_bound
    assert_includes @worker, "['fscc_values_ck', 'fsce_transition_ck', 'fscor_result_ck', 'fcs_prior_net_snapshot_ck']"
    assert_includes @worker, 'information_schema.table_constraints'
    assert_includes @worker, 'information_schema.triggers'
    assert_includes @worker, "['fscor_immutable', 'fscor_truncate_guard']"
    assert_includes @worker, "['fscor_immutable_update', 'fscor_immutable_delete']"
    assert_includes @worker, "'fscor_public_id_uq'"
    refute Harness::RUNTIME_TABLE_GRANTS.values.any? { |grant| grant.match?(/DELETE|DROP|ALTER|CREATE/) }
    assert_includes @source, 'role_table_grants'
    assert_includes @source, 'SHOW GRANTS FOR'
    assert_includes @source, 'information_schema.TABLE_PRIVILEGES'
    assert_includes Harness::FEATURE_SCENARIO_TESTS.keys, 'exact-role-denials'
    assert_includes @worker, "'delete_privilege_denials' => 2"
    assert_includes @worker, "'database_guard_update_denials' => 1"
    assert_includes @worker, "SET LOCAL simrs.synthetic_reset = '1'"
    assert_includes @worker, 'SET @simrs_synthetic_reset = 1'
    assert_includes @worker, "'session_bypass_escalation_denied' => true"
  end

  def test_terminal_refusals_receipt_and_third_connection_net_cash_are_explicit
    terminal = @worker.split('function completeAndReplace', 2).last.split('function verifyNetCash', 2).first
    verify = @worker.split('function verifyNetCash', 2).last.split('function schemaGuards', 2).first
    assert_includes terminal, "'review_already_completed'"
    assert_includes terminal, "'refund_not_approved'"
    assert_includes terminal, 'refundReceipt'
    assert_includes terminal, 'prior_net_collected_amount_snapshot = 0'
    assert_includes verify, "'net cash readback'"
    assert_includes verify, "'replacement prior net snapshot'"
    assert_includes verify, "'independent_connection' => true"
    assert_includes verify, "'receipt_integrity' => true"
    assert_includes @source, "worker: 'THIRD_CONNECTION'"
    recovery = @worker.split('function recoveryAuditProof', 2).last.split('function schemaGuards', 2).first
    assert_includes recovery, "'healthy same-version replacement audit recovery'"
    assert_includes recovery, "'deleted audit cannot be masked by sibling settlement'"
    assert_includes recovery, "'tampered audit cannot be masked by sibling settlement'"
    assert_includes recovery, "'independent_delete_or_tamper_probes'"
  end

  def test_rollback_reset_cleanup_and_local_only_boundaries_are_explicit
    assert_includes @source, 'expect_correction_rollback_refusal!'
    assert_includes @worker, 'function resetAndVerify'
    assert_includes @worker, 'SyntheticResetService::class'
    assert_includes @worker, "'audit_evidence_preserved' => true"
    assert_includes @worker, "'installer_owner_bypass_proven' => true"
    assert_includes @source, "worker: 'INSTALLER_OWNER_RESET'"
    assert_includes @source, 'cleanup!(strict: true)'
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, "'deployment_claim' => false"
    assert_includes @source, "'owner_acceptance_claim' => false"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
  end

  def test_exact_sources_and_hash_catalogues_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'SIMRS_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_PORTABILITY', Harness::EVIDENCE_KIND
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    binding = harness.current_correction_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 application_source_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
  end

  def test_external_database_overrides_are_refused
    error = assert_raises(Harness::CommandFailed) do
      Harness.new(engine: 'postgresql17', environment: confirmed_environment.merge('DB_PASSWORD' => 'refused')).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_PASSWORD/, error.message)
  end

  private

  def php_binary
    ['/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'].find { |path| File.executable?(path) } || 'php'
  end

  def confirmed_environment
    { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness
    Harness.new(engine: 'postgresql17', environment: confirmed_environment)
  end
end
