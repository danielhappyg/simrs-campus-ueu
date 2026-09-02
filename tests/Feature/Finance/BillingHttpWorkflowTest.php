<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\FinanceCashSettlement;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
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
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceCashierCollectionProjection;
use App\Support\Finance\FinanceCashierCollectionService;
use App\Support\Finance\FinanceCashSettlementCorrectionProjection;
use App\Support\Finance\FinanceCashSettlementProjection;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceProjection;
use App\Support\Pharmacy\PharmacyCanonicalJson;
use App\Support\Pharmacy\PharmacyMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BillingHttpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_continuous_http_journey_issues_settles_corrects_closes_verifies_and_hands_off_cash(): void
    {
        $fixture = $this->fixture();
        $cashier = $fixture['cashier'];
        $encounter = $fixture['encounter'];
        app(FinanceCashierCollectionService::class)->open($cashier, 'finance-http-batch-open-0001');

        $this->actingAs($cashier)->get(route('finance.bills.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('kasir/tagihan/index')
                ->where('definition_version', 'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1')
                ->where('coverage.profile', 'PHARMACY_RADIOLOGY_LABORATORY_AND_ACCOMMODATION_TARIFF_SOURCE_V1')
                ->where('coverage.domains.0.domain', 'PHARMACY')
                ->where('coverage.domains.1.domain', 'RADIOLOGY')
                ->where('coverage.domains.2.domain', 'LABORATORY')
                ->where('coverage.domains.3.domain', 'ACCOMMODATION')
                ->where('synchronization_candidates.0.encounter_public_id', $encounter->public_id)
                ->where('synchronization_candidates.0.net_amount', 18000)
                ->where('bills', []));

        $this->actingAs($cashier)->post(route('finance.sources.synchronize', $encounter), [
            'idempotency_key' => 'finance-http-sync-0001',
        ])->assertRedirect(route('finance.bills.show', $encounter))
            ->assertSessionHasNoErrors();

        $bill = FinanceBill::query()->sole();
        $this->actingAs($cashier)->get(route('finance.bills.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('kasir/tagihan/show')
                ->where('bill.public_id', $bill->public_id)
                ->where('bill.state', FinanceBill::OPEN_NO_VERSION)
                ->where('bill.current_sources.0.source_domain', 'PHARMACY')
                ->where('bill.current_sources.0.tariff_provenance', null)
                ->where('bill.current_sources.0.signed_amount', 18000)
                ->where('coverage.profile', 'PHARMACY_HANDOVER_RETURN_ONLY_V1')
                ->where('coverage.domains.0.domain', 'PHARMACY')
                ->missing('coverage.domains.1')
                ->where('permissions.can_issue', true)
                ->where('commands.issue_url', route('finance.bills.issue', $encounter, false)));

        $fingerprint = app(FinanceProjection::class)->fingerprint($bill);
        $this->actingAs($cashier)->post(route('finance.bills.issue', $encounter), [
            'expected_fingerprint' => $fingerprint,
            'issue_reason' => 'Sumber biaya Apotek telah diperiksa Kasir.',
            'confirm_issue' => '1',
            'idempotency_key' => 'finance-http-issue-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('finance_bill_versions', [
            'bill_id' => $bill->id,
            'version' => 1,
            'gross_amount' => 18000,
            'reversal_amount' => 0,
            'net_amount' => 18000,
            'coverage_profile' => 'PHARMACY_HANDOVER_RETURN_ONLY_V1',
        ]);
        $this->actingAs($cashier)->get(route('finance.bills.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bill.state', FinanceBill::ISSUED_CURRENT)
                ->where('bill.current_version', 1)
                ->where('bill.versions.0.version', 1)
                ->where('bill.versions.0.net_amount', 18000)
                ->where('permissions.can_issue', false)
                ->where('commands.issue_url', null)
                ->where('settlement.amount', 18000)
                ->where('settlement.payment_method', FinanceCashSettlement::PAYMENT_CASH)
                ->where('settlement.settlement_available', true)
                ->where('permissions.can_settle', true)
                ->where('commands.settlement_url', route('finance.settlements.store', $encounter, false)));

        $bill->refresh();
        $settlementContext = app(FinanceCashSettlementProjection::class)->forBill($bill, $cashier);
        $this->actingAs($cashier)->post(route('finance.settlements.store', $encounter), [
            'expected_bill_fingerprint' => $settlementContext['bill_fingerprint'],
            'expected_bill_version_content_digest' => $settlementContext['bill_version_content_digest'],
            'confirm_settlement' => '1',
            'idempotency_key' => 'finance-http-settlement-0001',
        ])->assertSessionHasNoErrors();

        $settlement = FinanceCashSettlement::query()->sole();
        $this->assertSame(18000, $settlement->amount);
        $this->assertSame(FinanceCashSettlement::PAYMENT_CASH, $settlement->payment_method);

        $this->actingAs($cashier)->get(route('finance.settlements.receipt', $settlement))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('kasir/pelunasan/kuitansi')
                ->where('definition_version', 'EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1')
                ->where('receipt.public_id', $settlement->public_id)
                ->where('receipt.receipt_number', $settlement->receipt_number)
                ->where('receipt.bill_number', $bill->bill_number)
                ->where('receipt.bill_version', 1)
                ->where('receipt.patient_name', $settlement->patient_name_snapshot)
                ->where('receipt.amount', 18000)
                ->where('receipt.payment_method', FinanceCashSettlement::PAYMENT_CASH)
                ->where('receipt.source_domains.0', 'PHARMACY')
                ->where('receipt.correction_state', 'ACTIVE')
                ->where('receipt.correction_url', null)
                ->where('back_url', route('finance.bills.show', $encounter, false)));

        $this->actingAs($cashier)->get(route('finance.bills.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settlement.settlement_available', false)
                ->where('settlement.settlement.public_id', $settlement->public_id)
                ->where('settlement.settlement.amount', 18000)
                ->where('settlement.settlement.receipt_url', route('finance.settlements.receipt', $settlement, false))
                ->where('settlement.settlement.content_digest', $settlement->content_digest)
                ->where('settlement.settlement.correction_state', 'ACTIVE')
                ->where('settlement.settlement.correction_url', null)
                ->where('permissions.can_settle', false)
                ->where('permissions.can_request_correction', true)
                ->where('commands.settlement_url', null)
                ->where('commands.correction_request_url', route('finance.settlement-corrections.store', $settlement, false)));

        $this->actingAs($cashier)->post(route('finance.settlement-corrections.store', $settlement), [
            'reason_code' => FinanceSettlementCorrectionCase::WRONG_BILL,
            'explanation' => 'Tagihan yang dipilih perlu dikoreksi penuh oleh supervisor kasir.',
            'expected_settlement_digest' => $settlement->content_digest,
            'confirm_request' => '1',
            'idempotency_key' => 'finance-http-correction-request-0001',
        ])->assertSessionHasNoErrors();

        $case = FinanceSettlementCorrectionCase::query()->sole();
        $this->actingAs($cashier)->get(route('finance.settlement-corrections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('kasir/koreksi-pelunasan/index')
                ->where('definition_version', 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1')
                ->where('cases.0.public_id', $case->public_id)
                ->where('cases.0.state', 'CORRECTION_REQUESTED')
                ->where('cases.0.show_url', route('finance.settlement-corrections.show', $case, false)));
        $this->actingAs($cashier)->get(route('finance.settlement-corrections.show', $case))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('kasir/koreksi-pelunasan/show')
                ->where('case.public_id', $case->public_id)
                ->where('case.state', 'CORRECTION_REQUESTED')
                ->where('permissions.can_review', false)
                ->where('permissions.can_complete_refund', false));

        $this->actingAs($cashier)->get(route('finance.settlements.receipt', $settlement))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('receipt.correction_state', 'CORRECTION_REQUESTED')
                ->where('receipt.correction_url', route('finance.settlement-corrections.show', $case, false)));

        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $correctionProjection = app(FinanceCashSettlementCorrectionProjection::class);
        $pending = $correctionProjection->case($case->public_id, $supervisor);
        $this->actingAs($supervisor)->get(route('finance.settlement-corrections.show', $case))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_review', true)
                ->where('permissions.can_complete_refund', true)
                ->where('commands.review_url', route('finance.settlement-corrections.review', $case, false)));

        $settlementReceipt = FinanceSettlementOperationReceipt::query()
            ->where('settlement_public_id', $settlement->public_id)->sole();
        $originalResultDigest = $settlementReceipt->result_digest;
        $mutateReceipt = fn (string $digest) => FinanceMutationScope::run(
            fn () => DB::transaction(fn () => FinanceAppendOnlyGuard::runSyntheticReset(
                fn () => DB::table('finance_settlement_operation_receipts')
                    ->where('id', $settlementReceipt->id)->update(['result_digest' => $digest]),
            )),
        );
        $mutateReceipt(str_repeat('f', 64));
        $this->actingAs($supervisor)->post(route('finance.settlement-corrections.review', $case), [
            'decision' => FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'explanation' => 'Bukti rusak harus menghasilkan gangguan layanan, bukan kesalahan formulir.',
            'expected_case_fingerprint' => $pending['fingerprint'],
            'confirm_review' => '1',
            'idempotency_key' => 'finance-http-correction-review-corrupt',
        ])->assertStatus(503);
        $this->assertDatabaseCount('finance_settlement_correction_events', 0);
        $mutateReceipt($originalResultDigest);

        $this->actingAs($supervisor)->post(route('finance.settlement-corrections.review', $case), [
            'decision' => FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'explanation' => 'Bukti transaksi telah diperiksa dan pengembalian penuh disetujui.',
            'expected_case_fingerprint' => $pending['fingerprint'],
            'confirm_review' => '1',
            'idempotency_key' => 'finance-http-correction-review-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $approved = $correctionProjection->case($case->public_id, $supervisor);
        $this->actingAs($supervisor)->post(route('finance.settlement-corrections.complete-refund', $case), [
            'expected_case_fingerprint' => $approved['fingerprint'],
            'confirm_cash_returned' => '1',
            'idempotency_key' => 'finance-http-correction-complete-0001',
        ])->assertRedirect(route('finance.settlement-corrections.refund-receipt', $case))
            ->assertSessionHasNoErrors();

        $this->actingAs($supervisor)->get(route('finance.settlement-corrections.refund-receipt', $case))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('kasir/koreksi-pelunasan/bukti-pengembalian')
                ->where('receipt.correction_public_id', $case->public_id)
                ->where('receipt.original_settlement_public_id', $settlement->public_id)
                ->where('receipt.amount', 18000)
                ->where('receipt.state', FinanceSettlementCorrectionEvent::REFUND_COMPLETED));

        $this->actingAs($cashier)->get(route('finance.bills.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settlement.amount', 18000)
                ->where('settlement.settlement_available', true)
                ->where('settlement.settlement', null)
                ->where('permissions.can_settle', true)
                ->where('permissions.can_request_correction', false)
                ->where('commands.correction_request_url', null));

        $collectionBatch = FinanceCashierCollectionBatch::query()->sole();
        $collection = app(FinanceCashierCollectionProjection::class);
        $openBatch = $collection->batch($collectionBatch->public_id, $cashier);
        $this->assertSame(18000, $openBatch['gross_amount']);
        $this->assertSame(18000, $openBatch['completed_refund_amount']);
        $this->assertSame(0, $openBatch['expected_net_amount']);

        $this->actingAs($cashier)->post(route('finance.cashier-collections.close', ['batch' => $collectionBatch->public_id]), [
            'counted_amount' => 0,
            'expected_state_fingerprint' => $openBatch['state_fingerprint'],
            'confirm_close' => '1',
            'idempotency_key' => 'finance-http-batch-close-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $awaitingVerification = $collection->batch($collectionBatch->public_id, $supervisor);
        $this->assertSame('AWAITING_SUPERVISOR', $awaitingVerification['state']);
        $this->actingAs($supervisor)->post(route('finance.cashier-collections.verify', ['batch' => $collectionBatch->public_id]), [
            'expected_state_fingerprint' => $awaitingVerification['state_fingerprint'],
            'confirm_action' => '1',
            'idempotency_key' => 'finance-http-batch-verify-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $verifiedBatch = $collection->batch($collectionBatch->public_id, $cashier);
        $this->assertSame('VERIFIED', $verifiedBatch['state']);
        $this->actingAs($cashier)->post(route('finance.cashier-collections.handoff', ['batch' => $collectionBatch->public_id]), [
            'expected_state_fingerprint' => $verifiedBatch['state_fingerprint'],
            'confirm_action' => '1',
            'idempotency_key' => 'finance-http-batch-handoff-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $handoff = FinanceCashDepositHandoff::query()->sole();
        $this->actingAs($cashier)->get(route('finance.cashier-collections.handoff-receipt', ['handoff' => $handoff->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('kasir/batch-penerimaan-kas/bukti-penyerahan')
                ->where('receipt.batch_public_id', $collectionBatch->public_id)
                ->where('receipt.gross_amount', 18000)
                ->where('receipt.completed_refund_amount', 18000)
                ->where('receipt.expected_net_amount', 0)
                ->where('receipt.counted_amount', 0)
                ->where('receipt.variance_amount', 0));
    }

    public function test_authorization_precedes_lookup_and_issuance_requires_deliberate_confirmation(): void
    {
        $fixture = $this->fixture();
        $cashier = $fixture['cashier'];
        $encounter = $fixture['encounter'];
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $administrator = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $missing = '01K99999999999999999999999';

        foreach ([$nurse, $administrator] as $unauthorized) {
            $this->actingAs($unauthorized)->get(route('finance.bills.show', $missing))->assertForbidden();
            $this->actingAs($unauthorized)->post(route('finance.sources.synchronize', $missing), [
                'idempotency_key' => 'finance-http-denied-sync',
            ])->assertForbidden();
            $this->actingAs($unauthorized)->post(route('finance.bills.issue', $missing), [
                'expected_fingerprint' => str_repeat('a', 64),
                'issue_reason' => 'Tidak berwenang.',
                'confirm_issue' => '1',
                'idempotency_key' => 'finance-http-denied-issue',
            ])->assertForbidden();
            $this->actingAs($unauthorized)->post(route('finance.settlements.store', $missing), [
                'expected_bill_fingerprint' => str_repeat('a', 64),
                'expected_bill_version_content_digest' => str_repeat('b', 64),
                'confirm_settlement' => '1',
                'idempotency_key' => 'finance-http-denied-settlement',
            ])->assertForbidden();
            $this->actingAs($unauthorized)->get(route('finance.settlements.receipt', $missing))->assertForbidden();
            $this->actingAs($unauthorized)->get(route('finance.settlement-corrections.index'))->assertForbidden();
            $this->actingAs($unauthorized)->post(route('finance.settlement-corrections.store', $missing), [
                'reason_code' => FinanceSettlementCorrectionCase::WRONG_BILL,
                'explanation' => 'Aktor tanpa kewenangan harus ditolak sebelum pencarian data.',
                'expected_settlement_digest' => str_repeat('c', 64),
                'confirm_request' => '1',
                'idempotency_key' => 'finance-http-denied-correction-request',
            ])->assertForbidden();
            $this->actingAs($unauthorized)->get(route('finance.settlement-corrections.show', $missing))->assertForbidden();
            $this->actingAs($unauthorized)->post(route('finance.settlement-corrections.review', $missing), [
                'decision' => FinanceSettlementCorrectionEvent::REVIEW_REJECTED,
                'explanation' => 'Aktor tanpa kewenangan tidak boleh meninjau perkara koreksi.',
                'expected_case_fingerprint' => str_repeat('d', 64),
                'confirm_review' => '1',
                'idempotency_key' => 'finance-http-denied-correction-review',
            ])->assertForbidden();
            $this->actingAs($unauthorized)->post(route('finance.settlement-corrections.complete-refund', $missing), [
                'expected_case_fingerprint' => str_repeat('e', 64),
                'confirm_cash_returned' => '1',
                'idempotency_key' => 'finance-http-denied-correction-complete',
            ])->assertForbidden();
            $this->actingAs($unauthorized)->get(route('finance.settlement-corrections.refund-receipt', $missing))->assertForbidden();
        }

        $this->actingAs($cashier)->get(route('finance.bills.show', $missing))->assertNotFound();
        $this->actingAs($cashier)->get(route('finance.settlement-corrections.show', $missing))->assertNotFound();
        $this->actingAs($cashier)->post(route('finance.settlement-corrections.review', $missing), [
            'decision' => FinanceSettlementCorrectionEvent::REVIEW_REJECTED,
            'explanation' => 'Kasir tidak boleh menjalankan tindakan supervisor.',
            'expected_case_fingerprint' => str_repeat('f', 64),
            'confirm_review' => '1',
            'idempotency_key' => 'finance-http-cashier-correction-review',
        ])->assertForbidden();
        $this->actingAs($cashier)->post(route('finance.sources.synchronize', $encounter), [
            'idempotency_key' => 'finance-http-confirm-sync',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $bill = FinanceBill::query()->sole();

        $this->actingAs($cashier)->post(route('finance.bills.issue', $encounter), [
            'expected_fingerprint' => app(FinanceProjection::class)->fingerprint($bill),
            'issue_reason' => 'Alasan lengkap tetapi belum dikonfirmasi.',
            'idempotency_key' => 'finance-http-no-confirm',
        ])->assertRedirect()->assertSessionHasErrors('confirm_issue');

        $this->assertDatabaseCount('finance_bill_versions', 0);
    }

    /** @return array{cashier:User,encounter:Encounter} */
    private function fixture(): array
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $pharmacist = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poli Penyakit Dalam',
        ]);
        $digest = str_repeat('a', 64);

        PharmacyMutationScope::run(function () use ($pharmacist, $patient, $encounter, $digest): void {
            $medicine = PharmacyMedicine::query()->create([
                'medicine_code' => 'MED-HTTP-FIN',
                'generic_name' => 'Parasetamol',
                'strength_text' => '500 mg',
                'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET',
                'route_choices' => ['ORAL'],
                'acquisition_value' => 1500,
                'teaching_sale_value' => 3000,
                'state' => PharmacyMedicine::ACTIVE,
                'version' => 1,
                'current_content_digest' => $digest,
            ]);
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEP-HTTP-FIN',
                'display_name' => 'Depo Rawat Jalan',
                'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
                'state' => PharmacyDepot::ACTIVE,
                'version' => 1,
                'current_content_digest' => $digest,
            ]);
            $lot = PharmacyStockLot::query()->create([
                'medicine_id' => $medicine->id,
                'depot_id' => $depot->id,
                'opened_by_user_id' => $pharmacist->id,
                'medicine_version' => 1,
                'depot_version' => 1,
                'medicine_code_snapshot' => $medicine->medicine_code,
                'depot_code_snapshot' => $depot->depot_code,
                'lot_code' => 'LOT-HTTP-FIN',
                'received_at' => now(),
                'expiry_date' => now()->addYear()->toDateString(),
                'available_quantity' => 50,
                'quarantined_quantity' => 0,
                'acquisition_value' => 1500,
                'source_reference' => 'HTTP-FINANCE-FIXTURE',
                'state' => PharmacyStockLot::ACTIVE,
                'version' => 1,
                'content_digest' => $digest,
            ]);
            $prescription = PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $patient->id,
                'ordering_physician_user_id' => $pharmacist->id,
                'depot_id' => $depot->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => 'Poli Penyakit Dalam',
                'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code,
                'status' => PharmacyPrescription::HANDED_OVER,
                'version' => 1,
                'current_content_digest' => $digest,
                'ordered_at' => now(),
            ]);
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $prescription->id,
                'medicine_id' => $medicine->id,
                'line_number' => 1,
                'medicine_version' => 1,
                'medicine_version_public_id' => $medicine->public_id,
                'medicine_content_digest' => $digest,
                'medicine_code' => $medicine->medicine_code,
                'medicine_name' => $medicine->generic_name,
                'strength_text' => '500 mg',
                'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET',
                'dose_text' => '1 tablet',
                'route' => 'ORAL',
                'frequency_text' => '3 kali sehari',
                'duration_text' => '2 hari',
                'requested_quantity' => 6,
                'verified_quantity' => 6,
                'sale_value_snapshot' => 3000,
                'instruction' => 'Sesudah makan',
                'content_digest' => $digest,
                'created_at' => now(),
            ]);
            $preparation = PharmacyPreparation::query()->create([
                'prescription_id' => $prescription->id,
                'technician_user_id' => $pharmacist->id,
                'sequence' => 1,
                'state' => PharmacyPreparation::ACTIVE,
                'prescription_fingerprint' => $digest,
                'stock_fingerprint' => $digest,
                'content_digest' => $digest,
                'prepared_at' => now(),
                'created_at' => now(),
            ]);
            $handover = PharmacyHandover::query()->create([
                'prescription_id' => $prescription->id,
                'preparation_id' => $preparation->id,
                'pharmacist_user_id' => $pharmacist->id,
                'sequence' => 1,
                'state' => PharmacyHandover::FULL,
                'preparation_fingerprint' => $digest,
                'content_digest' => $digest,
                'handed_over_at' => now(),
                'created_at' => now(),
            ]);
            $handoverItem = PharmacyHandoverItem::query()->create([
                'handover_id' => $handover->id,
                'prescription_item_id' => $item->id,
                'stock_lot_id' => $lot->id,
                'quantity' => 6,
                'sale_value_snapshot' => 3000,
                'content_digest' => $digest,
                'created_at' => now(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $prescription->id,
                'prescription_item_id' => $item->id,
                'actor_user_id' => $pharmacist->id,
                'event_type' => PharmacyFinancialSourceEvent::CHARGE,
                'quantity' => 6,
                'amount' => 18000,
                'source_type' => 'HANDOVER_ITEM',
                'source_public_id' => $handoverItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $prescription->public_id,
                    $item->public_id,
                    $pharmacist->id,
                    PharmacyFinancialSourceEvent::CHARGE,
                    6,
                    18000,
                    'HANDOVER_ITEM',
                    $handoverItem->public_id,
                ]),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        });

        return compact('cashier', 'encounter');
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor->fresh();
    }
}
