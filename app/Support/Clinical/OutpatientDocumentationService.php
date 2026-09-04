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
        private readonly OutpatientTerminologyService $terminology,
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

                if ($lockedEncounter->status === Encounter::STATUS_REGISTERED) {
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

        $structuredKeys = ['diagnosis_text', 'primary_icd10', 'secondary_icd10', 'procedures_icd9cm'];
        $usesStructuredCoding = $documentType === OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT
            && array_intersect($structuredKeys, array_keys($fields)) !== [];
        if ($usesStructuredCoding && array_diff($structuredKeys, array_keys($fields)) !== []) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Field diagnosis dan kode terstruktur harus dikirim lengkap.');
        }
        foreach ($fields as $key => $value) {
            if (in_array($key, ['primary_icd10', 'secondary_icd10', 'procedures_icd9cm'], true)) {
                continue;
            }
            if ($key === 'additional_notes' && $value === null) {
                continue;
            }
            if (! is_string($value) || mb_strlen($value) > 10000) {
                throw new OutpatientLifecycleDenial(
                    'validation_failed',
                    'Isi field harus berupa teks dengan panjang maksimal 10.000 karakter.',
                    metadata: ['invalid_field_key' => $key],
                );
            }
        }

        if ($usesStructuredCoding) {
            $this->validateCoding($fields, $finalize);
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
     * @return array<string, mixed>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            if (! is_string($key)) {
                throw new OutpatientLifecycleDenial('validation_failed', 'Field dokumen wajib memakai kunci teks.');
            }
            if (in_array($key, ['primary_icd10', 'secondary_icd10', 'procedures_icd9cm'], true)) {
                if ($key === 'primary_icd10') {
                    $normalized[$key] = $value === null ? null : $this->normalizedCodeEntry($value);
                } else {
                    $normalized[$key] = array_map(fn (mixed $entry): array => $this->normalizedCodeEntry($entry), $value);
                }
            } else {
                $normalized[$key] = $key === 'additional_notes' && $value === null ? '' : trim((string) $value);
            }
        }
        ksort($normalized);

        return $normalized;
    }

    /** @param array<array-key,mixed> $fields */
    private function validateCoding(array $fields, bool $finalize): void
    {
        if (! is_string($fields['diagnosis_text'] ?? null) || mb_strlen(trim($fields['diagnosis_text'])) > 4000) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Diagnosis klinis harus berupa teks.');
        }
        $primary = $fields['primary_icd10'] ?? null;
        if ($primary !== null) {
            $primaryCode = $this->validateCodeEntry($primary, 'ICD-10');
        } else {
            $primaryCode = null;
        }
        foreach ([['secondary_icd10', 'ICD-10'], ['procedures_icd9cm', 'ICD-9-CM']] as [$key, $system]) {
            $entries = $fields[$key] ?? null;
            if (! is_array($entries) || ! array_is_list($entries) || count($entries) > 20) {
                throw new OutpatientLifecycleDenial('validation_failed', 'Daftar kode terstruktur tidak valid.');
            }
            $seen = [];
            foreach ($entries as $entry) {
                $normalized = $this->validateCodeEntry($entry, $system);
                if (isset($seen[$normalized])) {
                    throw new OutpatientLifecycleDenial('validation_failed', 'Kode terstruktur tidak boleh duplikat.');
                }
                if ($system === 'ICD-10' && $normalized === $primaryCode) {
                    throw new OutpatientLifecycleDenial('validation_failed', 'ICD-10 utama tidak boleh diulang sebagai diagnosis sekunder.');
                }
                $seen[$normalized] = true;
            }
        }
        if ($finalize && (trim($fields['diagnosis_text']) === '' || $primary === null)) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Diagnosis klinis dan ICD-10 utama wajib diisi sebelum finalisasi.');
        }
    }

    private function validateCodeEntry(mixed $entry, string $system): string
    {
        if (! is_array($entry) || count($entry) !== 2 || ! array_key_exists('code', $entry) || ! array_key_exists('display', $entry) || ! is_string($entry['code']) || ! is_string($entry['display'])) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Pilihan kode terstruktur tidak valid.');
        }
        $code = trim($entry['code']);
        $display = trim($entry['display']);
        $pattern = $system === 'ICD-10' ? '/\A[A-Z][0-9][0-9A-Z](?:\.[0-9A-Z]{1,4})?\z/' : '/\A[0-9]{2}(?:\.[0-9]{1,2})?\z/';
        if (preg_match($pattern, $code) !== 1 || $display === '' || mb_strlen($display) > 500) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Kode atau deskripsi terminologi tidak valid.');
        }
        if (! $this->terminology->verifySelection($system, $code, $display)) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Kode dan deskripsi tidak cocok dengan katalog terminologi resmi.');
        }

        return $system.':'.$code;
    }

    /** @return array{code:string,display:string} */
    private function normalizedCodeEntry(mixed $entry): array
    {
        if (! is_array($entry) || ! is_string($entry['code'] ?? null) || ! is_string($entry['display'] ?? null)) {
            throw new OutpatientLifecycleDenial('validation_failed', 'Pilihan kode terstruktur tidak valid.');
        }

        return ['code' => trim($entry['code']), 'display' => trim($entry['display'])];
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
