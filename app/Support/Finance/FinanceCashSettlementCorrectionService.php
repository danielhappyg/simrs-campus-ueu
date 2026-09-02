<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceCashSettlement;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
use App\Models\FinanceSettlementCorrectionOperationReceipt;
use App\Models\FinanceSettlementOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceCashSettlementCorrectionService
{
    public const OPERATION_REQUEST = 'FINANCE_SETTLEMENT_CORRECTION_REQUEST';

    public const OPERATION_REVIEW = 'FINANCE_SETTLEMENT_CORRECTION_REVIEW';

    public const OPERATION_COMPLETE = 'FINANCE_SETTLEMENT_REFUND_COMPLETE';

    public function __construct(
        private readonly FinanceCashSettlementCorrectionActorPolicy $policy,
        private readonly FinanceCashSettlementFingerprint $settlementFingerprints,
        private readonly FinanceCashSettlementCorrectionFingerprint $fingerprints,
        private readonly FinanceCashSettlementNetPolicy $netPolicy,
        private readonly FinanceCashierCollectionService $collections,
        private readonly AuditRecorder $audit,
    ) {}

    public function request(
        string $settlementPublicId,
        User $actor,
        string $reasonCode,
        string $explanation,
        string $expectedSettlementDigest,
        string $idempotencyKey,
    ): FinanceMutationResult {
        $this->authorize(fn () => $this->policy->request($actor), $actor, $settlementPublicId, self::OPERATION_REQUEST);
        $key = $this->key($idempotencyKey, $actor, $settlementPublicId, self::OPERATION_REQUEST);
        $reasonCode = strtoupper(trim($reasonCode));
        $explanation = $this->explanation($explanation, $actor, $settlementPublicId, self::OPERATION_REQUEST);
        $this->digest($expectedSettlementDigest, $actor, $settlementPublicId, self::OPERATION_REQUEST);
        if (! in_array($reasonCode, FinanceSettlementCorrectionCase::REASON_CODES, true)) {
            throw $this->deny($actor, $settlementPublicId, self::OPERATION_REQUEST, 'validation_failed', 'Alasan koreksi tidak valid.');
        }
        $payload = FinanceCanonicalJson::digest([self::OPERATION_REQUEST, $settlementPublicId, $reasonCode, $explanation, $expectedSettlementDigest]);
        $candidate = null;
        try {
            $candidate = FinanceCashSettlement::query()->where('public_id', $settlementPublicId)->firstOrFail();

            return $this->transaction(function () use ($candidate, $actor, $reasonCode, $explanation, $expectedSettlementDigest, $key, $payload): FinanceMutationResult {
                if ($replay = $this->replay($actor, self::OPERATION_REQUEST, $key, $payload)) {
                    return $replay;
                }
                $this->collections->assertSettlementCorrectionAllowed($candidate);
                $encounter = Encounter::query()->whereKey($candidate->encounter_id)->lockForUpdate()->firstOrFail();
                $encounter->load('patient');
                $bill = FinanceBill::query()->whereKey($candidate->bill_id)->lockForUpdate()->firstOrFail();
                $version = FinanceBillVersion::query()->whereKey($candidate->bill_version_id)->lockForUpdate()->firstOrFail();
                $settlements = FinanceCashSettlement::query()->where('bill_id', $candidate->bill_id)
                    ->orderBy('bill_version_snapshot')->orderBy('id')->lockForUpdate()->get();
                $settlement = $settlements->firstWhere('id', $candidate->id);
                if (! $settlement instanceof FinanceCashSettlement) {
                    throw new FinanceDenied('settlement_integrity_failure', 'Bukti pelunasan tidak tersedia.');
                }
                if ($settlement->cashier_user_id !== $actor->id) {
                    throw new FinanceDenied('settlement_not_owned', 'Kasir hanya dapat meminta koreksi untuk pelunasannya sendiri.');
                }
                if (! $encounter->patient?->is_synthetic) {
                    throw new FinanceDenied('non_synthetic_record', 'Koreksi hanya tersedia untuk data pengajaran yang diizinkan.');
                }
                if (! hash_equals($settlement->content_digest, $expectedSettlementDigest)) {
                    throw new FinanceDenied('stale_settlement', 'Bukti pelunasan berubah. Muat ulang sebelum meminta koreksi.');
                }
                $this->assertOriginalEvidence($settlement, $bill, $version);
                if ($this->netPolicy->correctionState($settlement, true)['case'] !== null) {
                    throw new FinanceDenied('correction_case_exists', 'Pelunasan ini sudah memiliki perkara koreksi.');
                }

                $now = now();
                $publicId = (string) Str::ulid();
                $case = new FinanceSettlementCorrectionCase([
                    'correction_number' => 'KOR-'.$now->format('Ymd').'-'.$publicId,
                    'settlement_id' => $settlement->id,
                    'bill_id' => $settlement->bill_id,
                    'bill_version_id' => $settlement->bill_version_id,
                    'requesting_cashier_user_id' => $actor->id,
                    'requesting_cashier_name_snapshot' => $actor->name,
                    'settlement_public_id_snapshot' => $settlement->public_id,
                    'receipt_number_snapshot' => $settlement->receipt_number,
                    'amount' => $settlement->amount,
                    'settlement_content_digest' => $settlement->content_digest,
                    'bill_public_id_snapshot' => $settlement->bill_public_id_snapshot,
                    'bill_version_public_id_snapshot' => $settlement->bill_version_public_id_snapshot,
                    'bill_version_snapshot' => $settlement->bill_version_snapshot,
                    'reason_code' => $reasonCode,
                    'explanation' => $explanation,
                    'content_digest' => str_repeat('0', 64),
                    'requested_at' => $now,
                    'created_at' => $now,
                ]);
                $case->public_id = $publicId;
                $case->content_digest = $this->fingerprints->correctionCase($case);
                $case->save();
                $this->success($actor, $case, self::OPERATION_REQUEST, FinanceCashSettlementNetPolicy::CORRECTION_REQUESTED, 1);
                $this->receipt($actor, $case, null, self::OPERATION_REQUEST, $key, $payload);

                return new FinanceMutationResult($case, false);
            });
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->denial($actor, $settlementPublicId, self::OPERATION_REQUEST, 'resource_not_found');
            throw $exception;
        } catch (FinanceDenied $exception) {
            if ($candidate && ($replay = $this->replayOutside($actor, self::OPERATION_REQUEST, $key, $payload))) {
                return $replay;
            }
            $this->denial($actor, $settlementPublicId, self::OPERATION_REQUEST, $exception->reason);
            throw $exception;
        } catch (UniqueConstraintViolationException) {
            if ($candidate && ($replay = $this->replayOutside($actor, self::OPERATION_REQUEST, $key, $payload))) {
                return $replay;
            }
            throw $this->deny($actor, $settlementPublicId, self::OPERATION_REQUEST, 'concurrent_state_conflict', 'Keadaan koreksi berubah bersamaan.');
        }
    }

    public function review(
        string $casePublicId,
        User $supervisor,
        string $decision,
        string $explanation,
        string $expectedCaseFingerprint,
        string $idempotencyKey,
    ): FinanceMutationResult {
        $this->authorize(fn () => $this->policy->review($supervisor), $supervisor, $casePublicId, self::OPERATION_REVIEW);
        $decision = strtoupper(trim($decision));
        if (! in_array($decision, [FinanceSettlementCorrectionEvent::REVIEW_REJECTED, FinanceSettlementCorrectionEvent::REFUND_APPROVED], true)) {
            throw $this->deny($supervisor, $casePublicId, self::OPERATION_REVIEW, 'validation_failed', 'Keputusan tinjauan tidak valid.');
        }
        $explanation = $this->explanation($explanation, $supervisor, $casePublicId, self::OPERATION_REVIEW);
        $this->digest($expectedCaseFingerprint, $supervisor, $casePublicId, self::OPERATION_REVIEW);
        $key = $this->key($idempotencyKey, $supervisor, $casePublicId, self::OPERATION_REVIEW);
        $payload = FinanceCanonicalJson::digest([self::OPERATION_REVIEW, $casePublicId, $decision, $explanation, $expectedCaseFingerprint]);

        return $this->transition($casePublicId, $supervisor, self::OPERATION_REVIEW, $key, $payload, function (FinanceSettlementCorrectionCase $case, FinanceCashSettlement $settlement, $events) use ($supervisor, $decision, $explanation, $expectedCaseFingerprint): FinanceSettlementCorrectionEvent {
            if ($case->requesting_cashier_user_id === $supervisor->id) {
                throw new FinanceDenied('same_actor_separation', 'Pemohon tidak boleh meninjau perkara koreksinya sendiri.');
            }
            if (! hash_equals($expectedCaseFingerprint, $this->fingerprints->state($case, $events))) {
                throw new FinanceDenied('stale_correction_case', 'Perkara koreksi berubah. Muat ulang sebelum meninjau.');
            }
            if ($events->isNotEmpty()) {
                throw new FinanceDenied('review_already_completed', 'Perkara koreksi ini sudah ditinjau.');
            }

            return $this->event($case, $settlement, $supervisor, 1, $decision, null, $explanation, $decision === FinanceSettlementCorrectionEvent::REFUND_APPROVED ? $settlement->amount : null, null);
        });
    }

    public function completeRefund(
        string $casePublicId,
        User $supervisor,
        string $expectedCaseFingerprint,
        string $idempotencyKey,
    ): FinanceMutationResult {
        $this->authorize(fn () => $this->policy->completeRefund($supervisor), $supervisor, $casePublicId, self::OPERATION_COMPLETE);
        $this->digest($expectedCaseFingerprint, $supervisor, $casePublicId, self::OPERATION_COMPLETE);
        $key = $this->key($idempotencyKey, $supervisor, $casePublicId, self::OPERATION_COMPLETE);
        $payload = FinanceCanonicalJson::digest([self::OPERATION_COMPLETE, $casePublicId, $expectedCaseFingerprint]);

        return $this->transition($casePublicId, $supervisor, self::OPERATION_COMPLETE, $key, $payload, function (FinanceSettlementCorrectionCase $case, FinanceCashSettlement $settlement, $events) use ($supervisor, $expectedCaseFingerprint): FinanceSettlementCorrectionEvent {
            if ($case->requesting_cashier_user_id === $supervisor->id) {
                throw new FinanceDenied('same_actor_separation', 'Pemohon tidak boleh menyelesaikan pengembalian kas sendiri.');
            }
            if (! hash_equals($expectedCaseFingerprint, $this->fingerprints->state($case, $events))) {
                throw new FinanceDenied('stale_correction_case', 'Perkara koreksi berubah. Muat ulang sebelum menyelesaikan pengembalian.');
            }
            $approval = $events->firstWhere('event_type', FinanceSettlementCorrectionEvent::REFUND_APPROVED);
            if (! $approval instanceof FinanceSettlementCorrectionEvent
                || $events->count() !== 1 || $approval->sequence !== 1 || $approval->amount !== $settlement->amount) {
                throw new FinanceDenied('refund_not_approved', 'Pengembalian kas belum disetujui.');
            }

            return $this->event($case, $settlement, $supervisor, 2, FinanceSettlementCorrectionEvent::REFUND_COMPLETED, $approval->content_digest, null, $settlement->amount, $approval->content_digest);
        });
    }

    private function transition(string $publicId, User $actor, string $operation, string $key, string $payload, callable $callback): FinanceMutationResult
    {
        $candidate = null;
        try {
            $candidate = FinanceSettlementCorrectionCase::query()->where('public_id', $publicId)->firstOrFail();

            return $this->transaction(function () use ($candidate, $actor, $operation, $key, $payload, $callback): FinanceMutationResult {
                if ($replay = $this->replay($actor, $operation, $key, $payload)) {
                    return $replay;
                }
                $settlementCandidate = FinanceCashSettlement::query()->whereKey($candidate->settlement_id)->firstOrFail();
                if ($operation === self::OPERATION_COMPLETE) {
                    $this->collections->assertSettlementCorrectionAllowed($settlementCandidate);
                }
                Encounter::query()->whereKey($settlementCandidate->encounter_id)->lockForUpdate()->firstOrFail();
                $bill = FinanceBill::query()->whereKey($candidate->bill_id)->lockForUpdate()->firstOrFail();
                $version = FinanceBillVersion::query()->whereKey($candidate->bill_version_id)->lockForUpdate()->firstOrFail();
                $settlements = FinanceCashSettlement::query()->where('bill_id', $candidate->bill_id)->orderBy('bill_version_snapshot')->orderBy('id')->lockForUpdate()->get();
                $settlement = $settlements->firstWhere('id', $candidate->settlement_id);
                $case = FinanceSettlementCorrectionCase::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $events = FinanceSettlementCorrectionEvent::query()->where('correction_case_id', $case->id)->orderBy('sequence')->orderBy('id')->lockForUpdate()->get();
                if (! $settlement instanceof FinanceCashSettlement) {
                    throw new FinanceDenied('correction_integrity_failure', 'Pelunasan asal koreksi tidak tersedia.');
                }
                $this->assertOriginalEvidence($settlement, $bill, $version);
                $state = $this->netPolicy->correctionState($settlement);
                if (! $state['case'] instanceof FinanceSettlementCorrectionCase || $state['case']->id !== $case->id) {
                    throw new FinanceDenied('correction_integrity_failure', 'Perkara koreksi tidak dapat direkonsiliasi.');
                }
                $event = $callback($case, $settlement, $events);
                $stateName = $event->event_type;
                $this->success($actor, $case, $operation, $stateName, $event->sequence);
                $this->receipt($actor, $case, $event, $operation, $key, $payload);

                return new FinanceMutationResult($event, false);
            });
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->denial($actor, $publicId, $operation, 'resource_not_found');
            throw $exception;
        } catch (FinanceDenied $exception) {
            if ($candidate && ($replay = $this->replayOutside($actor, $operation, $key, $payload))) {
                return $replay;
            }
            $this->denial($actor, $publicId, $operation, $exception->reason);
            throw $exception;
        } catch (UniqueConstraintViolationException) {
            if ($candidate && ($replay = $this->replayOutside($actor, $operation, $key, $payload))) {
                return $replay;
            }
            throw $this->deny($actor, $publicId, $operation, 'concurrent_state_conflict', 'Keadaan koreksi berubah bersamaan.');
        }
    }

    private function event(FinanceSettlementCorrectionCase $case, FinanceCashSettlement $settlement, User $actor, int $sequence, string $type, ?string $previous, ?string $explanation, ?int $amount, ?string $approval): FinanceSettlementCorrectionEvent
    {
        $now = now();
        $event = new FinanceSettlementCorrectionEvent([
            'correction_case_id' => $case->id, 'sequence' => $sequence, 'event_type' => $type,
            'previous_event_digest' => $previous, 'actor_user_id' => $actor->id,
            'actor_name_snapshot' => $actor->name, 'explanation' => $explanation,
            'amount' => $amount, 'original_settlement_content_digest' => $settlement->content_digest,
            'approval_event_digest' => $approval, 'content_digest' => str_repeat('0', 64),
            'occurred_at' => $now, 'created_at' => $now,
        ]);
        $event->public_id = (string) Str::ulid();
        $event->content_digest = $this->fingerprints->event($event);
        $event->save();

        return $event;
    }

    private function assertOriginalEvidence(FinanceCashSettlement $settlement, FinanceBill $bill, FinanceBillVersion $version): void
    {
        if ($settlement->payment_method !== FinanceCashSettlement::PAYMENT_CASH
            || $settlement->state !== FinanceCashSettlement::SETTLED
            || $settlement->bill_id !== $bill->id
            || $settlement->bill_version_id !== $version->id
            || $version->bill_id !== $bill->id
            || $settlement->bill_public_id_snapshot !== $bill->public_id
            || $settlement->bill_version_public_id_snapshot !== $version->public_id
            || $settlement->bill_version_snapshot !== $version->version
            || ! hash_equals($settlement->source_set_digest, $version->source_set_digest)
            || ! hash_equals($settlement->bill_version_content_digest, $version->content_digest)
            || ! hash_equals($settlement->content_digest, $this->settlementFingerprints->settlement($settlement))) {
            throw new FinanceDenied('settlement_integrity_failure', 'Bukti pelunasan tidak dapat direkonsiliasi.');
        }
        $receipt = FinanceSettlementOperationReceipt::query()
            ->where('operation', FinanceCashSettlementService::OPERATION_SETTLE)
            ->where('settlement_public_id', $settlement->public_id)->first();
        if (! $receipt instanceof FinanceSettlementOperationReceipt
            || $receipt->actor_user_id !== $settlement->cashier_user_id
            || $receipt->amount !== $settlement->amount
            || $receipt->bill_version_public_id !== $settlement->bill_version_public_id_snapshot
            || ! hash_equals($receipt->source_set_digest, $settlement->source_set_digest)
            || ! hash_equals($receipt->bill_version_content_digest, $settlement->bill_version_content_digest)
            || ! hash_equals($receipt->settlement_content_digest, $settlement->content_digest)
            || ! hash_equals($receipt->result_digest, $this->settlementFingerprints->result($settlement))) {
            throw new FinanceDenied('settlement_integrity_failure', 'Bukti operasi pelunasan tidak dapat direkonsiliasi.');
        }
        $audit = AuditEvent::query()->where('action', 'finance.workflow.mutate')->where('outcome', 'SUCCESS')
            ->where('resource_id', $settlement->bill_public_id_snapshot)
            ->where('actor_user_id', $settlement->cashier_user_id)
            ->where('metadata->operation', FinanceCashSettlementService::OPERATION_SETTLE);
        if ($settlement->prior_net_collected_amount_snapshot !== null) {
            $audit->where('metadata->settlement_public_id', $settlement->public_id)
                ->where('metadata->settlement_content_digest', $settlement->content_digest)
                ->where('metadata->settlement_result_digest', $this->settlementFingerprints->result($settlement));
        }
        if ($audit->count() !== 1) {
            throw new FinanceDenied('settlement_integrity_failure', 'Audit pelunasan asal tidak tersedia.');
        }
    }

    private function receipt(User $actor, FinanceSettlementCorrectionCase $case, ?FinanceSettlementCorrectionEvent $event, string $operation, string $key, string $payload): void
    {
        FinanceSettlementCorrectionOperationReceipt::query()->create([
            'actor_user_id' => $actor->id, 'correction_case_id' => $case->id,
            'correction_event_id' => $event?->id, 'operation' => $operation,
            'idempotency_key' => $key, 'payload_digest' => $payload,
            'result_type' => $event ? FinanceSettlementCorrectionOperationReceipt::RESULT_EVENT : FinanceSettlementCorrectionOperationReceipt::RESULT_CASE,
            'result_public_id' => $event ? $event->public_id : $case->public_id,
            'case_content_digest' => $case->content_digest,
            'event_content_digest' => $event?->content_digest,
            'result_digest' => $this->fingerprints->result($operation, $case, $event),
            'request_correlation_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
            'completed_at' => now(),
        ]);
    }

    private function replay(User $actor, string $operation, string $key, string $payload): ?FinanceMutationResult
    {
        $receipt = FinanceSettlementCorrectionOperationReceipt::query()
            ->where('actor_user_id', $actor->id)->where('operation', $operation)
            ->where('idempotency_key', $key)->first();
        if (! $receipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $payload)) {
            throw new FinanceDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk muatan lain.');
        }
        $case = FinanceSettlementCorrectionCase::query()->whereKey($receipt->correction_case_id)->first();
        $event = $receipt->correction_event_id === null ? null : FinanceSettlementCorrectionEvent::query()->whereKey($receipt->correction_event_id)->first();
        $record = $event ?? $case;
        $settlement = $case ? FinanceCashSettlement::query()->whereKey($case->settlement_id)->first() : null;
        $state = $settlement ? $this->netPolicy->correctionState($settlement) : null;
        $expectedResultType = $event
            ? FinanceSettlementCorrectionOperationReceipt::RESULT_EVENT
            : FinanceSettlementCorrectionOperationReceipt::RESULT_CASE;
        $shapeValid = match ($operation) {
            self::OPERATION_REQUEST => $event === null && $receipt->actor_user_id === $case?->requesting_cashier_user_id,
            self::OPERATION_REVIEW => $event instanceof FinanceSettlementCorrectionEvent
                && $event->sequence === 1
                && in_array($event->event_type, [FinanceSettlementCorrectionEvent::REVIEW_REJECTED, FinanceSettlementCorrectionEvent::REFUND_APPROVED], true)
                && $event->actor_user_id === $actor->id,
            self::OPERATION_COMPLETE => $event instanceof FinanceSettlementCorrectionEvent
                && $event->sequence === 2
                && $event->event_type === FinanceSettlementCorrectionEvent::REFUND_COMPLETED
                && $event->actor_user_id === $actor->id,
            default => false,
        };
        if (! $case || ! $record
            || ! $settlement
            || ! $shapeValid
            || ! is_array($state)
            || ! $state['case'] instanceof FinanceSettlementCorrectionCase
            || $state['case']->id !== $case->id
            || $receipt->result_type !== $expectedResultType
            || $receipt->result_public_id !== $record->public_id
            || ! hash_equals($receipt->case_content_digest, $case->content_digest)
            || ! hash_equals($case->content_digest, $this->fingerprints->correctionCase($case))
            || ($event === null && $receipt->event_content_digest !== null)
            || ($event && ($event->correction_case_id !== $case->id
                || ! hash_equals((string) $receipt->event_content_digest, $event->content_digest)
                || ! hash_equals($event->content_digest, $this->fingerprints->event($event))))
            || ! hash_equals($receipt->result_digest, $this->fingerprints->result($operation, $case, $event))) {
            throw new FinanceDenied('receipt_corrupt', 'Bukti operasi koreksi tidak valid.');
        }
        $this->collections->assertRequiredSettlementBinding($settlement);
        $expectedState = $event instanceof FinanceSettlementCorrectionEvent
            ? $event->event_type
            : FinanceCashSettlementNetPolicy::CORRECTION_REQUESTED;
        $expectedVersion = $event instanceof FinanceSettlementCorrectionEvent ? $event->sequence : 1;
        $audits = AuditEvent::query()->where('action', 'finance.workflow.mutate')->where('outcome', 'SUCCESS')
            ->where('resource_id', $case->public_id)->where('actor_user_id', $actor->id)->get()
            ->filter(function (AuditEvent $audit) use ($operation, $expectedState, $expectedVersion): bool {
                $metadata = $audit->metadata ?? [];

                return ($metadata['operation'] ?? null) === $operation
                    && ($metadata['state'] ?? null) === $expectedState
                    && ($metadata['version'] ?? null) === $expectedVersion;
            });
        if ($audits->count() !== 1) {
            throw new FinanceDenied('receipt_corrupt', 'Audit operasi koreksi tidak valid.');
        }

        return new FinanceMutationResult($record, true);
    }

    private function replayOutside(User $actor, string $operation, string $key, string $payload): ?FinanceMutationResult
    {
        return DB::transaction(fn () => $this->replay($actor, $operation, $key, $payload));
    }

    private function success(User $actor, FinanceSettlementCorrectionCase $case, string $operation, string $state, int $version): void
    {
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', $case->public_id, $actor, 'SUCCESS', metadata: compact('operation', 'state', 'version')) === null) {
            throw new FinanceAuditUnavailable('Audit koreksi pelunasan kas tidak tersedia.');
        }
    }

    private function denial(User $actor, string $resource, string $operation, string $reason): void
    {
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', Str::isUlid($resource) ? $resource : null, $actor, 'DENIED', $reason, compact('operation')) === null) {
            throw new FinanceAuditUnavailable('Audit penolakan koreksi pelunasan tidak tersedia.');
        }
    }

    private function deny(User $actor, string $resource, string $operation, string $reason, string $message): FinanceDenied
    {
        $this->denial($actor, $resource, $operation, $reason);

        return new FinanceDenied($reason, $message);
    }

    private function authorize(callable $callback, User $actor, string $resource, string $operation): void
    {
        try {
            $callback();
        } catch (AuthorizationException $exception) {
            $this->denial($actor, $resource, $operation, 'role_not_permitted');
            throw $exception;
        }
    }

    private function key(string $key, User $actor, string $resource, string $operation): string
    {
        $key = mb_strtolower(trim($key));
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key)) {
            throw $this->deny($actor, $resource, $operation, 'validation_failed', 'Kunci idempotensi tidak valid.');
        }

        return $key;
    }

    private function digest(string $digest, User $actor, string $resource, string $operation): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
            throw $this->deny($actor, $resource, $operation, 'validation_failed', 'Sidik bukti koreksi tidak valid.');
        }
    }

    private function explanation(string $explanation, User $actor, string $resource, string $operation): string
    {
        $explanation = preg_replace('/\s+/u', ' ', trim($explanation)) ?? '';
        if (mb_strlen($explanation) < 8 || mb_strlen($explanation) > 500) {
            throw $this->deny($actor, $resource, $operation, 'validation_failed', 'Penjelasan koreksi harus 8 sampai 500 karakter.');
        }

        return $explanation;
    }

    private function transaction(callable $callback): mixed
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
        }, 3));
    }
}
