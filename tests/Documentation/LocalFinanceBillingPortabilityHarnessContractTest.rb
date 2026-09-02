# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-finance-billing-portability'

class LocalFinanceBillingPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalFinanceBillingPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['finance-billing-portability-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 18_000
  ensure
    worker&.close!
  end

  def test_closed_scenario_catalogue_matches_the_versioned_bill_scope
    assert_equal %w[
      fresh-migration empty-down-reapply exact-cashier-role-boundary rj-igd-ri-billing
      initial-candidate-discoverability pharmacy-charge-and-reversal initial-sync-issue-version-one
      later-source-sync-issue-version-two immutable-version-history exact-idempotent-replay
      changed-payload-key-conflict stale-fingerprint-refusal application-sql-guard-refusal
      database-mutable-head-refusal database-append-only-update-delete-truncate-refusal
      evidence-chain-corruption-refusal least-privilege-runtime bounded-reset-recovery-audit-preservation
      retained-evidence-rollback-refusal invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    assert_equal 20, Harness::SCENARIOS.length
  end

  def test_exact_engines_migration_sources_and_sha_catalogues_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_09_01_000800_create_cross_setting_financial_spine_tables.php', Harness::MIGRATION_PATH
    assert_equal 'SIMRS_LOCAL_CROSS_SETTING_FINANCE_BILLING_PORTABILITY', Harness::EVIDENCE_KIND
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    binding = harness.current_finance_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
    %w[
      app/Support/Finance/FinanceBillService.php
      app/Support/Finance/FinancePharmacySourceAdapter.php
      app/Support/Finance/FinanceEvidenceFingerprint.php
      app/Support/Finance/FinanceProjection.php
      app/Support/Finance/FinanceAppendOnlyGuard.php
      app/Support/Finance/FinanceMutableHeadGuard.php
      app/Support/Finance/FinanceSqlWriteGuard.php
      app/Support/Simulation/SyntheticResetService.php
      app/Support/Operations/SyntheticRecoverySnapshot.php
    ].each { |path| assert_includes Harness::SOURCE_PATHS, path }
    assert_includes Harness::SOURCE_PATHS, 'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md'
  end

  def test_worker_exercises_real_pharmacy_charge_reversal_and_three_care_settings
    %w[FinanceBillService FinanceProjection FinanceActorPolicy FinanceSqlWriteGuard SyntheticResetService].each do |service|
      assert_includes @worker, service
    end
    assert_includes @worker, 'PharmacyFinancialSourceEvent::CHARGE'
    assert_includes @worker, 'PharmacyFinancialSourceEvent::REVERSAL'
    assert_includes @worker, 'appendReversal'
    assert_includes @worker, 'Encounter::CARE_SETTINGS'
    assert_includes @worker, 'three read-only initial candidates'
    assert_includes @worker, 'candidate read has no bill mutation'
    assert_includes @worker, 'exact cashier view denial expected'
    assert_includes @worker, 'exact cashier mutation denial expected'
    assert_includes @worker, 'later reversal discoverable'
    assert_includes @worker, 'version two issued'
    assert_includes @worker, 'version one immutable history'
    assert_includes @worker, "expectFinanceDenied('stale_bill'"
    assert_includes @worker, "expectFinanceDenied('idempotency_key_conflict'"
  end

  def test_runtime_grants_encode_mutable_head_and_append_only_boundaries
    Harness::RUNTIME_READ_TABLES.each { |table| assert_equal 'SELECT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::RUNTIME_LOCK_TABLES.each { |table| assert_equal 'SELECT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::MUTABLE_HEAD_TABLES.each { |table| assert_equal 'SELECT, INSERT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::IMMUTABLE_EVIDENCE_TABLES.each { |table| assert_equal 'SELECT, INSERT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    refute Harness::RUNTIME_TABLE_GRANTS.values.any? { |grant| grant.include?('DELETE') }
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'role_table_grants'
    assert_includes @source, 'SHOW GRANTS FOR'
    assert_includes @worker, 'forbidden_operations_refused'
  end

  def test_guards_reset_recovery_rollback_cleanup_and_evidence_are_real
    %w[applicationGuards databaseGuards corruptionRefusal privilegeGuards financeInvariants resetAndVerify].each do |method|
      assert_includes @worker, "function #{method}"
    end
    assert_includes @worker, "SchemaQualifier::table('finance_bills')"
    assert_includes @worker, "SchemaQualifier::table('finance_charge_events')"
    mutable_guard_source = File.read(File.join(Harness::ROOT, 'app/Support/Finance/FinanceMutableHeadGuard.php'), encoding: Encoding::UTF_8)
    assert_includes mutable_guard_source, 'finance mutable head write requires governed scope'
    assert_includes @worker, 'post-reset finance graph empty'
    assert_includes @worker, 'reset audit evidence preserved'
    assert_includes @source, 'expect_finance_rollback_refusal!'
    assert_includes @source, 'correlatedauditevidenceexists'
    assert_includes @source, 'write_finance_evidence!'
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, 'cleanup!(strict: true)'
    assert_includes @source, "assert_unchanged_binding!('Finance execution bindings'"

    recovery_source = File.read(File.join(Harness::ROOT, 'app/Support/Operations/SyntheticRecoverySnapshot.php'), encoding: Encoding::UTF_8)
    (Harness::FINANCE_COUNT_KEYS + Harness::FINANCE_ORPHAN_KEYS + Harness::FINANCE_INTEGRITY_KEYS + Harness::FINANCE_DIGEST_KEYS).each do |key|
      assert_includes recovery_source, "'#{key}'"
    end
  end

  def test_every_scenario_has_explicit_worker_or_exact_engine_feature_evidence
    refute_includes @source, 'SCENARIOS.to_h'
    assert_includes @source, 'recreate_application_schema_outside_guard!'
    assert_includes @source, "'proof_kind' => 'EXACT_ENGINE_FEATURE_SUITE'"
    Harness::FEATURE_SCENARIO_TESTS.each do |scenario, (path, method)|
      assert_includes Harness::SCENARIOS, scenario
      assert_includes Harness::SOURCE_PATHS, path
      assert_match(/\Atest_[a-z0-9_]+\z/, method)
      assert_includes @source, "feature_evidence.fetch('#{scenario}')"
    end
    assert_includes @source, "scenarios.keys == SCENARIOS"
    assert_includes @source, "scenarios.values.all? { |result| result['status'] == 'PASS' }"
  end

  def test_external_boundaries_and_incomplete_execution_fail_closed
    assert_includes @source, "'application_mode' => 'SIMULATION'"
    assert_includes @source, "'synthetic_only' => true"
    assert_includes @source, "'live_integrations_enabled' => false"
    assert_includes @source, 'Only retained pharmacy CHARGE and REVERSAL source facts are valued'
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
    %w[BPJS_INTEGRATION_ENABLED VCLAIM_ENABLED SATUSEHAT_ENABLED].each do |name|
      assert_includes @source, "'#{name}' => 'false'"
    end

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
