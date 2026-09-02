<?php

namespace App\Support\Finance;

use App\Models\FinanceBill;
use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionActiveSlot;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\FinanceCashierCollectionEvent;
use App\Models\FinanceCashierCollectionMember;
use App\Models\FinanceCashierCollectionOperationReceipt;
use App\Models\FinanceCashSettlement;
use App\Models\FinanceSettlementCorrectionEvent;
use App\Models\FinanceSettlementOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceCashierCollectionService
{
    public const OPERATION_OPEN = 'FINANCE_CASHIER_COLLECTION_OPEN';

    public const OPERATION_CLOSE = 'FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST';

    public const OPERATION_RECOUNT = 'FINANCE_CASHIER_COLLECTION_RECOUNT';

    public const OPERATION_VERIFY = 'FINANCE_CASHIER_COLLECTION_VERIFY';

    public const OPERATION_HANDOFF = 'FINANCE_CASH_DEPOSIT_HANDOFF_CREATE';

    public function __construct(
        private readonly FinanceCashierCollectionActorPolicy $policy,
        private readonly FinanceCashierCollectionFingerprint $fingerprints,
        private readonly FinanceCashSettlementFingerprint $settlementFingerprints,
        private readonly FinanceCashSettlementNetPolicy $netPolicy,
        private readonly AuditRecorder $audit,
    ) {}

    public function open(User $actor, string $idempotencyKey): FinanceMutationResult
    {
        $this->authorize(fn () => $this->policy->open($actor), $actor, null, self::OPERATION_OPEN);
        $key = $this->key($idempotencyKey, $actor, null, self::OPERATION_OPEN);
        $payload = FinanceCanonicalJson::digest([self::OPERATION_OPEN, $actor->id, 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1']);

        return $this->mutate($actor, null, self::OPERATION_OPEN, $key, $payload, function () use ($actor, $key, $payload): FinanceMutationResult {
            if ($replay = $this->replay($actor, self::OPERATION_OPEN, $key, $payload)) {
                return $replay;
            }
            if (FinanceCashierCollectionActiveSlot::query()->whereKey($actor->id)->lockForUpdate()->exists()) {
                throw new FinanceDenied('active_batch_exists', 'Kasir masih memiliki batch penerimaan kas aktif.');
            }
            $now = now();
            $publicId = (string) Str::ulid();
            $batch = new FinanceCashierCollectionBatch([
                'batch_number' => 'BPK-'.$now->format('Ymd').'-'.$publicId,
                'cashier_user_id' => $actor->id,
                'cashier_name_snapshot' => $actor->name,
                'content_digest' => str_repeat('0', 64),
                'opened_at' => $now,
                'created_at' => $now,
            ]);
            $batch->public_id = $publicId;
            $batch->content_digest = $this->fingerprints->batch($batch);
            $batch->save();
            FinanceCashierCollectionActiveSlot::query()->create(['cashier_user_id' => $actor->id, 'collection_batch_id' => $batch->id, 'created_at' => $now]);
            $this->success($actor, $batch, self::OPERATION_OPEN, 'OPEN', 0, null, null);
            $this->receipt($actor, $batch, null, null, self::OPERATION_OPEN, $key, $payload);

            return new FinanceMutationResult($batch, false);
        });
    }

    public function requestClose(string $batchPublicId, User $actor, int $countedAmount, string $expectedFingerprint, string $idempotencyKey): FinanceMutationResult
    {
        $this->authorize(fn () => $this->policy->closeRequest($actor), $actor, $batchPublicId, self::OPERATION_CLOSE);

        return $this->eventMutation($batchPublicId, $actor, $countedAmount, $expectedFingerprint, $idempotencyKey, self::OPERATION_CLOSE, FinanceCashierCollectionEvent::CLOSE_REQUESTED, function (FinanceCashierCollectionBatch $batch, Collection $events): void {
            if ($events->isNotEmpty()) {
                throw new FinanceDenied('batch_not_open', 'Batch penerimaan kas sudah dibekukan.');
            }
        }, releaseSlot: true);
    }

    public function recount(string $batchPublicId, User $actor, int $countedAmount, string $expectedFingerprint, string $idempotencyKey, string $explanation): FinanceMutationResult
    {
        $this->authorize(fn () => $this->policy->recount($actor), $actor, $batchPublicId, self::OPERATION_RECOUNT);
        $explanation = preg_replace('/\s+/u', ' ', trim($explanation)) ?? '';
        if (mb_strlen($explanation) < 8 || mb_strlen($explanation) > 500) {
            throw $this->deny($actor, $batchPublicId, self::OPERATION_RECOUNT, 'validation_failed', 'Penjelasan hitung ulang harus 8 sampai 500 karakter.');
        }

        return $this->eventMutation($batchPublicId, $actor, $countedAmount, $expectedFingerprint, $idempotencyKey, self::OPERATION_RECOUNT, FinanceCashierCollectionEvent::RECOUNT_SUBMITTED, function (FinanceCashierCollectionBatch $batch, Collection $events): void {
            if ($events->isEmpty() || $events->contains(fn (FinanceCashierCollectionEvent $event): bool => $event->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED)) {
                throw new FinanceDenied('batch_not_recountable', 'Batch tidak dapat dihitung ulang pada keadaan ini.');
            }
        }, explanation: $explanation);
    }

    public function verify(string $batchPublicId, User $actor, string $expectedFingerprint, string $idempotencyKey): FinanceMutationResult
    {
        $this->authorize(fn () => $this->policy->verify($actor), $actor, $batchPublicId, self::OPERATION_VERIFY);
        $candidate = FinanceCashierCollectionBatch::query()->where('public_id', $batchPublicId)->firstOrFail();
        $latest = FinanceCashierCollectionEvent::query()->where('collection_batch_id', $candidate->id)->latest('sequence')->first();
        $counted = $latest instanceof FinanceCashierCollectionEvent ? $latest->counted_amount : -1;

        return $this->eventMutation($batchPublicId, $actor, $counted, $expectedFingerprint, $idempotencyKey, self::OPERATION_VERIFY, FinanceCashierCollectionEvent::CLOSE_VERIFIED, function (FinanceCashierCollectionBatch $batch, Collection $events) use ($actor): void {
            if ($batch->cashier_user_id === $actor->id) {
                throw new FinanceDenied('same_actor_separation', 'Supervisor verifikasi harus berbeda dari kasir pemilik batch.');
            }
            $latest = $events->last();
            if (! $latest || $events->contains(fn (FinanceCashierCollectionEvent $event): bool => $event->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED)) {
                throw new FinanceDenied('batch_not_verifiable', 'Batch tidak dapat diverifikasi pada keadaan ini.');
            }
            if ($latest->variance_amount !== 0) {
                throw new FinanceDenied('batch_variance_nonzero', 'Selisih kas harus nol sebelum verifikasi.');
            }
        });
    }

    public function createHandoff(string $batchPublicId, User $actor, string $expectedFingerprint, string $idempotencyKey): FinanceMutationResult
    {
        $this->authorize(fn () => $this->policy->createHandoff($actor), $actor, $batchPublicId, self::OPERATION_HANDOFF);
        $key = $this->key($idempotencyKey, $actor, $batchPublicId, self::OPERATION_HANDOFF);
        $this->digest($expectedFingerprint, $actor, $batchPublicId, self::OPERATION_HANDOFF);
        $payload = FinanceCanonicalJson::digest([self::OPERATION_HANDOFF, $batchPublicId, $expectedFingerprint]);
        $candidate = FinanceCashierCollectionBatch::query()->where('public_id', $batchPublicId)->firstOrFail();

        return $this->mutate($actor, $batchPublicId, self::OPERATION_HANDOFF, $key, $payload, function () use ($candidate, $actor, $key, $payload, $expectedFingerprint): FinanceMutationResult {
            $batch = FinanceCashierCollectionBatch::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($replay = $this->replay($actor, self::OPERATION_HANDOFF, $key, $payload)) {
                return $replay;
            }
            $this->owned($batch, $actor);
            [$members, $events] = $this->reconciledEvidence($batch, true);
            $this->assertStateFingerprint($batch, $members, $events, $expectedFingerprint);
            $verified = $events->firstWhere('event_type', FinanceCashierCollectionEvent::CLOSE_VERIFIED);
            if (! $verified instanceof FinanceCashierCollectionEvent || $verified->variance_amount !== 0) {
                throw new FinanceDenied('batch_not_verified', 'Batch belum diverifikasi dengan selisih nol.');
            }
            if (FinanceCashDepositHandoff::query()->where('collection_batch_id', $batch->id)->lockForUpdate()->exists()) {
                throw new FinanceDenied('handoff_already_exists', 'Bukti penyerahan setoran sudah dibuat.');
            }
            $now = now();
            $handoff = new FinanceCashDepositHandoff([
                'handoff_number' => 'SST-'.$now->format('Ymd').'-'.Str::ulid(),
                'collection_batch_id' => $batch->id,
                'verified_event_id' => $verified->id,
                'cashier_user_id' => $batch->cashier_user_id,
                'supervisor_user_id' => $verified->actor_user_id,
                'cashier_name_snapshot' => $batch->cashier_name_snapshot,
                'supervisor_name_snapshot' => $verified->actor_name_snapshot,
                'batch_public_id_snapshot' => $batch->public_id,
                'batch_number_snapshot' => $batch->batch_number,
                'membership_count' => $verified->membership_count,
                'gross_amount' => $verified->gross_amount,
                'completed_refund_amount' => $verified->completed_refund_amount,
                'expected_net_amount' => $verified->expected_net_amount,
                'counted_amount' => $verified->counted_amount,
                'batch_content_digest' => $batch->content_digest,
                'verified_event_digest' => $verified->content_digest,
                'content_digest' => str_repeat('0', 64),
                'handed_off_at' => $now,
                'created_at' => $now,
            ]);
            $handoff->public_id = (string) Str::ulid();
            $handoff->content_digest = $this->fingerprints->handoff($handoff);
            $handoff->save();
            $this->success($actor, $batch, self::OPERATION_HANDOFF, 'DEPOSIT_HANDOFF_CREATED', $verified->sequence, $verified, $handoff);
            $this->receipt($actor, $batch, $verified, $handoff, self::OPERATION_HANDOFF, $key, $payload);

            return new FinanceMutationResult($handoff, false);
        });
    }

    public function requireOpenBatch(User $actor): FinanceCashierCollectionBatch
    {
        $slot = FinanceCashierCollectionActiveSlot::query()->whereKey($actor->id)->lockForUpdate()->first();
        if (! $slot) {
            throw new FinanceDenied('cashier_collection_batch_required', 'Buka Batch Penerimaan Kas sebelum menerima pelunasan.');
        }
        $batch = FinanceCashierCollectionBatch::query()->whereKey($slot->collection_batch_id)->lockForUpdate()->first();
        if (! $batch || $batch->cashier_user_id !== $actor->id || FinanceCashierCollectionEvent::query()->where('collection_batch_id', $batch->id)->exists()
            || ! hash_equals($batch->content_digest, $this->fingerprints->batch($batch))) {
            throw new FinanceDenied('cashier_collection_batch_not_open', 'Batch Penerimaan Kas tidak valid atau sudah dibekukan.');
        }

        return $batch;
    }

    public function bindSettlement(FinanceCashierCollectionBatch $batch, FinanceCashSettlement $settlement, User $actor): FinanceCashierCollectionMember
    {
        if ($batch->cashier_user_id !== $actor->id || $settlement->cashier_user_id !== $actor->id
            || ! hash_equals($settlement->content_digest, $this->settlementFingerprints->settlement($settlement))) {
            throw new FinanceDenied('batch_integrity_failure', 'Pelunasan tidak dapat diikat ke batch kas.');
        }
        $member = new FinanceCashierCollectionMember([
            'collection_batch_id' => $batch->id, 'settlement_id' => $settlement->id,
            'cashier_user_id' => $actor->id, 'cashier_name_snapshot' => $actor->name,
            'settlement_public_id_snapshot' => $settlement->public_id,
            'receipt_number_snapshot' => $settlement->receipt_number, 'amount' => $settlement->amount,
            'settlement_content_digest' => $settlement->content_digest, 'content_digest' => str_repeat('0', 64),
            'collected_at' => $settlement->settled_at, 'created_at' => now(),
        ]);
        $member->public_id = (string) Str::ulid();
        $member->content_digest = $this->fingerprints->member($member);
        $member->save();

        return $member;
    }

    public function assertSettlementCorrectionAllowed(FinanceCashSettlement $settlement): void
    {
        $memberCandidate = FinanceCashierCollectionMember::query()->where('settlement_id', $settlement->id)->first();
        if (! $memberCandidate) {
            if ($settlement->collection_binding_required) {
                throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch wajib untuk pelunasan ini tidak tersedia.');
            }

            return; // Truthful legacy evidence predating batch activation.
        }
        $batchCandidate = FinanceCashierCollectionBatch::query()->whereKey($memberCandidate->collection_batch_id)->first();
        if (! $batchCandidate) {
            throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch pelunasan tidak utuh.');
        }
        $slot = FinanceCashierCollectionActiveSlot::query()->whereKey($batchCandidate->cashier_user_id)->lockForUpdate()->first();
        $batch = FinanceCashierCollectionBatch::query()->whereKey($batchCandidate->id)->lockForUpdate()->first();
        $member = FinanceCashierCollectionMember::query()->whereKey($memberCandidate->id)->lockForUpdate()->first();
        if (! $batch || ! $member || $member->collection_batch_id !== $batch->id) {
            throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch pelunasan tidak utuh.');
        }
        $this->assertSettlementBindingIntegrity($settlement, $member, $batch, false);
        if (FinanceCashierCollectionEvent::query()->where('collection_batch_id', $batch->id)->lockForUpdate()->exists()) {
            if (FinanceCashDepositHandoff::query()->where('collection_batch_id', $batch->id)->lockForUpdate()->exists()) {
                throw new FinanceDenied('cash_handoff_exists', 'Pelunasan sudah termasuk Bukti Penyerahan Setoran.');
            }
            throw new FinanceDenied('batch_frozen', 'Batch kas sudah dibekukan; koreksi memerlukan alur penyesuaian terpisah.');
        }
        if (! $slot || $slot->collection_batch_id !== $batch->id) {
            throw new FinanceDenied('batch_integrity_failure', 'Slot batch aktif pelunasan tidak dapat direkonsiliasi.');
        }
    }

    public function hasOpenBatch(User $actor): bool
    {
        $slot = FinanceCashierCollectionActiveSlot::query()->whereKey($actor->id)->first();
        if (! $slot) {
            return false;
        }
        $batch = FinanceCashierCollectionBatch::query()->whereKey($slot->collection_batch_id)->first();
        if (! $batch || $batch->cashier_user_id !== $actor->id) {
            throw new FinanceDenied('batch_integrity_failure', 'Slot batch aktif tidak dapat direkonsiliasi.');
        }
        [, $events] = $this->reconciledEvidence($batch);

        return $events->isEmpty();
    }

    public function correctionRequestAvailable(FinanceCashSettlement $settlement): bool
    {
        $member = FinanceCashierCollectionMember::query()->where('settlement_id', $settlement->id)->first();
        if (! $member) {
            if ($settlement->collection_binding_required) {
                throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch wajib untuk pelunasan ini tidak tersedia.');
            }

            return true;
        }
        $batch = FinanceCashierCollectionBatch::query()->whereKey($member->collection_batch_id)->first();
        if (! $batch) {
            throw new FinanceDenied('batch_integrity_failure', 'Batch pelunasan tidak tersedia.');
        }
        $this->assertSettlementBindingIntegrity($settlement, $member, $batch);
        [, $events] = $this->reconciledEvidence($batch);

        return $events->isEmpty();
    }

    public function assertRequiredSettlementBinding(FinanceCashSettlement $settlement): void
    {
        $members = FinanceCashierCollectionMember::query()->where('settlement_id', $settlement->id)->get();
        if ($members->isEmpty() && ! $settlement->collection_binding_required) {
            return;
        }
        $member = $members->first();
        $batch = $member instanceof FinanceCashierCollectionMember
            ? FinanceCashierCollectionBatch::query()->whereKey($member->collection_batch_id)->first()
            : null;
        if ($members->count() !== 1 || ! $member instanceof FinanceCashierCollectionMember || ! $batch instanceof FinanceCashierCollectionBatch) {
            throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch wajib tidak dapat direkonsiliasi.');
        }
        $this->assertSettlementBindingIntegrity($settlement, $member, $batch);
    }

    private function eventMutation(string $publicId, User $actor, int $counted, string $expectedFingerprint, string $idempotencyKey, string $operation, string $eventType, callable $eligibility, bool $releaseSlot = false, ?string $explanation = null): FinanceMutationResult
    {
        if ($counted < 0) {
            throw $this->deny($actor, $publicId, $operation, 'validation_failed', 'Kas fisik terhitung tidak valid.');
        }
        $this->digest($expectedFingerprint, $actor, $publicId, $operation);
        $key = $this->key($idempotencyKey, $actor, $publicId, $operation);
        $payload = FinanceCanonicalJson::digest([$operation, $publicId, $counted, $expectedFingerprint, $explanation]);
        $candidate = FinanceCashierCollectionBatch::query()->where('public_id', $publicId)->firstOrFail();

        return $this->mutate($actor, $publicId, $operation, $key, $payload, function () use ($candidate, $actor, $counted, $expectedFingerprint, $operation, $eventType, $eligibility, $releaseSlot, $explanation, $key, $payload): FinanceMutationResult {
            $slot = null;
            if ($releaseSlot) {
                $slot = FinanceCashierCollectionActiveSlot::query()->whereKey($candidate->cashier_user_id)->lockForUpdate()->first();
                if (! $slot || $slot->collection_batch_id !== $candidate->id) {
                    throw new FinanceDenied('batch_not_open', 'Batch penerimaan kas tidak lagi aktif.');
                }
            }
            $batch = FinanceCashierCollectionBatch::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($replay = $this->replay($actor, $operation, $key, $payload)) {
                return $replay;
            }
            if ($operation !== self::OPERATION_VERIFY) {
                $this->owned($batch, $actor);
            }
            [$members, $events, $refundEvents, $gross, $refunded] = $this->reconciledEvidence(
                $batch,
                true,
                $eventType === FinanceCashierCollectionEvent::CLOSE_REQUESTED,
            );
            $this->assertStateFingerprint($batch, $members, $events, $expectedFingerprint);
            $eligibility($batch, $events);
            $sequence = $events->count() + 1;
            $previous = $events->last();
            $event = new FinanceCashierCollectionEvent([
                'collection_batch_id' => $batch->id, 'sequence' => $sequence, 'event_type' => $eventType,
                'previous_event_digest' => $previous?->content_digest, 'actor_user_id' => $actor->id,
                'actor_name_snapshot' => $actor->name, 'membership_count' => $members->count(),
                'gross_amount' => $gross, 'completed_refund_amount' => $refunded,
                'expected_net_amount' => $gross - $refunded, 'counted_amount' => $counted,
                'variance_amount' => $counted - ($gross - $refunded),
                'membership_digest' => $this->fingerprints->membership($members, $refundEvents),
                'explanation' => $explanation, 'content_digest' => str_repeat('0', 64),
                'occurred_at' => now(), 'created_at' => now(),
            ]);
            $event->public_id = (string) Str::ulid();
            $event->content_digest = $this->fingerprints->event($event);
            $event->save();
            if ($releaseSlot) {
                $slot->delete();
            }
            $state = $eventType === FinanceCashierCollectionEvent::CLOSE_VERIFIED ? 'CLOSE_VERIFIED' : $eventType;
            $this->success($actor, $batch, $operation, $state, $sequence, $event, null);
            $this->receipt($actor, $batch, $event, null, $operation, $key, $payload);

            return new FinanceMutationResult($event, false);
        });
    }

    /** @return array{0: Collection<int, FinanceCashierCollectionMember>, 1: Collection<int, FinanceCashierCollectionEvent>, 2: Collection<int, FinanceSettlementCorrectionEvent>, 3: int, 4: int} */
    public function reconciledEvidence(FinanceCashierCollectionBatch $batch, bool $lock = false, bool $rejectPending = false): array
    {
        if (! hash_equals($batch->content_digest, $this->fingerprints->batch($batch))) {
            throw new FinanceDenied('batch_integrity_failure', 'Header batch penerimaan kas tidak utuh.');
        }
        $memberQuery = FinanceCashierCollectionMember::query()->where('collection_batch_id', $batch->id)->orderBy('id');
        $eventQuery = FinanceCashierCollectionEvent::query()->where('collection_batch_id', $batch->id)->orderBy('sequence')->orderBy('id');
        $members = $memberQuery->get();
        $events = $lock ? collect() : $eventQuery->get();
        $slots = FinanceCashierCollectionActiveSlot::query()->where('collection_batch_id', $batch->id)->get();
        $refundEvents = collect();
        $gross = 0;
        $refunded = 0;
        foreach ($members as $member) {
            $settlementQuery = FinanceCashSettlement::query()->whereKey($member->settlement_id);
            if ($lock) {
                $settlementQuery->lockForUpdate();
            }
            $settlement = $settlementQuery->first();
            if (! $settlement) {
                throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch penerimaan kas tidak dapat direkonsiliasi.');
            }
            $this->assertSettlementBindingIntegrity($settlement, $member, $batch);
            $state = $this->netPolicy->correctionState($settlement, $lock);
            if ($rejectPending && in_array($state['state'], [FinanceCashSettlementNetPolicy::CORRECTION_REQUESTED, FinanceCashSettlementNetPolicy::REFUND_APPROVED], true)) {
                throw new FinanceDenied('pending_cash_correction', 'Selesaikan perkara koreksi sebelum mengajukan tutup batch.');
            }
            $gross += $settlement->amount;
            if ($state['state'] === FinanceCashSettlementNetPolicy::REFUND_COMPLETED) {
                $refunded += $settlement->amount;
                $refundEvents->push($state['events']->last());
            }
        }
        if ($lock) {
            // The batch coordination row is already locked by the caller, so
            // membership cannot legitimately change. Lock immutable settlement
            // and correction evidence first (above), then members and events to
            // preserve the authorized canonical finance lock chain exactly.
            $lockedMembers = $memberQuery->lockForUpdate()->get();
            if ($members->pluck('id')->all() !== $lockedMembers->pluck('id')->all()
                || $members->pluck('content_digest')->all() !== $lockedMembers->pluck('content_digest')->all()) {
                throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch berubah saat rekonsiliasi.');
            }
            $members = $lockedMembers;
            $events = $eventQuery->lockForUpdate()->get();
        }
        if (($events->isEmpty() && ($slots->count() !== 1 || $slots->first()?->cashier_user_id !== $batch->cashier_user_id))
            || ($events->isNotEmpty() && $slots->isNotEmpty())) {
            throw new FinanceDenied('batch_integrity_failure', 'Slot aktif batch penerimaan kas tidak dapat direkonsiliasi.');
        }
        $previous = null;
        foreach ($events as $index => $event) {
            if ($event->sequence !== $index + 1 || ($previous === null ? $event->previous_event_digest !== null : ! hash_equals($previous->content_digest, (string) $event->previous_event_digest))
                || ($event->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED
                    ? $event->actor_user_id === $batch->cashier_user_id
                    : $event->actor_user_id !== $batch->cashier_user_id)
                || ! in_array($event->event_type, $index === 0
                    ? [FinanceCashierCollectionEvent::CLOSE_REQUESTED]
                    : [FinanceCashierCollectionEvent::RECOUNT_SUBMITTED, FinanceCashierCollectionEvent::CLOSE_VERIFIED], true)
                || $event->membership_count !== $members->count()
                || $event->gross_amount !== $gross
                || $event->completed_refund_amount !== $refunded
                || $event->expected_net_amount !== $gross - $refunded
                || $event->variance_amount !== $event->counted_amount - $event->expected_net_amount
                || ! hash_equals($event->membership_digest, $this->fingerprints->membership($members, $refundEvents))
                || ! hash_equals($event->content_digest, $this->fingerprints->event($event))) {
                throw new FinanceDenied('batch_integrity_failure', 'Rantai peristiwa batch penerimaan kas tidak utuh.');
            }
            $previous = $event;
        }
        $verified = $events->where('event_type', FinanceCashierCollectionEvent::CLOSE_VERIFIED);
        if ($verified->count() > 1 || ($verified->count() === 1
            && ($events->last()?->id !== $verified->first()?->id || $verified->first()?->variance_amount !== 0))) {
            throw new FinanceDenied('batch_integrity_failure', 'Verifikasi batch harus unik, terakhir, dan berselisih nol.');
        }

        $handoff = FinanceCashDepositHandoff::query()->where('collection_batch_id', $batch->id)->first();
        if ($handoff) {
            $verified = $events->firstWhere('event_type', FinanceCashierCollectionEvent::CLOSE_VERIFIED);
            if (! $verified instanceof FinanceCashierCollectionEvent
                || $events->last()?->id !== $verified->id
                || $handoff->verified_event_id !== $verified->id
                || $handoff->cashier_user_id !== $batch->cashier_user_id
                || $handoff->supervisor_user_id !== $verified->actor_user_id
                || $handoff->cashier_name_snapshot !== $batch->cashier_name_snapshot
                || $handoff->supervisor_name_snapshot !== $verified->actor_name_snapshot
                || $handoff->batch_public_id_snapshot !== $batch->public_id
                || $handoff->batch_number_snapshot !== $batch->batch_number
                || $handoff->membership_count !== $verified->membership_count
                || $handoff->gross_amount !== $verified->gross_amount
                || $handoff->completed_refund_amount !== $verified->completed_refund_amount
                || $handoff->expected_net_amount !== $verified->expected_net_amount
                || $handoff->counted_amount !== $verified->counted_amount
                || ! hash_equals($handoff->batch_content_digest, $batch->content_digest)
                || ! hash_equals($handoff->verified_event_digest, $verified->content_digest)
                || ! hash_equals($handoff->content_digest, $this->fingerprints->handoff($handoff))) {
                throw new FinanceDenied('batch_integrity_failure', 'Bukti Penyerahan Setoran tidak dapat direkonsiliasi.');
            }
        }
        $this->assertRetainedOperation($batch, null, null, self::OPERATION_OPEN, 'OPEN', 0, $batch->cashier_user_id);
        foreach ($events as $event) {
            [$operation, $state] = match ($event->event_type) {
                FinanceCashierCollectionEvent::CLOSE_REQUESTED => [self::OPERATION_CLOSE, 'CLOSE_REQUESTED'],
                FinanceCashierCollectionEvent::RECOUNT_SUBMITTED => [self::OPERATION_RECOUNT, 'RECOUNT_SUBMITTED'],
                FinanceCashierCollectionEvent::CLOSE_VERIFIED => [self::OPERATION_VERIFY, 'CLOSE_VERIFIED'],
                default => ['', ''],
            };
            if ($operation === '') {
                throw new FinanceDenied('batch_integrity_failure', 'Jenis peristiwa batch tidak dikenali.');
            }
            $this->assertRetainedOperation($batch, $event, null, $operation, $state, $event->sequence, $event->actor_user_id);
        }
        if ($handoff) {
            $verified = $events->firstWhere('event_type', FinanceCashierCollectionEvent::CLOSE_VERIFIED);
            if (! $verified instanceof FinanceCashierCollectionEvent) {
                throw new FinanceDenied('batch_integrity_failure', 'Peristiwa verifikasi batch tidak tersedia.');
            }
            $this->assertRetainedOperation($batch, $verified, $handoff, self::OPERATION_HANDOFF, 'DEPOSIT_HANDOFF_CREATED', $verified->sequence, $batch->cashier_user_id);
        }

        return [$members, $events, $refundEvents, $gross, $refunded];
    }

    private function assertRetainedOperation(FinanceCashierCollectionBatch $batch, ?FinanceCashierCollectionEvent $event, ?FinanceCashDepositHandoff $handoff, string $operation, string $state, int $version, int $actorUserId): void
    {
        $result = $handoff ?? $event ?? $batch;
        $receipts = FinanceCashierCollectionOperationReceipt::query()->where('operation', $operation)->where('result_public_id', $result->public_id)->get();
        $receipt = $receipts->first();
        $expectedType = $handoff ? FinanceCashierCollectionOperationReceipt::RESULT_HANDOFF : ($event ? FinanceCashierCollectionOperationReceipt::RESULT_EVENT : FinanceCashierCollectionOperationReceipt::RESULT_BATCH);
        if ($receipts->count() !== 1 || ! $receipt instanceof FinanceCashierCollectionOperationReceipt
            || $receipt->actor_user_id !== $actorUserId || $receipt->collection_batch_id !== $batch->id
            || $receipt->collection_event_id !== $event?->id || $receipt->deposit_handoff_id !== $handoff?->id
            || $receipt->result_type !== $expectedType || ! hash_equals($receipt->batch_content_digest, $batch->content_digest)
            || ($event ? ! hash_equals((string) $receipt->event_content_digest, $event->content_digest) : $receipt->event_content_digest !== null)
            || ($handoff ? ! hash_equals((string) $receipt->handoff_content_digest, $handoff->content_digest) : $receipt->handoff_content_digest !== null)
            || ! hash_equals($receipt->result_digest, $this->fingerprints->result($operation, $batch, $event, $handoff))) {
            throw new FinanceDenied('batch_integrity_failure', 'Bukti operasi batch penerimaan kas tidak utuh.');
        }
        $audits = AuditEvent::query()->where('action', 'finance.workflow.mutate')->where('outcome', 'SUCCESS')
            ->where('resource_id', $batch->public_id)->where('actor_user_id', $actorUserId)->get()
            ->filter(function (AuditEvent $audit) use ($operation, $state, $version, $batch, $event, $handoff): bool {
                $metadata = $audit->metadata ?? [];

                return ($metadata['operation'] ?? null) === $operation && ($metadata['state'] ?? null) === $state
                    && ($metadata['version'] ?? null) === $version && ($metadata['batch_public_id'] ?? null) === $batch->public_id
                    && ($metadata['batch_content_digest'] ?? null) === $batch->content_digest
                    && ($metadata['event_public_id'] ?? null) === $event?->public_id
                    && ($metadata['event_content_digest'] ?? null) === $event?->content_digest
                    && ($metadata['handoff_public_id'] ?? null) === $handoff?->public_id
                    && ($metadata['handoff_content_digest'] ?? null) === $handoff?->content_digest
                    && ($metadata['expected_net_amount'] ?? null) === ($event instanceof FinanceCashierCollectionEvent ? $event->expected_net_amount : 0)
                    && ($metadata['evidence_count'] ?? null) === ($event instanceof FinanceCashierCollectionEvent ? $event->membership_count : 0);
            });
        if ($audits->count() !== 1) {
            throw new FinanceDenied('batch_integrity_failure', 'Audit operasi batch penerimaan kas tidak utuh.');
        }
    }

    /**
     * @param  Collection<int, FinanceCashierCollectionMember>  $members
     * @param  Collection<int, FinanceCashierCollectionEvent>  $events
     */
    private function assertStateFingerprint(FinanceCashierCollectionBatch $batch, Collection $members, Collection $events, string $expected): void
    {
        $handoff = FinanceCashDepositHandoff::query()->where('collection_batch_id', $batch->id)->first();
        if (! hash_equals($expected, $this->fingerprints->state($batch, $members, $events, $handoff))) {
            throw new FinanceDenied('stale_collection_batch', 'Batch berubah. Muat ulang sebelum melanjutkan.');
        }
    }

    private function owned(FinanceCashierCollectionBatch $batch, User $actor): void
    {
        if ($batch->cashier_user_id !== $actor->id) {
            throw new ModelNotFoundException;
        }
    }

    private function receipt(User $actor, FinanceCashierCollectionBatch $batch, ?FinanceCashierCollectionEvent $event, ?FinanceCashDepositHandoff $handoff, string $operation, string $key, string $payload): void
    {
        FinanceCashierCollectionOperationReceipt::query()->create([
            'actor_user_id' => $actor->id, 'collection_batch_id' => $batch->id,
            'collection_event_id' => $event?->id, 'deposit_handoff_id' => $handoff?->id,
            'operation' => $operation, 'idempotency_key' => $key, 'payload_digest' => $payload,
            'result_type' => $handoff ? FinanceCashierCollectionOperationReceipt::RESULT_HANDOFF : ($event ? FinanceCashierCollectionOperationReceipt::RESULT_EVENT : FinanceCashierCollectionOperationReceipt::RESULT_BATCH),
            'result_public_id' => ($handoff ?? $event ?? $batch)->public_id,
            'batch_content_digest' => $batch->content_digest, 'event_content_digest' => $event?->content_digest,
            'handoff_content_digest' => $handoff?->content_digest,
            'result_digest' => $this->fingerprints->result($operation, $batch, $event, $handoff),
            'request_correlation_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
            'completed_at' => now(),
        ]);
    }

    private function replay(User $actor, string $operation, string $key, string $payload): ?FinanceMutationResult
    {
        $receipt = FinanceCashierCollectionOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if (! $receipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $payload)) {
            throw new FinanceDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk muatan lain.');
        }
        $batch = FinanceCashierCollectionBatch::query()->find($receipt->collection_batch_id);
        $event = $receipt->collection_event_id ? FinanceCashierCollectionEvent::query()->find($receipt->collection_event_id) : null;
        $handoff = $receipt->deposit_handoff_id ? FinanceCashDepositHandoff::query()->find($receipt->deposit_handoff_id) : null;
        $record = $handoff ?? $event ?? $batch;
        $shape = match ($operation) {
            self::OPERATION_OPEN => [$event === null && $handoff === null, 'OPEN', 0, $batch?->cashier_user_id],
            self::OPERATION_CLOSE => [$event?->event_type === FinanceCashierCollectionEvent::CLOSE_REQUESTED && $handoff === null, 'CLOSE_REQUESTED', $event?->sequence, $event?->actor_user_id],
            self::OPERATION_RECOUNT => [$event?->event_type === FinanceCashierCollectionEvent::RECOUNT_SUBMITTED && $handoff === null, 'RECOUNT_SUBMITTED', $event?->sequence, $event?->actor_user_id],
            self::OPERATION_VERIFY => [$event?->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED && $handoff === null, 'CLOSE_VERIFIED', $event?->sequence, $event?->actor_user_id],
            self::OPERATION_HANDOFF => [$event?->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED && $handoff?->verified_event_id === $event->id, 'DEPOSIT_HANDOFF_CREATED', $event?->sequence, $batch?->cashier_user_id],
            default => [false, '', -1, null],
        };
        if (! $batch || ! $record || $shape[0] !== true || $shape[3] !== $actor->id
            || $event?->collection_batch_id !== ($event ? $batch->id : null)
            || $handoff?->collection_batch_id !== ($handoff ? $batch->id : null)
            || $receipt->result_public_id !== $record->public_id) {
            throw new FinanceDenied('receipt_corrupt', 'Bukti operasi batch penerimaan kas tidak valid.');
        }
        $this->reconciledEvidence($batch);
        $this->assertRetainedOperation($batch, $event, $handoff, $operation, (string) $shape[1], (int) $shape[2], $actor->id);

        return new FinanceMutationResult($record, true);
    }

    private function assertSettlementBindingIntegrity(FinanceCashSettlement $settlement, FinanceCashierCollectionMember $member, FinanceCashierCollectionBatch $batch, bool $includeSettlementEvidence = true): void
    {
        if (! $settlement->collection_binding_required
            || $member->collection_batch_id !== $batch->id
            || $member->settlement_id !== $settlement->id
            || $member->cashier_user_id !== $batch->cashier_user_id
            || $member->cashier_user_id !== $settlement->cashier_user_id
            || $member->cashier_name_snapshot !== $settlement->cashier_name_snapshot
            || $member->settlement_public_id_snapshot !== $settlement->public_id
            || $member->receipt_number_snapshot !== $settlement->receipt_number
            || $member->amount !== $settlement->amount
            || ! hash_equals($member->settlement_content_digest, $settlement->content_digest)
            || ! hash_equals($member->content_digest, $this->fingerprints->member($member))
            || ! hash_equals($settlement->content_digest, $this->settlementFingerprints->settlement($settlement))) {
            throw new FinanceDenied('batch_integrity_failure', 'Keanggotaan batch penerimaan kas tidak dapat direkonsiliasi.');
        }
        if (! $includeSettlementEvidence) {
            return;
        }
        $receipts = FinanceSettlementOperationReceipt::query()
            ->where('operation', FinanceCashSettlementService::OPERATION_SETTLE)
            ->where('settlement_public_id', $settlement->public_id)->get();
        $receipt = $receipts->first();
        if ($receipts->count() !== 1 || ! $receipt instanceof FinanceSettlementOperationReceipt
            || $receipt->actor_user_id !== $settlement->cashier_user_id
            || $receipt->bill_version_public_id !== $settlement->bill_version_public_id_snapshot
            || $receipt->amount !== $settlement->amount
            || ! hash_equals($receipt->source_set_digest, $settlement->source_set_digest)
            || ! hash_equals($receipt->bill_version_content_digest, $settlement->bill_version_content_digest)
            || ! hash_equals($receipt->settlement_content_digest, $settlement->content_digest)
            || ! hash_equals($receipt->result_digest, $this->settlementFingerprints->result($settlement))) {
            throw new FinanceDenied('batch_integrity_failure', 'Bukti operasi pelunasan anggota batch tidak tersedia.');
        }
        $audits = AuditEvent::query()->where('action', 'finance.workflow.mutate')->where('outcome', 'SUCCESS')
            ->where('resource_id', $settlement->bill_public_id_snapshot)->where('actor_user_id', $settlement->cashier_user_id)->get()
            ->filter(function (AuditEvent $audit) use ($settlement): bool {
                $metadata = $audit->metadata ?? [];

                return ($metadata['operation'] ?? null) === FinanceCashSettlementService::OPERATION_SETTLE
                    && ($metadata['state'] ?? null) === FinanceBill::ISSUED_CURRENT
                    && ($metadata['version'] ?? null) === $settlement->bill_version_snapshot
                    && ($metadata['settlement_public_id'] ?? null) === $settlement->public_id
                    && ($metadata['settlement_content_digest'] ?? null) === $settlement->content_digest
                    && ($metadata['settlement_result_digest'] ?? null) === $this->settlementFingerprints->result($settlement);
            });
        if ($audits->count() !== 1) {
            throw new FinanceDenied('batch_integrity_failure', 'Audit pelunasan anggota batch tidak tersedia.');
        }
    }

    private function success(User $actor, FinanceCashierCollectionBatch $batch, string $operation, string $state, int $version, ?FinanceCashierCollectionEvent $event, ?FinanceCashDepositHandoff $handoff): void
    {
        $expected = $event instanceof FinanceCashierCollectionEvent ? $event->expected_net_amount : 0;
        $count = $event instanceof FinanceCashierCollectionEvent ? $event->membership_count : 0;
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', $batch->public_id, $actor, 'SUCCESS', metadata: [
            'operation' => $operation, 'state' => $state, 'version' => $version,
            'batch_public_id' => $batch->public_id, 'batch_content_digest' => $batch->content_digest,
            'event_public_id' => $event?->public_id, 'event_content_digest' => $event?->content_digest,
            'handoff_public_id' => $handoff?->public_id, 'handoff_content_digest' => $handoff?->content_digest,
            'expected_net_amount' => $expected, 'evidence_count' => $count,
        ]) === null) {
            throw new FinanceAuditUnavailable('Audit batch penerimaan kas tidak tersedia.');
        }
    }

    private function mutate(User $actor, ?string $resource, string $operation, string $key, string $payload, callable $callback): FinanceMutationResult
    {
        try {
            return FinanceMutationScope::run(fn () => DB::transaction(function () use ($callback): FinanceMutationResult {
                $driver = DB::connection()->getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement("SET LOCAL simrs.finance_mutation = '1'");
                }
                if ($driver === 'mysql') {
                    DB::statement('SET @simrs_finance_mutation = 1');
                }
                try {
                    return $callback();
                } finally {
                    if ($driver === 'mysql') {
                        DB::statement('SET @simrs_finance_mutation = 0');
                    }
                }
            }, 3));
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->denial($actor, $resource, $operation, 'resource_not_found');
            throw $exception;
        } catch (FinanceDenied $exception) {
            // A same-key peer may have committed while this transaction waited
            // on the canonical coordination locks. Re-check only after rollback
            // so an exact retained result wins over a stale lifecycle refusal.
            if ($replay = DB::transaction(fn () => $this->replay($actor, $operation, $key, $payload))) {
                return $replay;
            }
            $this->denial($actor, $resource, $operation, $exception->reason);
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            if ($replay = DB::transaction(fn () => $this->replay($actor, $operation, $key, $payload))) {
                return $replay;
            }
            $denied = new FinanceDenied('concurrent_state_conflict', 'Keadaan batch berubah bersamaan.');
            $this->denial($actor, $resource, $operation, $denied->reason);
            throw $denied;
        }
    }

    private function authorize(callable $authorize, User $actor, ?string $resource, string $operation): void
    {
        try {
            $authorize();
        } catch (AuthorizationException $exception) {
            $this->denial($actor, $resource, $operation, 'role_not_permitted');
            throw $exception;
        }
    }

    private function key(string $key, User $actor, ?string $resource, string $operation): string
    {
        $key = mb_strtolower(trim($key));
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key)) {
            throw $this->deny($actor, $resource, $operation, 'validation_failed', 'Kunci idempotensi tidak valid.');
        }

        return $key;
    }

    private function digest(string $digest, User $actor, ?string $resource, string $operation): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
            throw $this->deny($actor, $resource, $operation, 'validation_failed', 'Sidik batch tidak valid.');
        }
    }

    private function deny(User $actor, ?string $resource, string $operation, string $reason, string $message): FinanceDenied
    {
        $this->denial($actor, $resource, $operation, $reason);

        return new FinanceDenied($reason, $message);
    }

    private function denial(User $actor, ?string $resource, string $operation, string $reason): void
    {
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', $resource && Str::isUlid($resource) ? $resource : null, $actor, 'DENIED', $reason, ['operation' => $operation]) === null) {
            throw new FinanceAuditUnavailable('Audit penolakan batch penerimaan kas tidak tersedia.');
        }
    }
}
