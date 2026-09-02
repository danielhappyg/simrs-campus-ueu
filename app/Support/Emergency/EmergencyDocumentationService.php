<?php

namespace App\Support\Emergency;

use App\Models\EmergencyClinicalDocument;
use App\Models\EmergencyClinicalDocumentVersion;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyTriageAssessment;
use App\Models\Encounter;
use App\Models\User;

final class EmergencyDocumentationService
{
    public const DEFINITION_VERSION = 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1';

    public const NURSING_FIELDS = ['arrival_condition', 'focused_assessment', 'interventions', 'response_evaluation', 'safety_observation_needs', 'handoff_note'];

    public const MEDICAL_FIELDS = ['anamnesis', 'focused_physical_examination', 'clinical_impression', 'problem_list', 'treatment_action_plan', 'diagnostic_order_rationale', 'disposition_readiness_note'];

    public function __construct(private readonly EmergencyActorPolicy $policy, private readonly EmergencyOperationCoordinator $operations, private readonly EmergencyEvidenceFingerprint $fingerprints) {}

    /** @param array<string, mixed> $fields */
    public function saveDraft(string $encounterPublicId, string $documentType, User $actor, int $expectedVersion, array $fields, string $idempotencyKey): EmergencyMutationResult
    {
        $type = mb_strtoupper(trim($documentType));
        $normalized = $this->normalizeFields($type, $fields);

        return $this->operations->perform($actor, 'EMERGENCY_DOCUMENT_DRAFT_SAVE', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'type', 'expectedVersion', 'normalized'), fn () => $this->authorize($actor, $type), function () use ($encounterPublicId, $type, $actor, $expectedVersion, $normalized): EmergencyClinicalDocumentVersion {
            $encounter = $this->lockEligibleEncounter($encounterPublicId);
            $document = EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->where('document_type', $type)->lockForUpdate()->first();
            if (($document ? $document->version : 0) !== $expectedVersion) {
                throw new EmergencyDenied('stale_version', 'Versi dokumen IGD telah berubah.');
            }
            if ($document?->state === EmergencyClinicalDocument::FINAL) {
                throw new EmergencyDenied('document_final', 'Dokumen Final tidak dapat diubah.');
            }
            $version = $expectedVersion + 1;
            $digest = $this->fingerprints->document(EmergencyClinicalDocument::DRAFT, $normalized, $version, $actor->id, null);
            if (! $document) {
                $document = EmergencyClinicalDocument::query()->create(['encounter_id' => $encounter->id, 'document_type' => $type, 'state' => EmergencyClinicalDocument::DRAFT, 'version' => 1, 'current_content_digest' => $digest]);
            } else {
                $document->update(['state' => EmergencyClinicalDocument::DRAFT, 'version' => $version, 'current_content_digest' => $digest]);
            }

            $draft = EmergencyClinicalDocumentVersion::query()->create(['emergency_clinical_document_id' => $document->id, 'author_user_id' => $actor->id, 'version' => $version, 'state' => EmergencyClinicalDocument::DRAFT, 'fields' => $normalized, 'content_digest' => $digest, 'finalized_at' => null, 'created_at' => now()]);

            return $draft->refresh();
        });
    }

    public function finalize(string $encounterPublicId, string $documentType, User $actor, int $expectedVersion, string $idempotencyKey): EmergencyMutationResult
    {
        $type = mb_strtoupper(trim($documentType));

        return $this->operations->perform($actor, 'EMERGENCY_DOCUMENT_FINALIZE', $encounterPublicId, $idempotencyKey, compact('encounterPublicId', 'type', 'expectedVersion'), fn () => $this->authorize($actor, $type), function () use ($encounterPublicId, $type, $actor, $expectedVersion): EmergencyClinicalDocumentVersion {
            $encounter = $this->lockEligibleEncounter($encounterPublicId);
            $document = EmergencyClinicalDocument::query()->where('encounter_id', $encounter->id)->where('document_type', $type)->lockForUpdate()->firstOrFail();
            if ($document->version !== $expectedVersion) {
                throw new EmergencyDenied('stale_version', 'Versi dokumen IGD telah berubah.');
            }
            if ($document->state !== EmergencyClinicalDocument::DRAFT) {
                throw new EmergencyDenied('document_final', 'Dokumen sudah Final.');
            }
            $latest = EmergencyClinicalDocumentVersion::query()->where('emergency_clinical_document_id', $document->id)->where('version', $document->version)->firstOrFail();
            $this->fingerprints->documentVersion($latest);
            $version = $document->version + 1;
            $finalizedAt = now();
            $digest = $this->fingerprints->document(EmergencyClinicalDocument::FINAL, $latest->fields ?? [], $version, $actor->id, $finalizedAt->toJSON());
            $final = EmergencyClinicalDocumentVersion::query()->create(['emergency_clinical_document_id' => $document->id, 'author_user_id' => $actor->id, 'version' => $version, 'state' => EmergencyClinicalDocument::FINAL, 'fields' => $latest->fields, 'content_digest' => $digest, 'finalized_at' => $finalizedAt, 'created_at' => $finalizedAt]);
            $document->update(['state' => EmergencyClinicalDocument::FINAL, 'version' => $version, 'current_content_digest' => $digest]);

            return $final->refresh();
        });
    }

    private function lockEligibleEncounter(string $publicId): Encounter
    {
        $encounter = Encounter::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
        if ($encounter->care_setting !== Encounter::CARE_SETTING_EMERGENCY || $encounter->status !== Encounter::STATUS_IN_EXAMINATION || $encounter->cancellation()->exists() || ! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new EmergencyDenied($encounter->isCancelled() ? 'encounter_cancelled' : 'encounter_not_in_examination', 'Episode IGD tidak dapat didokumentasikan.');
        }
        if (! EmergencyTriageAssessment::query()->where('encounter_id', $encounter->id)->where('assessment_type', EmergencyTriageAssessment::INITIAL)->exists()) {
            throw new EmergencyDenied('initial_triage_required', 'Triase awal Final wajib tersedia.');
        }
        if (EmergencyDisposition::query()->where('encounter_id', $encounter->id)->exists()) {
            throw new EmergencyDenied('signed_disposition_exists', 'Dokumentasi biasa ditutup setelah disposisi ditandatangani.');
        }

        return $encounter;
    }

    private function authorize(User $actor, string $type): void
    {
        match ($type) {
            EmergencyClinicalDocument::NURSING => $this->policy->nursingDocument($actor),
            EmergencyClinicalDocument::MEDICAL => $this->policy->medicalDocument($actor),
            default => throw new EmergencyDenied('validation_failed', 'Jenis dokumen IGD tidak valid.'),
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    private function normalizeFields(string $type, array $fields): array
    {
        $expected = match ($type) {
            EmergencyClinicalDocument::NURSING => self::NURSING_FIELDS,
            EmergencyClinicalDocument::MEDICAL => self::MEDICAL_FIELDS,
            default => throw new EmergencyDenied('validation_failed', 'Jenis dokumen IGD tidak valid.'),
        };
        if (array_diff(array_keys($fields), $expected) !== [] || array_diff($expected, array_keys($fields)) !== []) {
            throw new EmergencyDenied('validation_failed', 'Bidang dokumen IGD tidak sesuai definisi.');
        }
        $normalized = [];
        foreach ($expected as $field) {
            $value = trim((string) ($fields[$field] ?? ''));
            if ($value === '' || mb_strlen($value) > 4000) {
                throw new EmergencyDenied('validation_failed', 'Setiap bidang dokumen IGD wajib diisi.');
            }
            $normalized[$field] = $value;
        }

        return $normalized;
    }
}
