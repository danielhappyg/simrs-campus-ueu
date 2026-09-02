#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'securerandom'
require 'time'

require_relative 'rehearse-local-portability-full-suite'

# Runs the complete structured-emergency feature/unit slice against isolated,
# disposable PostgreSQL 17 and MySQL 8.4 databases. It inherits the foundation
# harness's trusted binaries, closed environment, identifier validation,
# evidence sanitization, and strict cleanup behavior.
class LocalEmergencyPortabilityRehearsal < LocalPortabilityFullSuiteRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_EMERGENCY_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_EMERGENCY_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-emergency-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalEmergencyPortabilityHarnessContractTest.rb'
  MIGRATION_PATHS = %w[
    database/migrations/2026_09_01_000400_create_structured_emergency_triage_disposition_tables.php
    database/migrations/2026_09_01_000500_add_inpatient_patient_admission_claim.php
  ].freeze
  TEST_PATHS = %w[
    tests/Feature/Emergency
    tests/Unit/Emergency
  ].freeze
  TEST_BATCHES = {
    'audit_contract_unit' => ['tests/Unit/Emergency'],
    'continuous_teaching_journey' => ['tests/Feature/Emergency/ContinuousEmergencyTeachingJourneyTest.php'],
    'emergency_flow' => ['tests/Feature/Emergency/EmergencyFlowTest.php'],
    'inpatient_handoff' => ['tests/Feature/Emergency/EmergencyInpatientHandoffTest.php'],
    'structured_core_workflow' => ['tests/Feature/Emergency/StructuredEmergencyCoreWorkflowTest.php'],
    'schema_and_vocabulary_ddl' => ['tests/Feature/Emergency/EmergencySchemaAndVocabularyTest.php'],
  }.freeze
  EVIDENCE_KIND = 'SIMRS_LOCAL_STRUCTURED_EMERGENCY_PORTABILITY'
  SOURCE_PATHS = %w[
    scripts/rehearse-local-emergency-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalEmergencyPortabilityHarnessContractTest.rb
    docs/new-simrs-rebuild/phase-1/STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md
    database/migrations/2026_09_01_000400_create_structured_emergency_triage_disposition_tables.php
    database/migrations/2026_09_01_000500_add_inpatient_patient_admission_claim.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Audit/AuditEventSchemaRegistry.php
  ].freeze

  def run!
    assert_contract!
    source_binding = current_emergency_binding
    engine_binding = prepare_engine!

    # The Laravel feature suite owns the first migration of the pristine
    # database through RefreshDatabase. Running migrate:fresh before it would
    # make PostgreSQL's later global table drop collide with the application's
    # protected-table SQL guards. After the suite, reset the disposable schema
    # at the database-owner boundary and prove an explicit clean migration too.
    component_suites = TEST_BATCHES.to_h do |name, paths|
      # MySQL DDL implicitly commits. A schema-contract test must therefore
      # never share a migrated database with another RefreshDatabase process.
      # Reset every component at the database-owner boundary on both engines
      # so the catalogue has identical, deterministic isolation semantics.
      reset_engine_for_fresh_migration!
      [name, run_test_suite!(paths)]
    end
    suite = merge_suite_results(component_suites)
    assert_no_non_synthetic_patients!
    reset_engine_for_fresh_migration!
    migration_started = monotonic_now
    run_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(migration_started)
    assert_no_non_synthetic_patients!
    raise CommandFailed, 'Emergency source binding changed during rehearsal.' unless source_binding == current_emergency_binding

    cleanup!(strict: true)
    evidence_path = write_emergency_evidence!(source_binding, engine_binding, migration_duration_ms, suite)

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_EXACT_ENGINE_ONLY',
      'engine' => engine_binding.fetch('engine'),
      'engine_version' => engine_binding.fetch('engine_version'),
      'tests' => suite.fetch('tests'),
      'assertions' => suite.fetch('assertions'),
      'evidence_path' => evidence_path,
      'evidence_sha256' => Digest::SHA256.file(evidence_path).hexdigest,
      'evidence_mode' => format('%04o', File.stat(evidence_path).mode & 0o777)
    }
  ensure
    cleanup!
  end

  def assert_contract!
    raise CommandFailed, "engine must be one of #{ENGINES.join(', ')}" unless ENGINES.include?(@engine)
    unless @environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize disposable local database creation."
    end
    raise CommandFailed, 'Emergency rehearsal refuses any DB_URL.' unless @environment.fetch('DB_URL', '').strip.empty?

    forbidden = %w[PGHOST PGPORT PGUSER PGPASSWORD DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SOCKET]
      .select { |name| !@environment.fetch(name, '').strip.empty? }
    raise CommandFailed, "Emergency rehearsal refuses inherited database overrides: #{forbidden.join(', ')}." unless forbidden.empty?

    %w[POSTGRES17_BIN MYSQL84_BIN].each do |name|
      raise CommandFailed, "Emergency rehearsal refuses executable override #{name}." unless @environment.fetch(name, '').strip.empty?
    end
    raise CommandFailed, 'Emergency rehearsal must run from its repository-owned path.' unless File.realpath(ROOT) == ROOT

    (SOURCE_PATHS + MIGRATION_PATHS + TEST_PATHS + TEST_BATCHES.values.flatten).uniq.each do |path|
      safe_source_path(path, file_only: !TEST_PATHS.include?(path))
    end
    raise CommandFailed, 'Emergency test catalogue drifted.' unless TEST_PATHS == %w[tests/Feature/Emergency tests/Unit/Emergency]
  end

  def current_emergency_binding
    files = expanded_source_paths.to_h do |path|
      relative = path.delete_prefix("#{ROOT}/")
      [relative, Digest::SHA256.file(path).hexdigest]
    end
    {
      'files' => files.sort.to_h,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'harness_sha256' => Digest::SHA256.file(File.join(ROOT, SCRIPT_PATH)).hexdigest,
      'migration_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(MIGRATION_PATHS)),
      'test_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(TEST_PATHS)),
      'execution_batch_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(TEST_BATCHES))
    }
  end

  private

  def expanded_source_paths
    files = SOURCE_PATHS.map { |path| safe_source_path(path) }
    %w[app/Models app/Support/Emergency tests/Feature/Emergency tests/Unit/Emergency].each do |directory|
      root = safe_source_path(directory, file_only: false)
      Dir.glob(File.join(root, '**/*.php')).sort.each do |path|
        raise CommandFailed, "Emergency source path uses symlink: #{path.delete_prefix("#{ROOT}/")}." if File.symlink?(path)
        files << path
      end
    end
    files.uniq.sort
  end

  def monotonic_now
    Process.clock_gettime(Process::CLOCK_MONOTONIC)
  end

  def merge_suite_results(components)
    results = components.values

    {
      'tests' => results.sum { |result| result.fetch('tests') },
      'passed' => results.sum { |result| result.fetch('passed') },
      'skipped' => results.sum { |result| result.fetch('skipped', 0) },
      'assertions' => results.sum { |result| result.fetch('assertions') },
      'duration_ms_reported' => results.sum { |result| result.fetch('duration_ms_reported') },
      'duration_ms_observed' => results.sum { |result| result.fetch('duration_ms_observed') },
      'components' => components.to_h do |name, result|
        [name, result.merge('paths' => TEST_BATCHES.fetch(name))]
      end
    }
  end

  def reset_engine_for_fresh_migration!
    case @engine
    when 'postgresql17'
      @runner.run!(
        postgres_psql_arguments(@postgres_database) + [
          '--command',
          'DROP SCHEMA laravel CASCADE; CREATE SCHEMA laravel;'
        ],
        env: postgres_tool_environment
      )
    when 'mysql8411'
      @runner.run!(mysql_root_arguments, stdin_data: <<~SQL)
        DROP DATABASE `#{@mysql_database}`;
        CREATE DATABASE `#{@mysql_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON `#{@mysql_database}`.* TO '#{@mysql_user}'@'127.0.0.1';
        FLUSH PRIVILEGES;
      SQL
    else
      raise CommandFailed, 'Unsupported emergency rehearsal engine.'
    end
  end

  def write_emergency_evidence!(source_binding, engine_binding, migration_duration_ms, suite)
    assert_evidence_directory!
    timestamp = Time.now.utc.strftime('%Y%m%dT%H%M%SZ')
    path = File.join(EVIDENCE_DIRECTORY, "#{timestamp}-emergency-#{@engine}-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_EXACT_ENGINE_ONLY',
      'hosted_readiness_claim' => false,
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'working_tree_state' => 'UNCOMMITTED_LOCAL_MILESTONE',
      'source_binding' => source_binding,
      'engine' => engine_binding,
      'migration' => {
        'fresh_apply' => 'PASS',
        'paths' => MIGRATION_PATHS,
        'duration_ms_observed' => migration_duration_ms
      },
      'suite' => suite.merge('paths' => TEST_PATHS),
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'break_glass_mode' => 'off',
        'production_integrations_configured' => false,
        'disposable_local_engine' => true
      },
      'cleanup' => {
        'database_removed' => true,
        'temporary_server_removed' => true
      },
      'open_boundaries' => [
        'Local disposable-engine evidence only; no hosted migration, deployment, UAT, capacity, SLA, owner acceptance, or production-readiness claim.',
        'The complete structured-emergency feature/unit slice is verified; broader repository suites remain separately governed.'
      ]
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    raise CommandFailed, 'Emergency evidence file mode is not 0600.' unless (File.stat(path).mode & 0o777) == 0o600

    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalEmergencyPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalEmergencyPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalEmergencyPortabilityRehearsal::CommandFailed => e
    warn "emergency portability rehearsal failed: #{e.message}"
    exit 1
  end
end
