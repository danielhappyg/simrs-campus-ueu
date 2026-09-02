# frozen_string_literal: true

require 'json'
require 'minitest/autorun'
require 'open3'

require_relative '../../scripts/rehearse-local-encounter-cancellation-portability'

class LocalEncounterCancellationPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalEncounterCancellationPortabilityRehearsal

  def test_test_catalog_is_closed_and_covers_the_cancellation_integration_boundary
    assert_equal [
      'tests/Feature/Registration/EncounterCancellationTest.php',
      'tests/Feature/Registration/EncounterCancellationIntegrationReadModelTest.php',
      'tests/Feature/Registration/EncounterCancellationRegistrationProjectionTest.php',
      'tests/Feature/Clinical/LockedClinicalEntryWriterTest.php',
      'tests/Feature/Outpatient/OutpatientLabFlowTest.php',
      'tests/Feature/Outpatient/StructuredOutpatientDocumentationTest.php',
      'tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php',
      'tests/Feature/Simulation/SimulationResetCommandTest.php',
    ], Harness::TEST_PATHS
    assert_equal Harness::TEST_PATHS.uniq, Harness::TEST_PATHS
  end

  def test_contract_reuses_exact_engines_and_rejects_external_database_configuration
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal '17.10', Harness::POSTGRES_VERSION
    assert_equal '8.4.11', Harness::MYSQL_VERSION

    error = assert_raises(Harness::CommandFailed) do
      harness(engine: 'postgresql17', environment: confirmed_environment.merge('DB_URL' => 'postgresql://outside.invalid/db'))
        .assert_contract!
    end
    assert_match(/refuses any DB_URL/, error.message)
  end

  def test_execution_binding_covers_harness_foundation_migrations_sources_and_test_catalog
    binding = harness(engine: 'postgresql17', environment: confirmed_environment)
      .current_cancellation_execution_bindings

    assert_operator binding.dig('backend_execution_source_set', 'file_count'), :>, 100
    assert_operator binding.dig('migration_set', 'file_count'), :>, 20
    %w[
      harness_sha256
      foundation_harness_sha256
      focused_test_catalog_sha256
    ].each { |key| assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key)) }
  end

  def test_source_keeps_manifest_gate_separate_and_retains_cleanup_and_secret_controls
    source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH))

    refute_includes source, 'current_manifest_binding'
    refute_includes source, 'MANIFEST_PATH'
    refute_includes source, 'generate-t1-local-milestone-manifest'
    assert_includes source, "run_artisan!('migrate:fresh', '--force', '--no-interaction')"
    assert_includes source, 'cleanup!(strict: true)'
    assert_includes source, "ensure\n    cleanup!"
    assert_includes source, "mode: 'wx', perm: 0o600"
    assert_includes source, 'sanitize_evidence!(evidence)'
    assert_includes source, "'stale_milestone_manifest_not_rewritten' => true"
    refute_match(/git\s+(?:add|commit|push)/, source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, source)
  end

  def test_cli_accepts_exactly_one_supported_engine
    script = File.join(Harness::ROOT, Harness::SCRIPT_PATH)
    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, script)
    assert_equal 64, status.exitstatus

    _stdout, _stderr, status = Open3.capture3(RbConfig.ruby, script, 'postgresql17', 'mysql8411')
    assert_equal 64, status.exitstatus
  end

  private

  def confirmed_environment
    { 'SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness(engine:, environment:)
    Harness.new(engine: engine, environment: environment)
  end
end
