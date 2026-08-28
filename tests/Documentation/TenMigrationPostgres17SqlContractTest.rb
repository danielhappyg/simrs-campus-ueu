# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require 'digest'

class TenMigrationPostgres17SqlContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  OPERATIONS = File.join(ROOT, 'docs/operations')
  PREFLIGHT = File.join(OPERATIONS, 'T1_TEN_MIGRATION_PRODUCTION_READINESS_PREFLIGHT_2026-08-28.sql')
  PRE = File.join(OPERATIONS, 'T1_TEN_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-28.sql')
  POST = File.join(OPERATIONS, 'T1_TEN_MIGRATION_POSTMIGRATION_ACCEPTANCE_2026-08-28.sql')
  HEX_B = 'b' * 64
  HEX_C = 'c' * 64

  def setup
    skip 'Set SIMRS_TEN_MIGRATION_POSTGRES17=1 to run the PostgreSQL contract' unless ENV['SIMRS_TEN_MIGRATION_POSTGRES17'] == '1'

    @psql = ENV.fetch('PSQL', 'psql')
    @host = ENV.fetch('PGHOST', '127.0.0.1')
    @port = ENV.fetch('PGPORT', '5432')
    @database = ENV.fetch('PGDATABASE', 'postgres')
    @user = ENV.fetch('PGUSER', 'postgres')
  end

  def test_server_is_exact_postgresql_17_10
    stdout, stderr, status = run_psql(command: "SELECT current_setting('server_version_num')")

    assert status.success?, stderr
    assert_equal '170010', stdout.strip
  end

  def test_pre_preservation_parses_completely_then_fails_closed_on_absent_schema
    _stdout, stderr, status = run_psql(
      file: PRE,
      variables: {
        sql_sha256: Digest::SHA256.file(PRE).hexdigest,
        expected_database: @database,
        expected_current_user: @user,
        expected_server_version_num: '170010',
        expected_ssl: 'false'
      }
    )

    refute status.success?
    assert_includes stderr, 'PRE_MIGRATION_NO_GO: laravel schema is missing'
    refute_match(/syntax error|unterminated|invalid command/i, stderr)
  end

  def test_post_acceptance_parses_completely_then_fails_closed_on_absent_schema
    _stdout, stderr, status = run_psql(
      file: POST,
      variables: {
        sql_sha256: Digest::SHA256.file(POST).hexdigest,
        expected_pre_receipt_sha256: HEX_B,
        expected_database: @database,
        expected_current_user: @user,
        expected_server_version_num: '170010',
        expected_ssl: 'false',
        expected_preservation_contract: '{}',
        expected_preservation_contract_sha256: HEX_C
      }
    )

    refute status.success?
    assert_includes stderr, 'POST_MIGRATION_NO_GO: laravel schema is missing'
    refute_match(/syntax error|unterminated|invalid command/i, stderr)
  end

  def test_readiness_preflight_is_parsed_and_cannot_match_an_empty_database
    stdout, stderr, status = run_psql(file: PREFLIGHT)

    refute status.success?
    assert_empty stdout
    assert_includes stderr, 'relation "laravel.migrations" does not exist'
    refute_match(/syntax error|unterminated|invalid command/i, stderr)
  end

  private

  def run_psql(file: nil, command: nil, variables: {})
    arguments = [
      @psql,
      '--no-psqlrc',
      '--host', @host,
      '--port', @port,
      '--dbname', @database,
      '--username', @user,
      '--set=ON_ERROR_STOP=1',
      '--quiet',
      '--tuples-only',
      '--no-align'
    ]
    variables.each { |name, value| arguments << "--set=#{name}=#{value}" }
    arguments.concat(['--file', file]) if file
    arguments.concat(['--command', command]) if command

    Open3.capture3(*arguments, chdir: ROOT)
  end
end
