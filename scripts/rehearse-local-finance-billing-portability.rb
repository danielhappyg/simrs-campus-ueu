#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-pharmacy-stock-portability'

# Closed, disposable PostgreSQL 17/MySQL 8.4 rehearsal contract for the
# synthetic cross-setting, pharmacy-valued, versioned encounter-bill spine.
class LocalFinanceBillingPortabilityRehearsal < LocalPharmacyStockPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_FINANCE_BILLING_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_FINANCE_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-finance-billing-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalFinanceBillingPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_01_000800_create_cross_setting_financial_spine_tables.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_CROSS_SETTING_FINANCE_BILLING_EVIDENCE_TEMPLATE_2026-09-01.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_CROSS_SETTING_FINANCE_BILLING_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    exact-cashier-role-boundary
    rj-igd-ri-billing
    initial-candidate-discoverability
    pharmacy-charge-and-reversal
    initial-sync-issue-version-one
    later-source-sync-issue-version-two
    immutable-version-history
    exact-idempotent-replay
    changed-payload-key-conflict
    stale-fingerprint-refusal
    application-sql-guard-refusal
    database-mutable-head-refusal
    database-append-only-update-delete-truncate-refusal
    evidence-chain-corruption-refusal
    least-privilege-runtime
    bounded-reset-recovery-audit-preservation
    retained-evidence-rollback-refusal
    invariant-verification
  ].freeze

  FINANCE_COUNT_KEYS = %w[
    finance_charge_events finance_bills finance_bill_versions finance_bill_lines finance_operation_receipts
  ].freeze
  FINANCE_ORPHAN_KEYS = %w[
    finance_charge_without_pharmacy_source finance_charge_without_encounter finance_charge_without_patient
    finance_charge_without_importer finance_bill_without_encounter finance_bill_without_patient
    finance_version_without_bill finance_version_without_previous_version finance_version_without_issuer
    finance_version_without_encounter finance_version_without_patient finance_line_without_version
    finance_line_without_charge finance_receipt_without_actor
  ].freeze
  FINANCE_INTEGRITY_KEYS = %w[
    finance_retained_source_mismatches finance_line_source_mismatches finance_version_control_mismatches
    finance_previous_version_chain_mismatches finance_bill_head_mismatches finance_receipt_result_mismatches
  ].freeze
  FINANCE_DIGEST_KEYS = FINANCE_COUNT_KEYS.map { |key| "#{key}_sha256" }.freeze

  RUNTIME_READ_TABLES = %w[
    users roles permissions role_user permission_role patients
    pharmacy_prescriptions pharmacy_prescription_items pharmacy_handovers pharmacy_handover_items
    pharmacy_returns pharmacy_return_items pharmacy_financial_source_events
  ].freeze
  RUNTIME_LOCK_TABLES = %w[encounters].freeze
  MUTABLE_HEAD_TABLES = %w[finance_bills].freeze
  IMMUTABLE_EVIDENCE_TABLES = %w[
    finance_charge_events finance_bill_versions finance_bill_lines finance_operation_receipts audit_events
  ].freeze
  RUNTIME_TABLE_GRANTS = (
    RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }
      .merge(RUNTIME_LOCK_TABLES.to_h { |table| [table, 'SELECT, UPDATE'] })
      .merge(MUTABLE_HEAD_TABLES.to_h { |table| [table, 'SELECT, INSERT, UPDATE'] })
      .merge(IMMUTABLE_EVIDENCE_TABLES.to_h { |table| [table, 'SELECT, INSERT'] })
  ).freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-finance-billing-portability.rb
    scripts/rehearse-local-pharmacy-stock-portability.rb
    scripts/rehearse-local-laboratory-portability.rb
    scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalFinanceBillingPortabilityHarnessContractTest.rb
    tests/Documentation/CrossSettingVersionedEncounterBillV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md
    docs/operations/T1_LOCAL_CROSS_SETTING_FINANCE_BILLING_EVIDENCE_TEMPLATE_2026-09-01.md
    database/migrations/2026_09_01_000800_create_cross_setting_financial_spine_tables.php
    tests/Feature/Finance/FinanceBillCoreTest.php
    tests/Feature/Finance/BillingHttpWorkflowTest.php
    tests/Feature/Operations/FinanceRecoverySnapshotTest.php
    app/Models/FinanceBill.php
    app/Models/FinanceBillLine.php
    app/Models/FinanceBillVersion.php
    app/Models/FinanceChargeEvent.php
    app/Models/FinanceOperationReceipt.php
    app/Support/Finance/FinanceActorPolicy.php
    app/Support/Finance/FinanceAppendOnlyGuard.php
    app/Support/Finance/FinanceBillService.php
    app/Support/Finance/FinanceCanonicalJson.php
    app/Support/Finance/FinanceEvidenceFingerprint.php
    app/Support/Finance/FinanceMutableHeadGuard.php
    app/Support/Finance/FinanceMutationScope.php
    app/Support/Finance/FinancePharmacySourceAdapter.php
    app/Support/Finance/FinanceProjection.php
    app/Support/Finance/FinanceSchemaMutationScope.php
    app/Support/Finance/FinanceSqlWriteGuard.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Operations/SyntheticRecoverySnapshot.php
    app/Support/Audit/AuditEventSchemaRegistry.php
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'rj-igd-ri-billing' => ['tests/Feature/Finance/FinanceBillCoreTest.php', 'test_rj_igd_and_ri_issue_exact_immutable_bill_versions_and_replay'],
    'initial-candidate-discoverability' => ['tests/Feature/Finance/FinanceBillCoreTest.php', 'test_worklist_exposes_read_only_first_synchronization_candidate'],
    'pharmacy-charge-and-reversal' => ['tests/Feature/Finance/FinanceBillCoreTest.php', 'test_later_return_is_discoverable_then_creates_new_version_without_rewriting_history'],
    'exact-cashier-role-boundary' => ['tests/Feature/Finance/FinanceBillCoreTest.php', 'test_exact_role_cancelled_encounter_stale_and_idempotency_conflicts_fail_closed'],
    'application-sql-guard-refusal' => ['tests/Feature/Finance/FinanceBillCoreTest.php', 'test_source_drift_and_unguarded_finance_writes_are_refused'],
    'bounded-reset-recovery-audit-preservation' => ['tests/Feature/Operations/FinanceRecoverySnapshotTest.php', 'test_integrity_helpers_reconcile_a_real_issued_finance_version'],
  }.freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\FinanceBill;
    use App\Models\FinanceBillLine;
    use App\Models\FinanceBillVersion;
    use App\Models\FinanceChargeEvent;
    use App\Models\FinanceOperationReceipt;
    use App\Models\Patient;
    use App\Models\PharmacyDepot;
    use App\Models\PharmacyFinancialSourceEvent;
    use App\Models\PharmacyHandover;
    use App\Models\PharmacyHandoverItem;
    use App\Models\PharmacyMedicine;
    use App\Models\PharmacyPreparation;
    use App\Models\PharmacyPrescription;
    use App\Models\PharmacyPrescriptionItem;
    use App\Models\PharmacyReturn;
    use App\Models\PharmacyReturnItem;
    use App\Models\PharmacyStockLot;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Finance\FinanceActorPolicy;
    use App\Support\Finance\FinanceAppendOnlyGuard;
    use App\Support\Finance\FinanceBillService;
    use App\Support\Finance\FinanceDenied;
    use App\Support\Finance\FinanceMutationScope;
    use App\Support\Finance\FinanceProjection;
    use App\Support\Finance\FinanceSqlWriteGuard;
    use App\Support\Inpatient\InpatientLocationMutationScope;
    use App\Support\Pharmacy\PharmacyAppendOnlyGuard;
    use App\Support\Pharmacy\PharmacyCanonicalJson;
    use App\Support\Pharmacy\PharmacyMutationScope;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Auth\Access\AuthorizationException;
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
            'schema_version' => 1,
            'status' => 'PASS',
            'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_FINANCE_SCENARIO'),
            'worker' => (string) getenv('SIMRS_FINANCE_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_FINANCE_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function userByPublicId(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->firstOrFail();
    }

    function actor(string $token, string $name, string $role): User
    {
        $actor = User::query()->create([
            'name' => 'Finance portability '.$name,
            'email' => 'finance.'.$name.'.'.$token.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
        must($actor->fresh()->roleSlugs() === [$role], 'exact role '.$role);
        must(! $actor->is_system_administrator, 'non-system actor '.$role);
        return $actor->fresh();
    }

    function createSourceFixture(string $token, User $cashier, User $pharmacist, string $careSetting, int $sequence): array
    {
        $suffix = strtoupper(substr($careSetting, 0, 3)).$sequence;
        $patient = Patient::query()->create([
            'medical_record_number' => 'FPR'.strtoupper($token).str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'full_name' => 'Synthetic finance patient '.$suffix,
            'date_of_birth' => '1990-01-01',
            'sex' => Patient::SEX_PEREMPUAN,
            'is_synthetic' => true,
            'created_by_user_id' => $cashier->id,
        ]);
        $encounter = InpatientLocationMutationScope::run(fn () => Encounter::query()->create([
            'patient_id' => $patient->id,
            'care_setting' => $careSetting,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'Ruang Anggrek' : 'Unit '.$suffix,
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => now()->toDateString(),
            'queue_number' => $sequence,
            'registered_at' => now(),
            'registered_by_user_id' => $cashier->id,
            'ward_name' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'Ruang Anggrek' : null,
            'ward_class' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'KELAS_2' : null,
            'bed_code' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? 'A-0'.$sequence : null,
            'continue_from' => $careSetting === Encounter::CARE_SETTING_INPATIENT ? Encounter::CONTINUE_LANGSUNG : null,
        ]));
        $digest = str_repeat('a', 64);
        $unitAmount = 1000 + ($sequence * 250);
        $quantity = 2;

        return PharmacyMutationScope::run(function () use ($patient, $encounter, $pharmacist, $careSetting, $suffix, $digest, $unitAmount, $quantity): array {
            $medicine = PharmacyMedicine::query()->create([
                'medicine_code' => 'MED-'.$suffix, 'generic_name' => 'Parasetamol '.$suffix,
                'strength_text' => '500 mg', 'dosage_form' => 'TABLET', 'base_unit' => 'TABLET',
                'route_choices' => ['ORAL'], 'acquisition_value' => 500, 'teaching_sale_value' => $unitAmount,
                'state' => PharmacyMedicine::ACTIVE, 'version' => 1, 'current_content_digest' => $digest,
            ]);
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEP-'.$suffix, 'display_name' => 'Depo '.$suffix,
                'eligible_care_settings' => [$careSetting], 'state' => PharmacyDepot::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $lot = PharmacyStockLot::query()->create([
                'medicine_id' => $medicine->id, 'depot_id' => $depot->id,
                'opened_by_user_id' => $pharmacist->id, 'medicine_version' => 1, 'depot_version' => 1,
                'medicine_code_snapshot' => $medicine->medicine_code, 'depot_code_snapshot' => $depot->depot_code,
                'lot_code' => 'LOT-'.$suffix, 'received_at' => now(), 'expiry_date' => now()->addYear()->toDateString(),
                'available_quantity' => 100, 'quarantined_quantity' => 0, 'acquisition_value' => 500,
                'source_reference' => 'PORTABILITY-'.$suffix, 'state' => PharmacyStockLot::ACTIVE,
                'version' => 1, 'content_digest' => $digest,
            ]);
            $prescription = PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id, 'patient_id' => $patient->id,
                'ordering_physician_user_id' => $pharmacist->id, 'depot_id' => $depot->id,
                'care_setting' => $careSetting, 'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => 'Lokasi '.$suffix, 'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code, 'status' => PharmacyPrescription::HANDED_OVER,
                'version' => 1, 'current_content_digest' => $digest, 'ordered_at' => now(),
            ]);
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $prescription->id, 'medicine_id' => $medicine->id, 'line_number' => 1,
                'medicine_version' => 1, 'medicine_version_public_id' => $medicine->public_id,
                'medicine_content_digest' => $digest, 'medicine_code' => $medicine->medicine_code,
                'medicine_name' => $medicine->generic_name, 'strength_text' => '500 mg', 'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET', 'dose_text' => '1 tablet', 'route' => 'ORAL',
                'frequency_text' => '3 kali sehari', 'duration_text' => '1 hari',
                'requested_quantity' => $quantity, 'verified_quantity' => $quantity,
                'sale_value_snapshot' => $unitAmount, 'instruction' => 'Sesudah makan',
                'content_digest' => $digest, 'created_at' => now(),
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
                'handed_over_at' => now()->addSecond(), 'created_at' => now()->addSecond(),
            ]);
            $handoverItem = PharmacyHandoverItem::query()->create([
                'handover_id' => $handover->id, 'prescription_item_id' => $item->id, 'stock_lot_id' => $lot->id,
                'quantity' => $quantity, 'sale_value_snapshot' => $unitAmount,
                'content_digest' => $digest, 'created_at' => now()->addSecond(),
            ]);
            $source = PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id,
                'actor_user_id' => $pharmacist->id, 'event_type' => PharmacyFinancialSourceEvent::CHARGE,
                'quantity' => $quantity, 'amount' => $quantity * $unitAmount,
                'source_type' => 'HANDOVER_ITEM', 'source_public_id' => $handoverItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $prescription->public_id, $item->public_id, $pharmacist->id,
                    PharmacyFinancialSourceEvent::CHARGE, $quantity, $quantity * $unitAmount,
                    'HANDOVER_ITEM', $handoverItem->public_id,
                ]),
                'occurred_at' => now()->addSecond(), 'created_at' => now()->addSecond(),
            ]);

            return [
                'encounter' => $encounter->public_id, 'prescription' => $prescription->public_id,
                'item' => $item->public_id, 'handover' => $handover->public_id,
                'handover_item' => $handoverItem->public_id, 'source' => $source->public_id,
                'unit_amount' => $unitAmount, 'care_setting' => $careSetting,
            ];
        });
    }

    function appendReversal(array $row, User $pharmacist): PharmacyFinancialSourceEvent
    {
        return PharmacyMutationScope::run(function () use ($row, $pharmacist): PharmacyFinancialSourceEvent {
            $prescription = PharmacyPrescription::query()->where('public_id', $row['prescription'])->sole();
            $item = PharmacyPrescriptionItem::query()->where('public_id', $row['item'])->sole();
            $handover = PharmacyHandover::query()->where('public_id', $row['handover'])->sole();
            $handoverItem = PharmacyHandoverItem::query()->where('public_id', $row['handover_item'])->sole();
            $return = PharmacyReturn::query()->create([
                'handover_id' => $handover->id, 'pharmacist_user_id' => $pharmacist->id,
                'reason_code' => 'PATIENT_RETURN', 'handover_fingerprint' => str_repeat('c', 64),
                'content_digest' => str_repeat('c', 64), 'returned_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
            $returnItem = PharmacyReturnItem::query()->create([
                'return_id' => $return->id, 'handover_item_id' => $handoverItem->id,
                'condition' => PharmacyReturnItem::RETURN_TO_STOCK, 'quantity' => 1,
                'content_digest' => str_repeat('d', 64), 'created_at' => now()->addMinute(),
            ]);
            return PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id,
                'actor_user_id' => $pharmacist->id, 'event_type' => PharmacyFinancialSourceEvent::REVERSAL,
                'quantity' => 1, 'amount' => -((int) $row['unit_amount']),
                'source_type' => 'RETURN_ITEM', 'source_public_id' => $returnItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $prescription->public_id, $item->public_id, $pharmacist->id,
                    PharmacyFinancialSourceEvent::REVERSAL, 1, -((int) $row['unit_amount']),
                    'RETURN_ITEM', $returnItem->public_id,
                ]),
                'occurred_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
        });
    }

    function prepareFixture(string $token): array
    {
        $cashier = actor($token, 'cashier', RoleCapabilityMatrix::ROLE_CASHIER);
        $nurse = actor($token, 'nurse', RoleCapabilityMatrix::ROLE_NURSE);
        $pharmacist = actor($token, 'pharmacist', RoleCapabilityMatrix::ROLE_PHARMACIST);
        $rows = [];
        foreach (Encounter::CARE_SETTINGS as $index => $careSetting) {
            $rows[] = createSourceFixture($token, $cashier, $pharmacist, $careSetting, $index + 1);
        }
        return [
            'cashier' => $cashier->public_id,
            'nurse' => $nurse->public_id,
            'pharmacist' => $pharmacist->public_id,
            'rows' => $rows,
        ];
    }

    function expectFinanceDenied(string $reason, callable $callback): void
    {
        try {
            $callback();
            throw new RuntimeException('expected finance denial '.$reason);
        } catch (FinanceDenied $exception) {
            must($exception->reason === $reason, 'finance denial '.$reason);
        }
    }

    function exerciseInitialBilling(array $fixture): array
    {
        $cashier = userByPublicId($fixture['cashier']);
        $nurse = userByPublicId($fixture['nurse']);
        $service = app(FinanceBillService::class);
        $projection = app(FinanceProjection::class);

        $candidateBefore = $projection->worklist($cashier);
        must(count($candidateBefore['synchronization_candidates']) === 3, 'three read-only initial candidates');
        must(FinanceBill::query()->count() === 0, 'candidate read has no bill mutation');
        try {
            $projection->worklist($nurse);
            throw new RuntimeException('exact cashier view denial expected');
        } catch (AuthorizationException) {
            // expected
        }
        try {
            $service->synchronize($fixture['rows'][0]['encounter'], $nurse, 'finance-exact-role-denied');
            throw new RuntimeException('exact cashier mutation denial expected');
        } catch (AuthorizationException) {
            // expected
        }

        $bills = [];
        $versions = [];
        foreach ($fixture['rows'] as $index => $row) {
            $sync = $service->synchronize($row['encounter'], $cashier, 'finance-portability-sync-'.$index);
            must(! $sync->replayed && $sync->record->state === FinanceBill::OPEN_NO_VERSION, 'initial sync open no version');
            $fingerprint = $projection->fingerprint($sync->record);
            $issue = $service->issue(
                $sync->record->public_id,
                $cashier,
                $fingerprint,
                'Penerbitan awal tagihan obat untuk bukti portabilitas.',
                'finance-portability-issue-'.$index,
            );
            must(! $issue->replayed && $issue->record->version === 1, 'initial issue version one');
            must($issue->record->care_setting === $row['care_setting'], 'care setting retained');
            must($issue->record->net_amount === 2 * (int) $row['unit_amount'], 'initial charge total');
            $replay = $service->issue(
                $sync->record->public_id,
                $cashier,
                $fingerprint,
                'Penerbitan awal tagihan obat untuk bukti portabilitas.',
                'finance-portability-issue-'.$index,
            );
            must($replay->replayed && $replay->record->public_id === $issue->record->public_id, 'exact replay');
            $bills[] = $sync->record->public_id;
            $versions[] = $issue->record->public_id;
        }

        $firstBill = FinanceBill::query()->where('public_id', $bills[0])->sole();
        $versionOne = FinanceBillVersion::query()->where('public_id', $versions[0])->with('lines')->sole();

        return [
            'bill_count' => FinanceBill::query()->count(),
            'version_count' => FinanceBillVersion::query()->count(),
            'line_count' => FinanceBillLine::query()->count(),
            'charge_event_count' => FinanceChargeEvent::query()->count(),
            'receipt_count' => FinanceOperationReceipt::query()->count(),
            'bill' => $firstBill->public_id,
            'version_one' => $versionOne->public_id,
            'version_one_digest' => $versionOne->content_digest,
            'old_fingerprint' => $projection->fingerprint($firstBill),
            'corrupt_source' => $fixture['rows'][1]['source'],
        ];
    }

    function appendLaterReversal(array $fixture): array
    {
        $source = appendReversal($fixture['rows'][0], userByPublicId($fixture['pharmacist']));

        return ['source_public_id' => $source->public_id, 'signed_amount' => $source->amount];
    }

    function exerciseVersionTwo(array $fixture): array
    {
        $initial = $fixture['initial'];
        $cashier = userByPublicId($fixture['cashier']);
        $service = app(FinanceBillService::class);
        $projection = app(FinanceProjection::class);
        $firstBill = FinanceBill::query()->where('public_id', $initial['bill'])->sole();
        $versionOne = FinanceBillVersion::query()->where('public_id', $initial['version_one'])->with('lines')->sole();

        $summary = collect($projection->worklist($cashier)['bills'])->firstWhere('public_id', $firstBill->public_id);
        must($summary['pending_source_count'] === 1 && $summary['synchronization_available'] === true, 'later reversal discoverable');
        $synced = $service->synchronize($fixture['rows'][0]['encounter'], $cashier, 'finance-portability-sync-version-two')->record;
        must($synced->state === FinanceBill::NEW_SOURCE_PENDING, 'later source pending state');
        expectFinanceDenied('stale_bill', fn () => $service->issue(
            $synced->public_id,
            $cashier,
            $initial['old_fingerprint'],
            'Fingerprint lama harus ditolak.',
            'finance-portability-stale',
        ));
        $currentFingerprint = $projection->fingerprint($synced->fresh());
        $versionTwo = $service->issue(
            $synced->public_id,
            $cashier,
            $currentFingerprint,
            'Versi kedua setelah retur obat.',
            'finance-portability-issue-version-two',
        )->record;
        must($versionTwo->version === 2, 'version two issued');
        must($versionTwo->reversal_amount === -((int) $fixture['rows'][0]['unit_amount']), 'real reversal amount');
        must($versionTwo->net_amount === (int) $fixture['rows'][0]['unit_amount'], 'version two net');
        must($versionTwo->previous_version_id === $versionOne->id, 'previous version chain');
        must($versionOne->fresh()->content_digest === $initial['version_one_digest'], 'version one immutable history');
        expectFinanceDenied('idempotency_key_conflict', fn () => $service->issue(
            $synced->public_id,
            $cashier,
            $currentFingerprint,
            'Muatan berbeda memakai kunci yang sama.',
            'finance-portability-issue-version-two',
        ));

        return [
            'bill_count' => FinanceBill::query()->count(),
            'version_count' => FinanceBillVersion::query()->count(),
            'line_count' => FinanceBillLine::query()->count(),
            'charge_event_count' => FinanceChargeEvent::query()->count(),
            'receipt_count' => FinanceOperationReceipt::query()->count(),
            'bill' => $firstBill->public_id,
            'version_two' => $versionTwo->public_id,
        ];
    }

    function applicationGuards(): array
    {
        $guard = app(FinanceSqlWriteGuard::class);
        $guard->assertAllowed('SELECT * FROM finance_bills');
        foreach ([
            'UPDATE finance_bills SET state = \'ISSUED_CURRENT\'',
            'DELETE FROM finance_bill_versions',
            'TRUNCATE finance_operation_receipts',
        ] as $sql) {
            try {
                $guard->assertAllowed($sql);
                throw new RuntimeException('application finance SQL guard refusal expected');
            } catch (LogicException) {
                // expected
            }
        }
        return ['outcome' => 'APPLIED', 'read_allowed' => true, 'writes_refused' => 3];
    }

    function databaseGuards(): array
    {
        $bill = FinanceBill::query()->firstOrFail();
        $event = FinanceChargeEvent::query()->firstOrFail();
        $operations = [
            fn () => FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_bills'))->where('id', $bill->id)->update(['state' => FinanceBill::OPEN_NO_VERSION])),
            fn () => FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_charge_events'))->where('id', $event->id)->update(['description' => 'tampered'])),
            fn () => FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_charge_events'))->where('id', $event->id)->delete()),
        ];
        if (DB::connection()->getDriverName() === 'pgsql') {
            $operations[] = fn () => FinanceMutationScope::run(fn () => DB::statement('TRUNCATE TABLE '.SchemaQualifier::table('finance_operation_receipts')));
        }
        foreach ($operations as $operation) {
            try {
                $operation();
                throw new RuntimeException('database finance guard refusal expected');
            } catch (QueryException) {
                // expected
            }
        }
        return [
            'outcome' => 'APPLIED',
            'mutable_head_refused' => true,
            'append_only_update_delete_refused' => true,
            'postgres_append_only_truncate_refused' => DB::connection()->getDriverName() === 'pgsql',
            'mysql_append_only_truncate_boundary' => DB::connection()->getDriverName() === 'mysql'
                ? 'ENFORCED_BY_REDUCED_RUNTIME_PRIVILEGE' : 'NOT_APPLICABLE',
        ];
    }

    function corruptionRefusal(array $fixture): array
    {
        $source = PharmacyFinancialSourceEvent::query()->where('public_id', $fixture['corrupt_source'])->sole();
        $original = $source->content_digest;
        PharmacyMutationScope::run(fn () => DB::transaction(fn () => PharmacyAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table(SchemaQualifier::table('pharmacy_financial_source_events'))->where('id', $source->id)->update(['content_digest' => str_repeat('0', 64)]),
        )));
        $prescription = PharmacyPrescription::query()->whereKey($source->prescription_id)->sole();
        $encounter = Encounter::query()->whereKey($prescription->encounter_id)->sole();
        expectFinanceDenied('source_integrity_failure', fn () => app(FinanceBillService::class)->synchronize(
            $encounter->public_id,
            userByPublicId($fixture['cashier']),
            'finance-portability-corruption-refusal',
        ));
        PharmacyMutationScope::run(fn () => DB::transaction(fn () => PharmacyAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table(SchemaQualifier::table('pharmacy_financial_source_events'))->where('id', $source->id)->update(['content_digest' => $original]),
        )));
        return ['outcome' => 'APPLIED', 'corruption_refused' => true, 'source_restored' => true];
    }

    function privilegeGuards(): array
    {
        $driver = DB::connection()->getDriverName();
        $blocked = 0;
        foreach ([
            'UPDATE '.SchemaQualifier::table('finance_charge_events').' SET description=description',
            'DELETE FROM '.SchemaQualifier::table('finance_bill_versions'),
            'TRUNCATE TABLE '.SchemaQualifier::table('finance_operation_receipts'),
            'CREATE TABLE '.SchemaQualifier::table('finance_forbidden_probe').' (id INT)',
        ] as $sql) {
            try {
                FinanceMutationScope::run(fn () => DB::unprepared($sql));
            } catch (QueryException) {
                $blocked++;
            }
        }
        must($blocked === 4, 'least privilege runtime blocks immutable update delete truncate and ddl');
        return ['outcome' => 'APPLIED', 'driver' => $driver, 'forbidden_operations_refused' => $blocked];
    }

    function financeInvariants(): array
    {
        foreach (FinanceBillVersion::query()->with(['bill', 'lines.chargeEvent'])->orderBy('bill_id')->orderBy('version')->get() as $version) {
            must($version->lines->count() === $version->source_event_count, 'version line count');
            must((int) $version->lines->where('event_type', FinanceChargeEvent::CHARGE)->sum('signed_amount') === $version->gross_amount, 'version gross');
            must((int) $version->lines->where('event_type', FinanceChargeEvent::REVERSAL)->sum('signed_amount') === $version->reversal_amount, 'version reversal');
            must($version->gross_amount + $version->reversal_amount === $version->net_amount, 'version net');
            must($version->version === 1 ? $version->previous_version_id === null : $version->previous_version_id !== null, 'version chain');
        }
        foreach (FinanceBill::query()->with('versions')->get() as $bill) {
            must($bill->versions->max('version') === $bill->current_version, 'bill head latest version');
        }
        must(DB::table(SchemaQualifier::table('finance_charge_events').' as f')
            ->leftJoin(SchemaQualifier::table('pharmacy_financial_source_events').' as p', 'p.id', '=', 'f.pharmacy_financial_source_event_id')
            ->whereNull('p.id')->count() === 0, 'finance source orphans');
        must(DB::table(SchemaQualifier::table('finance_bill_lines').' as l')
            ->leftJoin(SchemaQualifier::table('finance_charge_events').' as f', 'f.id', '=', 'l.charge_event_id')
            ->whereNull('f.id')->count() === 0, 'finance line orphans');
        return [
            'outcome' => 'APPLIED',
            'bills' => FinanceBill::query()->count(),
            'versions' => FinanceBillVersion::query()->count(),
            'lines' => FinanceBillLine::query()->count(),
            'source_events' => FinanceChargeEvent::query()->count(),
            'receipts' => FinanceOperationReceipt::query()->count(),
            'orphans' => 0,
        ];
    }

    function resetAndVerify(array $fixture): array
    {
        $auditBefore = DB::table(SchemaQualifier::table('audit_events'))->count();
        app(SyntheticResetService::class)->reset([
            'actor' => userByPublicId($fixture['cashier']),
            'reason' => 'finance_exact_engine_portability',
        ]);
        $counts = [];
        foreach (['finance_charge_events', 'finance_bills', 'finance_bill_versions', 'finance_bill_lines', 'finance_operation_receipts'] as $table) {
            $counts[$table] = DB::table(SchemaQualifier::table($table))->count();
        }
        must(array_sum($counts) === 0, 'post-reset finance graph empty');
        must(DB::table(SchemaQualifier::table('patients'))->where('is_synthetic', true)->count() === 0, 'post-reset synthetic patients empty');
        $auditAfter = DB::table(SchemaQualifier::table('audit_events'))->count();
        must($auditAfter >= $auditBefore + 2, 'reset audit evidence preserved');
        return ['outcome' => 'APPLIED', 'counts' => $counts, 'audit_preserved' => true];
    }

    $action = (string) getenv('SIMRS_FINANCE_ACTION');
    $scenario = (string) getenv('SIMRS_FINANCE_SCENARIO');
    $token = (string) getenv('SIMRS_FINANCE_RUN_TOKEN');
    $fixture = fixture();
    protocol('STARTED');
    try {
        $result = match ($action) {
            'prepare' => ['outcome' => 'APPLIED', 'fixture' => prepareFixture($token)],
            'exercise_initial' => ['outcome' => 'APPLIED', 'result' => exerciseInitialBilling($fixture)],
            'append_reversal' => ['outcome' => 'APPLIED', 'result' => appendLaterReversal($fixture)],
            'exercise_version_two' => ['outcome' => 'APPLIED', 'result' => exerciseVersionTwo($fixture)],
            'application_guards' => applicationGuards(),
            'database_guards' => databaseGuards(),
            'corrupt' => corruptionRefusal($fixture),
            'privilege_guards' => privilegeGuards(),
            'verify' => ['outcome' => 'APPLIED', 'result' => financeInvariants()],
            'reset' => resetAndVerify($fixture),
            default => throw new RuntimeException('unknown finance action'),
        };
        protocol('COMMITTED', $action === 'prepare' ? $result : (['outcome' => $result['outcome'] ?? 'APPLIED'] + $result));
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1,
            'status' => 'BLOCKED',
            'protocol_state' => 'FAILED',
            'scenario' => $scenario,
            'worker' => (string) getenv('SIMRS_FINANCE_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'diagnostic_message' => ($exception instanceof LogicException || $exception instanceof RuntimeException)
                ? mb_substr($exception->getMessage(), 0, 300) : null,
            'failure_stage' => $action,
            'sql_state' => (string) ($errorInfo[0] ?? ''),
            'driver_code' => (string) ($errorInfo[1] ?? ''),
            'query_head' => $query ? mb_substr($query->getSql(), 0, 180) : null,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Finance rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Finance scenario catalogue drifted.' unless SCENARIOS.length == 20 && SCENARIOS.uniq.length == 20
    raise CommandFailed, 'Embedded finance worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 18_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false',
      'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false',
      'COLUMNS' => '300'
    )
  end

  def current_finance_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
    }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_FINANCE_ACTION' => action,
      'SIMRS_FINANCE_SCENARIO' => scenario,
      'SIMRS_FINANCE_WORKER' => worker,
      'SIMRS_FINANCE_RUN_TOKEN' => @run_token,
      'SIMRS_FINANCE_HOLD_MS' => hold_ms.to_s,
      'SIMRS_FINANCE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-finance-billing-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each { |table| statements << %(GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}") }
    insert_tables = RUNTIME_TABLE_GRANTS.select { |_table, grants| grants.include?('INSERT') }.keys
    sequences.each do |sequence|
      statements << %(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}") if insert_tables.any? { |table| sequence == "#{table}_id_seq" }
      statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL runtime grant drifted for #{table}." unless actual == expected.split(', ').sort.join(',')
    end
    unexpected = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT table_name FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name NOT IN (#{RUNTIME_TABLE_GRANTS.keys.map { |table| "'#{table}'" }.join(',')}) ORDER BY table_name"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    raise CommandFailed, "PostgreSQL runtime has grants outside the closed map: #{unexpected.join(', ')}." unless unexpected.empty?
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => '')
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated MySQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    runtime_password = SecureRandom.hex(24)
    reset_password = SecureRandom.hex(24)
    tables = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "MySQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each { |table| statements << "GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL runtime retained forbidden DDL grants.' unless %w[DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    RUNTIME_TABLE_GRANTS.each do |table, expected_grants|
      expected = "GRANT #{expected_grants} ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed, "MySQL runtime grant drifted for #{table}." unless grants.include?(expected)
    end
    raise CommandFailed, 'MySQL runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Scenario-bound finance feature method is missing: #{path}##{method}." unless source.include?("function #{method}")
      [label, {
        'status' => 'PASS',
        'proof_kind' => 'EXACT_ENGINE_FEATURE_SUITE',
        'path' => path,
        'method' => method,
        'scenario_binding' => label,
      }]
    end
  end

  # RefreshDatabase invokes migrate:fresh at the start of every new PHPUnit
  # process. Starting that process against an already-populated exact-engine
  # schema would correctly trip the application SQL guard during Laravel's
  # multi-table DROP. Recreate the disposable schema through the isolated
  # engine administrator, then run every finance feature file in one PHPUnit
  # process so the normal test migration owns the empty schema from the start.
  def recreate_application_schema_outside_guard!
    @command_catalog << ['engine-admin', 'recreate-application-schema']
    if @engine == 'postgresql17'
      @runner.run!(
        postgres_psql_arguments(@postgres_database) + [
          '--command', 'DROP SCHEMA IF EXISTS laravel CASCADE; CREATE SCHEMA laravel;',
        ],
        env: postgres_tool_environment
      )
    else
      @runner.run!(
        mysql_root_arguments,
        stdin_data: "DROP DATABASE IF EXISTS `#{@mysql_database}`;\n" \
          "CREATE DATABASE `#{@mysql_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
      )
    end
    @result_catalog << [['engine-admin', 'recreate-application-schema'], 'PASS']
  end

  def expect_finance_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained finance audit unexpectedly allowed rollback.' if status.success?
    combined = stdout + stderr
    raise CommandFailed, 'Finance rollback failed for an unexpected reason.' unless combined.gsub(/\s+/, '').include?('correlatedauditevidenceexists')
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_finance_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-finance-billing-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1,
      'kind' => EVIDENCE_KIND,
      'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_CROSS_SETTING_FINANCE_BILLING_ONLY',
      'hosted_readiness_claim' => false,
      'deployment_claim' => false,
      'owner_acceptance_claim' => false,
      'source_bindings' => bindings.merge(
        'command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),
        'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))
      ),
      'command_catalog' => @command_catalog,
      'protocol_result_catalog' => @protocol_catalog,
      'boundary' => {
        'application_mode' => 'SIMULATION', 'synthetic_only' => true,
        'live_integrations_enabled' => false, 'disposable_local_engine' => true,
        'external_database_configuration_accepted' => false,
      },
      'engine' => engine_binding,
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => {
        'database_removed' => true, 'temporary_server_removed' => true,
        'temporary_user_state_removed' => true, 'temporary_worker_removed' => true,
      },
      'open_boundaries' => [
        'Local disposable-engine evidence only; no deployment, hosted migration, payment, UAT, finance-owner acceptance, or production-readiness claim.',
        'Only retained pharmacy CHARGE and REVERSAL source facts are valued; no tariff, payment, accounting, claim, BPJS, VClaim, SATUSEHAT, mail, or production integration is exercised.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end

  def run!
    assert_contract!
    bindings = current_finance_bindings
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recreate_application_schema_outside_guard!
    recorded_artisan!(
      'test',
      'tests/Feature/Finance/FinanceBillCoreTest.php',
      'tests/Feature/Finance/BillingHttpWorkflowTest.php',
      'tests/Feature/Operations/FinanceRecoverySnapshotTest.php'
    )
    feature_evidence = bind_feature_evidence!(FEATURE_SCENARIO_TESTS)
    recreate_application_schema_outside_guard!
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    provision_runtime_identities!
    initial_exercise = run_worker_command!(action: 'exercise_initial', scenario: 'rj-igd-ri-billing', worker: 'EXERCISE_INITIAL', fixture: fixture)
    reversal = run_worker_command!(action: 'append_reversal', scenario: 'pharmacy-charge-and-reversal', worker: 'PHARMACY_REVERSAL', fixture: fixture, connection_environment: application_environment)
    version_two_fixture = fixture.merge('initial' => initial_exercise.fetch('result'))
    version_two_exercise = run_worker_command!(action: 'exercise_version_two', scenario: 'later-source-sync-issue-version-two', worker: 'EXERCISE_VERSION_TWO', fixture: version_two_fixture)
    application_guards = run_worker_command!(action: 'application_guards', scenario: 'application-sql-guard-refusal', worker: 'APP_GUARDS', fixture: fixture)
    database_guards = run_worker_command!(action: 'database_guards', scenario: 'database-append-only-update-delete-truncate-refusal', worker: 'DB_GUARDS', fixture: fixture, connection_environment: application_environment)
    corruption_fixture = fixture.merge('corrupt_source' => initial_exercise.fetch('result').fetch('corrupt_source'))
    corruption = run_worker_command!(action: 'corrupt', scenario: 'evidence-chain-corruption-refusal', worker: 'CORRUPTION', fixture: corruption_fixture, connection_environment: application_environment)
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'least-privilege-runtime', worker: 'PRIVILEGE', fixture: fixture)
    invariants = run_worker_command!(action: 'verify', scenario: 'invariant-verification', worker: 'VERIFY', fixture: fixture, connection_environment: application_environment)
    reset = run_worker_command!(action: 'reset', scenario: 'bounded-reset-recovery-audit-preservation', worker: 'RESET', fixture: fixture, connection_environment: @reset_application_environment)
    rollback = expect_finance_rollback_refusal!

    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'EMPTY_MIGRATION_ROLLBACK_REAPPLY' },
      'exact-cashier-role-boundary' => feature_evidence.fetch('exact-cashier-role-boundary'),
      'rj-igd-ri-billing' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER', 'result' => initial_exercise.fetch('result') },
      'initial-candidate-discoverability' => feature_evidence.fetch('initial-candidate-discoverability'),
      'pharmacy-charge-and-reversal' => { 'status' => 'PASS', 'proof_kind' => 'SEPARATE_PHARMACY_SOURCE_WORKER', 'result' => reversal.fetch('result'), 'feature' => feature_evidence.fetch('pharmacy-charge-and-reversal') },
      'initial-sync-issue-version-one' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER', 'result' => initial_exercise.fetch('result') },
      'later-source-sync-issue-version-two' => { 'status' => 'PASS', 'proof_kind' => 'REAL_SERVICE_WORKER', 'result' => version_two_exercise.fetch('result') },
      'immutable-version-history' => feature_evidence.fetch('pharmacy-charge-and-reversal'),
      'exact-idempotent-replay' => feature_evidence.fetch('rj-igd-ri-billing'),
      'changed-payload-key-conflict' => feature_evidence.fetch('exact-cashier-role-boundary'),
      'stale-fingerprint-refusal' => feature_evidence.fetch('exact-cashier-role-boundary'),
      'application-sql-guard-refusal' => { 'status' => 'PASS', 'proof_kind' => 'APPLICATION_GUARD_WORKER_AND_FILTERED_TEST', 'result' => application_guards, 'feature' => feature_evidence.fetch('application-sql-guard-refusal') },
      'database-mutable-head-refusal' => { 'status' => 'PASS', 'proof_kind' => 'DATABASE_TRIGGER_WORKER', 'result' => database_guards },
      'database-append-only-update-delete-truncate-refusal' => { 'status' => 'PASS', 'proof_kind' => 'DATABASE_TRIGGER_WORKER', 'result' => database_guards },
      'evidence-chain-corruption-refusal' => { 'status' => 'PASS', 'proof_kind' => 'CORRUPTION_PROBE_WORKER', 'result' => corruption },
      'least-privilege-runtime' => { 'status' => 'PASS', 'proof_kind' => 'REDUCED_RUNTIME_IDENTITY', 'result' => privilege },
      'bounded-reset-recovery-audit-preservation' => { 'status' => 'PASS', 'proof_kind' => 'RESET_WORKER_AND_FILTERED_RECOVERY_TEST', 'reset' => reset, 'feature' => feature_evidence.fetch('bounded-reset-recovery-audit-preservation') },
      'retained-evidence-rollback-refusal' => { 'status' => rollback, 'proof_kind' => 'EXPECTED_MIGRATION_REFUSAL' },
      'invariant-verification' => { 'status' => 'PASS', 'proof_kind' => 'DURABLE_DATABASE_READBACK', 'result' => invariants.fetch('result') },
    }
    raise CommandFailed, 'Finance scenario evidence is incomplete.' unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
    assert_unchanged_binding!('Finance execution bindings', bindings, current_finance_bindings)
    cleanup!(strict: true)
    evidence_path = write_finance_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios)
    {
      'status' => 'PASS',
      'claim' => 'LOCAL_DISPOSABLE_CROSS_SETTING_FINANCE_BILLING_ONLY',
      'engine' => @engine,
      'scenario_count' => scenarios.length,
      'evidence_path' => evidence_path,
    }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalFinanceBillingPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalFinanceBillingPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalFinanceBillingPortabilityRehearsal::CommandFailed => e
    warn "finance billing portability rehearsal failed: #{e.message}"
    exit 1
  end
end
