<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\OutpatientAdmissionOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientDisposition;
use App\Models\OutpatientInpatientHandoff;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\CanonicalJson;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use Illuminate\Support\Facades\DB;

final class OutpatientInpatientHandoffService
{
    public function __construct(private readonly InpatientAdmissionService $admissions, private readonly AuditRecorder $audit, private readonly PharmacyEncounterLifecycleGate $pharmacy, private readonly CanonicalInpatientBedOperationLockCoordinator $locks, private readonly OutpatientAdmissionOperationRunner $runner) {}

    public function execute(Encounter $source, User $actor, int $expectedVersion, string $bedPublicId, string $key): OutpatientInpatientHandoff
    {
        return $this->runner->run('clinical.outpatient.inpatient-handoff.execute', $source, $actor, fn (): OutpatientInpatientHandoff => $this->performHandoff($source, $actor, $expectedVersion, $bedPublicId, $key));
    }

    private function performHandoff(Encounter $source, User $actor, int $expectedVersion, string $bedPublicId, string $key): OutpatientInpatientHandoff
    {
        if (! ($actor->status === 'ACTIVE' && $actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_REGISTRAR] && $actor->canCapability(Capability::EMERGENCY_INPATIENT_HANDOFF))) {
            throw new OutpatientDispositionDenied('role_not_permitted', 'Hanya registrar aktif yang dapat menjalankan serah-terima.', 403);
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/', trim($key)) !== 1) {
            throw new OutpatientDispositionDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
        $payloadDigest = hash('sha256', CanonicalJson::encode(['source' => $source->public_id, 'expected_version' => $expectedVersion, 'bed' => $bedPublicId]));

        return DB::transaction(function () use ($source, $actor, $expectedVersion, $bedPublicId, $key, $payloadDigest): OutpatientInpatientHandoff {
            $candidate = Encounter::query()->whereKey($source->id)->firstOrFail();
            $patient = Patient::query()->whereKey($candidate->patient_id)->where('is_synthetic', true)->firstOrFail();
            $this->locks->lockPatientClaimMutexes([(int) $patient->id]);
            $receipt = OutpatientAdmissionOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', 'OUTPATIENT_INPATIENT_HANDOFF')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_digest, $payloadDigest)) {
                    throw new OutpatientDispositionDenied('idempotency_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
                }
                $result = OutpatientInpatientHandoff::query()->where('public_id', $receipt->result_public_id)->firstOrFail();
                if (! hash_equals($result->content_digest, $receipt->result_digest)) {
                    throw new OutpatientDispositionDenied('receipt_corrupt', 'Bukti serah-terima tidak cocok.');
                }

                return $result;
            }
            $this->pharmacy->lockInventoryForEncounter((int) $candidate->id);
            $locked = $this->admissions->lockResourcesWithinCurrentTransaction($patient, $bedPublicId, [$candidate->id]);
            $sourceLocked = $locked->encounters->get($candidate->id);
            if (! $sourceLocked || $sourceLocked->care_setting !== Encounter::CARE_SETTING_OUTPATIENT || $sourceLocked->status !== Encounter::STATUS_IN_EXAMINATION || $sourceLocked->isCancelled()) {
                throw new OutpatientDispositionDenied('source_not_eligible', 'Episode rawat jalan tidak lagi memenuhi syarat serah-terima.');
            }
            if ($this->pharmacy->inspect($sourceLocked)['active_prescription_public_ids'] !== []) {
                throw new OutpatientDispositionDenied('active_pharmacy_prescriptions', 'Serah-terima belum dapat diselesaikan karena masih ada resep aktif.');
            }
            if (OutpatientInpatientHandoff::query()->where('source_encounter_id', $sourceLocked->id)->lockForUpdate()->exists()) {
                throw new OutpatientDispositionDenied('handoff_already_completed', 'Serah-terima sudah diselesaikan.');
            }
            $disposition = OutpatientDisposition::query()->where('encounter_id', $sourceLocked->id)->where('version', $expectedVersion)->lockForUpdate()->first();
            if (! $disposition || $disposition->disposition_type !== 'RAWAT_INAP' || (int) OutpatientDisposition::query()->where('encounter_id', $sourceLocked->id)->max('version') !== $expectedVersion) {
                throw new OutpatientDispositionDenied('disposition_changed', 'Disposisi rawat inap telah berubah.');
            }
            $medicalDocument = OutpatientClinicalDocument::query()
                ->where('encounter_id', $sourceLocked->id)
                ->where('document_type', OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT)
                ->lockForUpdate()
                ->first();
            $currentMedicalVersionId = $medicalDocument instanceof OutpatientClinicalDocument
                ? OutpatientClinicalDocumentVersion::query()
                    ->where('outpatient_clinical_document_id', $medicalDocument->id)
                    ->where('version', $medicalDocument->version)
                    ->value('id')
                : null;
            if ($medicalDocument?->document_state !== OutpatientClinicalDocument::STATE_FINAL
                || $currentMedicalVersionId !== $disposition->medical_document_version_id) {
                throw new OutpatientDispositionDenied('medical_document_changed', 'Catatan medis final tidak lagi cocok dengan disposisi.');
            }
            $reason = trim($disposition->payload['admission_reason'] ?? '');
            if ($reason === '') {
                throw new OutpatientDispositionDenied('disposition_invalid', 'Alasan rawat inap tidak tersedia.');
            }
            $admission = $this->admissions->createFromLockedResourcesWithinCurrentTransaction($locked, $actor, $sourceLocked->payer_type, $sourceLocked->insurance_number, Encounter::CONTINUE_DARI_RJ, $reason, request()->attributes->get('request_id'), now(), Encounter::ADMISSION_OUTPATIENT);
            $sourceLocked->update(['status' => Encounter::STATUS_READY_FOR_RM]);
            $now = now();
            $snapshot = ['ward_public_id' => $locked->ward->public_id, 'ward_code' => $locked->ward->code, 'ward_display_name' => $locked->ward->display_name, 'bed_public_id' => $locked->bed->public_id, 'bed_code' => $locked->bed->code, 'bed_display_name' => $locked->bed->display_name, 'room_label' => $locked->bed->room_label, 'service_class' => $locked->bed->service_class, 'bed_version' => $locked->bed->version];
            $digest = hash('sha256', CanonicalJson::encode(['source' => $sourceLocked->public_id, 'disposition' => $disposition->public_id, 'target' => $admission->encounter->public_id, 'location' => $admission->location->public_id, 'actor' => $actor->public_id, 'bed' => $snapshot, 'completed_at' => $now->toIso8601String()]));
            $result = OutpatientInpatientHandoff::query()->create(['source_encounter_id' => $sourceLocked->id, 'disposition_id' => $disposition->id, 'target_encounter_id' => $admission->encounter->id, 'registrar_user_id' => $actor->id, 'inpatient_location_event_id' => $admission->location->id, 'bed_snapshot' => $snapshot, 'content_digest' => $digest, 'completed_at' => $now, 'created_at' => $now]);
            OutpatientAdmissionOperationReceipt::query()->create(['actor_user_id' => $actor->id, 'operation' => 'OUTPATIENT_INPATIENT_HANDOFF', 'idempotency_key' => $key, 'payload_digest' => $payloadDigest, 'result_type' => 'INPATIENT_HANDOFF', 'result_public_id' => $result->public_id, 'result_digest' => $digest, 'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => $now]);
            if (! $this->audit->record('clinical.outpatient.inpatient-handoff.execute', 'outpatient_inpatient_handoff', $result->public_id, $actor, 'SUCCESS', null, ['source_encounter_id' => $sourceLocked->public_id, 'target_encounter_id' => $admission->encounter->public_id, 'disposition_id' => $disposition->public_id])) {
                throw new OutpatientDispositionDenied('audit_unavailable', 'Serah-terima dibatalkan karena audit wajib tidak dapat direkam.', 503);
            }

            return $result;
        }, 3);
    }
}
