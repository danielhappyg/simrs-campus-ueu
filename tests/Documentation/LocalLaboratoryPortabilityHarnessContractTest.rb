# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-laboratory-portability'

class LocalLaboratoryPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalLaboratoryPortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_ruby_and_embedded_php_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['laboratory-portability-contract-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system('/opt/homebrew/bin/php', '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 30_000
  ensure
    worker&.close!
  end

  def test_closed_seventeen_scenario_catalogue_is_exact
    assert_equal %w[
      fresh-migration
      empty-down-reapply
      exact-role-boundary
      all-three-care-settings
      master-create-update-retire
      order-specimen-result-critical-amend-acknowledge
      closure-and-cancellation-blockers
      exact-replay-after-head-advance
      changed-payload-key-conflict
      same-key-concurrency-unique-reconciliation
      application-sql-guard-refusal
      database-snapshot-delete-truncate-refusal
      full-chain-corruption-refusal
      least-privilege-runtime
      bounded-reset-dependency-order-audit-preservation
      retained-evidence-rollback-refusal
      invariant-verification
    ], Harness::SCENARIOS
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
  end

  def test_exact_engines_migration_and_bound_sources_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal 'database/migrations/2026_09_01_000200_create_cross_setting_laboratory_tables.php', Harness::MIGRATION_PATH
    assert_equal 'SIMRS_LOCAL_CROSS_SETTING_LABORATORY_PORTABILITY', Harness::EVIDENCE_KIND
    binding = harness.current_laboratory_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
    %w[
      app/Support/Laboratory/LaboratoryWorkflowService.php
      app/Support/Laboratory/LaboratoryMasterService.php
      app/Support/Laboratory/LaboratoryEvidenceFingerprint.php
      app/Support/Laboratory/LaboratorySqlWriteGuard.php
      app/Support/Simulation/SyntheticResetService.php
    ].each { |path| assert_includes Harness::SOURCE_PATHS, path }
  end

  def test_postgres_trigger_functions_are_fresh_migration_reentrant
    migration = File.read(File.join(Harness::ROOT, Harness::MIGRATION_PATH), encoding: Encoding::UTF_8)

    %w[
      laboratory_append_only_guard
      laboratory_truncate_guard
      laboratory_master_transition_guard
      laboratory_order_transition_guard
      laboratory_specimen_transition_guard
    ].each do |function|
      assert_includes migration, "CREATE OR REPLACE FUNCTION #{function}()"
    end
    refute_match(/CREATE\s+FUNCTION\s+laboratory_/i, migration)
  end

  def test_real_services_cover_exact_roles_three_settings_and_terminal_chain
    assert_includes @worker, 'app(LaboratoryActorPolicy::class)'
    assert_includes @worker, 'exact-role wrong actor denied'
    %w[outpatient emergency inpatient].each { |setting| assert_includes @worker, "'#{setting}'" }
    assert_includes @worker, 'app(LaboratoryMasterService::class)'
    assert_includes @worker, 'master create version 1'
    assert_includes @worker, 'master update version 2'
    assert_includes @worker, 'master retired terminal version 3'
    assert_includes @worker, 'app(LaboratoryWorkflowService::class)'
    assert_includes @worker, 'LaboratorySpecimenAttempt::REJECTED'
    assert_includes @worker, 'LaboratorySpecimenAttempt::ACCEPTED'
    assert_includes @worker, 'LaboratoryResultVersion::DRAFT'
    assert_includes @worker, 'LaboratoryResultVersion::VERIFIED'
    assert_includes @worker, 'LaboratoryResultVersion::AMENDED_VERIFIED'
    assert_includes @worker, 'critical communication retained'
    assert_includes @worker, 'normal-to-critical amendment communication required'
    assert_includes @worker, 'normal-to-critical amendment communication retained'
    assert_includes @worker, 'critical-to-critical amendment communication required'
    assert_includes @worker, 'critical communication bound to every critical signed version'
    assert_includes @worker, 'second amendment binds prior digest'
    assert_includes @worker, 'closure clear after multi-amend chain'
    assert_includes @worker, 'closure stale after amendment'
    assert_includes @worker, 'closure clear after current acknowledgement'
  end

  def test_replay_conflict_blockers_and_corrupt_chain_refusal_are_explicit
    assert_includes @worker, 'exact Draft replay after later head advance'
    assert_includes @worker, 'changed payload same key conflict'
    assert_includes @worker, 'active order closure blocker'
    assert_includes @worker, 'encounter cancellation diagnostic blocker'
    assert_includes @worker, 'specimen/result order cancellation blocked'
    assert_includes @worker, 'full chain corruption acknowledgement refusal'
    assert_includes @worker, "evidence_fingerprint_invalid"
    assert_includes @worker, "content_digest' => str_repeat('0', 64)"
  end

  def test_race_uses_two_real_processes_native_wait_and_unique_reconciliation
    foundation = File.read(
      File.join(Harness::ROOT, 'scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb'),
      encoding: Encoding::UTF_8,
    )
    %w[Open3.popen3 observe_real_database_wait! pg_blocking_pids performance_schema.data_lock_waits].each do |needle|
      assert_includes @source + foundation, needle
    end
    assert_includes @source, "'independent_application_processes' => 2"
    assert_includes @source, "'real_database_wait_observed' => true"
    assert_includes @source, "outcomes == %w[APPLIED REPLAYED]"
    assert_includes @source, "'unique_constraint_reconciliation' => true"
    assert_includes @worker, 'same-key race one durable order'
    assert_includes @worker, 'same-key race one unique receipt'
    refute_includes @worker, 'GET_LOCK('
    refute_includes @worker, 'pg_advisory_lock'
  end

  def test_application_database_and_least_privilege_guards_are_exact
    Harness::RUNTIME_READ_TABLES.each { |table| assert_equal 'SELECT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::RUNTIME_LOCK_TABLES.each { |table| assert_equal 'SELECT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::MUTABLE_HEAD_TABLES.each { |table| assert_equal 'SELECT, INSERT, UPDATE', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    Harness::IMMUTABLE_EVIDENCE_TABLES.each { |table| assert_equal 'SELECT, INSERT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    assert_includes @worker, 'LaboratorySqlWriteGuard::class'
    assert_includes @worker, "UPDATE laboratory_orders SET care_setting='INPATIENT'"
    assert_includes @worker, 'DELETE FROM laboratory_result_versions'
    assert_includes @worker, 'TRUNCATE TABLE laboratory_operation_receipts'
    assert_includes @worker, "SchemaQualifier::table('laboratory_orders')"
    assert_includes @worker, "SchemaQualifier::table('laboratory_result_versions')"
    assert_includes @source, "'append_only_evidence_and_audit' => 'SELECT_INSERT_ONLY'"
    assert_includes @source, "'encounter_lock_head' => 'SELECT_UPDATE_NO_INSERT_DELETE'"
    assert_includes @source, 'runtime grant table missing'
    assert_includes @source, 'grants outside the closed map'
    assert_includes @source, 'schema wildcard grant'
  end

  def test_reset_rollback_evidence_and_external_boundaries_fail_closed
    assert_includes @worker, 'app(SyntheticResetService::class)->reset(['
    assert_includes @worker, 'result and amendment chain removed'
    assert_includes @worker, 'specimen attempt graph removed'
    assert_includes @worker, 'governed masters retained'
    assert_includes @worker, 'laboratory audit preserved'
    assert_includes @worker, 'reset audit preserved'
    assert_includes @source, "expect_laboratory_rollback_refusal!('correlated audit evidence exists')"
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'File.chmod(0o600, path)'
    assert_includes @source, 'sanitize_evidence!(evidence)'
    assert_includes @source, 'cleanup!(strict: true)'
    assert_match(/ensure\n\s+terminate_workers!/, @source)
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)

    error = assert_raises(Harness::CommandFailed) do
      Harness.new(engine: 'postgresql17', environment: confirmed_environment.merge('DB_PASSWORD' => 'refused')).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_PASSWORD/, error.message)
    %w[BPJS_INTEGRATION_ENABLED VCLAIM_ENABLED SATUSEHAT_ENABLED].each do |name|
      assert_includes @source, "'#{name}' => 'false'"
    end
  end

  private

  def confirmed_environment
    { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness
    Harness.new(engine: 'postgresql17', environment: confirmed_environment)
  end
end
