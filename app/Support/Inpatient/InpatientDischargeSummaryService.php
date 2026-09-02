<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientDischargeSummaryOperationReceipt;
use App\Models\InpatientDischargeSummaryVersion;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class InpatientDischargeSummaryService
{
    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly InpatientDischargeSummaryActorPolicy $actorPolicy,
        private readonly CanonicalInpatientBedOperationLockCoordinator $locks,
    ) {}

    /** @param array<array-key, mixed> $fields */
    public function saveDraft(
        string $encounterPublicId,
        User $actor,
        string $definitionVersion,
        int $expectedVersion,
        array $fields,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientDischargeSummaryMutationResult {
        return $this->execute(
            $encounterPublicId,
            $actor,
            $definitionVersion,
            $expectedVersion,
            $fields,
            $idempotencyKey,
            $requestCorrelationId,
            false,
        );
    }

    public function finalize(
        string $encounterPublicId,
        User $actor,
        string $definitionVersion,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientDischargeSummaryMutationResult {
        return $this->execute(
            $encounterPublicId,
            $actor,
            $definitionVersion,
            $expectedVersion,
            null,
            $idempotencyKey,
            $requestCorrelationId,
            true,
        );
    }

    /** @param array<array-key, mixed>|null $fields */
    private function execute(
        string $encounterPublicId,
        User $actor,
        string $definitionVersion,
        int $expectedVersion,
        ?array $fields,
        string $idempotencyKey,
        ?string $requestCorrelationId,
        bool $finalize,
    ): InpatientDischargeSummaryMutationResult {
        // Exact role and dedicated capability are checked before episode lookup.
        $this->actorPolicy->authorize($actor);
        $action = $finalize
            ? 'clinical.inpatient.discharge-summary.finalize'
            : 'clinical.inpatient.discharge-summary.draft.save';

        try {
            $this->validateOperationInput($encounterPublicId, $definitionVersion, $expectedVersion, $idempotencyKey, $requestCorrelationId);
            $normalized = $finalize ? null : $this->normalizeAndValidateFields($fields ?? [], false);
            $canonicalKey = mb_strtolower($idempotencyKey);
            $operation = $finalize
                ? InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE
                : InpatientDischargeSummaryOperationReceipt::OPERATION_DRAFT_SAVE;

            if ($replay = $this->replayFromReceiptIfPresent(
                $encounterPublicId,
                $actor,
                $expectedVersion,
                $normalized,
                $canonicalKey,
                $operation,
                $finalize,
            )) {
                return $replay;
            }

            return $this->mutate(
                $encounterPublicId,
                $actor,
                $expectedVersion,
                $normalized,
                $canonicalKey,
                $requestCorrelationId,
                $operation,
                $finalize,
                $action,
            );
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileRace(
                    $encounterPublicId,
                    $actor,
                    $expectedVersion,
                    $fields,
                    mb_strtolower($idempotencyKey),
                    $finalize,
                    $race,
                );
            } catch (InpatientDischargeSummaryDenied $denial) {
                $this->recordDenialOrFail($action, $encounterPublicId, $actor, $denial);
                throw $denial;
            }
        } catch (InpatientDischargeSummaryDenied $denial) {
            $this->recordDenialOrFail($action, $encounterPublicId, $actor, $denial);
            throw $denial;
        } catch (InvalidArgumentException $invalid) {
            $denial = new InpatientDischargeSummaryDenied('validation_failed', $invalid->getMessage());
            $this->recordDenialOrFail($action, $encounterPublicId, $actor, $denial);
            throw $denial;
        }
    }

    /** @param array<string, string>|null $normalized */
    private function mutate(
        string $encounterPublicId,
        User $actor,
        int $expectedVersion,
        ?array $normalized,
        string $canonicalKey,
        ?string $requestCorrelationId,
        string $operation,
        bool $finalize,
        string $action,
    ): InpatientDischargeSummaryMutationResult {
        $ownsTransaction = DB::connection()->transactionLevel() === 0;
        $attempt = 0;

        while (true) {
            try {
                return $this->mutateOnce(
                    $encounterPublicId,
                    $actor,
                    $expectedVersion,
                    $normalized,
                    $canonicalKey,
                    $requestCorrelationId,
                    $operation,
                    $finalize,
                    $action,
                );
            } catch (InpatientDischargeSummaryPlacementChanged) {
                $attempt++;
                if (! $ownsTransaction || $attempt >= 3) {
                    throw new InpatientDischargeSummaryDenied(
                        'placement_stale',
                        'Penempatan rawat inap berubah berulang kali. Silakan coba kembali.',
                    );
                }
            }
        }
    }

    /** @param array<string, string>|null $normalized */
    private function mutateOnce(
        string $encounterPublicId,
        User $actor,
        int $expectedVersion,
        ?array $normalized,
        string $canonicalKey,
        ?string $requestCorrelationId,
        string $operation,
        bool $finalize,
        string $action,
    ): InpatientDischargeSummaryMutationResult {
        return DB::transaction(function () use (
            $encounterPublicId,
            $actor,
            $expectedVersion,
            $normalized,
            $canonicalKey,
            $requestCorrelationId,
            $operation,
            $finalize,
            $action,
        ): InpatientDischargeSummaryMutationResult {
            [$encounter, $ward, $bed, $location, $historyBaseline, $historyComplete] = $this->lockEncounterAndManagedPlacement($encounterPublicId);
            $locationSequence = $location instanceof InpatientLocationEvent ? $location->sequence : 0;

            $summary = InpatientDischargeSummary::query()
                ->where('encounter_id', $encounter->id)
                ->lockForUpdate()
                ->first();

            if ($summary instanceof InpatientDischargeSummary
                && $summary->assigned_physician_user_id !== $actor->id) {
                throw new InpatientDischargeSummaryDenied(
                    'physician_assignment_mismatch',
                    'Ringkasan pulang hanya dapat diubah oleh dokter yang ditetapkan pada ringkasan ini.',
                );
            }
            if ($finalize && ! $summary instanceof InpatientDischargeSummary) {
                throw new InpatientDischargeSummaryDenied('summary_missing', 'Simpan draf ringkasan pulang sebelum finalisasi.');
            }

            $effectiveFields = $finalize ? $this->fieldsFromSummary($summary) : ($normalized ?? []);
            $digest = $this->operationDigest(
                $encounterPublicId,
                $expectedVersion,
                $effectiveFields,
                $operation,
                $finalize ? $summary->public_id : null,
            );

            $receiptQuery = InpatientDischargeSummaryOperationReceipt::query()
                ->where('actor_user_id', $actor->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $canonicalKey);
            // MySQL needs a locking current read after waiting on canonical
            // mutexes. PostgreSQL READ COMMITTED already takes a fresh
            // statement snapshot, and row-lock clauses would require UPDATE
            // privilege on this immutable table.
            $receipt = DB::connection()->getDriverName() === 'mysql'
                ? $receiptQuery->sharedLock()->first()
                : $receiptQuery->first();
            if ($receipt instanceof InpatientDischargeSummaryOperationReceipt) {
                if (! hash_equals($receipt->payload_digest, $digest)) {
                    throw new InpatientDischargeSummaryDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan yang berbeda.');
                }

                return $this->replayResult($receipt);
            }

            $currentVersion = $summary instanceof InpatientDischargeSummary ? $summary->version : 0;
            if ($expectedVersion !== $currentVersion) {
                throw new InpatientDischargeSummaryDenied(
                    'stale_version',
                    'Ringkasan pulang telah berubah. Muat ulang sebelum melanjutkan.',
                    metadata: ['expected_version' => $expectedVersion, 'current_version' => $currentVersion],
                );
            }
            if ($summary instanceof InpatientDischargeSummary
                && $summary->summary_state === InpatientDischargeSummary::STATE_FINAL) {
                throw new InpatientDischargeSummaryDenied('summary_final', 'Ringkasan pulang Final bersifat tetap.');
            }
            if ($finalize) {
                $effectiveFields = $this->normalizeAndValidateFields($effectiveFields, true);
            }

            $newVersion = $currentVersion + 1;
            $state = $finalize ? InpatientDischargeSummary::STATE_FINAL : InpatientDischargeSummary::STATE_DRAFT;
            $finalizedAt = $finalize ? now() : null;
            $statusSnapshot = $encounter->status;

            InpatientDischargeSummaryMutationScope::run(function () use (
                &$summary,
                $encounter,
                $actor,
                $state,
                $newVersion,
                $effectiveFields,
                $finalizedAt,
                $ward,
                $bed,
                $location,
                $locationSequence,
                $historyBaseline,
                $historyComplete,
                $statusSnapshot,
            ): void {
                $headValues = [
                    'finalized_by_user_id' => $finalizeUserId = $finalizedAt !== null ? $actor->id : null,
                    'summary_state' => $state,
                    'version' => $newVersion,
                    ...$effectiveFields,
                    'finalized_at' => $finalizedAt,
                ];
                if (! $summary instanceof InpatientDischargeSummary) {
                    $summary = InpatientDischargeSummary::query()->create([
                        'encounter_id' => $encounter->id,
                        'assigned_physician_user_id' => $actor->id,
                        'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                        ...$headValues,
                    ]);
                } else {
                    $summary->update($headValues);
                }

                InpatientDischargeSummaryVersion::query()->create([
                    'inpatient_discharge_summary_id' => $summary->id,
                    'actor_user_id' => $actor->id,
                    'version' => $newVersion,
                    'summary_state' => $state,
                    'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                    ...$effectiveFields,
                    'encounter_public_id' => $encounter->public_id,
                    'care_setting' => $encounter->care_setting,
                    'encounter_status' => $statusSnapshot,
                    'location_sequence' => $locationSequence,
                    'location_event_public_id' => $location?->public_id,
                    'location_event_type' => $location?->event_type,
                    'history_baseline' => $historyBaseline,
                    'history_complete' => $historyComplete,
                    'ward_public_id' => $ward->public_id,
                    'ward_code' => $ward->code,
                    'ward_display_name' => $ward->display_name,
                    'bed_public_id' => $bed->public_id,
                    'bed_code' => $bed->code,
                    'bed_display_name' => $bed->display_name,
                    'room_label' => $bed->room_label,
                    'service_class' => $bed->service_class,
                    'finalized_at' => $finalizedAt,
                ]);
            });

            // Finalization deliberately has no encounter, placement, billing,
            // claim, RMIK, pharmacy, or external-integration side effects.
            $metadata = [
                'encounter_public_id' => $encounter->public_id,
                'summary_state' => $state,
                'summary_version' => $newVersion,
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'assigned_physician_user_public_id' => $actor->public_id,
                'location_sequence' => $locationSequence,
                'location_event_public_id' => $location?->public_id,
                'location_event_type' => $location?->event_type,
                'history_baseline' => $historyBaseline,
                'history_complete' => $historyComplete,
                'ward_public_id' => $ward->public_id,
                'ward_code' => $ward->code,
                'bed_public_id' => $bed->public_id,
                'bed_code' => $bed->code,
                'expected_version' => $expectedVersion,
                'payload_digest' => $digest,
            ];
            if ($this->auditRecorder->record(
                action: $action,
                resourceType: 'inpatient_discharge_summary',
                resourceId: $summary->public_id,
                actor: $actor,
                metadata: $metadata,
            ) === null) {
                throw new InpatientDischargeSummaryAuditUnavailable('Required inpatient discharge summary audit could not be recorded.');
            }

            InpatientDischargeSummaryMutationScope::run(fn () => InpatientDischargeSummaryOperationReceipt::query()->create([
                'encounter_id' => $encounter->id,
                'actor_user_id' => $actor->id,
                'operation' => $operation,
                'idempotency_key' => $canonicalKey,
                'payload_digest' => $digest,
                'result_summary_public_id' => $summary->public_id,
                'result_version' => $newVersion,
                'request_correlation_id' => $requestCorrelationId,
                'completed_at' => now(),
            ]));

            $current = $summary->fresh(['assignedPhysician', 'versions']) ?? $summary;
            $resultVersion = InpatientDischargeSummaryVersion::query()
                ->where('inpatient_discharge_summary_id', $summary->id)
                ->where('version', $newVersion)
                ->sole();

            return new InpatientDischargeSummaryMutationResult($current, $resultVersion, false);
        }, 3);
    }

    /** @return array{Encounter, InpatientWard, InpatientBed, InpatientLocationEvent|null, string|null, bool} */
    private function lockEncounterAndManagedPlacement(string $encounterPublicId): array
    {
        $candidateEncounter = Encounter::query()->where('public_id', $encounterPublicId)->first();
        if (! $candidateEncounter instanceof Encounter) {
            throw new InpatientDischargeSummaryDenied('encounter_missing', 'Episode rawat inap tidak ditemukan.', 404);
        }
        $this->assertEligibleEncounter($candidateEncounter);
        if ($candidateEncounter->inpatient_bed_id === null) {
            throw new InpatientDischargeSummaryDenied('placement_missing', 'Penempatan bangsal dan tempat tidur belum tersedia.');
        }
        $candidateBed = InpatientBed::query()->whereKey($candidateEncounter->inpatient_bed_id)->first();
        if (! $candidateBed instanceof InpatientBed) {
            throw new InpatientDischargeSummaryDenied('placement_unmanaged', 'Penempatan tidak merujuk master tempat tidur terkelola.');
        }

        $this->locks->lockMutexes([$candidateBed->code]);
        $encounter = $this->locks->lockEncounters([$candidateEncounter->id])->get($candidateEncounter->id);
        if (! $encounter instanceof Encounter || $encounter->inpatient_bed_id === null) {
            throw new InpatientDischargeSummaryDenied('placement_stale', 'Penempatan rawat inap telah berubah atau tidak lengkap.');
        }
        if ($encounter->inpatient_bed_id !== $candidateBed->id || $encounter->bed_code !== $candidateBed->code) {
            throw new InpatientDischargeSummaryPlacementChanged('Encounter placement changed while awaiting its canonical mutex.');
        }
        $ward = $this->locks->lockWards([$candidateBed->ward_id])->get($candidateBed->ward_id);
        $bed = $this->locks->lockBeds([$candidateBed->id])->get($candidateBed->id);
        if (! $ward instanceof InpatientWard || ! $bed instanceof InpatientBed
            || $encounter->inpatient_bed_id !== $bed->id || $encounter->bed_code !== $bed->code
            || $bed->ward_id !== $ward->id) {
            throw new InpatientDischargeSummaryDenied('placement_stale', 'Penempatan rawat inap telah berubah atau tidak lengkap.');
        }
        $this->assertEligibleEncounter($encounter);
        if ($encounter->cancellation()->exists()) {
            throw new InpatientDischargeSummaryDenied('encounter_cancelled', 'Episode memiliki fakta pembatalan yang tetap tersimpan.');
        }
        if ($ward->state !== InpatientWard::STATE_ACTIVE || $bed->state !== InpatientBed::STATE_ACTIVE) {
            throw new InpatientDischargeSummaryDenied('placement_inactive', 'Bangsal atau tempat tidur sudah tidak aktif.');
        }

        $locationQuery = InpatientLocationEvent::query()
            ->where('encounter_id', $encounter->id)
            ->orderByDesc('sequence');
        // The canonical bed mutex serializes transfer. MySQL needs a shared
        // locking current read after the pre-mutex candidate lookup; PostgreSQL
        // READ COMMITTED uses a fresh statement snapshot without row-lock
        // privileges on immutable evidence.
        $location = DB::connection()->getDriverName() === 'mysql'
            ? $locationQuery->sharedLock()->first()
            : $locationQuery->first();
        if ($location instanceof InpatientLocationEvent
            && ($location->to_bed_public_id !== $bed->public_id || $location->to_bed_code !== $bed->code
                || $location->to_ward_public_id !== $ward->public_id)) {
            throw new InpatientDischargeSummaryDenied('placement_stale', 'Riwayat penempatan tidak sesuai dengan penempatan aktif.');
        }

        if (! $location instanceof InpatientLocationEvent) {
            return [
                $encounter,
                $ward,
                $bed,
                null,
                InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT,
                false,
            ];
        }

        $firstEventType = InpatientLocationEvent::query()
            ->where('encounter_id', $encounter->id)
            ->orderBy('sequence')
            ->value('event_type');
        $historyComplete = $firstEventType === InpatientLocationEvent::TYPE_ADMISSION;

        return [$encounter, $ward, $bed, $location, null, $historyComplete];
    }

    private function assertEligibleEncounter(Encounter $encounter): void
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            throw new InpatientDischargeSummaryDenied('not_inpatient', 'Ringkasan pulang hanya tersedia untuk rawat inap.');
        }
        if (! in_array($encounter->status, Encounter::BED_OCCUPYING_STATUSES, true)) {
            throw new InpatientDischargeSummaryDenied(
                $encounter->status === Encounter::STATUS_CANCELLED ? 'encounter_cancelled' : 'encounter_closed',
                'Episode tidak lagi aktif untuk ringkasan pulang.',
            );
        }
        if (! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new InpatientDischargeSummaryDenied('synthetic_only', 'Episode tidak berada dalam batas data yang diizinkan.');
        }
        if ($encounter->inpatient_bed_id === null) {
            throw new InpatientDischargeSummaryDenied('placement_missing', 'Penempatan bangsal dan tempat tidur belum tersedia.');
        }
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
        if ($definitionVersion !== InpatientDischargeSummary::DEFINITION_VERSION) {
            throw new InvalidArgumentException('Versi definisi ringkasan pulang tidak didukung.');
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
     * @return array<string, string|null>
     */
    private function normalizeAndValidateFields(array $fields, bool $finalize): array
    {
        $unknown = array_values(array_filter(
            array_keys($fields),
            static fn (int|string $key): bool => ! is_string($key)
                || ! in_array($key, InpatientDischargeSummary::NARRATIVE_FIELDS, true),
        ));
        if ($unknown !== []) {
            throw new InpatientDischargeSummaryDenied('validation_failed', 'Ringkasan pulang memuat field yang tidak didukung.', metadata: [
                'invalid_field_key_digests' => array_map(
                    static fn (int|string $key): string => hash('sha256', (is_int($key) ? 'integer:' : 'string:').(string) $key),
                    array_slice($unknown, 0, 50),
                ),
                'invalid_field_key_count' => count($unknown),
            ]);
        }

        $normalized = array_fill_keys(InpatientDischargeSummary::NARRATIVE_FIELDS, null);
        foreach ($fields as $key => $value) {
            if (! is_string($key) || ! is_string($value) || ! mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value, 'UTF-8') > 10000) {
                throw new InpatientDischargeSummaryDenied(
                    'validation_failed',
                    'Isi ringkasan harus berupa teks UTF-8 maksimal 10.000 karakter.',
                    metadata: ['invalid_field_key_digest' => hash('sha256', (string) $key)],
                );
            }
            $trimmed = trim($value);
            $normalized[$key] = $trimmed === '' ? null : $trimmed;
        }

        if ($finalize) {
            $missing = array_values(array_filter(
                InpatientDischargeSummary::NARRATIVE_FIELDS,
                static fn (string $field): bool => ! is_string($normalized[$field] ?? null)
                    || trim((string) $normalized[$field]) === '',
            ));
            if ($missing !== []) {
                throw new InpatientDischargeSummaryDenied('validation_failed', 'Semua field ringkasan pulang wajib dilengkapi sebelum finalisasi.', metadata: [
                    'missing_field_keys' => $missing,
                ]);
            }
        }

        return $normalized;
    }

    /** @return array<string, string|null> */
    private function fieldsFromSummary(?InpatientDischargeSummary $summary): array
    {
        $fields = [];
        foreach (InpatientDischargeSummary::NARRATIVE_FIELDS as $field) {
            $value = $summary?->getAttribute($field);
            $fields[$field] = is_string($value) ? $value : null;
        }

        return $fields;
    }

    /** @param array<string, string|null> $fields */
    private function operationDigest(
        string $encounterPublicId,
        int $expectedVersion,
        array $fields,
        string $operation,
        ?string $summaryPublicId,
    ): string {
        $payload = [
            'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
            'encounter_public_id' => $encounterPublicId,
            'expected_version' => $expectedVersion,
            'operation' => $operation,
        ];
        if ($operation === InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE) {
            $payload['current_summary_public_id'] = $summaryPublicId;
            $payload['current_summary_version'] = $expectedVersion;
            $payload['stored_content_digest'] = $this->contentDigest($fields);
        } else {
            $payload['fields'] = $fields;
        }

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            throw new InpatientDischargeSummaryDenied('validation_failed', 'Permintaan tidak dapat dikanonisasi.');
        }
    }

    /** @param array<string, string|null>|null $normalized */
    private function replayFromReceiptIfPresent(
        string $encounterPublicId,
        User $actor,
        int $expectedVersion,
        ?array $normalized,
        string $canonicalKey,
        string $operation,
        bool $finalize,
    ): ?InpatientDischargeSummaryMutationResult {
        $receipt = InpatientDischargeSummaryOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $canonicalKey)
            ->first();
        if (! $receipt instanceof InpatientDischargeSummaryOperationReceipt) {
            return null;
        }
        $verifiedResult = $this->replayResult($receipt);
        $digestFields = $finalize ? $this->fieldsFromVersion($verifiedResult->resultVersion) : ($normalized ?? []);
        $digest = $this->operationDigest(
            $encounterPublicId,
            $expectedVersion,
            $digestFields,
            $operation,
            $finalize ? $receipt->result_summary_public_id : null,
        );
        if (! hash_equals($receipt->payload_digest, $digest)) {
            throw new InpatientDischargeSummaryDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan yang berbeda.');
        }

        return $verifiedResult;
    }

    private function replayResult(InpatientDischargeSummaryOperationReceipt $receipt): InpatientDischargeSummaryMutationResult
    {
        if (! in_array($receipt->operation, [
            InpatientDischargeSummaryOperationReceipt::OPERATION_DRAFT_SAVE,
            InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE,
        ], true)) {
            throw new InpatientDischargeSummaryDenied(
                'receipt_binding_invalid',
                'Operasi pada bukti idempotensi ringkasan pulang tidak dikenal.',
                503,
            );
        }
        $summary = InpatientDischargeSummary::query()
            ->where('public_id', $receipt->result_summary_public_id)
            ->first();
        if (! $summary instanceof InpatientDischargeSummary) {
            throw new InpatientDischargeSummaryDenied(
                'receipt_binding_invalid',
                'Ringkasan hasil pada bukti idempotensi tidak ditemukan.',
                503,
            );
        }
        $version = InpatientDischargeSummaryVersion::query()
            ->where('inpatient_discharge_summary_id', $summary->id)
            ->where('version', $receipt->result_version)
            ->first();
        if (! $version instanceof InpatientDischargeSummaryVersion) {
            throw new InpatientDischargeSummaryDenied(
                'receipt_binding_invalid',
                'Versi hasil pada bukti idempotensi tidak ditemukan.',
                503,
            );
        }

        $encounter = Encounter::query()->whereKey($receipt->encounter_id)->first();
        if (! $encounter instanceof Encounter
            || $summary->encounter_id !== $receipt->encounter_id
            || $version->encounter_public_id !== $encounter->public_id
            || $version->inpatient_discharge_summary_id !== $summary->id
            || $version->version !== $receipt->result_version
            || $receipt->result_version < 1) {
            throw new InpatientDischargeSummaryDenied(
                'receipt_binding_invalid',
                'Bukti idempotensi ringkasan pulang tidak terikat pada hasil yang benar.',
                503,
            );
        }
        $expectedDigest = $this->operationDigest(
            $encounter->public_id,
            $receipt->result_version - 1,
            $this->fieldsFromVersion($version),
            $receipt->operation,
            $receipt->operation === InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE
                ? $summary->public_id
                : null,
        );
        if (! hash_equals($receipt->payload_digest, $expectedDigest)) {
            throw new InpatientDischargeSummaryDenied(
                'receipt_binding_invalid',
                'Digest bukti idempotensi ringkasan pulang tidak sesuai dengan versi tersimpan.',
                503,
            );
        }

        return new InpatientDischargeSummaryMutationResult($summary, $version, true);
    }

    /** @return array<string, string|null> */
    private function fieldsFromVersion(InpatientDischargeSummaryVersion $version): array
    {
        $fields = [];
        foreach (InpatientDischargeSummary::NARRATIVE_FIELDS as $field) {
            $value = $version->getAttribute($field);
            $fields[$field] = is_string($value) ? $value : null;
        }

        return $fields;
    }

    /** @param array<string, string|null> $fields */
    private function contentDigest(array $fields): string
    {
        try {
            return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            throw new InpatientDischargeSummaryDenied('validation_failed', 'Isi ringkasan tidak dapat dikanonisasi.');
        }
    }

    /** @param array<array-key, mixed>|null $fields */
    private function reconcileRace(
        string $encounterPublicId,
        User $actor,
        int $expectedVersion,
        ?array $fields,
        string $canonicalKey,
        bool $finalize,
        UniqueConstraintViolationException $race,
    ): InpatientDischargeSummaryMutationResult {
        $operation = $finalize
            ? InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE
            : InpatientDischargeSummaryOperationReceipt::OPERATION_DRAFT_SAVE;
        $receipt = InpatientDischargeSummaryOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $canonicalKey)
            ->first();
        if ($receipt instanceof InpatientDischargeSummaryOperationReceipt) {
            $verifiedResult = $this->replayResult($receipt);
            $normalized = $finalize
                ? $this->fieldsFromVersion($verifiedResult->resultVersion)
                : $this->normalizeAndValidateFields($fields ?? [], false);
            $digest = $this->operationDigest(
                $encounterPublicId,
                $expectedVersion,
                $normalized,
                $operation,
                $finalize ? $receipt->result_summary_public_id : null,
            );
            if (! hash_equals($receipt->payload_digest, $digest)) {
                throw new InpatientDischargeSummaryDenied('idempotency_key_conflict', 'Kunci operasi telah dipakai untuk permintaan yang berbeda.');
            }

            return $verifiedResult;
        }

        $summary = InpatientDischargeSummary::query()
            ->whereHas('encounter', fn ($query) => $query->where('public_id', $encounterPublicId))
            ->first();
        if ($summary instanceof InpatientDischargeSummary
            && $summary->assigned_physician_user_id !== $actor->id) {
            throw new InpatientDischargeSummaryDenied(
                'physician_assignment_mismatch',
                'Ringkasan pulang telah ditetapkan kepada dokter lain.',
            );
        }

        throw new InpatientDischargeSummaryDenied(
            'stale_version',
            'Ringkasan pulang berubah bersamaan. Muat ulang sebelum melanjutkan.',
            metadata: [
                'expected_version' => $expectedVersion,
                'current_version' => $summary instanceof InpatientDischargeSummary ? $summary->version : 0,
            ],
        );
    }

    private function recordDenialOrFail(
        string $action,
        string $encounterPublicId,
        User $actor,
        InpatientDischargeSummaryDenied $denial,
    ): void {
        $metadata = array_merge([
            'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
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
            throw new InpatientDischargeSummaryAuditUnavailable('Required inpatient discharge summary denial audit could not be recorded.');
        }
    }
}
