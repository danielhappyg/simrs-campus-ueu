# frozen_string_literal: true

require 'digest'
require 'json'
require 'minitest/autorun'

class LocalAppendOnlyCashierCollectionEvidenceRecordTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  RECORD_PATH = 'docs/operations/T1_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_EVIDENCE_2026-09-03.md'
  TEMPLATE_PATH = 'docs/operations/T1_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_EVIDENCE_TEMPLATE_2026-09-03.md'
  EVIDENCE = {
    'postgresql17' => [
      'storage/app/portability-rehearsals/20260902T190119Z-postgresql17-cashier-collection-25a08bba6ea3.json',
      '2665e91ff3573a2fedd184daa96bdb3757ca63fe5c07c19a5c8d67809ac7becf',
    ],
    'mysql8411' => [
      'storage/app/portability-rehearsals/20260902T190331Z-mysql8411-cashier-collection-22dbbe7ff187.json',
      '4c3923cfda495b700316d407b149e33c8e74c41674b0bbc6eca21a88238e40d3',
    ],
  }.freeze
  SHARED_BINDINGS = {
    'application_source_sha256' => '90e15b44c88469eadd4968087d1e44d84784276039c6f81d5f3fefce5884e6e0',
    'worker_source_sha256' => '43315f727c4ec59ef937b5bf4fd0cfdff2b3a329473e3aeb11d4f353e51fad9f',
    'scenario_catalog_sha256' => '392165504ae0959fba4b1d812cba32e58a5978ed6210c3485463582a7bfa3e63',
    'runtime_grant_catalog_sha256' => '26002b75b3609bc9c5247bf24b71773c1118e5a30836b9d95547eaa6480f2376',
    'sqlite_gate_catalog_sha256' => '64bd0a9b5320d47039c21c9eac3d01b71088716dcf917300ebfe5fe35189a783',
    'command_catalog_sha256' => 'b7a6b888fa4a0e831a45bc650d0e0645f1c98750ccf7aee327fb1a80e50ced78',
  }.freeze
  RESULT_BINDINGS = {
    'postgresql17' => {
      'sqlite_gate' => '2a87ac50ae34e081c19e4d94bda014578bdbd1fe19421900095b821b07f41c24',
      'source_bindings' => 'e526a54302ad5204cf7c8c98d3aaae770b9c171dc98ff8a34abdc1168bfc15b8',
    },
    'mysql8411' => {
      'sqlite_gate' => '33ee3a38d97caa98d28bb8cc1c9f191fd69133cb62b9fd994f6c17f807c4c845',
      'source_bindings' => '7cd9069e8203c0cb01f63180115b09ef229ed4df230a3b1497720cf58844e1bb',
    },
  }.freeze
  SCENARIOS = %w[
    fresh-migration empty-down-reapply migration-failure-guard-reinstall
    failed-install-preserves-lifetime-uniqueness shortened-identifier-inventory
    exact-runtime-grants exact-role-denials runtime-reset-bypass-denial
    owner-only-bounded-reset database-check-constraints database-append-only-refusals
    real-settlement-v-close-wait real-refund-completion-v-close-wait
    concurrent-same-key-replay double-close-refusal double-verify-refusal
    double-handoff-refusal third-connection-membership-net-cash-readback
    audit-failure-atomic-rollback recovery-tamper-detection
    retained-evidence-rollback-refusal strict-cleanup
  ].freeze

  def setup
    @record = File.read(File.join(ROOT, RECORD_PATH), encoding: Encoding::UTF_8)
    @documents = EVIDENCE.to_h do |engine, (path, expected_sha)|
      full_path = File.join(ROOT, path)
      assert_equal expected_sha, Digest::SHA256.file(full_path).hexdigest, path
      assert_equal 0o600, File.stat(full_path).mode & 0o777, path
      [engine, JSON.parse(File.read(full_path, encoding: Encoding::UTF_8))]
    end
  end

  def test_pair_is_complete_and_bound_to_current_identical_sources
    @documents.each_value do |document|
      assert_equal 'PASS', document.fetch('status')
      assert_equal 'SIMRS_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_PORTABILITY', document.fetch('kind')
      assert_equal SCENARIOS, document.fetch('scenarios').keys
      assert document.fetch('scenarios').values.all? { |scenario| scenario.fetch('status') == 'PASS' }
      assert document.fetch('scenarios').values.all? { |scenario| !scenario.fetch('proof_kind').empty? }
      assert_equal 'PASS', document.dig('sqlite_gate', 'status')
      assert_equal 7, document.dig('sqlite_gate', 'test_count')
    end

    file_bindings = @documents.values.map { |document| document.dig('source_bindings', 'files') }
    assert_equal 1, file_bindings.uniq.length
    file_bindings.first.each do |path, expected_sha|
      assert_equal expected_sha, Digest::SHA256.file(File.join(ROOT, path)).hexdigest, path
    end
    aggregate = Digest::SHA256.hexdigest(JSON.generate(file_bindings.first.sort.to_h))
    assert_equal SHARED_BINDINGS.fetch('application_source_sha256'), aggregate

    SHARED_BINDINGS.each do |key, expected_sha|
      values = @documents.values.map { |document| document.fetch('source_bindings').fetch(key) }
      assert_equal [expected_sha], values.uniq, key
      assert_includes @record, expected_sha
    end
  end

  def test_record_binds_artifacts_versions_and_four_independent_results
    EVIDENCE.each_value do |path, sha|
      assert_includes @record, path
      assert_includes @record, sha
    end
    assert_includes @record, 'PostgreSQL 17.10'
    assert_includes @record, 'MySQL 8.4.11 / InnoDB'
    assert_includes @record, '22/22'

    result_hashes = []
    RESULT_BINDINGS.each do |engine, expected|
      document = @documents.fetch(engine)
      assert_equal expected.fetch('sqlite_gate'), document.dig('sqlite_gate', 'result_catalog_sha256')
      assert_equal expected.fetch('source_bindings'), document.dig('source_bindings', 'result_catalog_sha256')
      expected.each_value do |sha|
        result_hashes << sha
        assert_includes @record, sha
      end
    end
    assert_equal 4, result_hashes.uniq.length
    assert_includes @record, 'RECORDED INDEPENDENTLY'
  end

  def test_real_waits_lock_order_equations_and_terminal_refusals_are_exact
    expected_lock_order = %w[finance_cashier_collection_active_slots finance_cashier_collection_batches]
    @documents.each_value do |document|
      scenarios = document.fetch('scenarios')
      %w[real-settlement-v-close-wait real-refund-completion-v-close-wait concurrent-same-key-replay].each do |name|
        wait = scenarios.fetch(name)
        assert_equal true, wait.fetch('real_database_wait_observed')
        assert_equal 2, wait.fetch('independent_application_processes')
        assert_equal expected_lock_order, wait.fetch('observed_lock_order')
        assert_equal false, wait.fetch('deadlock_observed')
      end
      settlement = scenarios.fetch('real-settlement-v-close-wait')
      assert_equal %w[SETTLEMENT_COMMITTED STALE_COLLECTION_BATCH_REFUSED], settlement.fetch('outcomes')
      assert_equal 'CLOSED', settlement.dig('retry', 'outcome')
      assert_equal %w[CLOSED REFUND_COMPLETED], scenarios.dig('real-refund-completion-v-close-wait', 'outcomes')
      final_state = scenarios.dig('real-refund-completion-v-close-wait', 'final_state')
      assert_equal [1, 9000, 9000, 0, 0, 0], final_state.values_at(
        'membership_count', 'gross_amount', 'completed_refund_amount',
        'expected_net_amount', 'counted_amount', 'variance_amount'
      )
      assert_equal true, final_state.fetch('durable_membership_and_event_integrity')
      assert_equal %w[CLOSED REPLAYED], scenarios.dig('concurrent-same-key-replay', 'outcomes')

      terminal = scenarios.dig('double-handoff-refusal', 'worker')
      assert_equal true, terminal.fetch('double_close_refused')
      assert_equal true, terminal.fetch('double_verify_refused')
      assert_equal true, terminal.fetch('double_handoff_refused')
    end
  end

  def test_constraints_third_connection_recovery_reset_and_audit_are_bound
    @documents.each_value do |document|
      scenarios = document.fetch('scenarios')
      schema = scenarios.dig('shortened-identifier-inventory', 'worker')
      assert_equal %w[fccm_values_ck fcce_values_ck fcdh_values_ck fccor_result_ck], schema.fetch('checks')
      assert_equal %w[fccb fccm fcce fcdh fccor], schema.fetch('short_trigger_bases')

      constraints = scenarios.dig('database-check-constraints', 'exact_worker')
      assert_equal 'NATIVE_CHECK_REJECTIONS_PROVEN', constraints.fetch('outcome')
      %w[fcce_values_ck fccor_result_ck].each do |name|
        assert_equal name, constraints.dig(name, 'constraint')
        assert_equal false, constraints.dig(name, 'query_text_recorded')
        refute_empty constraints.dig(name, 'sql_state')
        refute_empty constraints.dig(name, 'diagnostic_fingerprint')
      end
      assert_equal 0, constraints.fetch('failed_probe_rows_retained')
      assert_equal 0, constraints.fetch('healthy_recovery_mismatch_count')

      readback = scenarios.dig('third-connection-membership-net-cash-readback', 'worker')
      assert_equal true, readback.fetch('independent_connection')
      assert_equal({ 'members' => 2, 'events' => 1, 'gross' => 18_000, 'refunded' => 0, 'net' => 18_000 }, readback.dig('batches', 'settlement_race'))
      assert_equal({ 'members' => 1, 'events' => 1, 'gross' => 9000, 'refunded' => 9000, 'net' => 0 }, readback.dig('batches', 'refund_race'))
      assert_equal({ 'members' => 1, 'events' => 2, 'gross' => 7000, 'refunded' => 0, 'net' => 7000 }, readback.dig('batches', 'same_key'))
      assert_equal 0, readback.fetch('recovery_mismatches')

      recovery = scenarios.dig('recovery-tamper-detection', 'exact_worker')
      assert_equal true, recovery.fetch('member_tamper_detected')
      assert_equal true, recovery.fetch('rollback_restored')
      assert_equal 'test_audit_failure_rolls_back_settlement_and_operation_receipt', scenarios.dig('audit-failure-atomic-rollback', 'method')

      reset = scenarios.dig('owner-only-bounded-reset', 'exact_worker')
      assert_equal true, reset.fetch('installer_owner_bypass_proven')
      assert_equal true, reset.fetch('audit_evidence_preserved')
      assert_equal 2, reset.fetch('reset_audit_events')
      assert_equal true, reset.fetch('audit_count_not_decreased')
      assert_equal 'PASS', scenarios.dig('retained-evidence-rollback-refusal', 'status')
    end
  end

  def test_runtime_secrecy_cleanup_and_governance_boundaries_remain_closed_or_open_as_required
    @documents.each_value do |document|
      runtime = document.dig('scenarios', 'exact-runtime-grants', 'worker')
      assert_equal 3, runtime.fetch('delete_update_truncate_denials')
      assert_equal true, runtime.fetch('session_bypass_escalation_denied')
      assert_equal true, runtime.fetch('runtime_reset_denied')

      assert document.fetch('cleanup').values.all?
      assert_equal 'SIMULATION', document.dig('boundary', 'application_mode')
      assert_equal true, document.dig('boundary', 'synthetic_only')
      assert_equal false, document.dig('boundary', 'live_integrations_enabled')
      assert_equal false, document.dig('boundary', 'treasury_acceptance_claim')
      %w[owner_acceptance_claim g0_claim g3_claim deployment_claim hosted_readiness_claim].each do |claim|
        assert_equal false, document.fetch(claim)
      end
      assert document.fetch('open_boundaries').any? { |boundary| boundary.include?('G0 and G3 remain OPEN') }

      serialized = JSON.generate(document)
      %w[DB_PASSWORD DATABASE_URL AUTHORIZATION_COOKIE plaintext_secret bearer_token access_token refresh_token].each do |secret_marker|
        refute_includes serialized, secret_marker
      end
    end
    assert_includes @record, 'G0 and G3 remain **OPEN**'
    assert_includes @record, 'local engineering portability evidence only'
    assert_includes @record, 'not product-owner, cashier/revenue, finance-accounting, treasury, or facility acceptance'
  end

  def test_shared_backend_static_format_and_frontend_gates_are_recorded_exactly
    [
      '1,014 total, 1,010 passed, 4 intentional skips, 21,783 assertions, 45.100s',
      'PHPStan configured `app` scope | PASS — 0 errors',
      'Full Pint formatting | PASS — including ordered-import formatting',
      'Frontend Vitest | PASS — 32 files, 211 tests, 39.24s',
      'ESLint check | PASS',
      'Prettier `resources` check | PASS',
      'TypeScript check | PASS',
      'Vite production build | PASS — 2,409 modules, 8.64s',
    ].each { |result| assert_includes @record, result }
  end

  def test_not_run_template_remains_separate_and_truthful
    template = File.read(File.join(ROOT, TEMPLATE_PATH), encoding: Encoding::UTF_8)
    assert_includes template, 'READY / NOT RUN'
    assert_includes template, 'This template is not execution evidence.'
    refute_equal Digest::SHA256.file(File.join(ROOT, TEMPLATE_PATH)).hexdigest,
                 Digest::SHA256.file(File.join(ROOT, RECORD_PATH)).hexdigest
  end
end
