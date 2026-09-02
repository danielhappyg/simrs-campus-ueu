<?php

namespace App\Support\Finance;

use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\FinanceCashierCollectionEvent;
use App\Models\FinanceCashierCollectionMember;
use App\Models\FinanceSettlementCorrectionEvent;

final class FinanceCashierCollectionFingerprint
{
    public function batch(FinanceCashierCollectionBatch $batch): string
    {
        return FinanceCanonicalJson::digest([$batch->batch_number, $batch->cashier_user_id, $batch->cashier_name_snapshot, $batch->opened_at]);
    }

    public function member(FinanceCashierCollectionMember $member): string
    {
        return FinanceCanonicalJson::digest([$member->collection_batch_id, $member->settlement_id, $member->cashier_user_id, $member->cashier_name_snapshot, $member->settlement_public_id_snapshot, $member->receipt_number_snapshot, $member->amount, $member->settlement_content_digest, $member->collected_at]);
    }

    /**
     * @param  iterable<FinanceCashierCollectionMember>  $members
     * @param  iterable<FinanceSettlementCorrectionEvent>  $completedRefunds
     */
    public function membership(iterable $members, iterable $completedRefunds): string
    {
        $memberEvidence = [];
        foreach ($members as $member) {
            $memberEvidence[] = [$member->public_id, $member->content_digest, $member->settlement_public_id_snapshot, $member->settlement_content_digest, $member->amount];
        }
        $refundEvidence = [];
        foreach ($completedRefunds as $refund) {
            $refundEvidence[] = [$refund->public_id, $refund->content_digest, $refund->amount];
        }

        return FinanceCanonicalJson::digest([$memberEvidence, $refundEvidence]);
    }

    public function event(FinanceCashierCollectionEvent $event): string
    {
        return FinanceCanonicalJson::digest([$event->collection_batch_id, $event->sequence, $event->event_type, $event->previous_event_digest, $event->actor_user_id, $event->actor_name_snapshot, $event->membership_count, $event->gross_amount, $event->completed_refund_amount, $event->expected_net_amount, $event->counted_amount, $event->variance_amount, $event->membership_digest, $event->explanation, $event->occurred_at]);
    }

    public function handoff(FinanceCashDepositHandoff $handoff): string
    {
        return FinanceCanonicalJson::digest([$handoff->handoff_number, $handoff->collection_batch_id, $handoff->verified_event_id, $handoff->cashier_user_id, $handoff->supervisor_user_id, $handoff->cashier_name_snapshot, $handoff->supervisor_name_snapshot, $handoff->batch_public_id_snapshot, $handoff->batch_number_snapshot, $handoff->membership_count, $handoff->gross_amount, $handoff->completed_refund_amount, $handoff->expected_net_amount, $handoff->counted_amount, $handoff->batch_content_digest, $handoff->verified_event_digest, $handoff->handed_off_at]);
    }

    /**
     * @param  iterable<FinanceCashierCollectionMember>  $members
     * @param  iterable<FinanceCashierCollectionEvent>  $events
     */
    public function state(FinanceCashierCollectionBatch $batch, iterable $members, iterable $events, ?FinanceCashDepositHandoff $handoff): string
    {
        return FinanceCanonicalJson::digest([$batch->public_id, $batch->content_digest, collect($members)->map(fn ($m) => [$m->public_id, $m->content_digest])->values()->all(), collect($events)->map(fn ($e) => [$e->public_id, $e->content_digest])->values()->all(), $handoff?->public_id, $handoff?->content_digest]);
    }

    public function result(string $operation, FinanceCashierCollectionBatch $batch, ?FinanceCashierCollectionEvent $event, ?FinanceCashDepositHandoff $handoff): string
    {
        return FinanceCanonicalJson::digest([$operation, $batch->public_id, $batch->content_digest, $event?->public_id, $event?->content_digest, $handoff?->public_id, $handoff?->content_digest]);
    }
}
