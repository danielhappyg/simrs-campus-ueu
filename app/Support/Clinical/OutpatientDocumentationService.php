<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OutpatientDocumentationService
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $fields
     */
    public function save(
        Encounter $encounter,
        User $actor,
        string $documentType,
        ?array $fields,
        int $expectedVersion,
        bool $finalize,
    ): OutpatientClinicalDocument {
        $action = OutpatientDocumentationDefinition::auditPrefix($documentType)
            .($finalize ? '.finalize' : '.draft.save');

        try {
            return DB::transaction(function () use (
                $encounter,
                $actor,
                $documentType,
                $fields,
                $expectedVersion,
                $finalize,
                $action,
            ): OutpatientClinicalDocument {
                $closesClinicalEpisode = $finalize
                    && $documentType === OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT;
                if ($closesClinicalEpisode) {
                    $this->pharmacy->lockInventoryForEncounter((int) $encounter->id);
                }
                $lockedEncounter = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

                abort_unless($lockedEncounter->care_setting === Encounter::CARE_SETTING_OUTPATIENT, 404);

                if ($lockedEncounter->isCancelled()) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'encounter_cancelled',
                        message: 'Kunjungan telah dibatalkan. Catatan tidak dapat disimpan.',
                    );
                }

                if ($lockedEncounter->status === Encounter::STATUS_CLOSED) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'encounter_closed',
                        message: 'Kunjungan sudah ditutup. Catatan tidak dapat disimpan.',
                    );
                }

                $document = OutpatientClinicalDocument::query()
                    ->where('encounter_id', $lockedEncounter->id)
                    ->where('document_type', $documentType)
                    ->lockForUpdate()
                    ->first();

                if ($finalize) {
                    if ($document === null) {
                        throw new OutpatientLifecycleDenial(
                            reason: 'document_missing',
                            message: 'Simpan draf sebelum melakukan finalisasi.',
                        );
                    }
                    $effectiveFields = $document->fields;
                } else {
                    if ($fields === null) {
                        throw new OutpatientLifecycleDenial('validation_failed', 'Field dokumen wajib dikirim.');
                    }
                    $effectiveFields = $fields;
                }
                $this->validateFields($documentType, $effectiveFields, $finalize);

                $currentVersion = $document === null ? 0 : $document->version;
                if ($expectedVersion !== $currentVersion) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'stale_version',
                        message: 'Catatan telah berubah. Muat ulang sebelum melanjutkan.',
                        metadata: ['expected_version' => $expectedVersion, 'current_version' => $currentVersion],
                    );
                }

                if ($document !== null && $document->author_user_id !== $actor->id) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'author_mismatch',
                        message: 'Catatan draf hanya dapat diubah oleh penulisnya.',
                        status: 403,
                    );
                }

                if ($document?->document_state === OutpatientClinicalDocument::STATE_FINAL) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'document_final',
                        message: 'Catatan final bersifat tetap dan tidak dapat diubah.',
                    );
                }

                if ($closesClinicalEpisode
                    && $this->pharmacy->inspect($lockedEncounter)['active_prescription_public_ids'] !== []) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'active_pharmacy_prescriptions',
                        message: 'Pemeriksaan klinis belum dapat diselesaikan karena masih ada resep aktif.',
                    );
                }

                $newVersion = $currentVersion + 1;
                $state = $finalize
                    ? OutpatientClinicalDocument::STATE_FINAL
                    : OutpatientClinicalDocument::STATE_DRAFT;
                $finalizedAt = $finalize ? now() : null;
                $normalizedFields = $this->normalizeFields($effectiveFields);

                if ($document === null) {
                    $document = OutpatientClinicalDocument::query()->create([
                        'encounter_id' => $lockedEncounter->id,
                        'author_user_id' => $actor->id,
                        'finalized_by_user_id' => $finalize ? $actor->id : null,
                        'document_type' => $documentType,
                        'document_state' => $state,
                        'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                        'version' => $newVersion,
                        'fields' => $normalizedFields,
                        'finalized_at' => $finalizedAt,
                    ]);
                } else {
                    $document->update([
                        'finalized_by_user_id' => $finalize ? $actor->id : null,
                        'document_state' => $state,
                        'version' => $newVersion,
                        'fields' => $normalizedFields,
                        'finalized_at' => $finalizedAt,
                    ]);
                }

                OutpatientClinicalDocumentVersion::query()->create([
                    'outpatient_clinical_document_id' => $document->id,
                    'actor_user_id' => $actor->id,
                    'version' => $newVersion,
                    'document_state' => $state,
                    'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                    'fields' => $normalizedFields,
                    'finalized_at' => $finalizedAt,
                ]);

                if ($closesClinicalEpisode) {
                    $lockedEncounter->update(['status' => Encounter::STATUS_READY_FOR_RM]);
                } elseif ($lockedEncounter->status === Encounter::STATUS_REGISTERED) {
                    $lockedEncounter->update(['status' => Encounter::STATUS_IN_EXAMINATION]);
                }

                $event = $this->auditRecorder->record(
                    action: $action,
                    resourceType: 'outpatient_clinical_document',
                    resourceId: $document->public_id,
                    actor: $actor,
                    metadata: [
                        'encounter_id' => $lockedEncounter->public_id,
                        'document_type' => $documentType,
                        'version' => $newVersion,
                        'author_user_public_id' => $actor->public_id,
                        'document_state' => $state,
                    ],
                );

                if ($event === null) {
                    throw new RuntimeException("Audit wajib gagal direkam untuk aksi {$action}.");
                }

                return $document->fresh(['author', 'finalizedBy']) ?? $document;
            });
        } catch (OutpatientLifecycleDenial $denial) {
            $this->recordDenial($action, $encounter, $actor, $documentType, $denial);
            abort($denial->status, $denial->getMessage());
        }
    }

    /** @param array<array-key, mixed> $fields */
    private function validateFields(string $documentType, array $fields, bool $finalize): void
    {
        $allowed = OutpatientDocumentationDefinition::allowedFields($documentType);
        if ($allowed === []) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Jenis dokumen tidak didukung.');
        }

        $unknown = array_values(array_filter(
            array_keys($fields),
            static fn (int|string $key): bool => ! is_string($key) || ! in_array($key, $allowed, true),
        ));
        if ($unknown !== []) {
            $digests = array_map(
                static fn (int|string $key): string => hash(
                    'sha256',
                    (is_int($key) ? 'integer:' : 'string:').(string) $key,
                ),
                array_slice($unknown, 0, 50),
            );

            throw new OutpatientLifecycleDenial(
                'validation_failed',
                'Dokumen memuat field yang tidak didukung.',
                metadata: [
                    'invalid_field_key_digests' => $digests,
                    'invalid_field_key_count' => count($unknown),
                ],
            );
        }

        foreach ($fields as $key => $value) {
            if (! is_string($value) || mb_strlen($value) > 10000) {
                throw new OutpatientLifecycleDenial(
                    'validation_failed',
                    'Isi field harus berupa teks dengan panjang maksimal 10.000 karakter.',
                    metadata: ['invalid_field_key' => $key],
                );
            }
        }

        if ($finalize) {
            $missing = array_values(array_filter(
                OutpatientDocumentationDefinition::requiredOnFinal($documentType),
                static fn (string $key): bool => trim((string) ($fields[$key] ?? '')) === '',
            ));
            if ($missing !== []) {
                throw new OutpatientLifecycleDenial(
                    'validation_failed',
                    'Field wajib harus dilengkapi sebelum finalisasi.',
                    metadata: ['missing_field_keys' => $missing],
                );
            }
        }
    }

    /** @param array<array-key, mixed> $fields
     * @return array<string, string>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            if (! is_string($key)) {
                throw new OutpatientLifecycleDenial('validation_failed', 'Field dokumen wajib memakai kunci teks.');
            }
            $normalized[$key] = trim((string) $value);
        }
        ksort($normalized);

        return $normalized;
    }

    private function recordDenial(
        string $action,
        Encounter $encounter,
        User $actor,
        string $documentType,
        OutpatientLifecycleDenial $denial,
    ): void {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: array_merge(['document_type' => $documentType], $denial->metadata),
        );

        if ($event === null) {
            throw new RuntimeException("Audit penolakan gagal direkam untuk aksi {$action}.");
        }
    }
}
