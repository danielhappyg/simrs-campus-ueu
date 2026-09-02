# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-finance-tariff-component-master-portability'

class LocalFinanceTariffComponentMasterPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalFinanceTariffComponentMasterPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['finance-tariff-component-master-portability-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 18_000
  ensure
    worker&.close!
  end

  def test_closed_twenty_scenario_catalogue_matches_the_authorized_scope
    assert_equal %w[
      fresh-migration empty-down-reapply exact-finance-steward-role-boundary
      cashier-admin-mixed-denial stable-code-reservations integer-rupiah-refusal
      current-effective-resolution future-authored-today-effective half-open-effective-boundary
      terminal-retirement-history upstream-retirement-refusal idempotent-replay-key-conflict
      stale-and-concurrent-writer-refusal application-sql-guard-refusal
      database-mutable-head-refusal database-append-only-update-delete-truncate-refusal
      audit-and-corruption-refusal least-privilege-runtime
      bounded-reset-recovery-audit-preservation
      retained-evidence-rollback-refusal-and-strict-cleanup
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    assert_equal 20, Harness::SCENARIOS.length
    refute_includes @source, 'SCENARIOS.to_h'
  end

  def test_exact_engines_migration_execution_state_and_closed_sha_bindings
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_09_02_000100_create_governed_finance_tariff_component_master.php', Harness::MIGRATION_PATH
    assert_equal 'SIMRS_LOCAL_GOVERNED_FINANCE_TARIFF_COMPONENT_MASTER_PORTABILITY', Harness::EVIDENCE_KIND
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    binding = harness.current_tariff_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[
      aggregate_sha256 application_source_sha256 worker_source_sha256
      scenario_catalog_sha256 runtime_grant_catalog_sha256
    ].each { |key| assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key)) }
    assert_equal binding.fetch('aggregate_sha256'), binding.fetch('application_source_sha256')
    assert_includes Harness::SOURCE_PATHS, Harness::MIGRATION_PATH
    assert_includes Harness::SOURCE_PATHS, Harness::CONTRACT_PATH
    assert_includes Harness::SOURCE_PATHS, Harness::EVIDENCE_TEMPLATE_PATH
    assert_includes Harness::SOURCE_PATHS, 'docs/new-simrs-rebuild/phase-1/GOVERNED_EFFECTIVE_DATED_FINANCE_TARIFF_COMPONENT_MASTER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
  end

  def test_real_service_worker_covers_empty_master_roles_codes_and_integer_money
    %w[
      FinanceTariffActorPolicy FinanceTariffMasterService FinanceTariffProjection
      FinanceTariffCodeReservation FinanceTariffOperationReceipt
    ].each { |name| assert_includes @worker, name }
    assert_includes @worker, 'fresh master empty'
    assert_includes @worker, 'exact finance steward manages'
    assert_includes @worker, 'cashier is read only'
    assert_includes @worker, 'admin system mixed non bypass'
    assert_includes @worker, 'four stable code reservations'
    assert_includes @worker, 'floating-point rupiah refusal expected'
    assert_includes @worker, '1000.5'
    assert_includes @worker, "expectDenied('idempotency_key_conflict'"
    assert_includes @worker, "expectDenied('validation_failed'"
  end

  def test_effective_dated_semantics_are_exercised_with_real_resolution_and_history
    assert_includes @worker, 'resolveEffectiveTariff'
    assert_includes @worker, 'today remains version one'
    assert_includes @worker, 'half open boundary selects version two'
    assert_includes @worker, 'effective and latest authored distinguished'
    assert_includes @worker, 'history survives retirement authoring'
    assert_includes @worker, 'retirement boundary unselectable'
    assert_includes @worker, 'half open history intervals'
    assert_includes @worker, 'Pensiun komponen setelah tarif.'
    assert_includes @worker, 'Pensiun katalog setelah tarif.'
    assert_includes @worker, 'Pensiun group setelah komponen.'
    assert_includes @worker, "expectDenied('dependent_tariffs_remain'"
  end

  def test_competing_writer_uses_two_processes_real_row_lock_and_durable_outcomes
    assert_includes @source, 'start_tariff_race_worker!'
    assert_includes @source, 'await_protocol!'
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @source, "outcomes == %w[APPLIED DENIED]"
    assert_includes @worker, 'backendConnectionId'
    assert_includes @worker, 'lockForUpdate'
    assert_includes @worker, "protocol('HOLDING'"
    assert_includes @worker, 'finance_cost_component_groups'
    assert_includes @worker, "expectDenied('stale_version'"
  end

  def test_runtime_grants_encode_mutable_heads_and_append_only_evidence
    Harness::RUNTIME_READ_TABLES.each { |table| assert_equal 'SELECT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::MUTABLE_HEAD_TABLES.each { |table| assert_equal 'SELECT, INSERT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::IMMUTABLE_EVIDENCE_TABLES.each { |table| assert_equal 'SELECT, INSERT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    refute Harness::RUNTIME_TABLE_GRANTS.values.any? { |grants| grants.include?('DELETE') }
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'information_schema.role_table_grants'
    assert_includes @source, 'SHOW GRANTS FOR'
    assert_includes @source, 'schema wildcard grant'
    assert_includes @worker, 'forbidden_operations_refused'
  end

  def test_application_database_audit_corruption_recovery_reset_and_rollback_are_real
    %w[
      applicationGuards databaseGuards corruptionRefusal privilegeGuards
      tariffInvariants resetAndVerify
    ].each { |method| assert_includes @worker, "function #{method}" }
    assert_includes @worker, 'FinanceTariffSqlWriteGuard'
    assert_includes @worker, 'FinanceTariffAppendOnlyGuard::runSyntheticReset'
    assert_includes @worker, "expectDenied('receipt_corrupt'"
    assert_includes @worker, 'post-reset tariff master graph empty'
    assert_includes @worker, 'reset audit evidence preserved'
    assert_includes @source, 'expect_tariff_rollback_refusal!'
    assert_includes @source, 'correlatedauditevidenceremains'
    assert_includes @source, 'cleanup!(strict: true)'
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, "assert_unchanged_binding!('Finance tariff execution bindings'"

    recovery_source = File.read(File.join(Harness::ROOT, 'app/Support/Operations/SyntheticRecoverySnapshot.php'), encoding: Encoding::UTF_8)
    (Harness::TARIFF_COUNT_KEYS + Harness::TARIFF_ORPHAN_KEYS + Harness::TARIFF_INTEGRITY_KEYS + Harness::TARIFF_DIGEST_KEYS).each do |key|
      assert_includes recovery_source, "'#{key}'"
    end
  end

  def test_each_scenario_is_explicitly_bound_to_worker_feature_or_engine_evidence
    assert_includes @source, "scenarios.keys == SCENARIOS"
    assert_includes @source, "scenarios.values.all? { |result| result['status'] == 'PASS' }"
    assert_includes @source, "'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION_AND_EMPTY_READBACK'"
    assert_includes @source, "'proof_kind' => 'OBSERVED_DATABASE_RACE'"
    assert_includes @source, "'proof_kind' => 'EXPECTED_MIGRATION_REFUSAL_AND_ENGINE_OWNED_STRICT_CLEANUP'"
    Harness::SCENARIOS.each { |scenario| assert_includes @source, "'#{scenario}' =>" }
    Harness::FEATURE_SCENARIO_TESTS.each do |scenario, (path, method)|
      assert_includes Harness::SCENARIOS, scenario
      assert_includes Harness::SOURCE_PATHS, path
      assert_match(/\Atest_[a-z0-9_]+\z/, method)
      assert_includes @source, "feature_evidence.fetch('#{scenario}')"
    end
  end

  def test_external_boundaries_incomplete_execution_and_inherited_overrides_fail_closed
    assert_includes @source, "'application_mode' => 'SIMULATION'"
    assert_includes @source, "'synthetic_only' => true"
    assert_includes @source, "'live_integrations_enabled' => false"
    assert_includes @source, "'empty_by_default' => true"
    assert_includes @source, "'automatic_charge_generation' => false"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
    %w[
      BPJS_INTEGRATION_ENABLED VCLAIM_ENABLED SATUSEHAT_ENABLED
      LIS_INTEGRATION_ENABLED PACS_INTEGRATION_ENABLED
    ].each { |name| assert_includes @source, "'#{name}' => 'false'" }

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
