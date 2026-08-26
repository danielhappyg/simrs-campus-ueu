# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require_relative '../../scripts/rehearse-local-postgres17-recovery'

class LocalPostgresRecoveryHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/rehearse-local-postgres17-recovery.rb')

  class NoCommandRunner
    def run!(*args, **kwargs)
      raise "Unexpected command before safety boundary: #{args.inspect} #{kwargs.inspect}"
    end

    def run_cleanup(*); end
  end

  class FailingCleanupRunner
    def run!(argv, **)
      raise LocalPostgresRecoveryRehearsal::CommandFailed, 'simulated drop failure' if argv.first == 'dropdb'

      ''
    end

    def run_cleanup(*); end
  end

  def test_script_is_valid_ruby
    _stdout, stderr, status = Open3.capture3('ruby', '-c', SCRIPT)

    assert status.success?, stderr
  end

  def test_explicit_confirmation_is_required_before_any_command
    rehearsal = LocalPostgresRecoveryRehearsal.new(environment: {}, runner: NoCommandRunner.new)

    error = assert_raises(LocalPostgresRecoveryRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/SIMRS_RECOVERY_REHEARSAL_CONFIRM/, error.message)
  end

  def test_non_local_host_is_rejected_before_any_command
    rehearsal = LocalPostgresRecoveryRehearsal.new(
      environment: {
        'SIMRS_RECOVERY_REHEARSAL_CONFIRM' => LocalPostgresRecoveryRehearsal::CONFIRMATION,
        'PGHOST' => 'db.example.invalid'
      },
      runner: NoCommandRunner.new
    )

    error = assert_raises(LocalPostgresRecoveryRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/non-local PostgreSQL host/, error.message)
  end

  def test_runner_does_not_inherit_libpq_host_override
    original = ENV['PGHOSTADDR']
    ENV['PGHOSTADDR'] = '203.0.113.8'

    output = LocalPostgresRecoveryRehearsal::Runner.new.run!(
      ['ruby', '-e', "print ENV.key?('PGHOSTADDR') ? ENV.fetch('PGHOSTADDR') : 'absent'"]
    )

    assert_equal 'absent', output
  ensure
    original.nil? ? ENV.delete('PGHOSTADDR') : ENV['PGHOSTADDR'] = original
  end

  def test_closed_database_namespace_and_cleanup_contract_are_present
    source = File.read(SCRIPT)

    assert_includes source, 'simrs_recovery_#{attempt}_source'
    assert_includes source, 'simrs_recovery_#{attempt}_restore'
    assert_includes source, 'DATABASE_PATTERN.match?(database)'
    assert_includes source, '@created_databases << name'
    assert_includes source, '@created_databases.reverse.each'
    assert_includes source, 'FileUtils.remove_entry_secure'
    refute_match(/OptionParser|--database|--root|SIMRS_RECOVERY_EVIDENCE_DIR/, source)
  end

  def test_restore_is_transactional_and_never_cleans_an_existing_database
    source = File.read(SCRIPT)

    assert_includes source, "'--exit-on-error', '--single-transaction', '--no-owner', '--no-privileges'"
    assert_includes source, 'assert_database_absent!(@restore_database)'
    refute_includes source, '--clean'
    refute_includes source, 'migrate:fresh'
    refute_includes source, 'simulation:reset'
  end

  def test_cleanup_failure_blocks_verified_cleanup
    rehearsal = LocalPostgresRecoveryRehearsal.new(
      environment: {
        'SIMRS_RECOVERY_REHEARSAL_CONFIRM' => LocalPostgresRecoveryRehearsal::CONFIRMATION,
        'PGHOST' => '127.0.0.1',
        'PGPORT' => '5432',
        'PGUSER' => 'recovery_test'
      },
      runner: FailingCleanupRunner.new
    )
    rehearsal.send(:configure_connection!)
    database = 'simrs_recovery_000000000000_source'
    rehearsal.instance_variable_set(:@created_databases, [database])

    error = assert_raises(LocalPostgresRecoveryRehearsal::CommandFailed) do
      rehearsal.send(:cleanup!, strict: true)
    end
    assert_match(/no PASS evidence was written/, error.message)
    assert_equal [database], rehearsal.instance_variable_get(:@created_databases)
  end

  def test_frozen_acceptance_contract_hash_matches_reviewed_source
    assert_equal(
      LocalPostgresRecoveryRehearsal::ACCEPTANCE_SQL_SHA256,
      Digest::SHA256.file(LocalPostgresRecoveryRehearsal::ACCEPTANCE_SQL).hexdigest
    )
  end

  def test_rehearsal_binds_synthetic_private_schema_and_frozen_acceptance_contract
    source = File.read(SCRIPT)

    assert_includes source, "'APP_MODE' => 'SIMULATION'"
    assert_includes source, "'APP_SYNTHETIC_ONLY' => 'true'"
    assert_includes source, "'DB_SCHEMA' => 'laravel'"
    assert_includes source, "'DB_URL' => ''"
    assert_includes source, 'BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql'
    assert_includes source, "'source_restore_snapshot_match' => true"
    assert_includes source, "'source_restore_acceptance_match' => true"
    assert_includes source, "'rpo_data_loss_rows' => 0"
    assert_includes source, "'frozen_acceptance_contract_sha256' => ACCEPTANCE_SQL_SHA256"
    assert_includes source, 'cleanup!(strict: true)'
    assert_operator source.index('cleanup!(strict: true)'), :<, source.index('evidence_path = write_evidence!')
  end

  def test_secret_values_and_database_names_are_not_written_to_evidence
    source = File.read(SCRIPT)
    evidence_method = source[/def write_evidence!.*?^  end/m]

    refute_nil evidence_method
    refute_includes evidence_method, 'PGPASSWORD'
    refute_includes evidence_method, 'DB_PASSWORD'
    refute_includes evidence_method, '@source_database'
    refute_includes evidence_method, '@restore_database'
    refute_includes evidence_method, 'DEMO_ACCOUNT_PASSWORD'
  end
end
