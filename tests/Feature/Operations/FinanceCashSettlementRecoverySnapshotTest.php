<?php

namespace Tests\Feature\Operations;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceCashSettlement;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
use App\Models\FinanceSettlementOperationReceipt;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceCanonicalJson;
use App\Support\Finance\FinanceCashSettlementCorrectionFingerprint;
use App\Support\Finance\FinanceCashSettlementCorrectionService;
use App\Support\Finance\FinanceCashSettlementFingerprint;
use App\Support\Finance\FinanceCashSettlementService;
use App\Support\Finance\FinanceEvidenceFingerprint;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Operations\SyntheticRecoverySnapshot;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class FinanceCashSettlementRecoverySnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_snapshot_registers_cash_settlement_counts_digests_orphans_and_integrity(): void
    {
        $source = file_get_contents(app_path('Support/Operations/SyntheticRecoverySnapshot.php'));
        $this->assertIsString($source);

        foreach ([
            'finance_cash_settlements',
            'finance_settlement_operation_receipts',
            'finance_settlement_correction_cases',
            'finance_settlement_correction_events',
            'finance_settlement_correction_operation_receipts',
        ] as $table) {
            $this->assertStringContainsString("'{$table}' =>", $source);
            $this->assertStringContainsString("'{$table}_sha256' =>", $source);
        }

        foreach ([
            'finance_cash_settlement_without_bill',
            'finance_cash_settlement_without_bill_version',
            'finance_cash_settlement_without_encounter',
            'finance_cash_settlement_without_patient',
            'finance_cash_settlement_without_cashier',
            'finance_settlement_receipt_without_actor',
            'finance_settlement_correction_case_without_settlement',
            'finance_settlement_correction_case_without_bill',
            'finance_settlement_correction_case_without_bill_version',
            'finance_settlement_correction_case_without_requester',
            'finance_settlement_correction_event_without_case',
            'finance_settlement_correction_event_without_actor',
            'finance_settlement_correction_receipt_without_actor',
            'finance_settlement_correction_receipt_without_case',
            'finance_settlement_correction_receipt_without_event',
            'finance_cash_settlement_mismatches',
            'finance_cash_settlement_net_equation_mismatches',
            'finance_settlement_receipt_mismatches',
            'finance_cash_settlement_audit_mismatches',
            'finance_settlement_correction_mismatches',
            'finance_settlement_correction_receipt_mismatches',
            'finance_settlement_correction_audit_mismatches',
        ] as $contract) {
            $this->assertStringContainsString("'{$contract}' =>", $source);
        }
        $this->assertStringContainsString("'prior_net_collected_amount_snapshot'", $source);
    }

    public function test_integrity_helpers_reconcile_exact_settlement_receipt_cashier_and_audit_evidence(): void
    {
        $this->fixture();

        $this->assertDatabaseCount('finance_cash_settlements', 1);
        $this->assertDatabaseCount('finance_settlement_operation_receipts', 1);
        $this->assertSame(0, $this->integrityCount('financeCashSettlementMismatchCount'));
        $this->assertSame(0, $this->integrityCount('financeSettlementReceiptMismatchCount'));
        $this->assertSame(0, $this->integrityCount('financeCashSettlementAuditMismatchCount'));
    }

    public function test_integrity_helpers_detect_settlement_and_replay_receipt_corruption(): void
    {
        $this->fixture();

        FinanceMutationScope::run(fn () => FinanceAppendOnlyGuard::runSyntheticReset(function (): void {
            DB::table('finance_cash_settlements')->update(['amount' => 1]);
            DB::table('finance_settlement_operation_receipts')->update(['result_digest' => str_repeat('f', 64)]);
        }));

        $this->assertGreaterThan(0, $this->integrityCount('financeCashSettlementMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeSettlementReceiptMismatchCount'));
    }

    public function test_integrity_helper_detects_missing_success_audit(): void
    {
        $this->fixture(recordAudit: false);

        $this->assertSame(0, $this->integrityCount('financeCashSettlementMismatchCount'));
        $this->assertSame(0, $this->integrityCount('financeSettlementReceiptMismatchCount'));
        $this->assertSame(1, $this->integrityCount('financeCashSettlementAuditMismatchCount'));
    }

    public function test_settlement_integrity_reconciles_cumulative_outstanding_across_bill_versions(): void
    {
        $fixture = $this->fixture();
        $second = $this->appendSecondSettlement($fixture);

        $this->assertSame(75000, $second->amount);
        $this->assertSame(0, $this->integrityCount('financeCashSettlementMismatchCount'));

        FinanceMutationScope::run(fn () => FinanceAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table('finance_cash_settlements')
                ->where('id', $second->id)
                ->update(['amount' => 200000]),
        ));

        $this->assertGreaterThan(0, $this->integrityCount('financeCashSettlementMismatchCount'));
    }

    public function test_integrity_helpers_reconcile_completed_correction_chain_receipts_audits_and_net_cash(): void
    {
        $fixture = $this->fixture();
        $this->completeCorrection($fixture);

        $this->assertDatabaseCount('finance_settlement_correction_cases', 1);
        $this->assertDatabaseCount('finance_settlement_correction_events', 2);
        $this->assertDatabaseCount('finance_settlement_correction_operation_receipts', 3);
        $this->assertSame(0, $this->integrityCount('financeCashSettlementMismatchCount'));
        $this->assertSame(0, $this->integrityCount('financeCashSettlementNetEquationMismatchCount'));
        $this->assertSame(0, $this->integrityCount('financeSettlementCorrectionMismatchCount'));
        $this->assertSame(0, $this->integrityCount('financeSettlementCorrectionReceiptMismatchCount'));
        $this->assertSame(0, $this->integrityCount('financeSettlementCorrectionAuditMismatchCount'));
    }

    public function test_integrity_helpers_detect_snapshot_case_and_correction_receipt_corruption(): void
    {
        $fixture = $this->fixture();
        $case = $this->completeCorrection($fixture);

        FinanceMutationScope::run(fn () => FinanceAppendOnlyGuard::runSyntheticReset(function () use ($case): void {
            DB::table('finance_cash_settlements')
                ->where('id', $case->settlement_id)
                ->update(['prior_net_collected_amount_snapshot' => 1]);
            DB::table('finance_settlement_correction_cases')
                ->where('id', $case->id)
                ->update(['content_digest' => str_repeat('e', 64)]);
            DB::table('finance_settlement_correction_operation_receipts')
                ->where('correction_case_id', $case->id)
                ->where('operation', FinanceCashSettlementCorrectionService::OPERATION_COMPLETE)
                ->update(['result_digest' => str_repeat('f', 64)]);
        }));

        $this->assertGreaterThan(0, $this->integrityCount('financeCashSettlementMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeCashSettlementNetEquationMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeSettlementCorrectionMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeSettlementCorrectionReceiptMismatchCount'));
    }

    public function test_synthetic_reset_removes_settlement_and_receipt_before_bill_parents_and_preserves_audit(): void
    {
        $fixture = $this->fixture();
        $case = $this->completeCorrection($fixture);
        $auditId = $fixture['audit']->id;
        $correctionAuditIds = AuditEvent::query()
            ->where('resource_id', $case->public_id)
            ->where('action', 'finance.workflow.mutate')
            ->where('outcome', 'SUCCESS')
            ->orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $correctionAuditIds);

        app(SyntheticResetService::class)->reset([
            'actor' => $fixture['cashier'],
            'reason' => 'finance_cash_settlement_reset_test',
        ]);

        foreach ([
            'finance_settlement_correction_operation_receipts',
            'finance_settlement_correction_events', 'finance_settlement_correction_cases',
            'finance_settlement_operation_receipts', 'finance_cash_settlements',
            'finance_bill_versions', 'finance_bills', 'encounters', 'patients',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must be empty after reset.');
        }
        $this->assertDatabaseHas('audit_events', ['id' => $auditId]);
        foreach ($correctionAuditIds as $correctionAuditId) {
            $this->assertDatabaseHas('audit_events', ['id' => $correctionAuditId]);
        }
        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.completed',
            'resource_type' => 'simulation',
            'reason' => 'finance_cash_settlement_reset_test',
        ]);
    }

    /**
     * @return array{cashier: User, audit: AuditEvent, bill: FinanceBill, version: FinanceBillVersion, settlement: FinanceCashSettlement, patient: Patient, encounter: Encounter}
     */
    private function fixture(bool $recordAudit = true): array
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Bukti Pemulihan Kasir',
        ]);
        $sourceDigest = FinanceCanonicalJson::digest(['synthetic-recovery-settlement-source']);
        $now = now();

        [$bill, $version, $settlement] = $this->financeTransaction(
            function () use ($cashier, $patient, $encounter, $sourceDigest, $now): array {
                $bill = FinanceBill::query()->create([
                    'bill_number' => 'TAG-RECOVERY-CASH-0001',
                    'encounter_id' => $encounter->id,
                    'patient_id' => $patient->id,
                    'care_setting' => $encounter->care_setting,
                    'state' => FinanceBill::ISSUED_CURRENT,
                    'current_version' => 1,
                    'current_source_event_count' => 1,
                    'current_source_set_digest' => $sourceDigest,
                    'current_issued_source_set_digest' => $sourceDigest,
                    'current_content_digest' => str_repeat('0', 64),
                ]);
                $bill->current_content_digest = app(FinanceEvidenceFingerprint::class)->billContent($bill);
                $bill->save();

                $version = new FinanceBillVersion([
                    'bill_id' => $bill->id,
                    'previous_version_id' => null,
                    'issued_by_user_id' => $cashier->id,
                    'encounter_id' => $encounter->id,
                    'patient_id' => $patient->id,
                    'version' => 1,
                    'encounter_public_id_snapshot' => $encounter->public_id,
                    'encounter_number_snapshot' => $encounter->public_id,
                    'patient_public_id_snapshot' => $patient->public_id,
                    'patient_name_snapshot' => $patient->full_name,
                    'medical_record_number_snapshot' => $patient->medical_record_number,
                    'care_setting' => $encounter->care_setting,
                    'payer_snapshot' => Encounter::PAYER_UMUM,
                    'service_location_snapshot' => $encounter->clinic_name,
                    'coverage_profile' => FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1,
                    'source_set_digest' => $sourceDigest,
                    'source_event_count' => 1,
                    'source_cutoff_at' => $now,
                    'gross_amount' => 125000,
                    'reversal_amount' => 0,
                    'net_amount' => 125000,
                    'issue_reason' => 'Bukti pemulihan pelunasan kas.',
                    'content_digest' => str_repeat('0', 64),
                    'issued_at' => $now,
                    'created_at' => $now,
                ]);
                $version->public_id = (string) Str::ulid();
                $version->content_digest = app(FinanceEvidenceFingerprint::class)->version($version, $version->lines);
                $version->save();

                $settlement = new FinanceCashSettlement([
                    'receipt_number' => 'KWT-RECOVERY-CASH-0001',
                    'bill_id' => $bill->id,
                    'bill_version_id' => $version->id,
                    'encounter_id' => $encounter->id,
                    'patient_id' => $patient->id,
                    'cashier_user_id' => $cashier->id,
                    'cashier_name_snapshot' => $cashier->name,
                    'bill_public_id_snapshot' => $bill->public_id,
                    'bill_number_snapshot' => $bill->bill_number,
                    'bill_version_public_id_snapshot' => $version->public_id,
                    'bill_version_snapshot' => $version->version,
                    'encounter_public_id_snapshot' => $encounter->public_id,
                    'patient_public_id_snapshot' => $patient->public_id,
                    'patient_name_snapshot' => $patient->full_name,
                    'medical_record_number_snapshot' => $patient->medical_record_number,
                    'care_setting' => $encounter->care_setting,
                    'coverage_profile_snapshot' => $version->coverage_profile,
                    'coverage_label_snapshot' => 'Seluruh sumber biaya yang telah dimaterialisasi pada versi tagihan.',
                    'coverage_exclusion_snapshot' => 'Integrasi penjamin eksternal tidak termasuk.',
                    'payment_method' => FinanceCashSettlement::PAYMENT_CASH,
                    'state' => FinanceCashSettlement::SETTLED,
                    'amount' => $version->net_amount,
                    'prior_net_collected_amount_snapshot' => 0,
                    'source_set_digest' => $version->source_set_digest,
                    'bill_version_content_digest' => $version->content_digest,
                    'content_digest' => str_repeat('0', 64),
                    'settled_at' => $now,
                    'created_at' => $now,
                ]);
                $settlement->public_id = (string) Str::ulid();
                $settlement->content_digest = app(FinanceCashSettlementFingerprint::class)->settlement($settlement);
                $settlement->save();

                FinanceSettlementOperationReceipt::query()->create([
                    'actor_user_id' => $cashier->id,
                    'operation' => FinanceCashSettlementService::OPERATION_SETTLE,
                    'idempotency_key' => 'recovery-cash-settlement-0001',
                    'payload_digest' => str_repeat('a', 64),
                    'settlement_public_id' => $settlement->public_id,
                    'bill_version_public_id' => $version->public_id,
                    'amount' => $settlement->amount,
                    'source_set_digest' => $settlement->source_set_digest,
                    'bill_version_content_digest' => $settlement->bill_version_content_digest,
                    'settlement_content_digest' => $settlement->content_digest,
                    'result_digest' => FinanceCanonicalJson::digest([
                        FinanceCashSettlementService::OPERATION_SETTLE,
                        $settlement->public_id,
                        $settlement->content_digest,
                        $settlement->bill_version_public_id_snapshot,
                        $settlement->bill_version_content_digest,
                        $settlement->amount,
                        $settlement->source_set_digest,
                    ]),
                    'request_correlation_id' => null,
                    'completed_at' => $now,
                ]);

                return [$bill, $version, $settlement];
            }
        );

        $audit = $recordAudit
            ? app(AuditRecorder::class)->record(
                'finance.workflow.mutate',
                'finance_record',
                $bill->public_id,
                $cashier,
                'SUCCESS',
                metadata: [
                    'operation' => FinanceCashSettlementService::OPERATION_SETTLE,
                    'state' => FinanceBill::ISSUED_CURRENT,
                    'version' => $version->version,
                    'settlement_public_id' => $settlement->public_id,
                    'settlement_content_digest' => $settlement->content_digest,
                    'settlement_result_digest' => app(FinanceCashSettlementFingerprint::class)->result($settlement),
                ],
            )
            : null;
        if ($recordAudit) {
            $this->assertInstanceOf(AuditEvent::class, $audit);
        }

        return [
            'cashier' => $cashier,
            'audit' => $audit ?? new AuditEvent,
            'bill' => $bill,
            'version' => $version,
            'settlement' => $settlement,
            'patient' => $patient,
            'encounter' => $encounter,
        ];
    }

    /** @param array{cashier: User, bill: FinanceBill, version: FinanceBillVersion, patient: Patient, encounter: Encounter} $fixture */
    private function appendSecondSettlement(array $fixture): FinanceCashSettlement
    {
        return $this->financeTransaction(function () use ($fixture): FinanceCashSettlement {
            $bill = $fixture['bill']->fresh();
            $sourceDigest = FinanceCanonicalJson::digest(['synthetic-recovery-settlement-source-v2']);
            $bill->fill([
                'state' => FinanceBill::ISSUED_CURRENT,
                'current_version' => 2,
                'current_source_event_count' => 2,
                'current_source_set_digest' => $sourceDigest,
                'current_issued_source_set_digest' => $sourceDigest,
            ]);
            $bill->current_content_digest = app(FinanceEvidenceFingerprint::class)->billContent($bill);
            $bill->save();

            $version = new FinanceBillVersion([
                'bill_id' => $bill->id,
                'previous_version_id' => $fixture['version']->id,
                'issued_by_user_id' => $fixture['cashier']->id,
                'encounter_id' => $fixture['encounter']->id,
                'patient_id' => $fixture['patient']->id,
                'version' => 2,
                'encounter_public_id_snapshot' => $fixture['encounter']->public_id,
                'encounter_number_snapshot' => $fixture['encounter']->public_id,
                'patient_public_id_snapshot' => $fixture['patient']->public_id,
                'patient_name_snapshot' => $fixture['patient']->full_name,
                'medical_record_number_snapshot' => $fixture['patient']->medical_record_number,
                'care_setting' => $fixture['encounter']->care_setting,
                'payer_snapshot' => Encounter::PAYER_UMUM,
                'service_location_snapshot' => $fixture['encounter']->clinic_name,
                'coverage_profile' => FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1,
                'source_set_digest' => $sourceDigest,
                'source_event_count' => 2,
                'source_cutoff_at' => now(),
                'gross_amount' => 200000,
                'reversal_amount' => 0,
                'net_amount' => 200000,
                'issue_reason' => 'Versi kedua bukti pemulihan pelunasan kas.',
                'content_digest' => str_repeat('0', 64),
                'issued_at' => now(),
                'created_at' => now(),
            ]);
            $version->public_id = (string) Str::ulid();
            $version->content_digest = app(FinanceEvidenceFingerprint::class)->version($version, $version->lines);
            $version->save();

            $settlement = new FinanceCashSettlement([
                'receipt_number' => 'KWT-RECOVERY-CASH-0002',
                'bill_id' => $bill->id,
                'bill_version_id' => $version->id,
                'encounter_id' => $fixture['encounter']->id,
                'patient_id' => $fixture['patient']->id,
                'cashier_user_id' => $fixture['cashier']->id,
                'cashier_name_snapshot' => $fixture['cashier']->name,
                'bill_public_id_snapshot' => $bill->public_id,
                'bill_number_snapshot' => $bill->bill_number,
                'bill_version_public_id_snapshot' => $version->public_id,
                'bill_version_snapshot' => $version->version,
                'encounter_public_id_snapshot' => $fixture['encounter']->public_id,
                'patient_public_id_snapshot' => $fixture['patient']->public_id,
                'patient_name_snapshot' => $fixture['patient']->full_name,
                'medical_record_number_snapshot' => $fixture['patient']->medical_record_number,
                'care_setting' => $fixture['encounter']->care_setting,
                'coverage_profile_snapshot' => $version->coverage_profile,
                'coverage_label_snapshot' => 'Seluruh sumber biaya yang telah dimaterialisasi pada versi tagihan.',
                'coverage_exclusion_snapshot' => 'Integrasi penjamin eksternal tidak termasuk.',
                'payment_method' => FinanceCashSettlement::PAYMENT_CASH,
                'state' => FinanceCashSettlement::SETTLED,
                'amount' => 75000,
                'prior_net_collected_amount_snapshot' => 125000,
                'source_set_digest' => $version->source_set_digest,
                'bill_version_content_digest' => $version->content_digest,
                'content_digest' => str_repeat('0', 64),
                'settled_at' => now(),
                'created_at' => now(),
            ]);
            $settlement->public_id = (string) Str::ulid();
            $settlement->content_digest = app(FinanceCashSettlementFingerprint::class)->settlement($settlement);
            $settlement->save();

            return $settlement;

        });
    }

    /** @param array{cashier: User, settlement: FinanceCashSettlement} $fixture */
    private function completeCorrection(array $fixture): FinanceSettlementCorrectionCase
    {
        $service = app(FinanceCashSettlementCorrectionService::class);
        $fingerprints = app(FinanceCashSettlementCorrectionFingerprint::class);
        $case = $service->request(
            $fixture['settlement']->public_id,
            $fixture['cashier'],
            FinanceSettlementCorrectionCase::DUPLICATE_COLLECTION,
            'Pelunasan sintetis duplikat harus dikembalikan penuh.',
            $fixture['settlement']->content_digest,
            'recovery-cash-correction-request-0001',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionCase::class, $case);

        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $state = $fingerprints->state($case, collect());
        $approval = $service->review(
            $case->public_id,
            $supervisor,
            FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Pengembalian penuh telah diperiksa dan disetujui supervisor.',
            $state,
            'recovery-cash-correction-review-0001',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionEvent::class, $approval);

        $events = FinanceSettlementCorrectionEvent::query()
            ->where('correction_case_id', $case->id)->orderBy('sequence')->orderBy('id')->get();
        $completion = $service->completeRefund(
            $case->public_id,
            $supervisor,
            $fingerprints->state($case, $events),
            'recovery-cash-correction-complete-0001',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionEvent::class, $completion);

        return $case->fresh();
    }

    private function financeTransaction(callable $callback): mixed
    {
        return FinanceMutationScope::run(fn () => DB::transaction(function () use ($callback): mixed {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                DB::statement("SET LOCAL simrs.finance_mutation = '1'");
            } elseif ($driver === 'mysql') {
                DB::statement('SET @simrs_finance_mutation = 1');
            }

            try {
                return $callback();
            } finally {
                if ($driver === 'mysql') {
                    DB::statement('SET @simrs_finance_mutation = 0');
                }
            }
        }));
    }

    private function integrityCount(string $method): int
    {
        $reflection = new ReflectionClass(SyntheticRecoverySnapshot::class);

        return (int) $reflection->getMethod($method)->invoke(app(SyntheticRecoverySnapshot::class));
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
