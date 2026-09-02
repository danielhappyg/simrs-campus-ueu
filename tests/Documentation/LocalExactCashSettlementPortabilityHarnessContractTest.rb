# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-exact-cash-settlement-portability'

class LocalExactCashSettlementPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalExactCashSettlementPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['exact-cash-settlement-portability-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 12_000
  ensure
    worker&.close!
  end

  def test_closed_scenario_catalogue_matches_exact_cash_settlement_scope
    assert_equal %w[
      fresh-migration empty-down-reapply migration-guard-reinstall-after-failure
      exact-cashier-runtime-grants
      database-check-and-append-only-trigger-refusal exact-server-derived-cash-amount
      same-key-same-payload-concurrency changed-payload-idempotency-conflict
      stale-bill-version-refusal new-source-pending-refusal duplicate-settlement-refusal
      cumulative-version-outstanding-balance audit-failure-atomic-rollback
      third-connection-single-durable-settlement
      retained-evidence-migration-rollback-refusal bounded-reset-recovery strict-cleanup
    ], Harness::SCENARIOS
    assert_equal 17, Harness::SCENARIOS.length
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
  end

  def test_exact_engines_migration_sources_and_hash_catalogues_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php', Harness::MIGRATION_PATH
    assert_equal 'SIMRS_LOCAL_EXACT_CASH_SETTLEMENT_PORTABILITY', Harness::EVIDENCE_KIND
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    binding = harness.current_exact_cash_settlement_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 application_source_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
    assert_equal binding.fetch('aggregate_sha256'), binding.fetch('application_source_sha256')
    %w[
      app/Support/Finance/FinanceCashSettlementService.php
      app/Support/Finance/FinanceCashSettlementProjection.php
      app/Support/Finance/FinanceCashSettlementFingerprint.php
      app/Support/Finance/FinanceSourceCoordinator.php
      app/Support/Simulation/SyntheticResetService.php
      app/Support/Operations/SyntheticRecoverySnapshot.php
    ].each { |path| assert_includes Harness::SOURCE_PATHS, path }
  end

  def test_feature_bindings_cover_denials_audit_and_database_guards
    Harness::FEATURE_SCENARIO_TESTS.each do |scenario, (path, method)|
      assert_includes Harness::SCENARIOS, scenario
      assert_includes Harness::SOURCE_PATHS, path
      assert_includes File.read(File.join(Harness::ROOT, path), encoding: Encoding::UTF_8), "function #{method}"
    end
    assert_equal Harness::FEATURE_SCENARIO_TESTS.values.uniq, Harness::SQLITE_FEATURE_TESTS
    assert_operator @source.index('sqlite_gate = run_sqlite_gate!'), :<, @source.index('engine_binding = prepare_engine!')
    sqlite = harness.sqlite_application_environment
    assert_equal 'sqlite', sqlite.fetch('DB_CONNECTION')
    assert_equal ':memory:', sqlite.fetch('DB_DATABASE')
  end

  def test_real_wait_pair_and_third_connection_single_row_reconciliation_are_required
    race = @worker.split('function settleRace', 2).last.split('function verifyDurable', 2).first
    verify = @worker.split('function verifyDurable', 2).last.split('function privilegeGuards', 2).first
    assert_includes race, "lockForUpdate()->sole()"
    assert_includes race, "protocol('HOLDING'"
    assert_includes race, "'lock_order_trace' => ['encounters']"
    assert_includes race, 'FinanceCashSettlementService::class'
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, "outcomes == %w[APPLIED REPLAYED]"
    assert_includes @source, "'third_connection_reconciliation_required' => true"
    assert_includes verify, "FinanceCashSettlement::query()->orderBy('bill_version_snapshot')->get()"
    assert_includes verify, "FinanceSettlementOperationReceipt::query()->orderBy('id')->get()"
    assert_includes verify, "'current_version_settlements' => 1"
    assert_includes verify, "'two exact settlement success audits'"
    assert_includes verify, "'exact bill version binding'"
    assert_includes verify, "'no cumulative double charge'"
    assert_includes verify, "'replay receipt bill-version public-id integrity'"
  end

  def test_exact_engine_catalogues_check_constraints_and_append_only_triggers
    schema = @worker.split('function schemaGuards', 2).last.split('function verifyDurable', 2).first
    assert_includes schema, "['fcs_exact_cash_ck', 'fsor_result_ck']"
    assert_includes schema, "information_schema.table_constraints"
    assert_includes schema, "information_schema.triggers"
    assert_includes schema, "'finance_cash_settlements'"
    assert_includes schema, "'finance_settlement_operation_receipts'"
    assert_includes schema, "'append-only update and delete triggers installed'"
    assert_includes @source, "action: 'schema_guards'"
    reinstall = @worker.split('function guardReinstallAfterFailedUp', 2).last.split('function verifyDurable', 2).first
    assert_includes reinstall, "2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php"
    assert_includes reinstall, '$migration->up()'
    assert_includes reinstall, "'duplicate migration up must fail'"
    assert_includes reinstall, "'append-only guards reinstalled after failed migration up'"
    assert_includes reinstall, "'guard_reinstalled' => true"
  end

  def test_exact_cashier_runtime_grants_are_read_back_and_fail_closed
    Harness::RUNTIME_READ_TABLES.each do |table|
      expected = ['SELECT']
      expected << 'INSERT' if Harness::RUNTIME_INSERT_TABLES.include?(table)
      expected << 'UPDATE' if Harness::RUNTIME_LOCK_TABLES.include?(table)
      expected = expected.join(', ')
      assert_equal expected, Harness::RUNTIME_TABLE_GRANTS.fetch(table)
    end
    refute Harness::RUNTIME_TABLE_GRANTS.values.any? { |grant| grant.match?(/DELETE|DROP|ALTER|CREATE/) }
    assert_equal %w[finance_cash_settlements finance_settlement_operation_receipts audit_events], Harness::RUNTIME_INSERT_TABLES
    assert_equal %w[encounters finance_bills finance_cash_settlements], Harness::RUNTIME_LOCK_TABLES
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'role_table_grants'
    assert_includes @source, 'SHOW GRANTS FOR'
    assert_includes @source, 'information_schema.TABLE_PRIVILEGES'
    assert_includes @source, "GROUP_CONCAT(PRIVILEGE_TYPE ORDER BY PRIVILEGE_TYPE SEPARATOR ',')"
    assert_includes @worker, "'delete_privilege_denials' => 4"
    assert_includes @worker, "'database_guard_update_denials' => 2"
    assert_includes @worker, "'runtime lock grants remain database-guarded against mutation'"
    privilege = @worker.split('function privilegeGuards', 2).last.split('function resetAndVerify', 2).first
    assert_includes privilege, 'FinanceMutationScope::run'
    assert_includes privilege, 'catch (QueryException)'
    refute_includes privilege, 'catch (LogicException)'
  end

  def test_reset_rollback_cleanup_and_open_governance_boundary_are_explicit
    assert_includes @worker, 'function resetAndVerify'
    assert_includes @worker, "'settlement reset'"
    assert_includes @worker, "'settlement receipt reset'"
    assert_includes @worker, "'dependent finance bill reset'"
    assert_includes @worker, "'reset audit preserved'"
    assert_includes @source, 'expect_settlement_rollback_refusal!'
    assert_includes @source, 'refusingtodiscardcashsettlementtableswhilecorrelatedauditevidenceremains'
    assert_includes @source, 'refusingtodiscardpopulatedcashsettlementevidence'
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'cleanup!(strict: true)'
    assert_includes @source, "'owner_acceptance_claim' => false"
    assert_includes @source, "'g0_claim' => false"
    assert_includes @source, "'g3_claim' => false"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
  end

  def test_inherited_database_wait_diagnostics_hash_queries
    parent = File.read(File.join(Harness::ROOT, 'scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb'), encoding: Encoding::UTF_8)
    assert_includes parent, "md5(coalesce(query, ''))"
    assert_includes parent, "'query_sha256' => $query ? hash('sha256', $query->getSql()) : null"
    assert_includes @worker, "'query_sha256' => $query ? hash('sha256', $query->getSql()) : null"
    refute_includes @worker, 'query_head'
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
