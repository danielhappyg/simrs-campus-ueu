<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceCashSettlement;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceSettlementOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceCashSettlementService
{
    public const OPERATION_SETTLE = 'FINANCE_CASH_SETTLEMENT';

    public function __construct(
        private readonly FinanceCashSettlementActorPolicy $policy,
        private readonly FinanceSourceCoordinator $sources,
        private readonly FinanceEvidenceFingerprint $billFingerprints,
        private readonly FinanceCashSettlementFingerprint $settlementFingerprints,
        private readonly FinanceCashSettlementNetPolicy $netPolicy,
        private readonly FinanceCashierCollectionService $collections,
        private readonly AuditRecorder $audit,
    ) {}

    public function settle(
        string $billPublicId,
        User $actor,
        string $expectedBillFingerprint,
        string $expectedBillVersionContentDigest,
        string $idempotencyKey,
    ): FinanceMutationResult {
        $this->authorize($actor, $billPublicId);
        $key = $this->key($idempotencyKey, $actor, $billPublicId);
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedBillFingerprint)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedBillVersionContentDigest)) {
            $denied = new FinanceDenied('validation_failed', 'Fingerprint tagihan tidak valid.');
            $this->denial($actor, $billPublicId, $denied->reason);
            throw $denied;
        }
        $payloadDigest = FinanceCanonicalJson::digest([
            self::OPERATION_SETTLE,
            [
                'bill_public_id' => $billPublicId,
                'expected_bill_fingerprint' => $expectedBillFingerprint,
                'expected_bill_version_content_digest' => $expectedBillVersionContentDigest,
            ],
            'EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1',
        ]);
        $candidate = null;

        try {
            $candidate = FinanceBill::query()->where('public_id', $billPublicId)->firstOrFail();

            return $this->transaction(function () use (
                $candidate,
                $actor,
                $key,
                $payloadDigest,
                $expectedBillFingerprint,
                $expectedBillVersionContentDigest,
            ): FinanceMutationResult {
                if ($replay = $this->replay($actor, $key, $payloadDigest)) {
                    return $replay;
                }
                $collectionBatch = $this->collections->requireOpenBatch($actor);
                $encounter = Encounter::query()->whereKey($candidate->encounter_id)->lockForUpdate()->firstOrFail();
                $encounter->load('patient');
                $bill = FinanceBill::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

                if (! $encounter->patient?->is_synthetic) {
                    throw new FinanceDenied('non_synthetic_record', 'Pelunasan hanya tersedia untuk data pengajaran yang diizinkan.');
                }
                if ($encounter->status === Encounter::STATUS_CANCELLED) {
                    throw new FinanceDenied('encounter_cancelled', 'Encounter yang dibatalkan tidak dapat dilunasi.');
                }
                if ($bill->current_version < 1 || $bill->state !== FinanceBill::ISSUED_CURRENT) {
                    throw new FinanceDenied(
                        $bill->state === FinanceBill::NEW_SOURCE_PENDING ? 'new_source_pending' : 'bill_not_issued',
                        'Hanya versi tagihan terbit terkini yang dapat dilunasi.',
                    );
                }
                if (! hash_equals($bill->current_content_digest, $this->billFingerprints->billContent($bill))
                    || ! hash_equals($expectedBillFingerprint, $this->billFingerprints->bill($bill))) {
                    throw new FinanceDenied('stale_bill', 'Tagihan berubah. Muat ulang sebelum melunasi.');
                }

                $synchronization = $this->sources->synchronize($encounter, $actor);
                if ($synchronization->hasUnresolved()) {
                    throw $this->unresolved($synchronization->unresolved());
                }
                $events = $synchronization->events;
                $sourceDigest = $this->billFingerprints->sourceSet($events);
                if (! hash_equals((string) $bill->current_source_set_digest, $sourceDigest)
                    || ! hash_equals((string) $bill->current_issued_source_set_digest, $sourceDigest)) {
                    throw new FinanceDenied('new_source_pending', 'Ada sumber biaya baru. Terbitkan versi tagihan baru sebelum melunasi.');
                }

                $version = FinanceBillVersion::query()->where('bill_id', $bill->id)
                    ->where('version', $bill->current_version)->with('lines')->lockForUpdate()->firstOrFail();
                if (! hash_equals($version->source_set_digest, $sourceDigest)
                    || ! hash_equals($version->content_digest, $this->billFingerprints->version($version, $version->lines))
                    || ! hash_equals($expectedBillVersionContentDigest, $version->content_digest)) {
                    throw new FinanceDenied('stale_bill_version', 'Versi tagihan atau sumber biayanya berubah. Muat ulang sebelum melunasi.');
                }
                $priorSettlements = FinanceCashSettlement::query()
                    ->where('bill_id', $bill->id)
                    ->where('bill_version_snapshot', '<=', $version->version)
                    ->orderBy('bill_version_snapshot')->orderBy('id')
                    ->lockForUpdate()->get();
                foreach ($priorSettlements as $prior) {
                    if ($prior->bill_id !== $bill->id
                        || $prior->bill_public_id_snapshot !== $bill->public_id
                        || $prior->bill_version_snapshot > $version->version
                        || ! hash_equals($prior->content_digest, $this->settlementFingerprints->settlement($prior))) {
                        throw new FinanceDenied('prior_settlement_corrupt', 'Bukti pelunasan sebelumnya tidak dapat direkonsiliasi.');
                    }
                }
                $outstandingAmount = $version->net_amount - $this->netPolicy->netCollected($priorSettlements, true);
                if ($outstandingAmount === 0) {
                    $hasActiveCurrentSettlement = $priorSettlements
                        ->where('bill_version_id', $version->id)
                        ->contains(fn (FinanceCashSettlement $existing): bool => $this->netPolicy->correctionState($existing)['state'] !== FinanceCashSettlementNetPolicy::REFUND_COMPLETED);
                    if ($hasActiveCurrentSettlement) {
                        throw new FinanceDenied('bill_version_already_settled', 'Versi tagihan ini sudah dilunasi.');
                    }
                    throw new FinanceDenied('zero_outstanding_amount', 'Versi tagihan tidak memiliki sisa yang perlu dilunasi.');
                }
                if ($outstandingAmount < 0) {
                    throw new FinanceDenied('refund_required', 'Nilai tagihan terkini lebih kecil daripada pembayaran terdahulu dan memerlukan alur pengembalian terpisah.');
                }

                $settlement = $this->createSettlement($bill, $version, $encounter, $actor, $outstandingAmount);
                $this->collections->bindSettlement($collectionBatch, $settlement, $actor);
                $this->successAudit($actor, $bill, $settlement);
                $this->receipt($actor, $key, $payloadDigest, $settlement);

                return new FinanceMutationResult($settlement, false);
            });
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->denial($actor, $billPublicId, 'resource_not_found');
            throw $exception;
        } catch (FinanceDenied $exception) {
            if ($candidate && ($replay = $this->replayOutsideTransaction($actor, $key, $payloadDigest))) {
                return $replay;
            }
            $this->denial($actor, $billPublicId, $exception->reason);
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            if ($candidate && ($replay = $this->replayOutsideTransaction($actor, $key, $payloadDigest))) {
                return $replay;
            }
            $denied = new FinanceDenied('concurrent_state_conflict', 'Keadaan pelunasan berubah bersamaan.');
            $this->denial($actor, $billPublicId, $denied->reason);
            throw $denied;
        }
    }

    private function createSettlement(
        FinanceBill $bill,
        FinanceBillVersion $version,
        Encounter $encounter,
        User $actor,
        int $amount,
    ): FinanceCashSettlement {
        $now = now();
        $publicId = (string) Str::ulid();
        $settlement = new FinanceCashSettlement([
            'receipt_number' => 'KWT-'.$now->format('Ymd').'-'.$publicId,
            'bill_id' => $bill->id,
            'bill_version_id' => $version->id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'cashier_user_id' => $actor->id,
            'cashier_name_snapshot' => $actor->name,
            'bill_public_id_snapshot' => $bill->public_id,
            'bill_number_snapshot' => $bill->bill_number,
            'bill_version_public_id_snapshot' => $version->public_id,
            'bill_version_snapshot' => $version->version,
            'encounter_public_id_snapshot' => $encounter->public_id,
            'patient_public_id_snapshot' => $encounter->patient->public_id,
            'patient_name_snapshot' => $encounter->patient->full_name,
            'medical_record_number_snapshot' => $encounter->patient->medical_record_number,
            'care_setting' => $encounter->care_setting,
            'coverage_profile_snapshot' => $version->coverage_profile,
            'coverage_label_snapshot' => $this->coverageLabel($version->coverage_profile),
            'coverage_exclusion_snapshot' => 'Layanan atau tindakan lain di luar versi tagihan ini dan klaim tidak dinyatakan lunas.',
            'payment_method' => FinanceCashSettlement::PAYMENT_CASH,
            'state' => FinanceCashSettlement::SETTLED,
            'amount' => $amount,
            'prior_net_collected_amount_snapshot' => $version->net_amount - $amount,
            'collection_binding_required' => true,
            'source_set_digest' => $version->source_set_digest,
            'bill_version_content_digest' => $version->content_digest,
            'content_digest' => str_repeat('0', 64),
            'settled_at' => $now,
            'created_at' => $now,
        ]);
        $settlement->public_id = $publicId;
        $settlement->content_digest = $this->settlementFingerprints->settlement($settlement);
        $settlement->save();

        return $settlement;
    }

    private function receipt(
        User $actor,
        string $key,
        string $payloadDigest,
        FinanceCashSettlement $settlement,
    ): void {
        $resultDigest = $this->settlementFingerprints->result($settlement);
        FinanceSettlementOperationReceipt::query()->create([
            'actor_user_id' => $actor->id,
            'operation' => self::OPERATION_SETTLE,
            'idempotency_key' => $key,
            'payload_digest' => $payloadDigest,
            'settlement_public_id' => $settlement->public_id,
            'bill_version_public_id' => $settlement->bill_version_public_id_snapshot,
            'amount' => $settlement->amount,
            'source_set_digest' => $settlement->source_set_digest,
            'bill_version_content_digest' => $settlement->bill_version_content_digest,
            'settlement_content_digest' => $settlement->content_digest,
            'result_digest' => $resultDigest,
            'request_correlation_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
            'completed_at' => now(),
        ]);
    }

    private function replay(User $actor, string $key, string $payloadDigest): ?FinanceMutationResult
    {
        $receipt = FinanceSettlementOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', self::OPERATION_SETTLE)
            ->where('idempotency_key', $key)
            ->first();
        if (! $receipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $payloadDigest)) {
            throw new FinanceDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk muatan lain.');
        }

        $settlement = FinanceCashSettlement::query()->where('public_id', $receipt->settlement_public_id)
            ->with(['billVersion.lines'])->first();
        $version = $settlement?->billVersion;
        if (! $settlement || ! $version
            || $receipt->amount !== $settlement->amount
            || $receipt->bill_version_public_id !== $settlement->bill_version_public_id_snapshot
            || ! hash_equals($receipt->source_set_digest, $settlement->source_set_digest)
            || ! hash_equals($receipt->bill_version_content_digest, $settlement->bill_version_content_digest)
            || ! hash_equals($receipt->settlement_content_digest, $settlement->content_digest)
            || ! hash_equals($settlement->content_digest, $this->settlementFingerprints->settlement($settlement))
            || ! hash_equals($version->content_digest, $this->billFingerprints->version($version, $version->lines))
            || ! hash_equals($settlement->bill_version_content_digest, $version->content_digest)
            || ! hash_equals($receipt->result_digest, $this->settlementFingerprints->result($settlement))) {
            throw new FinanceDenied('receipt_corrupt', 'Bukti operasi pelunasan tidak valid.');
        }
        $this->collections->assertRequiredSettlementBinding($settlement);
        $audits = AuditEvent::query()->where('action', 'finance.workflow.mutate')->where('outcome', 'SUCCESS')
            ->where('resource_id', $settlement->bill_public_id_snapshot)
            ->where('actor_user_id', $settlement->cashier_user_id)->get()
            ->filter(function (AuditEvent $audit) use ($settlement): bool {
                $metadata = $audit->metadata ?? [];

                return ($metadata['operation'] ?? null) === self::OPERATION_SETTLE
                    && ($metadata['state'] ?? null) === FinanceBill::ISSUED_CURRENT
                    && ($metadata['version'] ?? null) === $settlement->bill_version_snapshot
                    && ($metadata['settlement_public_id'] ?? null) === $settlement->public_id
                    && ($metadata['settlement_content_digest'] ?? null) === $settlement->content_digest
                    && ($metadata['settlement_result_digest'] ?? null) === $this->settlementFingerprints->result($settlement);
            });
        if ($audits->count() !== 1) {
            throw new FinanceDenied('receipt_corrupt', 'Audit pelunasan tidak dapat direkonsiliasi.');
        }

        return new FinanceMutationResult($settlement, true);
    }

    private function coverageLabel(string $profile): string
    {
        return match ($profile) {
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_V1 => 'Obat yang diserahkan atau diretur, pemeriksaan radiologi selesai, hasil laboratorium terverifikasi bertarif, dan hari akomodasi rawat inap tertutup',
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_LABORATORY_V1 => 'Obat yang diserahkan atau diretur, pemeriksaan radiologi selesai, dan hasil laboratorium terverifikasi bertarif',
            FinanceBillVersion::COVERAGE_PHARMACY_RADIOLOGY_V1 => 'Obat yang diserahkan atau diretur dan pemeriksaan radiologi selesai bertarif',
            default => 'Obat yang telah diserahkan dan retur terkait',
        };
    }

    /** @param list<array<string, mixed>> $unresolved */
    private function unresolved(array $unresolved): FinanceDenied
    {
        $has = static fn (string $domain): bool => collect($unresolved)->contains(
            static fn (array $item): bool => ($item['source_domain'] ?? null) === $domain,
        );
        $reason = $has(FinanceChargeEvent::SOURCE_ACCOMMODATION)
            ? 'unresolved_accommodation_source'
            : ($has(FinanceChargeEvent::SOURCE_LABORATORY)
                ? 'unresolved_laboratory_source'
                : 'unresolved_radiology_source');

        return new FinanceDenied($reason, 'Satu atau lebih sumber layanan belum dapat diterbitkan.');
    }

    private function replayOutsideTransaction(User $actor, string $key, string $payloadDigest): ?FinanceMutationResult
    {
        return DB::transaction(fn () => $this->replay($actor, $key, $payloadDigest));
    }

    private function key(string $key, User $actor, string $resource): string
    {
        $key = mb_strtolower(trim($key));
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key)) {
            $denied = new FinanceDenied('validation_failed', 'Kunci idempotensi tidak valid.');
            $this->denial($actor, $resource, $denied->reason);
            throw $denied;
        }

        return $key;
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

    private function authorize(User $actor, string $resource): void
    {
        try {
            $this->policy->settle($actor);
        } catch (AuthorizationException $exception) {
            $this->denial($actor, $resource, 'role_not_permitted');
            throw $exception;
        }
    }

    private function successAudit(User $actor, FinanceBill $bill, FinanceCashSettlement $settlement): void
    {
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', $bill->public_id, $actor, 'SUCCESS', metadata: [
            'operation' => self::OPERATION_SETTLE,
            'state' => FinanceBill::ISSUED_CURRENT,
            'version' => $settlement->bill_version_snapshot,
            'settlement_public_id' => $settlement->public_id,
            'settlement_content_digest' => $settlement->content_digest,
            'settlement_result_digest' => $this->settlementFingerprints->result($settlement),
        ]) === null) {
            throw new FinanceAuditUnavailable('Audit pelunasan kas tidak tersedia.');
        }
    }

    private function denial(User $actor, string $resource, string $reason): void
    {
        $resource = Str::isUlid($resource) ? $resource : null;
        if ($this->audit->record('finance.workflow.mutate', 'finance_record', $resource, $actor, 'DENIED', $reason, [
            'operation' => self::OPERATION_SETTLE,
        ]) === null) {
            throw new FinanceAuditUnavailable('Audit penolakan pelunasan kas tidak tersedia.');
        }
    }
}
