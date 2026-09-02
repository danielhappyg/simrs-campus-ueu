<?php

namespace App\Support\Emergency;

use App\Models\EmergencyDisposition;
use App\Models\EmergencyInpatientHandoff;
use App\Models\EmergencyOperationReceipt;
use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientAdmissionLockContext;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Inpatient\InpatientMasterDenied;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Registration\InpatientBedUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmergencyInpatientHandoffService
{
    public const OPERATION = 'EMERGENCY_INPATIENT_HANDOFF';

    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly EmergencyActorPolicy $actors,
        private readonly EmergencyEvidenceFingerprint $fingerprints,
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
        private readonly InpatientAdmissionService $admissions,
        private readonly AuditRecorder $audit,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    public function execute(
        string $sourceEncounterPublicId,
        User $actor,
        int $expectedDispositionVersion,
        string $bedPublicId,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): EmergencyMutationResult {
        $this->authorizeOrAudit($actor);
        $key = trim($idempotencyKey);
        if (! Str::isUlid($sourceEncounterPublicId) || ! Str::isUlid($bedPublicId)
            || $expectedDispositionVersion < 1 || preg_match(self::KEY_PATTERN, $key) !== 1
            || ($requestCorrelationId !== null && ! Str::isUlid($requestCorrelationId))) {
            $denial = new EmergencyDenied('validation_failed', 'Permintaan serah-terima rawat inap tidak valid.');
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        }
        $payloadDigest = EmergencyCanonicalJson::digest([
            'source_encounter_public_id' => $sourceEncounterPublicId,
            'expected_disposition_version' => $expectedDispositionVersion,
            'bed_public_id' => $bedPublicId,
        ]);

        try {
            return DB::transaction(function () use ($sourceEncounterPublicId, $actor, $expectedDispositionVersion, $bedPublicId, $key, $requestCorrelationId, $payloadDigest): EmergencyMutationResult {
                $candidateSource = Encounter::query()->where('public_id', $sourceEncounterPublicId)->first();
                $candidateBed = InpatientBed::query()->where('public_id', $bedPublicId)->first();
                if (! $candidateSource instanceof Encounter || ! $candidateBed instanceof InpatientBed) {
                    throw new EmergencyDenied('resource_not_found', 'Episode IGD atau tempat tidur tidak ditemukan.');
                }
                $patient = Patient::query()->whereKey($candidateSource->patient_id)->first();
                if (! $patient instanceof Patient || ! $patient->is_synthetic) {
                    throw new EmergencyDenied('resource_not_found', 'Episode IGD tidak ditemukan.');
                }

                // Canonical global order: patient claim, bed mutex, every
                // involved encounter, ward, bed, handoff/receipt evidence.
                $this->locks->lockPatientClaimMutexes([(int) $patient->id]);
                $this->pharmacy->lockInventoryForEncounter((int) $candidateSource->id);
                $this->locks->lockMutexes([$candidateBed->code]);
                $existingHandoff = EmergencyInpatientHandoff::query()
                    ->where('source_encounter_id', $candidateSource->id)
                    ->first();
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
                    })
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();
                $encounterIds = [$candidateSource->id, ...$claimIds];
                if ($existingHandoff instanceof EmergencyInpatientHandoff) {
                    $encounterIds[] = (int) $existingHandoff->target_encounter_id;
                }
                $encounters = $this->locks->lockEncounters($encounterIds);
                $ward = $this->locks->lockWards([(int) $candidateBed->ward_id])->get((int) $candidateBed->ward_id);
                $bed = $this->locks->lockBeds([(int) $candidateBed->id])->get((int) $candidateBed->id);
                if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed
                    || $bed->ward_id !== $ward->id || $bed->code !== $candidateBed->code) {
                    throw new EmergencyDenied('bed_changed', 'Tempat tidur berubah saat serah-terima.');
                }

                // Operation evidence is locked after the shared patient/bed
                // graph and before the idempotency receipt.
                $source = $encounters->get($candidateSource->id);
                $lockedHandoff = EmergencyInpatientHandoff::query()
                    ->where('source_encounter_id', $candidateSource->id)
                    ->lockForUpdate()
                    ->first();
                $disposition = EmergencyDisposition::query()
                    ->where('encounter_id', $candidateSource->id)
                    ->where('version', $expectedDispositionVersion)
                    ->lockForUpdate()
                    ->first();
                $receipt = EmergencyOperationReceipt::query()
                    ->where('actor_user_id', $actor->id)
                    ->where('operation', self::OPERATION)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();
                if ($receipt instanceof EmergencyOperationReceipt) {
                    return $this->replayFromReceipt($receipt, $payloadDigest, $sourceEncounterPublicId);
                }

                if (! $source instanceof Encounter || $source->care_setting !== Encounter::CARE_SETTING_EMERGENCY
                    || $source->patient_id !== $patient->id || $source->status !== Encounter::STATUS_IN_EXAMINATION) {
                    throw new EmergencyDenied('source_not_eligible', 'Episode IGD tidak lagi memenuhi syarat serah-terima.');
                }
                if ($lockedHandoff instanceof EmergencyInpatientHandoff) {
                    throw new EmergencyDenied('handoff_already_completed', 'Serah-terima rawat inap sudah diselesaikan.');
                }
                if ($this->pharmacy->inspect($source)['active_prescription_public_ids'] !== []) {
                    throw new EmergencyDenied('active_pharmacy_prescriptions', 'Serah-terima belum dapat diselesaikan karena masih ada resep obat IGD aktif.');
                }
                if ($bed->state !== InpatientBed::STATE_ACTIVE || $ward->state !== InpatientWard::STATE_ACTIVE) {
                    throw new EmergencyDenied('bed_inactive', 'Tempat tidur tidak aktif.');
                }
                if ($claimIds !== []) {
                    throw new EmergencyDenied(
                        $encounters->contains(fn (Encounter $encounter): bool => $encounter->active_inpatient_patient_id === $patient->id)
                            ? 'active_patient_admission_exists'
                            : 'bed_occupied',
                        'Pasien sudah dirawat inap aktif atau tempat tidur sudah digunakan.',
                    );
                }

                $currentVersion = (int) EmergencyDisposition::query()->where('encounter_id', $source->id)->max('version');
                if (! $disposition instanceof EmergencyDisposition || $currentVersion !== $expectedDispositionVersion
                    || $disposition->disposition_type !== 'RAWAT_INAP') {
                    throw new EmergencyDenied('disposition_changed', 'Disposisi rawat inap telah berubah.');
                }
                $dispositionFingerprint = $this->fingerprints->disposition($disposition);
                $admissionReason = trim((string) ($disposition->payload['admission_reason'] ?? ''));
                if ($admissionReason === '') {
                    throw new EmergencyDenied('disposition_invalid', 'Alasan rawat inap pada disposisi tidak tersedia.');
                }

                $locked = new InpatientAdmissionLockContext($patient, $ward, $bed, $encounters);
                $admission = $this->admissions->createFromLockedResourcesWithinCurrentTransaction(
                    locked: $locked,
                    actor: $actor,
                    payerType: $source->payer_type,
                    insuranceNumber: $source->insurance_number,
                    continueFrom: Encounter::CONTINUE_DARI_IGD,
                    chiefComplaint: $admissionReason,
                    requestCorrelationId: $requestCorrelationId,
                    registeredAt: $source->registered_at,
                    admissionMode: Encounter::ADMISSION_IGD,
                );
                $target = $admission->encounter;
                if ($target->patient_id !== $source->patient_id) {
                    throw new EmergencyDenied('patient_link_mismatch', 'Pasien sumber dan tujuan serah-terima tidak cocok.');
                }

                $source->status = Encounter::STATUS_READY_FOR_RM;
                $source->save();
                $now = now((string) config('app.timezone', 'Asia/Jakarta'));
                $attributes = [
                    'source_encounter_id' => $source->id,
                    'disposition_id' => $disposition->id,
                    'target_encounter_id' => $target->id,
                    'inpatient_location_event_id' => $admission->location->id,
                    'inpatient_bed_id' => $bed->id,
                    'actor_user_id' => $actor->id,
                    'inpatient_bed_version' => $bed->version,
                    'bed_snapshot' => $this->bedSnapshot($ward, $bed),
                    'source_encounter_fingerprint' => $this->fingerprints->encounter($source),
                    'disposition_fingerprint' => $dispositionFingerprint,
                    'target_encounter_fingerprint' => $this->fingerprints->encounter($target),
                    'location_event_fingerprint' => $this->fingerprints->location($admission->location),
                    'handed_off_at' => $now,
                    'created_at' => $now,
                ];
                $attributes['content_digest'] = $this->fingerprints->handoffPayload($attributes);
                $handoff = EmergencyMutationScope::run(fn (): EmergencyInpatientHandoff => EmergencyInpatientHandoff::query()->create($attributes));

                $this->recordSuccessOrFail($actor, $source, $handoff);
                EmergencyMutationScope::run(fn () => EmergencyOperationReceipt::query()->create([
                    'actor_user_id' => $actor->id,
                    'operation' => self::OPERATION,
                    'idempotency_key' => $key,
                    'payload_digest' => $payloadDigest,
                    'result_type' => 'INPATIENT_HANDOFF',
                    'result_public_id' => $handoff->public_id,
                    'result_version' => 1,
                    'result_state' => 'COMPLETED',
                    'result_digest' => $handoff->content_digest,
                    'request_correlation_id' => $requestCorrelationId,
                    'completed_at' => $now,
                ]));

                return new EmergencyMutationResult($handoff, false);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $denial = new EmergencyDenied('concurrent_change', 'Serah-terima berubah bersamaan. Muat ulang sebelum melanjutkan.');
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        } catch (InpatientBedUnavailable|InpatientMasterDenied $exception) {
            $denial = new EmergencyDenied('bed_unavailable', $exception->getMessage());
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        } catch (EmergencyDenied $denial) {
            $this->denyOrFail($actor, $sourceEncounterPublicId, $denial);
            throw $denial;
        }
    }

    private function authorizeOrAudit(User $actor): void
    {
        try {
            $this->actors->inpatientHandoff($actor);
        } catch (AuthorizationException $exception) {
            $denial = new EmergencyDenied('role_not_permitted', 'Aktor tidak diizinkan menjalankan serah-terima rawat inap.');
            $this->denyOrFail($actor, null, $denial);
            throw $exception;
        }
    }

    private function replayFromReceipt(EmergencyOperationReceipt $receipt, string $payloadDigest, string $sourcePublicId): EmergencyMutationResult
    {
        if (! hash_equals((string) $receipt->payload_digest, $payloadDigest)
            || $receipt->result_type !== 'INPATIENT_HANDOFF'
            || $receipt->result_version !== 1 || $receipt->result_state !== 'COMPLETED') {
            throw new EmergencyDenied('idempotency_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
        }
        $handoff = EmergencyInpatientHandoff::query()->where('public_id', $receipt->result_public_id)->first();
        if (! $handoff instanceof EmergencyInpatientHandoff
            || ! hash_equals($this->fingerprints->handoff($handoff), (string) $receipt->result_digest)
            || $handoff->sourceEncounter()->where('public_id', $sourcePublicId)->doesntExist()) {
            throw new EmergencyDenied('receipt_corrupt', 'Bukti serah-terima tidak lagi cocok.');
        }

        return new EmergencyMutationResult($handoff, true);
    }

    /** @return array<string, mixed> */
    private function bedSnapshot(InpatientWard $ward, InpatientBed $bed): array
    {
        return [
            'ward_public_id' => $ward->public_id,
            'ward_code' => $ward->code,
            'ward_display_name' => $ward->display_name,
            'ward_version' => $ward->version,
            'bed_public_id' => $bed->public_id,
            'bed_code' => $bed->code,
            'bed_display_name' => $bed->display_name,
            'room_label' => $bed->room_label,
            'service_class' => $bed->service_class,
            'bed_version' => $bed->version,
        ];
    }

    private function recordSuccessOrFail(User $actor, Encounter $source, EmergencyInpatientHandoff $handoff): void
    {
        if ($this->audit->record(
            'emergency.workflow.mutate',
            'emergency_record',
            $source->public_id,
            $actor,
            'SUCCESS',
            null,
            ['operation' => self::OPERATION],
        ) === null) {
            throw new EmergencyAuditUnavailable('Audit serah-terima IGD gagal.');
        }
    }

    private function denyOrFail(User $actor, ?string $sourcePublicId, EmergencyDenied $denial): void
    {
        $resource = is_string($sourcePublicId) && Str::isUlid($sourcePublicId) ? $sourcePublicId : null;
        if ($this->audit->record(
            'emergency.workflow.mutate',
            'emergency_record',
            $resource,
            $actor,
            'DENIED',
            $denial->reason,
            ['operation' => self::OPERATION],
        ) === null) {
            throw new EmergencyAuditUnavailable('Audit penolakan serah-terima IGD gagal.');
        }
    }
}
