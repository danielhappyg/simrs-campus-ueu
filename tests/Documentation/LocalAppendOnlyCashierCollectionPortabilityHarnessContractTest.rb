# frozen_string_literal: true

require 'minitest/autorun'
require 'rbconfig'
require 'tempfile'

require_relative '../../scripts/rehearse-local-append-only-cashier-collection-portability'

class LocalAppendOnlyCashierCollectionPortabilityHarnessContractTest < Minitest::Test
  Harness = LocalAppendOnlyCashierCollectionPortabilityRehearsal

  EXPECTED_SCENARIOS = %w[
    fresh-migration empty-down-reapply migration-failure-guard-reinstall
    failed-install-preserves-lifetime-uniqueness shortened-identifier-inventory
    exact-runtime-grants exact-role-denials runtime-reset-bypass-denial
    owner-only-bounded-reset database-check-constraints database-append-only-refusals
    real-settlement-v-close-wait real-refund-completion-v-close-wait
    concurrent-same-key-replay double-close-refusal double-verify-refusal
    double-handoff-refusal third-connection-membership-net-cash-readback
    audit-failure-atomic-rollback recovery-tamper-detection
    retained-evidence-rollback-refusal strict-cleanup
  ].freeze

  def setup
    @source = File.read(File.join(Harness::ROOT, Harness::SCRIPT_PATH), encoding: Encoding::UTF_8)
  end

  def test_harness_and_embedded_worker_are_syntactically_valid
    assert system(RbConfig.ruby, '-c', File.join(Harness::ROOT, Harness::SCRIPT_PATH),
                  out: File::NULL, err: File::NULL)
    worker = Tempfile.new(['cashier-collection-portability-contract-', '.php'])
    worker.write(Harness::WORKER_SOURCE)
    worker.flush
    assert system(php_binary, '-l', worker.path, out: File::NULL, err: File::NULL)
    assert_operator Harness::WORKER_SOURCE.bytesize, :>, 20_000
  ensure
    worker&.close!
  end

  def test_exact_engine_and_execution_state_are_closed
    assert_equal %w[postgresql17 mysql8411], Harness::ENGINES
    assert_equal '17.10', Harness::POSTGRES_VERSION
    assert_equal '8.4.11', Harness::MYSQL_VERSION
    assert_equal 'SIMRS_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_PORTABILITY', Harness::EVIDENCE_KIND
    assert_equal 'READY_NOT_RUN', Harness::EXECUTION_STATE
    assert_equal 'YES_DISPOSABLE_LOCAL_CASHIER_COLLECTION', Harness::CONFIRMATION
    assert_equal 'SIMRS_CASHIER_COLLECTION_REHEARSAL_CONFIRM', Harness::CONFIRMATION_ENV
  end

  def test_closed_scenario_inventory_matches_the_authorized_rehearsal
    assert_equal EXPECTED_SCENARIOS, Harness::SCENARIOS
    assert_equal 22, Harness::SCENARIOS.length
    assert_equal Harness::SCENARIOS.uniq, Harness::SCENARIOS
  end

  def test_source_inventory_is_closed_and_includes_the_evidence_template_binding
    expected_contract = 'tests/Documentation/LocalAppendOnlyCashierCollectionPortabilityHarnessContractTest.rb'
    expected_authorization = 'tests/Documentation/AppendOnlyCashierCollectionBatchCloseAndDepositHandoffV1LocalEngineeringAuthorizationTest.rb'
    assert_includes Harness::SOURCE_PATHS, Harness::SCRIPT_PATH
    assert_includes Harness::SOURCE_PATHS, expected_contract
    assert_includes Harness::SOURCE_PATHS, expected_authorization
    assert_includes Harness::SOURCE_PATHS, Harness::MIGRATION_PATH
    assert_includes Harness::SOURCE_PATHS, Harness::CORRECTION_MIGRATION_PATH
    assert_includes Harness::SOURCE_PATHS, Harness::EVIDENCE_TEMPLATE_PATH
    assert_equal Harness::SOURCE_PATHS.uniq, Harness::SOURCE_PATHS

    Harness::SOURCE_PATHS.each { |path| assert_path_exists(path) }
    binding = Harness.new(engine: 'postgresql17', environment: {}).current_collection_bindings
    assert_match(/\A[0-9a-f]{64}\z/, binding.fetch('files').fetch(Harness::EVIDENCE_TEMPLATE_PATH))
    assert_equal Harness::SOURCE_PATHS.length, binding.fetch('files').length
  end

  def test_runtime_grants_are_exact_and_fixture_creation_remains_owner_side
    expected = {
      'patients' => 'SELECT',
      'encounters' => 'SELECT, UPDATE',
      'finance_bills' => 'SELECT, UPDATE',
      'finance_bill_versions' => 'SELECT, UPDATE',
      'finance_settlement_correction_cases' => 'SELECT, INSERT, UPDATE',
      'finance_cashier_collection_batches' => 'SELECT, INSERT, UPDATE',
      'finance_cashier_collection_active_slots' => 'SELECT, INSERT, UPDATE, DELETE',
      'finance_cashier_collection_members' => 'SELECT, INSERT, UPDATE',
      'finance_cashier_collection_events' => 'SELECT, INSERT, UPDATE',
      'finance_cash_deposit_handoffs' => 'SELECT, INSERT, UPDATE',
      'finance_cashier_collection_operation_receipts' => 'SELECT, INSERT',
      'audit_events' => 'SELECT, INSERT'
    }
    expected.each { |table, grants| assert_equal grants, Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    source_read_tables = %w[
      pharmacy_medicines pharmacy_depots pharmacy_stock_lots pharmacy_prescriptions
      pharmacy_prescription_items pharmacy_preparations pharmacy_handovers
      pharmacy_handover_items pharmacy_returns pharmacy_return_items pharmacy_financial_source_events
      radiology_examination_masters radiology_orders radiology_performances radiology_report_versions
      finance_radiology_tariff_bindings finance_radiology_tariff_binding_versions
      finance_radiology_source_events laboratory_examination_masters laboratory_orders
      laboratory_specimen_attempts laboratory_result_versions laboratory_critical_communications
      finance_laboratory_tariff_bindings finance_laboratory_tariff_binding_versions
      finance_laboratory_source_events inpatient_location_events inpatient_bed_versions
      inpatient_discharges finance_accommodation_tariff_bindings
      finance_accommodation_tariff_binding_versions finance_accommodation_source_events
      finance_charge_events finance_bill_lines
    ]
    source_read_tables.each { |table| assert_equal 'SELECT', Harness::RUNTIME_TABLE_GRANTS.fetch(table) }
    refute Harness::RUNTIME_TABLE_GRANTS.values.any? { |grants| grants.match?(/DROP|ALTER|CREATE|TRUNCATE/) }
    fixture = Harness::WORKER_SOURCE.split('function createPendingSettlementFixture', 2).last
      .split('function prepareFixture', 2).first
    assert_includes fixture, 'PharmacyMutationScope::run'
    assert_includes fixture, 'PharmacyFinancialSourceEvent::query()->create'
    assert_includes fixture, 'PharmacyFinancialSourceEvent::CHARGE'
    assert_includes fixture, 'FinanceBillService::class'
    assert_includes fixture, '->synchronize('
    assert_includes fixture, 'FinanceProjection::class)->fingerprint'
    assert_includes fixture, '->issue('
    assert_includes fixture, 'FinanceCashSettlementProjection::class)->forBill'
    refute_includes fixture, 'new FinanceBill('
    refute_includes fixture, 'new FinanceBillVersion('
    settlement_hold = Harness::WORKER_SOURCE.split('function settlementHold', 2).last
      .split('function closeBatch', 2).first
    assert_includes settlement_hold, 'FinanceCashSettlementService::class)->settle'
    refute_includes settlement_hold, 'FinanceBillService::class'
    refute_includes settlement_hold, 'PharmacyMutationScope::run'
    refute_includes settlement_hold, 'Patient::factory()'
    refute_includes settlement_hold, 'Encounter::factory()'
  end

  def test_pending_fixture_cannot_encode_zero_sources_with_a_positive_bill
    impossible = /source_event_count'\s*=>\s*0[\s\S]{0,500}(?:gross_amount|net_amount)'\s*=>\s*(?:[1-9]|\$amount)/
    refute_match impossible, Harness::WORKER_SOURCE
  end

  def test_database_checks_are_proven_by_sanitized_native_rejections
    schema = Harness::WORKER_SOURCE.split('function schemaGuards', 2).last
      .split('function uniqueBarrierNames', 2).first
    assert_includes schema, 'pg_catalog.pg_trigger'
    assert_includes schema, 'NOT trigger.tgisinternal'
    assert_includes schema, 'information_schema.triggers WHERE trigger_schema=DATABASE()'
    assert_includes schema, '"{$base}_truncate_guard"'
    probe = Harness::WORKER_SOURCE.split('function nativeCheckRejection', 2).last
      .split('function schemaGuards', 2).first
    assert_includes probe, 'catch (QueryException $exception)'
    assert_includes probe, "strtolower($exception->getMessage())"
    assert_includes probe, "'query_text_recorded' => false"
    assert_includes probe, 'FinanceCashierCollectionEvent::CLOSE_VERIFIED'
    assert_includes probe, "'counted_amount' => 9001, 'variance_amount' => 1"
    assert_includes probe, "}, 'fcce_values_ck')"
    assert_includes probe, "'operation' => 'FINANCE_CASHIER_COLLECTION_OPEN'"
    assert_includes probe, "'result_type' => 'EVENT'"
    assert_includes probe, "}, 'fccor_result_ck')"
    assert_includes probe, 'invalid collection event rolled back'
    assert_includes probe, 'invalid collection receipt rolled back'
    assert_includes probe, 'native CHECK probes preserve healthy recovery'
    assert_includes probe, "'healthy_recovery_mismatch_count' => 0"
    assert_operator @source.index("action: 'prepare'"), :<, @source.index("action: 'constraint_probes'")
    assert_includes @source, "scenario: 'database-check-constraints'"
    assert_includes @source, "'catalog_worker' => schema, 'exact_worker' => constraints"
  end

  def test_refund_close_race_accepts_authorized_serializations_and_proves_final_equation
    finalizer = Harness::WORKER_SOURCE.split('function ensureRefundBatchClosed', 2).last
      .split('function sameKeyClose', 2).first
    assert_includes @source, 'expected_contender: %w[CLOSED STALE_COLLECTION_BATCH_REFUSED BATCH_NOT_OPEN_REFUSED]'
    assert_includes Harness::WORKER_SOURCE,
                    "'close_refund' => closeBatch($f['refund_race'], 'collection-portability-close-refund-race', 0)"
    assert_includes finalizer, "'ALREADY_FROZEN'"
    assert_includes finalizer, "'CLOSED_BY_FINALIZER'"
    assert_includes finalizer, '->reconciledEvidence($batch)'
    assert_includes finalizer, '$members->count() === 1 && $events->count() === 1'
    assert_includes finalizer, '$gross === 9000 && $refunded === 9000'
    assert_includes finalizer, '$close->completed_refund_amount === 9000 && $close->expected_net_amount === 0'
    assert_includes finalizer, '$close->counted_amount === 0 && $close->variance_amount === 0'
    assert_includes finalizer, 'FinanceCashierCollectionFingerprint::class)->event($close)'
    assert_includes @source, "action: 'ensure_refund_closed'"
    assert_includes @source, "'real-refund-completion-v-close-wait' => refund_race.merge('final_state' => refund_final)"
    refute_includes @source, "action: 'close_refund_retry'"
  end

  def test_recovery_tamper_uses_governed_owner_scope_and_always_rolls_back
    recovery = Harness::WORKER_SOURCE.split('function recoveryTamper', 2).last
      .split('function nativeCheckRejection', 2).first
    assert_includes recovery, 'healthy recovery before tamper'
    assert_includes recovery, 'DB::beginTransaction()'
    assert_includes recovery, 'FinanceAppendOnlyGuard::runSyntheticReset(function (): void'
    assert_includes recovery, 'FinanceMutationScope::run(function (): void'
    assert_includes recovery, "->update(['content_digest' => str_repeat('f', 64)])"
    assert_includes recovery, 'member tamper detected'
    assert_includes recovery, 'finally { DB::rollBack(); }'
    assert_includes recovery, 'recovery restored after rollback probe'
  end

  def test_native_violation_feature_uses_sqlite_gate_plus_dedicated_exact_worker
    assert_equal({
      'database-append-only-refusals' => 'privilege_guards',
      'owner-only-bounded-reset' => 'owner_reset'
    }, Harness::EXACT_WORKER_ONLY_SCENARIOS)
    assert_equal Harness::FEATURE_SCENARIO_TESTS.keys - Harness::EXACT_WORKER_ONLY_SCENARIOS.keys,
                 Harness::EXACT_FILTERED_FEATURE_SCENARIOS
    binding = @source.split('def bind_feature_evidence!', 2).last
      .split('def start_collection_worker!', 2).first
    assert_includes binding, 'SQLITE_GATE_SOURCE_BINDING_WITH_DEDICATED_EXACT_WORKER'
    assert_includes binding, "'exact_worker_action' => EXACT_WORKER_ONLY_SCENARIOS.fetch(label)"
    privilege = Harness::WORKER_SOURCE.split('function privilegeGuards', 2).last
      .split('function ownerReset', 2).first
    assert_includes privilege, 'delete update truncate refused'
    assert_includes privilege, "financeTransaction(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_members'))"
    assert_includes privilege, "financeTransaction(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_batches'))"
    assert_includes privilege, "financeTransaction(fn () => DB::statement('TRUNCATE TABLE '.SchemaQualifier::table('finance_cashier_collection_members')))"
    refute_includes privilege, "FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_members'))"
    assert_equal 1, privilege.scan('FinanceMutationScope::run').length
    assert_includes privilege, 'DB::transaction(function () use ($batch): void'
    assert_includes privilege, 'FinanceMutationScope::run(fn () => DB::table'
    assert_includes @source, "feature.fetch('database-append-only-refusals').merge('exact_worker' => privilege)"
    owner_reset = Harness::WORKER_SOURCE.split('function ownerReset', 2).last
      .split("$action = (string) getenv", 2).first
    assert_includes owner_reset, 'FinanceMutationScope::run(fn () => app(SyntheticResetService::class)->reset(['
    assert_equal 1, owner_reset.scan('SyntheticResetService::class)->reset').length
    assert_includes owner_reset, "$auditBefore = DB::table(SchemaQualifier::table('audit_events'))->count()"
    assert_includes owner_reset, "whereIn('action', ['teaching.reset.started', 'teaching.reset.completed'])"
    assert_includes owner_reset, 'installer_owner_bypass_proven'
    assert_includes owner_reset, 'paired owner reset audit'
    assert_includes owner_reset, "'finance_cashier_collection_operation_receipts', 'finance_cash_deposit_handoffs'"
    assert_includes @source, "feature.fetch('owner-only-bounded-reset').merge('exact_worker' => reset)"
  end

  def test_exact_filtered_recovery_feature_uses_scoped_reset_without_trigger_ddl
    source = File.read(
      File.join(Harness::ROOT, 'tests/Feature/Finance/FinanceCashSettlementCoreTest.php'),
      encoding: Encoding::UTF_8
    )
    method = source[/public function test_recovery_detects_binding_marker_or_member_tamper\(\): void\s*\{(?<body>.*?)\n    \}/m, :body]

    refute_nil method
    assert_includes method, 'FinanceAppendOnlyGuard::runSyntheticReset'
    refute_includes method, 'FinanceAppendOnlyGuard::remove()'
    refute_includes method, 'FinanceAppendOnlyGuard::install()'
    assert_includes Harness::EXACT_FILTERED_FEATURE_SCENARIOS, 'recovery-tamper-detection'
  end

  def test_event_mutation_opens_no_mysql_snapshot_before_canonical_coordination_locks
    service = File.read(
      File.join(Harness::ROOT, 'app/Support/Finance/FinanceCashierCollectionService.php'),
      encoding: Encoding::UTF_8
    ).split('private function eventMutation', 2).last.split('public function reconciledEvidence', 2).first

    slot_lock = service.index("FinanceCashierCollectionActiveSlot::query()->whereKey($candidate->cashier_user_id)->lockForUpdate()->first()")
    batch_lock = service.index("FinanceCashierCollectionBatch::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail()")
    replay = service.index('if ($replay = $this->replay($actor, $operation, $key, $payload))')
    reconciliation = service.index('$this->reconciledEvidence(')

    refute_nil slot_lock
    refute_nil batch_lock
    refute_nil replay
    refute_nil reconciliation
    assert_operator slot_lock, :<, batch_lock
    assert_operator batch_lock, :<, replay
    assert_operator replay, :<, reconciliation
  end

  def test_all_initial_settlements_use_the_real_settlement_service
    %w[new\ FinanceBill\( new\ FinanceBillVersion\( new\ FinanceCashSettlement\(].each do |forbidden|
      refute_includes Harness::WORKER_SOURCE, forbidden.gsub('\\', '')
    end
    settled = Harness::WORKER_SOURCE.split('function createSettledFixture', 2).last
      .split('function prepareFixture', 2).first
    assert_includes settled, 'createPendingSettlementFixture($cashier, $amount, $suffix)'
    assert_includes settled, 'FinanceCashSettlementService::class)->settle'
    assert_includes settled, '$result->record->amount === $amount'
    assert_includes settled, "->where('collection_batch_id', $batch->id)"
    prepare = Harness::WORKER_SOURCE.split('function prepareFixture', 2).last
      .split('function lockCoordination', 2).first
    assert_includes prepare, "createSettledFixture($cashierA, $batchA, 9000, 'A1')"
    assert_includes prepare, "createSettledFixture($cashierB, $batchB, 9000, 'B1')"
    assert_includes prepare, "createSettledFixture($cashierC, $batchC, 7000, 'C1')"
    refute_includes prepare, 'createBoundSettlement'
  end

  def test_confirmation_and_hosted_database_overrides_fail_closed
    missing = assert_raises(Harness::CommandFailed) do
      Harness.new(engine: 'postgresql17', environment: { 'DB_URL' => '' }).assert_contract!
    end
    assert_match(/authorize the disposable local rehearsal/, missing.message)

    external = assert_raises(Harness::CommandFailed) do
      Harness.new(
        engine: 'postgresql17',
        environment: { Harness::CONFIRMATION_ENV => Harness::CONFIRMATION, 'DB_URL' => '', 'DB_HOST' => 'hosted.example' }
      ).assert_contract!
    end
    assert_match(/refuses inherited overrides: DB_HOST/, external.message)
    assert_includes @source, 'external_database_configuration_accepted'
    assert_includes @source, "'disposable_local_engine' => true"
    refute_match(/(?:vercel|supabase)\s+(?:deploy|link|migration|db)/i, @source)
    refute_match(/git\s+(?:add|commit|push)/, @source)
  end

  private

  def assert_path_exists(path)
    assert File.file?(File.join(Harness::ROOT, path)), "missing closed source path: #{path}"
  end

  def php_binary
    ['/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'].find { |path| File.executable?(path) } || 'php'
  end
end
