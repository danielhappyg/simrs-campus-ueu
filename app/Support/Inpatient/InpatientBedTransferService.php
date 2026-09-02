<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientLocationOperationReceipt;
use App\Models\InpatientWard;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CanonicalJson;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InpatientBedTransferService
{
    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly InpatientBedTransferActorPolicy $actorPolicy,
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    public function transfer(
        string $encounterPublicId,
        User $actor,
        int $expectedSequence,
        string $expectedSourceBedPublicId,
        string $targetBedPublicId,
        string $reason,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientBedTransferResult {
        $this->authorizeActor($actor);
        try {
            [$reason, $canonicalKey] = $this->validateAndNormalize(
                $encounterPublicId, $expectedSequence, $expectedSourceBedPublicId,
                $targetBedPublicId, $reason, $idempotencyKey, $requestCorrelationId,
            );
            $digest = $this->digest($encounterPublicId, $expectedSequence, $expectedSourceBedPublicId, $targetBedPublicId, $reason);
            if ($replay = $this->replay($actor, $canonicalKey, $digest)) {
                return $replay;
            }

            return InpatientLocationMutationScope::run(fn (): InpatientBedTransferResult => DB::transaction(function () use (
                $encounterPublicId, $actor, $expectedSequence, $expectedSourceBedPublicId, $targetBedPublicId,
                $reason, $canonicalKey, $requestCorrelationId, $digest,
            ): InpatientBedTransferResult {
                $candidateEncounter = Encounter::query()->where('public_id', $encounterPublicId)->first();
                $candidateSource = InpatientBed::query()->where('public_id', $expectedSourceBedPublicId)->first();
                $candidateTarget = InpatientBed::query()->where('public_id', $targetBedPublicId)->first();
                if (! $candidateEncounter instanceof Encounter) {
                    throw new InpatientBedTransferDenied('encounter_missing', 'Episode rawat inap tidak ditemukan.', 404);
                }
                if (! $candidateSource instanceof InpatientBed || ! $candidateTarget instanceof InpatientBed) {
                    throw new InpatientBedTransferDenied('placement_unmanaged', 'Penempatan tempat tidur tidak terkelola.');
                }

                $this->locks->lockPatientClaimMutexes([(int) $candidateEncounter->patient_id]);
                $this->pharmacy->lockInventoryForEncounter((int) $candidateEncounter->id);
                $this->locks->lockMutexes([$candidateSource->code, $candidateTarget->code]);
                $encounter = $this->locks->lockEncounters([$candidateEncounter->id])->get($candidateEncounter->id);
                if (! $encounter instanceof Encounter) {
                    throw new InpatientBedTransferDenied('encounter_missing', 'Episode rawat inap tidak ditemukan.', 404);
                }
                $wards = $this->locks->lockWards([$candidateSource->ward_id, $candidateTarget->ward_id]);
                $beds = $this->locks->lockBeds([$candidateSource->id, $candidateTarget->id]);
                $receipt = $this->receiptAfterCanonicalLocks($actor, $canonicalKey);
                if ($receipt instanceof InpatientLocationOperationReceipt) {
                    return $this->receiptResult($receipt, $digest);
                }
                $source = $beds->get($candidateSource->id);
                $target = $beds->get($candidateTarget->id);
                $sourceWard = $source instanceof InpatientBed ? $wards->get($source->ward_id) : null;
                $targetWard = $target instanceof InpatientBed ? $wards->get($target->ward_id) : null;
                if (! $source instanceof InpatientBed || ! $target instanceof InpatientBed
                    || ! $sourceWard instanceof InpatientWard || ! $targetWard instanceof InpatientWard) {
                    throw new InpatientBedTransferDenied('placement_stale', 'Penempatan telah berubah. Muat ulang sebelum melanjutkan.');
                }

                $this->assertEligible($encounter, $source, $sourceWard, $target, $targetWard, $expectedSequence);
                $sequence = $expectedSequence + 1;
                $occurredAt = now((string) config('app.timezone', 'Asia/Jakarta'));
                $previous = $expectedSequence > 0
                    ? InpatientLocationEvent::query()
                        ->where('encounter_id', $encounter->id)
                        ->where('sequence', $expectedSequence)
                        ->first()
                    : null;
                $fromSnapshot = $previous instanceof InpatientLocationEvent
                    ? $this->priorDestinationAttributes($previous)
                    : [
                        ...$this->snapshotAttributes('from', $sourceWard, $source),
                        ...$this->bedVersionAttributes('from', $this->exactVersionForLockedBed($source)),
                    ];
                $targetVersion = $this->exactVersionForLockedBed($target);
                $event = InpatientLocationEvent::query()->create([
                    'encounter_id' => $encounter->id,
                    'encounter_public_id' => $encounter->public_id,
                    'actor_user_id' => $actor->id,
                    'event_type' => InpatientLocationEvent::TYPE_TRANSFER,
                    'sequence' => $sequence,
                    ...$fromSnapshot,
                    ...$this->snapshotAttributes('to', $targetWard, $target),
                    ...$this->bedVersionAttributes('to', $targetVersion),
                    'reason' => $reason,
                    'request_correlation_id' => $requestCorrelationId,
                    'payload_digest' => $digest,
                    'occurred_at' => $occurredAt,
                ]);

                $encounter->fill([
                    'inpatient_bed_id' => $target->id,
                    'bed_code' => $target->code,
                    'ward_name' => $targetWard->display_name,
                    'ward_class' => $target->service_class,
                    'clinic_name' => $targetWard->display_name,
                ])->save();

                $audit = $this->auditRecorder->record(
                    action: 'inpatient.bed.transfer', resourceType: 'encounter', resourceId: $encounter->public_id,
                    actor: $actor, outcome: 'SUCCESS', metadata: [
                        'event_public_id' => $event->public_id, 'sequence' => $sequence,
                        'from_ward_public_id' => $sourceWard->public_id, 'from_ward_code' => $sourceWard->code,
                        'from_bed_public_id' => $source->public_id, 'from_bed_code' => $source->code,
                        'to_ward_public_id' => $targetWard->public_id, 'to_ward_code' => $targetWard->code,
                        'to_bed_public_id' => $target->public_id, 'to_bed_code' => $target->code,
                        'expected_sequence' => $expectedSequence, 'payload_digest' => $digest,
                        'request_correlation_id' => $requestCorrelationId,
                    ],
                );
                if ($audit === null) {
                    throw new InpatientBedTransferAuditUnavailable('Transfer dibatalkan karena audit tidak dapat direkam.');
                }

                InpatientLocationOperationReceipt::query()->create([
                    'encounter_id' => $encounter->id, 'actor_user_id' => $actor->id,
                    'operation' => InpatientLocationOperationReceipt::OPERATION_TRANSFER,
                    'idempotency_key' => $canonicalKey, 'payload_digest' => $digest,
                    'result_event_public_id' => $event->public_id, 'result_sequence' => $sequence,
                    'request_correlation_id' => $requestCorrelationId, 'completed_at' => $occurredAt,
                ]);

                return new InpatientBedTransferResult($event->fresh('actor') ?? $event, false);
            }, 3));
        } catch (UniqueConstraintViolationException) {
            $result = $this->replay($actor, mb_strtolower(trim($idempotencyKey)), $this->digest($encounterPublicId, $expectedSequence, $expectedSourceBedPublicId, $targetBedPublicId, trim($reason)));
            if ($result instanceof InpatientBedTransferResult) {
                return $result;
            }
            $denial = new InpatientBedTransferDenied('stale_location', 'Lokasi telah berubah. Muat ulang sebelum melanjutkan.');
            $this->recordDenialOrFail($actor, $encounterPublicId, $denial);
            throw $denial;
        } catch (QueryException) {
            $denial = new InpatientBedTransferDenied(
                'persistence_unavailable',
                'Transfer dibatalkan karena penyimpanan tidak tersedia.',
                503,
            );
            $this->recordDenialOrFail($actor, $encounterPublicId, $denial);
            throw $denial;
        } catch (InvalidArgumentException $exception) {
            $denial = new InpatientBedTransferDenied('validation_failed', $exception->getMessage());
            $this->recordDenialOrFail($actor, $encounterPublicId, $denial);
            throw $denial;
        } catch (InpatientBedTransferDenied $denial) {
            $this->recordDenialOrFail($actor, $encounterPublicId, $denial);
            throw $denial;
        }
    }

    /**
     * Authorize and audit before request shape validation or manual encounter/bed lookup.
     */
    public function authorizeActor(User $actor): void
    {
        try {
            $this->actorPolicy->authorize($actor);
        } catch (AuthorizationException $denial) {
            if ($this->auditRecorder->record(
                action: 'inpatient.bed.transfer',
                resourceType: 'encounter',
                resourceId: null,
                actor: $actor,
                outcome: 'DENIED',
                reason: 'unauthorized_actor',
                metadata: [],
            ) === null) {
                throw new InpatientBedTransferAuditUnavailable('Penolakan transfer tidak dapat diaudit.');
            }

            throw $denial;
        }
    }

    public function recordValidationDenial(User $actor, string $encounterPublicId): void
    {
        $this->recordDenialOrFail(
            $actor,
            $encounterPublicId,
            new InpatientBedTransferDenied('validation_failed', 'Permintaan transfer tidak valid.'),
        );
    }

    public function recordAdmission(Encounter $encounter, InpatientWard $ward, InpatientBed $bed, User $actor, ?string $correlation): InpatientLocationEvent
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Admission location events require the registration transaction.');
        }
        $payload = ['operation' => InpatientLocationEvent::TYPE_ADMISSION, 'encounter_public_id' => $encounter->public_id, 'to_bed_public_id' => $bed->public_id];
        $bedVersion = $this->exactVersionForLockedBed($bed);

        return InpatientLocationMutationScope::run(fn (): InpatientLocationEvent => InpatientLocationEvent::query()->create([
            'encounter_id' => $encounter->id, 'encounter_public_id' => $encounter->public_id,
            'actor_user_id' => $actor->id, 'event_type' => InpatientLocationEvent::TYPE_ADMISSION, 'sequence' => 1,
            ...$this->snapshotAttributes('to', $ward, $bed), 'reason' => null,
            ...$this->bedVersionAttributes('to', $bedVersion),
            'request_correlation_id' => $correlation, 'payload_digest' => hash('sha256', CanonicalJson::encode($payload)),
            'occurred_at' => $encounter->registered_at,
        ]));
    }

    private function assertEligible(Encounter $encounter, InpatientBed $source, InpatientWard $sourceWard, InpatientBed $target, InpatientWard $targetWard, int $expectedSequence): void
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            throw new InpatientBedTransferDenied('not_inpatient', 'Episode bukan rawat inap.');
        }
        if (! in_array($encounter->status, Encounter::BED_OCCUPYING_STATUSES, true)) {
            throw new InpatientBedTransferDenied($encounter->status === Encounter::STATUS_CANCELLED ? 'encounter_cancelled' : 'encounter_closed', 'Episode tidak dapat dipindahkan.');
        }
        if ($encounter->cancellation()->exists()) {
            throw new InpatientBedTransferDenied('encounter_cancelled', 'Episode telah dibatalkan.');
        }
        if (! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new InpatientBedTransferDenied('synthetic_only', 'Episode tidak berada dalam batas data yang diizinkan.');
        }
        if ($this->pharmacy->inspect($encounter)['active_preparation_public_ids'] !== []) {
            throw new InpatientBedTransferDenied('active_pharmacy_preparation', 'Pasien belum dapat dipindahkan karena penyiapan obat masih aktif.');
        }
        $summary = InpatientDischargeSummary::query()
            ->where('encounter_id', $encounter->id)
            ->lockForUpdate()
            ->first();
        if ($summary instanceof InpatientDischargeSummary
            && $summary->summary_state === InpatientDischargeSummary::STATE_FINAL) {
            throw new InpatientBedTransferDenied('discharge_summary_final', 'Lokasi tidak dapat dipindahkan setelah ringkasan pulang menjadi Final.');
        }
        $codingSource = InpatientDischargeCodingSource::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
        if ($codingSource instanceof InpatientDischargeCodingSource && $codingSource->source_state === InpatientDischargeCodingSource::STATE_FINAL) {
            throw new InpatientBedTransferDenied('discharge_coding_source_final', 'Lokasi tidak dapat dipindahkan setelah diagnosis dan prosedur akhir menjadi Final.');
        }
        if ($encounter->inpatient_bed_id === null) {
            throw new InpatientBedTransferDenied('placement_missing', 'Penempatan saat ini tidak tersedia.');
        }
        if ($encounter->inpatient_bed_id !== $source->id || $encounter->bed_code !== $source->code) {
            throw new InpatientBedTransferDenied('source_bed_changed', 'Tempat tidur sumber telah berubah.');
        }
        if ($source->state !== InpatientBed::STATE_ACTIVE || $sourceWard->state !== InpatientWard::STATE_ACTIVE) {
            throw new InpatientBedTransferDenied('source_inactive', 'Tempat tidur sumber tidak aktif.');
        }
        if ($target->state !== InpatientBed::STATE_ACTIVE || $targetWard->state !== InpatientWard::STATE_ACTIVE) {
            throw new InpatientBedTransferDenied('target_inactive', 'Tempat tidur tujuan tidak aktif.');
        }
        if ($source->id === $target->id) {
            throw new InpatientBedTransferDenied('same_bed', 'Tempat tidur tujuan harus berbeda.');
        }
        if ($source->service_class !== $target->service_class) {
            throw new InpatientBedTransferDenied('service_class_change_not_authorized', 'Perubahan kelas layanan tidak diizinkan.');
        }
        $currentSequence = (int) InpatientLocationEvent::query()->where('encounter_id', $encounter->id)->max('sequence');
        if ($currentSequence !== $expectedSequence) {
            throw new InpatientBedTransferDenied('stale_location', 'Riwayat lokasi telah berubah.', metadata: ['current_sequence' => $currentSequence]);
        }
        $targetClaims = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where(function ($query) use ($target): void {
                $query->where('inpatient_bed_id', $target->id)
                    ->orWhere('bed_code', $target->code);
            })->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)->whereKeyNot($encounter->id)
            ->lockForUpdate()->get(['id']);
        if ($targetClaims->isNotEmpty()) {
            throw new InpatientBedTransferDenied('target_occupied', 'Tempat tidur tujuan sedang terisi.');
        }
        $sourceClaims = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where(function ($query) use ($source): void {
                $query->where('inpatient_bed_id', $source->id)
                    ->orWhere('bed_code', $source->code);
            })->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)->lockForUpdate()->get(['id']);
        if ($sourceClaims->count() !== 1) {
            throw new InpatientBedTransferDenied('duplicate_current_claim', 'Klaim penempatan saat ini tidak konsisten.');
        }
    }

    /** @return array<string, string> */
    private function snapshotAttributes(string $prefix, InpatientWard $ward, InpatientBed $bed): array
    {
        return [
            $prefix.'_ward_public_id' => $ward->public_id, $prefix.'_ward_code' => $ward->code,
            $prefix.'_ward_display_name' => $ward->display_name, $prefix.'_bed_public_id' => $bed->public_id,
            $prefix.'_bed_code' => $bed->code, $prefix.'_bed_display_name' => $bed->display_name,
            $prefix.'_room_label' => $bed->room_label, $prefix.'_service_class' => $bed->service_class,
        ];
    }

    /** @return array<string, int|string> */
    private function bedVersionAttributes(string $prefix, InpatientBedVersion $version): array
    {
        return [
            $prefix.'_inpatient_bed_version_id' => $version->id,
            $prefix.'_inpatient_bed_version_public_id' => $version->public_id,
            $prefix.'_inpatient_bed_version' => $version->version,
            $prefix.'_inpatient_bed_after_digest' => $version->after_digest,
        ];
    }

    /** @return array<string, int|string|null> */
    private function priorDestinationAttributes(InpatientLocationEvent $previous): array
    {
        return [
            'from_ward_public_id' => $previous->to_ward_public_id,
            'from_ward_code' => $previous->to_ward_code,
            'from_ward_display_name' => $previous->to_ward_display_name,
            'from_bed_public_id' => $previous->to_bed_public_id,
            'from_bed_code' => $previous->to_bed_code,
            'from_bed_display_name' => $previous->to_bed_display_name,
            'from_room_label' => $previous->to_room_label,
            'from_service_class' => $previous->to_service_class,
            'from_inpatient_bed_version_id' => $previous->to_inpatient_bed_version_id,
            'from_inpatient_bed_version_public_id' => $previous->to_inpatient_bed_version_public_id,
            'from_inpatient_bed_version' => $previous->to_inpatient_bed_version,
            'from_inpatient_bed_after_digest' => $previous->to_inpatient_bed_after_digest,
        ];
    }

    private function exactVersionForLockedBed(InpatientBed $bed): InpatientBedVersion
    {
        $version = InpatientBedVersion::query()
            ->where('bed_id', $bed->id)
            ->where('version', $bed->version)
            ->first();
        if (! $version instanceof InpatientBedVersion) {
            throw new \LogicException('Locked inpatient bed has no matching immutable version evidence.');
        }

        return $version;
    }

    /** @return array{string,string} */
    private function validateAndNormalize(string $encounter, int $sequence, string $source, string $target, string $reason, string $key, ?string $correlation): array
    {
        if (! Str::isUlid($encounter) || ! Str::isUlid($source) || ! Str::isUlid($target)) {
            throw new InvalidArgumentException('Identitas operasi tidak valid.');
        }
        if ($sequence < 0) {
            throw new InvalidArgumentException('Urutan lokasi tidak boleh negatif.');
        }
        if (! mb_check_encoding($reason, 'UTF-8')) {
            throw new InvalidArgumentException('Alasan harus berupa UTF-8 yang valid.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Alasan harus berisi 5-500 karakter.');
        }
        $key = trim($key);
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('Kunci idempotensi harus berisi 8-255 karakter aman.');
        }
        if ($correlation !== null && ! Str::isUlid($correlation)) {
            throw new InvalidArgumentException('Identitas korelasi tidak valid.');
        }

        return [$reason, mb_strtolower($key)];
    }

    private function digest(string $encounter, int $sequence, string $source, string $target, string $reason): string
    {
        return hash('sha256', CanonicalJson::encode([
            'operation' => InpatientLocationOperationReceipt::OPERATION_TRANSFER,
            'encounter_public_id' => $encounter, 'expected_location_sequence' => $sequence,
            'expected_source_bed_public_id' => $source, 'target_bed_public_id' => $target,
            'reason' => $reason,
        ]));
    }

    private function receiptAfterCanonicalLocks(User $actor, string $key): ?InpatientLocationOperationReceipt
    {
        $query = InpatientLocationOperationReceipt::query()->where('actor_user_id', $actor->id)
            ->where('operation', InpatientLocationOperationReceipt::OPERATION_TRANSFER)
            ->where('idempotency_key', $key);

        return DB::connection()->getDriverName() === 'mysql'
            ? $query->sharedLock()->first()
            : $query->first();
    }

    private function replay(User $actor, string $key, string $digest): ?InpatientBedTransferResult
    {
        $receipt = InpatientLocationOperationReceipt::query()->where('actor_user_id', $actor->id)
            ->where('operation', InpatientLocationOperationReceipt::OPERATION_TRANSFER)->where('idempotency_key', $key)->first();

        return $receipt instanceof InpatientLocationOperationReceipt ? $this->receiptResult($receipt, $digest) : null;
    }

    private function receiptResult(InpatientLocationOperationReceipt $receipt, string $digest): InpatientBedTransferResult
    {
        if (! hash_equals($receipt->payload_digest, $digest)) {
            throw new InpatientBedTransferDenied('idempotency_key_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
        }
        $eventQuery = InpatientLocationEvent::query()->where('public_id', $receipt->result_event_public_id)
            ->where('encounter_id', $receipt->encounter_id)->where('sequence', $receipt->result_sequence)
            ->where('payload_digest', $receipt->payload_digest);
        $event = DB::connection()->getDriverName() === 'mysql'
            ? $eventQuery->sharedLock()->first()
            : $eventQuery->first();
        if (! $event instanceof InpatientLocationEvent) {
            throw new InpatientBedTransferDenied('idempotency_key_conflict', 'Bukti idempotensi tidak merujuk hasil yang sah.');
        }

        return new InpatientBedTransferResult($event, true);
    }

    private function recordDenialOrFail(User $actor, string $encounterPublicId, InpatientBedTransferDenied $denial): void
    {
        $resourceId = Str::isUlid($encounterPublicId) ? $encounterPublicId : null;
        if ($this->auditRecorder->record(action: 'inpatient.bed.transfer', resourceType: 'encounter', resourceId: $resourceId,
            actor: $actor, outcome: 'DENIED', reason: $denial->reason, metadata: $denial->metadata) === null) {
            throw new InpatientBedTransferAuditUnavailable('Penolakan transfer tidak dapat diaudit.');
        }
    }
}
