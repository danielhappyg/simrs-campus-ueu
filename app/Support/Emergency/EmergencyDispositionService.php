<?php

namespace App\Support\Emergency;

use App\Models\EmergencyClinicalDocument;
use App\Models\EmergencyClinicalDocumentVersion;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyDispositionCorrectionIntent;
use App\Models\EmergencyDispositionCorrectionIntentEvent;
use App\Models\EmergencyHandoffCompensation;
use App\Models\EmergencyInpatientHandoff;
use App\Models\EmergencyTriageAssessment;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use Carbon\CarbonImmutable;

final class EmergencyDispositionService
{
    public function __construct(
        private readonly EmergencyActorPolicy $policy,
        private readonly EmergencyOperationCoordinator $operations,
        private readonly EmergencyEvidenceFingerprint $fingerprints,
        private readonly EmergencyDiagnosticFollowUpService $followUp,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    /** @param array<string, mixed> $payload */
    public function sign(string $encounterPublicId, User $actor, string $dispositionType, array $payload, string $idempotencyKey): EmergencyMutationResult
    {
        $type = mb_strtoupper(trim($dispositionType));
        $details = $this->normalizePayload($type, $payload);

        return $this->operations->perform($actor, 'EMERGENCY_DISPOSITION_SIGN', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'type', 'details'), fn () => $this->policy->disposition($actor), function () use ($encounterPublicId, $actor, $type, $details): EmergencyDisposition {
            $this->lockPharmacyInventory($encounterPublicId);
            $encounter = $this->lockEncounter($encounterPublicId);
            if ($encounter->status !== Encounter::STATUS_IN_EXAMINATION || EmergencyDisposition::query()->where('encounter_id', $encounter->id)->exists()) {
                throw new EmergencyDenied('disposition_not_permitted', 'Episode tidak dapat menerima disposisi baru.');
            }
            $this->verifyReadiness($encounter);

            return $this->appendDisposition($encounter, $actor, $type, $details, null, null);
        });
    }

    /** @param array<string, mixed> $replacementPayload */
    public function correctBeforeHandoff(string $encounterPublicId, User $actor, int $expectedDispositionVersion, string $replacementType, array $replacementPayload, string $reason, string $idempotencyKey): EmergencyMutationResult
    {
        $type = mb_strtoupper(trim($replacementType));
        $details = $this->normalizePayload($type, $replacementPayload);
        $reason = $this->text($reason, 3, 2000);

        return $this->operations->perform($actor, 'EMERGENCY_DISPOSITION_CORRECT_PRE_HANDOFF', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'expectedDispositionVersion', 'type', 'details', 'reason'), fn () => $this->policy->disposition($actor), function () use ($encounterPublicId, $actor, $expectedDispositionVersion, $type, $details, $reason): EmergencyDisposition {
            $this->lockPharmacyInventory($encounterPublicId);
            $encounter = $this->lockEncounter($encounterPublicId, allowReady: true);
            $current = EmergencyDisposition::query()->where('encounter_id', $encounter->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($current->version !== $expectedDispositionVersion) {
                throw new EmergencyDenied('stale_version', 'Versi disposisi telah berubah.');
            }
            if (EmergencyInpatientHandoff::query()->where('source_encounter_id', $encounter->id)->exists()) {
                throw new EmergencyDenied('executed_handoff_requires_intent', 'Serah-terima yang sudah dijalankan memerlukan maksud koreksi.');
            }
            $this->verifyReadiness($encounter);
            $priorDigest = $this->fingerprints->disposition($current);
            $encounter->update(['status' => Encounter::STATUS_IN_EXAMINATION]);

            return $this->appendDisposition($encounter, $actor, $type, $details, $reason, $current, $priorDigest);
        });
    }

    /** @param array<string, mixed> $replacementPayload */
    public function createExecutedHandoffCorrectionIntent(string $encounterPublicId, User $actor, int $expectedDispositionVersion, string $replacementType, array $replacementPayload, string $reason, string $expiresAt, string $idempotencyKey): EmergencyMutationResult
    {
        $type = mb_strtoupper(trim($replacementType));
        $details = $this->normalizePayload($type, $replacementPayload);
        $reason = $this->text($reason, 3, 2000);
        try {
            $expiry = CarbonImmutable::parse($expiresAt);
        } catch (\Throwable) {
            throw new EmergencyDenied('validation_failed', 'Waktu kedaluwarsa maksud koreksi tidak valid.');
        }
        if ($expiry->lte(now()) || $expiry->gt(now()->addDay())) {
            throw new EmergencyDenied('validation_failed', 'Maksud koreksi harus berlaku sampai maksimal 24 jam.');
        }

        return $this->operations->perform($actor, 'EMERGENCY_DISPOSITION_CORRECTION_INTENT_CREATE', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'expectedDispositionVersion', 'type', 'details', 'reason', 'expiresAt'), fn () => $this->policy->disposition($actor), function () use ($encounterPublicId, $actor, $expectedDispositionVersion, $type, $details, $reason, $expiry): EmergencyDispositionCorrectionIntent {
            $encounter = $this->lockEncounter($encounterPublicId, allowReady: true);
            $current = EmergencyDisposition::query()->where('encounter_id', $encounter->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($current->version !== $expectedDispositionVersion || $current->disposition_type !== 'RAWAT_INAP') {
                throw new EmergencyDenied('stale_or_ineligible_disposition', 'Disposisi saat ini tidak memenuhi syarat koreksi serah-terima.');
            }
            $handoff = EmergencyInpatientHandoff::query()->where('source_encounter_id', $encounter->id)->lockForUpdate()->firstOrFail();
            if (EmergencyHandoffCompensation::query()->where('handoff_id', $handoff->id)->exists()) {
                throw new EmergencyDenied('handoff_already_compensated', 'Serah-terima sudah dikompensasi.');
            }
            $existing = EmergencyDispositionCorrectionIntent::query()->where('encounter_id', $encounter->id)->where('expires_at', '>', now())->whereDoesntHave('events')->exists();
            if ($existing) {
                throw new EmergencyDenied('pending_correction_intent_exists', 'Masih ada maksud koreksi aktif.');
            }
            $target = $handoff->targetEncounter()->firstOrFail();
            $attributes = [
                'encounter_id' => $encounter->id, 'current_disposition_id' => $current->id,
                'handoff_id' => $handoff->id, 'physician_user_id' => $actor->id,
                'replacement_type' => $type, 'replacement_payload' => $details, 'reason' => $reason,
                'source_encounter_fingerprint' => $this->fingerprints->encounter($encounter),
                'disposition_fingerprint' => $this->fingerprints->disposition($current),
                'handoff_fingerprint' => $this->fingerprints->handoff($handoff),
                'target_encounter_fingerprint' => $this->fingerprints->encounter($target),
                'expires_at' => $expiry,
            ];
            $attributes['content_digest'] = $this->fingerprints->correctionIntentPayload($attributes);

            return EmergencyDispositionCorrectionIntent::query()->create([...$attributes, 'created_at' => now()]);
        });
    }

    public function revokeCorrectionIntent(string $intentPublicId, User $actor, string $expectedIntentFingerprint, string $reason, string $idempotencyKey): EmergencyMutationResult
    {
        $reason = $this->text($reason, 3, 1000);

        return $this->operations->perform($actor, 'EMERGENCY_DISPOSITION_CORRECTION_INTENT_REVOKE', $intentPublicId, $idempotencyKey, compact('intentPublicId', 'expectedIntentFingerprint', 'reason'), fn () => $this->policy->disposition($actor), function () use ($intentPublicId, $actor, $expectedIntentFingerprint, $reason): EmergencyDispositionCorrectionIntentEvent {
            $reference = EmergencyDispositionCorrectionIntent::query()->where('public_id', $intentPublicId)->firstOrFail();
            Encounter::query()->whereKey($reference->encounter_id)->lockForUpdate()->firstOrFail();
            $intent = EmergencyDispositionCorrectionIntent::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            if ($intent->physician_user_id !== $actor->id) {
                throw new EmergencyDenied('correction_intent_revoke_not_permitted', 'Hanya dokter pembuat dapat mencabut maksud koreksi.');
            }
            if ($intent->expires_at->isPast()) {
                throw new EmergencyDenied('correction_intent_expired', 'Maksud koreksi sudah kedaluwarsa.');
            }
            if ($intent->events()->exists()) {
                throw new EmergencyDenied('correction_intent_not_pending', 'Maksud koreksi tidak lagi aktif.');
            }
            $fingerprint = $this->fingerprints->correctionIntent($intent);
            if (! hash_equals($fingerprint, $expectedIntentFingerprint)) {
                throw new EmergencyDenied('stale_correction_intent', 'Sidik maksud koreksi telah berubah.');
            }
            $occurredAt = now();
            $digest = EmergencyCanonicalJson::digest([$intent->id, $actor->id, 'REVOKED', $reason, $fingerprint, $occurredAt->toJSON()]);

            return EmergencyDispositionCorrectionIntentEvent::query()->create(['correction_intent_id' => $intent->id, 'actor_user_id' => $actor->id, 'event_type' => 'REVOKED', 'reason' => $reason, 'intent_fingerprint' => $fingerprint, 'content_digest' => $digest, 'occurred_at' => $occurredAt, 'created_at' => $occurredAt]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function normalizePayload(string $type, array $payload): array
    {
        if (! in_array($type, EmergencyDisposition::TYPES, true)) {
            throw new EmergencyDenied('validation_failed', 'Jenis disposisi IGD tidak valid.');
        }
        $definitions = match ($type) {
            'PULANG' => ['condition_at_discharge', 'instructions', 'warning_signs', 'follow_up_plan'],
            'DIRUJUK' => ['destination', 'clinical_reason', 'transport_plan', 'handoff_note'],
            'RAWAT_INAP' => ['admission_reason', 'receiving_unit_handoff_note'],
            'MENINGGAL_DI_IGD' => ['event_time', 'clinical_note'],
            'DOA' => ['arrival_declaration_time', 'clinical_note'],
        };
        if (array_diff(array_keys($payload), $definitions) !== [] || array_diff($definitions, array_keys($payload)) !== []) {
            throw new EmergencyDenied('validation_failed', 'Rincian disposisi tidak sesuai jenisnya.');
        }
        $normalized = [];
        foreach ($definitions as $field) {
            if (in_array($field, ['event_time', 'arrival_declaration_time'], true)) {
                try {
                    $time = CarbonImmutable::parse((string) $payload[$field]);
                } catch (\Throwable) {
                    throw new EmergencyDenied('validation_failed', 'Waktu kejadian disposisi tidak valid.');
                }
                if ($time->gt(now()->addMinutes(5)) || $time->lt(now()->subDays(7))) {
                    throw new EmergencyDenied('validation_failed', 'Waktu kejadian disposisi berada di luar rentang.');
                }
                $normalized[$field] = $time->toJSON();
            } else {
                $normalized[$field] = $this->text((string) ($payload[$field] ?? ''), 2, 4000);
            }
        }

        return $normalized;
    }

    /** @param array<string, string> $details */
    private function appendDisposition(Encounter $encounter, User $actor, string $type, array $details, ?string $reason, ?EmergencyDisposition $prior, ?string $priorDigest = null): EmergencyDisposition
    {
        $version = ($prior ? $prior->version : 0) + 1;
        $signedAt = now();
        $digest = $this->fingerprints->dispositionPayload($encounter->id, $actor->id, $prior?->id, $version, $type, $details, $reason, $priorDigest, $signedAt->toJSON());
        $disposition = EmergencyDisposition::query()->create(['encounter_id' => $encounter->id, 'physician_user_id' => $actor->id, 'prior_disposition_id' => $prior?->id, 'version' => $version, 'disposition_type' => $type, 'payload' => $details, 'correction_reason' => $reason, 'prior_disposition_digest' => $priorDigest, 'content_digest' => $digest, 'signed_at' => $signedAt, 'created_at' => $signedAt]);
        $encounter->update(['status' => $type === 'RAWAT_INAP' ? Encounter::STATUS_IN_EXAMINATION : Encounter::STATUS_READY_FOR_RM]);

        return $disposition;
    }

    private function verifyReadiness(Encounter $encounter): void
    {
        if ($this->pharmacy->inspect($encounter)['active_prescription_public_ids'] !== []) {
            throw new EmergencyDenied('active_pharmacy_prescriptions', 'Disposisi belum dapat diselesaikan karena masih ada resep obat aktif.');
        }
        if (! EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->where('assessment_type', EmergencyTriageAssessment::INITIAL)->exists()) {
            throw new EmergencyDenied('initial_triage_required', 'Triase awal Final wajib tersedia.');
        }
        foreach ([EmergencyClinicalDocument::NURSING, EmergencyClinicalDocument::MEDICAL] as $type) {
            $document = EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->where('document_type', $type)->first();
            if (! $document || $document->state !== EmergencyClinicalDocument::FINAL) {
                throw new EmergencyDenied(strtolower($type).'_document_final_required', 'Dokumen keperawatan dan medis Final wajib tersedia.');
            }
            $version = EmergencyClinicalDocumentVersion::query()->where('emergency_clinical_document_id', $document->id)->where('version', $document->version)->firstOrFail();
            $this->fingerprints->documentVersion($version);
        }
        foreach ($this->followUp->unresolvedDiagnostics($encounter) as $item) {
            if (! $this->followUp->hasAcceptedCurrentAssignment($encounter, $item)) {
                throw new EmergencyDenied('diagnostic_follow_up_required', 'Setiap pemeriksaan diagnostik belum selesai membutuhkan penugasan tindak lanjut yang diterima.');
            }
        }
    }

    private function lockPharmacyInventory(string $encounterPublicId): void
    {
        $candidate = Encounter::query()->where('public_id', $encounterPublicId)->first();
        if (! $candidate instanceof Encounter) {
            throw new EmergencyDenied('resource_not_found', 'Episode IGD tidak ditemukan.');
        }
        $this->pharmacy->lockInventoryForEncounter((int) $candidate->id);
    }

    private function lockEncounter(string $publicId, bool $allowReady = false): Encounter
    {
        $encounter = Encounter::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
        $allowed = $allowReady ? [Encounter::STATUS_IN_EXAMINATION, Encounter::STATUS_READY_FOR_RM] : [Encounter::STATUS_IN_EXAMINATION];
        if ($encounter->care_setting !== Encounter::CARE_SETTING_EMERGENCY || ! in_array($encounter->status, $allowed, true) || $encounter->cancellation()->exists() || ! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new EmergencyDenied($encounter->isCancelled() ? 'encounter_cancelled' : 'encounter_not_eligible', 'Episode IGD tidak memenuhi syarat disposisi.');
        }

        return $encounter;
    }

    private function text(string $value, int $min, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            throw new EmergencyDenied('validation_failed', 'Teks disposisi tidak valid.');
        }

        return $value;
    }
}
