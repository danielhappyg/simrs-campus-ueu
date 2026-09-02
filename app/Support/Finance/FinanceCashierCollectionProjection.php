<?php

namespace App\Support\Finance;

use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\FinanceCashierCollectionEvent;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class FinanceCashierCollectionProjection
{
    public function __construct(
        private readonly FinanceCashierCollectionActorPolicy $policy,
        private readonly FinanceCashierCollectionFingerprint $fingerprints,
        private readonly FinanceCashierCollectionService $service,
    ) {}

    /** @return list<array<string, mixed>> */
    public function worklist(User $actor): array
    {
        $this->policy->view($actor);
        $query = FinanceCashierCollectionBatch::query()->with(['members', 'events', 'handoff']);
        if ($actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_CASHIER]) {
            $query->where('cashier_user_id', $actor->id);
        }

        return array_values($query->orderByDesc('opened_at')->orderByDesc('id')->get()
            ->map(fn (FinanceCashierCollectionBatch $batch): array => $this->summary($batch))->all());
    }

    /** @return array<string, mixed> */
    public function batch(string $publicId, User $actor): array
    {
        $this->policy->view($actor);
        $batch = FinanceCashierCollectionBatch::query()->where('public_id', $publicId)->with(['members.settlement', 'events', 'handoff'])->firstOrFail();
        if ($actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_CASHIER] && $batch->cashier_user_id !== $actor->id) {
            throw new ModelNotFoundException;
        }

        return $this->summary($batch);
    }

    /** @return array<string, mixed> */
    public function handoffReceipt(string $publicId, User $actor): array
    {
        $this->policy->viewHandoff($actor);
        $handoff = FinanceCashDepositHandoff::query()->where('public_id', $publicId)->with(['batch.events', 'verifiedEvent'])->firstOrFail();
        if ($actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_CASHIER] && $handoff->cashier_user_id !== $actor->id) {
            throw new ModelNotFoundException;
        }
        if ($handoff->batch) {
            $this->service->reconciledEvidence($handoff->batch);
        }
        if (! $handoff->batch || ! $handoff->verifiedEvent
            || $handoff->verifiedEvent->event_type !== FinanceCashierCollectionEvent::CLOSE_VERIFIED
            || $handoff->verified_event_id !== $handoff->verifiedEvent->id
            || $handoff->cashier_user_id !== $handoff->batch->cashier_user_id
            || $handoff->cashier_name_snapshot !== $handoff->batch->cashier_name_snapshot
            || $handoff->supervisor_user_id !== $handoff->verifiedEvent->actor_user_id
            || $handoff->supervisor_name_snapshot !== $handoff->verifiedEvent->actor_name_snapshot
            || $handoff->batch_public_id_snapshot !== $handoff->batch->public_id
            || $handoff->batch_number_snapshot !== $handoff->batch->batch_number
            || $handoff->membership_count !== $handoff->verifiedEvent->membership_count
            || $handoff->gross_amount !== $handoff->verifiedEvent->gross_amount
            || $handoff->completed_refund_amount !== $handoff->verifiedEvent->completed_refund_amount
            || $handoff->expected_net_amount !== $handoff->verifiedEvent->expected_net_amount
            || $handoff->counted_amount !== $handoff->verifiedEvent->counted_amount
            || ! hash_equals($handoff->content_digest, $this->fingerprints->handoff($handoff))
            || ! hash_equals($handoff->batch_content_digest, $handoff->batch->content_digest)
            || ! hash_equals($handoff->verified_event_digest, $handoff->verifiedEvent->content_digest)) {
            throw new FinanceDenied('batch_integrity_failure', 'Bukti Penyerahan Setoran tidak dapat direkonsiliasi.');
        }

        return [
            'public_id' => $handoff->public_id, 'handoff_number' => $handoff->handoff_number,
            'batch_public_id' => $handoff->batch_public_id_snapshot, 'batch_number' => $handoff->batch_number_snapshot,
            'cashier_name' => $handoff->cashier_name_snapshot, 'supervisor_name' => $handoff->supervisor_name_snapshot,
            'membership_count' => $handoff->membership_count, 'gross_amount' => $handoff->gross_amount,
            'completed_refund_amount' => $handoff->completed_refund_amount, 'expected_net_amount' => $handoff->expected_net_amount,
            'counted_amount' => $handoff->counted_amount, 'variance_amount' => 0,
            'opened_at' => $handoff->batch->opened_at->toIso8601String(),
            'frozen_at' => $handoff->batch->events->first()?->occurred_at->toIso8601String(),
            'verified_at' => $handoff->verifiedEvent->occurred_at->toIso8601String(),
            'handed_off_at' => $handoff->handed_off_at->toIso8601String(),
            'batch_content_digest' => $handoff->batch_content_digest,
            'verified_event_digest' => $handoff->verified_event_digest,
            'content_digest' => $handoff->content_digest,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(FinanceCashierCollectionBatch $batch): array
    {
        [$members, $events, , $gross, $refunded] = $this->service->reconciledEvidence($batch);
        $events = $events->sortBy(['sequence', 'id'])->values();
        $batch->load('handoff');
        $latest = $events->last();
        $state = $batch->handoff ? 'HANDED_OFF'
            : ($events->contains(fn (FinanceCashierCollectionEvent $event): bool => $event->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED) ? 'VERIFIED'
                : ($latest ? ($latest->variance_amount === 0 ? 'AWAITING_SUPERVISOR' : 'RECOUNT_REQUIRED') : 'OPEN'));

        return [
            'public_id' => $batch->public_id, 'batch_number' => $batch->batch_number,
            'cashier_name' => $batch->cashier_name_snapshot,
            'opened_at' => $batch->opened_at->toIso8601String(), 'state' => $state,
            'membership_count' => $events->isEmpty() ? $members->count() : $latest->membership_count,
            'gross_amount' => $events->isEmpty() ? $gross : $latest->gross_amount,
            'completed_refund_amount' => $events->isEmpty() ? $refunded : $latest->completed_refund_amount,
            'expected_net_amount' => $events->isEmpty() ? $gross - $refunded : $latest->expected_net_amount,
            'counted_amount' => $latest?->counted_amount, 'variance_amount' => $latest?->variance_amount,
            'frozen_at' => $events->first()?->occurred_at?->toIso8601String(),
            'verified_at' => $events->firstWhere('event_type', FinanceCashierCollectionEvent::CLOSE_VERIFIED)?->occurred_at?->toIso8601String(),
            'handed_off_at' => $batch->handoff?->handed_off_at?->toIso8601String(),
            'content_digest' => $batch->content_digest,
            'state_fingerprint' => $this->fingerprints->state($batch, $members, $events, $batch->handoff),
            'integrity' => ['status' => 'OK', 'message' => null],
            'members' => $members->map(fn ($member): array => [
                'public_id' => $member->public_id, 'settlement_public_id' => $member->settlement_public_id_snapshot,
                'receipt_number' => $member->receipt_number_snapshot, 'amount' => $member->amount,
                'collected_at' => $member->collected_at->toIso8601String(),
                'settlement_content_digest' => $member->settlement_content_digest,
                'content_digest' => $member->content_digest,
            ])->values()->all(),
            'events' => $events->map(fn (FinanceCashierCollectionEvent $event): array => [
                'public_id' => $event->public_id, 'sequence' => $event->sequence, 'event_type' => $event->event_type,
                'actor_name' => $event->actor_name_snapshot, 'membership_count' => $event->membership_count,
                'gross_amount' => $event->gross_amount, 'completed_refund_amount' => $event->completed_refund_amount,
                'expected_net_amount' => $event->expected_net_amount, 'counted_amount' => $event->counted_amount,
                'variance_amount' => $event->variance_amount, 'explanation' => $event->explanation,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'membership_digest' => $event->membership_digest, 'content_digest' => $event->content_digest,
            ])->values()->all(),
            'handoff' => $batch->handoff ? [
                'public_id' => $batch->handoff->public_id, 'handoff_number' => $batch->handoff->handoff_number,
                'handed_off_at' => $batch->handoff->handed_off_at->toIso8601String(),
                'supervisor_name' => $batch->handoff->supervisor_name_snapshot,
                'receipt_url' => '/kasir/batch-penerimaan-kas/penyerahan/'.$batch->handoff->public_id,
                'content_digest' => $batch->handoff->content_digest,
            ] : null,
            'show_url' => '/kasir/batch-penerimaan-kas/'.$batch->public_id,
        ];
    }
}
