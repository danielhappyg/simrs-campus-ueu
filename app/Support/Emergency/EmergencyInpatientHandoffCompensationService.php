<?php

namespace App\Support\Emergency;

use App\Models\EmergencyDisposition;
use App\Models\EmergencyDispositionCorrectionIntent;
use App\Models\EmergencyDispositionCorrectionIntentEvent;
use App\Models\EmergencyHandoffCompensation;
use App\Models\EmergencyInpatientHandoff;
use App\Models\EmergencyOperationReceipt;
use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientBed;
use App\Models\InpatientDischarge;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientRmCoding;
use App\Models\InpatientRmCompletenessReview;
use App\Models\InpatientSummaryAddendum;
use App\Models\InpatientSummaryCorrectionRequest;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Registration\EncounterCancellationAuditUnavailable;
use App\Support\Registration\EncounterCancellationDenied;
use App\Support\Registration\EncounterCancellationDependencyRegistry;
use App\Support\Registration\EncounterCancellationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmergencyInpatientHandoffCompensationService
{
    public const OPERATION = 'EMERGENCY_INPATIENT_HANDOFF_COMPENSATION';

    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly EmergencyActorPolicy $actors,
        private readonly EmergencyEvidenceFingerprint $fingerprints,
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
        private readonly EncounterCancellationService $cancellations,
        private readonly EncounterCancellationDependencyRegistry $dependencies,
        private readonly AuditRecorder $audit,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    public function execute(
        string $sourceEncounterPublicId,
        User $actor,
        string $correctionIntentPublicId,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): EmergencyMutationResult {
        $this->authorizeOrAudit($actor);
        $key = trim($idempotencyKey);
        if (! Str::isUlid($sourceEncounterPublicId) || ! Str::isUlid($correctionIntentPublicId)
            || preg_match(self::KEY_PATTERN, $key) !== 1
            || ($requestCorrelationId !== null && ! Str::isUlid($requestCorrelationId))) {
            $denial = new EmergencyDenied('validation_failed', 'Permintaan kompensasi serah-terima tidak valid.');
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        }
        $payloadDigest = EmergencyCanonicalJson::digest([
            'source_encounter_public_id' => $sourceEncounterPublicId,
            'correction_intent_public_id' => $correctionIntentPublicId,
        ]);

        try {
            return DB::transaction(function () use ($sourceEncounterPublicId, $actor, $correctionIntentPublicId, $key, $requestCorrelationId, $payloadDigest): EmergencyMutationResult {
                $candidateSource = Encounter::query()->where('public_id', $sourceEncounterPublicId)->first();
                $candidateIntent = EmergencyDispositionCorrectionIntent::query()->where('public_id', $correctionIntentPublicId)->first();
                if (! $candidateSource instanceof Encounter || ! $candidateIntent instanceof EmergencyDispositionCorrectionIntent
                    || $candidateIntent->encounter_id !== $candidateSource->id) {
                    throw new EmergencyDenied('resource_not_found', 'Maksud koreksi atau episode IGD tidak ditemukan.');
                }
                $candidateHandoff = EmergencyInpatientHandoff::query()->whereKey($candidateIntent->handoff_id)->first();
                if (! $candidateHandoff instanceof EmergencyInpatientHandoff || $candidateHandoff->source_encounter_id !== $candidateSource->id) {
                    throw new EmergencyDenied('handoff_binding_invalid', 'Bukti serah-terima tidak cocok dengan maksud koreksi.');
                }
                $candidateTarget = Encounter::query()->whereKey($candidateHandoff->target_encounter_id)->first();
                $candidateBed = InpatientBed::query()->whereKey($candidateHandoff->inpatient_bed_id)->first();
                $patient = Patient::query()->whereKey($candidateSource->patient_id)->first();
                if (! $candidateTarget instanceof Encounter || ! $candidateBed instanceof InpatientBed
                    || ! $patient instanceof Patient || ! $patient->is_synthetic) {
                    throw new EmergencyDenied('handoff_binding_invalid', 'Graf serah-terima tidak lengkap.');
                }

                $this->locks->lockPatientClaimMutexes([(int) $patient->id]);
                $this->pharmacy->lockInventoryForEncounter((int) $candidateTarget->id);
                $this->locks->lockMutexes([$candidateBed->code]);
                $claimIds = Encounter::query()
                    ->where(function ($query) use ($patient, $candidateBed): void {
                        $query->where('active_inpatient_patient_id', $patient->id)
                            ->orWhere(function ($bedQuery) use ($candidateBed): void {
                                $bedQuery->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
                                    ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
                                    ->where(function ($placement) use ($candidateBed): void {
                                        $placement->where('inpatient_bed_id', $candidateBed->id)
                                            ->orWhere('bed_code', $candidateBed->code);
                                    });
                            });
                    })->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
                $claimIds = array_values($claimIds);
                $encounters = $this->locks->lockEncounters([$candidateSource->id, $candidateTarget->id, ...$claimIds]);
                $ward = $this->locks->lockWards([(int) $candidateBed->ward_id])->get((int) $candidateBed->ward_id);
                $bed = $this->locks->lockBeds([(int) $candidateBed->id])->get((int) $candidateBed->id);
                if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed
                    || $bed->ward_id !== $ward->id || $bed->code !== $candidateBed->code) {
                    throw new EmergencyDenied('bed_reconciliation_invalid', 'Penempatan serah-terima tidak lagi konsisten.');
                }

                $source = $encounters->get($candidateSource->id);
                $target = $encounters->get($candidateTarget->id);
                $intent = EmergencyDispositionCorrectionIntent::query()->whereKey($candidateIntent->id)->lockForUpdate()->first();
                $handoff = EmergencyInpatientHandoff::query()->whereKey($candidateHandoff->id)->lockForUpdate()->first();
                if (! $source instanceof Encounter || ! $target instanceof Encounter
                    || ! $intent instanceof EmergencyDispositionCorrectionIntent || ! $handoff instanceof EmergencyInpatientHandoff) {
                    throw new EmergencyDenied('concurrent_change', 'Graf serah-terima berubah bersamaan.');
                }
                $currentDisposition = EmergencyDisposition::query()->whereKey($intent->current_disposition_id)->lockForUpdate()->first();
                $intentEvents = $intent->events()->orderBy('id')->lockForUpdate()->get();
                $existingCompensation = EmergencyHandoffCompensation::query()->where('handoff_id', $handoff->id)->lockForUpdate()->first();
                $existingCancellation = EncounterCancellation::query()->where('encounter_id', $target->id)->lockForUpdate()->first();
                $locations = InpatientLocationEvent::query()->where('encounter_id', $target->id)->orderBy('sequence')->lockForUpdate()->get();

                // The idempotency receipt is deliberately last in the global
                // lock sequence, after every operation-specific evidence row.
                $receipt = EmergencyOperationReceipt::query()
                    ->where('actor_user_id', $actor->id)->where('operation', self::OPERATION)
                    ->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($receipt instanceof EmergencyOperationReceipt) {
                    return $this->replayFromReceipt($receipt, $payloadDigest, $handoff);
                }

                $this->assertEligible(
                    $source,
                    $target,
                    $intent,
                    $handoff,
                    $patient,
                    $bed,
                    $claimIds,
                    $intentEvents->isNotEmpty(),
                    $existingCompensation instanceof EmergencyHandoffCompensation,
                    $existingCancellation instanceof EncounterCancellation,
                    $locations,
                );
                if (! $currentDisposition instanceof EmergencyDisposition
                    || $currentDisposition->encounter_id !== $source->id
                    || (int) EmergencyDisposition::query()->where('encounter_id', $source->id)->max('version') !== $currentDisposition->version) {
                    throw new EmergencyDenied('disposition_changed', 'Disposisi sumber telah berubah setelah maksud koreksi ditandatangani.');
                }
                $intentFingerprint = $this->fingerprints->correctionIntent($intent);
                if (! hash_equals((string) $intent->source_encounter_fingerprint, $this->fingerprints->encounter($source))
                    || ! hash_equals((string) $intent->disposition_fingerprint, $this->fingerprints->disposition($currentDisposition))
                    || ! hash_equals((string) $intent->handoff_fingerprint, $this->fingerprints->handoff($handoff))
                    || ! hash_equals((string) $intent->target_encounter_fingerprint, $this->fingerprints->encounter($target))) {
                    throw new EmergencyDenied('correction_intent_stale', 'Maksud koreksi tidak lagi cocok dengan keadaan terkunci.');
                }
                $cancellation = $this->cancellations->cancelLockedPreclinicalInpatientHandoffTarget(
                    lockedEncounter: $target,
                    actor: $actor,
                    note: mb_substr($intent->reason, 0, 500),
                    idempotencyKey: 'emergency-compensation-cancel:'.$intent->public_id,
                    requestCorrelationId: $requestCorrelationId,
                )->cancellation;
                $replacement = $this->appendReplacementDisposition($source, $intent, $currentDisposition);
                $source->status = $replacement->disposition_type === 'RAWAT_INAP'
                    ? Encounter::STATUS_IN_EXAMINATION
                    : Encounter::STATUS_READY_FOR_RM;
                $source->save();

                $now = now((string) config('app.timezone', 'Asia/Jakarta'));
                $eventPayload = [
                    'correction_intent_id' => $intent->id,
                    'actor_user_id' => $actor->id,
                    'event_type' => 'EXECUTED',
                    'reason' => null,
                    'intent_fingerprint' => $intentFingerprint,
                    'occurred_at' => $now,
                ];
                $eventPayload['content_digest'] = EmergencyCanonicalJson::digest($eventPayload);
                $eventPayload['created_at'] = $now;
                EmergencyMutationScope::run(fn () => EmergencyDispositionCorrectionIntentEvent::query()->create($eventPayload));

                $location = $locations->first();
                if (! $location instanceof InpatientLocationEvent) {
                    throw new EmergencyDenied('child_progressed', 'Riwayat lokasi anak tidak tersedia.');
                }
                // The patient and bed mutexes plus the complete claim set are
                // already locked. Re-read without acquiring a later lock out
                // of the global ordering sequence.
                $remainingPatientClaims = Encounter::query()->where('active_inpatient_patient_id', $patient->id)->count();
                $remainingBedClaims = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
                    ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
                    ->where(function ($query) use ($bed): void {
                        $query->where('inpatient_bed_id', $bed->id)->orWhere('bed_code', $bed->code);
                    })->count();
                if ($remainingPatientClaims !== 0 || $remainingBedClaims !== 0) {
                    throw new EmergencyDenied('bed_reconciliation_invalid', 'Klaim pasien atau tempat tidur belum dilepas secara atomik.');
                }

                $attributes = [
                    'handoff_id' => $handoff->id,
                    'correction_intent_id' => $intent->id,
                    'replacement_disposition_id' => $replacement->id,
                    'actor_user_id' => $actor->id,
                    'target_cancellation_fingerprint' => $this->fingerprints->cancellation($cancellation),
                    'bed_reconciliation_fingerprint' => EmergencyCanonicalJson::digest([
                        'patient_id' => $patient->id, 'bed_id' => $bed->id, 'bed_code' => $bed->code,
                        'target_encounter_id' => $target->id, 'target_status' => $target->status,
                        'active_patient_claims' => $remainingPatientClaims, 'active_bed_claims' => $remainingBedClaims,
                    ]),
                    'location_reconciliation_fingerprint' => EmergencyCanonicalJson::digest([
                        'location_fingerprint' => $this->fingerprints->location($location),
                        'target_cancellation_fingerprint' => $this->fingerprints->cancellation($cancellation),
                    ]),
                    'compensated_at' => $now,
                    'created_at' => $now,
                ];
                $attributes['content_digest'] = $this->fingerprints->compensationPayload($attributes);
                $compensation = EmergencyMutationScope::run(fn (): EmergencyHandoffCompensation => EmergencyHandoffCompensation::query()->create($attributes));

                $this->recordSuccessOrFail($actor, $source);
                EmergencyMutationScope::run(fn () => EmergencyOperationReceipt::query()->create([
                    'actor_user_id' => $actor->id, 'operation' => self::OPERATION, 'idempotency_key' => $key,
                    'payload_digest' => $payloadDigest, 'result_type' => 'HANDOFF_COMPENSATION',
                    'result_public_id' => $compensation->public_id, 'result_version' => 1,
                    'result_state' => 'COMPENSATED', 'result_digest' => $compensation->content_digest,
                    'request_correlation_id' => $requestCorrelationId, 'completed_at' => $now,
                ]));

                return new EmergencyMutationResult($compensation, false);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $denial = new EmergencyDenied('concurrent_change', 'Kompensasi berubah bersamaan. Muat ulang sebelum melanjutkan.');
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        } catch (EncounterCancellationDenied $exception) {
            $denial = new EmergencyDenied('child_progressed', $exception->getMessage());
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        } catch (EncounterCancellationAuditUnavailable $exception) {
            throw new EmergencyAuditUnavailable($exception->getMessage());
        } catch (EmergencyDenied $denial) {
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        }
    }

    /**
     * @param  list<int>  $claimIds
     * @param  Collection<int, InpatientLocationEvent>  $locations
     */
    private function assertEligible(
        Encounter $source,
        Encounter $target,
        EmergencyDispositionCorrectionIntent $intent,
        EmergencyInpatientHandoff $handoff,
        Patient $patient,
        InpatientBed $bed,
        array $claimIds,
        bool $intentHasEvents,
        bool $hasCompensation,
        bool $hasCancellation,
        Collection $locations,
    ): void {
        sort($claimIds, SORT_NUMERIC);
        $firstLocation = $locations->first();

        if ($source->care_setting !== Encounter::CARE_SETTING_EMERGENCY || $source->status !== Encounter::STATUS_READY_FOR_RM
            || $target->care_setting !== Encounter::CARE_SETTING_INPATIENT || $target->status !== Encounter::STATUS_REGISTERED
            || $source->patient_id !== $patient->id || $target->patient_id !== $patient->id
            || $target->active_inpatient_patient_id !== $patient->id
            || $target->inpatient_bed_id !== $bed->id || $target->bed_code !== $bed->code
            || $handoff->source_encounter_id !== $source->id || $handoff->target_encounter_id !== $target->id
            || $intent->handoff_id !== $handoff->id || $claimIds !== [(int) $target->id]) {
            throw new EmergencyDenied('child_progressed', 'Episode anak atau tautan pasien/tempat tidur telah berubah.');
        }
        if ($intent->expires_at->isPast()) {
            throw new EmergencyDenied('correction_intent_expired', 'Maksud koreksi telah kedaluwarsa.');
        }
        if ($intentHasEvents) {
            throw new EmergencyDenied('correction_intent_not_pending', 'Maksud koreksi tidak lagi menunggu eksekusi.');
        }
        if ($hasCompensation || $hasCancellation
            || $this->dependencies->firstDenialReason($target) !== null
            || $this->hasExtendedDownstreamEvidence($target)) {
            throw new EmergencyDenied('child_progressed', 'Episode anak telah memiliki bukti lanjutan dan tidak dapat dibatalkan.');
        }
        if ($locations->count() !== 1 || ! $firstLocation instanceof InpatientLocationEvent
            || $firstLocation->id !== $handoff->inpatient_location_event_id
            || $firstLocation->event_type !== InpatientLocationEvent::TYPE_ADMISSION || $firstLocation->sequence !== 1) {
            throw new EmergencyDenied('child_progressed', 'Riwayat lokasi anak telah berkembang.');
        }
    }

    private function hasExtendedDownstreamEvidence(Encounter $target): bool
    {
        return InpatientDischargeSummary::query()->where('encounter_id', $target->id)->exists()
            || InpatientDischargeCodingSource::query()->where('encounter_id', $target->id)->exists()
            || InpatientDischarge::query()->where('encounter_id', $target->id)->exists()
            || InpatientRmCoding::query()->where('encounter_id', $target->id)->exists()
            || InpatientRmCompletenessReview::query()->where('encounter_id', $target->id)->exists()
            || InpatientSummaryCorrectionRequest::query()->where('encounter_id', $target->id)->exists()
            || InpatientSummaryAddendum::query()->where('encounter_id', $target->id)->exists();
    }

    private function appendReplacementDisposition(Encounter $source, EmergencyDispositionCorrectionIntent $intent, EmergencyDisposition $current): EmergencyDisposition
    {
        $signedAt = $intent->created_at;
        $attributes = [
            'encounter_id' => $source->id,
            'physician_user_id' => $intent->physician_user_id,
            'prior_disposition_id' => $current->id,
            'version' => $current->version + 1,
            'disposition_type' => $intent->replacement_type,
            'payload' => $intent->replacement_payload,
            'correction_reason' => $intent->reason,
            'prior_disposition_digest' => $this->fingerprints->disposition($current),
            'signed_at' => $signedAt,
            'created_at' => now((string) config('app.timezone', 'Asia/Jakarta')),
        ];
        $attributes['content_digest'] = $this->fingerprints->dispositionPayload(
            $attributes['encounter_id'], $attributes['physician_user_id'], $attributes['prior_disposition_id'],
            $attributes['version'], $attributes['disposition_type'], $attributes['payload'],
            $attributes['correction_reason'], $attributes['prior_disposition_digest'], $signedAt->toJSON(),
        );

        return EmergencyMutationScope::run(fn (): EmergencyDisposition => EmergencyDisposition::query()->create($attributes));
    }

    private function replayFromReceipt(EmergencyOperationReceipt $receipt, string $payloadDigest, EmergencyInpatientHandoff $handoff): EmergencyMutationResult
    {
        if (! hash_equals((string) $receipt->payload_digest, $payloadDigest)
            || $receipt->result_type !== 'HANDOFF_COMPENSATION' || $receipt->result_version !== 1
            || $receipt->result_state !== 'COMPENSATED') {
            throw new EmergencyDenied('idempotency_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
        }
        $compensation = EmergencyHandoffCompensation::query()->where('public_id', $receipt->result_public_id)->first();
        if (! $compensation instanceof EmergencyHandoffCompensation || $compensation->handoff_id !== $handoff->id
            || ! hash_equals($this->fingerprints->compensation($compensation), (string) $receipt->result_digest)) {
            throw new EmergencyDenied('receipt_corrupt', 'Bukti kompensasi tidak lagi cocok.');
        }

        return new EmergencyMutationResult($compensation, true);
    }

    private function authorizeOrAudit(User $actor): void
    {
        try {
            $this->actors->dispositionCompensation($actor);
        } catch (AuthorizationException $exception) {
            $denial = new EmergencyDenied('role_not_permitted', 'Aktor tidak diizinkan menjalankan kompensasi disposisi.');
            $this->denyOrFail($actor, null, $denial);
            throw $exception;
        }
    }

    private function recordSuccessOrFail(User $actor, Encounter $source): void
    {
        if ($this->audit->record('emergency.workflow.mutate', 'emergency_record', $source->public_id, $actor, 'SUCCESS', null, ['operation' => self::OPERATION]) === null) {
            throw new EmergencyAuditUnavailable('Audit kompensasi serah-terima gagal.');
        }
    }

    private function denyOrFail(User $actor, ?string $sourcePublicId, EmergencyDenied $denial): void
    {
        $resource = is_string($sourcePublicId) && Str::isUlid($sourcePublicId) ? $sourcePublicId : null;
        if ($this->audit->record('emergency.workflow.mutate', 'emergency_record', $resource, $actor, 'DENIED', $denial->reason, ['operation' => self::OPERATION]) === null) {
            throw new EmergencyAuditUnavailable('Audit penolakan kompensasi serah-terima gagal.');
        }
    }
}
