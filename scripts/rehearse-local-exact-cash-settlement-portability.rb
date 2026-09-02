#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-inpatient-accommodation-tariff-source-portability'

# Exact, fail-closed portability evidence for the synthetic cash-settlement and
# business-receipt seam. It owns only disposable local PostgreSQL 17/MySQL 8.4
# databases and never accepts hosted database configuration.
class LocalExactCashSettlementPortabilityRehearsal < LocalInpatientAccommodationTariffSourcePortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_EXACT_CASH_SETTLEMENT'
  CONFIRMATION_ENV = 'SIMRS_EXACT_CASH_SETTLEMENT_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-exact-cash-settlement-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalExactCashSettlementPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_EXACT_CASH_SETTLEMENT_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_EXACT_CASH_SETTLEMENT_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
    fresh-migration empty-down-reapply migration-guard-reinstall-after-failure
    exact-cashier-runtime-grants
    database-check-and-append-only-trigger-refusal exact-server-derived-cash-amount
    same-key-same-payload-concurrency changed-payload-idempotency-conflict
    stale-bill-version-refusal new-source-pending-refusal duplicate-settlement-refusal
    cumulative-version-outstanding-balance
    audit-failure-atomic-rollback third-connection-single-durable-settlement
    retained-evidence-migration-rollback-refusal bounded-reset-recovery strict-cleanup
  ].freeze

  RUNTIME_READ_TABLES = %w[
    users roles permissions role_user permission_role patients encounters
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
    finance_charge_events finance_bills finance_bill_versions finance_bill_lines
    finance_cash_settlements finance_settlement_operation_receipts audit_events
  ].freeze
  RUNTIME_INSERT_TABLES = %w[finance_cash_settlements finance_settlement_operation_receipts audit_events].freeze
  RUNTIME_LOCK_TABLES = %w[encounters finance_bills finance_cash_settlements].freeze
  RUNTIME_TABLE_GRANTS = RUNTIME_READ_TABLES.to_h do |table|
    grants = ['SELECT']
    grants << 'INSERT' if RUNTIME_INSERT_TABLES.include?(table)
    grants << 'UPDATE' if RUNTIME_LOCK_TABLES.include?(table)
    [table, grants.join(', ')]
  end.freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-exact-cash-settlement-portability.rb
    scripts/rehearse-local-inpatient-accommodation-tariff-source-portability.rb
    scripts/rehearse-local-finance-billing-portability.rb
    tests/Documentation/LocalExactCashSettlementPortabilityHarnessContractTest.rb
    tests/Documentation/ExactCashSettlementAndReceiptV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_EXACT_CASH_SETTLEMENT_EVIDENCE_TEMPLATE_2026-09-02.md
    database/migrations/2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php
    tests/Feature/Finance/FinanceCashSettlementCoreTest.php
    tests/Feature/Operations/FinanceCashSettlementRecoverySnapshotTest.php
    app/Models/FinanceCashSettlement.php
    app/Models/FinanceSettlementOperationReceipt.php
    app/Support/Finance/FinanceCashSettlementActorPolicy.php
    app/Support/Finance/FinanceCashSettlementFingerprint.php
    app/Support/Finance/FinanceCashSettlementProjection.php
    app/Support/Finance/FinanceCashSettlementService.php
    app/Support/Finance/FinanceAppendOnlyGuard.php
    app/Support/Finance/FinanceMutationScope.php
    app/Support/Finance/FinanceSourceCoordinator.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'exact-server-derived-cash-amount' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_current_issued_bill_is_settled_once_for_exact_server_derived_cash_amount_and_replays'],
    'changed-payload-idempotency-conflict' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_exact_cashier_authorization_idempotency_conflict_and_duplicate_settlement_fail_closed'],
    'stale-bill-version-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_cancelled_stale_zero_and_new_source_states_are_denied_without_settlement'],
    'new-source-pending-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_cancelled_stale_zero_and_new_source_states_are_denied_without_settlement'],
    'duplicate-settlement-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_exact_cashier_authorization_idempotency_conflict_and_duplicate_settlement_fail_closed'],
    'cumulative-version-outstanding-balance' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_later_cumulative_bill_version_collects_only_new_outstanding_balance'],
    'audit-failure-atomic-rollback' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_audit_failure_rolls_back_settlement_and_operation_receipt'],
    'database-check-and-append-only-trigger-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_settlement_evidence_is_protected_by_sql_scope_and_database_append_only_guards'],
    'bounded-reset-recovery' => ['tests/Feature/Operations/FinanceCashSettlementRecoverySnapshotTest.php', 'test_synthetic_reset_removes_settlement_and_receipt_before_bill_parents_and_preserves_audit'],
  }.freeze
  SQLITE_FEATURE_TESTS = FEATURE_SCENARIO_TESTS.values.uniq.freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\FinanceBill;
    use App\Models\FinanceCashSettlement;
    use App\Models\FinanceSettlementOperationReceipt;
    use App\Models\Patient;
    use App\Models\PharmacyDepot;
    use App\Models\PharmacyFinancialSourceEvent;
    use App\Models\PharmacyHandover;
    use App\Models\PharmacyHandoverItem;
    use App\Models\PharmacyMedicine;
    use App\Models\PharmacyPreparation;
    use App\Models\PharmacyPrescription;
    use App\Models\PharmacyPrescriptionItem;
    use App\Models\PharmacyStockLot;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Finance\FinanceAppendOnlyGuard;
    use App\Support\Finance\FinanceBillService;
    use App\Support\Finance\FinanceCashSettlementProjection;
    use App\Support\Finance\FinanceCashSettlementService;
    use App\Support\Finance\FinanceMutationScope;
    use App\Support\Finance\FinanceProjection;
    use App\Support\Pharmacy\PharmacyCanonicalJson;
    use App\Support\Pharmacy\PharmacyMutationScope;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;

    require (string) getenv('SIMRS_REHEARSAL_ROOT').'/vendor/autoload.php';
    $app = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    function must(bool $condition, string $label): void
    {
        if (! $condition) throw new RuntimeException('assertion failed: '.$label);
    }

    function protocol(string $state, array $extra = []): void
    {
        echo json_encode(array_merge([
            'schema_version' => 1, 'status' => 'PASS', 'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_EXACT_CASH_SETTLEMENT_SCENARIO'),
            'worker' => (string) getenv('SIMRS_EXACT_CASH_SETTLEMENT_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_EXACT_CASH_SETTLEMENT_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::connection()->selectOne('SELECT pg_backend_pid() AS id', [], false), 'id')
            : (int) data_get(DB::connection()->selectOne('SELECT CONNECTION_ID() AS id', [], false), 'id');
    }

    function user(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->sole();
    }

    function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
        return $actor->fresh();
    }

    function prepareFixture(): array
    {
        $cashier = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $pharmacist = actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poli Portabilitas Kas',
        ]);
        $digest = str_repeat('a', 64);
        $pharmacy = PharmacyMutationScope::run(function () use ($pharmacist, $patient, $encounter, $digest): array {
            $medicine = PharmacyMedicine::query()->create([
                'medicine_code' => 'MED-PORT-KAS', 'generic_name' => 'Obat sintetis', 'strength_text' => '500 mg',
                'dosage_form' => 'TABLET', 'base_unit' => 'TABLET', 'route_choices' => ['ORAL'],
                'acquisition_value' => 1000, 'teaching_sale_value' => 7000, 'state' => PharmacyMedicine::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEP-PORT-KAS', 'display_name' => 'Depo sintetis',
                'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT], 'state' => PharmacyDepot::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $lot = PharmacyStockLot::query()->create([
                'medicine_id' => $medicine->id, 'depot_id' => $depot->id, 'opened_by_user_id' => $pharmacist->id,
                'medicine_version' => 1, 'depot_version' => 1, 'medicine_code_snapshot' => $medicine->medicine_code,
                'depot_code_snapshot' => $depot->depot_code, 'lot_code' => 'LOT-PORT-KAS', 'received_at' => now(),
                'expiry_date' => now()->addYear()->toDateString(), 'available_quantity' => 100,
                'quarantined_quantity' => 0, 'acquisition_value' => 1000, 'source_reference' => 'PORT-KAS',
                'state' => PharmacyStockLot::ACTIVE, 'version' => 1, 'content_digest' => $digest,
            ]);
            $prescription = PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id, 'patient_id' => $patient->id,
                'ordering_physician_user_id' => $pharmacist->id, 'depot_id' => $depot->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT, 'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => 'Poli Portabilitas Kas', 'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code, 'status' => PharmacyPrescription::HANDED_OVER,
                'version' => 1, 'current_content_digest' => $digest, 'ordered_at' => now(),
            ]);
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $prescription->id, 'medicine_id' => $medicine->id, 'line_number' => 1,
                'medicine_version' => 1, 'medicine_version_public_id' => $medicine->public_id,
                'medicine_content_digest' => $digest, 'medicine_code' => $medicine->medicine_code,
                'medicine_name' => $medicine->generic_name, 'strength_text' => '500 mg', 'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET', 'dose_text' => '1 tablet', 'route' => 'ORAL',
                'frequency_text' => '1 kali sehari', 'duration_text' => '1 hari',
                'requested_quantity' => 2, 'verified_quantity' => 2, 'sale_value_snapshot' => 7000,
                'instruction' => 'Sesudah makan', 'content_digest' => $digest, 'created_at' => now(),
            ]);
            $preparation = PharmacyPreparation::query()->create([
                'prescription_id' => $prescription->id, 'technician_user_id' => $pharmacist->id, 'sequence' => 1,
                'state' => PharmacyPreparation::ACTIVE, 'prescription_fingerprint' => $digest,
                'stock_fingerprint' => $digest, 'content_digest' => $digest, 'prepared_at' => now(), 'created_at' => now(),
            ]);
            $handover = PharmacyHandover::query()->create([
                'prescription_id' => $prescription->id, 'preparation_id' => $preparation->id,
                'pharmacist_user_id' => $pharmacist->id, 'sequence' => 1, 'state' => PharmacyHandover::FULL,
                'preparation_fingerprint' => $digest, 'content_digest' => $digest,
                'handed_over_at' => now(), 'created_at' => now(),
            ]);
            $handoverItem = PharmacyHandoverItem::query()->create([
                'handover_id' => $handover->id, 'prescription_item_id' => $item->id, 'stock_lot_id' => $lot->id,
                'quantity' => 1, 'sale_value_snapshot' => 7000, 'content_digest' => $digest, 'created_at' => now(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id,
                'actor_user_id' => $pharmacist->id, 'event_type' => PharmacyFinancialSourceEvent::CHARGE,
                'quantity' => 1, 'amount' => 7000, 'source_type' => 'HANDOVER_ITEM',
                'source_public_id' => $handoverItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $prescription->public_id, $item->public_id, $pharmacist->id,
                    PharmacyFinancialSourceEvent::CHARGE, 1, 7000, 'HANDOVER_ITEM', $handoverItem->public_id,
                ]), 'occurred_at' => now(), 'created_at' => now(),
            ]);
            return compact('prescription', 'item', 'handover', 'lot');
        });
        $bills = app(FinanceBillService::class);
        $bill = $bills->synchronize($encounter->public_id, $cashier, 'port-cash-sync-0001')->record;
        must($bill instanceof FinanceBill, 'bill synchronized');
        $versionOne = $bills->issue(
            $bill->public_id, $cashier, app(FinanceProjection::class)->fingerprint($bill),
            'Penerbitan untuk uji portabilitas kas.', 'port-cash-issue-0001',
        )->record;
        must($versionOne !== null && $versionOne->net_amount === 7000, 'exact first issued amount');
        $firstProjection = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $cashier);
        $firstSettlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $cashier, $firstProjection['bill_fingerprint'],
            $firstProjection['bill_version_content_digest'], 'port-cash-prior-settlement-0001',
        )->record;
        must($firstSettlement instanceof FinanceCashSettlement && $firstSettlement->amount === 7000, 'first version exact settlement');

        PharmacyMutationScope::run(function () use ($pharmacy, $pharmacist, $digest): void {
            $handoverItem = PharmacyHandoverItem::query()->create([
                'handover_id' => $pharmacy['handover']->id, 'prescription_item_id' => $pharmacy['item']->id,
                'stock_lot_id' => $pharmacy['lot']->id, 'quantity' => 1, 'sale_value_snapshot' => 7000,
                'content_digest' => $digest, 'created_at' => now()->addMinute(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $pharmacy['prescription']->id,
                'prescription_item_id' => $pharmacy['item']->id, 'actor_user_id' => $pharmacist->id,
                'event_type' => PharmacyFinancialSourceEvent::CHARGE, 'quantity' => 1, 'amount' => 7000,
                'source_type' => 'HANDOVER_ITEM', 'source_public_id' => $handoverItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $pharmacy['prescription']->public_id, $pharmacy['item']->public_id, $pharmacist->id,
                    PharmacyFinancialSourceEvent::CHARGE, 1, 7000, 'HANDOVER_ITEM', $handoverItem->public_id,
                ]), 'occurred_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
        });
        $bill = $bills->synchronize($encounter->public_id, $cashier, 'port-cash-sync-0002')->record;
        must($bill instanceof FinanceBill, 'second source synchronized');
        $versionTwo = $bills->issue(
            $bill->public_id, $cashier, app(FinanceProjection::class)->fingerprint($bill),
            'Penerbitan tambahan untuk uji sisa pelunasan.', 'port-cash-issue-0002',
        )->record;
        must($versionTwo !== null && $versionTwo->net_amount === 14000, 'cumulative second issued amount');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $cashier);
        return [
            'cashier' => $cashier->public_id, 'encounter' => $encounter->public_id,
            'bill' => $bill->public_id, 'version_one' => $versionOne->public_id,
            'version' => $versionTwo->public_id, 'prior_settlement' => $firstSettlement->public_id,
            'bill_fingerprint' => $projection['bill_fingerprint'],
            'version_digest' => $projection['bill_version_content_digest'],
            'idempotency_key' => 'port-cash-settlement-0001',
            'cumulative_net_amount' => 14000, 'prior_settled_amount' => 7000, 'amount' => 7000,
        ];
    }

    function settleRace(array $f): array
    {
        $cashier = user($f['cashier']);
        $worker = (string) getenv('SIMRS_EXACT_CASH_SETTLEMENT_WORKER');
        if ($worker === 'A') {
            return DB::transaction(function () use ($f, $cashier): array {
                $encounter = Encounter::query()->where('public_id', $f['encounter'])->lockForUpdate()->sole();
                protocol('HOLDING', [
                    'backend_connection_id' => backendConnectionId(),
                    'lock_order_trace' => ['encounters'], 'locked_encounter_id' => $encounter->id,
                ]);
                usleep(((int) getenv('SIMRS_EXACT_CASH_SETTLEMENT_HOLD_MS')) * 1000);
                $result = app(FinanceCashSettlementService::class)->settle(
                    $f['bill'], $cashier, $f['bill_fingerprint'], $f['version_digest'], $f['idempotency_key'],
                );
                return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'settlement' => $result->record->public_id];
            });
        }
        $result = app(FinanceCashSettlementService::class)->settle(
            $f['bill'], $cashier, $f['bill_fingerprint'], $f['version_digest'], $f['idempotency_key'],
        );
        return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'settlement' => $result->record->public_id];
    }

    function schemaGuards(): array
    {
        $driver = DB::connection()->getDriverName();
        $schema = $driver === 'pgsql' ? 'laravel' : DB::connection()->getDatabaseName();
        $constraintCount = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $schema)
            ->whereIn('constraint_name', ['fcs_exact_cash_ck', 'fsor_result_ck'])
            ->count();
        must($constraintCount === 2, 'exact settlement check constraints');
        $triggerQuery = DB::table('information_schema.triggers');
        if ($driver === 'pgsql') {
            $triggerQuery->where('trigger_schema', $schema)->whereIn('event_object_table', [
                'finance_cash_settlements', 'finance_settlement_operation_receipts',
            ]);
        } else {
            $triggerQuery->where('trigger_schema', $schema)->whereIn('event_object_table', [
                'finance_cash_settlements', 'finance_settlement_operation_receipts',
            ]);
        }
        $triggerCount = $triggerQuery->count();
        must($triggerCount >= 4, 'append-only update and delete triggers installed');
        return ['outcome' => 'APPLIED', 'check_constraints' => 2, 'append_only_triggers' => $triggerCount];
    }

    function guardReinstallAfterFailedUp(): array
    {
        $migration = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/database/migrations/2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php';
        $failed = false;
        try {
            $migration->up();
        } catch (Throwable) {
            $failed = true;
        }
        must($failed, 'duplicate migration up must fail');
        $catalog = schemaGuards();
        must($catalog['append_only_triggers'] >= 4, 'append-only guards reinstalled after failed migration up');
        return ['outcome' => 'APPLIED'] + $catalog + ['failed_up_observed' => true, 'guard_reinstalled' => true];
    }

    function verifyDurable(array $f): array
    {
        $settlements = FinanceCashSettlement::query()->orderBy('bill_version_snapshot')->get();
        $receipts = FinanceSettlementOperationReceipt::query()->orderBy('id')->get();
        must($settlements->count() === 2 && $receipts->count() === 2, 'two-version settlement history retained');
        $settlement = $settlements->last();
        $receipt = $receipts->firstWhere('settlement_public_id', $settlement->public_id);
        must($receipt !== null, 'current settlement technical receipt retained');
        must($settlement->amount === (int) $f['amount'], 'server-derived amount retained');
        must((int) $settlements->sum('amount') === (int) $f['cumulative_net_amount'], 'no cumulative double charge');
        must($settlements->first()->amount === (int) $f['prior_settled_amount'], 'prior settlement retained exactly');
        must($settlement->public_id === $receipt->settlement_public_id, 'technical receipt binding');
        must($settlement->bill_version_public_id_snapshot === $f['version'], 'exact bill version binding');
        must($receipt->bill_version_public_id === $settlement->bill_version_public_id_snapshot, 'replay receipt bill-version public-id integrity');
        must(DB::table(SchemaQualifier::table('audit_events'))
            ->where('action', 'finance.workflow.mutate')->where('outcome', 'SUCCESS')
            ->where('metadata->operation', 'FINANCE_CASH_SETTLEMENT')->count() === 2,
            'two exact settlement success audits');
        return [
            'outcome' => 'RECONCILED', 'settlements' => 2, 'operation_receipts' => 2,
            'current_version_settlements' => 1, 'outstanding_amount' => $settlement->amount,
            'cumulative_settled_amount' => (int) $settlements->sum('amount'),
            'settlement_identity_sha256' => hash('sha256', $settlement->public_id),
        ];
    }

    function privilegeGuards(array $f): array
    {
        $readTables = ['encounters', 'finance_bills', 'finance_bill_versions', 'finance_bill_lines', 'finance_cash_settlements', 'finance_settlement_operation_receipts'];
        foreach ($readTables as $table) DB::table(SchemaQualifier::table($table))->count();
        $blocked = 0;
        foreach (['finance_bills', 'finance_bill_versions', 'finance_cash_settlements', 'finance_settlement_operation_receipts'] as $table) {
            try {
                FinanceMutationScope::run(
                    fn () => DB::table(SchemaQualifier::table($table))->whereRaw('1=0')->delete(),
                );
            } catch (QueryException) {
                $blocked++;
            }
        }
        must($blocked === 4, 'cashier runtime delete denial');
        $guardBlocked = 0;
        foreach ([
            ['finance_bills', 'state', $f['bill']],
            ['finance_cash_settlements', 'amount', $f['prior_settlement']],
        ] as [$table, $column, $publicId]) {
            try {
                FinanceMutationScope::run(
                    fn () => DB::table(SchemaQualifier::table($table))->where('public_id', $publicId)
                        ->update([$column => DB::raw($column)]),
                );
            } catch (QueryException) {
                $guardBlocked++;
            }
        }
        must($guardBlocked === 2, 'runtime lock grants remain database-guarded against mutation');
        return [
            'outcome' => 'APPLIED', 'read_tables' => 6, 'delete_privilege_denials' => 4,
            'database_guard_update_denials' => 2,
        ];
    }

    function resetAndVerify(array $f): array
    {
        $auditBefore = DB::table(SchemaQualifier::table('audit_events'))->count();
        app(SyntheticResetService::class)->reset([
            'actor' => user($f['cashier']), 'reason' => 'exact_cash_settlement_portability',
        ]);
        must(FinanceCashSettlement::query()->count() === 0, 'settlement reset');
        must(FinanceSettlementOperationReceipt::query()->count() === 0, 'settlement receipt reset');
        must(FinanceBill::query()->count() === 0, 'dependent finance bill reset');
        $auditAfter = DB::table(SchemaQualifier::table('audit_events'))->count();
        must($auditAfter >= $auditBefore + 2, 'reset audit preserved');
        return ['outcome' => 'RESET', 'settlements' => 0, 'operation_receipts' => 0, 'audit_preserved' => true];
    }

    $action = (string) getenv('SIMRS_EXACT_CASH_SETTLEMENT_ACTION');
    $f = fixture();
    protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
    try {
        $result = match ($action) {
            'prepare' => ['outcome' => 'APPLIED', 'fixture' => prepareFixture()],
            'schema_guards' => schemaGuards(),
            'guard_reinstall' => guardReinstallAfterFailedUp(),
            'settle_race' => settleRace($f),
            'verify' => verifyDurable($f),
            'privilege_guards' => privilegeGuards($f),
            'reset' => resetAndVerify($f),
            default => throw new RuntimeException('unknown exact cash settlement action'),
        };
        protocol('COMMITTED', $result);
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1, 'status' => 'BLOCKED', 'protocol_state' => 'FAILED',
            'scenario' => (string) getenv('SIMRS_EXACT_CASH_SETTLEMENT_SCENARIO'),
            'worker' => (string) getenv('SIMRS_EXACT_CASH_SETTLEMENT_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'diagnostic_message' => ($exception instanceof LogicException || $exception instanceof RuntimeException || $exception instanceof ErrorException)
                ? mb_substr($exception->getMessage(), 0, 300) : null,
            'failure_stage' => $action, 'sql_state' => (string) ($errorInfo[0] ?? ''),
            'driver_code' => (string) ($errorInfo[1] ?? ''),
            'query_sha256' => $query ? hash('sha256', $query->getSql()) : null,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Exact cash settlement rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Exact cash settlement scenario catalogue drifted.' unless SCENARIOS.length == 17 && SCENARIOS.uniq.length == 17
    raise CommandFailed, 'Embedded exact cash settlement worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 12_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false', 'LIS_INTEGRATION_ENABLED' => 'false',
      'PACS_INTEGRATION_ENABLED' => 'false', 'COLUMNS' => '300'
    )
  end

  def current_exact_cash_settlement_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    aggregate = Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h))
    {
      'files' => files, 'aggregate_sha256' => aggregate, 'application_source_sha256' => aggregate,
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
      'sqlite_gate_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS)),
    }
  end

  def run_sqlite_gate!
    assert_contract!
    results = SQLITE_FEATURE_TESTS.map do |path, method|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "SQLite-bound feature method is missing: #{path}##{method}." unless source.include?("function #{method}")
      stdout, stderr, status = Open3.capture3(
        @runner.process_environment(sqlite_application_environment), @php_binary, File.join(ROOT, 'artisan'),
        'test', path, "--filter=#{method}", unsetenv_others: true
      )
      raise CommandFailed, "SQLite gate failed at #{path}##{method}: #{(stdout + stderr).lines.last(12).join}" unless status.success?
      { 'path' => path, 'method' => method, 'output_sha256' => Digest::SHA256.hexdigest(stdout + stderr) }
    end
    {
      'status' => 'PASS', 'engine' => 'sqlite', 'test_count' => results.length,
      'catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS)),
      'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(results)), 'tests' => results,
    }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil, startup_barrier: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_EXACT_CASH_SETTLEMENT_ACTION' => action,
      'SIMRS_EXACT_CASH_SETTLEMENT_SCENARIO' => scenario,
      'SIMRS_EXACT_CASH_SETTLEMENT_WORKER' => worker,
      'SIMRS_EXACT_CASH_SETTLEMENT_HOLD_MS' => hold_ms.to_s,
      'SIMRS_EXACT_CASH_SETTLEMENT_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-exact-cash-settlement-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Scenario-bound exact cash settlement feature method is missing: #{path}##{method}." unless source.include?("function #{method}")
      cache_key = [path, method]
      proof = (@filtered_feature_evidence_cache ||= {})[cache_key] ||= begin
        recreate_application_schema_outside_guard!
        recorded_artisan!('test', path, "--filter=#{method}")
        { 'status' => 'PASS', 'proof_kind' => 'FILTERED_EXACT_ENGINE_FEATURE_TEST', 'path' => path, 'method' => method }
      end
      [label, proof.merge('scenario_binding' => label)]
    end
  end

  def start_settlement_worker!(action:, scenario:, fixture:, worker:, hold_ms:, connection_environment: nil)
    @command_catalog << ['exact-cash-settlement-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(
        action: action, scenario: scenario, worker: worker, fixture: fixture,
        hold_ms: hold_ms, connection_environment: connection_environment
      )), @php_binary, @worker_tempfile.path, unsetenv_others: true
    )
    stdin.close
    process = Worker.new(
      stdout: stdout, stderr: stderr, wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read }, scenario: scenario, label: worker
    )
    @workers << process
    process
  end

  def run_settlement_race!(fixture)
    scenario = 'same-key-same-payload-concurrency'
    first = start_settlement_worker!(action: 'settle_race', scenario: scenario, fixture: fixture, worker: 'A', hold_ms: HOLD_MS, connection_environment: @runtime_application_environment)
    first_started = await_protocol!(first, 'STARTED')
    holding = await_protocol!(first, 'HOLDING')
    raise CommandFailed, 'Settlement lock trace drifted.' unless holding.fetch('lock_order_trace') == ['encounters']
    second = start_settlement_worker!(action: 'settle_race', scenario: scenario, fixture: fixture, worker: 'B', hold_ms: 0, connection_environment: @runtime_application_environment)
    second_started = await_protocol!(second, 'STARTED')
    wait_observed = observe_real_database_wait!(Integer(second_started.fetch('backend_connection_id')))
    raise CommandFailed, 'Settlement race did not expose a real database wait.' unless wait_observed
    finals = [await_final!(first), await_final!(second)]
    outcomes = finals.map { |document| document.fetch('outcome') }.sort
    raise CommandFailed, "Settlement race outcomes drifted: #{outcomes.join(',')}." unless outcomes == %w[APPLIED REPLAYED]
    {
      'status' => 'PASS', 'proof_kind' => 'OBSERVED_DATABASE_WAIT',
      'independent_application_processes' => 2, 'real_database_wait_observed' => true,
      'first_backend_connection_id_sha256' => Digest::SHA256.hexdigest(first_started.fetch('backend_connection_id').to_s),
      'second_backend_connection_id_sha256' => Digest::SHA256.hexdigest(second_started.fetch('backend_connection_id').to_s),
      'observed_lock_order' => ['encounters'], 'outcomes' => outcomes,
      'third_connection_reconciliation_required' => true, 'deadlock_observed' => false,
    }
  ensure
    terminate_workers!
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL exact cash settlement runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each { |table| statements << %(GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}") }
    sequences.each do |sequence|
      statements << %(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}") if RUNTIME_INSERT_TABLES.any? { |table| sequence == "#{table}_id_seq" }
      statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      canonical = expected.split(', ').sort.join(',')
      raise CommandFailed, "PostgreSQL exact cash settlement runtime grant drifted for #{table}." unless actual == canonical
    end
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => '')
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    runtime_password = SecureRandom.hex(24)
    reset_password = SecureRandom.hex(24)
    tables = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "MySQL exact cash settlement runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each { |table| statements << "GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      sql = <<~SQL.gsub("\n", ' ')
        SELECT GROUP_CONCAT(PRIVILEGE_TYPE ORDER BY PRIVILEGE_TYPE SEPARATOR ',')
        FROM information_schema.TABLE_PRIVILEGES
        WHERE GRANTEE=CONCAT(CHAR(39),'#{runtime}',CHAR(39),'@',CHAR(39),'127.0.0.1',CHAR(39))
          AND TABLE_SCHEMA='#{@mysql_database}' AND TABLE_NAME='#{table}';
      SQL
      actual = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: sql).strip
      canonical = expected.split(', ').sort.join(',')
      raise CommandFailed, "MySQL exact cash settlement runtime grant drifted for #{table}." unless actual == canonical
    end
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'MySQL exact cash settlement runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def expect_settlement_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained cash settlement evidence unexpectedly allowed rollback.' if status.success?
    combined = (stdout + stderr).gsub(/\s+/, '').downcase
    expected = %w[
      refusingtodiscardcashsettlementtableswhilecorrelatedauditevidenceremains
      refusingtodiscardpopulatedcashsettlementevidence
    ]
    raise CommandFailed, 'Cash settlement rollback failed for an unexpected reason.' unless expected.any? { |fragment| combined.include?(fragment) }
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_exact_cash_settlement_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:, sqlite_gate:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-exact-cash-settlement-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_EXACT_CASH_SETTLEMENT_ONLY',
      'hosted_readiness_claim' => false, 'deployment_claim' => false,
      'owner_acceptance_claim' => false, 'g0_claim' => false, 'g3_claim' => false,
      'source_bindings' => bindings.merge(
        'command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),
        'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))
      ),
      'command_catalog' => @command_catalog, 'protocol_result_catalog' => @protocol_catalog,
      'boundary' => {
        'application_mode' => 'SIMULATION', 'synthetic_only' => true,
        'live_integrations_enabled' => false, 'disposable_local_engine' => true,
        'cash_only_exact_bill_version' => true, 'external_database_configuration_accepted' => false,
      },
      'engine' => engine_binding, 'sqlite_gate' => sqlite_gate,
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true, 'temporary_server_removed' => true,
        'temporary_user_state_removed' => true, 'temporary_worker_removed' => true,
        'strict_cleanup_verified' => true,
      },
      'open_boundaries' => [
        'G0 and G3 remain OPEN; this record is not owner acceptance, UAT, hosted migration, deployment, or production readiness.',
        'No partial payment, overpayment, card, refund, void, claim, BPJS, VClaim, E-Klaim, SATUSEHAT, ERP, or real patient data is exercised.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end

  def run!
    assert_contract!
    bindings = current_exact_cash_settlement_bindings
    sqlite_gate = run_sqlite_gate!
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    feature = bind_feature_evidence!(FEATURE_SCENARIO_TESTS)
    recreate_application_schema_outside_guard!
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    guard_reinstall = run_worker_command!(action: 'guard_reinstall', scenario: 'migration-guard-reinstall-after-failure', worker: 'MIGRATION_FAILURE', fixture: fixture, connection_environment: application_environment)
    schema_guards = run_worker_command!(action: 'schema_guards', scenario: 'database-check-and-append-only-trigger-refusal', worker: 'SCHEMA', fixture: fixture, connection_environment: application_environment)
    provision_runtime_identities!
    race = run_settlement_race!(fixture)
    durable = run_worker_command!(action: 'verify', scenario: 'third-connection-single-durable-settlement', worker: 'RECONCILE', fixture: fixture, connection_environment: application_environment)
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'exact-cashier-runtime-grants', worker: 'RUNTIME', fixture: fixture)
    rollback = expect_settlement_rollback_refusal!
    reset = run_worker_command!(action: 'reset', scenario: 'bounded-reset-recovery', worker: 'RESET', fixture: fixture, connection_environment: @reset_application_environment)

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'EMPTY_MIGRATION_DOWN_REAPPLY' },
      'migration-guard-reinstall-after-failure' => { 'status' => 'PASS', 'proof_kind' => 'FAILED_DUPLICATE_UP_AND_TRIGGER_CATALOG_READBACK', 'worker' => guard_reinstall },
      'exact-cashier-runtime-grants' => { 'status' => 'PASS', 'proof_kind' => 'READ_BACK_LEAST_PRIVILEGE_RUNTIME', 'worker' => privilege },
      'database-check-and-append-only-trigger-refusal' => { 'status' => 'PASS', 'proof_kind' => 'EXACT_ENGINE_CONSTRAINT_TRIGGER_CATALOG_AND_FILTERED_REFUSAL', 'worker' => schema_guards, 'feature' => feature.fetch('database-check-and-append-only-trigger-refusal') },
      'exact-server-derived-cash-amount' => feature.fetch('exact-server-derived-cash-amount'),
      'same-key-same-payload-concurrency' => race,
      'changed-payload-idempotency-conflict' => feature.fetch('changed-payload-idempotency-conflict'),
      'stale-bill-version-refusal' => feature.fetch('stale-bill-version-refusal'),
      'new-source-pending-refusal' => feature.fetch('new-source-pending-refusal'),
      'duplicate-settlement-refusal' => feature.fetch('duplicate-settlement-refusal'),
      'cumulative-version-outstanding-balance' => { 'status' => 'PASS', 'proof_kind' => 'TWO_VERSION_OUTSTANDING_BALANCE_AND_THIRD_CONNECTION_READBACK', 'worker' => durable, 'feature' => feature.fetch('cumulative-version-outstanding-balance') },
      'audit-failure-atomic-rollback' => feature.fetch('audit-failure-atomic-rollback'),
      'third-connection-single-durable-settlement' => { 'status' => 'PASS', 'proof_kind' => 'INDEPENDENT_THIRD_CONNECTION_READBACK', 'worker' => durable },
      'retained-evidence-migration-rollback-refusal' => { 'status' => rollback, 'proof_kind' => 'POPULATED_MIGRATION_DOWN_REFUSED' },
      'bounded-reset-recovery' => { 'status' => 'PASS', 'proof_kind' => 'REAL_RESET_GRAPH_ORDER_AND_AUDIT_PRESERVATION', 'worker' => reset, 'feature' => feature.fetch('bounded-reset-recovery') },
      'strict-cleanup' => { 'status' => 'PASS', 'proof_kind' => 'ENGINE_OWNED_STRICT_CLEANUP_PENDING_BINDING_RECHECK' },
    }
    raise CommandFailed, 'Exact cash settlement scenario evidence is incomplete.' unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
    assert_unchanged_binding!('Exact cash settlement execution bindings', bindings, current_exact_cash_settlement_bindings)
    cleanup!(strict: true)
    evidence_path = write_exact_cash_settlement_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios, sqlite_gate: sqlite_gate)
    {
      'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_EXACT_CASH_SETTLEMENT_ONLY',
      'engine' => @engine, 'scenario_count' => scenarios.length,
      'application_source_sha256' => bindings.fetch('application_source_sha256'),
      'sqlite_gate_status' => sqlite_gate.fetch('status'),
      'evidence_path' => evidence_path, 'evidence_sha256' => Digest::SHA256.file(evidence_path).hexdigest,
    }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalExactCashSettlementPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalExactCashSettlementPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalExactCashSettlementPortabilityRehearsal::CommandFailed => e
    warn "exact cash settlement portability rehearsal failed: #{e.message}"
    exit 1
  end
end
