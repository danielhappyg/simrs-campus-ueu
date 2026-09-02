# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-pharmacy-stock-portability'

class LocalPharmacyStockPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalPharmacyStockPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['pharmacy-stock-portability-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 14_000
  ensure
    worker&.close!
  end

  def test_closed_scenario_catalogue_matches_evidence_template
    assert_equal %w[
      fresh-migration empty-down-reapply exact-role-boundary all-three-care-settings
      medicine-depot-version-retire opening-lot-expiry-quarantine verification-refusal
      deterministic-fefo-revalidation full-partial-unfilled-handover three-condition-return
      stock-financial-reconciliation encounter-lifecycle-blockers exact-replay-after-head-advance
      changed-payload-key-conflict competing-handovers-no-negative-stock return-vs-handover
      preparation-vs-transfer-discharge reset-recovery-vs-operation exact-lock-order-observability
      application-sql-guard-refusal
      database-update-delete-truncate-refusal evidence-chain-corruption-refusal
      least-privilege-runtime bounded-reset-recovery-audit-preservation
      retained-evidence-rollback-refusal invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
  end

  def test_exact_engines_migration_sources_and_sha_catalogues_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_09_01_000600_create_cross_setting_pharmacy_tables.php', Harness::MIGRATION_PATH
    assert_equal 'SIMRS_LOCAL_CROSS_SETTING_PHARMACY_STOCK_PORTABILITY', Harness::EVIDENCE_KIND
    binding = harness.current_pharmacy_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
    %w[
      app/Support/Pharmacy/PharmacyWorkflowService.php
      app/Support/Pharmacy/PharmacyStockService.php
      app/Support/Pharmacy/PharmacyLockCoordinator.php
      app/Support/Pharmacy/PharmacyEvidenceFingerprint.php
      app/Support/Simulation/SyntheticResetService.php
      app/Support/Operations/SyntheticRecoverySnapshot.php
    ].each { |path| assert_includes Harness::SOURCE_PATHS, path }
    assert_includes Harness::SOURCE_PATHS, 'tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php'
    assert_includes Harness::SOURCE_PATHS, 'docs/new-simrs-rebuild/phase-1/CROSS_SETTING_MEDICATION_DISPENSING_STOCK_LEDGER_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md'
  end

  def test_real_service_worker_binds_roles_three_settings_fefo_handover_and_return
    %w[PharmacyMasterService PharmacyStockService PharmacyWorkflowService PharmacyActorPolicy].each do |service|
      assert_includes @worker, "app(#{service}::class)"
    end
    assert_includes @worker, 'exact-role wrong actor denied'
    %w[CARE_SETTING_OUTPATIENT CARE_SETTING_EMERGENCY CARE_SETTING_INPATIENT].each { |setting| assert_includes @worker, setting }
    assert_includes @worker, 'EARLY-'
    assert_includes @worker, 'LATE-'
    assert_includes @worker, 'fingerprintPreparation'
    assert_includes @worker, 'recordReturn('
    assert_includes @worker, 'RETURN_TO_STOCK'
    assert_includes @worker, 'runVerificationRefusalScenario'
    assert_includes @worker, 'runExpiryQuarantineScenario'
    assert_includes @worker, 'runFefoRevalidationScenario'
    assert_includes @worker, 'deterministic multi-lot FEFO order'
    assert_includes @worker, 'final handover rejects stale FEFO evidence'
    assert_includes @worker, 'movement balance matches lot'
    assert_includes @worker, 'pharmacy_financial_source_mismatches'
    assert_includes @worker, 'authoritativePharmacyIntegrity'
  end

  def test_two_real_process_race_contract_has_native_wait_and_no_advisory_lock
    foundation = File.read(File.join(Harness::ROOT, 'scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb'), encoding: Encoding::UTF_8)
    %w[Open3.popen3 observe_real_database_wait! pg_blocking_pids performance_schema.data_lock_waits].each do |needle|
      assert_includes @source + foundation, needle
    end
    assert_includes @source, "'independent_application_processes'=>2"
    assert_includes @source, "'real_database_wait_observed'=>true"
    assert_includes @worker, 'raceHandover'
    assert_includes @worker, 'raceReturnHandover'
    assert_includes @worker, 'racePreparationLifecycle'
    assert_includes @worker, 'raceResetRecoveryOperation'
    assert_includes @worker, 'preparation blocks transfer and discharge'
    assert_includes @source, 'second_connection_environment:@reset_application_environment'
    assert_includes @source, "%w[APPLIED DENIED]"
    assert_includes @source, "%w[PREPARED DENIED_BOTH]"
    assert_includes @source, "%w[HANDED_OVER RESET]"
    assert_includes @worker, 'no negative stock'
    refute_includes @worker, 'GET_LOCK('
    refute_includes @worker, 'pg_advisory_lock'
  end

  def test_lock_order_is_observed_from_real_for_update_queries_and_race_stages
    assert_includes @worker, 'function runLockOrderProbe'
    assert_includes @worker, "str_contains($sql,'for update')"
    expected = %w[
      inpatient_patient_claim_mutex pharmacy_inventory_mutex encounter
      inpatient_location_event inpatient_bed pharmacy_prescription
    ]
    expected.each { |stage| assert_includes @worker, "'#{stage}'" }
    assert_includes @worker, "must($trace===$expected,'exact service lock order')"
    assert_includes @source, 'lock_order_trace_asserted'
    assert_includes @source, 'observed_precondition_lock_order'
  end

  def test_runtime_grants_encode_mutable_and_append_only_boundaries
    Harness::RUNTIME_READ_TABLES.each { |table| assert_equal 'SELECT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::RUNTIME_LOCK_TABLES.each { |table| assert_equal 'SELECT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::RUNTIME_INSERT_LOCK_TABLES.each { |table| assert_equal 'SELECT, INSERT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::MUTABLE_HEAD_TABLES.each { |table| assert_equal 'SELECT, INSERT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::IMMUTABLE_EVIDENCE_TABLES.each { |table| assert_equal 'SELECT, INSERT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    refute Harness::RUNTIME_TABLE_GRANTS.values.any? { |grant| grant.include?('DELETE') }
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'role_table_grants'
    assert_includes @source, 'SHOW GRANTS FOR'
  end

  def test_guard_corruption_reset_rollback_cleanup_and_evidence_paths_are_real
    %w[runApplicationGuards runDatabaseGuards runPrivilegeGuards runCorruption runReset verifyInvariants].each do |method|
      assert_includes @worker, "function #{method}"
    end
    assert_includes @worker, "app(PharmacySqlWriteGuard::class)"
    assert_includes @worker, "SchemaQualifier::table('pharmacy_prescription_versions')"
    assert_includes @worker, 'postgres_evidence_truncate_refusal'
    assert_includes @worker, "'evidence_table'=>'pharmacy_prescription_versions'"
    assert_includes @worker, "current_content_digest'=>str_repeat('0',64)"
    assert_includes @worker, "app(SyntheticResetService::class)->reset"
    assert_includes @source, 'expect_pharmacy_rollback_refusal!'
    assert_includes @source, 'correlatedauditevidenceexists'
    assert_includes @source, 'write_pharmacy_evidence!'
    assert_includes @source, "mode:'wx',perm:0o600"
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, 'cleanup!(strict:true)'
    assert_includes @source, "assert_unchanged_binding!('Pharmacy execution bindings'"
    recovery_source = File.read(File.join(Harness::ROOT, 'app/Support/Operations/SyntheticRecoverySnapshot.php'), encoding: Encoding::UTF_8)
    (Harness::RECOVERY_COUNT_KEYS + Harness::RECOVERY_ORPHAN_KEYS + Harness::RECOVERY_INTEGRITY_KEYS + Harness::RECOVERY_DIGEST_KEYS).each do |key|
      assert_includes recovery_source, "'#{key}'"
    end
    assert_includes @worker, 'SyntheticRecoverySnapshot'
    assert_includes @worker, 'pharmacyStockBalanceMismatchCount'
  end

  def test_every_pass_scenario_has_an_explicit_evidence_binding
    refute_includes @source, 'SCENARIOS.to_h{|name|[name,feature.dup]}'
    refute_includes @source, "'focused_feature_suite'=>'CrossSettingPharmacyWorkflowTest'"
    Harness::HARDENING_SCENARIO_TESTS.each do |scenario, (path, method)|
      assert_includes Harness::SCENARIOS, scenario
      assert_equal 'tests/Feature/Pharmacy/PharmacyBackendHardeningTest.php', path
      assert_match(/\Atest_[a-z0-9_]+\z/, method)
      assert_includes @source, "hardening_evidence.fetch('#{scenario}')"
    end
    Harness::LIFECYCLE_GATE_TESTS.each_value do |path, method|
      assert_includes Harness::SOURCE_PATHS, path
      assert_match(/\Atest_[a-z0-9_]+\z/, method)
    end
    Harness::PRIMARY_SCENARIO_TESTS.each do |scenario, (path, method)|
      assert_includes Harness::SCENARIOS, scenario
      assert_equal 'tests/Feature/Pharmacy/CrossSettingPharmacyWorkflowTest.php', path
      assert_match(/\Atest_[a-z0-9_]+\z/, method)
      assert_includes @source, "primary_evidence.fetch('#{scenario}')"
    end
    assert_includes @source, "scenarios.values.all?{|result|result['status']=='PASS'}"
  end

  def test_external_boundaries_and_incomplete_exact_engine_execution_fail_closed
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    assert_includes @source, 'Post-reset pharmacy recovery counts were not empty.'
    assert_includes @source, "cleanup!"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
    %w[BPJS_INTEGRATION_ENABLED VCLAIM_ENABLED SATUSEHAT_ENABLED].each { |name| assert_includes @source, "'#{name}' => 'false'" }

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
