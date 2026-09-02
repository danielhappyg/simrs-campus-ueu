<?php

namespace App\Support\Finance;

use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;

final class FinanceCashSettlementCorrectionFingerprint
{
    public function correctionCase(FinanceSettlementCorrectionCase $case): string
    {
        return FinanceCanonicalJson::digest([
            $case->correction_number, $case->settlement_id, $case->bill_id,
            $case->bill_version_id, $case->requesting_cashier_user_id,
            $case->requesting_cashier_name_snapshot, $case->settlement_public_id_snapshot,
            $case->receipt_number_snapshot, $case->amount, $case->settlement_content_digest,
            $case->bill_public_id_snapshot, $case->bill_version_public_id_snapshot,
            $case->bill_version_snapshot, $case->reason_code, $case->explanation,
            $case->requested_at,
        ]);
    }

    public function event(FinanceSettlementCorrectionEvent $event): string
    {
        return FinanceCanonicalJson::digest([
            $event->correction_case_id, $event->sequence, $event->event_type,
            $event->previous_event_digest, $event->actor_user_id,
            $event->actor_name_snapshot, $event->explanation, $event->amount,
            $event->original_settlement_content_digest, $event->approval_event_digest,
            $event->occurred_at,
        ]);
    }

    public function result(
        string $operation,
        FinanceSettlementCorrectionCase $case,
        ?FinanceSettlementCorrectionEvent $event,
    ): string {
        return FinanceCanonicalJson::digest([
            $operation, $case->public_id, $case->content_digest,
            $event?->public_id, $event?->content_digest,
        ]);
    }

    /** @param iterable<FinanceSettlementCorrectionEvent> $events */
    public function state(FinanceSettlementCorrectionCase $case, iterable $events): string
    {
        $digests = [];
        foreach ($events as $event) {
            $digests[] = [$event->sequence, $event->event_type, $event->content_digest];
        }

        return FinanceCanonicalJson::digest([$case->public_id, $case->content_digest, $digests]);
    }
}
