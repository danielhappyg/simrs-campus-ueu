# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'

require_relative '../../scripts/rehearse-local-emergency-portability'

class LocalEmergencyPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalEmergencyPortabilityRehearsal

  def setup
    @path = File.join(Harness::ROOT, Harness::SCRIPT_PATH)
    @source = File.read(@path, encoding: Encoding::UTF_8)
  end

  def test_script_is_valid_and_catalogues_are_closed
    assert system(RbConfig.ruby, '-c', @path, out: File::NULL, err: File::NULL)
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal %w[tests/Feature/Emergency tests/Unit/Emergency], Harness::TEST_PATHS
    assert_equal [
      'database/migrations/2026_09_01_000400_create_structured_emergency_triage_disposition_tables.php',
      'database/migrations/2026_09_01_000500_add_inpatient_patient_admission_claim.php',
    ], Harness::MIGRATION_PATHS
    assert_equal 'SIMRS_LOCAL_STRUCTURED_EMERGENCY_PORTABILITY', Harness::EVIDENCE_KIND
  end

  def test_source_binding_is_deterministic_hash_bound_and_complete
    first = harness.current_emergency_binding
    second = harness.current_emergency_binding
    assert_equal first, second
    assert_operator first.fetch('files').length, :>, 30
    %w[aggregate_sha256 harness_sha256 migration_catalog_sha256 test_catalog_sha256 execution_batch_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, first.fetch(key))
    end
    %w[
      app/Support/Emergency/EmergencyTriageService.php
      app/Support/Emergency/EmergencyDocumentationService.php
      app/Support/Emergency/EmergencyDiagnosticFollowUpService.php
      app/Support/Emergency/EmergencyDispositionService.php
      app/Support/Emergency/EmergencyInpatientHandoffService.php
      app/Support/Emergency/EmergencyInpatientHandoffCompensationService.php
      app/Support/Emergency/EmergencyProjection.php
      tests/Feature/Emergency/StructuredEmergencyCoreWorkflowTest.php
      tests/Feature/Emergency/EmergencyInpatientHandoffTest.php
      tests/Unit/Emergency/EmergencyAuditContractTest.php
    ].each { |path| assert_includes first.fetch('files').keys, path }
  end

  def test_confirmation_and_connection_overrides_fail_closed
    error = assert_raises(Harness::CommandFailed) { Harness.new(engine: 'postgresql17', environment: {}).assert_contract! }
    assert_match(/SIMRS_EMERGENCY_REHEARSAL_CONFIRM/, error.message)

    error = assert_raises(Harness::CommandFailed) do
      Harness.new(engine: 'postgresql17', environment: confirmed_environment.merge('DB_URL' => 'postgresql://outside.invalid/db')).assert_contract!
    end
    assert_match(/refuses any DB_URL/, error.message)

    %w[PGHOST PGPORT PGUSER PGPASSWORD DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET].each do |name|
      error = assert_raises(Harness::CommandFailed) do
        Harness.new(engine: 'postgresql17', environment: confirmed_environment.merge(name => 'unexpected')).assert_contract!
      end
      assert_match(/refuses inherited database overrides/, error.message)
    end
  end

  def test_harness_forces_safe_boundary_strict_cleanup_and_private_artifacts
    [
      "'APP_MODE' => 'SIMULATION'",
      "'APP_SYNTHETIC_ONLY' => 'true'",
      "'BREAK_GLASS_MODE' => 'off'",
      "'BREAK_GLASS_GLOBAL_DISABLED' => 'true'",
      "'DB_URL' => ''",
    ].each { |needle| assert_includes foundation_source, needle }
    assert_includes @source, 'cleanup!(strict: true)'
    assert_match(/ensure\n\s+cleanup!/, @source)
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, "evidence file mode is not 0600"
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, "'hosted_readiness_claim' => false"
    assert_includes @source, "'deployment_claim' => false"
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
  end

  def test_complete_emergency_suite_and_fresh_migration_are_executed
    assert_includes @source, "run_artisan!('migrate:fresh', '--force', '--no-interaction')"
    assert_includes @source, 'TEST_BATCHES.to_h do |name, paths|'
    assert_includes @source, '[name, run_test_suite!(paths)]'
    assert_includes @source, 'merge_suite_results(component_suites)'
    assert_includes @source, 'assert_no_non_synthetic_patients!'
    assert_includes @source, "'fresh_apply' => 'PASS'"
    assert_includes @source, "'database_removed' => true"
    assert_includes @source, "'temporary_server_removed' => true"
  end

  def test_postgres_trigger_functions_are_fresh_migration_reentrant
    migration = File.read(File.join(Harness::ROOT, Harness::MIGRATION_PATHS.first), encoding: Encoding::UTF_8)

    %w[
      emergency_append_only_guard
      emergency_truncate_guard
      emergency_vocabulary_transition_guard
      emergency_document_transition_guard
      emergency_handoff_graph_guard
    ].each do |function|
      assert_includes migration, "CREATE OR REPLACE FUNCTION #{function}()"
    end
    refute_match(/CREATE\s+FUNCTION\s+emergency_/i, migration)
  end

  def test_cross_domain_acknowledgement_foreign_keys_use_mysql_safe_names
    migration = File.read(File.join(Harness::ROOT, Harness::MIGRATION_PATHS.first))

    %w[lraa_emergency_acceptance_fk rraa_emergency_acceptance_fk].each do |name|
      assert_operator name.length, :<=, 64
      assert_includes migration, "indexName: '#{name}'"
      assert_includes migration, 'dropForeign($foreignKey)'
    end
    assert_includes migration, %q{if ($driver === 'sqlite')}
  end

  private

  def confirmed_environment
    { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness
    Harness.new(engine: 'postgresql17', environment: confirmed_environment)
  end

  def foundation_source
    @foundation_source ||= File.read(
      File.join(Harness::ROOT, 'scripts/rehearse-local-portability-full-suite.rb'),
      encoding: Encoding::UTF_8,
    )
  end
end
