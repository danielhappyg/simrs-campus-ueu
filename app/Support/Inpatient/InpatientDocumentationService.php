<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientClinicalDocumentVersion;
use App\Models\InpatientDocumentOperationReceipt;
use App\Models\InpatientWard;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class InpatientDocumentationService
{
    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly InpatientDocumentationActorPolicy $actorPolicy,
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
    ) {}

    /** @param array<array-key, mixed> $fields */
    public function saveDraft(
        string $encounterPublicId,
        User $actor,
        string $documentType,
        string $definitionVersion,
        int $expectedVersion,
        array $fields,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientDocumentationMutationResult {
        return $this->execute(
            encounterPublicId: $encounterPublicId,
            actor: $actor,
            documentType: $documentType,
            definitionVersion: $definitionVersion,
            expectedVersion: $expectedVersion,
            fields: $fields,
            idempotencyKey: $idempotencyKey,
            requestCorrelationId: $requestCorrelationId,
            finalize: false,
        );
    }

    public function finalize(
        string $encounterPublicId,
        User $actor,
        string $documentType,
        string $definitionVersion,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientDocumentationMutationResult {
        return $this->execute(
            encounterPublicId: $encounterPublicId,
            actor: $actor,
            documentType: $documentType,
            definitionVersion: $definitionVersion,
            expectedVersion: $expectedVersion,
            fields: null,
            idempotencyKey: $idempotencyKey,
            requestCorrelationId: $requestCorrelationId,
            finalize: true,
        );
    }

    /** @param array<array-key, mixed>|null $fields */
    private function execute(
        string $encounterPublicId,
        User $actor,
        string $documentType,
        string $definitionVersion,
        int $expectedVersion,
        ?array $fields,
        string $idempotencyKey,
        ?string $requestCorrelationId,
        bool $finalize,
    ): InpatientDocumentationMutationResult {
        // Role + capability is intentionally checked before any manual encounter/document lookup.
        $this->actorPolicy->authorize($actor, $documentType);
        $action = InpatientDocumentationDefinition::auditAction($documentType, $finalize);

        try {
            $this->validateOperationInput($encounterPublicId, $definitionVersion, $expectedVersion, $idempotencyKey, $requestCorrelationId);
            $normalizedFields = $finalize ? null : $this->normalizeAndValidateFields($documentType, $fields ?? [], false);
            $canonicalKey = mb_strtolower($idempotencyKey);
            $operation = $this->operation($documentType, $finalize);

            if ($replay = $this->replayFromReceiptIfPresent(
                $encounterPublicId,
                $actor,
                $documentType,
                $expectedVersion,
                $normalizedFields,
                $canonicalKey,
                $finalize,
                $operation,
            )) {
                return $replay;
            }

            return $this->mutate(
                $encounterPublicId,
                $actor,
                $documentType,
                $expectedVersion,
                $normalizedFields,
                $canonicalKey,
                $requestCorrelationId,
                $finalize,
                $action,
            );
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileRace(
                    $encounterPublicId,
                    $actor,
                    $documentType,
                    $expectedVersion,
                    $fields,
                    mb_strtolower($idempotencyKey),
                    $finalize,
                    $race,
                );
            } catch (InpatientDocumentationDenied $denial) {
                $this->recordDenialOrFail($action, $encounterPublicId, $actor, $documentType, $denial);
                throw $denial;
            }
        } catch (InpatientDocumentationDenied $denial) {
            $this->recordDenialOrFail($action, $encounterPublicId, $actor, $documentType, $denial);
            throw $denial;
        } catch (InvalidArgumentException $invalid) {
            $denial = new InpatientDocumentationDenied('validation_failed', $invalid->getMessage());
            $this->recordDenialOrFail($action, $encounterPublicId, $actor, $documentType, $denial);
            throw $denial;
        }
    }

    /** @param array<string, string>|null $normalizedFields */
    private function mutate(
        string $encounterPublicId,
        User $actor,
        string $documentType,
        int $expectedVersion,
        ?array $normalizedFields,
        string $canonicalKey,
        ?string $requestCorrelationId,
        bool $finalize,
        string $action,
    ): InpatientDocumentationMutationResult {
        $ownsTransaction = DB::connection()->transactionLevel() === 0;
        $attempt = 0;

        while (true) {
            try {
                return $this->mutateOnce(
                    $encounterPublicId,
                    $actor,
                    $documentType,
                    $expectedVersion,
                    $normalizedFields,
                    $canonicalKey,
                    $requestCorrelationId,
                    $finalize,
                    $action,
                );
            } catch (InpatientDocumentationPlacementChanged) {
                $attempt++;
                if (! $ownsTransaction || $attempt >= 3) {
                    throw new InpatientDocumentationDenied(
                        'placement_stale',
                        'Penempatan rawat inap berubah berulang kali. Silakan coba kembali.',
                    );
                }
            }
        }
    }

    /** @param array<string, string>|null $normalizedFields */
    private function mutateOnce(
        string $encounterPublicId,
        User $actor,
        string $documentType,
        int $expectedVersion,
        ?array $normalizedFields,
        string $canonicalKey,
        ?string $requestCorrelationId,
        bool $finalize,
        string $action,
    ): InpatientDocumentationMutationResult {
        return DB::transaction(function () use (
            $encounterPublicId,
            $actor,
            $documentType,
            $expectedVersion,
            $normalizedFields,
            $canonicalKey,
            $requestCorrelationId,
            $finalize,
            $action,
        ): InpatientDocumentationMutationResult {
            [$encounter, $ward, $bed] = $this->lockEncounterAndManagedPlacement($encounterPublicId);
            $serviceDate = now('Asia/Jakarta')->toDateString();

            $document = InpatientClinicalDocument::query()
                ->where('encounter_id', $encounter->id)
                ->where('document_type', $documentType)
                ->whereDate('service_date', $serviceDate)
                ->where('author_user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            $effectiveFields = $finalize
                ? ($document instanceof InpatientClinicalDocument ? $document->fields : [])
                : ($normalizedFields ?? []);
            $digest = $this->operationDigest(
                $encounterPublicId,
                $documentType,
                $expectedVersion,
                $effectiveFields,
                $finalize,
                $document?->public_id,
            );
            $operation = $this->operation($documentType, $finalize);

            $receipt = InpatientDocumentOperationReceipt::query()
                ->where('actor_user_id', $actor->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $canonicalKey)
                ->lockForUpdate()
                ->first();
            if ($receipt instanceof InpatientDocumentOperationReceipt) {
                if (! hash_equals($receipt->payload_digest, $digest)) {
                    throw new InpatientDocumentationDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan yang berbeda.');
                }

                return $this->replayResult($receipt);
            }

            if ($finalize && ! $document instanceof InpatientClinicalDocument) {
                throw new InpatientDocumentationDenied('document_missing', 'Simpan draf harian sebelum finalisasi.');
            }
            $currentVersion = $document instanceof InpatientClinicalDocument ? $document->version : 0;
            if ($expectedVersion !== $currentVersion) {
                throw new InpatientDocumentationDenied(
                    'stale_version',
                    'Dokumen telah berubah. Muat ulang sebelum melanjutkan.',
                    metadata: ['expected_version' => $expectedVersion, 'current_version' => $currentVersion],
                );
            }
            if ($document instanceof InpatientClinicalDocument && $document->document_state === InpatientClinicalDocument::STATE_FINAL) {
                throw new InpatientDocumentationDenied('document_final', 'Dokumen Final bersifat tetap.');
            }
            if ($finalize) {
                $effectiveFields = $this->normalizeAndValidateFields($documentType, $effectiveFields, true);
            }

            $newVersion = $currentVersion + 1;
            $state = $finalize ? InpatientClinicalDocument::STATE_FINAL : InpatientClinicalDocument::STATE_DRAFT;
            $finalizedAt = $finalize ? now() : null;
            $statusSnapshot = $encounter->status;

            InpatientDocumentationMutationScope::run(function () use (
                &$document,
                $encounter,
                $actor,
                $documentType,
                $serviceDate,
                $state,
                $newVersion,
                $effectiveFields,
                $finalizedAt,
                $ward,
                $bed,
                $statusSnapshot,
            ): void {
                if (! $document instanceof InpatientClinicalDocument) {
                    $document = InpatientClinicalDocument::query()->create([
                        'encounter_id' => $encounter->id,
                        'author_user_id' => $actor->id,
                        'finalized_by_user_id' => null,
                        'document_type' => $documentType,
                        'service_date' => $serviceDate,
                        'document_state' => $state,
                        'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                        'version' => $newVersion,
                        'fields' => $effectiveFields,
                        'finalized_at' => null,
                    ]);
                } else {
                    $document->update([
                        'finalized_by_user_id' => $finalizedAt !== null ? $actor->id : null,
                        'document_state' => $state,
                        'version' => $newVersion,
                        'fields' => $effectiveFields,
                        'finalized_at' => $finalizedAt,
                    ]);
                }

                InpatientClinicalDocumentVersion::query()->create([
                    'inpatient_clinical_document_id' => $document->id,
                    'actor_user_id' => $actor->id,
                    'version' => $newVersion,
                    'document_type' => $documentType,
                    'document_state' => $state,
                    'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                    'fields' => $effectiveFields,
                    'encounter_public_id' => $encounter->public_id,
                    'care_setting' => $encounter->care_setting,
                    'service_date' => $serviceDate,
                    'ward_public_id' => $ward->public_id,
                    'ward_code' => $ward->code,
                    'ward_display_name' => $ward->display_name,
                    'bed_public_id' => $bed->public_id,
                    'bed_code' => $bed->code,
                    'bed_display_name' => $bed->display_name,
                    'room_label' => $bed->room_label,
                    'service_class' => $bed->service_class,
                    'encounter_status' => $statusSnapshot,
                    'finalized_at' => $finalizedAt,
                ]);
            });

            if ($finalize && $encounter->status === Encounter::STATUS_REGISTERED) {
                $encounter->update(['status' => Encounter::STATUS_IN_EXAMINATION]);
            }

            $metadata = [
                'encounter_id' => $encounter->public_id,
                'document_type' => $documentType,
                'document_state' => $state,
                'document_version' => $newVersion,
                'service_date' => $serviceDate,
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'author_user_public_id' => $actor->public_id,
                'ward_public_id' => $ward->public_id,
                'ward_code' => $ward->code,
                'bed_public_id' => $bed->public_id,
                'bed_code' => $bed->code,
                'expected_version' => $expectedVersion,
                'payload_digest' => $digest,
            ];
            if ($this->auditRecorder->record(
                action: $action,
                resourceType: 'inpatient_clinical_document',
                resourceId: $document->public_id,
                actor: $actor,
                metadata: $metadata,
            ) === null) {
                throw new InpatientDocumentationAuditUnavailable('Required inpatient documentation audit could not be recorded.');
            }

            InpatientDocumentationMutationScope::run(fn () => InpatientDocumentOperationReceipt::query()->create([
                'encounter_id' => $encounter->id,
                'actor_user_id' => $actor->id,
                'operation' => $operation,
                'idempotency_key' => $canonicalKey,
                'payload_digest' => $digest,
                'result_public_id' => $document->public_id,
                'result_version' => $newVersion,
                'request_correlation_id' => $requestCorrelationId,
                'completed_at' => now(),
            ]));

            $current = $document->fresh(['author', 'versions']) ?? $document;
            $resultVersion = InpatientClinicalDocumentVersion::query()
                ->where('inpatient_clinical_document_id', $document->id)
                ->where('version', $newVersion)
                ->sole();

            return new InpatientDocumentationMutationResult($current, $resultVersion, replayed: false);
        }, 3);
    }

    private function assertEligibleEncounter(Encounter $encounter): void
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            throw new InpatientDocumentationDenied('not_inpatient', 'Dokumentasi ini hanya tersedia untuk rawat inap.');
        }
        if (! in_array($encounter->status, Encounter::BED_OCCUPYING_STATUSES, true)) {
            throw new InpatientDocumentationDenied(
                $encounter->status === Encounter::STATUS_CANCELLED ? 'encounter_cancelled' : 'encounter_closed',
                'Episode tidak lagi menerima dokumentasi harian.',
            );
        }
        if (! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new InpatientDocumentationDenied('synthetic_only', 'Episode tidak berada dalam batas data yang diizinkan.');
        }
        if ($encounter->inpatient_bed_id === null) {
            throw new InpatientDocumentationDenied('placement_missing', 'Penempatan bangsal dan tempat tidur belum tersedia.');
        }
    }

    /** @return array{Encounter, InpatientWard, InpatientBed} */
    private function lockEncounterAndManagedPlacement(string $encounterPublicId): array
    {
        $candidateEncounter = Encounter::query()->where('public_id', $encounterPublicId)->first();
        if (! $candidateEncounter instanceof Encounter) {
            throw new InpatientDocumentationDenied('encounter_missing', 'Episode rawat inap tidak ditemukan.', 404);
        }
        $this->assertEligibleEncounter($candidateEncounter);
        if ($candidateEncounter->inpatient_bed_id === null) {
            throw new InpatientDocumentationDenied('placement_missing', 'Penempatan bangsal dan tempat tidur belum tersedia.');
        }
        $candidateBed = InpatientBed::query()->whereKey($candidateEncounter->inpatient_bed_id)->first();
        if (! $candidateBed instanceof InpatientBed) {
            throw new InpatientDocumentationDenied('placement_unmanaged', 'Penempatan tidak merujuk master tempat tidur terkelola.');
        }

        $this->locks->lockMutexes([$candidateBed->code]);
        $encounter = $this->locks->lockEncounters([$candidateEncounter->id])->get($candidateEncounter->id);
        if (! $encounter instanceof Encounter || $encounter->inpatient_bed_id === null) {
            throw new InpatientDocumentationDenied('placement_stale', 'Penempatan rawat inap telah berubah atau tidak lengkap.');
        }
        if ($encounter->inpatient_bed_id !== $candidateBed->id || $encounter->bed_code !== $candidateBed->code) {
            throw new InpatientDocumentationPlacementChanged('Encounter placement changed while awaiting its canonical mutex.');
        }
        $ward = $this->locks->lockWards([$candidateBed->ward_id])->get($candidateBed->ward_id);
        $bed = $this->locks->lockBeds([$candidateBed->id])->get($candidateBed->id);
        if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed
            || $encounter->inpatient_bed_id !== $bed->id || $encounter->bed_code !== $bed->code
            || $bed->ward_id !== $ward->id) {
            throw new InpatientDocumentationDenied('placement_stale', 'Penempatan rawat inap telah berubah atau tidak lengkap.');
        }
        $this->assertEligibleEncounter($encounter);
        if ($ward->state !== InpatientWard::STATE_ACTIVE || $bed->state !== InpatientBed::STATE_ACTIVE) {
            throw new InpatientDocumentationDenied('placement_inactive', 'Bangsal atau tempat tidur sudah tidak aktif.');
        }

        return [$encounter, $ward, $bed];
    }

    private function validateOperationInput(
        string $encounterPublicId,
        string $definitionVersion,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): void {
        if (! Str::isUlid($encounterPublicId)) {
            throw new InvalidArgumentException('Identitas episode tidak valid.');
        }
        if ($definitionVersion !== InpatientClinicalDocument::DEFINITION_VERSION) {
            throw new InvalidArgumentException('Versi definisi dokumentasi tidak didukung.');
        }
        if ($expectedVersion < 0) {
            throw new InvalidArgumentException('Versi yang diharapkan tidak boleh negatif.');
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Kunci operasi harus berisi 8-255 karakter aman.');
        }
        if ($requestCorrelationId !== null && ! Str::isUlid($requestCorrelationId)) {
            throw new InvalidArgumentException('Identitas korelasi permintaan tidak valid.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<string, string>
     */
    private function normalizeAndValidateFields(string $documentType, array $fields, bool $finalize): array
    {
        $allowed = InpatientDocumentationDefinition::allowedFields($documentType);
        if ($allowed === []) {
            throw new InpatientDocumentationDenied('validation_failed', 'Jenis dokumen tidak didukung.');
        }

        $unknown = array_values(array_filter(
            array_keys($fields),
            static fn (int|string $key): bool => ! is_string($key) || ! in_array($key, $allowed, true),
        ));
        if ($unknown !== []) {
            throw new InpatientDocumentationDenied('validation_failed', 'Dokumen memuat field yang tidak didukung.', metadata: [
                'invalid_field_key_digests' => array_map(
                    static fn (int|string $key): string => hash('sha256', (is_int($key) ? 'integer:' : 'string:').(string) $key),
                    array_slice($unknown, 0, 50),
                ),
                'invalid_field_key_count' => count($unknown),
            ]);
        }

        $normalized = [];
        foreach ($fields as $key => $value) {
            if (! is_string($key) || ! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 10000) {
                throw new InpatientDocumentationDenied('validation_failed', 'Isi field harus berupa teks UTF-8 maksimal 10.000 karakter.', metadata: [
                    'invalid_field_key' => is_string($key) ? $key : hash('sha256', 'integer:'.$key),
                ]);
            }
            $normalized[$key] = trim($value);
        }
        ksort($normalized, SORT_STRING);

        if ($finalize) {
            $missing = array_values(array_filter(
                InpatientDocumentationDefinition::requiredOnFinal($documentType),
                static fn (string $key): bool => ($normalized[$key] ?? '') === '',
            ));
            if ($missing !== []) {
                throw new InpatientDocumentationDenied('validation_failed', 'Field wajib harus dilengkapi sebelum finalisasi.', metadata: [
                    'missing_field_keys' => $missing,
                ]);
            }
        }

        return $normalized;
    }

    /** @param array<string, string> $fields */
    private function operationDigest(
        string $encounterPublicId,
        string $documentType,
        int $expectedVersion,
        array $fields,
        bool $finalize,
        ?string $documentPublicId,
    ): string {
        $payload = [
            'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
            'document_type' => $documentType,
            'encounter_public_id' => $encounterPublicId,
            'expected_version' => $expectedVersion,
            'operation' => $this->operation($documentType, $finalize),
        ];
        if ($finalize) {
            $payload['current_draft_public_id'] = $documentPublicId;
            $payload['current_draft_version'] = $expectedVersion;
        } else {
            $payload['fields'] = $fields;
        }

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            throw new InpatientDocumentationDenied('validation_failed', 'Permintaan tidak dapat dikanonisasi.');
        }
    }

    private function operation(string $documentType, bool $finalize): string
    {
        return $documentType.($finalize ? '_FINALIZE' : '_DRAFT_SAVE');
    }

    /** @param array<string, string>|null $normalizedFields */
    private function replayFromReceiptIfPresent(
        string $encounterPublicId,
        User $actor,
        string $documentType,
        int $expectedVersion,
        ?array $normalizedFields,
        string $canonicalKey,
        bool $finalize,
        string $operation,
    ): ?InpatientDocumentationMutationResult {
        $receipt = InpatientDocumentOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $canonicalKey)
            ->first();
        if (! $receipt instanceof InpatientDocumentOperationReceipt) {
            return null;
        }

        $digest = $this->operationDigest(
            $encounterPublicId,
            $documentType,
            $expectedVersion,
            $normalizedFields ?? [],
            $finalize,
            $finalize ? $receipt->result_public_id : null,
        );
        if (! hash_equals($receipt->payload_digest, $digest)) {
            throw new InpatientDocumentationDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan yang berbeda.');
        }

        return $this->replayResult($receipt);
    }

    private function replayResult(InpatientDocumentOperationReceipt $receipt): InpatientDocumentationMutationResult
    {
        // Locking reads are deliberate here: under MySQL REPEATABLE READ an outer
        // transaction may have established its snapshot before the winning
        // receipt committed. A current read must resolve the durable receipt to
        // the winning head/version instead of falsely reporting it missing.
        $document = InpatientClinicalDocument::query()
            ->where('public_id', $receipt->result_public_id)
            ->lockForUpdate()
            ->firstOrFail();
        $version = InpatientClinicalDocumentVersion::query()
            ->where('inpatient_clinical_document_id', $document->id)
            ->where('version', $receipt->result_version)
            ->lockForUpdate()
            ->sole();

        return new InpatientDocumentationMutationResult($document, $version, replayed: true);
    }

    /** @param array<array-key, mixed>|null $fields */
    private function reconcileRace(
        string $encounterPublicId,
        User $actor,
        string $documentType,
        int $expectedVersion,
        ?array $fields,
        string $canonicalKey,
        bool $finalize,
        UniqueConstraintViolationException $race,
    ): InpatientDocumentationMutationResult {
        $receipt = InpatientDocumentOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', $this->operation($documentType, $finalize))
            ->where('idempotency_key', $canonicalKey)
            ->first();
        if ($receipt instanceof InpatientDocumentOperationReceipt) {
            $effectiveFields = $finalize ? [] : $this->normalizeAndValidateFields($documentType, $fields ?? [], false);
            $digest = $this->operationDigest(
                $encounterPublicId,
                $documentType,
                $expectedVersion,
                $effectiveFields,
                $finalize,
                $finalize ? $receipt->result_public_id : null,
            );
            if (! hash_equals($receipt->payload_digest, $digest)) {
                throw new InpatientDocumentationDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan yang berbeda.');
            }

            return $this->replayResult($receipt);
        }

        $document = InpatientClinicalDocument::query()
            ->whereHas('encounter', fn ($query) => $query->where('public_id', $encounterPublicId))
            ->where('document_type', $documentType)
            ->whereDate('service_date', now('Asia/Jakarta')->toDateString())
            ->where('author_user_id', $actor->id)
            ->firstOrFail();

        throw new InpatientDocumentationDenied(
            'stale_version',
            'Dokumen berubah bersamaan. Muat ulang sebelum melanjutkan.',
            metadata: ['expected_version' => $expectedVersion, 'current_version' => $document->version],
        );
    }

    private function recordDenialOrFail(
        string $action,
        string $encounterPublicId,
        User $actor,
        string $documentType,
        InpatientDocumentationDenied $denial,
    ): void {
        $metadata = array_merge([
            'document_type' => $documentType,
            'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
        ], $denial->metadata);
        if ($this->auditRecorder->record(
            action: $action,
            resourceType: 'encounter',
            resourceId: $encounterPublicId,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: $metadata,
        ) === null) {
            throw new InpatientDocumentationAuditUnavailable('Required inpatient documentation denial audit could not be recorded.');
        }
    }
}
