# frozen_string_literal: true

require 'minitest/autorun'
require 'open3'
require_relative '../../scripts/rehearse-local-postgres17-query-plans'

class LocalPostgresQueryPlanHarnessContractTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  SCRIPT = File.join(ROOT, 'scripts/rehearse-local-postgres17-query-plans.rb')
  FIXTURE = File.join(ROOT, 'docs/operations/T1_LOCAL_POSTGRESQL_QUERY_PLAN_FIXTURE_2026-08-26.sql')
  INDEX_MIGRATION = File.join(ROOT, 'database/migrations/2026_08_26_000100_add_operational_worklist_indexes.php')

  class NoCommandRunner
    def run!(*args, **kwargs)
      raise "Unexpected command before safety boundary: #{args.inspect} #{kwargs.inspect}"
    end

    def run_cleanup(*); end
  end

  def test_script_is_valid_ruby
    _stdout, stderr, status = Open3.capture3('ruby', '-c', SCRIPT)

    assert status.success?, stderr
    refute_includes File.read(SCRIPT), '.filter_map'
  end

  def test_explicit_confirmation_is_required_before_any_command
    rehearsal = LocalPostgresQueryPlanRehearsal.new(environment: {}, runner: NoCommandRunner.new)

    error = assert_raises(LocalPostgresQueryPlanRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/SIMRS_QUERY_PLAN_REHEARSAL_CONFIRM/, error.message)
  end

  def test_non_local_host_is_rejected_before_any_command
    rehearsal = LocalPostgresQueryPlanRehearsal.new(
      environment: {
        'SIMRS_QUERY_PLAN_REHEARSAL_CONFIRM' => LocalPostgresQueryPlanRehearsal::CONFIRMATION,
        'PGHOST' => 'db.example.invalid'
      },
      runner: NoCommandRunner.new
    )

    error = assert_raises(LocalPostgresQueryPlanRehearsal::CommandFailed) { rehearsal.run! }
    assert_match(/non-local PostgreSQL host/, error.message)
  end

  def test_closed_database_namespace_and_verified_cleanup_are_present
    source = File.read(SCRIPT)

    assert_includes source, 'simrs_queryplan_#{SecureRandom.hex(6)}'
    assert_includes source, 'DATABASE_PATTERN.match?(database)'
    assert_includes source, '@created_database = @database'
    assert_includes source, 'cleanup!(strict: true)'
    assert_operator source.index('cleanup!(strict: true)'), :<, source.index('write_evidence!(')
    refute_match(/OptionParser|--database|--root|SIMRS_QUERY_PLAN_EVIDENCE_DIR/, source)
    refute_includes source, 'migrate:fresh'
    refute_includes source, '--clean'
    refute_includes source, 'simulation:reset'
  end

  def test_fixture_and_runtime_are_synthetic_private_and_bounded
    source = File.read(SCRIPT)
    fixture = File.read(FIXTURE)

    assert_includes source, "'APP_MODE' => 'SIMULATION'"
    assert_includes source, "'APP_SYNTHETIC_ONLY' => 'true'"
    assert_includes source, "'DB_SCHEMA' => 'laravel'"
    assert_includes source, "'DB_URL' => ''"
    assert_includes source, "'non_synthetic_patients' => 0"
    assert_includes fixture, 'generate_series(1, 30000)'
    assert_includes fixture, "'Pasien Sintetis Kinerja '"
    assert_includes fixture, "'SYNTH-PERF-'"
    assert_includes fixture, "SET TIME ZONE 'Asia/Jakarta'"
    assert_includes fixture, 'queue_assignments.queue_number'
    assert_includes fixture, 'queue_assignments.queue_date'
    assert_includes fixture, 'INSERT INTO laravel.daily_queue_counters'
    assert_includes fixture, 'greatest(daily_queue_counters.last_number, EXCLUDED.last_number)'
    assert_includes fixture, 'ANALYZE laravel.encounters'
    assert_includes source, "postgres_environment.merge('TZ' => 'Asia/Jakarta', 'LC_ALL' => 'C')"
  end

  def test_workload_compares_cast_and_half_open_date_shapes
    probes = LocalPostgresQueryPlanRehearsal::PROBES

    assert_includes probes.fetch('registration_today_cast'), 'registered_at::date = CURRENT_DATE'
    assert_includes probes.fetch('registration_today_range'), 'registered_at >= CURRENT_DATE'
    assert_includes probes.fetch('registration_today_range'), "registered_at < CURRENT_DATE + INTERVAL '1 day'"
    assert_includes probes.fetch('laboratory_active'), "l.status = 'ACTIVE'"
    assert_includes probes.fetch('recap_31_day_range'), 'LIMIT 500'
    assert_includes probes.fetch('daily_queue_counter_lookup'), 'FROM laravel.daily_queue_counters AS c'
    assert_includes probes.fetch('daily_queue_counter_lookup'), 'c.queue_date = CURRENT_DATE'
    refute_includes probes.keys, 'daily_queue_max_range'
  end

  def test_candidate_indexes_are_measured_before_any_migration_is_added
    indexes = LocalPostgresQueryPlanRehearsal::CANDIDATE_INDEXES
    source = File.read(SCRIPT)

    assert_equal 2, indexes.length
    assert_includes indexes.keys, 'encounters_care_registered_id_idx'
    assert_includes indexes.keys, 'lab_requests_status_requested_id_idx'
    assert_includes indexes.values.join("\n"), '(care_setting, registered_at, id)'
    assert_includes indexes.values.join("\n"), '(status, requested_at, id)'
    assert_operator source.index('baseline_plans ='), :<, source.index('create_candidate_indexes!')
    assert_operator source.index('create_candidate_indexes!'), :<, source.index('candidate_plans =')
    assert_includes source, 'candidate_assertions = assert_candidate_plans!(candidate_plans)'
  end

  def test_proven_candidate_indexes_match_the_portable_migration
    migration = File.read(INDEX_MIGRATION)

    LocalPostgresQueryPlanRehearsal::CANDIDATE_INDEXES.each_key do |index_name|
      assert_includes migration, index_name
    end
    assert_includes migration, "['care_setting', 'registered_at', 'id']"
    assert_includes migration, "['status', 'requested_at', 'id']"
    refute_includes migration, 'DB::statement'
    refute_includes migration, 'CONCURRENTLY'
  end

  def test_disposable_postgres_rehearsal_verifies_migration_rollback_and_reapply
    source = File.read(SCRIPT)

    assert_includes source, "run_artisan!('migrate:rollback', \"--path=\#{INDEX_MIGRATION_PATH}\", '--force', '--no-interaction')"
    assert_includes source, 'Operational index migration rollback left a reviewed index installed.'
    assert_includes source, "run_artisan!('migrate', \"--path=\#{INDEX_MIGRATION_PATH}\", '--force', '--no-interaction')"
    assert_includes source, 'Operational index migration reapply did not restore both reviewed indexes.'
    assert_includes source, "'rollback_removed_indexes' => true"
    assert_includes source, "'reapply_restored_indexes' => true"
  end

  def test_target_operational_routes_use_half_open_timestamp_ranges
    registration = File.read(File.join(ROOT, 'app/Http/Controllers/Outpatient/OutpatientRegistrationController.php'))
    examination = File.read(File.join(ROOT, 'app/Http/Controllers/Outpatient/OutpatientExaminationController.php'))
    recap = File.read(File.join(ROOT, 'app/Http/Controllers/Outpatient/OutpatientRecapController.php'))

    [registration, examination, recap].each do |source|
      refute_includes source, "whereDate('registered_at'"
    end
    assert_includes registration, "->where('registered_at', '>=', \$todayStart)"
    assert_includes registration, "->where('registered_at', '<', \$tomorrowStart)"
    assert_includes examination, "CarbonImmutable::parse(\$dateTo, config('app.timezone'))->startOfDay()->addDay()"
    assert_includes recap, "CarbonImmutable::parse(\$dateTo, config('app.timezone'))->startOfDay()->addDay()"
  end

  def test_candidate_pass_requires_reviewed_topology_and_no_disk_spill
    source = File.read(SCRIPT)

    assert_equal 4, LocalPostgresQueryPlanRehearsal::SELECTIVE_CANDIDATE_ASSERTIONS.length
    assert_includes source, 'did not select its reviewed composite index'
    assert_includes source, 'still sequentially scanned its target operational table'
    assert_includes source, "node['Sort Space Type'] == 'Disk'"
    assert_includes source, "fetch('Temp Read Blocks', 0)"
    assert_includes source, "fetch('Temp Written Blocks', 0)"
    assert_includes source, "'status' => 'PASS'"
  end

  def test_server_identity_read_only_timeouts_timezone_and_provenance_are_bound
    source = File.read(SCRIPT)

    assert_includes source, 'inet_server_addr() IS NULL'
    assert_includes source, 'BEGIN READ ONLY'
    assert_includes source, "SET LOCAL TIME ZONE 'Asia/Jakarta'"
    assert_includes source, "SET LOCAL statement_timeout = '5s'"
    assert_includes source, "SET LOCAL lock_timeout = '1s'"
    assert_includes source, "SET LOCAL idle_in_transaction_session_timeout = '10s'"
    assert_includes source, "'harness_sha256'"
    assert_includes source, "'contract_test_sha256'"
    assert_includes source, "'application_query_sources_sha256'"
  end

  def test_buffer_summary_uses_root_totals_without_double_counting_child_nodes
    source = File.read(SCRIPT)

    assert_includes source, "'shared_hit_blocks' => root.fetch('Shared Hit Blocks', 0)"
    assert_includes source, "'shared_read_blocks' => root.fetch('Shared Read Blocks', 0)"
    refute_includes source, "nodes.sum { |node| node.fetch('Shared Hit Blocks', 0) }"
  end

  def test_evidence_cannot_claim_hosted_latency_capacity_or_sla
    source = File.read(SCRIPT)
    evidence_method = source[/def write_evidence!.*?^  end/m]

    assert_includes source, "'hosted_latency_claim' => false"
    assert_includes source, "'capacity_or_sla_claim' => false"
    assert_includes source, 'Local single-user plan topology and buffer evidence only'
    refute_nil evidence_method
    refute_includes evidence_method, '@database'
    refute_includes evidence_method, 'PGPASSWORD'
    refute_includes evidence_method, 'DB_PASSWORD'
    refute_includes evidence_method, 'DEMO_ACCOUNT_PASSWORD'
  end
end
