<?php

namespace Tests\Feature\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
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
use App\Models\PharmacyReturn;
use App\Models\PharmacyReturnItem;
use App\Models\PharmacyStockLot;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceAuditUnavailable;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceCashierCollectionProjection;
use App\Support\Finance\FinanceCashierCollectionService;
use App\Support\Finance\FinanceCashSettlementCorrectionProjection;
use App\Support\Finance\FinanceCashSettlementCorrectionService;
use App\Support\Finance\FinanceCashSettlementProjection;
use App\Support\Finance\FinanceCashSettlementService;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceProjection;
use App\Support\Operations\SyntheticRecoverySnapshot;
use App\Support\Pharmacy\PharmacyCanonicalJson;
use App\Support\Pharmacy\PharmacyMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

final class FinanceCashSettlementCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_current_issued_bill_is_settled_once_for_exact_server_derived_cash_amount_and_replays(): void
    {
        $fixture = $this->fixture('SUCCESS', 2, 7000);
        [$bill, $version] = $this->issue($fixture, 'success');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);

        $result = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id,
            $fixture['cashier'],
            $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'],
            'cash-settlement-success-0001',
        );

        $this->assertFalse($result->replayed);
        $this->assertInstanceOf(FinanceCashSettlement::class, $result->record);
        $settlement = $result->record;
        $this->assertSame($version->net_amount, $settlement->amount);
        $this->assertSame(14000, $settlement->amount);
        $this->assertSame(FinanceCashSettlement::PAYMENT_CASH, $settlement->payment_method);
        $this->assertSame(FinanceCashSettlement::SETTLED, $settlement->state);
        $this->assertStringStartsWith('KWT-', $settlement->receipt_number);
        $this->assertSame($version->content_digest, $settlement->bill_version_content_digest);
        $this->assertSame($version->source_set_digest, $settlement->source_set_digest);
        $this->assertDatabaseCount('finance_cash_settlements', 1);
        $this->assertDatabaseCount('finance_settlement_operation_receipts', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'finance.workflow.mutate',
            'outcome' => 'SUCCESS',
        ]);

        $replay = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id,
            $fixture['cashier'],
            $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'],
            'cash-settlement-success-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertInstanceOf(FinanceCashSettlement::class, $replay->record);
        $this->assertSame($settlement->public_id, $replay->record->public_id);

        $after = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier']);
        $this->assertFalse($after['settlement_available']);
        $this->assertSame($settlement->receipt_number, $after['settlement']['receipt_number']);
    }

    public function test_frozen_batch_refuses_correction_but_historical_settlement_replay_survives_close(): void
    {
        $fixture = $this->fixture('BATCH-FREEZE', 1, 5000);
        [$bill] = $this->issue($fixture, 'batch-freeze');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'], 'batch-freeze-settle-0001',
        )->record;
        $this->assertTrue($settlement->collection_binding_required);
        $this->assertDatabaseHas('finance_cashier_collection_members', ['settlement_id' => $settlement->id]);

        $batchProjection = app(FinanceCashierCollectionProjection::class);
        $batch = $batchProjection->worklist($fixture['cashier'])[0];
        app(FinanceCashierCollectionService::class)->requestClose(
            $batch['public_id'], $fixture['cashier'], $settlement->amount,
            $batch['state_fingerprint'], 'batch-freeze-close-0001',
        );
        $frozenSettlementView = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier']);
        $this->assertFalse($frozenSettlementView['settlement_available']);
        $this->assertFalse($frozenSettlementView['settlement']['correction_request_available']);
        $replay = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'], 'batch-freeze-settle-0001',
        );
        $this->assertTrue($replay->replayed);

        try {
            app(FinanceCashSettlementCorrectionService::class)->request(
                $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::WRONG_BILL,
                'Batch telah dibekukan sebelum permintaan koreksi.', $settlement->content_digest,
                'batch-freeze-correction-0001',
            );
            $this->fail('Expected frozen batch correction refusal.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('batch_frozen', $denied->reason);
        }

        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $frozen = $batchProjection->batch($batch['public_id'], $supervisor);
        app(FinanceCashierCollectionService::class)->verify(
            $batch['public_id'], $supervisor, $frozen['state_fingerprint'], 'batch-freeze-verify-0001',
        );
        $verified = $batchProjection->batch($batch['public_id'], $fixture['cashier']);
        app(FinanceCashierCollectionService::class)->createHandoff(
            $batch['public_id'], $fixture['cashier'], $verified['state_fingerprint'], 'batch-freeze-handoff-0001',
        );
        try {
            app(FinanceCashSettlementCorrectionService::class)->request(
                $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::WRONG_BILL,
                'Setoran sudah diserahkan sebelum permintaan koreksi.', $settlement->content_digest,
                'batch-handoff-correction-0001',
            );
            $this->fail('Expected handed-off batch correction refusal.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('cash_handoff_exists', $denied->reason);
        }
    }

    public function test_pending_and_approved_corrections_block_close_while_rejected_and_completed_refund_reconcile(): void
    {
        $corrections = app(FinanceCashSettlementCorrectionService::class);
        $caseProjection = app(FinanceCashSettlementCorrectionProjection::class);
        $collections = app(FinanceCashierCollectionService::class);
        $batchProjection = app(FinanceCashierCollectionProjection::class);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);

        $rejectedFixture = $this->fixture('BATCH-REJECTED', 1, 7000);
        [$rejectedBill] = $this->issue($rejectedFixture, 'batch-rejected');
        $billView = app(FinanceCashSettlementProjection::class)->forBill($rejectedBill, $rejectedFixture['cashier']);
        $rejectedSettlement = app(FinanceCashSettlementService::class)->settle(
            $rejectedBill->public_id, $rejectedFixture['cashier'], $billView['bill_fingerprint'],
            $billView['bill_version_content_digest'], 'batch-rejected-settle-0001',
        )->record;
        $case = $corrections->request(
            $rejectedSettlement->public_id, $rejectedFixture['cashier'], FinanceSettlementCorrectionCase::WRONG_BILL,
            'Menunggu keputusan supervisor sebelum tutup batch.', $rejectedSettlement->content_digest,
            'batch-rejected-request-0001',
        )->record;
        $batch = $batchProjection->worklist($rejectedFixture['cashier'])[0];
        $this->assertDenied('pending_cash_correction', fn () => $collections->requestClose(
            $batch['public_id'], $rejectedFixture['cashier'], $rejectedSettlement->amount,
            $batch['state_fingerprint'], 'batch-rejected-close-pending-0001',
        ));
        $pending = $caseProjection->case($case->public_id, $supervisor);
        $corrections->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REVIEW_REJECTED,
            'Koreksi ditolak; penerimaan kas tetap diperhitungkan.', $pending['fingerprint'],
            'batch-rejected-review-0001',
        );
        $freshBatch = $batchProjection->batch($batch['public_id'], $rejectedFixture['cashier']);
        $closed = $collections->requestClose(
            $batch['public_id'], $rejectedFixture['cashier'], $rejectedSettlement->amount,
            $freshBatch['state_fingerprint'], 'batch-rejected-close-0001',
        )->record;
        $this->assertSame($rejectedSettlement->amount, $closed->gross_amount);
        $this->assertSame(0, $closed->completed_refund_amount);
        $this->assertSame($rejectedSettlement->amount, $closed->expected_net_amount);

        $refundFixture = $this->fixture('BATCH-REFUND', 1, 9000);
        [$refundBill] = $this->issue($refundFixture, 'batch-refund');
        $refundBillView = app(FinanceCashSettlementProjection::class)->forBill($refundBill, $refundFixture['cashier']);
        $refundSettlement = app(FinanceCashSettlementService::class)->settle(
            $refundBill->public_id, $refundFixture['cashier'], $refundBillView['bill_fingerprint'],
            $refundBillView['bill_version_content_digest'], 'batch-refund-settle-0001',
        )->record;
        $refundCase = $corrections->request(
            $refundSettlement->public_id, $refundFixture['cashier'], FinanceSettlementCorrectionCase::DUPLICATE_COLLECTION,
            'Pelunasan ganda menunggu persetujuan pengembalian.', $refundSettlement->content_digest,
            'batch-refund-request-0001',
        )->record;
        $refundPending = $caseProjection->case($refundCase->public_id, $supervisor);
        $corrections->review(
            $refundCase->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Pengembalian penuh disetujui sebelum tutup batch.', $refundPending['fingerprint'],
            'batch-refund-review-0001',
        );
        $refundBatch = $batchProjection->worklist($refundFixture['cashier'])[0];
        $this->assertDenied('pending_cash_correction', fn () => $collections->requestClose(
            $refundBatch['public_id'], $refundFixture['cashier'], 0,
            $refundBatch['state_fingerprint'], 'batch-refund-close-approved-0001',
        ));
        $approved = $caseProjection->case($refundCase->public_id, $supervisor);
        $corrections->completeRefund(
            $refundCase->public_id, $supervisor, $approved['fingerprint'], 'batch-refund-complete-0001',
        );
        $refreshedRefundBatch = $batchProjection->batch($refundBatch['public_id'], $refundFixture['cashier']);
        $refundClosed = $collections->requestClose(
            $refundBatch['public_id'], $refundFixture['cashier'], 0,
            $refreshedRefundBatch['state_fingerprint'], 'batch-refund-close-0001',
        )->record;
        $this->assertSame($refundSettlement->amount, $refundClosed->gross_amount);
        $this->assertSame($refundSettlement->amount, $refundClosed->completed_refund_amount);
        $this->assertSame(0, $refundClosed->expected_net_amount);
        $requestReplay = $corrections->request(
            $refundSettlement->public_id, $refundFixture['cashier'], FinanceSettlementCorrectionCase::DUPLICATE_COLLECTION,
            'Pelunasan ganda menunggu persetujuan pengembalian.', $refundSettlement->content_digest,
            'batch-refund-request-0001',
        );
        $this->assertTrue($requestReplay->replayed);
        $completeReplay = $corrections->completeRefund(
            $refundCase->public_id, $supervisor, $approved['fingerprint'], 'batch-refund-complete-0001',
        );
        $this->assertTrue($completeReplay->replayed);
        $this->assertDenied('batch_frozen', fn () => $corrections->request(
            $refundSettlement->public_id, $refundFixture['cashier'], FinanceSettlementCorrectionCase::DUPLICATE_COLLECTION,
            'Perintah baru tetap ditolak setelah batch dibekukan.', $refundSettlement->content_digest,
            'batch-refund-request-after-freeze-0002',
        ));
    }

    public function test_recovery_detects_binding_marker_or_member_tamper(): void
    {
        $fixture = $this->fixture('BATCH-RECOVERY', 1, 6000);
        [$bill] = $this->issue($fixture, 'batch-recovery');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'], 'batch-recovery-settle-0001',
        )->record;
        $collectionMethod = new ReflectionMethod(SyntheticRecoverySnapshot::class, 'financeCashierCollectionMismatchCount');
        $settlementMethod = new ReflectionMethod(SyntheticRecoverySnapshot::class, 'financeCashSettlementMismatchCount');
        $snapshot = app(SyntheticRecoverySnapshot::class);
        $this->assertSame(0, $collectionMethod->invoke($snapshot));

        FinanceMutationScope::run(fn () => FinanceAppendOnlyGuard::runSyntheticReset(function () use ($settlement): void {
            DB::table('finance_cash_settlements')->where('id', $settlement->id)->update(['collection_binding_required' => false]);
        }));
        $this->assertGreaterThan(0, $settlementMethod->invoke($snapshot));
        FinanceMutationScope::run(fn () => FinanceAppendOnlyGuard::runSyntheticReset(function () use ($settlement): void {
            DB::table('finance_cash_settlements')->where('id', $settlement->id)->update(['collection_binding_required' => true]);
            DB::table('finance_cashier_collection_members')->where('settlement_id', $settlement->id)->delete();
        }));
        $this->assertGreaterThan(0, $collectionMethod->invoke($snapshot));
        $this->assertDenied('batch_integrity_failure', fn () => app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'], 'batch-recovery-settle-0001',
        ));
    }

    public function test_exact_cashier_authorization_idempotency_conflict_and_duplicate_settlement_fail_closed(): void
    {
        $fixture = $this->fixture('DENIAL', 1, 5000);
        [$bill] = $this->issue($fixture, 'denial');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);

        try {
            app(FinanceCashSettlementService::class)->settle(
                $bill->public_id,
                $nurse,
                $projection['bill_fingerprint'],
                $projection['bill_version_content_digest'],
                'cash-settlement-denied-role',
            );
            $this->fail('Expected exact cashier authorization denial.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('audit_events', [
                'action' => 'finance.workflow.mutate', 'outcome' => 'DENIED', 'reason' => 'role_not_permitted',
            ]);
        }

        $service = app(FinanceCashSettlementService::class);
        $service->settle(
            $bill->public_id,
            $fixture['cashier'],
            $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'],
            'cash-settlement-denial-0001',
        );
        $this->assertDenied('idempotency_key_conflict', fn () => $service->settle(
            $bill->public_id,
            $fixture['cashier'],
            str_repeat('a', 64),
            $projection['bill_version_content_digest'],
            'cash-settlement-denial-0001',
        ));
        $this->assertDenied('bill_version_already_settled', fn () => $service->settle(
            $bill->public_id,
            $fixture['cashier'],
            $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'],
            'cash-settlement-denial-0002',
        ));
        $this->assertDatabaseCount('finance_cash_settlements', 1);
    }

    public function test_cancelled_stale_zero_and_new_source_states_are_denied_without_settlement(): void
    {
        $cancelled = $this->fixture('CANCEL', 1, 4000);
        [$cancelledBill] = $this->issue($cancelled, 'cancel');
        $cancelledProjection = app(FinanceCashSettlementProjection::class)->forBill($cancelledBill, $cancelled['cashier']);
        $cancelled['encounter']->update(['status' => Encounter::STATUS_CANCELLED]);
        $this->assertDenied('encounter_cancelled', fn () => app(FinanceCashSettlementService::class)->settle(
            $cancelledBill->public_id,
            $cancelled['cashier'],
            $cancelledProjection['bill_fingerprint'],
            $cancelledProjection['bill_version_content_digest'],
            'cash-settlement-cancelled',
        ));

        $stale = $this->fixture('STALE', 1, 4500);
        [$staleBill] = $this->issue($stale, 'stale');
        $staleProjection = app(FinanceCashSettlementProjection::class)->forBill($staleBill, $stale['cashier']);
        $this->assertDenied('stale_bill', fn () => app(FinanceCashSettlementService::class)->settle(
            $staleBill->public_id,
            $stale['cashier'],
            str_repeat('b', 64),
            $staleProjection['bill_version_content_digest'],
            'cash-settlement-stale',
        ));

        $zero = $this->fixture('ZERO', 1, 6000);
        $this->appendReversal($zero, 1);
        [$zeroBill] = $this->issue($zero, 'zero');
        $zeroProjection = app(FinanceCashSettlementProjection::class)->forBill($zeroBill, $zero['cashier']);
        $this->assertDenied('zero_outstanding_amount', fn () => app(FinanceCashSettlementService::class)->settle(
            $zeroBill->public_id,
            $zero['cashier'],
            $zeroProjection['bill_fingerprint'],
            $zeroProjection['bill_version_content_digest'],
            'cash-settlement-zero',
        ));

        $pending = $this->fixture('PENDING', 2, 6500);
        [$pendingBill] = $this->issue($pending, 'pending');
        $pendingProjection = app(FinanceCashSettlementProjection::class)->forBill($pendingBill, $pending['cashier']);
        $this->appendReversal($pending, 1);
        $this->assertDenied('new_source_pending', fn () => app(FinanceCashSettlementService::class)->settle(
            $pendingBill->public_id,
            $pending['cashier'],
            $pendingProjection['bill_fingerprint'],
            $pendingProjection['bill_version_content_digest'],
            'cash-settlement-pending',
        ));

        $this->assertDatabaseCount('finance_cash_settlements', 0);
        $this->assertDatabaseCount('finance_settlement_operation_receipts', 0);
    }

    public function test_later_cumulative_bill_version_collects_only_new_outstanding_balance(): void
    {
        $fixture = $this->fixture('OUTSTANDING', 2, 7000);
        [$bill, $versionOne] = $this->issue($fixture, 'outstanding-v1');
        $settlements = app(FinanceCashSettlementService::class);
        $firstProjection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $first = $settlements->settle(
            $bill->public_id,
            $fixture['cashier'],
            $firstProjection['bill_fingerprint'],
            $firstProjection['bill_version_content_digest'],
            'cash-settlement-outstanding-v1',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $first);
        $this->assertSame(14000, $first->amount);

        $this->appendAdditionalCharge($fixture, 5000);
        $billService = app(FinanceBillService::class);
        $bill = $billService->synchronize(
            $fixture['encounter']->public_id,
            $fixture['cashier'],
            'cash-prerequisite-sync-outstanding-v2',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $versionTwo = $billService->issue(
            $bill->public_id,
            $fixture['cashier'],
            app(FinanceProjection::class)->fingerprint($bill),
            'Sumber biaya baru setelah pelunasan versi pertama.',
            'cash-prerequisite-issue-outstanding-v2',
        )->record;
        $this->assertInstanceOf(FinanceBillVersion::class, $versionTwo);
        $this->assertSame(19000, $versionTwo->net_amount);
        $this->assertSame(14000, $versionOne->net_amount);

        $secondProjection = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier']);
        $this->assertSame(5000, $secondProjection['amount']);
        $this->assertTrue($secondProjection['settlement_available']);
        $second = $settlements->settle(
            $bill->public_id,
            $fixture['cashier'],
            $secondProjection['bill_fingerprint'],
            $secondProjection['bill_version_content_digest'],
            'cash-settlement-outstanding-v2',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $second);
        $this->assertSame(5000, $second->amount);
        $this->assertSame(19000, (int) FinanceCashSettlement::query()->where('bill_id', $bill->id)->sum('amount'));

        $snapshottedCashier = $second->cashier_name_snapshot;
        $fixture['cashier']->update(['name' => 'Nama Kasir Berubah']);
        $receipt = app(FinanceCashSettlementProjection::class)->receipt($second->public_id, $fixture['cashier']->fresh());
        $this->assertSame($snapshottedCashier, $receipt['cashier_name']);
        $this->assertNotSame('Nama Kasir Berubah', $receipt['cashier_name']);
        $this->assertNotEmpty($receipt['coverage_label']);
        $this->assertNotEmpty($receipt['coverage_exclusion']);
    }

    public function test_audit_failure_rolls_back_settlement_and_operation_receipt(): void
    {
        $fixture = $this->fixture('AUDIT', 1, 9000);
        [$bill] = $this->issue($fixture, 'audit');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null, string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null, bool $includeRequestFingerprint = true): ?AuditEvent
            {
                return null;
            }
        });

        try {
            app(FinanceCashSettlementService::class)->settle(
                $bill->public_id,
                $fixture['cashier'],
                $projection['bill_fingerprint'],
                $projection['bill_version_content_digest'],
                'cash-settlement-audit-failure',
            );
            $this->fail('Expected audit failure.');
        } catch (FinanceAuditUnavailable) {
            $this->assertDatabaseCount('finance_cash_settlements', 0);
            $this->assertDatabaseCount('finance_settlement_operation_receipts', 0);
        }
    }

    public function test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays(): void
    {
        $fixture = $this->fixture('CORRECTION', 2, 8000);
        [$bill, $version] = $this->issue($fixture, 'correction');
        $settlementProjection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $settlementProjection['bill_fingerprint'],
            $settlementProjection['bill_version_content_digest'], 'cash-correction-original-settle',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $settlement);
        $settledSummary = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier'])['settlement'];
        $this->assertSame($settlement->content_digest, $settledSummary['content_digest']);
        $this->assertSame('ACTIVE', $settledSummary['correction_state']);
        $this->assertNull($settledSummary['correction_public_id']);
        $this->assertTrue($settledSummary['correction_request_available']);

        $corrections = app(FinanceCashSettlementCorrectionService::class);
        $request = $corrections->request(
            $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::WRONG_BILL,
            'Tagihan yang dipilih kasir perlu dikoreksi penuh.', $settlement->content_digest,
            'cash-correction-request-0001',
        );
        $this->assertInstanceOf(FinanceSettlementCorrectionCase::class, $request->record);
        $case = $request->record;
        $requestedSummary = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier'])['settlement'];
        $this->assertSame('CORRECTION_REQUESTED', $requestedSummary['correction_state']);
        $this->assertSame($case->public_id, $requestedSummary['correction_public_id']);
        $this->assertFalse($requestedSummary['correction_request_available']);
        $replay = $corrections->request(
            $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::WRONG_BILL,
            'Tagihan yang dipilih kasir perlu dikoreksi penuh.', $settlement->content_digest,
            'cash-correction-request-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame($case->public_id, $replay->record->public_id);
        $this->assertDenied('idempotency_key_conflict', fn () => $corrections->request(
            $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::WRONG_BILL,
            'Muatan yang berbeda harus ditolak untuk kunci sama.', $settlement->content_digest,
            'cash-correction-request-0001',
        ));

        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $projection = app(FinanceCashSettlementCorrectionProjection::class);
        $pending = $projection->case($case->public_id, $supervisor);
        $approval = $corrections->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Pengembalian penuh telah diperiksa dan disetujui.', $pending['fingerprint'],
            'cash-correction-review-0001',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionEvent::class, $approval);
        $this->assertSame($settlement->amount, $approval->amount);

        $approved = $projection->case($case->public_id, $supervisor);
        $this->assertDenied('review_already_completed', fn () => $corrections->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Tinjauan kedua wajib ditolak setelah keputusan pertama.', $approved['fingerprint'],
            'cash-correction-review-duplicate',
        ));
        $completion = $corrections->completeRefund(
            $case->public_id, $supervisor, $approved['fingerprint'], 'cash-correction-complete-0001',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionEvent::class, $completion);
        $this->assertSame(FinanceSettlementCorrectionEvent::REFUND_COMPLETED, $completion->event_type);
        $this->assertSame($settlement->amount, $completion->amount);
        $this->assertSame($settlement->receipt_number, $projection->refundReceipt($case->public_id, $supervisor)['original_receipt_number']);
        $completed = $projection->case($case->public_id, $supervisor);
        $this->assertDenied('refund_not_approved', fn () => $corrections->completeRefund(
            $case->public_id, $supervisor, $completed['fingerprint'], 'cash-correction-complete-duplicate',
        ));

        $originalReceipt = app(FinanceCashSettlementProjection::class)->receipt($settlement->public_id, $fixture['cashier']);
        $this->assertSame(FinanceSettlementCorrectionEvent::REFUND_COMPLETED, $originalReceipt['correction_state']);
        $replacementProjection = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier']);
        $this->assertTrue($replacementProjection['settlement_available']);
        $this->assertSame($version->net_amount, $replacementProjection['amount']);
        $replacement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $replacementProjection['bill_fingerprint'],
            $replacementProjection['bill_version_content_digest'], 'cash-correction-replacement-0001',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $replacement);
        $this->assertNotSame($settlement->public_id, $replacement->public_id);
        $this->assertSame($settlement->amount, $replacement->amount);
        $this->assertDatabaseCount('finance_cash_settlements', 2);
        $this->assertDatabaseCount('finance_settlement_correction_cases', 1);
        $this->assertDatabaseCount('finance_settlement_correction_events', 2);

        try {
            DB::table('finance_settlement_correction_cases')->where('id', $case->id)->update(['amount' => 1]);
            $this->fail('Expected correction SQL scope refusal.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Write-capable SQL against finance tables is prohibited', $exception->getMessage());
        }
        try {
            FinanceMutationScope::run(fn () => DB::transaction(
                fn () => DB::table('finance_settlement_correction_events')->where('id', $completion->id)->update(['amount' => 1]),
            ));
            $this->fail('Expected correction append-only database refusal.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('finance append-only evidence is immutable', $exception->getMessage());
        }

        FinanceMutationScope::run(fn () => DB::transaction(fn () => FinanceAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table('finance_settlement_correction_operation_receipts')
                ->where('operation', FinanceCashSettlementCorrectionService::OPERATION_COMPLETE)
                ->update(['result_digest' => str_repeat('f', 64)]),
        )));
        $this->assertDenied('receipt_corrupt', fn () => $projection->refundReceipt($case->public_id, $supervisor));
    }

    public function test_correction_worklist_visibility_dual_control_terminal_states_and_stale_guards(): void
    {
        $fixture = $this->fixture('CORRECTION-DENIAL', 1, 8100);
        [$bill] = $this->issue($fixture, 'correction-denial');
        $settlementProjection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $settlementProjection['bill_fingerprint'],
            $settlementProjection['bill_version_content_digest'], 'cash-correction-denial-settle',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $settlement);
        $case = app(FinanceCashSettlementCorrectionService::class)->request(
            $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::CASHIER_INPUT_CONTEXT_ERROR,
            'Konteks masukan kasir harus ditinjau supervisor.', $settlement->content_digest,
            'cash-correction-denial-request',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionCase::class, $case);

        $projection = app(FinanceCashSettlementCorrectionProjection::class);
        $otherCashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $this->assertCount(1, $projection->worklist($fixture['cashier']));
        $this->assertCount(0, $projection->worklist($otherCashier));
        $this->assertCount(1, $projection->worklist($supervisor));

        $pending = $projection->case($case->public_id, $supervisor);
        $this->assertDenied('stale_correction_case', fn () => app(FinanceCashSettlementCorrectionService::class)->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REVIEW_REJECTED,
            'Permintaan ditolak setelah pemeriksaan supervisor.', str_repeat('d', 64),
            'cash-correction-denial-stale',
        ));
        app(FinanceCashSettlementCorrectionService::class)->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REVIEW_REJECTED,
            'Permintaan ditolak setelah pemeriksaan supervisor.', $pending['fingerprint'],
            'cash-correction-denial-review',
        );
        $terminal = $projection->case($case->public_id, $supervisor);
        $this->assertSame(FinanceSettlementCorrectionEvent::REVIEW_REJECTED, $terminal['state']);
        $this->assertDenied('refund_not_approved', fn () => app(FinanceCashSettlementCorrectionService::class)->completeRefund(
            $case->public_id, $supervisor, $terminal['fingerprint'], 'cash-correction-denial-complete',
        ));
        $this->assertDenied('refund_not_completed', fn () => $projection->refundReceipt($case->public_id, $supervisor));

        try {
            $projection->case($case->public_id, $otherCashier);
            $this->fail('Expected cashier ownership denial.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_requester_cannot_become_the_supervisor_for_their_own_case(): void
    {
        $fixture = $this->fixture('CORRECTION-SEPARATION', 1, 8200);
        [$bill] = $this->issue($fixture, 'correction-separation');
        $billProjection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $billProjection['bill_fingerprint'],
            $billProjection['bill_version_content_digest'], 'cash-correction-separation-settle',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $settlement);
        $case = app(FinanceCashSettlementCorrectionService::class)->request(
            $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::OTHER_SUPERVISOR_REVIEW,
            'Permintaan harus diperiksa oleh akun supervisor terpisah.', $settlement->content_digest,
            'cash-correction-separation-request',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionCase::class, $case);

        $fixture['cashier']->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR)->sole()->id,
        ]);
        $samePersonAsSupervisor = $fixture['cashier']->fresh();
        $sameActorProjection = app(FinanceCashSettlementCorrectionProjection::class)
            ->case($case->public_id, $samePersonAsSupervisor);
        $this->assertTrue($sameActorProjection['requester_is_actor']);
        $this->actingAs($samePersonAsSupervisor)
            ->get(route('finance.settlement-corrections.show', $case->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.requester_is_actor', true)
                ->where('permissions.can_review', false)
                ->where('permissions.can_complete_refund', false));
        $fingerprint = $sameActorProjection['fingerprint'];
        $this->assertDenied('same_actor_separation', fn () => app(FinanceCashSettlementCorrectionService::class)->review(
            $case->public_id, $samePersonAsSupervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Persetujuan oleh pemohon sendiri harus ditolak.', $fingerprint,
            'cash-correction-separation-review',
        ));
        $this->assertDatabaseCount('finance_settlement_correction_events', 0);
    }

    public function test_refund_of_predecessor_after_later_payment_uses_net_cash_for_additional_replacement(): void
    {
        $fixture = $this->fixture('CORRECTION-PREDECESSOR', 2, 8400);
        [$bill] = $this->issue($fixture, 'correction-predecessor-v1');
        $settlements = app(FinanceCashSettlementService::class);
        $v1Projection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $first = $settlements->settle(
            $bill->public_id, $fixture['cashier'], $v1Projection['bill_fingerprint'],
            $v1Projection['bill_version_content_digest'], 'cash-correction-predecessor-settle-v1',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $first);

        $this->appendAdditionalCharge($fixture, 5100);
        $billing = app(FinanceBillService::class);
        $bill = $billing->synchronize(
            $fixture['encounter']->public_id, $fixture['cashier'], 'cash-correction-predecessor-sync-v2',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $versionTwo = $billing->issue(
            $bill->public_id, $fixture['cashier'], app(FinanceProjection::class)->fingerprint($bill),
            'Sumber baru sebelum koreksi pembayaran terdahulu.', 'cash-correction-predecessor-issue-v2',
        )->record;
        $this->assertInstanceOf(FinanceBillVersion::class, $versionTwo);
        $v2Projection = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier']);
        $second = $settlements->settle(
            $bill->public_id, $fixture['cashier'], $v2Projection['bill_fingerprint'],
            $v2Projection['bill_version_content_digest'], 'cash-correction-predecessor-settle-v2',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $second);
        $this->assertSame(5100, $second->amount);

        $corrections = app(FinanceCashSettlementCorrectionService::class);
        $case = $corrections->request(
            $first->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::WRONG_BILL,
            'Pelunasan versi terdahulu harus dikembalikan penuh.', $first->content_digest,
            'cash-correction-predecessor-request',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionCase::class, $case);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $cases = app(FinanceCashSettlementCorrectionProjection::class);
        $pending = $cases->case($case->public_id, $supervisor);
        $corrections->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Pengembalian pelunasan terdahulu disetujui penuh.', $pending['fingerprint'],
            'cash-correction-predecessor-review',
        );
        $approved = $cases->case($case->public_id, $supervisor);
        $corrections->completeRefund(
            $case->public_id, $supervisor, $approved['fingerprint'], 'cash-correction-predecessor-complete',
        );

        $replacementProjection = app(FinanceCashSettlementProjection::class)->forBill($bill->fresh(), $fixture['cashier']);
        $this->assertTrue($replacementProjection['settlement_available']);
        $this->assertSame($first->amount, $replacementProjection['amount']);
        $this->assertSame($second->public_id, $replacementProjection['settlement']['public_id']);
        $replacement = $settlements->settle(
            $bill->public_id, $fixture['cashier'], $replacementProjection['bill_fingerprint'],
            $replacementProjection['bill_version_content_digest'], 'cash-correction-predecessor-replacement',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $replacement);
        $this->assertSame($first->amount, $replacement->amount);
        $this->assertSame($versionTwo->id, $replacement->bill_version_id);
        $this->assertSame($versionTwo->net_amount, FinanceCashSettlement::query()->where('bill_id', $bill->id)->sum('amount') - $first->amount);
        $settlementAudits = AuditEvent::query()
            ->where('action', 'finance.workflow.mutate')
            ->where('resource_id', $bill->public_id)
            ->where('outcome', 'SUCCESS')->get()
            ->filter(fn (AuditEvent $event): bool => ($event->metadata['operation'] ?? null) === FinanceCashSettlementService::OPERATION_SETTLE);
        $this->assertCount(3, $settlementAudits);
        $this->assertEqualsCanonicalizing(
            [$first->public_id, $second->public_id, $replacement->public_id],
            $settlementAudits->pluck('metadata.settlement_public_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$first->content_digest, $second->content_digest, $replacement->content_digest],
            $settlementAudits->pluck('metadata.settlement_content_digest')->all(),
        );
    }

    public function test_correction_audit_failure_rolls_back_case_and_technical_receipt(): void
    {
        $fixture = $this->fixture('CORRECTION-AUDIT', 1, 8300);
        [$bill] = $this->issue($fixture, 'correction-audit');
        $billProjection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $billProjection['bill_fingerprint'],
            $billProjection['bill_version_content_digest'], 'cash-correction-audit-settle',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $settlement);
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null, string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null, bool $includeRequestFingerprint = true): ?AuditEvent
            {
                return null;
            }
        });

        try {
            app(FinanceCashSettlementCorrectionService::class)->request(
                $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::DUPLICATE_COLLECTION,
                'Koreksi memerlukan audit yang wajib tersedia.', $settlement->content_digest,
                'cash-correction-audit-request',
            );
            $this->fail('Expected correction audit failure.');
        } catch (FinanceAuditUnavailable) {
            $this->assertDatabaseCount('finance_settlement_correction_cases', 0);
            $this->assertDatabaseCount('finance_settlement_correction_operation_receipts', 0);
            $this->assertDatabaseCount('finance_cash_settlements', 1);
        }
    }

    public function test_review_and_refund_completion_revalidate_original_settlement_receipt(): void
    {
        $fixture = $this->fixture('CORRECTION-REVALIDATE', 1, 8350);
        [$bill] = $this->issue($fixture, 'correction-revalidate');
        $billProjection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id, $fixture['cashier'], $billProjection['bill_fingerprint'],
            $billProjection['bill_version_content_digest'], 'cash-correction-revalidate-settle',
        )->record;
        $this->assertInstanceOf(FinanceCashSettlement::class, $settlement);
        $service = app(FinanceCashSettlementCorrectionService::class);
        $case = $service->request(
            $settlement->public_id, $fixture['cashier'], FinanceSettlementCorrectionCase::DUPLICATE_COLLECTION,
            'Bukti asal harus diperiksa ulang pada setiap transisi.', $settlement->content_digest,
            'cash-correction-revalidate-request',
        )->record;
        $this->assertInstanceOf(FinanceSettlementCorrectionCase::class, $case);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $projection = app(FinanceCashSettlementCorrectionProjection::class);
        $pending = $projection->case($case->public_id, $supervisor);
        $receipt = FinanceSettlementOperationReceipt::query()
            ->where('settlement_public_id', $settlement->public_id)->sole();
        $originalResultDigest = $receipt->result_digest;

        $tamper = fn (string $digest) => FinanceMutationScope::run(
            fn () => DB::transaction(fn () => FinanceAppendOnlyGuard::runSyntheticReset(
                fn () => DB::table('finance_settlement_operation_receipts')
                    ->where('id', $receipt->id)->update(['result_digest' => $digest]),
            )),
        );
        $tamper(str_repeat('f', 64));
        $this->assertDenied('settlement_integrity_failure', fn () => $service->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Persetujuan tidak boleh melewati bukti asal yang rusak.', $pending['fingerprint'],
            'cash-correction-revalidate-review-denied',
        ));
        $this->assertDatabaseCount('finance_settlement_correction_events', 0);

        $tamper($originalResultDigest);
        $service->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Bukti asal pulih dan pengembalian penuh disetujui.', $pending['fingerprint'],
            'cash-correction-revalidate-review-approved',
        );
        $approved = $projection->case($case->public_id, $supervisor);
        $tamper(str_repeat('e', 64));
        $this->assertDenied('settlement_integrity_failure', fn () => $service->completeRefund(
            $case->public_id, $supervisor, $approved['fingerprint'],
            'cash-correction-revalidate-complete-denied',
        ));
        $this->assertDatabaseCount('finance_settlement_correction_events', 1);
        $this->assertDatabaseCount('finance_settlement_correction_operation_receipts', 2);
    }

    public function test_settlement_evidence_is_protected_by_sql_scope_and_database_append_only_guards(): void
    {
        $fixture = $this->fixture('GUARD', 1, 10000);
        [$bill] = $this->issue($fixture, 'guard');
        $projection = app(FinanceCashSettlementProjection::class)->forBill($bill, $fixture['cashier']);
        $settlement = app(FinanceCashSettlementService::class)->settle(
            $bill->public_id,
            $fixture['cashier'],
            $projection['bill_fingerprint'],
            $projection['bill_version_content_digest'],
            'cash-settlement-guard',
        )->record;
        if (! $settlement instanceof FinanceCashSettlement) {
            throw new LogicException('Expected cash settlement evidence.');
        }

        try {
            DB::table('finance_cash_settlements')->where('id', $settlement->id)->update(['amount' => 1]);
            $this->fail('Expected application SQL guard refusal.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Write-capable SQL against finance tables is prohibited', $exception->getMessage());
        }
        try {
            FinanceMutationScope::run(fn () => DB::transaction(
                fn () => DB::table('finance_cash_settlements')->where('id', $settlement->id)->update(['amount' => 1]),
            ));
            $this->fail('Expected append-only database refusal.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('finance append-only evidence is immutable', $exception->getMessage());
        }

        FinanceMutationScope::run(fn () => DB::transaction(fn () => FinanceAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table('finance_settlement_operation_receipts')->delete(),
        )));
        $this->assertDatabaseCount('finance_settlement_operation_receipts', 0);
        $this->assertDatabaseCount('finance_cash_settlements', 1);
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{FinanceBill, FinanceBillVersion}
     */
    private function issue(array $fixture, string $suffix): array
    {
        $service = app(FinanceBillService::class);
        $bill = $service->synchronize(
            $fixture['encounter']->public_id,
            $fixture['cashier'],
            'cash-prerequisite-sync-'.$suffix,
        )->record;
        if (! $bill instanceof FinanceBill) {
            throw new LogicException('Expected finance bill.');
        }
        $version = $service->issue(
            $bill->public_id,
            $fixture['cashier'],
            app(FinanceProjection::class)->fingerprint($bill),
            'Penerbitan sebelum pelunasan kas.',
            'cash-prerequisite-issue-'.$suffix,
        )->record;
        if (! $version instanceof FinanceBillVersion) {
            throw new LogicException('Expected finance bill version.');
        }
        app(FinanceCashierCollectionService::class)->open(
            $fixture['cashier'],
            'cash-batch-open-'.$suffix,
        );

        return [$bill->fresh(), $version];
    }

    /** @return array<string, mixed> */
    private function fixture(string $suffix, int $quantity, int $unitAmount): array
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $pharmacist = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poli '.$suffix,
        ]);
        $digest = str_repeat('a', 64);

        return PharmacyMutationScope::run(function () use ($cashier, $pharmacist, $patient, $encounter, $quantity, $unitAmount, $suffix, $digest): array {
            $medicine = PharmacyMedicine::query()->create([
                'medicine_code' => 'MED-CASH-'.$suffix, 'generic_name' => 'Obat '.$suffix, 'strength_text' => '500 mg',
                'dosage_form' => 'TABLET', 'base_unit' => 'TABLET', 'route_choices' => ['ORAL'],
                'acquisition_value' => 1000, 'teaching_sale_value' => $unitAmount, 'state' => PharmacyMedicine::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEP-CASH-'.$suffix, 'display_name' => 'Depo '.$suffix,
                'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT], 'state' => PharmacyDepot::ACTIVE,
                'version' => 1, 'current_content_digest' => $digest,
            ]);
            $lot = PharmacyStockLot::query()->create([
                'medicine_id' => $medicine->id, 'depot_id' => $depot->id, 'opened_by_user_id' => $pharmacist->id,
                'medicine_version' => 1, 'depot_version' => 1, 'medicine_code_snapshot' => $medicine->medicine_code,
                'depot_code_snapshot' => $depot->depot_code, 'lot_code' => 'LOT-CASH-'.$suffix, 'received_at' => now(),
                'expiry_date' => now()->addYear()->toDateString(), 'available_quantity' => 100,
                'quarantined_quantity' => 0, 'acquisition_value' => 1000, 'source_reference' => 'CASH-'.$suffix,
                'state' => PharmacyStockLot::ACTIVE, 'version' => 1, 'content_digest' => $digest,
            ]);
            $prescription = PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id, 'patient_id' => $patient->id,
                'ordering_physician_user_id' => $pharmacist->id, 'depot_id' => $depot->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT, 'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => 'Poli '.$suffix, 'depot_version' => 1, 'depot_code_snapshot' => $depot->depot_code,
                'status' => PharmacyPrescription::HANDED_OVER, 'version' => 1,
                'current_content_digest' => $digest, 'ordered_at' => now(),
            ]);
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $prescription->id, 'medicine_id' => $medicine->id, 'line_number' => 1,
                'medicine_version' => 1, 'medicine_version_public_id' => $medicine->public_id,
                'medicine_content_digest' => $digest, 'medicine_code' => $medicine->medicine_code,
                'medicine_name' => $medicine->generic_name, 'strength_text' => '500 mg', 'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET', 'dose_text' => '1 tablet', 'route' => 'ORAL',
                'frequency_text' => '1 kali sehari', 'duration_text' => '1 hari',
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
                'handed_over_at' => now(), 'created_at' => now(),
            ]);
            $handoverItem = PharmacyHandoverItem::query()->create([
                'handover_id' => $handover->id, 'prescription_item_id' => $item->id, 'stock_lot_id' => $lot->id,
                'quantity' => $quantity, 'sale_value_snapshot' => $unitAmount, 'content_digest' => $digest, 'created_at' => now(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id,
                'actor_user_id' => $pharmacist->id, 'event_type' => PharmacyFinancialSourceEvent::CHARGE,
                'quantity' => $quantity, 'amount' => $quantity * $unitAmount,
                'source_type' => 'HANDOVER_ITEM', 'source_public_id' => $handoverItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $prescription->public_id, $item->public_id, $pharmacist->id, PharmacyFinancialSourceEvent::CHARGE,
                    $quantity, $quantity * $unitAmount, 'HANDOVER_ITEM', $handoverItem->public_id,
                ]),
                'occurred_at' => now(), 'created_at' => now(),
            ]);

            return compact('cashier', 'pharmacist', 'patient', 'encounter', 'medicine', 'lot', 'prescription', 'item', 'handover', 'handoverItem');
        });
    }

    /** @param array<string, mixed> $fixture */
    private function appendAdditionalCharge(array $fixture, int $amount): void
    {
        PharmacyMutationScope::run(function () use ($fixture, $amount): void {
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $fixture['prescription']->id, 'medicine_id' => $fixture['medicine']->id, 'line_number' => 2,
                'medicine_version' => 1, 'medicine_version_public_id' => $fixture['medicine']->public_id,
                'medicine_content_digest' => str_repeat('a', 64), 'medicine_code' => $fixture['medicine']->medicine_code,
                'medicine_name' => $fixture['medicine']->generic_name, 'strength_text' => '500 mg', 'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET', 'dose_text' => '1 tablet', 'route' => 'ORAL',
                'frequency_text' => '1 kali sehari', 'duration_text' => '1 hari',
                'requested_quantity' => 1, 'verified_quantity' => 1, 'sale_value_snapshot' => $amount,
                'instruction' => 'Sesudah makan', 'content_digest' => str_repeat('e', 64), 'created_at' => now()->addMinute(),
            ]);
            $handoverItem = PharmacyHandoverItem::query()->create([
                'handover_id' => $fixture['handover']->id, 'prescription_item_id' => $item->id,
                'stock_lot_id' => $fixture['lot']->id, 'quantity' => 1, 'sale_value_snapshot' => $amount,
                'content_digest' => str_repeat('f', 64), 'created_at' => now()->addMinute(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $fixture['prescription']->id, 'prescription_item_id' => $item->id,
                'actor_user_id' => $fixture['pharmacist']->id, 'event_type' => PharmacyFinancialSourceEvent::CHARGE,
                'quantity' => 1, 'amount' => $amount, 'source_type' => 'HANDOVER_ITEM',
                'source_public_id' => $handoverItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $fixture['prescription']->public_id, $item->public_id, $fixture['pharmacist']->id,
                    PharmacyFinancialSourceEvent::CHARGE, 1, $amount, 'HANDOVER_ITEM', $handoverItem->public_id,
                ]),
                'occurred_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
        });
    }

    /** @param array<string, mixed> $fixture */
    private function appendReversal(array $fixture, int $quantity): void
    {
        PharmacyMutationScope::run(function () use ($fixture, $quantity): void {
            $return = PharmacyReturn::query()->create([
                'handover_id' => $fixture['handover']->id, 'pharmacist_user_id' => $fixture['pharmacist']->id,
                'reason_code' => 'PATIENT_RETURN', 'handover_fingerprint' => str_repeat('c', 64),
                'content_digest' => str_repeat('c', 64), 'returned_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
            $returnItem = PharmacyReturnItem::query()->create([
                'return_id' => $return->id, 'handover_item_id' => $fixture['handoverItem']->id,
                'condition' => PharmacyReturnItem::RETURN_TO_STOCK, 'quantity' => $quantity,
                'content_digest' => str_repeat('d', 64), 'created_at' => now()->addMinute(),
            ]);
            PharmacyFinancialSourceEvent::query()->create([
                'prescription_id' => $fixture['prescription']->id, 'prescription_item_id' => $fixture['item']->id,
                'actor_user_id' => $fixture['pharmacist']->id, 'event_type' => PharmacyFinancialSourceEvent::REVERSAL,
                'quantity' => $quantity, 'amount' => -($quantity * $fixture['item']->sale_value_snapshot),
                'source_type' => 'RETURN_ITEM', 'source_public_id' => $returnItem->public_id,
                'content_digest' => PharmacyCanonicalJson::digest([
                    $fixture['prescription']->public_id, $fixture['item']->public_id, $fixture['pharmacist']->id,
                    PharmacyFinancialSourceEvent::REVERSAL, $quantity,
                    -($quantity * $fixture['item']->sale_value_snapshot), 'RETURN_ITEM', $returnItem->public_id,
                ]),
                'occurred_at' => now()->addMinute(), 'created_at' => now()->addMinute(),
            ]);
        });
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor->fresh();
    }

    private function assertDenied(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected finance denial {$reason}.");
        } catch (FinanceDenied $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
