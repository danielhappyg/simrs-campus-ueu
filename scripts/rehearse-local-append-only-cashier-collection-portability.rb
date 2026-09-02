#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-append-only-cash-settlement-correction-portability'

# Disposable exact-engine verification for the append-only cashier collection
# batch, independent close verification, and internal deposit-handoff lifecycle.
# It accepts no hosted/external database configuration and writes local evidence
# only after strict engine cleanup succeeds.
class LocalAppendOnlyCashierCollectionPortabilityRehearsal < LocalAppendOnlyCashSettlementCorrectionPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_CASHIER_COLLECTION'
  CONFIRMATION_ENV = 'SIMRS_CASHIER_COLLECTION_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-append-only-cashier-collection-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalAppendOnlyCashierCollectionPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_02_000900_create_cashier_collection_batch_tables.php'
  CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_EVIDENCE_TEMPLATE_2026-09-03.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
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

  RUNTIME_TABLE_GRANTS = {
    'users' => 'SELECT', 'roles' => 'SELECT', 'role_user' => 'SELECT',
    'permissions' => 'SELECT', 'permission_role' => 'SELECT',
    'patients' => 'SELECT', 'encounters' => 'SELECT, UPDATE',
    'pharmacy_medicines' => 'SELECT', 'pharmacy_depots' => 'SELECT',
    'pharmacy_stock_lots' => 'SELECT', 'pharmacy_prescriptions' => 'SELECT',
    'pharmacy_prescription_items' => 'SELECT', 'pharmacy_preparations' => 'SELECT',
    'pharmacy_handovers' => 'SELECT', 'pharmacy_handover_items' => 'SELECT',
    'pharmacy_returns' => 'SELECT', 'pharmacy_return_items' => 'SELECT',
    'pharmacy_financial_source_events' => 'SELECT',
    'radiology_examination_masters' => 'SELECT', 'radiology_orders' => 'SELECT',
    'radiology_performances' => 'SELECT', 'radiology_report_versions' => 'SELECT',
    'finance_radiology_tariff_bindings' => 'SELECT',
    'finance_radiology_tariff_binding_versions' => 'SELECT',
    'finance_radiology_source_events' => 'SELECT',
    'laboratory_examination_masters' => 'SELECT', 'laboratory_orders' => 'SELECT',
    'laboratory_specimen_attempts' => 'SELECT', 'laboratory_result_versions' => 'SELECT',
    'laboratory_critical_communications' => 'SELECT',
    'finance_laboratory_tariff_bindings' => 'SELECT',
    'finance_laboratory_tariff_binding_versions' => 'SELECT',
    'finance_laboratory_source_events' => 'SELECT',
    'inpatient_location_events' => 'SELECT', 'inpatient_bed_versions' => 'SELECT',
    'inpatient_discharges' => 'SELECT', 'finance_accommodation_tariff_bindings' => 'SELECT',
    'finance_accommodation_tariff_binding_versions' => 'SELECT',
    'finance_accommodation_source_events' => 'SELECT',
    'finance_charge_events' => 'SELECT', 'finance_bill_lines' => 'SELECT',
    'finance_bills' => 'SELECT, UPDATE',
    'finance_bill_versions' => 'SELECT, UPDATE',
    'finance_cash_settlements' => 'SELECT, INSERT, UPDATE',
    'finance_settlement_operation_receipts' => 'SELECT, INSERT',
    'finance_settlement_correction_cases' => 'SELECT, INSERT, UPDATE',
    'finance_settlement_correction_events' => 'SELECT, INSERT, UPDATE',
    'finance_settlement_correction_operation_receipts' => 'SELECT, INSERT',
    'finance_cashier_collection_batches' => 'SELECT, INSERT, UPDATE',
    'finance_cashier_collection_active_slots' => 'SELECT, INSERT, UPDATE, DELETE',
    'finance_cashier_collection_members' => 'SELECT, INSERT, UPDATE',
    'finance_cashier_collection_events' => 'SELECT, INSERT, UPDATE',
    'finance_cash_deposit_handoffs' => 'SELECT, INSERT, UPDATE',
    'finance_cashier_collection_operation_receipts' => 'SELECT, INSERT',
    'audit_events' => 'SELECT, INSERT'
  }.freeze
  RUNTIME_INSERT_TABLES = RUNTIME_TABLE_GRANTS.select { |_table, grants| grants.include?('INSERT') }.keys.freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-append-only-cashier-collection-portability.rb
    scripts/rehearse-local-append-only-cash-settlement-correction-portability.rb
    scripts/rehearse-local-exact-cash-settlement-portability.rb
    tests/Documentation/LocalAppendOnlyCashierCollectionPortabilityHarnessContractTest.rb
    tests/Documentation/LocalAppendOnlyCashierCollectionEvidenceTemplateTest.rb
    tests/Documentation/AppendOnlyCashierCollectionBatchCloseAndDepositHandoffV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/APPEND_ONLY_CASHIER_COLLECTION_BATCH_CLOSE_AND_DEPOSIT_HANDOFF_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_APPEND_ONLY_CASHIER_COLLECTION_EVIDENCE_TEMPLATE_2026-09-03.md
    database/migrations/2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php
    database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php
    database/migrations/2026_09_02_000900_create_cashier_collection_batch_tables.php
    tests/Feature/Finance/FinanceCashierCollectionCoreTest.php
    tests/Feature/Finance/FinanceCashSettlementCoreTest.php
    tests/Feature/Database/CashierCollectionMigrationTest.php
    tests/Feature/Authorization/CashierSupervisorAccessTest.php
    tests/Feature/Simulation/SimulationResetCommandTest.php
    app/Models/Encounter.php
    app/Models/FinanceBill.php
    app/Models/FinanceBillLine.php
    app/Models/FinanceBillVersion.php
    app/Models/FinanceCashSettlement.php
    app/Models/FinanceChargeEvent.php
    app/Models/FinanceCashierCollectionBatch.php
    app/Models/FinanceCashierCollectionActiveSlot.php
    app/Models/FinanceCashierCollectionMember.php
    app/Models/FinanceCashierCollectionEvent.php
    app/Models/FinanceCashDepositHandoff.php
    app/Models/FinanceCashierCollectionOperationReceipt.php
    app/Models/PharmacyDepot.php
    app/Models/PharmacyFinancialSourceEvent.php
    app/Models/PharmacyHandover.php
    app/Models/PharmacyHandoverItem.php
    app/Models/PharmacyMedicine.php
    app/Models/PharmacyPreparation.php
    app/Models/PharmacyPrescription.php
    app/Models/PharmacyPrescriptionItem.php
    app/Models/PharmacyStockLot.php
    app/Models/Patient.php
    app/Support/Finance/FinanceBillService.php
    app/Support/Finance/FinanceCashSettlementFingerprint.php
    app/Support/Finance/FinanceCashSettlementProjection.php
    app/Support/Finance/FinanceEvidenceFingerprint.php
    app/Support/Finance/FinanceProjection.php
    app/Support/Finance/FinanceSourceCoordinator.php
    app/Support/Finance/FinanceAccommodationSourceAdapter.php
    app/Support/Finance/FinanceLaboratorySourceAdapter.php
    app/Support/Finance/FinancePharmacySourceAdapter.php
    app/Support/Finance/FinanceRadiologySourceAdapter.php
    app/Support/Finance/FinanceSourceReadinessProjection.php
    app/Support/Finance/FinanceCashSettlementService.php
    app/Support/Finance/FinanceCashSettlementCorrectionService.php
    app/Support/Finance/FinanceCashierCollectionActorPolicy.php
    app/Support/Finance/FinanceCashierCollectionFingerprint.php
    app/Support/Finance/FinanceCashierCollectionService.php
    app/Support/Finance/FinanceCashierCollectionProjection.php
    app/Support/Finance/FinanceAppendOnlyGuard.php
    app/Support/Finance/FinanceSqlWriteGuard.php
    app/Support/Pharmacy/PharmacyCanonicalJson.php
    app/Support/Pharmacy/PharmacyMutationScope.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Simulation/SyntheticResetService.php
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'exact-role-denials' => ['tests/Feature/Authorization/CashierSupervisorAccessTest.php', 'test_admin_system_admin_and_mixed_role_accounts_fail_closed'],
    'database-check-constraints' => ['tests/Feature/Database/CashierCollectionMigrationTest.php', 'test_schema_activation_discriminator_and_all_evidence_guards_are_installed'],
    'database-append-only-refusals' => ['tests/Feature/Finance/FinanceCashierCollectionCoreTest.php', 'test_append_only_tamper_and_missing_success_audit_roll_back'],
    'double-close-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_frozen_batch_refuses_correction_but_historical_settlement_replay_survives_close'],
    'audit-failure-atomic-rollback' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_audit_failure_rolls_back_settlement_and_operation_receipt'],
    'recovery-tamper-detection' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_recovery_detects_binding_marker_or_member_tamper'],
    'owner-only-bounded-reset' => ['tests/Feature/Simulation/SimulationResetCommandTest.php', 'test_reset_succeeds_with_force_in_simulation']
  }.freeze
  SQLITE_FEATURE_TESTS = FEATURE_SCENARIO_TESTS.values.uniq.freeze
  EXACT_WORKER_ONLY_SCENARIOS = {
    'database-append-only-refusals' => 'privilege_guards',
    'owner-only-bounded-reset' => 'owner_reset'
  }.freeze
  EXACT_FILTERED_FEATURE_SCENARIOS = (FEATURE_SCENARIO_TESTS.keys - EXACT_WORKER_ONLY_SCENARIOS.keys).freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\FinanceBill;
    use App\Models\FinanceBillVersion;
    use App\Models\FinanceCashSettlement;
    use App\Models\FinanceCashierCollectionActiveSlot;
    use App\Models\FinanceCashierCollectionBatch;
    use App\Models\FinanceCashierCollectionEvent;
    use App\Models\FinanceCashierCollectionMember;
    use App\Models\FinanceSettlementCorrectionCase;
    use App\Models\FinanceSettlementCorrectionEvent;
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
    use App\Support\Finance\FinanceCashSettlementCorrectionProjection;
    use App\Support\Finance\FinanceCashSettlementCorrectionService;
    use App\Support\Finance\FinanceCashSettlementProjection;
    use App\Support\Finance\FinanceCashSettlementService;
    use App\Support\Finance\FinanceCashierCollectionFingerprint;
    use App\Support\Finance\FinanceCashierCollectionProjection;
    use App\Support\Finance\FinanceCashierCollectionService;
    use App\Support\Finance\FinanceDenied;
    use App\Support\Finance\FinanceMutationScope;
    use App\Support\Finance\FinanceProjection;
    use App\Support\Finance\FinanceSchemaMutationScope;
    use App\Support\Pharmacy\PharmacyCanonicalJson;
    use App\Support\Pharmacy\PharmacyMutationScope;
    use App\Support\Operations\SyntheticRecoverySnapshot;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    require (string) getenv('SIMRS_REHEARSAL_ROOT').'/vendor/autoload.php';
    $app = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    function must(bool $condition, string $label): void
    {
        if (!$condition) throw new RuntimeException('assertion failed: '.$label);
    }

    function protocol(string $state, array $extra = []): void
    {
        echo json_encode(array_merge([
            'schema_version' => 1, 'status' => 'PASS', 'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_COLLECTION_SCENARIO'),
            'worker' => (string) getenv('SIMRS_COLLECTION_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_COLLECTION_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::selectOne('SELECT pg_backend_pid() AS id'), 'id')
            : (int) data_get(DB::selectOne('SELECT CONNECTION_ID() AS id'), 'id');
    }

    function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
        return $actor->fresh();
    }

    function user(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->sole();
    }

    function financeTransaction(callable $callback): mixed
    {
        return FinanceMutationScope::run(fn () => DB::transaction(function () use ($callback): mixed {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') DB::statement("SET LOCAL simrs.finance_mutation = '1'");
            if ($driver === 'mysql') DB::statement('SET @simrs_finance_mutation = 1');
            try { return $callback(); }
            finally { if ($driver === 'mysql') DB::statement('SET @simrs_finance_mutation = 0'); }
        }));
    }

    function createPendingSettlementFixture(User $cashier, int $amount, string $suffix): array
    {
        $pharmacist = actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id, 'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION, 'clinic_name' => 'Poli Batch Kas '.$suffix,
        ]);
        $digest = str_repeat('a', 64);
        PharmacyMutationScope::run(function () use ($pharmacist, $patient, $encounter, $digest, $amount, $suffix): void {
            $medicine = PharmacyMedicine::query()->create([
                'medicine_code' => 'MED-BPK-'.$suffix, 'generic_name' => 'Obat sintetis '.$suffix,
                'strength_text' => '500 mg', 'dosage_form' => 'TABLET', 'base_unit' => 'TABLET',
                'route_choices' => ['ORAL'], 'acquisition_value' => 1000,
                'teaching_sale_value' => $amount, 'state' => PharmacyMedicine::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEP-BPK-'.$suffix, 'display_name' => 'Depo sintetis '.$suffix,
                'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
                'state' => PharmacyDepot::ACTIVE, 'version' => 1, 'current_content_digest' => $digest,
            ]);
            $lot = PharmacyStockLot::query()->create([
                'medicine_id' => $medicine->id, 'depot_id' => $depot->id,
                'opened_by_user_id' => $pharmacist->id, 'medicine_version' => 1, 'depot_version' => 1,
                'medicine_code_snapshot' => $medicine->medicine_code, 'depot_code_snapshot' => $depot->depot_code,
                'lot_code' => 'LOT-BPK-'.$suffix, 'received_at' => now(),
                'expiry_date' => now()->addYear()->toDateString(), 'available_quantity' => 100,
                'quarantined_quantity' => 0, 'acquisition_value' => 1000,
                'source_reference' => 'BPK-'.$suffix, 'state' => PharmacyStockLot::ACTIVE,
                'version' => 1, 'content_digest' => $digest,
            ]);
            $prescription = PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id, 'patient_id' => $patient->id,
                'ordering_physician_user_id' => $pharmacist->id, 'depot_id' => $depot->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => 'Poli Batch Kas', 'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code, 'status' => PharmacyPrescription::HANDED_OVER,
                'version' => 1, 'current_content_digest' => $digest, 'ordered_at' => now(),
            ]);
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $prescription->id, 'medicine_id' => $medicine->id,
                'line_number' => 1, 'medicine_version' => 1,
                'medicine_version_public_id' => $medicine->public_id, 'medicine_content_digest' => $digest,
                'medicine_code' => $medicine->medicine_code, 'medicine_name' => $medicine->generic_name,
                'strength_text' => '500 mg', 'dosage_form' => 'TABLET', 'base_unit' => 'TABLET',
                'dose_text' => '1 tablet', 'route' => 'ORAL', 'frequency_text' => '1 kali sehari',
                'duration_text' => '1 hari', 'requested_quantity' => 1, 'verified_quantity' => 1,
                'sale_value_snapshot' => $amount, 'instruction' => 'Sesudah makan',
                'content_digest' => $digest, 'created_at' => now(),
            ]);
            $preparation = PharmacyPreparation::query()->create([
                'prescription_id' => $prescription->id, 'technician_user_id' => $pharmacist->id,
                'sequence' => 1, 'state' => PharmacyPreparation::ACTIVE,
                'prescription_fingerprint' => $digest, 'stock_fingerprint' => $digest,
                'content_digest' => $digest, 'prepared_at' => now(), 'created_at' => now(),
            ]);
            $handover = PharmacyHandover::query()->create([
                'prescription_id' => $prescription->id, 'preparation_id' => $preparation->id,
                'pharmacist_user_id' => $pharmacist->id, 'sequence' => 1,
                'state' => PharmacyHandover::FULL, 'preparation_fingerprint' => $digest,
                'content_digest' => $digest, 'handed_over_at' => now(), 'created_at' => now(),
            ]);
            $handoverItem = PharmacyHandoverItem::query()->create([
                'handover_id' => $handover->id, 'prescription_item_id' => $item->id,
                'stock_lot_id' => $lot->id, 'quantity' => 1, 'sale_value_snapshot' => $amount,
                'content_digest' => $digest, 'created_at' => now(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id,
                'actor_user_id' => $pharmacist->id, 'event_type' => PharmacyFinancialSourceEvent::CHARGE,
                'quantity' => 1, 'amount' => $amount, 'source_type' => 'HANDOVER_ITEM',
                'source_public_id' => $handoverItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $prescription->public_id, $item->public_id, $pharmacist->id,
                    PharmacyFinancialSourceEvent::CHARGE, 1, $amount, 'HANDOVER_ITEM', $handoverItem->public_id,
                ]), 'occurred_at' => now(), 'created_at' => now(),
            ]);
        });
        $bills = app(FinanceBillService::class);
        $bill = $bills->synchronize($encounter->public_id, $cashier, 'collection-sync-'.$suffix)->record;
        must($bill instanceof FinanceBill, 'valued pharmacy source synchronized');
        $version = $bills->issue(
            $bill->public_id, $cashier, app(FinanceProjection::class)->fingerprint($bill),
            'Penerbitan sumber farmasi sintetis untuk rehearsal batch kas.', 'collection-issue-'.$suffix,
        )->record;
        must($version instanceof FinanceBillVersion && $version->net_amount === $amount, 'valued bill issued');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $cashier);
        return [
            'encounter' => $encounter->public_id, 'bill' => $bill->public_id,
            'bill_fingerprint' => $projection['bill_fingerprint'],
            'version_digest' => $projection['bill_version_content_digest'],
            'idempotency_key' => 'collection-settlement-'.$suffix,
        ];
    }

    function createSettledFixture(
        User $cashier,
        FinanceCashierCollectionBatch $batch,
        int $amount,
        string $suffix,
    ): FinanceCashSettlement {
        $pending = createPendingSettlementFixture($cashier, $amount, $suffix);
        $result = app(FinanceCashSettlementService::class)->settle(
            $pending['bill'], $cashier, $pending['bill_fingerprint'], $pending['version_digest'],
            $pending['idempotency_key'],
        );
        must(! $result->replayed, 'initial settlement applied once');
        must($result->record instanceof FinanceCashSettlement, 'initial settlement created by service');
        must($result->record->amount === $amount, 'initial settlement uses exact issued amount');
        must(FinanceCashierCollectionMember::query()
            ->where('collection_batch_id', $batch->id)
            ->where('settlement_id', $result->record->id)->count() === 1,
            'initial settlement bound to open cashier batch');

        return $result->record;
    }

    function prepareFixture(): array
    {
        $collections = app(FinanceCashierCollectionService::class);
        $cashierA = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $batchA = $collections->open($cashierA, 'collection-portability-open-a')->record;
        createSettledFixture($cashierA, $batchA, 9000, 'A1');
        $pendingA = createPendingSettlementFixture($cashierA, 9000, 'A2');

        $cashierB = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $batchB = $collections->open($cashierB, 'collection-portability-open-b')->record;
        $settlementB = createSettledFixture($cashierB, $batchB, 9000, 'B1');
        $supervisorB = actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $corrections = app(FinanceCashSettlementCorrectionService::class);
        $case = $corrections->request(
            $settlementB->public_id, $cashierB, FinanceSettlementCorrectionCase::DUPLICATE_COLLECTION,
            'Pengembalian exact engine menunggu penyelesaian.', $settlementB->content_digest,
            'collection-portability-refund-request',
        )->record;
        $pending = app(FinanceCashSettlementCorrectionProjection::class)->case($case->public_id, $supervisorB);
        $corrections->review(
            $case->public_id, $supervisorB, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Pengembalian penuh disetujui untuk rehearsal exact engine.', $pending['fingerprint'],
            'collection-portability-refund-review',
        );

        $cashierC = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $batchC = $collections->open($cashierC, 'collection-portability-open-c')->record;
        createSettledFixture($cashierC, $batchC, 7000, 'C1');
        $supervisorC = actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);

        return [
            'settlement_race' => array_merge(
                ['cashier' => $cashierA->public_id, 'batch' => $batchA->public_id, 'counted' => 9000],
                $pendingA,
            ),
            'refund_race' => ['cashier' => $cashierB->public_id, 'batch' => $batchB->public_id,
                'supervisor' => $supervisorB->public_id, 'case' => $case->public_id, 'counted' => 9000],
            'same_key' => ['cashier' => $cashierC->public_id, 'supervisor' => $supervisorC->public_id,
                'batch' => $batchC->public_id, 'counted' => 7000],
        ];
    }

    function lockCoordination(array $part): FinanceCashierCollectionBatch
    {
        $cashier = user($part['cashier']);
        $slot = FinanceCashierCollectionActiveSlot::query()->whereKey($cashier->id)->lockForUpdate()->sole();
        return FinanceCashierCollectionBatch::query()->whereKey($slot->collection_batch_id)->lockForUpdate()->sole();
    }

    function settlementHold(array $f): array
    {
        $part = $f['settlement_race'];
        return financeTransaction(function () use ($part): array {
            $batch = lockCoordination($part);
            protocol('HOLDING', ['backend_connection_id' => backendConnectionId(),
                'lock_order_trace' => ['finance_cashier_collection_active_slots', 'finance_cashier_collection_batches']]);
            usleep(((int) getenv('SIMRS_COLLECTION_HOLD_MS')) * 1000);
            $cashier = user($part['cashier']);
            $settlement = app(FinanceCashSettlementService::class)->settle(
                $part['bill'], $cashier, $part['bill_fingerprint'], $part['version_digest'],
                $part['idempotency_key'],
            )->record;
            return ['outcome' => 'SETTLEMENT_COMMITTED', 'settlement' => $settlement->public_id];
        });
    }

    function closeBatch(array $part, string $key, int $counted): array
    {
        $cashier = user($part['cashier']);
        $view = app(FinanceCashierCollectionProjection::class)->batch($part['batch'], $cashier);
        try {
            $result = app(FinanceCashierCollectionService::class)->requestClose(
                $part['batch'], $cashier, $counted, $view['state_fingerprint'], $key,
            );
            return ['outcome' => $result->replayed ? 'REPLAYED' : 'CLOSED', 'event' => $result->record->public_id];
        } catch (FinanceDenied $denied) {
            return ['outcome' => strtoupper($denied->reason).'_REFUSED', 'reason' => $denied->reason];
        }
    }

    function refundHold(array $f): array
    {
        $part = $f['refund_race'];
        return financeTransaction(function () use ($part): array {
            lockCoordination($part);
            protocol('HOLDING', ['backend_connection_id' => backendConnectionId(),
                'lock_order_trace' => ['finance_cashier_collection_active_slots', 'finance_cashier_collection_batches']]);
            usleep(((int) getenv('SIMRS_COLLECTION_HOLD_MS')) * 1000);
            $supervisor = user($part['supervisor']);
            $view = app(FinanceCashSettlementCorrectionProjection::class)->case($part['case'], $supervisor);
            app(FinanceCashSettlementCorrectionService::class)->completeRefund(
                $part['case'], $supervisor, $view['fingerprint'], 'collection-portability-refund-complete',
            );
            return ['outcome' => 'REFUND_COMPLETED'];
        });
    }

    function ensureRefundBatchClosed(array $f): array
    {
        $part = $f['refund_race'];
        $cashier = user($part['cashier']);
        $batch = FinanceCashierCollectionBatch::query()->where('public_id', $part['batch'])->sole();
        $slot = FinanceCashierCollectionActiveSlot::query()->whereKey($cashier->id)->first();
        $finalizer = 'ALREADY_FROZEN';
        if ($slot) {
            must($slot->collection_batch_id === $batch->id, 'refund batch owns cashier active slot');
            $view = app(FinanceCashierCollectionProjection::class)->batch($batch->public_id, $cashier);
            $result = app(FinanceCashierCollectionService::class)->requestClose(
                $batch->public_id, $cashier, 0, $view['state_fingerprint'],
                'collection-portability-close-refund-finalizer',
            );
            must(! $result->replayed, 'refund batch finalizer applies one durable close');
            $finalizer = 'CLOSED_BY_FINALIZER';
        }
        $batch->refresh();
        [$members, $events, , $gross, $refunded] = app(FinanceCashierCollectionService::class)
            ->reconciledEvidence($batch);
        must($members->count() === 1 && $events->count() === 1, 'refund close durable membership and event');
        $close = $events->sole();
        must($close->event_type === FinanceCashierCollectionEvent::CLOSE_REQUESTED, 'refund close event type');
        must($gross === 9000 && $refunded === 9000, 'refund close reconciled gross and completed refund');
        must($close->membership_count === 1 && $close->gross_amount === 9000, 'refund close membership snapshot');
        must($close->completed_refund_amount === 9000 && $close->expected_net_amount === 0,
            'refund close exact net equation');
        must($close->counted_amount === 0 && $close->variance_amount === 0,
            'refund close counted cash and zero variance');
        must(hash_equals($close->content_digest, app(FinanceCashierCollectionFingerprint::class)->event($close)),
            'refund close event content integrity');

        return [
            'outcome' => 'REFUND_BATCH_CLOSED_EXACT', 'finalizer_branch' => $finalizer,
            'membership_count' => 1, 'gross_amount' => 9000, 'completed_refund_amount' => 9000,
            'expected_net_amount' => 0, 'counted_amount' => 0, 'variance_amount' => 0,
            'event_content_digest_sha256' => hash('sha256', $close->content_digest),
            'durable_membership_and_event_integrity' => true,
        ];
    }

    function sameKeyClose(array $f): array
    {
        $part = $f['same_key'];
        $key = 'collection-portability-concurrent-close';
        if ((string) getenv('SIMRS_COLLECTION_WORKER') === 'A') {
            return financeTransaction(function () use ($part, $key): array {
                lockCoordination($part);
                protocol('HOLDING', ['backend_connection_id' => backendConnectionId(),
                    'lock_order_trace' => ['finance_cashier_collection_active_slots', 'finance_cashier_collection_batches']]);
                usleep(((int) getenv('SIMRS_COLLECTION_HOLD_MS')) * 1000);
                return closeBatch($part, $key, (int) $part['counted']);
            });
        }
        $result = closeBatch($part, $key, (int) $part['counted']);
        if (($result['reason'] ?? null) === 'batch_not_open') {
            $result = closeBatch($part, $key, (int) $part['counted']);
            $result['retried_after_wait'] = true;
        }
        return $result;
    }

    function terminalLifecycle(array $f): array
    {
        $part = $f['same_key'];
        $cashier = user($part['cashier']);
        $supervisor = user($part['supervisor']);
        $collections = app(FinanceCashierCollectionService::class);
        $projection = app(FinanceCashierCollectionProjection::class);
        $doubleClose = false;
        try {
            $view = $projection->batch($part['batch'], $cashier);
            $collections->requestClose($part['batch'], $cashier, (int) $part['counted'], $view['state_fingerprint'], 'collection-portability-close-duplicate');
        } catch (FinanceDenied $denied) { $doubleClose = $denied->reason === 'batch_not_open'; }
        must($doubleClose, 'double close refusal');
        $frozen = $projection->batch($part['batch'], $supervisor);
        $collections->verify($part['batch'], $supervisor, $frozen['state_fingerprint'], 'collection-portability-verify');
        $doubleVerify = false;
        try {
            $verified = $projection->batch($part['batch'], $supervisor);
            $collections->verify($part['batch'], $supervisor, $verified['state_fingerprint'], 'collection-portability-verify-duplicate');
        } catch (FinanceDenied $denied) { $doubleVerify = $denied->reason === 'batch_not_verifiable'; }
        must($doubleVerify, 'double verify refusal');
        $verified = $projection->batch($part['batch'], $cashier);
        $collections->createHandoff($part['batch'], $cashier, $verified['state_fingerprint'], 'collection-portability-handoff');
        $doubleHandoff = false;
        try {
            $handed = $projection->batch($part['batch'], $cashier);
            $collections->createHandoff($part['batch'], $cashier, $handed['state_fingerprint'], 'collection-portability-handoff-duplicate');
        } catch (FinanceDenied $denied) { $doubleHandoff = $denied->reason === 'handoff_already_exists'; }
        must($doubleHandoff, 'double handoff refusal');
        return ['outcome' => 'TERMINAL_REFUSALS_PROVEN', 'double_close_refused' => true,
            'double_verify_refused' => true, 'double_handoff_refused' => true];
    }

    function readback(array $f): array
    {
        $collections = app(FinanceCashierCollectionService::class);
        $result = [];
        foreach ($f as $label => $part) {
            $batch = FinanceCashierCollectionBatch::query()->where('public_id', $part['batch'])->sole();
            [$members, $events, , $gross, $refunded] = $collections->reconciledEvidence($batch);
            $result[$label] = ['members' => $members->count(), 'events' => $events->count(),
                'gross' => $gross, 'refunded' => $refunded, 'net' => $gross - $refunded];
        }
        must($result['settlement_race']['members'] === 2 && $result['settlement_race']['net'] === 18000, 'settlement race readback');
        must($result['refund_race']['members'] === 1 && $result['refund_race']['net'] === 0, 'refund race readback');
        must($result['same_key']['members'] === 1 && $result['same_key']['net'] === 7000, 'same key readback');
        $method = (new ReflectionClass(SyntheticRecoverySnapshot::class))->getMethod('financeCashierCollectionMismatchCount');
        must((int) $method->invoke(app(SyntheticRecoverySnapshot::class)) === 0, 'healthy collection recovery');
        return ['outcome' => 'THIRD_CONNECTION_RECONCILED', 'independent_connection' => true,
            'batches' => $result, 'recovery_mismatches' => 0];
    }

    function recoveryTamper(): array
    {
        $snapshot = app(SyntheticRecoverySnapshot::class);
        $method = (new ReflectionClass(SyntheticRecoverySnapshot::class))->getMethod('financeCashierCollectionMismatchCount');
        must((int) $method->invoke($snapshot) === 0, 'healthy recovery before tamper');
        DB::beginTransaction();
        try {
            FinanceAppendOnlyGuard::runSyntheticReset(function (): void {
                FinanceMutationScope::run(function (): void {
                    $member = FinanceCashierCollectionMember::query()->orderBy('id')->firstOrFail();
                    DB::table(SchemaQualifier::table('finance_cashier_collection_members'))->where('id', $member->id)
                        ->update(['content_digest' => str_repeat('f', 64)]);
                });
            });
            must((int) $method->invoke($snapshot) > 0, 'member tamper detected');
        } finally { DB::rollBack(); }
        must((int) $method->invoke($snapshot) === 0, 'recovery restored after rollback probe');
        return ['outcome' => 'RECOVERY_TAMPER_DETECTED', 'member_tamper_detected' => true, 'rollback_restored' => true];
    }

    function nativeCheckRejection(callable $probe, string $constraint): array
    {
        try {
            $probe();
        } catch (QueryException $exception) {
            $errorInfo = $exception->errorInfo ?? [];
            $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());
            $driverCode = (string) ($errorInfo[1] ?? '');
            must($sqlState !== '', $constraint.' SQLSTATE captured');
            must(str_contains(strtolower($exception->getMessage()), strtolower($constraint)),
                $constraint.' named by native engine rejection');

            return [
                'constraint' => $constraint, 'sql_state' => $sqlState, 'driver_code' => $driverCode,
                'diagnostic_fingerprint' => hash('sha256', $constraint."\0".$sqlState."\0".$driverCode),
                'query_text_recorded' => false,
            ];
        }

        throw new RuntimeException('native CHECK unexpectedly accepted invalid '.$constraint.' row');
    }

    function constraintProbes(array $f): array
    {
        $part = $f['settlement_race'];
        $batch = FinanceCashierCollectionBatch::query()->where('public_id', $part['batch'])->sole();
        $cashier = user($part['cashier']);
        $eventsBefore = FinanceCashierCollectionEvent::query()->where('collection_batch_id', $batch->id)->count();
        $receiptsBefore = DB::table(SchemaQualifier::table('finance_cashier_collection_operation_receipts'))
            ->where('collection_batch_id', $batch->id)->count();
        $event = nativeCheckRejection(function () use ($batch, $cashier): void {
            financeTransaction(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_events'))->insert([
                'public_id' => str_repeat('E', 26), 'collection_batch_id' => $batch->id,
                'sequence' => 2, 'event_type' => FinanceCashierCollectionEvent::CLOSE_VERIFIED,
                'previous_event_digest' => str_repeat('1', 64), 'actor_user_id' => $cashier->id,
                'actor_name_snapshot' => $cashier->name, 'membership_count' => 1,
                'gross_amount' => 9000, 'completed_refund_amount' => 0,
                'expected_net_amount' => 9000, 'counted_amount' => 9001, 'variance_amount' => 1,
                'membership_digest' => str_repeat('2', 64), 'explanation' => null,
                'content_digest' => str_repeat('3', 64), 'occurred_at' => now(), 'created_at' => now(),
            ]));
        }, 'fcce_values_ck');
        $receipt = nativeCheckRejection(function () use ($batch, $cashier): void {
            financeTransaction(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_operation_receipts'))->insert([
                'public_id' => str_repeat('R', 26), 'actor_user_id' => $cashier->id,
                'collection_batch_id' => $batch->id, 'collection_event_id' => null,
                'deposit_handoff_id' => null, 'operation' => 'FINANCE_CASHIER_COLLECTION_OPEN',
                'idempotency_key' => 'invalid-open-event-shape', 'payload_digest' => str_repeat('4', 64),
                'result_type' => 'EVENT', 'result_public_id' => str_repeat('X', 26),
                'batch_content_digest' => $batch->content_digest, 'event_content_digest' => null,
                'handoff_content_digest' => null, 'result_digest' => str_repeat('5', 64),
                'request_correlation_id' => null, 'completed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }, 'fccor_result_ck');
        must(FinanceCashierCollectionEvent::query()->where('collection_batch_id', $batch->id)->count() === $eventsBefore,
            'invalid collection event rolled back');
        must(DB::table(SchemaQualifier::table('finance_cashier_collection_operation_receipts'))
            ->where('collection_batch_id', $batch->id)->count() === $receiptsBefore,
            'invalid collection receipt rolled back');
        $method = (new ReflectionClass(SyntheticRecoverySnapshot::class))->getMethod('financeCashierCollectionMismatchCount');
        must((int) $method->invoke(app(SyntheticRecoverySnapshot::class)) === 0,
            'native CHECK probes preserve healthy recovery');

        return [
            'outcome' => 'NATIVE_CHECK_REJECTIONS_PROVEN', 'fcce_values_ck' => $event,
            'fccor_result_ck' => $receipt, 'failed_probe_rows_retained' => 0,
            'healthy_recovery_mismatch_count' => 0,
        ];
    }

    function schemaGuards(): array
    {
        $driver = DB::connection()->getDriverName();
        $requiredChecks = ['fccm_values_ck', 'fcce_values_ck', 'fcdh_values_ck', 'fccor_result_ck'];
        if ($driver === 'pgsql') {
            $checks = collect(DB::select("SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema='laravel' AND constraint_type='CHECK'"))->pluck('constraint_name')->all();
            $triggerCatalog = "SELECT trigger.tgname AS trigger_name FROM pg_catalog.pg_trigger trigger JOIN pg_catalog.pg_class relation ON relation.oid=trigger.tgrelid JOIN pg_catalog.pg_namespace namespace ON namespace.oid=relation.relnamespace WHERE namespace.nspname='laravel' AND NOT trigger.tgisinternal";
            $triggers = collect(DB::select($triggerCatalog))->pluck('trigger_name')->all();
            $identifiers = collect(DB::select("SELECT indexname AS name FROM pg_indexes WHERE schemaname='laravel' UNION ALL SELECT trigger.tgname AS name FROM pg_catalog.pg_trigger trigger JOIN pg_catalog.pg_class relation ON relation.oid=trigger.tgrelid JOIN pg_catalog.pg_namespace namespace ON namespace.oid=relation.relnamespace WHERE namespace.nspname='laravel' AND NOT trigger.tgisinternal UNION ALL SELECT constraint_name AS name FROM information_schema.table_constraints WHERE table_schema='laravel'"))->pluck('name')->all();
        } else {
            $checks = collect(DB::select("SELECT CONSTRAINT_NAME AS constraint_name FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND constraint_type='CHECK'"))->pluck('constraint_name')->all();
            $triggers = collect(DB::select('SELECT TRIGGER_NAME AS trigger_name FROM information_schema.triggers WHERE trigger_schema=DATABASE()'))->pluck('trigger_name')->all();
            $identifiers = collect(DB::select("SELECT INDEX_NAME AS name FROM information_schema.statistics WHERE table_schema=DATABASE() UNION ALL SELECT TRIGGER_NAME AS name FROM information_schema.triggers WHERE trigger_schema=DATABASE() UNION ALL SELECT CONSTRAINT_NAME AS name FROM information_schema.table_constraints WHERE table_schema=DATABASE()"))->pluck('name')->all();
        }
        foreach ($requiredChecks as $check) must(in_array($check, $checks, true), 'required check '.$check);
        foreach (['fccb', 'fccm', 'fcce', 'fcdh', 'fccor'] as $base) {
            $expected = $driver === 'pgsql' ? ["{$base}_immutable", "{$base}_truncate_guard"] : ["{$base}_immutable_update", "{$base}_immutable_delete"];
            foreach ($expected as $trigger) must(in_array($trigger, $triggers, true), 'short trigger '.$trigger);
        }
        $max = collect($identifiers)->map(fn ($name) => strlen((string) $name))->max() ?? 0;
        must($max <= 64, 'all exact identifiers fit MySQL limit');
        return ['outcome' => 'SCHEMA_GUARDED', 'checks' => $requiredChecks,
            'short_trigger_bases' => ['fccb', 'fccm', 'fcce', 'fcdh', 'fccor'], 'max_identifier_length' => $max];
    }

    function uniqueBarrierNames(): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname='laravel' AND tablename='finance_cash_settlements'"))->pluck('indexname')->all();
        }
        return collect(DB::select("SELECT DISTINCT INDEX_NAME AS indexname FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='finance_cash_settlements'"))->pluck('indexname')->all();
    }

    function failureSafety(): array
    {
        $collection = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/database/migrations/2026_09_02_000900_create_cashier_collection_batch_tables.php';
        $correction = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php';
        $collection->down();
        $correction->down();
        FinanceSchemaMutationScope::run(function (): void {
            Schema::create(SchemaQualifier::table('finance_cashier_collection_batches'), function ($table): void {
                $table->id(); $table->string('sentinel');
            });
            DB::table(SchemaQualifier::table('finance_cashier_collection_batches'))->insert(['sentinel' => 'retained-partial-install']);
        });
        $failed = false;
        try { $collection->up(); } catch (Throwable) { $failed = true; }
        must($failed, 'forced installation failure');
        $indexes = uniqueBarrierNames();
        must(in_array('fcs_bill_version_uq', $indexes, true), 'lifetime bill-version unique preserved');
        must(in_array('fcs_bill_version_number_uq', $indexes, true), 'lifetime bill-version-number unique preserved');
        $driver = DB::connection()->getDriverName();
        $guardCount = $driver === 'pgsql'
            ? DB::table('information_schema.triggers')->where('trigger_schema', 'laravel')->where('event_object_table', 'finance_cash_settlements')->count()
            : DB::table('information_schema.triggers')->whereRaw('trigger_schema=DATABASE()')->where('event_object_table', 'finance_cash_settlements')->count();
        must($guardCount >= ($driver === 'pgsql' ? 2 : 2), 'guards reinstalled after failed collection up');
        FinanceSchemaMutationScope::run(function (): void {
            FinanceAppendOnlyGuard::remove();
            try {
                Schema::dropIfExists(SchemaQualifier::table('finance_cashier_collection_batches'));
                if (Schema::hasColumn(SchemaQualifier::table('finance_cash_settlements'), 'collection_binding_required')) {
                    Schema::table(SchemaQualifier::table('finance_cash_settlements'), function ($table): void {
                        $table->dropIndex('fcs_collection_binding_idx');
                        $table->dropColumn('collection_binding_required');
                    });
                }
            } finally { FinanceAppendOnlyGuard::install(); }
        });
        $correction->up();
        $collection->up();
        return ['outcome' => 'FAIL_CLOSED', 'forced_failure' => true,
            'old_unique_barriers_preserved' => 2, 'guard_reinstalled' => true, 'reapply' => true];
    }

    function privilegeGuards(array $f): array
    {
        $batch = FinanceCashierCollectionBatch::query()->where('public_id', $f['same_key']['batch'])->sole();
        $member = FinanceCashierCollectionMember::query()->where('collection_batch_id', $batch->id)->sole();
        $denials = 0;
        try { financeTransaction(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_members'))->where('id', $member->id)->delete()); }
        catch (QueryException) { $denials++; }
        try { financeTransaction(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_batches'))->where('id', $batch->id)->update(['batch_number' => 'TAMPER'])); }
        catch (QueryException) { $denials++; }
        try { financeTransaction(fn () => DB::statement('TRUNCATE TABLE '.SchemaQualifier::table('finance_cashier_collection_members'))); }
        catch (QueryException) { $denials++; }
        must($denials === 3, 'delete update truncate refused');
        $bypassDenied = false;
        try {
            DB::transaction(function () use ($batch): void {
                if (DB::connection()->getDriverName() === 'pgsql') DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
                else DB::statement('SET @simrs_synthetic_reset = 1');
                FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_cashier_collection_batches'))
                    ->where('id', $batch->id)->update(['batch_number' => 'BYPASS']));
            });
        } catch (QueryException) { $bypassDenied = true; }
        finally { if (DB::connection()->getDriverName() === 'mysql') DB::statement('SET @simrs_synthetic_reset = 0'); }
        must($bypassDenied, 'runtime reset flag cannot bypass installer identity');
        $runtimeResetDenied = false;
        try { app(SyntheticResetService::class)->reset(['reason' => 'runtime_identity_must_not_reset']); }
        catch (Throwable) { $runtimeResetDenied = true; }
        must($runtimeResetDenied, 'runtime identity bounded reset denied');
        return ['outcome' => 'LEAST_PRIVILEGE', 'delete_update_truncate_denials' => $denials,
            'session_bypass_escalation_denied' => true, 'runtime_reset_denied' => true];
    }

    function ownerReset(array $f): array
    {
        $auditBefore = DB::table(SchemaQualifier::table('audit_events'))->count();
        FinanceMutationScope::run(fn () => app(SyntheticResetService::class)->reset([
            'actor' => user($f['same_key']['cashier']), 'reason' => 'append_only_cashier_collection_portability',
        ]));
        foreach (['finance_cashier_collection_operation_receipts', 'finance_cash_deposit_handoffs',
            'finance_cashier_collection_events', 'finance_cashier_collection_members',
            'finance_cashier_collection_active_slots', 'finance_cashier_collection_batches'] as $table) {
            must(DB::table(SchemaQualifier::table($table))->count() === 0, 'owner reset '.$table);
        }
        $resets = DB::table(SchemaQualifier::table('audit_events'))
            ->whereIn('action', ['teaching.reset.started', 'teaching.reset.completed'])->count();
        must($resets === 2, 'paired owner reset audit');
        return ['outcome' => 'OWNER_BOUNDED_RESET', 'installer_owner_bypass_proven' => true,
            'audit_evidence_preserved' => true, 'reset_audit_events' => $resets,
            'audit_count_not_decreased' => DB::table(SchemaQualifier::table('audit_events'))->count() >= $auditBefore];
    }

    $action = (string) getenv('SIMRS_COLLECTION_ACTION');
    $f = fixture();
    protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
    try {
        $result = match ($action) {
            'prepare' => ['outcome' => 'APPLIED', 'fixture' => prepareFixture()],
            'schema_guards' => schemaGuards(),
            'constraint_probes' => constraintProbes($f),
            'failure_safety' => failureSafety(),
            'privilege_guards' => privilegeGuards($f),
            'settlement_hold' => settlementHold($f),
            'close_settlement' => closeBatch($f['settlement_race'], 'collection-portability-close-settlement-race', (int) $f['settlement_race']['counted']),
            'close_settlement_retry' => closeBatch($f['settlement_race'], 'collection-portability-close-settlement-retry', 18000),
            'refund_hold' => refundHold($f),
            'close_refund' => closeBatch($f['refund_race'], 'collection-portability-close-refund-race', 0),
            'ensure_refund_closed' => ensureRefundBatchClosed($f),
            'same_key_close' => sameKeyClose($f),
            'terminal' => terminalLifecycle($f),
            'readback' => readback($f),
            'recovery_tamper' => recoveryTamper(),
            'owner_reset' => ownerReset($f),
            default => throw new RuntimeException('unknown cashier collection rehearsal action'),
        };
        protocol('COMMITTED', $result);
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1, 'status' => 'BLOCKED', 'protocol_state' => 'FAILED',
            'scenario' => (string) getenv('SIMRS_COLLECTION_SCENARIO'),
            'worker' => (string) getenv('SIMRS_COLLECTION_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'diagnostic_message' => ($exception instanceof LogicException || $exception instanceof RuntimeException)
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
    raise CommandFailed, "Cashier collection rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Cashier collection scenario catalogue drifted.' unless SCENARIOS.length == 22 && SCENARIOS.uniq.length == 22
    raise CommandFailed, 'Embedded cashier collection worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 20_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION, 'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false', 'SATUSEHAT_ENABLED' => 'false',
      'LIS_INTEGRATION_ENABLED' => 'false', 'PACS_INTEGRATION_ENABLED' => 'false', 'COLUMNS' => '300'
    )
  end

  def current_collection_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    aggregate = Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h))
    {
      'files' => files, 'aggregate_sha256' => aggregate, 'application_source_sha256' => aggregate,
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
      'sqlite_gate_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS))
    }
  end

  def run_sqlite_gate!
    assert_contract!
    results = SQLITE_FEATURE_TESTS.map do |path, method|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "SQLite-bound method missing: #{path}##{method}." unless source.include?("function #{method}")
      stdout, stderr, status = Open3.capture3(
        @runner.process_environment(sqlite_application_environment), @php_binary, File.join(ROOT, 'artisan'),
        'test', path, "--filter=#{method}", unsetenv_others: true
      )
      raise CommandFailed, "SQLite gate failed: #{(stdout + stderr).lines.last(12).join}" unless status.success?
      { 'path' => path, 'method' => method, 'output_sha256' => Digest::SHA256.hexdigest(stdout + stderr) }
    end
    { 'status' => 'PASS', 'engine' => 'sqlite', 'test_count' => results.length,
      'catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS)),
      'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(results)), 'tests' => results }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil, startup_barrier: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT, 'SIMRS_COLLECTION_ACTION' => action,
      'SIMRS_COLLECTION_SCENARIO' => scenario, 'SIMRS_COLLECTION_WORKER' => worker,
      'SIMRS_COLLECTION_HOLD_MS' => hold_ms.to_s,
      'SIMRS_COLLECTION_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-cashier-collection-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Collection feature method missing: #{path}##{method}." unless source.include?("function #{method}")
      unless EXACT_FILTERED_FEATURE_SCENARIOS.include?(label)
        next [label, {
          'status' => 'PASS', 'proof_kind' => 'SQLITE_GATE_SOURCE_BINDING_WITH_DEDICATED_EXACT_WORKER',
          'path' => path, 'method' => method, 'scenario_binding' => label,
          'exact_worker_action' => EXACT_WORKER_ONLY_SCENARIOS.fetch(label)
        }]
      end
      cache_key = [path, method]
      proof = (@filtered_feature_evidence_cache ||= {})[cache_key] ||= begin
        recreate_application_schema_outside_guard!
        recorded_artisan!('test', path, "--filter=#{method}")
        { 'status' => 'PASS', 'proof_kind' => 'FILTERED_EXACT_ENGINE_FEATURE_TEST', 'path' => path, 'method' => method }
      end
      [label, proof.merge('scenario_binding' => label)]
    end
  end

  def start_collection_worker!(action:, scenario:, fixture:, worker:, hold_ms:, connection_environment: nil)
    @command_catalog << ['cashier-collection-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(action: action, scenario: scenario, worker: worker,
        fixture: fixture, hold_ms: hold_ms, connection_environment: connection_environment)),
      @php_binary, @worker_tempfile.path, unsetenv_others: true
    )
    stdin.close
    process = Worker.new(stdout: stdout, stderr: stderr, wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read }, scenario: scenario, label: worker)
    @workers << process
    process
  end

  def run_wait_race!(scenario:, hold_action:, contender_action:, fixture:, expected_hold:, expected_contender:)
    first = start_collection_worker!(action: hold_action, scenario: scenario, fixture: fixture,
      worker: 'A', hold_ms: HOLD_MS, connection_environment: @runtime_application_environment)
    first_started = await_protocol!(first, 'STARTED')
    holding = await_protocol!(first, 'HOLDING')
    expected_trace = ['finance_cashier_collection_active_slots', 'finance_cashier_collection_batches']
    raise CommandFailed, "#{scenario} lock trace drifted." unless holding.fetch('lock_order_trace') == expected_trace
    second = start_collection_worker!(action: contender_action, scenario: scenario, fixture: fixture,
      worker: 'B', hold_ms: 0, connection_environment: @runtime_application_environment)
    second_started = await_protocol!(second, 'STARTED')
    unless observe_real_database_wait!(Integer(second_started.fetch('backend_connection_id')))
      raise CommandFailed, "#{scenario} did not expose a real native database wait."
    end
    finals = [await_final!(first), await_final!(second)]
    outcomes = finals.map { |row| row.fetch('outcome') }
    raise CommandFailed, "#{scenario} holder outcome drifted." unless outcomes.include?(expected_hold)
    raise CommandFailed, "#{scenario} contender outcome drifted: #{outcomes.join(',')}." unless outcomes.any? { |value| expected_contender.include?(value) }
    { 'status' => 'PASS', 'proof_kind' => 'OBSERVED_DATABASE_WAIT', 'outcomes' => outcomes.sort,
      'real_database_wait_observed' => true, 'independent_application_processes' => 2,
      'first_backend_connection_id_sha256' => Digest::SHA256.hexdigest(first_started.fetch('backend_connection_id').to_s),
      'second_backend_connection_id_sha256' => Digest::SHA256.hexdigest(second_started.fetch('backend_connection_id').to_s),
      'observed_lock_order' => expected_trace, 'deadlock_observed' => false }
  ensure
    terminate_workers!
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL collection grant table missing: #{missing.join(',')}." unless missing.empty?
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    sequences.each do |sequence|
      statements << %(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}") if RUNTIME_INSERT_TABLES.any? { |table| sequence == "#{table}_id_seq" }
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL collection grant drifted for #{table}." unless actual == expected.split(', ').sort.join(',')
    end
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    password = SecureRandom.hex(24)
    tables = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "MySQL collection grant table missing: #{missing.join(',')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      sql = "SELECT GROUP_CONCAT(PRIVILEGE_TYPE ORDER BY PRIVILEGE_TYPE SEPARATOR ',') FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),'#{runtime}',CHAR(39),'@',CHAR(39),'127.0.0.1',CHAR(39)) AND TABLE_SCHEMA='#{@mysql_database}' AND TABLE_NAME='#{table}';"
      actual = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: sql).strip
      raise CommandFailed, "MySQL collection grant drifted for #{table}." unless actual == expected.split(', ').sort.join(',')
    end
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'MySQL collection runtime received schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => password)
  end

  def expect_collection_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained collection evidence unexpectedly allowed rollback.' if status.success?
    combined = (stdout + stderr).gsub(/\s+/, '').downcase
    expected = %w[refusingtodiscardpopulatedcashiercollectionevidence refusingtodiscardcashiercollectiontableswhilecorrelatedauditevidenceremains refusingtodiscardthedurablecashier-collectionactivationdiscriminator]
    raise CommandFailed, 'Collection rollback failed for an unexpected reason.' unless expected.any? { |fragment| combined.include?(fragment) }
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_collection_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:, sqlite_gate:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-cashier-collection-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_APPEND_ONLY_CASHIER_COLLECTION_ONLY',
      'hosted_readiness_claim' => false, 'deployment_claim' => false,
      'owner_acceptance_claim' => false, 'g0_claim' => false, 'g3_claim' => false,
      'source_bindings' => bindings.merge(
        'command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),
        'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))
      ),
      'cross_engine_comparison' => {
        'required_engine_inventory' => ENGINES, 'scenario_catalog_sha256' => bindings.fetch('scenario_catalog_sha256'),
        'application_source_sha256' => bindings.fetch('application_source_sha256'),
        'identical_source_bindings_required' => true
      },
      'command_catalog' => @command_catalog, 'protocol_result_catalog' => @protocol_catalog,
      'boundary' => { 'application_mode' => 'SIMULATION', 'synthetic_only' => true,
        'live_integrations_enabled' => false, 'disposable_local_engine' => true,
        'append_only_cashier_collection_only' => true, 'treasury_acceptance_claim' => false,
        'external_database_configuration_accepted' => false },
      'engine' => engine_binding, 'sqlite_gate' => sqlite_gate,
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => { 'database_removed' => true, 'temporary_server_removed' => true,
        'temporary_user_state_removed' => true, 'temporary_worker_removed' => true,
        'strict_cleanup_verified' => true },
      'open_boundaries' => [
        'G0 and G3 remain OPEN; this is not owner acceptance, UAT, hosted migration, deployment, or production readiness.',
        'No treasury receipt, bank reconciliation, journal posting, partial payment/refund, live integration, or real patient/payment data is exercised.'
      ]
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end

  def run!
    assert_contract!
    bindings = current_collection_bindings
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
    failure = run_worker_command!(action: 'failure_safety', scenario: 'failed-install-preserves-lifetime-uniqueness', worker: 'MIGRATION_FAILURE', connection_environment: application_environment)
    schema = run_worker_command!(action: 'schema_guards', scenario: 'shortened-identifier-inventory', worker: 'SCHEMA', connection_environment: application_environment)
    feature = bind_feature_evidence!(FEATURE_SCENARIO_TESTS)
    recreate_application_schema_outside_guard!
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    provision_runtime_identities!
    constraints = run_worker_command!(action: 'constraint_probes', scenario: 'database-check-constraints',
      worker: 'NATIVE_CHECKS', fixture: fixture)
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'exact-runtime-grants', worker: 'RUNTIME', fixture: fixture)
    settlement_race = run_wait_race!(scenario: 'real-settlement-v-close-wait', hold_action: 'settlement_hold',
      contender_action: 'close_settlement', fixture: fixture, expected_hold: 'SETTLEMENT_COMMITTED',
      expected_contender: %w[STALE_COLLECTION_BATCH_REFUSED BATCH_NOT_OPEN_REFUSED])
    settlement_retry = run_worker_command!(action: 'close_settlement_retry', scenario: 'real-settlement-v-close-wait', worker: 'RETRY', fixture: fixture)
    refund_race = run_wait_race!(scenario: 'real-refund-completion-v-close-wait', hold_action: 'refund_hold',
      contender_action: 'close_refund', fixture: fixture, expected_hold: 'REFUND_COMPLETED',
      expected_contender: %w[CLOSED STALE_COLLECTION_BATCH_REFUSED BATCH_NOT_OPEN_REFUSED])
    refund_final = run_worker_command!(action: 'ensure_refund_closed',
      scenario: 'real-refund-completion-v-close-wait', worker: 'FINAL_STATE', fixture: fixture)
    same_key = run_wait_race!(scenario: 'concurrent-same-key-replay', hold_action: 'same_key_close',
      contender_action: 'same_key_close', fixture: fixture, expected_hold: 'CLOSED', expected_contender: %w[REPLAYED])
    terminal = run_worker_command!(action: 'terminal', scenario: 'double-handoff-refusal', worker: 'TERMINAL', fixture: fixture)
    readback = run_worker_command!(action: 'readback', scenario: 'third-connection-membership-net-cash-readback', worker: 'THIRD_CONNECTION', fixture: fixture, connection_environment: application_environment)
    recovery = run_worker_command!(action: 'recovery_tamper', scenario: 'recovery-tamper-detection', worker: 'RECOVERY', fixture: fixture, connection_environment: application_environment)
    rollback = expect_collection_rollback_refusal!
    reset = run_worker_command!(action: 'owner_reset', scenario: 'owner-only-bounded-reset', worker: 'INSTALLER_OWNER_RESET', fixture: fixture, connection_environment: application_environment)
    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'EMPTY_MIGRATION_DOWN_REAPPLY' },
      'migration-failure-guard-reinstall' => { 'status' => 'PASS', 'proof_kind' => 'FINALLY_GUARD_REINSTALL_AFTER_FORCED_FAILURE', 'worker' => failure },
      'failed-install-preserves-lifetime-uniqueness' => { 'status' => 'PASS', 'proof_kind' => 'FORCED_FAILURE_WITH_BOTH_LIFETIME_UNIQUES', 'worker' => failure },
      'shortened-identifier-inventory' => { 'status' => 'PASS', 'proof_kind' => 'EXACT_IDENTIFIER_CATALOG', 'worker' => schema },
      'exact-runtime-grants' => { 'status' => 'PASS', 'proof_kind' => 'READ_BACK_LEAST_PRIVILEGE_RUNTIME', 'worker' => privilege },
      'exact-role-denials' => feature.fetch('exact-role-denials'),
      'runtime-reset-bypass-denial' => { 'status' => 'PASS', 'proof_kind' => 'RUNTIME_FLAG_AND_RESET_DENIED', 'worker' => privilege },
      'owner-only-bounded-reset' => feature.fetch('owner-only-bounded-reset').merge('exact_worker' => reset),
      'database-check-constraints' => feature.fetch('database-check-constraints').merge(
        'catalog_worker' => schema, 'exact_worker' => constraints),
      'database-append-only-refusals' => feature.fetch('database-append-only-refusals').merge('exact_worker' => privilege),
      'real-settlement-v-close-wait' => settlement_race.merge('retry' => settlement_retry),
      'real-refund-completion-v-close-wait' => refund_race.merge('final_state' => refund_final),
      'concurrent-same-key-replay' => same_key,
      'double-close-refusal' => feature.fetch('double-close-refusal').merge('exact_worker' => terminal),
      'double-verify-refusal' => { 'status' => 'PASS', 'proof_kind' => 'EXACT_TERMINAL_REFUSAL', 'worker' => terminal },
      'double-handoff-refusal' => { 'status' => 'PASS', 'proof_kind' => 'EXACT_TERMINAL_REFUSAL', 'worker' => terminal },
      'third-connection-membership-net-cash-readback' => { 'status' => 'PASS', 'proof_kind' => 'INDEPENDENT_THIRD_CONNECTION_READBACK', 'worker' => readback },
      'audit-failure-atomic-rollback' => feature.fetch('audit-failure-atomic-rollback'),
      'recovery-tamper-detection' => feature.fetch('recovery-tamper-detection').merge('exact_worker' => recovery),
      'retained-evidence-rollback-refusal' => { 'status' => rollback, 'proof_kind' => 'POPULATED_MIGRATION_DOWN_REFUSED' },
      'strict-cleanup' => { 'status' => 'PASS', 'proof_kind' => 'ENGINE_OWNED_STRICT_CLEANUP_PENDING_BINDING_RECHECK' }
    }
    unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
      raise CommandFailed, 'Cashier collection scenario evidence is incomplete.'
    end
    assert_unchanged_binding!('Cashier collection execution bindings', bindings, current_collection_bindings)
    cleanup!(strict: true)
    evidence_path = write_collection_evidence!(bindings: bindings, engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms, scenarios: scenarios, sqlite_gate: sqlite_gate)
    { 'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_APPEND_ONLY_CASHIER_COLLECTION_ONLY',
      'engine' => @engine, 'scenario_count' => scenarios.length,
      'scenario_catalog_sha256' => bindings.fetch('scenario_catalog_sha256'),
      'application_source_sha256' => bindings.fetch('application_source_sha256'),
      'sqlite_gate_status' => sqlite_gate.fetch('status'), 'evidence_path' => evidence_path,
      'evidence_sha256' => Digest::SHA256.file(evidence_path).hexdigest }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalAppendOnlyCashierCollectionPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalAppendOnlyCashierCollectionPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalAppendOnlyCashierCollectionPortabilityRehearsal::CommandFailed => e
    warn "cashier collection portability rehearsal failed: #{e.message}"
    exit 1
  end
end
