#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'etc'
require 'fileutils'
require 'json'
require 'open3'
require 'securerandom'
require 'time'
require 'tmpdir'

class LocalPostgresRecoveryRehearsal
  ROOT = File.expand_path('..', __dir__)
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_POSTGRES17'
  DATABASE_PATTERN = /\Asimrs_recovery_[0-9a-f]{12}_(source|restore)\z/
  ACCEPTANCE_SQL = File.join(ROOT, 'docs/operations/BG_02C4B_G1_SOURCE_RESTORE_ACCEPTANCE_2026-08-25.sql')
  ACCEPTANCE_SQL_SHA256 = '832cfa0a145c5d92f32114675d1249300899e554497b65e2d275d7877639b3ba'
  EVIDENCE_DIRECTORY = File.join(ROOT, 'storage/app/recovery-rehearsals')

  class CommandFailed < StandardError; end

  class Runner
    SAFE_INHERITED_ENVIRONMENT = %w[PATH TMPDIR LANG LC_ALL TZ USER LOGNAME].freeze

    def run!(argv, env: {}, stdin_data: '')
      stdout, stderr, status = Open3.capture3(
        safe_environment.merge(env),
        *argv,
        stdin_data: stdin_data,
        unsetenv_others: true
      )
      return stdout if status.success?

      reason = sanitized_reason(stderr)
      suffix = reason.empty? ? '' : ": #{reason}"
      raise CommandFailed, "#{File.basename(argv.fetch(0))} failed with exit status #{status.exitstatus}#{suffix}"
    end

    def run_cleanup(argv, env: {})
      Open3.capture3(safe_environment.merge(env), *argv, unsetenv_others: true)
    rescue StandardError
      nil
    end

    private

    def safe_environment
      SAFE_INHERITED_ENVIRONMENT.each_with_object({}) do |name, safe|
        safe[name] = ENV[name] if ENV.key?(name)
      end
    end

    def sanitized_reason(stderr)
      stderr.lines.map(&:strip).find { |line| !line.empty? }.to_s
        .gsub(/simrs_recovery_[0-9a-f]{12}_(?:source|restore)/, '<disposable-db>')
        .gsub(%r{postgres(?:ql)?://\S+}i, '<redacted-dsn>')
        .gsub(/(password|secret|token)\s*[=:]\s*\S+/i, '\\1=<redacted>')
        .slice(0, 300)
    end
  end

  def initialize(environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @environment = environment
    @runner = runner
    @clock = monotonic_clock || -> { Process.clock_gettime(Process::CLOCK_MONOTONIC) }
    @created_databases = []
    @temporary_directory = nil
    @application_key = "base64:#{Base64.strict_encode64(SecureRandom.random_bytes(32))}"
    @demo_password = SecureRandom.hex(24)
  end

  def run!
    assert_operator_confirmation!
    configure_connection!
    assert_fixed_contracts!
    verify_tools_and_server!

    attempt = SecureRandom.hex(6)
    @source_database = "simrs_recovery_#{attempt}_source"
    @restore_database = "simrs_recovery_#{attempt}_restore"
    assert_generated_database_name!(@source_database)
    assert_generated_database_name!(@restore_database)
    assert_database_absent!(@source_database)
    assert_database_absent!(@restore_database)

    @temporary_directory = Dir.mktmpdir('simrs-recovery-')
    File.chmod(0o700, @temporary_directory)

    create_database!(@source_database)
    create_database!(@restore_database)
    create_private_schema!(@source_database)

    migrate_and_seed_source!
    source_snapshot = recovery_snapshot!(@source_database)
    source_acceptance = acceptance_output!(@source_database)

    dump_path = File.join(@temporary_directory, 'synthetic-recovery.dump')
    backup_started = @clock.call
    create_backup!(@source_database, dump_path)
    backup_elapsed_ms = elapsed_ms(backup_started)
    File.chmod(0o600, dump_path)

    recovery_started = @clock.call
    restore_backup!(@restore_database, dump_path)
    restore_snapshot = recovery_snapshot!(@restore_database)
    restore_acceptance = acceptance_output!(@restore_database)
    verify_laravel_migration_state!(@restore_database)
    recovery_elapsed_ms = elapsed_ms(recovery_started)

    assert_identical!('application recovery snapshot', source_snapshot, restore_snapshot)
    assert_identical!('frozen source/restore acceptance output', source_acceptance, restore_acceptance)
    schema_sha256, data_sha256 = verify_plain_dump_equivalence!
    backup_sha256 = Digest::SHA256.file(dump_path).hexdigest
    backup_bytes = File.size(dump_path)

    cleanup!(strict: true)

    evidence_path = write_evidence!(
      attempt: attempt,
      source_snapshot: JSON.parse(source_snapshot),
      source_acceptance: source_acceptance,
      backup_sha256: backup_sha256,
      backup_bytes: backup_bytes,
      schema_sha256: schema_sha256,
      data_sha256: data_sha256,
      backup_elapsed_ms: backup_elapsed_ms,
      recovery_elapsed_ms: recovery_elapsed_ms
    )

    {
      'status' => 'PASS',
      'evidence_path' => evidence_path,
      'snapshot_sha256' => JSON.parse(source_snapshot).fetch('snapshot_sha256'),
      'backup_elapsed_ms' => backup_elapsed_ms,
      'recovery_elapsed_ms' => recovery_elapsed_ms,
      'rpo_data_loss_rows' => 0,
      'claim' => 'LOCAL_DISPOSABLE_ONLY'
    }
  ensure
    cleanup!
  end

  private

  def assert_operator_confirmation!
    return if @environment['SIMRS_RECOVERY_REHEARSAL_CONFIRM'] == CONFIRMATION

    raise CommandFailed, "Set SIMRS_RECOVERY_REHEARSAL_CONFIRM=#{CONFIRMATION} to authorize disposable local database creation."
  end

  def configure_connection!
    @host = @environment.fetch('PGHOST', '').strip
    @host = '127.0.0.1' if @host.empty?
    allowed_hosts = ['127.0.0.1', 'localhost', '::1', '/tmp', '/private/tmp']
    raise CommandFailed, 'Recovery rehearsal refuses a non-local PostgreSQL host.' unless allowed_hosts.include?(@host)

    @port = @environment.fetch('PGPORT', '5432')
    unless @port.match?(/\A[0-9]{1,5}\z/) && @port.to_i.between?(1, 65_535)
      raise CommandFailed, 'Recovery rehearsal requires a valid local PostgreSQL port.'
    end

    @user = @environment.fetch('PGUSER', '').strip
    @user = Etc.getpwuid.name if @user.empty?
    raise CommandFailed, 'Recovery rehearsal requires a simple PostgreSQL role name.' unless @user.match?(/\A[A-Za-z_][A-Za-z0-9_.-]{0,62}\z/)
  end

  def assert_fixed_contracts!
    raise CommandFailed, 'Recovery rehearsal must run from its repository-owned location.' unless File.realpath(ROOT) == ROOT
    raise CommandFailed, 'The frozen PostgreSQL acceptance query is missing.' unless File.file?(ACCEPTANCE_SQL)
    raise CommandFailed, 'The frozen PostgreSQL acceptance query must not be a symlink.' if File.symlink?(ACCEPTANCE_SQL)
    unless Digest::SHA256.file(ACCEPTANCE_SQL).hexdigest == ACCEPTANCE_SQL_SHA256
      raise CommandFailed, 'The frozen PostgreSQL acceptance query hash does not match its reviewed contract.'
    end
  end

  def verify_tools_and_server!
    %w[psql createdb dropdb pg_dump pg_restore php git].each do |tool|
      @runner.run!([tool, '--version'], env: postgres_environment)
    end

    version_num = @runner.run!(psql_arguments('postgres') + ['--tuples-only', '--no-align', '--command', 'SHOW server_version_num'], env: postgres_environment).strip
    raise CommandFailed, 'Recovery rehearsal requires PostgreSQL major version 17.' unless version_num.match?(/\A17[0-9]{4}\z/)
  end

  def assert_generated_database_name!(name)
    raise CommandFailed, 'Generated recovery database name failed its closed pattern.' unless DATABASE_PATTERN.match?(name)
  end

  def assert_database_absent!(name)
    assert_generated_database_name!(name)
    output = @runner.run!(
      psql_arguments('postgres') + [
        '--tuples-only', '--no-align', '--command',
        "SELECT datname FROM pg_database WHERE datname LIKE 'simrs_recovery_%' ORDER BY datname"
      ],
      env: postgres_environment
    )
    existing = output.lines.map(&:strip).reject(&:empty?)
    raise CommandFailed, 'Recovery rehearsal refuses a pre-existing generated database.' if existing.include?(name)
  end

  def create_database!(name)
    assert_generated_database_name!(name)
    @runner.run!(connection_arguments('createdb') + ['--maintenance-db=postgres', name], env: postgres_environment)
    @created_databases << name
  end

  def create_private_schema!(database)
    @runner.run!(psql_arguments(database) + ['--command', 'CREATE SCHEMA laravel;'], env: postgres_environment)
  end

  def migrate_and_seed_source!
    run_artisan!(@source_database, 'migrate', '--force', '--no-interaction')
    run_artisan!(@source_database, 'db:seed', '--force', '--no-interaction')
    run_artisan!(@source_database, 'db:seed', '--class=Database\\Seeders\\RecoveryRehearsalSeeder', '--force', '--no-interaction')
  end

  def recovery_snapshot!(database)
    output = run_artisan!(database, 'ops:verify-synthetic-recovery', '--json', '--no-ansi')
    json_line = output.lines.map(&:strip).reject(&:empty?).last
    document = JSON.parse(json_line || '')
    raise CommandFailed, 'Recovery snapshot command did not return a PASS document.' unless document['snapshot_sha256']&.match?(/\A[0-9a-f]{64}\z/)

    JSON.generate(document)
  rescue JSON::ParserError
    raise CommandFailed, 'Recovery snapshot command returned malformed JSON.'
  end

  def acceptance_output!(database)
    @runner.run!(
      psql_arguments(database) + ['--csv', '--file', ACCEPTANCE_SQL],
      env: postgres_environment.merge('TZ' => 'UTC', 'LC_ALL' => 'C')
    )
  end

  def create_backup!(database, path)
    @runner.run!(
      connection_arguments('pg_dump') + [
        '--format=custom', '--compress=9', '--no-owner', '--no-privileges', '--schema=laravel',
        '--file', path, '--dbname', database
      ],
      env: postgres_environment.merge('TZ' => 'UTC', 'LC_ALL' => 'C')
    )
    raise CommandFailed, 'Recovery backup was not created.' unless File.file?(path) && File.size(path).positive?
  end

  def restore_backup!(database, path)
    raise CommandFailed, 'Recovery backup is missing before restore.' unless File.file?(path)

    @runner.run!(
      connection_arguments('pg_restore') + [
        '--exit-on-error', '--single-transaction', '--no-owner', '--no-privileges',
        '--dbname', database, path
      ],
      env: postgres_environment.merge('TZ' => 'UTC', 'LC_ALL' => 'C')
    )
  end

  def verify_laravel_migration_state!(database)
    output = run_artisan!(database, 'migrate:status', '--no-ansi', '--no-interaction')
    raise CommandFailed, 'Restored Laravel migration status did not report completed migrations.' unless output.include?('Ran')
  end

  def verify_plain_dump_equivalence!
    source_schema = File.join(@temporary_directory, 'source-schema.sql')
    restore_schema = File.join(@temporary_directory, 'restore-schema.sql')
    source_data = File.join(@temporary_directory, 'source-data.sql')
    restore_data = File.join(@temporary_directory, 'restore-data.sql')

    plain_dump!(@source_database, source_schema, '--schema-only')
    plain_dump!(@restore_database, restore_schema, '--schema-only')
    plain_dump!(@source_database, source_data, '--data-only', '--inserts', '--column-inserts', '--rows-per-insert=100')
    plain_dump!(@restore_database, restore_data, '--data-only', '--inserts', '--column-inserts', '--rows-per-insert=100')

    assert_file_identical!('normalized schema dump', source_schema, restore_schema)
    assert_file_identical!('ordered logical data dump', source_data, restore_data)

    [Digest::SHA256.file(source_schema).hexdigest, Digest::SHA256.file(source_data).hexdigest]
  end

  def plain_dump!(database, path, *mode)
    @runner.run!(
      connection_arguments('pg_dump') + [
        '--format=plain', '--no-owner', '--no-privileges', '--no-comments',
        '--restrict-key=7f6509b40ae9343e650f40f62aa0f1f9e7d7f4f21f3c7e36de50d90ddc338d0e', '--schema=laravel',
        *mode, '--file', path, '--dbname', database
      ],
      env: postgres_environment.merge('TZ' => 'UTC', 'LC_ALL' => 'C')
    )
  end

  def assert_identical!(label, source, restored)
    raise CommandFailed, "Restored #{label} differs from the source." unless source == restored
  end

  def assert_file_identical!(label, source, restored)
    raise CommandFailed, "Restored #{label} differs from the source." unless FileUtils.compare_file(source, restored)
  end

  def write_evidence!(attempt:, source_snapshot:, source_acceptance:, backup_sha256:, backup_bytes:, schema_sha256:, data_sha256:, backup_elapsed_ms:, recovery_elapsed_ms:)
    FileUtils.mkdir_p(EVIDENCE_DIRECTORY, mode: 0o700)
    timestamp = Time.now.utc.strftime('%Y%m%dT%H%M%SZ')
    path = File.join(EVIDENCE_DIRECTORY, "#{timestamp}-#{attempt}.json")
    head = @runner.run!(['git', '-C', ROOT, 'rev-parse', 'HEAD']).strip
    dirty = !@runner.run!(['git', '-C', ROOT, 'status', '--porcelain']).strip.empty?
    evidence = {
      'schema_version' => 1,
      'kind' => 'SIMRS_LOCAL_POSTGRESQL_RECOVERY_REHEARSAL',
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_ONLY',
      'hosted_readiness_claim' => false,
      'approved_rpo_rto_target_available' => false,
      'baseline_git_sha' => head,
      'working_tree_state' => dirty ? 'UNCOMMITTED_LOCAL_MILESTONE' : 'CLEAN',
      'postgresql_major' => 17,
      'application_schema' => 'laravel',
      'source_snapshot_sha256' => source_snapshot.fetch('snapshot_sha256'),
      'migration_file_set_sha256' => source_snapshot.dig('digests', 'migration_file_set_sha256'),
      'frozen_acceptance_contract_sha256' => ACCEPTANCE_SQL_SHA256,
      'frozen_acceptance_output_sha256' => Digest::SHA256.hexdigest(source_acceptance),
      'backup_sha256' => backup_sha256,
      'backup_bytes' => backup_bytes,
      'schema_dump_sha256' => schema_sha256,
      'data_dump_sha256' => data_sha256,
      'backup_elapsed_ms' => backup_elapsed_ms,
      'recovery_elapsed_ms' => recovery_elapsed_ms,
      'rpo_data_loss_rows' => 0,
      'source_restore_snapshot_match' => true,
      'source_restore_acceptance_match' => true,
      'source_restore_schema_match' => true,
      'source_restore_data_match' => true,
      'temporary_databases_removed_after_run' => true,
      'notes' => [
        'A same-host disposable rehearsal is not failure-domain disaster-recovery evidence.',
        'Zero row loss in a quiesced fixture is not an institutionally approved RPO.',
        'Measured recovery time is a local observation and is not an approved RTO.'
      ]
    }
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    path
  end

  def run_artisan!(database, *arguments)
    @runner.run!(['php', File.join(ROOT, 'artisan'), *arguments], env: application_environment(database))
  end

  def application_environment(database)
    assert_generated_database_name!(database)
    {
      'APP_ENV' => 'testing',
      'APP_KEY' => @application_key,
      'APP_MODE' => 'SIMULATION',
      'APP_SYNTHETIC_ONLY' => 'true',
      'DEMO_SEED_ENABLED' => 'true',
      'DEMO_ACCOUNT_PASSWORD' => @demo_password,
      'SIMRS_RECOVERY_REHEARSAL_CONFIRM' => CONFIRMATION,
      'DB_CONNECTION' => 'pgsql',
      'DB_URL' => '',
      'DB_HOST' => @host,
      'DB_PORT' => @port,
      'DB_DATABASE' => database,
      'DB_USERNAME' => @user,
      'DB_PASSWORD' => @environment.fetch('PGPASSWORD', ''),
      'DB_SCHEMA' => 'laravel',
      'DB_SSLMODE' => 'disable',
      'CACHE_STORE' => 'array',
      'SESSION_DRIVER' => 'array',
      'QUEUE_CONNECTION' => 'sync',
      'LOG_CHANNEL' => 'stderr'
    }
  end

  def psql_arguments(database)
    connection_arguments('psql') + ['--no-psqlrc', '--set', 'ON_ERROR_STOP=1', '--dbname', database]
  end

  def connection_arguments(tool)
    [tool, '--host', @host, '--port', @port, '--username', @user]
  end

  def postgres_environment
    environment = { 'PGCONNECT_TIMEOUT' => '5', 'PGSSLMODE' => 'disable' }
    environment['PGPASSWORD'] = @environment['PGPASSWORD'] if @environment.key?('PGPASSWORD')
    environment
  end

  def elapsed_ms(started)
    ((@clock.call - started) * 1000).round
  end

  def cleanup!(strict: false)
    failures = []

    @created_databases.reverse.each do |database|
      next unless DATABASE_PATTERN.match?(database)

      begin
        arguments = connection_arguments('dropdb') + ['--if-exists', '--force', '--maintenance-db=postgres', database]
        if strict
          @runner.run!(arguments, env: postgres_environment)
          assert_database_absent!(database)
        else
          @runner.run_cleanup(arguments, env: postgres_environment)
        end
        @created_databases.delete(database)
      rescue StandardError => e
        failures << e
      end
    end

    if @temporary_directory && File.directory?(@temporary_directory) && File.basename(@temporary_directory).start_with?('simrs-recovery-')
      begin
        FileUtils.remove_entry_secure(@temporary_directory)
        @temporary_directory = nil
      rescue StandardError => e
        failures << e
      end
    end

    return if failures.empty? || !strict

    raise CommandFailed, 'Recovery rehearsal cleanup could not be verified; no PASS evidence was written.'
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    result = LocalPostgresRecoveryRehearsal.new.run!
    puts JSON.pretty_generate(result)
  rescue LocalPostgresRecoveryRehearsal::CommandFailed => e
    warn "BLOCKED: #{e.message}"
    exit 1
  end
end
