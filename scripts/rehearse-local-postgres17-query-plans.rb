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

class LocalPostgresQueryPlanRehearsal
  ROOT = File.expand_path('..', __dir__)
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_POSTGRES17_QUERY_PLANS'
  DATABASE_PATTERN = /\Asimrs_queryplan_[0-9a-f]{12}\z/
  FIXTURE_SQL = File.join(ROOT, 'docs/operations/T1_LOCAL_POSTGRESQL_QUERY_PLAN_FIXTURE_2026-08-26.sql')
  EVIDENCE_DIRECTORY = File.join(ROOT, 'storage/app/query-plan-rehearsals')
  INDEX_MIGRATION_PATH = 'database/migrations/2026_08_26_000100_add_operational_worklist_indexes.php'
  QUERY_SOURCE_FILES = %w[
    app/Http/Controllers/Clinical/LaboratoryController.php
    app/Http/Controllers/Outpatient/OutpatientExaminationController.php
    app/Http/Controllers/Outpatient/OutpatientRecapController.php
    app/Http/Controllers/Outpatient/OutpatientRegistrationController.php
    app/Models/Encounter.php
    app/Models/DailyQueueCounter.php
    app/Models/LabServiceRequest.php
    app/Models/Patient.php
    app/Support/Registration/DailyQueueAllocator.php
  ].freeze

  PROBES = {
    'registration_today_cast' => <<~SQL,
      SELECT e.*
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.registered_at::date = CURRENT_DATE
      ORDER BY e.registered_at DESC, e.id DESC
      LIMIT 50
    SQL
    'registration_today_range' => <<~SQL,
      SELECT e.*
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.registered_at >= CURRENT_DATE
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
      ORDER BY e.registered_at DESC, e.id DESC
      LIMIT 50
    SQL
    'examination_31_day_range' => <<~SQL,
      SELECT e.*
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.status IN ('REGISTERED', 'IN_EXAMINATION', 'READY_FOR_RM')
        AND e.registered_at >= CURRENT_DATE - INTERVAL '30 days'
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
      ORDER BY e.registered_at, e.id
      LIMIT 100
    SQL
    'laboratory_active' => <<~SQL,
      SELECT l.*
      FROM laravel.lab_service_requests AS l
      WHERE EXISTS (
          SELECT 1
          FROM laravel.encounters AS e
          WHERE e.id = l.encounter_id
            AND EXISTS (
                SELECT 1 FROM laravel.patients AS p
                WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
            )
      )
        AND l.status = 'ACTIVE'
      ORDER BY l.requested_at, l.id
      LIMIT 100
    SQL
    'recap_31_day_range' => <<~SQL,
      SELECT e.*
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.registered_at >= CURRENT_DATE - INTERVAL '30 days'
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
      ORDER BY e.registered_at DESC, e.id DESC
      LIMIT 500
    SQL
    'registration_today_count_range' => <<~SQL,
      SELECT count(*)
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.registered_at >= CURRENT_DATE
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
    SQL
    'examination_31_day_count' => <<~SQL,
      SELECT count(*)
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.status IN ('REGISTERED', 'IN_EXAMINATION', 'READY_FOR_RM')
        AND e.registered_at >= CURRENT_DATE - INTERVAL '30 days'
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
    SQL
    'laboratory_active_count' => <<~SQL,
      SELECT count(*)
      FROM laravel.lab_service_requests AS l
      WHERE EXISTS (
          SELECT 1
          FROM laravel.encounters AS e
          WHERE e.id = l.encounter_id
            AND EXISTS (
                SELECT 1 FROM laravel.patients AS p
                WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
            )
      )
        AND l.status = 'ACTIVE'
    SQL
    'recap_online_count' => <<~SQL,
      SELECT count(*)
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.registered_at >= CURRENT_DATE - INTERVAL '30 days'
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
        AND e.booking_code IS NOT NULL
        AND e.booking_code <> ''
    SQL
    'recap_csv_max_id' => <<~SQL,
      SELECT max(e.id)
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.registered_at >= CURRENT_DATE - INTERVAL '30 days'
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
    SQL
    'recap_csv_first_chunk' => <<~SQL,
      SELECT e.*
      FROM laravel.encounters AS e
      WHERE EXISTS (
          SELECT 1 FROM laravel.patients AS p
          WHERE p.id = e.patient_id AND p.is_synthetic = TRUE
      )
        AND e.care_setting = 'OUTPATIENT'
        AND e.registered_at >= CURRENT_DATE - INTERVAL '30 days'
        AND e.registered_at < CURRENT_DATE + INTERVAL '1 day'
        AND e.id <= (SELECT max(id) FROM laravel.encounters)
      ORDER BY e.id DESC
      LIMIT 500
    SQL
    'daily_queue_counter_lookup' => <<~SQL,
      SELECT c.last_number
      FROM laravel.daily_queue_counters AS c
      WHERE c.queue_date = CURRENT_DATE
    SQL
    'patient_search_leading_wildcard' => <<~SQL,
      SELECT p.*
      FROM laravel.patients AS p
      WHERE p.is_synthetic = TRUE
        AND (
          p.full_name ILIKE '%Sintetis Kinerja 000000000123%'
          OR p.medical_record_number ILIKE '%000000000123%'
          OR p.nik ILIKE '%000000000123%'
        )
      ORDER BY p.full_name
      LIMIT 21
    SQL
    'patient_search_exact_mrn' => <<~SQL
      SELECT p.*
      FROM laravel.patients AS p
      WHERE p.is_synthetic = TRUE
        AND p.medical_record_number = 'SYNTH-PERF-000000000123'
      ORDER BY p.full_name
      LIMIT 21
    SQL
  }.freeze

  CANDIDATE_INDEXES = {
    'encounters_care_registered_id_idx' =>
      'CREATE INDEX IF NOT EXISTS encounters_care_registered_id_idx ON laravel.encounters (care_setting, registered_at, id)',
    'lab_requests_status_requested_id_idx' =>
      'CREATE INDEX IF NOT EXISTS lab_requests_status_requested_id_idx ON laravel.lab_service_requests (status, requested_at, id)'
  }.freeze

  SELECTIVE_CANDIDATE_ASSERTIONS = {
    'registration_today_range' => ['encounters_care_registered_id_idx', 'encounters'],
    'examination_31_day_range' => ['encounters_care_registered_id_idx', 'encounters'],
    'laboratory_active' => ['lab_requests_status_requested_id_idx', 'lab_service_requests'],
    'recap_31_day_range' => ['encounters_care_registered_id_idx', 'encounters']
  }.freeze

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

      reason = sanitize(stderr)
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

    def sanitize(stderr)
      stderr.lines.map(&:strip).find { |line| !line.empty? }.to_s
        .gsub(/simrs_queryplan_[0-9a-f]{12}/, '<disposable-db>')
        .gsub(%r{postgres(?:ql)?://\S+}i, '<redacted-dsn>')
        .gsub(/(password|secret|token)\s*[=:]\s*\S+/i, '\\1=<redacted>')
        .slice(0, 300)
    end
  end

  def initialize(environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @environment = environment
    @runner = runner
    @clock = monotonic_clock || -> { Process.clock_gettime(Process::CLOCK_MONOTONIC) }
    @created_database = nil
    @application_key = "base64:#{Base64.strict_encode64(SecureRandom.random_bytes(32))}"
    @demo_password = SecureRandom.hex(24)
  end

  def run!
    assert_operator_confirmation!
    configure_connection!
    assert_fixed_contracts!
    verify_tools_and_server!

    @database = "simrs_queryplan_#{SecureRandom.hex(6)}"
    assert_database_name!(@database)
    assert_database_absent!(@database)
    create_database!
    create_private_schema!

    started = @clock.call
    migrate_and_seed!
    load_fixture!
    fixture_elapsed_ms = elapsed_ms(started)
    counts = fixture_counts!
    baseline_plans = PROBES.transform_values { |sql| explain!(sql) }
    create_candidate_indexes!
    candidate_plans = PROBES.transform_values { |sql| explain!(sql) }
    candidate_assertions = assert_candidate_plans!(candidate_plans)
    migration_round_trip = verify_index_migration_round_trip!
    source_revision = source_revision!

    cleanup!(strict: true)
    evidence_path = write_evidence!(
      counts: counts,
      baseline_plans: baseline_plans,
      candidate_plans: candidate_plans,
      candidate_assertions: candidate_assertions,
      migration_round_trip: migration_round_trip,
      source_revision: source_revision,
      fixture_elapsed_ms: fixture_elapsed_ms
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_QUERY_PLAN_ONLY',
      'evidence_path' => evidence_path,
      'probe_count' => baseline_plans.length,
      'candidate_index_count' => CANDIDATE_INDEXES.length,
      'fixture_counts' => counts
    }
  ensure
    cleanup!
  end

  private

  def assert_operator_confirmation!
    return if @environment['SIMRS_QUERY_PLAN_REHEARSAL_CONFIRM'] == CONFIRMATION

    raise CommandFailed, "Set SIMRS_QUERY_PLAN_REHEARSAL_CONFIRM=#{CONFIRMATION} to authorize disposable local database creation."
  end

  def configure_connection!
    @host = @environment.fetch('PGHOST', '').strip
    @host = '127.0.0.1' if @host.empty?
    allowed_hosts = ['127.0.0.1', 'localhost', '::1', '/tmp', '/private/tmp']
    raise CommandFailed, 'Query-plan rehearsal refuses a non-local PostgreSQL host.' unless allowed_hosts.include?(@host)

    @port = @environment.fetch('PGPORT', '5432')
    unless @port.match?(/\A[0-9]{1,5}\z/) && @port.to_i.between?(1, 65_535)
      raise CommandFailed, 'Query-plan rehearsal requires a valid local PostgreSQL port.'
    end

    @user = @environment.fetch('PGUSER', '').strip
    @user = Etc.getpwuid.name if @user.empty?
    unless @user.match?(/\A[A-Za-z_][A-Za-z0-9_.-]{0,62}\z/)
      raise CommandFailed, 'Query-plan rehearsal requires a simple PostgreSQL role name.'
    end
  end

  def assert_fixed_contracts!
    raise CommandFailed, 'Query-plan rehearsal must run from its repository-owned location.' unless File.realpath(ROOT) == ROOT
    raise CommandFailed, 'The query-plan fixture is missing.' unless File.file?(FIXTURE_SQL)
    raise CommandFailed, 'The query-plan fixture must not be a symlink.' if File.symlink?(FIXTURE_SQL)
  end

  def verify_tools_and_server!
    %w[psql createdb dropdb php git].each do |tool|
      @runner.run!([tool, '--version'], env: postgres_environment)
    end

    version_num = @runner.run!(
      psql_arguments('postgres') + ['--tuples-only', '--no-align', '--command', 'SHOW server_version_num'],
      env: postgres_environment
    ).strip
    raise CommandFailed, 'Query-plan rehearsal requires PostgreSQL major version 17.' unless version_num.match?(/\A17[0-9]{4}\z/)

    server_is_local = @runner.run!(
      psql_arguments('postgres') + [
        '--tuples-only', '--no-align', '--command',
        <<~SQL
          SELECT inet_server_addr() IS NULL
              OR inet_server_addr() <<= inet '127.0.0.0/8'
              OR inet_server_addr() = inet '::1'
        SQL
      ],
      env: postgres_environment
    ).strip
    raise CommandFailed, 'Query-plan rehearsal refuses a PostgreSQL server whose actual address is not local.' unless server_is_local == 't'
  end

  def assert_database_name!(database)
    raise CommandFailed, 'Generated query-plan database name failed its closed pattern.' unless DATABASE_PATTERN.match?(database)
  end

  def assert_database_absent!(database)
    assert_database_name!(database)
    output = @runner.run!(
      psql_arguments('postgres') + [
        '--tuples-only', '--no-align', '--command',
        "SELECT datname FROM pg_database WHERE datname LIKE 'simrs_queryplan_%' ORDER BY datname"
      ],
      env: postgres_environment
    )
    existing = output.lines.map(&:strip).reject(&:empty?)
    raise CommandFailed, 'Query-plan rehearsal refuses a pre-existing generated database.' if existing.include?(database)
  end

  def create_database!
    @runner.run!(connection_arguments('createdb') + ['--maintenance-db=postgres', @database], env: postgres_environment)
    @created_database = @database
  end

  def create_private_schema!
    @runner.run!(psql_arguments(@database) + ['--command', 'CREATE SCHEMA laravel;'], env: postgres_environment)
  end

  def migrate_and_seed!
    run_artisan!('migrate', '--force', '--no-interaction')
    run_artisan!('db:seed', '--force', '--no-interaction')
  end

  def load_fixture!
    @runner.run!(
      psql_arguments(@database) + ['--file', FIXTURE_SQL],
      env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
    )
  end

  def fixture_counts!
    output = @runner.run!(
      psql_arguments(@database) + [
        '--tuples-only', '--no-align', '--field-separator', ',', '--command',
        <<~SQL
          SELECT
            (SELECT count(*) FROM laravel.patients WHERE medical_record_number LIKE 'SYNTH-PERF-%'),
            (SELECT count(*) FROM laravel.encounters WHERE public_id LIKE 'PERF-ENC-%'),
            (SELECT count(*) FROM laravel.lab_service_requests WHERE public_id LIKE 'PERF-LAB-%'),
            (SELECT count(*) FROM laravel.patients WHERE is_synthetic = FALSE)
        SQL
      ],
      env: postgres_environment
    ).strip
    patients, encounters, lab_requests, non_synthetic_patients = output.split(',').map { |value| Integer(value, 10) }
    counts = {
      'patients' => patients,
      'encounters' => encounters,
      'lab_requests' => lab_requests,
      'non_synthetic_patients' => non_synthetic_patients
    }
    unless counts == {
      'patients' => 30_000,
      'encounters' => 30_000,
      'lab_requests' => 12_000,
      'non_synthetic_patients' => 0
    }
      raise CommandFailed, 'Query-plan fixture counts or synthetic-only boundary did not match the reviewed contract.'
    end

    counts
  rescue ArgumentError
    raise CommandFailed, 'Query-plan fixture count output was malformed.'
  end

  def explain!(sql)
    output = @runner.run!(
      psql_arguments(@database) + [
        '--quiet', '--tuples-only', '--no-align', '--command',
        <<~SQL
          BEGIN READ ONLY;
          SET LOCAL TIME ZONE 'Asia/Jakarta';
          SET LOCAL statement_timeout = '5s';
          SET LOCAL lock_timeout = '1s';
          SET LOCAL idle_in_transaction_session_timeout = '10s';
          EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON, TIMING OFF, SUMMARY ON) #{sql};
          COMMIT;
        SQL
      ],
      env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
    ).strip
    plan_document = JSON.parse(output)
    root = plan_document.fetch(0).fetch('Plan')
    nodes = flatten_plan(root)

    {
      'plan_sha256' => Digest::SHA256.hexdigest(JSON.generate(plan_document)),
      'execution_time_ms' => plan_document.fetch(0).fetch('Execution Time'),
      'planning_time_ms' => plan_document.fetch(0).fetch('Planning Time'),
      'root_node_type' => root.fetch('Node Type'),
      'node_types' => nodes.map { |node| node['Node Type'] }.compact.uniq.sort,
      'index_names' => nodes.map { |node| node['Index Name'] }.compact.uniq.sort,
      'sequential_scan_relations' => nodes.map do |node|
        node['Relation Name'] if node['Node Type'] == 'Seq Scan'
      end.compact.uniq.sort,
      'shared_hit_blocks' => root.fetch('Shared Hit Blocks', 0),
      'shared_read_blocks' => root.fetch('Shared Read Blocks', 0),
      'actual_rows' => root.fetch('Actual Rows'),
      'raw_plan' => plan_document
    }
  rescue JSON::ParserError, KeyError, TypeError
    raise CommandFailed, 'PostgreSQL returned a malformed query-plan document.'
  end

  def create_candidate_indexes!
    CANDIDATE_INDEXES.each_value do |sql|
      @runner.run!(
        psql_arguments(@database) + [
          '--command',
          "SET statement_timeout = '30s'; SET lock_timeout = '1s'; #{sql}"
        ],
        env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
      )
    end
    @runner.run!(
      psql_arguments(@database) + [
        '--command',
        'ANALYZE laravel.encounters; ANALYZE laravel.lab_service_requests;'
      ],
      env: postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')
    )
  end

  def assert_candidate_plans!(candidate_plans)
    SELECTIVE_CANDIDATE_ASSERTIONS.map do |probe, (expected_index, target_relation)|
      plan = candidate_plans.fetch(probe)
      unless plan.fetch('index_names').include?(expected_index)
        raise CommandFailed, "Candidate plan #{probe} did not select its reviewed composite index."
      end
      if plan.fetch('sequential_scan_relations').include?(target_relation)
        raise CommandFailed, "Candidate plan #{probe} still sequentially scanned its target operational table."
      end

      raw_nodes = flatten_plan(plan.fetch('raw_plan').fetch(0).fetch('Plan'))
      if raw_nodes.any? { |node| node['Sort Space Type'] == 'Disk' }
        raise CommandFailed, "Candidate plan #{probe} spilled a sort to disk."
      end
      temp_blocks = plan.fetch('raw_plan').fetch(0).fetch('Plan').fetch('Temp Read Blocks', 0) +
        plan.fetch('raw_plan').fetch(0).fetch('Plan').fetch('Temp Written Blocks', 0)
      raise CommandFailed, "Candidate plan #{probe} used temporary disk blocks." unless temp_blocks.zero?
      raise CommandFailed, "Candidate plan #{probe} returned no rows from the reviewed fixture." unless plan.fetch('actual_rows').positive?

      {
        'probe' => probe,
        'expected_index' => expected_index,
        'target_relation' => target_relation,
        'target_sequential_scan' => false,
        'disk_sort' => false,
        'temporary_blocks' => 0,
        'returned_rows' => plan.fetch('actual_rows'),
        'status' => 'PASS'
      }
    end
  rescue KeyError, TypeError
    raise CommandFailed, 'Candidate plan assertion input was malformed.'
  end

  def verify_index_migration_round_trip!
    run_artisan!('migrate:rollback', "--path=#{INDEX_MIGRATION_PATH}", '--force', '--no-interaction')
    remaining_after_rollback = installed_candidate_indexes
    unless remaining_after_rollback.empty?
      raise CommandFailed, 'Operational index migration rollback left a reviewed index installed.'
    end

    run_artisan!('migrate', "--path=#{INDEX_MIGRATION_PATH}", '--force', '--no-interaction')
    installed_after_reapply = installed_candidate_indexes
    unless installed_after_reapply.sort == CANDIDATE_INDEXES.keys.sort
      raise CommandFailed, 'Operational index migration reapply did not restore both reviewed indexes.'
    end

    {
      'rollback_removed_indexes' => true,
      'reapply_restored_indexes' => true,
      'installed_indexes' => installed_after_reapply.sort,
      'status' => 'PASS'
    }
  end

  def installed_candidate_indexes
    names = CANDIDATE_INDEXES.keys.map { |name| "'#{name}'" }.join(', ')
    output = @runner.run!(
      psql_arguments(@database) + [
        '--tuples-only', '--no-align', '--command',
        <<~SQL
          SELECT indexname
          FROM pg_indexes
          WHERE schemaname = 'laravel'
            AND indexname IN (#{names})
          ORDER BY indexname
        SQL
      ],
      env: postgres_environment
    )
    output.lines.map(&:strip).reject(&:empty?)
  end

  def flatten_plan(node)
    [node] + node.fetch('Plans', []).flat_map { |child| flatten_plan(child) }
  end

  def source_revision!
    {
      'head' => @runner.run!(['git', '-C', ROOT, 'rev-parse', 'HEAD']).strip,
      'working_tree_dirty' => !@runner.run!(['git', '-C', ROOT, 'status', '--porcelain']).strip.empty?,
      'migration_file_set_sha256' => migration_file_set_sha256,
      'fixture_sql_sha256' => Digest::SHA256.file(FIXTURE_SQL).hexdigest,
      'probe_contract_sha256' => Digest::SHA256.hexdigest(PROBES.map { |name, sql| "#{name}\n#{sql}" }.join("\n")),
      'harness_sha256' => Digest::SHA256.file(__FILE__).hexdigest,
      'contract_test_sha256' => Digest::SHA256.file(File.join(ROOT, 'tests/Documentation/LocalPostgresQueryPlanHarnessContractTest.rb')).hexdigest,
      'application_query_sources_sha256' => application_query_sources_sha256
    }
  end

  def migration_file_set_sha256
    rows = Dir[File.join(ROOT, 'database/migrations/*.php')].sort.map do |path|
      "#{File.basename(path)} #{Digest::SHA256.file(path).hexdigest}"
    end
    Digest::SHA256.hexdigest(rows.join("\n"))
  end

  def application_query_sources_sha256
    rows = QUERY_SOURCE_FILES.map do |relative_path|
      path = File.join(ROOT, relative_path)
      raise CommandFailed, "Query source #{relative_path} is missing." unless File.file?(path)

      "#{relative_path} #{Digest::SHA256.file(path).hexdigest}"
    end
    Digest::SHA256.hexdigest(rows.join("\n"))
  end

  def write_evidence!(counts:, baseline_plans:, candidate_plans:, candidate_assertions:, migration_round_trip:, source_revision:, fixture_elapsed_ms:)
    FileUtils.mkdir_p(EVIDENCE_DIRECTORY, mode: 0o700)
    evidence = {
      'schema_version' => 1,
      'kind' => 'SIMRS_LOCAL_POSTGRESQL_QUERY_PLAN_REHEARSAL',
      'status' => 'PASS',
      'captured_at' => Time.now.utc.iso8601,
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'database_engine' => 'postgresql',
        'database_major' => 17,
        'application_schema' => 'laravel',
        'query_timezone' => 'Asia/Jakarta',
        'disposable_database' => true,
        'hosted_latency_claim' => false,
        'capacity_or_sla_claim' => false
      },
      'source_revision' => source_revision,
      'fixture_counts' => counts,
      'fixture_elapsed_ms' => fixture_elapsed_ms,
      'candidate_indexes' => CANDIDATE_INDEXES.keys,
      'candidate_assertions' => candidate_assertions,
      'migration_round_trip' => migration_round_trip,
      'baseline_plans' => baseline_plans,
      'candidate_plans' => candidate_plans,
      'interpretation' => 'Local single-user plan topology and buffer evidence only; not hosted latency, concurrency, capacity, or SLA acceptance.'
    }
    digest = Digest::SHA256.hexdigest(JSON.generate(evidence))
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{digest[0, 12]}.json")
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'w', perm: 0o600)
    path
  end

  def run_artisan!(*arguments)
    @runner.run!(['php', File.join(ROOT, 'artisan'), *arguments], env: application_environment)
  end

  def application_environment
    {
      'APP_ENV' => 'testing',
      'APP_KEY' => @application_key,
      'APP_MODE' => 'SIMULATION',
      'APP_SYNTHETIC_ONLY' => 'true',
      'DEMO_SEED_ENABLED' => 'true',
      'DEMO_ACCOUNT_PASSWORD' => @demo_password,
      'DB_CONNECTION' => 'pgsql',
      'DB_URL' => '',
      'DB_HOST' => @host,
      'DB_PORT' => @port,
      'DB_DATABASE' => @database,
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
    return unless @created_database && DATABASE_PATTERN.match?(@created_database)

    arguments = connection_arguments('dropdb') + [
      '--if-exists', '--force', '--maintenance-db=postgres', @created_database
    ]
    if strict
      @runner.run!(arguments, env: postgres_environment)
      assert_database_absent!(@created_database)
    else
      @runner.run_cleanup(arguments, env: postgres_environment)
    end
    @created_database = nil
  rescue StandardError
    raise if strict
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    result = LocalPostgresQueryPlanRehearsal.new.run!
    puts JSON.pretty_generate(result)
  rescue LocalPostgresQueryPlanRehearsal::CommandFailed => e
    warn "BLOCKED: #{e.message}"
    exit 1
  end
end
