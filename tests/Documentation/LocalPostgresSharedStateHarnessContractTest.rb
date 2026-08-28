# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require_relative '../../scripts/rehearse-local-postgres17-shared-state'

class LocalPostgresSharedStateHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/rehearse-local-postgres17-shared-state.rb')
  WORKER = File.join(ROOT, 'app/Console/Commands/RehearseSharedStateWorkerCommand.php')

  class NoCommandRunner
    def run!(*args, **kwargs)
      raise "Unexpected command before safety boundary: #{args.inspect} #{kwargs.inspect}"
    end

    def run_cleanup(*); end
  end

  class ShutdownFailingRunner
    def run!(*, **)
      raise LocalPostgresSharedStateRehearsal::CommandFailed, 'simulated pg_ctl failure'
    end
  end

  def test_script_and_worker_are_syntactically_valid
    _stdout, ruby_stderr, ruby_status = Open3.capture3('ruby', '-c', SCRIPT)
    _stdout, php_stderr, php_status = Open3.capture3('php', '-l', WORKER)

    assert ruby_status.success?, ruby_stderr
    assert php_status.success?, php_stderr
  end

  def test_explicit_confirmation_is_required_before_any_command
    rehearsal = LocalPostgresSharedStateRehearsal.new(environment: {}, runner: NoCommandRunner.new)

    error = assert_raises(LocalPostgresSharedStateRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/SIMRS_SHARED_STATE_REHEARSAL_CONFIRM/, error.message)
  end

  def test_inherited_state_database_and_executable_overrides_are_rejected_before_any_command
    base = {
      'SIMRS_SHARED_STATE_REHEARSAL_CONFIRM' => LocalPostgresSharedStateRehearsal::CONFIRMATION,
      'PATH' => ENV.fetch('PATH')
    }

    %w[DB_URL DATABASE_URL PGHOST PGSERVICE PGPASSWORD REDIS_URL MEMCACHED_HOST SUPABASE_DB_URL POSTGRES_BIN PSQL PHP_BINARY].each do |name|
      rehearsal = LocalPostgresSharedStateRehearsal.new(
        environment: base.merge(name => 'untrusted-override'),
        runner: NoCommandRunner.new
      )
      error = assert_raises(LocalPostgresSharedStateRehearsal::CommandFailed) { rehearsal.run! }
      assert_match(/refuses inherited state, database, or executable overrides/, error.message)
      assert_includes error.message, name
    end
  end

  def test_runner_does_not_inherit_state_database_or_executable_overrides
    names = %w[DB_URL DATABASE_URL PGHOST PGSERVICE PGPASSWORD REDIS_URL SUPABASE_DB_URL POSTGRES_BIN PSQL]
    originals = names.to_h { |name| [name, ENV[name]] }
    originals.each_key { |name| ENV[name] = 'must-not-cross-runner-boundary' }

    output = LocalPostgresSharedStateRehearsal::Runner.new.run!([
      'ruby', '-e',
      "print #{names.inspect}.select { |key| ENV.key?(key) }.join(',')"
    ])

    assert_equal '', output
  ensure
    originals&.each { |name, value| value.nil? ? ENV.delete(name) : ENV[name] = value }
  end

  def test_harness_owns_a_private_postgresql_17_10_cluster_without_tcp
    source = File.read(SCRIPT)

    assert_equal '17.10', LocalPostgresSharedStateRehearsal::POSTGRES_VERSION
    assert_includes source, "'--auth-local=trust', '--auth-host=reject'"
    assert_includes source, "'-c', 'listen_addresses='"
    assert_includes source, "'-c', 'unix_socket_permissions=0700'"
    assert_includes source, 'permission_bits(@temporary_root) == 0o700'
    assert_includes source, 'permission_bits(@data_directory) == 0o700'
    assert_includes source, 'permission_bits(@socket_directory) == 0o700'
    assert_includes source, "bool_and(auth_method = 'reject')"
    assert_includes source, "'--host', '127.0.0.1'"
    assert_includes source, '@runner.expect_failure!'
    refute_match(/OptionParser|--host=|--database=|POSTGRES_BIN.*fetch/, source)
  end

  def test_laravel_runtime_files_are_redirected_into_the_disposable_root
    source = File.read(SCRIPT)

    assert_includes source, "@application_storage_directory = File.join(@temporary_root, 'laravel-storage')"
    assert_includes source, "'LARAVEL_STORAGE_PATH' => @application_storage_directory"
    assert_includes source, "'APP_CONFIG_CACHE' => File.join(@bootstrap_cache_directory, 'config.php')"
    assert_includes source, "'APP_SERVICES_CACHE' => File.join(@bootstrap_cache_directory, 'services.php')"
    assert_includes source, "'VIEW_COMPILED_PATH' => @compiled_views_directory"
  end

  def test_workers_are_forced_into_private_synthetic_database_shared_state
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    %w[
      APP_MODE APP_SYNTHETIC_ONLY DB_SCHEMA CACHE_STORE CACHE_PREFIX SESSION_DRIVER
      SESSION_ENCRYPT APP_MAINTENANCE_DRIVER APP_MAINTENANCE_STORE MAIL_MAILER QUEUE_CONNECTION
    ].each { |name| assert_includes harness, "'#{name}' =>" }
    assert_includes harness, "'DB_SCHEMA' => 'laravel'"
    assert_includes harness, "'CACHE_STORE' => 'database'"
    assert_includes harness, "'SESSION_DRIVER' => 'database'"
    assert_includes harness, "'SESSION_ENCRYPT' => 'true'"
    assert_includes harness, "'BPJS_INTEGRATION_ENABLED' => 'false'"
    assert_includes harness, "'VCLAIM_ENABLED' => 'false'"
    assert_includes harness, "'SATUSEHAT_ENABLED' => 'false'"
    assert_includes worker, "config('simulation.mode') !== 'SIMULATION'"
    assert_includes worker, "SchemaQualifier::primarySchema() !== 'laravel'"
    assert_includes worker, "where('is_synthetic', false)->exists()"
    assert_includes worker, "DB::scalar('SHOW server_version_num')"
  end

  def test_framework_tables_resolve_only_in_the_private_schema
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    %w[cache cache_locks sessions].each do |table|
      assert_includes harness, "to_regclass('laravel.#{table}')"
      assert_includes harness, "to_regclass('public.#{table}') IS NULL"
      assert_includes worker, "to_regclass('laravel.#{table}')"
    end
    assert_includes worker, 'current_schema() AS current_schema'
  end

  def test_independent_process_maintenance_counter_and_lock_contracts_are_explicit
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, "run_artisan!('down', '--retry=60', '--no-interaction')"
    assert_includes harness, "phase: 'maintenance-read', worker: 'B', expect: 'active'"
    assert_includes harness, "environment: divergent_prefix_environment"
    assert_includes harness, "illuminate:foundation:down'"
    refute_includes harness, 'WHERE key LIKE'
    assert_includes harness, "values == [1, 2]"
    assert_includes harness, "wait_event = 'PgSleep'"
    assert_includes harness, "phase: 'lock-attempt', worker: 'B', expect: 'rejected'"
    assert_includes harness, 'assert_independent_backends!'
    assert_includes worker, "Cache::store('database')->increment"
    assert_includes worker, 'Cache::lock($this->lockKey($runToken), 10)'
    assert_includes worker, '$this->releaseRequired($lock)'
  end

  def test_encrypted_session_continuity_and_divergence_are_distinguished
    harness = File.read(SCRIPT)
    worker = File.read(WORKER)

    assert_includes harness, "phase: 'session-write', worker: 'A', expect: 'written'"
    assert_includes harness, "phase: 'session-read', worker: 'B', expect: 'present'"
    assert_includes harness, "environment: divergent_key_environment"
    assert_includes harness, "'application_key_session_rejection_observed' => true"
    assert_includes harness, "environment: { 'DB_SCHEMA' => 'preview_probe' }"
    assert_includes harness, '@runner.expect_blocked_failure!'
    assert_includes worker, 'instanceof EncryptedStore'
    assert_includes worker, "$session->setId($this->sessionId($runToken))"
    refute_includes worker, 'CACHE_PREFIX', 'Database sessions must not be claimed as cache-prefix scoped.'
  end

  def test_cleanup_is_closed_exact_and_precedes_aggregate_evidence
    source = File.read(SCRIPT)

    assert_includes source, 'DATABASE_PATTERN.match?(@created_database)'
    assert_includes source, "'--if-exists', '--force', '--maintenance-db=postgres', @created_database"
    assert_includes source, 'assert_database_absent!(@created_database)'
    assert_includes source, 'FileUtils.remove_entry_secure(@temporary_root)'
    assert_includes source, "Process.kill('TERM', wait_thread.pid)"
    assert_includes source, "Process.kill('KILL', wait_thread.pid)"
    assert_includes source, 'Owned PostgreSQL process survived cleanup; its directory was preserved.'
    assert_operator source.index('if @postgres_process'), :<, source.index('FileUtils.remove_entry_secure(@temporary_root)')
    assert_includes source, 'cleanup!(strict: true)'
    assert_operator source.index('cleanup!(strict: true)'), :<, source.index('evidence_path = write_evidence!')
    refute_includes source, 'migrate:fresh'
    refute_includes source, 'simulation:reset'
  end

  def test_failed_pg_ctl_terminates_the_exact_owned_child_before_removing_its_directory
    temporary_root = Dir.mktmpdir('simrs-shared-state-', '/tmp')
    data_directory = File.join(temporary_root, 'cluster')
    socket_directory = File.join(temporary_root, 'socket')
    FileUtils.mkdir_p([data_directory, socket_directory])
    stdin, stdout, stderr, wait_thread = Open3.popen3('ruby', '-e', 'sleep 30')
    stdin.close
    stdout_reader = Thread.new { stdout.read }
    stderr_reader = Thread.new { stderr.read }

    rehearsal = LocalPostgresSharedStateRehearsal.new(environment: {}, runner: ShutdownFailingRunner.new)
    rehearsal.instance_variable_set(:@temporary_root, temporary_root)
    rehearsal.instance_variable_set(:@data_directory, data_directory)
    rehearsal.instance_variable_set(:@socket_directory, socket_directory)
    rehearsal.instance_variable_set(:@port, 32_001)
    rehearsal.instance_variable_set(:@cluster_user, 'synthetic_runner')
    rehearsal.instance_variable_set(:@tools, { 'pg_ctl' => '/closed/fake-pg-ctl' })
    rehearsal.instance_variable_set(
      :@postgres_process,
      [stdout, stderr, wait_thread, stdout_reader, stderr_reader]
    )

    error = assert_raises(LocalPostgresSharedStateRehearsal::CommandFailed) do
      rehearsal.send(:cleanup!, strict: true)
    end

    assert_match(/no PASS evidence was written/, error.message)
    refute wait_thread.alive?
    refute File.exist?(temporary_root)
  ensure
    if defined?(wait_thread) && wait_thread&.alive?
      Process.kill('KILL', wait_thread.pid)
      wait_thread.value
    end
    FileUtils.remove_entry_secure(temporary_root) if defined?(temporary_root) && File.exist?(temporary_root)
  end

  def test_evidence_is_mode_0600_aggregate_scrubbed_and_explicitly_not_hosted
    source = File.read(SCRIPT)
    evidence_method = source[/def write_evidence!.*?^  end/m]

    refute_nil evidence_method
    assert_includes source, "'files' => hashes"
    assert_includes source, 'Digest::SHA256.file(path).hexdigest'
    assert_includes source, 'source_revision_before = source_revision!'
    assert_includes source, 'source_revision_after = source_revision!'
    assert_includes source, 'assert_source_revision_stable!(source_revision_before, source_revision_after)'
    assert_includes source, "'stable_during_execution' => true"
    assert_includes source, 'composer.lock'
    assert_includes source, 'vendor/composer/installed.php'
    assert_includes source, "'laravel_framework' => laravel_framework_identity!"
    assert_includes source, 'Composer\\\\InstalledVersions::getPrettyVersion'
    assert_includes source, 'Installed Laravel runtime identity does not match composer.lock.'
    assert_includes source, "'runtime_installed_match' => true"
    assert_includes evidence_method, 'perm: 0o600'
    assert_includes evidence_method, 'File.chmod(0o600, path)'
    assert_includes evidence_method, "'hosted_shared_state_claim' => false"
    assert_includes evidence_method, "'supabase_pooler_claim' => false"
    assert_includes evidence_method, "'real_patient_data_rows' => 0"
    assert_includes source, 'File.lstat(expanded_parent)'
    assert_includes source, 'File.lstat(expanded_directory)'
    assert_includes source, '!directory_stat.symlink?'
    assert_includes source, 'File.realpath(expanded_directory) == expanded_directory'
    %w[@database @run_token @application_key @divergent_application_key backend_pid session_id cache_prefix DB_PASSWORD DEMO_ACCOUNT_PASSWORD].each do |secret_shape|
      refute_includes evidence_method, secret_shape
    end
  end

  def test_evidence_directory_rejects_a_symlink_before_writing
    parent = File.realpath(Dir.mktmpdir('simrs-evidence-parent-'))
    outside = File.realpath(Dir.mktmpdir('simrs-evidence-outside-'))
    symlink = File.join(parent, 'shared-state-rehearsals')
    File.symlink(outside, symlink)
    rehearsal = LocalPostgresSharedStateRehearsal.new(environment: {})

    error = assert_raises(LocalPostgresSharedStateRehearsal::CommandFailed) do
      rehearsal.send(:prepare_evidence_directory!, symlink, parent)
    end

    assert_match(/never a symlink/, error.message)
    assert_empty Dir.children(outside)
  ensure
    FileUtils.remove_entry_secure(parent) if defined?(parent) && File.exist?(parent)
    FileUtils.remove_entry_secure(outside) if defined?(outside) && File.exist?(outside)
  end
end
