<?php

namespace App\Support\Finance;

use App\Models\FinanceCashSettlement;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
use App\Models\FinanceSettlementCorrectionOperationReceipt;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class FinanceCashSettlementCorrectionProjection
{
    public function __construct(
        private readonly FinanceCashSettlementCorrectionActorPolicy $policy,
        private readonly FinanceCashSettlementNetPolicy $netPolicy,
        private readonly FinanceCashSettlementCorrectionFingerprint $fingerprints,
    ) {}

    /** @return list<array<string, mixed>> */
    public function worklist(User $actor): array
    {
        $this->policy->view($actor);
        $query = FinanceSettlementCorrectionCase::query()
            ->orderByDesc('requested_at')->orderByDesc('id');
        if ($actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_CASHIER]) {
            $query->where('requesting_cashier_user_id', $actor->id);
        }

        return array_values($query->get()->map(function (FinanceSettlementCorrectionCase $case) use ($actor): array {
            $settlement = FinanceCashSettlement::query()->whereKey($case->settlement_id)->firstOrFail();
            $this->assertVisibility($actor, $settlement);
            $state = $this->netPolicy->correctionState($settlement);
            if (! $state['case'] instanceof FinanceSettlementCorrectionCase || $state['case']->id !== $case->id) {
                throw new FinanceDenied('correction_integrity_failure', 'Perkara koreksi tidak dapat direkonsiliasi.');
            }

            return $this->project($case, $settlement, $state['events'], $state['state'], $actor);
        })->all());
    }

    /** @return array<string, mixed>|null */
    public function caseForSettlement(string $settlementPublicId, User $actor): ?array
    {
        $this->policy->view($actor);
        $settlement = FinanceCashSettlement::query()->where('public_id', $settlementPublicId)->firstOrFail();
        $this->assertVisibility($actor, $settlement);
        $state = $this->netPolicy->correctionState($settlement);

        return $state['case'] instanceof FinanceSettlementCorrectionCase
            ? $this->project($state['case'], $settlement, $state['events'], $state['state'], $actor)
            : null;
    }

    /** @return array<string, mixed> */
    public function case(string $publicId, User $actor): array
    {
        $this->policy->view($actor);
        $case = FinanceSettlementCorrectionCase::query()->where('public_id', $publicId)->firstOrFail();
        $settlement = FinanceCashSettlement::query()->whereKey($case->settlement_id)->firstOrFail();
        $this->assertVisibility($actor, $settlement);
        $state = $this->netPolicy->correctionState($settlement);
        if (! $state['case'] instanceof FinanceSettlementCorrectionCase || $state['case']->id !== $case->id) {
            throw new FinanceDenied('correction_integrity_failure', 'Perkara koreksi tidak dapat direkonsiliasi.');
        }

        return $this->project($case, $settlement, $state['events'], $state['state'], $actor);
    }

    /** @return array<string, mixed> */
    public function refundReceipt(string $publicId, User $actor): array
    {
        $this->policy->viewReceipt($actor);
        $case = FinanceSettlementCorrectionCase::query()->where('public_id', $publicId)->firstOrFail();
        $settlement = FinanceCashSettlement::query()->whereKey($case->settlement_id)->firstOrFail();
        $this->assertVisibility($actor, $settlement);
        $state = $this->netPolicy->correctionState($settlement);
        $approval = $state['events']->firstWhere('event_type', FinanceSettlementCorrectionEvent::REFUND_APPROVED);
        $completion = $state['events']->firstWhere('event_type', FinanceSettlementCorrectionEvent::REFUND_COMPLETED);
        if ($state['state'] !== FinanceCashSettlementNetPolicy::REFUND_COMPLETED
            || ! $approval instanceof FinanceSettlementCorrectionEvent
            || ! $completion instanceof FinanceSettlementCorrectionEvent) {
            throw new FinanceDenied('refund_not_completed', 'Bukti pengembalian hanya tersedia setelah kas dikembalikan.');
        }
        $receipt = FinanceSettlementCorrectionOperationReceipt::query()
            ->where('operation', FinanceCashSettlementCorrectionService::OPERATION_COMPLETE)
            ->where('result_public_id', $completion->public_id)->first();
        if (! $receipt instanceof FinanceSettlementCorrectionOperationReceipt
            || $receipt->correction_case_id !== $case->id
            || $receipt->correction_event_id !== $completion->id
            || ! hash_equals($receipt->case_content_digest, $case->content_digest)
            || ! hash_equals((string) $receipt->event_content_digest, $completion->content_digest)
            || ! hash_equals($receipt->result_digest, $this->fingerprints->result(FinanceCashSettlementCorrectionService::OPERATION_COMPLETE, $case, $completion))) {
            throw new FinanceDenied('receipt_corrupt', 'Bukti operasi pengembalian tidak valid.');
        }

        return [
            'correction_public_id' => $case->public_id,
            'correction_number' => $case->correction_number,
            'original_settlement_public_id' => $settlement->public_id,
            'original_receipt_number' => $case->receipt_number_snapshot,
            'amount' => $completion->amount,
            'reason_code' => $case->reason_code,
            'explanation' => $case->explanation,
            'requesting_cashier_name' => $case->requesting_cashier_name_snapshot,
            'approving_supervisor_name' => $approval->actor_name_snapshot,
            'completion_actor_name' => $completion->actor_name_snapshot,
            'requested_at' => $case->requested_at->toIso8601String(),
            'approved_at' => $approval->occurred_at->toIso8601String(),
            'completed_at' => $completion->occurred_at->toIso8601String(),
            'state' => FinanceSettlementCorrectionEvent::REFUND_COMPLETED,
            'content_digest' => $completion->content_digest,
        ];
    }

    /**
     * @param  iterable<FinanceSettlementCorrectionEvent>  $events
     * @return array<string, mixed>
     */
    private function project(FinanceSettlementCorrectionCase $case, FinanceCashSettlement $settlement, iterable $events, string $state, User $actor): array
    {
        $eventRows = collect($events);

        return [
            'public_id' => $case->public_id,
            'correction_number' => $case->correction_number,
            'settlement_public_id' => $settlement->public_id,
            'receipt_number' => $case->receipt_number_snapshot,
            'amount' => $case->amount,
            'reason_code' => $case->reason_code,
            'explanation' => $case->explanation,
            'requesting_cashier_name' => $case->requesting_cashier_name_snapshot,
            'requester_is_actor' => $case->requesting_cashier_user_id === $actor->id,
            'requested_at' => $case->requested_at->toIso8601String(),
            'state' => $state,
            'fingerprint' => $this->fingerprints->state($case, $eventRows),
            'events' => $eventRows->map(fn (FinanceSettlementCorrectionEvent $event): array => [
                'public_id' => $event->public_id,
                'sequence' => $event->sequence,
                'event_type' => $event->event_type,
                'actor_name' => $event->actor_name_snapshot,
                'explanation' => $event->explanation,
                'amount' => $event->amount,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'content_digest' => $event->content_digest,
            ])->values()->all(),
        ];
    }

    private function assertVisibility(User $actor, FinanceCashSettlement $settlement): void
    {
        if ($actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_CASHIER]
            && $settlement->cashier_user_id !== $actor->id) {
            throw new AuthorizationException;
        }
    }
}
