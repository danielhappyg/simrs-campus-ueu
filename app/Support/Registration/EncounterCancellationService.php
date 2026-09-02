<?php

namespace App\Support\Registration;

use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientBed;
use App\Models\InpatientLocationEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class EncounterCancellationService
{
    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly EncounterCancellationDependencyRegistry $dependencies,
        private readonly AuditRecorder $auditRecorder,
        private readonly EncounterCancellationRaceReconciler $raceReconciler,
        private readonly CanonicalInpatientBedOperationLockCoordinator $inpatientLocks,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    public function cancel(
        Encounter $encounter,
        User $actor,
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): EncounterCancellationResult {
        $note = $this->normalizeNote($note);
        $this->validateInput($reasonCode, $note, $idempotencyKey, $requestCorrelationId);
        $digest = $this->payloadDigest($encounter, $reasonCode, $note);

        try {
            return DB::transaction(function () use (
                $encounter,
                $actor,
                $reasonCode,
                $note,
                $idempotencyKey,
                $requestCorrelationId,
                $digest,
            ): EncounterCancellationResult {
                $candidate = Encounter::query()->whereKey($encounter->getKey())->firstOrFail();
                $candidateBed = null;
                $candidateBedCode = null;
                if ($candidate->care_setting === Encounter::CARE_SETTING_INPATIENT) {
                    $this->inpatientLocks->lockPatientClaimMutexes([(int) $candidate->patient_id]);
                    $this->pharmacy->lockInventoryForEncounter((int) $candidate->id);
                    if ($candidate->inpatient_bed_id !== null) {
                        $candidateBed = InpatientBed::query()->whereKey($candidate->inpatient_bed_id)->first();
                    }
                    $candidateBedCode = $candidateBed instanceof InpatientBed ? $candidateBed->code : $candidate->bed_code;
                    if (is_string($candidateBedCode) && trim($candidateBedCode) !== '') {
                        $this->inpatientLocks->lockMutexes([$candidateBedCode]);
                    }
                } else {
                    $this->pharmacy->lockInventoryForEncounter((int) $candidate->id);
                }

                $locked = $this->inpatientLocks->lockEncounters([$candidate->id])->get($candidate->id);
                if (! $locked instanceof Encounter) {
                    abort(404);
                }

                if ($candidate->care_setting === Encounter::CARE_SETTING_INPATIENT) {
                    if ($candidateBed instanceof InpatientBed) {
                        if ($locked->inpatient_bed_id !== $candidateBed->id || $locked->bed_code !== $candidateBed->code) {
                            throw new EncounterCancellationDenied(
                                'location_activity_exists',
                                'Kunjungan tidak dapat dibatalkan karena penempatan rawat inap sudah berubah.',
                            );
                        }
                        $wards = $this->inpatientLocks->lockWards([$candidateBed->ward_id]);
                        $beds = $this->inpatientLocks->lockBeds([$candidateBed->id]);
                        $lockedBed = $beds->get($candidateBed->id);
                        if (! $lockedBed instanceof InpatientBed
                            || $lockedBed->ward_id !== $candidateBed->ward_id
                            || ! $wards->has($candidateBed->ward_id)) {
                            throw new EncounterCancellationDenied(
                                'location_activity_exists',
                                'Kunjungan tidak dapat dibatalkan karena penempatan rawat inap tidak konsisten.',
                            );
                        }
                    } elseif ($candidate->inpatient_bed_id !== null
                        || $locked->inpatient_bed_id !== $candidate->inpatient_bed_id
                        || $locked->bed_code !== $candidateBedCode) {
                        throw new EncounterCancellationDenied(
                            'location_activity_exists',
                            'Kunjungan tidak dapat dibatalkan karena penempatan rawat inap sudah berubah.',
                        );
                    }
                }

                abort_unless(
                    $locked->patient()->where('is_synthetic', true)->exists(),
                    404,
                );
                abort_unless(in_array($locked->care_setting, Encounter::CARE_SETTINGS, true), 404);

                $keyMatch = EncounterCancellation::query()
                    ->where('cancelled_by_user_id', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($keyMatch instanceof EncounterCancellation) {
                    if ($keyMatch->encounter_id === $locked->id && hash_equals($keyMatch->payload_digest, $digest)) {
                        return new EncounterCancellationResult($keyMatch, replayed: true);
                    }

                    throw new EncounterCancellationDenied(
                        'idempotency_key_conflict',
                        'Kunci idempotensi sudah digunakan untuk permintaan pembatalan yang berbeda.',
                    );
                }

                $existing = EncounterCancellation::query()
                    ->where('encounter_id', $locked->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof EncounterCancellation) {
                    throw new EncounterCancellationDenied(
                        'already_cancelled',
                        'Kunjungan sudah dibatalkan.',
                    );
                }

                if ($locked->status !== Encounter::STATUS_REGISTERED) {
                    throw new EncounterCancellationDenied(
                        'encounter_not_registered',
                        'Hanya kunjungan yang masih terdaftar dan belum menerima pelayanan yang dapat dibatalkan.',
                    );
                }

                $dependencyReason = $this->dependencies->firstDenialReason($locked);
                if ($dependencyReason !== null) {
                    throw new EncounterCancellationDenied(
                        $dependencyReason,
                        'Kunjungan tidak dapat dibatalkan karena aktivitas pelayanan sudah tercatat.',
                    );
                }

                $cancellation = EncounterCancellation::query()->create([
                    'encounter_id' => $locked->id,
                    'cancelled_by_user_id' => $actor->id,
                    'reason_code' => $reasonCode,
                    'note' => $note,
                    'idempotency_key' => $idempotencyKey,
                    'payload_digest' => $digest,
                    'request_correlation_id' => $requestCorrelationId,
                ]);
                $cancellation->refresh();

                $locked->update(['status' => Encounter::STATUS_CANCELLED]);

                $event = $this->auditRecorder->record(
                    action: 'encounter.cancel',
                    resourceType: 'encounter',
                    resourceId: $locked->public_id,
                    actor: $actor,
                    outcome: 'SUCCESS',
                    metadata: [
                        'care_setting' => $locked->care_setting,
                        'cancellation_public_id' => $cancellation->public_id,
                        'reason_code' => $reasonCode,
                        'prior_status' => Encounter::STATUS_REGISTERED,
                        'new_status' => Encounter::STATUS_CANCELLED,
                        'queue_date' => $locked->queue_date,
                        'queue_number' => $locked->queue_number,
                        'active_worklists_excluded' => true,
                        'inpatient_bed_released' => $locked->care_setting === Encounter::CARE_SETTING_INPATIENT,
                    ],
                );

                if ($event === null) {
                    throw new EncounterCancellationAuditUnavailable('Pembatalan tidak dapat disimpan karena audit gagal direkam.');
                }

                return new EncounterCancellationResult($cancellation, replayed: false);
            }, 3);
        } catch (EncounterCancellationDenied $denial) {
            $this->recordDenial($encounter, $actor, $denial);
        } catch (UniqueConstraintViolationException $race) {
            return $this->raceReconciler->resolve(
                encounter: $encounter,
                actor: $actor,
                idempotencyKey: $idempotencyKey,
                digest: $digest,
                race: $race,
            );
        }
    }

    /**
     * Bounded seam for registrar execution of a physician-signed emergency
     * correction intent. The caller must already hold the canonical patient,
     * bed, encounter, ward and bed locks in that order.
     */
    public function cancelLockedPreclinicalInpatientHandoffTarget(
        Encounter $lockedEncounter,
        User $actor,
        string $note,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): EncounterCancellationResult {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Emergency handoff compensation cancellation requires an active transaction.');
        }
        $note = $this->normalizeNote($note);
        $reasonCode = EncounterCancellation::REASON_PLAN_CHANGED_BEFORE_SERVICE;
        $this->validateInput($reasonCode, $note, $idempotencyKey, $requestCorrelationId);
        if ($lockedEncounter->care_setting !== Encounter::CARE_SETTING_INPATIENT
            || $lockedEncounter->status !== Encounter::STATUS_REGISTERED
            || $lockedEncounter->active_inpatient_patient_id !== $lockedEncounter->patient_id) {
            throw new EncounterCancellationDenied('encounter_not_registered', 'Episode rawat inap telah berkembang dan tidak dapat dikompensasi.');
        }

        $keyMatch = EncounterCancellation::query()
            ->where('cancelled_by_user_id', $actor->id)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
        $digest = $this->payloadDigest($lockedEncounter, $reasonCode, $note);
        if ($keyMatch instanceof EncounterCancellation) {
            if ($keyMatch->encounter_id === $lockedEncounter->id && hash_equals($keyMatch->payload_digest, $digest)) {
                return new EncounterCancellationResult($keyMatch, replayed: true);
            }
            throw new EncounterCancellationDenied('idempotency_key_conflict', 'Kunci pembatalan anak rawat inap telah digunakan untuk permintaan berbeda.');
        }
        if (EncounterCancellation::query()->where('encounter_id', $lockedEncounter->id)->lockForUpdate()->exists()) {
            throw new EncounterCancellationDenied('already_cancelled', 'Episode rawat inap sudah dibatalkan.');
        }
        if ($this->dependencies->firstDenialReason($lockedEncounter) !== null) {
            throw new EncounterCancellationDenied('downstream_activity_exists', 'Episode rawat inap telah memiliki aktivitas lanjutan.');
        }
        $locations = InpatientLocationEvent::query()
            ->where('encounter_id', $lockedEncounter->id)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();
        $onlyLocation = $locations->first();
        if ($locations->count() !== 1 || ! $onlyLocation instanceof InpatientLocationEvent
            || $onlyLocation->event_type !== InpatientLocationEvent::TYPE_ADMISSION
            || $onlyLocation->sequence !== 1) {
            throw new EncounterCancellationDenied('location_activity_exists', 'Riwayat lokasi rawat inap telah berkembang.');
        }

        $cancellation = EncounterCancellation::query()->create([
            'encounter_id' => $lockedEncounter->id,
            'cancelled_by_user_id' => $actor->id,
            'reason_code' => $reasonCode,
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
            'payload_digest' => $digest,
            'request_correlation_id' => $requestCorrelationId,
        ]);
        $lockedEncounter->status = Encounter::STATUS_CANCELLED;
        $lockedEncounter->save();

        $event = $this->auditRecorder->record(
            action: 'encounter.cancel',
            resourceType: 'encounter',
            resourceId: $lockedEncounter->public_id,
            actor: $actor,
            outcome: 'SUCCESS',
            metadata: [
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'cancellation_public_id' => $cancellation->public_id,
                'reason_code' => $reasonCode,
                'prior_status' => Encounter::STATUS_REGISTERED,
                'new_status' => Encounter::STATUS_CANCELLED,
                'queue_date' => $lockedEncounter->queue_date,
                'queue_number' => $lockedEncounter->queue_number,
                'active_worklists_excluded' => true,
                'inpatient_bed_released' => true,
            ],
        );
        if ($event === null) {
            throw new EncounterCancellationAuditUnavailable('Kompensasi dibatalkan karena audit pembatalan anak gagal direkam.');
        }

        return new EncounterCancellationResult($cancellation->fresh() ?? $cancellation, replayed: false);
    }

    private function recordDenial(
        Encounter $encounter,
        User $actor,
        EncounterCancellationDenied $denial,
    ): never {
        $event = $this->auditRecorder->record(
            action: 'encounter.cancel',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: ['care_setting' => $encounter->care_setting],
        );

        if ($event === null) {
            throw new EncounterCancellationAuditUnavailable('Penolakan pembatalan tidak dapat direkam dalam audit.');
        }

        throw $denial;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }

    private function validateInput(
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): void {
        if (! in_array($reasonCode, EncounterCancellation::REASON_CODES, true)) {
            throw new InvalidArgumentException('Unknown encounter cancellation reason code.');
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Encounter cancellation note exceeds 500 characters.');
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Encounter cancellation idempotency key is invalid.');
        }
        if ($requestCorrelationId !== null
            && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $requestCorrelationId) !== 1) {
            throw new InvalidArgumentException('Encounter cancellation request correlation ID is invalid.');
        }
    }

    private function payloadDigest(Encounter $encounter, string $reasonCode, ?string $note): string
    {
        $payload = json_encode([
            'encounter_public_id' => $encounter->public_id,
            'note' => $note,
            'reason_code' => $reasonCode,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }
}
