<?php

namespace App\Support\Finance;

use App\Models\FinanceCashSettlement;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
use Illuminate\Support\Collection;

final class FinanceCashSettlementNetPolicy
{
    public const ACTIVE = 'ACTIVE';

    public const CORRECTION_REQUESTED = 'CORRECTION_REQUESTED';

    public const REVIEW_REJECTED = FinanceSettlementCorrectionEvent::REVIEW_REJECTED;

    public const REFUND_APPROVED = FinanceSettlementCorrectionEvent::REFUND_APPROVED;

    public const REFUND_COMPLETED = FinanceSettlementCorrectionEvent::REFUND_COMPLETED;

    public function __construct(
        private readonly FinanceCashSettlementFingerprint $settlementFingerprints,
        private readonly FinanceCashSettlementCorrectionFingerprint $correctionFingerprints,
    ) {}

    /** @return array{state: string, refunded_amount: int, case: FinanceSettlementCorrectionCase|null, events: Collection<int, FinanceSettlementCorrectionEvent>} */
    public function correctionState(FinanceCashSettlement $settlement, bool $lock = false): array
    {
        if (! hash_equals($settlement->content_digest, $this->settlementFingerprints->settlement($settlement))) {
            throw new FinanceDenied('settlement_integrity_failure', 'Bukti pelunasan tidak utuh.');
        }

        $caseQuery = FinanceSettlementCorrectionCase::query()->where('settlement_id', $settlement->id);
        if ($lock) {
            $caseQuery->lockForUpdate();
        }
        $case = $caseQuery->first();
        if (! $case instanceof FinanceSettlementCorrectionCase) {
            return ['state' => self::ACTIVE, 'refunded_amount' => 0, 'case' => null, 'events' => collect()];
        }

        if ($case->settlement_id !== $settlement->id
            || $case->bill_id !== $settlement->bill_id
            || $case->bill_version_id !== $settlement->bill_version_id
            || $case->requesting_cashier_user_id !== $settlement->cashier_user_id
            || $case->settlement_public_id_snapshot !== $settlement->public_id
            || $case->receipt_number_snapshot !== $settlement->receipt_number
            || $case->amount !== $settlement->amount
            || $case->bill_public_id_snapshot !== $settlement->bill_public_id_snapshot
            || $case->bill_version_public_id_snapshot !== $settlement->bill_version_public_id_snapshot
            || $case->bill_version_snapshot !== $settlement->bill_version_snapshot
            || ! in_array($case->reason_code, FinanceSettlementCorrectionCase::REASON_CODES, true)
            || mb_strlen($case->explanation) < 8
            || mb_strlen($case->explanation) > 500
            || ! hash_equals($case->settlement_content_digest, $settlement->content_digest)
            || ! hash_equals($case->content_digest, $this->correctionFingerprints->correctionCase($case))) {
            throw new FinanceDenied('correction_integrity_failure', 'Bukti koreksi pelunasan tidak dapat direkonsiliasi.');
        }

        $eventsQuery = FinanceSettlementCorrectionEvent::query()
            ->where('correction_case_id', $case->id)->orderBy('sequence')->orderBy('id');
        if ($lock) {
            $eventsQuery->lockForUpdate();
        }
        $events = $eventsQuery->get();
        $previous = null;
        foreach ($events as $index => $event) {
            if ($event->sequence !== $index + 1
                || $event->actor_user_id === $case->requesting_cashier_user_id
                || ! hash_equals($event->original_settlement_content_digest, $settlement->content_digest)
                || ($previous === null ? $event->previous_event_digest !== null : ! hash_equals($previous->content_digest, (string) $event->previous_event_digest))
                || ! hash_equals($event->content_digest, $this->correctionFingerprints->event($event))) {
                throw new FinanceDenied('correction_integrity_failure', 'Rantai peristiwa koreksi pelunasan tidak utuh.');
            }
            $previous = $event;
        }

        $first = $events->get(0);
        $second = $events->get(1);
        if ($events->isEmpty()) {
            $state = self::CORRECTION_REQUESTED;
        } elseif ($events->count() === 1 && $first?->event_type === self::REVIEW_REJECTED
            && $first->amount === null && $first->approval_event_digest === null) {
            $state = self::REVIEW_REJECTED;
        } elseif ($events->count() === 1 && $first?->event_type === self::REFUND_APPROVED
            && $first->amount === $settlement->amount && $first->approval_event_digest === null) {
            $state = self::REFUND_APPROVED;
        } elseif ($events->count() === 2 && $first?->event_type === self::REFUND_APPROVED
            && $second?->event_type === self::REFUND_COMPLETED
            && $first->amount === $settlement->amount
            && $second->amount === $settlement->amount
            && is_string($second->approval_event_digest)
            && hash_equals($first->content_digest, $second->approval_event_digest)) {
            $state = self::REFUND_COMPLETED;
        } else {
            throw new FinanceDenied('correction_integrity_failure', 'Transisi koreksi pelunasan tidak valid.');
        }

        return [
            'state' => $state,
            'refunded_amount' => $state === self::REFUND_COMPLETED ? $settlement->amount : 0,
            'case' => $case,
            'events' => $events,
        ];
    }

    /** @param Collection<int, FinanceCashSettlement> $settlements */
    public function netCollected(Collection $settlements, bool $lock = false): int
    {
        $net = 0;
        foreach ($settlements as $settlement) {
            $state = $this->correctionState($settlement, $lock);
            $net += $settlement->amount - $state['refunded_amount'];
        }

        return $net;
    }
}
