<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\OutpatientAdmissionOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientDisposition;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\CanonicalJson;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use Illuminate\Support\Facades\DB;

final class OutpatientDispositionService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly OutpatientAdmissionOperationRunner $runner, private readonly PharmacyEncounterLifecycleGate $pharmacy) {}

    /** @param array<string,mixed> $payload */
    public function sign(Encounter $encounter, User $actor, string $type, array $payload, int $expectedDocumentVersion, string $key): OutpatientDisposition
    {
        return $this->runner->run('clinical.outpatient.disposition.sign', $encounter, $actor, fn (): OutpatientDisposition => $this->performSign($encounter, $actor, $type, $payload, $expectedDocumentVersion, $key));
    }

    /** @param array<string,mixed> $payload */
    private function performSign(Encounter $encounter, User $actor, string $type, array $payload, int $expectedDocumentVersion, string $key): OutpatientDisposition
    {
        $this->physician($actor);
        $type = mb_strtoupper(trim($type));
        $details = $this->normalize($type, $payload);
        $this->key($key);
        $payloadDigest = hash('sha256', CanonicalJson::encode(['encounter' => $encounter->public_id, 'type' => $type, 'details' => $details, 'document_version' => $expectedDocumentVersion]));

        return DB::transaction(function () use ($encounter, $actor, $type, $details, $expectedDocumentVersion, $key, $payloadDigest): OutpatientDisposition {
            $this->pharmacy->lockInventoryForEncounter((int) $encounter->id);
            $locked = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            $receipt = OutpatientAdmissionOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', 'OUTPATIENT_DISPOSITION_SIGN')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_digest, $payloadDigest)) {
                    throw new OutpatientDispositionDenied('idempotency_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
                }
                $result = OutpatientDisposition::query()->where('public_id', $receipt->result_public_id)->firstOrFail();
                if (! hash_equals($result->content_digest, $receipt->result_digest)) {
                    throw new OutpatientDispositionDenied('receipt_corrupt', 'Bukti disposisi tidak cocok.');
                }

                return $result;
            }
            if ($locked->care_setting !== Encounter::CARE_SETTING_OUTPATIENT || $locked->isCancelled() || $locked->status === Encounter::STATUS_CLOSED || ! $locked->patient()->where('is_synthetic', true)->exists()) {
                throw new OutpatientDispositionDenied('encounter_not_eligible', 'Kunjungan rawat jalan tidak memenuhi syarat disposisi.', 404);
            }
            if (OutpatientDisposition::query()->where('encounter_id', $locked->id)->exists()) {
                throw new OutpatientDispositionDenied('disposition_exists', 'Kunjungan sudah memiliki disposisi bertanda tangan.');
            }
            if ($this->pharmacy->inspect($locked)['active_prescription_public_ids'] !== []) {
                throw new OutpatientDispositionDenied('active_pharmacy_prescriptions', 'Disposisi belum dapat diselesaikan karena masih ada resep aktif.');
            }
            $document = OutpatientClinicalDocument::query()->where('encounter_id', $locked->id)->where('document_type', OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT)->lockForUpdate()->first();
            if (! $document || $document->document_state !== OutpatientClinicalDocument::STATE_FINAL || $document->version !== $expectedDocumentVersion) {
                throw new OutpatientDispositionDenied('medical_document_not_current', 'Versi final catatan medis tidak cocok.');
            }
            $version = OutpatientClinicalDocumentVersion::query()->where('outpatient_clinical_document_id', $document->id)->where('version', $document->version)->where('document_state', OutpatientClinicalDocument::STATE_FINAL)->firstOrFail();
            $now = now();
            $digest = hash('sha256', CanonicalJson::encode(['encounter' => $locked->public_id, 'actor' => $actor->public_id, 'document_version' => $version->public_id, 'version' => 1, 'type' => $type, 'details' => $details, 'signed_at' => $now->toIso8601String()]));
            $result = OutpatientDisposition::query()->create(['encounter_id' => $locked->id, 'physician_user_id' => $actor->id, 'medical_document_version_id' => $version->id, 'version' => 1, 'disposition_type' => $type, 'payload' => $details, 'content_digest' => $digest, 'signed_at' => $now, 'created_at' => $now]);
            $locked->update(['status' => $type === 'RAWAT_INAP' ? Encounter::STATUS_IN_EXAMINATION : Encounter::STATUS_READY_FOR_RM]);
            OutpatientAdmissionOperationReceipt::query()->create(['actor_user_id' => $actor->id, 'operation' => 'OUTPATIENT_DISPOSITION_SIGN', 'idempotency_key' => $key, 'payload_digest' => $payloadDigest, 'result_type' => 'DISPOSITION', 'result_public_id' => $result->public_id, 'result_digest' => $digest, 'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => $now]);
            if (! $this->audit->record('clinical.outpatient.disposition.sign', 'outpatient_disposition', $result->public_id, $actor, 'SUCCESS', null, ['encounter_id' => $locked->public_id, 'disposition_type' => $type, 'version' => 1, 'medical_document_version_public_id' => $version->public_id])) {
                throw new OutpatientDispositionDenied('audit_unavailable', 'Disposisi dibatalkan karena audit wajib tidak dapat direkam.', 503);
            }

            return $result;
        }, 3);
    }

    /** @param array<string,mixed> $payload */
    public function correct(Encounter $encounter, User $actor, int $expectedVersion, string $type, array $payload, int $expectedDocumentVersion, string $reason, string $key): OutpatientDisposition
    {
        return $this->runner->run('clinical.outpatient.disposition.correct', $encounter, $actor, fn (): OutpatientDisposition => $this->performCorrection($encounter, $actor, $expectedVersion, $type, $payload, $expectedDocumentVersion, $reason, $key));
    }

    /** @param array<string,mixed> $payload */
    private function performCorrection(Encounter $encounter, User $actor, int $expectedVersion, string $type, array $payload, int $expectedDocumentVersion, string $reason, string $key): OutpatientDisposition
    {
        $this->physician($actor);
        $type = mb_strtoupper(trim($type));
        $details = $this->normalize($type, $payload);
        $this->key($key);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw new OutpatientDispositionDenied('validation_failed', 'Alasan koreksi wajib diisi.');
        }
        $payloadDigest = hash('sha256', CanonicalJson::encode(['encounter' => $encounter->public_id, 'expected_version' => $expectedVersion, 'type' => $type, 'details' => $details, 'document_version' => $expectedDocumentVersion, 'reason' => $reason]));

        return DB::transaction(function () use ($encounter, $actor, $expectedVersion, $type, $details, $expectedDocumentVersion, $reason, $key, $payloadDigest): OutpatientDisposition {
            $this->pharmacy->lockInventoryForEncounter((int) $encounter->id);
            $locked = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            $receipt = OutpatientAdmissionOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', 'OUTPATIENT_DISPOSITION_CORRECT')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_digest, $payloadDigest)) {
                    throw new OutpatientDispositionDenied('idempotency_conflict', 'Kunci idempotensi telah digunakan untuk permintaan berbeda.');
                }
                $replay = OutpatientDisposition::query()->where('public_id', $receipt->result_public_id)->firstOrFail();
                if (! hash_equals($replay->content_digest, $receipt->result_digest)) {
                    throw new OutpatientDispositionDenied('receipt_corrupt', 'Bukti koreksi tidak cocok.');
                }

                return $replay;
            }
            $current = OutpatientDisposition::query()->where('encounter_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($locked->care_setting !== Encounter::CARE_SETTING_OUTPATIENT || $locked->isCancelled() || $locked->status === Encounter::STATUS_CLOSED || ! $locked->patient()->where('is_synthetic', true)->exists()) {
                throw new OutpatientDispositionDenied('encounter_not_eligible', 'Kunjungan rawat jalan tidak memenuhi syarat koreksi.', 404);
            }
            if ($current->version !== $expectedVersion) {
                throw new OutpatientDispositionDenied('stale_version', 'Versi disposisi telah berubah.');
            }
            if ($current->physician_user_id !== $actor->id) {
                throw new OutpatientDispositionDenied('author_mismatch', 'Hanya dokter penandatangan yang dapat mengoreksi disposisi.', 403);
            }
            if ($current->handoff()->exists()) {
                throw new OutpatientDispositionDenied('handoff_already_completed', 'Disposisi tidak dapat dikoreksi setelah serah-terima.');
            }
            if ($this->pharmacy->inspect($locked)['active_prescription_public_ids'] !== []) {
                throw new OutpatientDispositionDenied('active_pharmacy_prescriptions', 'Koreksi disposisi belum dapat diselesaikan karena masih ada resep aktif.');
            }
            $document = OutpatientClinicalDocument::query()->where('encounter_id', $locked->id)->where('document_type', OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT)->firstOrFail();
            if ($document->version !== $expectedDocumentVersion || $current->medical_document_version_id !== OutpatientClinicalDocumentVersion::query()->where('outpatient_clinical_document_id', $document->id)->where('version', $expectedDocumentVersion)->value('id')) {
                throw new OutpatientDispositionDenied('medical_document_changed', 'Catatan medis final telah berubah.');
            }
            $now = now();
            $digest = hash('sha256', CanonicalJson::encode(['encounter' => $locked->public_id, 'actor' => $actor->public_id, 'prior' => $current->public_id, 'prior_digest' => $current->content_digest, 'version' => $expectedVersion + 1, 'type' => $type, 'details' => $details, 'reason' => $reason, 'signed_at' => $now->toIso8601String()]));
            $result = OutpatientDisposition::query()->create(['encounter_id' => $locked->id, 'physician_user_id' => $actor->id, 'medical_document_version_id' => $current->medical_document_version_id, 'prior_disposition_id' => $current->id, 'version' => $expectedVersion + 1, 'disposition_type' => $type, 'payload' => $details, 'correction_reason' => $reason, 'prior_disposition_digest' => $current->content_digest, 'content_digest' => $digest, 'signed_at' => $now, 'created_at' => $now]);
            $locked->update(['status' => $type === 'RAWAT_INAP' ? Encounter::STATUS_IN_EXAMINATION : Encounter::STATUS_READY_FOR_RM]);
            OutpatientAdmissionOperationReceipt::query()->create(['actor_user_id' => $actor->id, 'operation' => 'OUTPATIENT_DISPOSITION_CORRECT', 'idempotency_key' => $key, 'payload_digest' => $payloadDigest, 'result_type' => 'DISPOSITION', 'result_public_id' => $result->public_id, 'result_digest' => $digest, 'request_correlation_id' => request()->attributes->get('request_id'), 'completed_at' => $now]);
            if (! $this->audit->record('clinical.outpatient.disposition.correct', 'outpatient_disposition', $result->public_id, $actor, 'SUCCESS', null, ['encounter_id' => $locked->public_id, 'prior_disposition_id' => $current->public_id, 'version' => $result->version])) {
                throw new OutpatientDispositionDenied('audit_unavailable', 'Koreksi dibatalkan karena audit wajib tidak dapat direkam.', 503);
            }

            return $result;
        }, 3);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,string>
     */
    private function normalize(string $type, array $payload): array
    {
        $fields = match ($type) {
            'KONTROL_ULANG' => ['follow_up_plan'],'SEMBUH' => ['clinical_note'],'RAWAT_INAP' => ['admission_reason', 'receiving_unit_handoff_note'],default => throw new OutpatientDispositionDenied('validation_failed', 'Jenis disposisi rawat jalan tidak valid.')
        };
        if (array_diff(array_keys($payload), $fields) !== [] || array_diff($fields, array_keys($payload)) !== []) {
            throw new OutpatientDispositionDenied('validation_failed', 'Rincian disposisi tidak sesuai jenisnya.');
        }
        $out = [];
        foreach ($fields as $field) {
            if (! is_string($payload[$field])) {
                throw new OutpatientDispositionDenied('validation_failed', 'Rincian disposisi harus berupa teks.');
            }
            $value = trim($payload[$field]);
            if (mb_strlen($value) < 2 || mb_strlen($value) > 4000) {
                throw new OutpatientDispositionDenied('validation_failed', 'Rincian disposisi wajib diisi.');
            }
            $out[$field] = $value;
        }

        return $out;
    }

    private function physician(User $actor): void
    {
        if (! ($actor->status === 'ACTIVE' && $actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_PHYSICIAN] && $actor->canCapability(Capability::CLINICAL_MEDICAL_WRITE))) {
            throw new OutpatientDispositionDenied('role_not_permitted', 'Hanya dokter aktif yang dapat menandatangani disposisi.', 403);
        }
    }

    private function key(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/', trim($key)) !== 1) {
            throw new OutpatientDispositionDenied('validation_failed', 'Kunci idempotensi tidak valid.');
        }
    }
}
