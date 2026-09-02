#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'securerandom'
require 'time'

require_relative 'rehearse-local-portability-full-suite'

class LocalEncounterCancellationPortabilityRehearsal < LocalPortabilityFullSuiteRehearsal
  TEST_PATHS = %w[
    tests/Feature/Registration/EncounterCancellationTest.php
    tests/Feature/Registration/EncounterCancellationIntegrationReadModelTest.php
    tests/Feature/Registration/EncounterCancellationRegistrationProjectionTest.php
    tests/Feature/Clinical/LockedClinicalEntryWriterTest.php
    tests/Feature/Outpatient/OutpatientLabFlowTest.php
    tests/Feature/Outpatient/StructuredOutpatientDocumentationTest.php
    tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php
    tests/Feature/Simulation/SimulationResetCommandTest.php
  ].freeze

  EVIDENCE_KIND = 'SIMRS_LOCAL_ENCOUNTER_CANCELLATION_PORTABILITY'.freeze
  SCRIPT_PATH = 'scripts/rehearse-local-encounter-cancellation-portability.rb'.freeze
  FOUNDATION_HARNESS_PATH = 'scripts/rehearse-local-portability-full-suite.rb'.freeze

  def run!
    assert_contract!
    execution_bindings = current_cancellation_execution_bindings
    engine_binding = prepare_engine!

    migration_started = @clock.call
    run_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(migration_started)

    focused_suite = run_test_suite!(TEST_PATHS)
    assert_no_non_synthetic_patients!
    assert_unchanged_binding!(
      'Cancellation portability execution bindings',
      execution_bindings,
      current_cancellation_execution_bindings,
    )

    cleanup!(strict: true)
    evidence_path = write_cancellation_evidence!(
      execution_bindings: execution_bindings,
      engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms,
      focused_suite: focused_suite,
    )

    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_ENCOUNTER_CANCELLATION_ONLY',
      'engine' => @engine,
      'evidence_path' => evidence_path,
      'focused_suite' => focused_suite,
    }
  ensure
    cleanup!
  end

  def assert_contract!
    super
    safe_source_path(SCRIPT_PATH)
    TEST_PATHS.each { |path| safe_source_path(path) }
    unless TEST_PATHS == TEST_PATHS.uniq && TEST_PATHS.all? { |path| path.start_with?('tests/Feature/') }
      raise CommandFailed, 'Cancellation portability test catalogue is not a closed unique feature-test list.'
    end
  end

  def current_cancellation_execution_bindings
    migration_records = Dir.glob(File.join(ROOT, 'database/migrations/*.php')).sort.map do |migration|
      [File.basename(migration), Digest::SHA256.file(migration).hexdigest]
    end

    {
      'backend_execution_source_set' => backend_source_binding,
      'migration_set' => {
        'file_count' => migration_records.length,
        'sha256' => Digest::SHA256.hexdigest(JSON.generate(migration_records)),
      },
      'harness_sha256' => Digest::SHA256.file(File.join(ROOT, SCRIPT_PATH)).hexdigest,
      'foundation_harness_sha256' => Digest::SHA256.file(File.join(ROOT, FOUNDATION_HARNESS_PATH)).hexdigest,
      'focused_test_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(TEST_PATHS)),
    }
  end

  private

  def write_cancellation_evidence!(execution_bindings:, engine_binding:, migration_duration_ms:, focused_suite:)
    assert_evidence_directory!
    timestamp = Time.now.utc.strftime('%Y%m%dT%H%M%SZ')
    path = File.join(
      EVIDENCE_DIRECTORY,
      "#{timestamp}-#{@engine}-encounter-cancellation-#{SecureRandom.hex(6)}.json",
    )
    head = @runner.run!([@git_binary, '-C', ROOT, 'rev-parse', 'HEAD']).strip
    dirty = !@runner.run!([@git_binary, '-C', ROOT, 'status', '--porcelain']).strip.empty?
    php_version = @runner.run!([@php_binary, '-r', 'echo PHP_VERSION;']).strip
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_ENCOUNTER_CANCELLATION_ONLY',
      'hosted_readiness_claim' => false,
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'baseline_git_sha' => head,
      'working_tree_state' => dirty ? 'UNCOMMITTED_LOCAL_MILESTONE' : 'CLEAN',
      'backend_execution_source_set' => execution_bindings.fetch('backend_execution_source_set'),
      'migration_set' => execution_bindings.fetch('migration_set'),
      'harness_sha256' => execution_bindings.fetch('harness_sha256'),
      'foundation_harness_sha256' => execution_bindings.fetch('foundation_harness_sha256'),
      'focused_test_catalog_sha256' => execution_bindings.fetch('focused_test_catalog_sha256'),
      'php_version' => php_version,
      'boundary' => {
        'application_mode' => 'SIMULATION',
        'synthetic_only' => true,
        'break_glass_mode' => 'off',
        'production_integrations_configured' => false,
        'disposable_local_engine' => true,
        'stale_milestone_manifest_not_rewritten' => true,
      },
      'engine' => engine_binding,
      'migration' => {
        'fresh_apply' => 'PASS',
        'duration_ms_observed' => migration_duration_ms,
      },
      'focused_suite' => focused_suite,
      'focused_test_paths' => TEST_PATHS,
      'cleanup' => {
        'database_removed' => true,
        'temporary_server_removed' => true,
        'temporary_user_state_removed' => true,
      },
      'open_boundaries' => [
        'This is local disposable-engine evidence, not hosted migration, hosted UAT, deployment or production evidence.',
        'This focused run does not refresh, replace or satisfy the stale local milestone manifest.',
        'Passing technical tests does not establish clinical, RMIK, product-owner or parity acceptance.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence) + "\n", mode: 'wx', perm: 0o600)
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalEncounterCancellationPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end

    result = LocalEncounterCancellationPortabilityRehearsal.new(engine: ARGV.first).run!
    puts JSON.generate(result)
  rescue LocalEncounterCancellationPortabilityRehearsal::CommandFailed => e
    warn "encounter cancellation portability rehearsal failed: #{e.message}"
    exit 1
  end
end
