# frozen_string_literal: true

require 'json'
require 'minitest/autorun'
require 'open3'

require_relative '../../scripts/rehearse-local-portability-full-suite'

class LocalPortabilityFullSuiteHarnessContractTest < Minitest::Test
  Harness = LocalPortabilityFullSuiteRehearsal

  def test_engine_and_workflow_universes_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal %w[E2E-01 E2E-02 E2E-03 E2E-04 E2E-05 E2E-12 E2E-15 E2E-16], Harness::WORKFLOW_SLICES.keys
    assert Harness::WORKFLOW_SLICES.values.flatten.all? { |path| path.start_with?('tests/Feature/') }
    Harness::WORKFLOW_SLICES.each_value { |paths| assert_equal paths.uniq, paths }
    assert_equal [
      'tests/Feature/Emergency/EmergencyFlowTest.php',
      'tests/Feature/Emergency/ContinuousEmergencyTeachingJourneyTest.php',
      'tests/Feature/Clinical/LockedClinicalEntryWriterTest.php',
    ], Harness::WORKFLOW_SLICES.fetch('E2E-02')
    assert_includes Harness::WORKFLOW_SLICES.fetch('E2E-16'), 'tests/Feature/PrivilegedAccess/PrivilegedAccessShadowResolverTest.php'
    assert_equal File.join(Harness::ROOT, 'storage/app/portability-rehearsals'), Harness::EVIDENCE_DIRECTORY
  end

  def test_confirmation_engine_and_db_url_fail_closed_before_engine_access
    error = assert_raises(Harness::CommandFailed) do
      harness(engine: 'postgresql17', environment: {}).assert_contract!
    end
    assert_match(/SIMRS_PORTABILITY_REHEARSAL_CONFIRM/, error.message)

    error = assert_raises(Harness::CommandFailed) do
      harness(engine: 'postgresql18', environment: confirmed_environment).assert_contract!
    end
    assert_match(/engine must be one of/, error.message)

    error = assert_raises(Harness::CommandFailed) do
      harness(engine: 'mysql8411', environment: confirmed_environment.merge('DB_URL' => 'mysql://outside.invalid/db')).assert_contract!
    end
    assert_match(/refuses any DB_URL/, error.message)

    %w[PGHOST PGPORT PGUSER PGPASSWORD].each do |name|
      error = assert_raises(Harness::CommandFailed) do
        harness(engine: 'postgresql17', environment: confirmed_environment.merge(name => 'unexpected')).assert_contract!
      end
      assert_match(/creates its own PostgreSQL cluster/, error.message)
    end

    %w[POSTGRES17_BIN MYSQL84_BIN].each do |name|
      error = assert_raises(Harness::CommandFailed) do
        harness(engine: 'postgresql17', environment: confirmed_environment.merge(name => '/tmp/untrusted')).assert_contract!
      end
      assert_match(/refuses executable override/, error.message)
    end
  end

  def test_application_environment_forces_simulation_and_disables_privileged_or_external_modes
    pg = harness(engine: 'postgresql17', environment: confirmed_environment)
    pg.instance_variable_set(:@postgres_database, 'simrs_portability_pg_012345abcdef')
    pg.instance_variable_set(:@postgres_host, '/private/tmp/sp17-fixture/socket')
    pg.instance_variable_set(:@postgres_port, '5432')
    pg.instance_variable_set(:@postgres_user, 'local_test_role')
    environment = pg.application_environment

    assert_equal 'SIMULATION', environment.fetch('APP_MODE')
    assert_equal 'true', environment.fetch('APP_SYNTHETIC_ONLY')
    assert_equal 'off', environment.fetch('BREAK_GLASS_MODE')
    assert_equal 'true', environment.fetch('BREAK_GLASS_GLOBAL_DISABLED')
    assert_equal '', environment.fetch('BREAK_GLASS_SESSION_HMAC_KEY')
    assert_equal '', environment.fetch('DB_URL')
    assert_equal '', environment.fetch('AWS_ACCESS_KEY_ID')
    assert_equal 'pgsql', environment.fetch('DB_CONNECTION')
    assert_equal '/private/tmp/sp17-fixture/socket', environment.fetch('DB_HOST')
    assert_equal '', environment.fetch('DB_PASSWORD')
    assert_equal 'laravel', environment.fetch('DB_SCHEMA')
  end

  def test_mysql_environment_uses_only_generated_closed_identifiers
    mysql = harness(engine: 'mysql8411', environment: confirmed_environment)
    mysql.instance_variable_set(:@mysql_database, 'simrs_portability_my_012345abcdef')
    mysql.instance_variable_set(:@mysql_user, 'simrs_p_012345abcdef')
    mysql.instance_variable_set(:@mysql_password, 'ephemeral-only')
    mysql.instance_variable_set(:@mysql_port, 33_071)
    environment = mysql.application_environment

    assert_equal 'mysql', environment.fetch('DB_CONNECTION')
    assert_equal '127.0.0.1', environment.fetch('DB_HOST')
    assert_match(Harness::MYSQL_DATABASE_PATTERN, environment.fetch('DB_DATABASE'))
    assert_match(Harness::MYSQL_USER_PATTERN, environment.fetch('DB_USERNAME'))
    assert_equal '', environment.fetch('DB_SOCKET')
  end

  def test_phpunit_aggregate_parser_accepts_only_passing_integer_results
    subject = harness(engine: 'postgresql17', environment: confirmed_environment)
    result = subject.send(:parse_phpunit_result, JSON.generate(
      'tool' => 'phpunit', 'result' => 'passed', 'tests' => 12, 'passed' => 11,
      'skipped' => 1, 'assertions' => 34, 'duration_ms' => 56
    ) + "\n")

    assert_equal 12, result.fetch('tests')
    assert_equal 11, result.fetch('passed')
    assert_equal 1, result.fetch('skipped')
    assert_equal 34, result.fetch('assertions')

    assert_raises(Harness::CommandFailed) do
      subject.send(:parse_phpunit_result, JSON.generate('tool' => 'phpunit', 'result' => 'failed') + "\n")
    end
    assert_raises(Harness::CommandFailed) { subject.send(:parse_phpunit_result, "not-json\n") }

    standard = subject.send(
      :parse_phpunit_result,
      "PASS Tests\\Feature\\FixtureTest\nTests: 2 skipped, 11 passed (34 assertions)\nDuration: 0.56s\n",
    )
    assert_equal 13, standard.fetch('tests')
    assert_equal 11, standard.fetch('passed')
    assert_equal 2, standard.fetch('skipped')
    assert_equal 34, standard.fetch('assertions')
    assert_equal 560, standard.fetch('duration_ms_reported')
  end

  def test_evidence_guard_rejects_secrets_targets_and_raw_output_but_allows_boundary_labels
    subject = harness(engine: 'postgresql17', environment: confirmed_environment)

    assert_equal({ 'hosted_readiness_claim' => false }, subject.sanitize_evidence!('hosted_readiness_claim' => false))
    %w[password secret token dsn database_name database_user host port pid socket raw_output].each do |key|
      assert_raises(Harness::CommandFailed, key) { subject.sanitize_evidence!(key => 'unsafe') }
    end
    credential_url = ['postgresql://', 'user', ':', 'pass', '@example.invalid/db'].join
    assert_raises(Harness::CommandFailed) { subject.sanitize_evidence!('value' => credential_url) }
    assert_raises(Harness::CommandFailed) { subject.sanitize_evidence!('value' => 'simrs_portability_pg_012345abcdef') }
    assert_raises(Harness::CommandFailed) { subject.sanitize_evidence!('value' => 'simrs_p_012345abcdef') }
  end

  def test_backend_source_binding_is_deterministic_closed_and_nonempty
    subject = harness(engine: 'postgresql17', environment: confirmed_environment)
    first = subject.backend_source_binding
    second = subject.backend_source_binding

    assert_equal first, second
    assert_operator first.fetch('file_count'), :>, 100
    assert_match(/\A[0-9a-f]{64}\z/, first.fetch('sha256'))
    assert_includes subject.send(:source_paths), File.join(Harness::ROOT, 'scripts/rehearse-local-portability-full-suite.rb')
    assert_raises(Harness::CommandFailed) { subject.send(:safe_source_path, '../outside') }

    execution = Harness.current_execution_bindings
    assert_equal first, execution.fetch('backend_execution_source_set')
    assert_equal 21, execution.dig('migration_set', 'file_count')
    assert_match(/\A[0-9a-f]{64}\z/, execution.dig('migration_set', 'sha256'))
    assert_match(/\A[0-9a-f]{64}\z/, execution.fetch('harness_sha256'))
    assert_match(/\A[0-9a-f]{64}\z/, execution.fetch('workflow_test_catalog_sha256'))
  end

  def test_binding_drift_gate_accepts_identity_and_rejects_any_change
    subject = harness(engine: 'postgresql17', environment: confirmed_environment)
    binding = Harness.current_execution_bindings

    assert_nil subject.send(:assert_unchanged_binding!, 'fixture', binding, Marshal.load(Marshal.dump(binding)))
    changed = Marshal.load(Marshal.dump(binding))
    changed.fetch('backend_execution_source_set')['sha256'] = '0' * 64
    error = assert_raises(Harness::CommandFailed) do
      subject.send(:assert_unchanged_binding!, 'Execution source bindings', binding, changed)
    end
    assert_match(/changed during/, error.message)
  end

  def test_non_strict_cleanup_reports_an_exceptional_residue_without_deleting_an_unexpected_path
    subject = harness(engine: 'postgresql17', environment: confirmed_environment)
    subject.instance_variable_set(:@postgres_temp_directory, '/private/tmp/not-a-portability-directory')

    _stdout, stderr = capture_io { subject.send(:cleanup!) }

    assert_match(/portability cleanup incomplete/, stderr)
    assert_match(/Refusing to remove an unexpected/, stderr)
    assert_equal '/private/tmp/not-a-portability-directory', subject.instance_variable_get(:@postgres_temp_directory)
  end

  def test_runner_sanitizes_disposable_identifiers_and_connection_material
    runner = Harness::Runner.new
    credential_url = ['mysql://', 'user', ':', 'pass', '@127.0.0.1/simrs_portability_my_012345abcdef'].join
    sanitized = runner.sanitize("#{credential_url} password=visible token=visible")

    refute_includes sanitized, 'simrs_portability_my_012345abcdef'
    refute_includes sanitized, 'user:pass'
    refute_includes sanitized, 'password=visible'
    refute_includes sanitized, 'token=visible'
  end

  def test_source_contract_contains_cleanup_env_isolation_exact_versions_and_mode_0600_evidence
    source = File.read(File.join(Harness::ROOT, 'scripts/rehearse-local-portability-full-suite.rb'))

    assert_includes source, 'unsetenv_others: true'
    assert_includes source, "SAFE_PATH = '/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin'"
    assert_includes source, "POSTGRES_VERSION = '17.10'"
    assert_includes source, "MYSQL_VERSION = '8.4.11'"
    assert_includes source, "POSTGRES_DATABASE_PATTERN"
    assert_includes source, "Dir.mktmpdir('sp17-', '/private/tmp')"
    assert_includes source, "'-k', @postgres_socket_directory, '-h', ''"
    assert_includes source, "assert_unchanged_binding!('Execution source bindings'"
    assert_includes source, "'--check-inventory'"
    assert_includes source, "ensure\n    cleanup!"
    assert_includes source, "mode: 'wx', perm: 0o600"
    assert_includes source, "FileUtils.remove_entry_secure"
    assert_includes source, "--log-bin-trust-function-creators=1"
    assert_includes source, 'assert_evidence_directory!'
    refute_match(/git\s+(?:add|commit|push)/, source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, source)
  end

  def test_cli_accepts_exactly_one_known_engine
    script = File.join(Harness::ROOT, 'scripts/rehearse-local-portability-full-suite.rb')
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
