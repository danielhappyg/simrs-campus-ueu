<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Registration\DailyQueueAllocator;
use App\Support\Registration\InpatientBedUnavailable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single admission seam shared by direct registration and an executed
 * IGD-to-RI handoff. Patient-claim locking always precedes bed/encounter locks.
 */
final class InpatientAdmissionService
{
    public function __construct(
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
        private readonly InpatientBedTransferService $locations,
        private readonly DailyQueueAllocator $queues,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function admitDirect(
        Patient $patient,
        User $actor,
        string $bedPublicId,
        string $payerType,
        ?string $insuranceNumber,
        string $continueFrom,
        ?string $chiefComplaint,
        ?string $requestCorrelationId = null,
        ?CarbonInterface $registeredAt = null,
    ): InpatientAdmissionResult {
        try {
            return DB::transaction(function () use ($patient, $actor, $bedPublicId, $payerType, $insuranceNumber, $continueFrom, $chiefComplaint, $requestCorrelationId, $registeredAt): InpatientAdmissionResult {
                $result = $this->createWithinCurrentTransaction(
                    patient: $patient,
                    actor: $actor,
                    bedPublicId: $bedPublicId,
                    payerType: $payerType,
                    insuranceNumber: $insuranceNumber,
                    continueFrom: $continueFrom,
                    chiefComplaint: $chiefComplaint,
                    requestCorrelationId: $requestCorrelationId,
                    registeredAt: $registeredAt,
                );

                $event = $this->auditRecorder->record(
                    action: 'patient.register',
                    resourceType: 'encounter',
                    resourceId: $result->encounter->public_id,
                    actor: $actor,
                    outcome: 'SUCCESS',
                    metadata: $this->registrationAuditMetadata($result->encounter, $patient),
                );
                if ($event === null) {
                    throw new InpatientAdmissionDenied('audit_unavailable', 'Pendaftaran dibatalkan karena audit wajib tidak dapat direkam.', 503);
                }

                return $result;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new InpatientAdmissionDenied(
                'active_patient_admission_exists',
                'Pasien sudah memiliki episode rawat inap aktif.',
            );
        }
    }

    /**
     * Used only by the bounded emergency handoff transaction. The caller owns
     * the handoff receipt and success audit, while this method owns admission
     * claim/bed locking and target encounter/location creation.
     */
    public function admitFromEmergencyWithinCurrentTransaction(
        Patient $patient,
        User $actor,
        string $bedPublicId,
        string $payerType,
        ?string $insuranceNumber,
        string $admissionReason,
        ?string $requestCorrelationId,
        CarbonInterface $registeredAt,
    ): InpatientAdmissionResult {
        return $this->createWithinCurrentTransaction(
            patient: $patient,
            actor: $actor,
            bedPublicId: $bedPublicId,
            payerType: $payerType,
            insuranceNumber: $insuranceNumber,
            continueFrom: Encounter::CONTINUE_DARI_IGD,
            chiefComplaint: $admissionReason,
            requestCorrelationId: $requestCorrelationId,
            registeredAt: $registeredAt,
            admissionMode: Encounter::ADMISSION_IGD,
        );
    }

    private function createWithinCurrentTransaction(
        Patient $patient,
        User $actor,
        string $bedPublicId,
        string $payerType,
        ?string $insuranceNumber,
        string $continueFrom,
        ?string $chiefComplaint,
        ?string $requestCorrelationId,
        ?CarbonInterface $registeredAt,
        ?string $admissionMode = null,
    ): InpatientAdmissionResult {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inpatient admission requires an active database transaction.');
        }
        if (! $patient->exists || ! $patient->is_synthetic) {
            throw new InpatientAdmissionDenied('synthetic_only', 'Pasien tidak berada dalam batas data yang diizinkan.', 404);
        }

        // Global order: patient claim first, then bed mutex/claim encounters,
        // ward, bed, queue and finally target creation/evidence.
        $locked = $this->lockResourcesWithinCurrentTransaction($patient, $bedPublicId);

        return $this->createFromLockedResourcesWithinCurrentTransaction(
            locked: $locked,
            actor: $actor,
            payerType: $payerType,
            insuranceNumber: $insuranceNumber,
            continueFrom: $continueFrom,
            chiefComplaint: $chiefComplaint,
            requestCorrelationId: $requestCorrelationId,
            registeredAt: $registeredAt,
            admissionMode: $admissionMode,
        );
    }

    /** @param list<int> $additionalEncounterIds */
    public function lockResourcesWithinCurrentTransaction(
        Patient $patient,
        string $bedPublicId,
        array $additionalEncounterIds = [],
    ): InpatientAdmissionLockContext {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inpatient admission resource locking requires an active database transaction.');
        }
        if (! $patient->exists || ! $patient->is_synthetic) {
            throw new InpatientAdmissionDenied('synthetic_only', 'Pasien tidak berada dalam batas data yang diizinkan.', 404);
        }

        $this->locks->lockPatientClaimMutexes([(int) $patient->id]);
        $candidate = InpatientBed::query()->where('public_id', $bedPublicId)->first();
        if (! $candidate instanceof InpatientBed) {
            throw new InpatientMasterDenied('bed_missing', 'Tempat tidur tidak ditemukan.');
        }
        $this->locks->lockMutexes([$candidate->code]);

        $bedClaimIds = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where(function ($query) use ($candidate): void {
                $query->where('bed_code', $candidate->code)->orWhere('inpatient_bed_id', $candidate->id);
            })
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $patientClaimIds = Encounter::query()
            ->where('active_inpatient_patient_id', $patient->id)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $encounters = $this->locks->lockEncounters(array_values([...$additionalEncounterIds, ...$bedClaimIds, ...$patientClaimIds]));
        if ($encounters->contains(static fn (Encounter $encounter): bool => in_array($encounter->id, $bedClaimIds, true)
            && in_array($encounter->status, Encounter::BED_OCCUPYING_STATUSES, true))) {
            throw new InpatientBedUnavailable('Tempat tidur sudah dipakai kunjungan rawat inap aktif.');
        }
        if ($encounters->contains(static fn (Encounter $encounter): bool => in_array($encounter->id, $patientClaimIds, true)
            && $encounter->active_inpatient_patient_id === $patient->id)) {
            throw new InpatientAdmissionDenied(
                'active_patient_admission_exists',
                'Pasien sudah memiliki episode rawat inap aktif.',
            );
        }
        $ward = $this->locks->lockWards([(int) $candidate->ward_id])->get((int) $candidate->ward_id);
        $bed = $this->locks->lockBeds([(int) $candidate->id])->get((int) $candidate->id);
        if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed
            || $bed->ward_id !== $ward->id || $bed->code !== $candidate->code
            || $bed->state !== InpatientBed::STATE_ACTIVE || $ward->state !== InpatientWard::STATE_ACTIVE) {
            throw new InpatientMasterDenied('bed_retired', 'Tempat tidur tidak aktif dan tidak dapat dipilih.');
        }
        $bed->setRelation('ward', $ward);

        return new InpatientAdmissionLockContext($patient, $ward, $bed, $encounters);
    }

    public function createFromLockedResourcesWithinCurrentTransaction(
        InpatientAdmissionLockContext $locked,
        User $actor,
        string $payerType,
        ?string $insuranceNumber,
        string $continueFrom,
        ?string $chiefComplaint,
        ?string $requestCorrelationId,
        ?CarbonInterface $registeredAt,
        ?string $admissionMode = null,
    ): InpatientAdmissionResult {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inpatient admission creation requires an active database transaction.');
        }
        $patient = $locked->patient;
        $bed = $locked->bed;

        $registeredAt ??= now((string) config('app.timezone', 'Asia/Jakarta'));
        $queue = $this->queues->allocate($registeredAt);
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::query()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'clinic_name' => $locked->ward->display_name,
            'ward_name' => $locked->ward->display_name,
            'ward_class' => $bed->service_class,
            'bed_code' => $bed->code,
            'inpatient_bed_id' => $bed->id,
            'continue_from' => $continueFrom,
            'visit_date' => $registeredAt->toDateString(),
            'admission_mode' => $admissionMode,
            'payer_type' => $payerType,
            'insurance_number' => $insuranceNumber,
            'queue_date' => $queue->queueDate,
            'queue_number' => $queue->queueNumber,
            'registered_at' => $registeredAt,
            'registered_by_user_id' => $actor->id,
            'chief_complaint' => $chiefComplaint,
        ]));
        $encounter->setRelation('patient', $patient);
        $location = $this->locations->recordAdmission(
            $encounter,
            $locked->ward,
            $bed,
            $actor,
            $requestCorrelationId,
        );

        return new InpatientAdmissionResult($encounter, $location);
    }

    /** @return array<string, mixed> */
    private function registrationAuditMetadata(Encounter $encounter, Patient $patient): array
    {
        return [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'patient_public_id' => $patient->public_id,
            'ward_name' => $encounter->ward_name,
            'ward_class' => $encounter->ward_class,
            'bed_code' => $encounter->bed_code,
            'continue_from' => $encounter->continue_from,
            'payer_type' => $encounter->payer_type,
            'queue_date' => $encounter->queue_date,
            'queue_number' => $encounter->queue_number,
        ];
    }
}
