<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceCashSettlement;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
use App\Models\FinanceSettlementOperationReceipt;
use App\Models\User;
use Illuminate\Support\Collection;

final class FinanceCashSettlementProjection
{
    public function __construct(
        private readonly FinanceCashSettlementActorPolicy $policy,
        private readonly FinanceEvidenceFingerprint $billFingerprints,
        private readonly FinanceCashSettlementFingerprint $settlementFingerprints,
        private readonly FinanceCashSettlementNetPolicy $netPolicy,
        private readonly FinanceCashierCollectionService $collections,
    ) {}

    /** @return array<string, mixed> */
    public function forBill(FinanceBill $bill, User $actor): array
    {
        $this->policy->view($actor);
        $bill->loadMissing(['encounter', 'patient']);
        $version = $bill->current_version > 0
            ? FinanceBillVersion::query()->where('bill_id', $bill->id)->where('version', $bill->current_version)->first()
            : null;
        $priorSettlements = $version === null ? collect() : FinanceCashSettlement::query()
            ->where('bill_id', $bill->id)
            ->where('bill_version_snapshot', '<=', $version->version)
            ->orderBy('bill_version_snapshot')->orderBy('id')->get();
        foreach ($priorSettlements as $prior) {
            if ($prior->bill_id !== $bill->id
                || $prior->bill_public_id_snapshot !== $bill->public_id
                || $prior->bill_version_snapshot > $version->version
                || ! hash_equals($prior->content_digest, $this->settlementFingerprints->settlement($prior))) {
                throw new FinanceDenied('prior_settlement_corrupt', 'Bukti pelunasan sebelumnya tidak dapat direkonsiliasi.');
            }
        }
        $outstandingAmount = $version === null ? null : $version->net_amount - $this->netPolicy->netCollected($priorSettlements);
        $settlement = $version === null ? null : $priorSettlements
            ->where('bill_version_id', $version->id)
            ->filter(fn (FinanceCashSettlement $candidate): bool => $this->netPolicy->correctionState($candidate)['state'] !== FinanceCashSettlementNetPolicy::REFUND_COMPLETED)
            ->last();

        $result = [
            'bill_public_id' => $bill->public_id,
            'bill_number' => $bill->bill_number,
            'bill_state' => $bill->state,
            'bill_fingerprint' => $this->billFingerprints->bill($bill),
            'bill_version_public_id' => $version?->public_id,
            'bill_version' => $version?->version,
            'bill_version_content_digest' => $version?->content_digest,
            'amount' => $outstandingAmount !== null && $outstandingAmount > 0
                ? $outstandingAmount
                : ($settlement->amount ?? $outstandingAmount),
            'payment_method' => FinanceCashSettlement::PAYMENT_CASH,
            'settlement_available' => $version !== null
                && $bill->state === FinanceBill::ISSUED_CURRENT
                && $outstandingAmount > 0
                && $bill->encounter?->status !== Encounter::STATUS_CANCELLED
                && $this->collections->hasOpenBatch($actor),
            'settlement' => null,
        ];
        if ($settlement !== null) {
            $this->policy->viewReceipt($actor);
            $correction = $this->assertSettlementIntegrity($settlement, $version);
            $result['settlement'] = [
                'public_id' => $settlement->public_id,
                'receipt_number' => $settlement->receipt_number,
                'amount' => $settlement->amount,
                'payment_method' => $settlement->payment_method,
                'state' => $settlement->state,
                'settled_at' => $settlement->settled_at->toIso8601String(),
                'content_digest' => $settlement->content_digest,
                'correction_state' => $correction['state'],
                'correction_public_id' => $correction['case']?->public_id,
                'correction_request_available' => $correction['case'] === null
                    && $settlement->cashier_user_id === $actor->id
                    && $this->collections->correctionRequestAvailable($settlement),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function receipt(string $publicId, User $actor): array
    {
        $this->policy->viewReceipt($actor);
        $settlement = FinanceCashSettlement::query()
            ->where('public_id', $publicId)
            ->with(['billVersion.lines'])
            ->firstOrFail();
        $version = $settlement->billVersion;
        if (! $version instanceof FinanceBillVersion) {
            throw new FinanceDenied('settlement_integrity_failure', 'Versi tagihan kuitansi tidak tersedia.');
        }
        $correction = $this->assertSettlementIntegrity($settlement, $version);

        return [
            'public_id' => $settlement->public_id,
            'receipt_number' => $settlement->receipt_number,
            'bill_number' => $settlement->bill_number_snapshot,
            'bill_version' => $settlement->bill_version_snapshot,
            'encounter_public_id' => $settlement->encounter_public_id_snapshot,
            'patient_name' => $settlement->patient_name_snapshot,
            'medical_record_number' => $settlement->medical_record_number_snapshot,
            'care_setting' => $settlement->care_setting,
            'payment_method' => $settlement->payment_method,
            'state' => $settlement->state,
            'amount' => $settlement->amount,
            'settled_at' => $settlement->settled_at->toIso8601String(),
            'cashier_name' => $settlement->cashier_name_snapshot,
            'coverage_profile' => $settlement->coverage_profile_snapshot,
            'coverage_label' => $settlement->coverage_label_snapshot,
            'coverage_exclusion' => $settlement->coverage_exclusion_snapshot,
            'source_domains' => $version->lines->pluck('source_domain')->filter()->unique()->sort()->values()->all(),
            'content_digest' => $settlement->content_digest,
            'correction_state' => $correction['state'],
            'correction_public_id' => $correction['case']?->public_id,
        ];
    }

    /** @return array{state: string, refunded_amount: int, case: FinanceSettlementCorrectionCase|null, events: Collection<int, FinanceSettlementCorrectionEvent>} */
    private function assertSettlementIntegrity(
        FinanceCashSettlement $settlement,
        FinanceBillVersion $version,
    ): array {
        $priorSettlements = FinanceCashSettlement::query()
            ->where('bill_id', $settlement->bill_id)
            ->where(function ($query) use ($settlement): void {
                $query->where('bill_version_snapshot', '<', $settlement->bill_version_snapshot)
                    ->orWhere(function ($query) use ($settlement): void {
                        $query->where('bill_version_snapshot', $settlement->bill_version_snapshot)
                            ->where('id', '<', $settlement->id);
                    });
            })
            ->orderBy('bill_version_snapshot')->orderBy('id')->get();
        foreach ($priorSettlements as $prior) {
            if (! hash_equals($prior->content_digest, $this->settlementFingerprints->settlement($prior))) {
                throw new FinanceDenied('settlement_integrity_failure', 'Bukti pelunasan sebelumnya tidak utuh.');
            }
        }
        $expectedAmount = $settlement->prior_net_collected_amount_snapshot === null
            ? $version->net_amount - (int) $priorSettlements->sum('amount')
            : $version->net_amount - $settlement->prior_net_collected_amount_snapshot;
        $receipt = FinanceSettlementOperationReceipt::query()
            ->where('operation', FinanceCashSettlementService::OPERATION_SETTLE)
            ->where('settlement_public_id', $settlement->public_id)
            ->first();
        if ($settlement->bill_version_id !== $version->id
            || $settlement->bill_version_public_id_snapshot !== $version->public_id
            || $settlement->bill_version_snapshot !== $version->version
            || $settlement->amount !== $expectedAmount
            || ! hash_equals($settlement->source_set_digest, $version->source_set_digest)
            || ! hash_equals($settlement->bill_version_content_digest, $version->content_digest)
            || ! hash_equals($version->content_digest, $this->billFingerprints->version($version, $version->lines))
            || ! hash_equals($settlement->content_digest, $this->settlementFingerprints->settlement($settlement))
            || ! $receipt instanceof FinanceSettlementOperationReceipt
            || $receipt->actor_user_id !== $settlement->cashier_user_id
            || $receipt->settlement_public_id !== $settlement->public_id
            || $receipt->bill_version_public_id !== $settlement->bill_version_public_id_snapshot
            || $receipt->amount !== $settlement->amount
            || ! hash_equals($receipt->source_set_digest, $settlement->source_set_digest)
            || ! hash_equals($receipt->bill_version_content_digest, $settlement->bill_version_content_digest)
            || ! hash_equals($receipt->settlement_content_digest, $settlement->content_digest)
            || ! hash_equals($receipt->result_digest, $this->settlementFingerprints->result($settlement))) {
            throw new FinanceDenied('settlement_integrity_failure', 'Bukti pelunasan atau kuitansi tidak dapat direkonsiliasi.');
        }

        return $this->netPolicy->correctionState($settlement);
    }
}
