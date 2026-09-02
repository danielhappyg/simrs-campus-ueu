# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-inpatient-accommodation-tariff-source-portability'

class LocalInpatientAccommodationTariffSourcePortabilityHarnessContractTest < Minitest::Test
  Harness = LocalInpatientAccommodationTariffSourcePortabilityRehearsal

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
    @worker = Harness::WORKER_SOURCE
  end

  def test_harness_is_syntactically_valid_and_not_run
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH), out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['inpatient-accommodation-portability-', '.php'])
    worker.write(@worker)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator @worker.bytesize, :>, 12_000
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    assert_equal 'SIMRS_LOCAL_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_PORTABILITY', Harness::EVIDENCE_KIND
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
  ensure
    worker&.close!
  end

  def test_closed_twenty_eight_scenario_catalogue_covers_required_exact_engine_evidence
    assert_equal %w[
      fresh-migration empty-down-reapply exact-role-boundary
      prospective-bed-version-provenance database-provenance-trigger-refusal
      legacy-provenance-no-inference occupancy-day-calendar-allocation
      admission-census-cancellation-open-interval-no-charge
      exact-effective-dated-binding future-half-open-terminal-retirement
      exact-context-and-positive-integer-refusal four-domain-typed-union
      closed-interval-source-materialization open-interval-partial-sync-issue-refusal
      routine-discharge-complete-bill replay-conflict-retroactive-stale-refusal
      same-service-day-import-concurrency transfer-synchronization-concurrency
      discharge-issue-cutoff-concurrency bed-version-binding-resolution-concurrency
      competing-binding-writer-concurrency application-sql-guard-refusal
      database-append-only-head-trigger-check-refusal
      audit-corruption-reconciliation-refusal least-privilege-runtime
      reset-recovery-concurrency retained-evidence-rollback-refusal strict-cleanup
    ], Harness::SCENARIOS
    assert_equal 28, Harness::SCENARIOS.length
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
    %w[
      prospective-bed-version-provenance database-provenance-trigger-refusal
      occupancy-day-calendar-allocation four-domain-typed-union
      same-service-day-import-concurrency transfer-synchronization-concurrency
      discharge-issue-cutoff-concurrency bed-version-binding-resolution-concurrency
      competing-binding-writer-concurrency least-privilege-runtime
      reset-recovery-concurrency retained-evidence-rollback-refusal strict-cleanup
    ].each { |scenario| assert_includes Harness::SCENARIOS, scenario }
  end

  def test_worker_and_scenario_tail_have_no_foreign_trigger_residue
    refute_match(/specimen|verified[_ -]?result|result[_ -]?amendment|three-care-setting|resolve_gap|run_visibility/i, @worker)
    refute_match(/order-specimen|one-original|verified-amendment|three-care-setting|resolve_gap|run_visibility/i, @source)
    assert_includes @worker, 'InpatientAdmissionService'
    assert_includes @worker, 'InpatientBedTransferService'
    assert_includes @worker, 'InpatientDischargeService'
    assert_includes @worker, 'FinanceAccommodationSourceAdapter'
    assert_includes @worker, 'FinanceAccommodationTariffBindingService'
    assert_includes @worker, 'COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1'
    %w[pharmacy_financial_source_event_id finance_radiology_source_event_id finance_laboratory_source_event_id finance_accommodation_source_event_id].each do |column|
      assert_includes @worker, column
    end
  end

  def test_source_hash_boundary_is_closed_and_includes_both_migrations
    assert_equal 'database/migrations/2026_09_02_000500_add_bed_version_provenance_to_inpatient_location_events.php', Harness::PROVENANCE_MIGRATION_PATH
    assert_equal 'database/migrations/2026_09_02_000600_create_inpatient_accommodation_tariff_source.php', Harness::MIGRATION_PATH
    binding = harness.current_inpatient_accommodation_tariff_source_bindings
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
    %w[aggregate_sha256 application_source_sha256 worker_source_sha256 scenario_catalog_sha256 runtime_grant_catalog_sha256 sqlite_gate_catalog_sha256].each do |key|
      assert_match(/\A[0-9a-f]{64}\z/, binding.fetch(key))
    end
    assert_equal binding.fetch('aggregate_sha256'), binding.fetch('application_source_sha256')
    [Harness::SCRIPT_PATH, Harness::CONTRACT_PATH, Harness::EVIDENCE_TEMPLATE_PATH,
     Harness::PROVENANCE_MIGRATION_PATH, Harness::MIGRATION_PATH].each do |path|
      assert_includes Harness::SOURCE_PATHS, path
    end
  end

  def test_feature_bindings_exist_and_are_hashed
    Harness::FEATURE_SCENARIO_TESTS.each do |scenario, (path, method)|
      assert_includes Harness::SCENARIOS, scenario
      assert_includes Harness::SOURCE_PATHS, path
      assert_includes File.read(File.join(Harness::ROOT, path), encoding: Encoding::UTF_8), "function #{method}"
    end
  end

  def test_sqlite_gate_precedes_exact_engine_and_cannot_claim_concurrency
    Harness::FEATURE_SCENARIO_TESTS.values.uniq.each { |binding| assert_includes Harness::SQLITE_FEATURE_TESTS, binding }
    assert_includes Harness::SQLITE_FEATURE_TESTS,
                    ['tests/Feature/Inpatient/InpatientLocationBedVersionProvenanceTest.php',
                     'test_database_guard_rejects_digest_drift_and_reset_still_deletes_valid_provenance']
    assert_includes Harness::SQLITE_FEATURE_TESTS,
                    ['tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php',
                     'test_open_final_interval_materializes_closed_days_once_with_historical_tariffs_and_tamper_refusal']
    assert_includes Harness::SQLITE_FEATURE_TESTS,
                    ['tests/Feature/Operations/FinanceAccommodationRecoverySnapshotTest.php',
                     'test_integrity_helpers_detect_binding_receipt_occupancy_source_and_typed_charge_corruption']
    assert_operator @source.index('sqlite_gate = run_sqlite_gate!'), :<, @source.index('engine_binding = prepare_engine!')
    assert_includes @source, "'DB_CONNECTION' => 'sqlite'"
    assert_includes @source, "'DB_DATABASE' => ':memory:'"
  end

  def test_exact_provenance_trigger_refusal_uses_savepoint_and_durable_readback
    assert_includes @worker, "SAVEPOINT accommodation_provenance_guard"
    assert_includes @worker, "ROLLBACK TO SAVEPOINT accommodation_provenance_guard"
    assert_includes @worker, 'destination bed-version provenance does not resolve exactly'
    assert_includes @worker, "'row_count_unchanged' => true"
    assert_equal ['tests/Feature/Inpatient/InpatientLocationBedVersionProvenanceTest.php',
                  'test_schema_is_nullable_fk_backed_and_declares_postgres_mysql_safe_guards'],
                 Harness::FEATURE_SCENARIO_TESTS.fetch('database-provenance-trigger-refusal')
  end

  def test_exact_engine_keeps_the_complete_routine_discharge_bill_feature_bound
    expected = ['tests/Feature/Finance/FinanceAccommodationSourceMaterializationTest.php',
                'test_governed_routine_discharge_closes_and_bill_sync_materializes_complete_daily_sources']
    assert_equal expected, Harness::FEATURE_SCENARIO_TESTS.fetch('closed-interval-source-materialization')
    assert_equal expected, Harness::FEATURE_SCENARIO_TESTS.fetch('routine-discharge-complete-bill')
    test_source = File.read(File.join(Harness::ROOT, expected.first), encoding: Encoding::UTF_8)
    assert_includes test_source, "(int) FinanceAccommodationSourceEvent::query()->sum('signed_amount')"
  end

  def test_import_and_discharge_flows_use_four_distinct_managed_beds
    %w[$sourceBed $targetBed $flowSourceBed $flowTargetBed].each { |name| assert_includes @worker, name }
    assert_includes @worker, '$importEncounter = $admit($sourceBed)'
    assert_includes @worker, '$flowEncounter = $admit($flowSourceBed)'
    assert_includes @worker, "$f['flow_source_bed'], $f['flow_target_bed']"
    assert_includes @worker, "'four distinct beds'"
    assert_includes @worker, 'Flow source binding'
    assert_includes @worker, 'Flow target binding'
  end

  def test_discharge_evidence_is_finalized_only_after_transfer_open_interval_race
    transfer = @source.index('transfer_race = run_mixed_race!')
    prepare = @source.index("action: 'prepare_discharge_evidence'")
    discharge = @source.index('discharge_race = run_mixed_race!')
    assert_operator transfer, :<, prepare
    assert_operator prepare, :<, discharge
    refute_includes @worker.split('function prepareFixture(): array', 2).last.split('function synchronizeRace', 2).first,
                    'prepareDischargeEvidence($flowEncounter'
    assert_includes @worker, '$summary->version, 2'
    refute_includes @worker, "$f['flow_encounter'], user($f['physician']), 1, 2"
    transfer_worker = @worker.split('function transferRace', 2).last.split('function synchronizeFlow', 2).first
    synchronize_worker = @worker.split('function synchronizeFlow', 2).last.split('function dischargeRace', 2).first
    discharge_worker = @worker.split('function dischargeRace', 2).last.split('function prepareFlowDischargeEvidence', 2).first
    prepare_worker = @worker.split('function prepareFlowDischargeEvidence', 2).last.split('function issueAfterDischarge', 2).first
    issue_worker = @worker.split('function issueAfterDischarge', 2).last.split('function bindingRace', 2).first
    assert_includes transfer_worker, "Carbon::setTestNow('2026-09-03 00:00:00')"
    assert_includes synchronize_worker, "Carbon::setTestNow('2026-09-03 00:00:00')"
    assert_includes discharge_worker, "Carbon::setTestNow('2026-09-04 08:00:00')"
    assert_includes prepare_worker, "Carbon::setTestNow('2026-09-04 08:00:00')"
    assert_includes issue_worker, "Carbon::setTestNow('2026-09-04 08:00:00')"
    assert_includes @worker, 'transfer chronology retained before discharge evidence'
    assert_includes @worker, 'retained transfer timestamp'
    assert_includes @worker, 'retained discharge timestamp'
  end

  def test_final_bill_readback_uses_the_actual_bill_version_relationship
    assert_includes @worker, '$bill->versions()->count() === 1'
    assert_includes @worker, '$version = $bill->versions()->sole()'
    assert_includes @worker, '$version->net_amount === 34000'
    assert_includes @worker, "['2026-09-02', '2026-09-03', '2026-09-04']"
    assert_includes @worker, "->where('source_domain', FinanceChargeEvent::SOURCE_ACCOMMODATION)"
    refute_includes @worker, "whereIn('finance_bill_id'"
  end

  def test_least_privilege_create_only_evidence_and_open_governance_boundary
    assert_equal 40, Harness::RUNTIME_READ_TABLES.length
    assert_equal Harness::RUNTIME_READ_TABLES.uniq, Harness::RUNTIME_READ_TABLES
    assert_equal 40, Harness::RUNTIME_TABLE_GRANTS.length
    Harness::RUNTIME_TABLE_GRANTS.each_value { |grant| assert_equal 'SELECT', grant }
    assert_includes @source, 'provision_postgres_runtime_identities!'
    assert_includes @source, 'provision_mysql_runtime_identities!'
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, "mode: 'wx', perm: 0o600"
    assert_includes @source, 'cleanup!(strict: true)'
    assert_includes @source, "'owner_acceptance_claim' => false"
    assert_includes @source, "'g0_claim' => false"
    assert_includes @source, "'g3_claim' => false"
    assert_includes @source, 'G0 and G3 remain OPEN'
    refute_match(/git\s+(?:add|commit|push)/, @source)
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
    privilege_worker = @worker.split('function privilegeGuards', 2).last.split('$action =', 2).first
    assert_includes privilege_worker, '$readTables = ['
    assert_includes privilege_worker, '$writeProbeTables = ['
    read_literal = privilege_worker.split('$readTables = [', 2).last.split('];', 2).first
    write_literal = privilege_worker.split('$writeProbeTables = [', 2).last.split('];', 2).first
    assert_equal 11, read_literal.scan(/'[^']+'/).length
    assert_equal 8, write_literal.scan(/'[^']+'/).length
    %w[inpatient_location_events inpatient_bed_versions inpatient_discharges].each do |table|
      assert_includes privilege_worker.split('$writeProbeTables =', 2).first, "'#{table}'"
      refute_includes privilege_worker.split('$writeProbeTables =', 2).last.split('foreach ($readTables', 2).first, "'#{table}'"
      assert_includes Harness::RUNTIME_READ_TABLES, table
    end
    assert_includes privilege_worker, "'read_tables' => count($readTables)"
    assert_includes privilege_worker, "'write_probe_tables' => count($writeProbeTables)"
    assert_includes privilege_worker, "'inpatient_tables_write_probed' => false"
    assert_includes privilege_worker, 'catch (QueryException)'
    refute_includes privilege_worker, 'catch (LogicException)'
  end

  def test_native_wait_failures_retain_the_exact_scenario_name
    assert_equal 2, @source.scan(/#\{scenario\} wait observation failed/).length
    assert_equal 3, @source.scan('observe_real_database_wait!').length
    assert_equal 2, @source.scan('first_protocol=STARTED/HOLDING').length
    assert_equal 2, @source.scan("first_backend=\#{first_started.fetch('backend_connection_id')}").length
    assert_equal 2, @source.scan("second_backend=\#{started.fetch('backend_connection_id')}").length
    assert_equal 2, @source.scan("expected_lock_order=\#{expected_lock_order.join('>')}").length
    assert_equal 2, @source.scan('diagnostic = wait_failure_diagnostic').length
    assert_includes @source, 'deadline = @clock.call + 5.0'
    assert_includes @source, 'transitions << snapshot if transitions.last != snapshot'
    assert_includes @source, "snapshot.fetch('wait_event_type') == 'Lock' && snapshot.fetch('blocking_pids').any?"
    assert_includes @source, "coalesce(array_to_string(pg_blocking_pids(pid), ','), '')"
    assert_includes @source, "CASE WHEN xact_start IS NULL THEN 'NONE' ELSE 'PRESENT' END"
    assert_includes @source, "md5(coalesce(query, ''))"
    assert_includes @source, "'transaction' => transaction, 'query_md5' => query_md5"
    refute_match(/query(?:_text)?['\"]?\s*=>\s*(?:query|value)/, @source)
    assert_equal 2, @source.scan('worker_diagnostic = completed_worker_diagnostic(second)').length
    assert_includes @source, 'allowed = %w[status protocol_state exception_class exception_fingerprint diagnostic_message failure_stage sql_state driver_code query_sha256]'
    completed = @source.split('def completed_worker_diagnostic', 2).last.split('def observe_real_database_wait!', 2).first
    refute_includes completed, 'query_head'
    refute_includes @worker, 'query_head'
    assert_includes @worker, "'query_sha256' => $query ? hash('sha256', $query->getSql()) : null"
  end

  def test_revision_resolution_startup_barrier_is_pair_scoped_create_only_and_strictly_cleaned
    assert_equal 1, @source.scan('startup_barrier: true').length
    revision_call = @source.split('revision_race = run_mixed_race!', 2).last.split('binding_race = run_domain_race!', 2).first
    assert_includes revision_call, "scenario: 'bed-version-binding-resolution-concurrency'"
    assert_includes revision_call, 'startup_barrier: true'

    revision_worker = @worker.split('function revisionRace', 2).last.split('function resolveAfterRevision', 2).first
    resolve_worker = @worker.split('function resolveAfterRevision', 2).last.split('function verifyFixture', 2).first
    assert_operator revision_worker.index('lockExactTargetBinding'), :<, revision_worker.index("protocol('HOLDING'")
    assert_operator revision_worker.index("protocol('HOLDING'"), :<, revision_worker.index('holdAfterContenderStarts()')
    assert_operator resolve_worker.index('return DB::transaction'), :<, resolve_worker.index("protocol('STARTED'")
    assert_includes resolve_worker, "protocol('STARTED', ['backend_connection_id' => backendConnectionId()])"
    assert_operator resolve_worker.index("protocol('STARTED'"), :<, resolve_worker.index('signalContenderStartup()')
    assert_operator resolve_worker.index('signalContenderStartup()'), :<, resolve_worker.index('FinanceAccommodationTariffMutationScope::run')
    assert_operator resolve_worker.index('FinanceAccommodationTariffMutationScope::run'), :<, resolve_worker.index('lockExactTargetBinding')
    assert_operator resolve_worker.index('lockExactTargetBinding'), :<, resolve_worker.index('FinanceAccommodationTariffProjection::class')
    refute_includes resolve_worker, 'FinanceAccommodationTariffBindingService'
    assert_includes @worker, "transactionLevel() > 0"
    assert_includes @worker, "DB::connection()->selectOne('SELECT pg_backend_pid() AS id', [], false)"
    assert_includes @worker, "DB::connection()->selectOne('SELECT CONNECTION_ID() AS id', [], false)"
    refute_includes @worker, "DB::selectOne('SELECT pg_backend_pid() AS id')"
    assert_includes @worker, "SchemaQualifier::table('finance_accommodation_tariff_bindings')"
    assert_includes @worker, "in_array($table, ['finance_accommodation_tariff_bindings', 'laravel.finance_accommodation_tariff_bindings'], true)"
    assert_includes @worker, 'SELECT id FROM {$table} WHERE public_id = ? FOR UPDATE'
    assert_includes @worker, '[$publicId], false'
    assert_includes @worker, 'count($rows) === 1'
    refute_includes @worker, 'public_id = {$publicId}'
    assert_includes @source, "canonical_locked_row_id == Integer(fixture.fetch('target_binding_id'))"
    assert_includes @source, "canonical_lock_identity_sha256 == resolved.fetch('locked_binding_identity_sha256')"
    assert_includes @worker, "fopen($barrier, 'x')"
    assert_includes @worker, 'contender startup barrier timeout'
    assert_includes @worker, "is_file($barrier.'.observed')"
    assert_includes @worker, 'native wait observation acknowledgement timeout'

    assert_includes @source, 'File.dirname(@worker_tempfile.path)'
    assert_includes @source, 'SecureRandom.hex(8)'
    assert_includes @source, "raise CommandFailed, 'Harness startup barrier path collision.' if File.exist?(path)"
    assert_includes @source, 'File.unlink(path) if File.file?(path)'
    assert_includes @source, "File.unlink(observed_path) if File.file?(observed_path)"
    assert_includes @source, "raise CommandFailed, 'Harness startup barrier strict cleanup failed.' if File.exist?(path)"
    assert_includes @source, "'SIMRS_INPATIENT_ACCOMMODATION_TARIFF_SOURCE_STARTUP_BARRIER' => startup_barrier.to_s"
    assert_includes @source, 'observe_real_database_wait!'
    assert_includes @source, "File.write(path+'.observed', \"NATIVE_WAIT_OBSERVED\\n\", mode: 'wx', perm: 0o600)"

    mixed = @source.split('def run_mixed_race!', 2).last.split('def internal_startup_barrier_path', 2).first
    domain = @source.split('def run_domain_race!', 2).last.split('def run_mixed_race!', 2).first
    assert_operator mixed.index('raise CommandFailed, "#{scenario} did not expose a real database wait." unless wait_observed'), :<,
                    mixed.index('signal_native_wait_observed!(barrier_path) if startup_barrier')
    assert_operator mixed.index('signal_native_wait_observed!(barrier_path) if startup_barrier'), :<,
                    mixed.index('finals = [await_final!(first), await_final!(second)]')
    refute_includes domain, 'signal_native_wait_observed!'
  end

  def test_recovery_row_lock_uses_governed_scopes_only_for_lock_acquisition
    recovery = @worker.split('function recoveryRace', 2).last.split('function resetRace', 2).first
    finance_scope = recovery.index('FinanceMutationScope::run')
    tariff_scope = recovery.index('FinanceAccommodationTariffMutationScope::run')
    lock = recovery.index("FinanceAccommodationSourceEvent::query()->where('encounter_id'")
    holding = recovery.index("protocol('HOLDING'")
    verify = recovery.index('FinanceAccommodationSourceAdapter::class').to_i
    assert_operator finance_scope, :<, tariff_scope
    assert_operator tariff_scope, :<, lock
    assert_operator lock, :<, holding
    assert_operator holding, :<, verify
    assert_equal 1, recovery.scan('FinanceMutationScope::run').length
    assert_equal 1, recovery.scan('FinanceAccommodationTariffMutationScope::run').length
    scoped_lock = recovery.split('FinanceMutationScope::run', 2).last.split('));', 2).first
    refute_includes scoped_lock, 'SyntheticResetService'
    refute_includes scoped_lock, 'FinanceAccommodationSourceAdapter'
  end

  def test_inherited_database_overrides_are_rejected
    error = assert_raises(Harness::CommandFailed) do
      Harness.new(engine: 'postgresql17', environment: confirmed_environment.merge('DB_PASSWORD' => 'refused')).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_PASSWORD/, error.message)
  end

  private

  def php_binary
    ['/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'].find { |path| File.executable?(path) } || 'php'
  end

  def confirmed_environment
    { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '' }
  end

  def harness
    Harness.new(engine: 'postgresql17', environment: confirmed_environment)
  end
end
