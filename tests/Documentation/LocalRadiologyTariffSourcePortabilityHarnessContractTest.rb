# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-radiology-tariff-source-portability'

class LocalRadiologyTariffSourcePortabilityHarnessContractTest < Minitest::Test
  Harness = LocalRadiologyTariffSourcePortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['radiology-tariff-source-portability-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 12_000
  ensure
    worker&.close!
  end

  def test_closed_twenty_scenario_catalogue_is_bounded_and_explicit
    assert_equal %w[
      fresh-migration empty-down-reapply exact-role-boundary three-care-setting-bindings
      performed-service-date-resolution future-half-open-terminal-retirement upstream-context-refusal
      order-report-amendment-no-charge one-performance-one-typed-source radiology-only-bill-snapshot
      partial-sync-unresolved-issue-refusal later-gap-resolution-new-version
      replay-key-conflict-stale-refusal same-performance-import-concurrency
      competing-binding-writer-concurrency application-sql-guard-refusal
      database-append-only-and-head-refusal audit-corruption-and-reconciliation-refusal
      least-privilege-runtime bounded-reset-recovery-rollback-and-strict-cleanup
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    assert_equal 20, Harness::SCENARIOS.length
    refute_includes @source, 'SCENARIOS.to_h'
  end

  def test_exact_engines_and_current_source_sha_bindings_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_09_02_000300_create_radiology_performance_tariff_source.php', Harness::MIGRATION_PATH
    assert_equal 'SIMRS_LOCAL_RADIOLOGY_TARIFF_SOURCE_PORTABILITY', Harness::EVIDENCE_KIND
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    binding = harness.current_radiology_tariff_source_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[
      aggregate_sha256 application_source_sha256 worker_source_sha256
      scenario_catalog_sha256 runtime_grant_catalog_sha256
    ].each { |key| assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key)) }
    assert_equal binding.fetch('aggregate_sha256'), binding.fetch('application_source_sha256')
    assert_includes Harness::SOURCE_PATHS, Harness::MIGRATION_PATH
    assert_includes Harness::SOURCE_PATHS, Harness::CONTRACT_PATH
    assert_includes Harness::SOURCE_PATHS, Harness::EVIDENCE_TEMPLATE_PATH
  end

  def test_real_worker_uses_governed_services_and_typed_one_to_one_source
    %w[
      FinanceTariffMasterService FinanceRadiologyTariffBindingService
      FinanceRadiologyTariffProjection FinanceRadiologySourceAdapter
      FinanceSourceCoordinator FinanceBillService RadiologyMasterService
    ].each { |name| assert_includes @worker, name }
    assert_includes @worker, 'one source per performance'
    assert_includes @worker, 'one typed charge per source'
    assert_includes @worker, "'source_domain' => $charges->sole()->source_domain"
    assert_includes @worker, "'unit_amount' => $sources->sole()->unit_amount"
    assert_includes @worker, 'verifyRetained'
    assert_includes @worker, "'unresolved_count'"
  end

  def test_two_independent_process_races_observe_real_database_waits
    assert_includes @source, 'start_domain_race_worker!'
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @worker, 'backendConnectionId'
    assert_includes @worker, "protocol('HOLDING'"
    assert_includes @worker, "'lock_order_trace' => ['encounters']"
    assert_includes @worker, "'lock_order_trace' => ['finance_radiology_tariff_bindings']"
    assert_includes @source, 'expected_outcomes: %w[MATERIALIZED RECONCILED]'
    assert_includes @source, 'expected_outcomes: %w[APPLIED DENIED]'
  end

  def test_least_privilege_map_is_select_only_and_has_no_schema_wildcard
    Harness::RUNTIME_TABLE_GRANTS.each_value { |grant| assert_equal 'SELECT', grant }
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'information_schema.role_table_grants'
    assert_includes @source, 'SHOW GRANTS FOR'
    assert_includes @source, 'schema wildcard grant'
    assert_includes @worker, 'forbidden_operations_refused'
    assert_includes @worker, 'write unexpectedly allowed'
  end

  def test_each_scenario_is_bound_to_feature_worker_or_engine_evidence
    assert_includes @source, 'scenarios.keys == SCENARIOS'
    assert_includes @source, "scenarios.values.all? { |result| result['status'] == 'PASS' }"
    assert_includes @source, "'proof_kind' => 'OBSERVED_DATABASE_RACE'"
    assert_includes @source, "'proof_kind' => 'EXPECTED_MIGRATION_REFUSAL_AND_ENGINE_OWNED_STRICT_CLEANUP'"
    Harness::SCENARIOS.each { |scenario| assert_includes @source, "'#{scenario}' =>" }
    Harness::FEATURE_SCENARIO_TESTS.each do |scenario, (path, method)|
      assert_includes Harness::SCENARIOS, scenario
      assert_includes Harness::SOURCE_PATHS, path
      assert_match(/\Atest_[a-z0-9_]+\z/, method)
      assert_includes @source, "feature.fetch('#{scenario}')"
    end
  end

  def test_evidence_is_create_only_sanitized_strictly_cleaned_and_keeps_gates_open
    assert_includes @source, "'application_mode' => 'SIMULATION'"
    assert_includes @source, "'synthetic_only' => true"
    assert_includes @source, "'live_integrations_enabled' => false"
    assert_includes @source, "'owner_acceptance_claim' => false"
    assert_includes @source, "'g0_claim' => false"
    assert_includes @source, "'g3_claim' => false"
    assert_includes @source, 'G0 and G3 remain OPEN'
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, 'cleanup!(strict: true)'
    assert_includes @source, "assert_unchanged_binding!('Radiology tariff/source execution bindings'"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
  end

  def test_inherited_database_overrides_are_rejected
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
