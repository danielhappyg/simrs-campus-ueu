<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientDischarge;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientRmCoding;
use App\Models\InpatientRmCodingAssignment;
use App\Models\InpatientRmCodingVersion;
use App\Models\InpatientRmCompletenessReview;
use App\Models\InpatientRmOperationReceipt;
use App\Models\LabServiceRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CanonicalJson;
use App\Support\Laboratory\LaboratoryClosureGate;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Radiology\RadiologyClosureGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InpatientRmService
{
    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    private const ACTION_CODING = 'rmik.inpatient.coding.draft.save';

    private const ACTION_REVIEW = 'rmik.inpatient.completeness.review.save';

    private const ACTION_SIGNOFF = 'rmik.inpatient.episode.signoff';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InpatientRmActorPolicy $actorPolicy,
        private readonly InpatientDischargeCodingSourceEvidenceDigest $sourceDigests,
        private readonly InpatientDischargeSummaryEvidenceDigest $summaryDigests,
        private readonly PharmacyEncounterLifecycleGate $pharmacy,
    ) {}

    /**
     * @return array{
     *   source_fingerprint:string,coding_version:int,coding_digest:string,
     *   source_version_public_id:string|null,source_content_digest:string|null,source_provenance_digest:string|null,
     *   items:list<array{item_code:string,label:string,is_blocking:bool,is_complete:bool,source_reference:string|null}>,
     *   blockers:list<string>
     * }
     */
    public function snapshot(Encounter $encounter): array
    {
        $encounter->loadMissing([
            'patient', 'inpatientDischarge.summary', 'inpatientDischarge.summaryVersion',
            'inpatientDischarge.codingSourceVersion.source', 'inpatientDischargeSummary',
            'inpatientDischargeCodingSource', 'inpatientClinicalDocuments', 'labServiceRequests',
            'inpatientRmCoding.versions.assignments',
        ]);
        $discharge = $encounter->inpatientDischarge()->first();
        $summary = $encounter->inpatientDischargeSummary()->first();
        $source = $encounter->inpatientDischargeCodingSource()->first();
        $coding = $encounter->inpatientRmCoding()->first();
        $codingVersion = $coding instanceof InpatientRmCoding
            ? $coding->versions->sortByDesc('version')->first()
            : null;
        $activeLabs = $encounter->labServiceRequests
            ->where('status', LabServiceRequest::STATUS_ACTIVE)
            ->sortBy('public_id')->pluck('public_id')->values()->all();
        $radiology = app(RadiologyClosureGate::class)->inspect($encounter);
        $laboratory = app(LaboratoryClosureGate::class)->inspect($encounter);
        $pharmacy = $this->pharmacy->inspect($encounter);
        $allActiveLabs = collect($activeLabs)
            ->merge($laboratory['active_order_public_ids'])
            ->unique()
            ->sort()
            ->values()
            ->all();
        $draftDocuments = $encounter->inpatientClinicalDocuments
            ->where('document_state', InpatientClinicalDocument::STATE_DRAFT)
            ->sortBy('public_id')->map(fn (InpatientClinicalDocument $document): array => [
                'public_id' => $document->public_id,
                'version' => $document->version,
                'type' => $document->document_type,
            ])->values()->all();

        $identityComplete = $encounter->patient !== null
            && $encounter->patient->public_id !== ''
            && $encounter->patient->medical_record_number !== '';
        $dischargeComplete = $discharge instanceof InpatientDischarge
            && $discharge->disposition_code === InpatientDischarge::DISPOSITION_ROUTINE_HOME
            && $discharge->encounter_status_after === Encounter::STATUS_READY_FOR_RM;
        $summaryComplete = $summary instanceof InpatientDischargeSummary
            && $summary->summary_state === InpatientDischargeSummary::STATE_FINAL
            && $discharge instanceof InpatientDischarge
            && $discharge->discharge_summary_public_id === $summary->public_id
            && $discharge->discharge_summary_version === $summary->version;
        $sourceComplete = $source instanceof InpatientDischargeCodingSource
            && $source->source_state === InpatientDischargeCodingSource::STATE_FINAL
            && $discharge instanceof InpatientDischarge
            && $discharge->discharge_coding_source_public_id === $source->public_id
            && $discharge->discharge_coding_source_version === $source->version;
        $summaryVersion = $discharge instanceof InpatientDischarge ? $discharge->summaryVersion : null;
        $sourceVersion = $discharge instanceof InpatientDischarge ? $discharge->codingSourceVersion : null;
        $dischargeCodingVersionPublicId = $discharge instanceof InpatientDischarge ? $discharge->discharge_coding_source_version_public_id : null;
        $dischargeCodingContentDigest = $discharge instanceof InpatientDischarge ? $discharge->discharge_coding_source_content_digest : null;
        $dischargeCodingProvenanceDigest = $discharge instanceof InpatientDischarge ? $discharge->discharge_coding_source_provenance_digest : null;
        $summaryComplete = $summaryComplete
            && $summaryVersion !== null
            && $summaryVersion->summary_state === InpatientDischargeSummary::STATE_FINAL
            && hash_equals($this->summaryDigests->content($summaryVersion), $discharge->discharge_summary_content_digest)
            && hash_equals($this->summaryDigests->provenance($summary, $summaryVersion), $discharge->discharge_summary_provenance_digest);
        $sourceComplete = $sourceComplete
            && $sourceVersion instanceof InpatientDischargeCodingSourceVersion
            && $sourceVersion->source_state === InpatientDischargeCodingSource::STATE_FINAL
            && hash_equals($this->sourceDigests->content($sourceVersion), $discharge->discharge_coding_source_content_digest)
            && hash_equals($this->sourceDigests->provenance($source, $sourceVersion), $discharge->discharge_coding_source_provenance_digest);
        $codingComplete = $codingVersion instanceof InpatientRmCodingVersion
            && $sourceVersion instanceof InpatientDischargeCodingSourceVersion
            && $this->codingCoverageComplete($codingVersion, $sourceVersion)
            && $codingVersion->source_version_public_id === $dischargeCodingVersionPublicId
            && $codingVersion->source_content_digest === $dischargeCodingContentDigest
            && $codingVersion->source_provenance_digest === $dischargeCodingProvenanceDigest;

        $items = [
            $this->item('IDENTITY_LINKED', 'Identitas pasien dan episode terhubung', $identityComplete, $encounter->patient?->public_id),
            $this->item('ROUTINE_DISCHARGE_RECORDED', 'Pemulangan rutin tercatat', $dischargeComplete, $discharge?->public_id),
            $this->item('FINAL_DISCHARGE_SUMMARY', 'Ringkasan pulang Final terikat tepat', $summaryComplete, $summary?->public_id),
            $this->item('FINAL_DISCHARGE_CODING_SOURCE', 'Sumber diagnosis/prosedur Final terikat tepat', $sourceComplete, $source?->public_id),
            $this->item('NO_INPATIENT_DRAFT_DOCUMENTS', 'Tidak ada dokumen rawat inap Draft tersimpan', $draftDocuments === [], null),
            $this->item('NO_ACTIVE_LAB_ORDERS', 'Tidak ada order laboratorium aktif', $allActiveLabs === [], null),
            $this->item('NO_UNRESOLVED_LAB_SPECIMENS', 'Tidak ada spesimen laboratorium yang belum terselesaikan', $laboratory['unresolved_specimen_order_public_ids'] === [], null),
            $this->item('NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS', 'Tidak ada hasil laboratorium terverifikasi yang belum diakui', $laboratory['stale_acknowledgement_order_public_ids'] === [], null),
            $this->item('NO_ACTIVE_RADIOLOGY_ORDERS', 'Tidak ada pesanan radiologi aktif', $radiology['active_order_public_ids'] === [], null),
            $this->item('NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS', 'Tidak ada laporan radiologi terverifikasi yang belum diakui', $radiology['stale_acknowledgement_order_public_ids'] === [], null),
            $this->item('NO_ACTIVE_MEDICATION_PRESCRIPTIONS', 'Tidak ada resep obat aktif', $pharmacy['active_prescription_public_ids'] === [], null),
            $this->item('MANUAL_CODING_COMPLETE', 'Seluruh pernyataan mendapat assignment kode manual', $codingComplete, $codingVersion?->public_id),
        ];
        $blockers = array_values(collect($items)->where('is_blocking', true)->where('is_complete', false)
            ->pluck('item_code')->map(static fn (mixed $code): string => (string) $code)->all());
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'definition_version' => InpatientRmCompletenessReview::DEFINITION_VERSION,
            'encounter_public_id' => $encounter->public_id,
            'patient_public_id' => $encounter->patient?->public_id,
            'discharge_public_id' => $discharge?->public_id,
            'discharge_summary_version_public_id' => $discharge?->discharge_summary_version_public_id,
            'discharge_summary_content_digest' => $discharge?->discharge_summary_content_digest,
            'discharge_coding_source_version_public_id' => $discharge?->discharge_coding_source_version_public_id,
            'discharge_coding_source_content_digest' => $discharge?->discharge_coding_source_content_digest,
            'discharge_coding_source_provenance_digest' => $discharge?->discharge_coding_source_provenance_digest,
            'draft_documents' => $draftDocuments,
            'active_lab_order_public_ids' => $allActiveLabs,
            'unresolved_lab_specimen_order_public_ids' => $laboratory['unresolved_specimen_order_public_ids'],
            'stale_lab_acknowledgement_order_public_ids' => $laboratory['stale_acknowledgement_order_public_ids'],
            'active_radiology_order_public_ids' => $radiology['active_order_public_ids'],
            'stale_radiology_acknowledgement_order_public_ids' => $radiology['stale_acknowledgement_order_public_ids'],
            'active_medication_prescription_public_ids' => $pharmacy['active_prescription_public_ids'],
            'coding_version' => $codingVersion instanceof InpatientRmCodingVersion ? $codingVersion->version : 0,
            'coding_state' => $codingVersion instanceof InpatientRmCodingVersion ? $codingVersion->coding_state : null,
            'coding_digest' => $codingVersion instanceof InpatientRmCodingVersion ? $codingVersion->content_digest : hash('sha256', ''),
            'coding_is_complete' => $codingVersion instanceof InpatientRmCodingVersion && $codingVersion->is_complete,
        ]));

        return [
            'source_fingerprint' => $fingerprint,
            'coding_version' => $codingVersion instanceof InpatientRmCodingVersion ? $codingVersion->version : 0,
            'coding_digest' => $codingVersion instanceof InpatientRmCodingVersion ? $codingVersion->content_digest : hash('sha256', ''),
            'source_version_public_id' => $discharge instanceof InpatientDischarge ? $discharge->discharge_coding_source_version_public_id : null,
            'source_content_digest' => $discharge instanceof InpatientDischarge ? $discharge->discharge_coding_source_content_digest : null,
            'source_provenance_digest' => $discharge instanceof InpatientDischarge ? $discharge->discharge_coding_source_provenance_digest : null,
            'items' => $items,
            'blockers' => $blockers,
        ];
    }

    /** @param array<array-key, mixed> $assignments */
    public function saveCodingDraft(
        string $encounterPublicId,
        User $actor,
        int $expectedVersion,
        string $sourceVersionPublicId,
        string $sourceContentDigest,
        string $sourceProvenanceDigest,
        array $assignments,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientRmResult {
        $this->authorize($actor, self::ACTION_CODING, $encounterPublicId, fn () => $this->actorPolicy->authorizeCoding($actor));
        $canonicalKey = null;
        $payloadDigest = null;
        try {
            $this->validateCommon($encounterPublicId, $expectedVersion, $idempotencyKey, $requestCorrelationId);
            $this->sha($sourceContentDigest, 'Digest konten sumber');
            $this->sha($sourceProvenanceDigest, 'Digest provenans sumber');
            if (! Str::isUlid($sourceVersionPublicId)) {
                throw new InpatientRmDenied('validation_failed', 'Versi sumber tidak valid.');
            }
            $canonicalKey = mb_strtolower($idempotencyKey);
            $payloadDigest = hash('sha256', CanonicalJson::encode([
                'encounter_public_id' => $encounterPublicId, 'expected_version' => $expectedVersion,
                'source_version_public_id' => $sourceVersionPublicId,
                'source_content_digest' => $sourceContentDigest,
                'source_provenance_digest' => $sourceProvenanceDigest,
                'assignments' => $assignments,
            ]));
            if ($replay = $this->replay($actor, InpatientRmOperationReceipt::OPERATION_CODING_SAVE, $canonicalKey, $payloadDigest)) {
                return $replay;
            }

            return DB::transaction(function () use ($encounterPublicId, $actor, $expectedVersion, $sourceVersionPublicId, $sourceContentDigest, $sourceProvenanceDigest, $assignments, $canonicalKey, $payloadDigest, $requestCorrelationId): InpatientRmResult {
                Encounter::query()->where('public_id', $encounterPublicId)->lockForUpdate()->first();
                if ($replay = $this->replay($actor, InpatientRmOperationReceipt::OPERATION_CODING_SAVE, $canonicalKey, $payloadDigest)) {
                    return $replay;
                }
                $encounter = $this->lockReadyEncounter($encounterPublicId);
                $sourceVersion = $this->lockedFinalSourceVersion($encounter);
                $discharge = $encounter->inpatientDischarge()->first();
                if (! $discharge instanceof InpatientDischarge
                    || $sourceVersion->public_id !== $sourceVersionPublicId
                    || ! hash_equals($discharge->discharge_coding_source_content_digest, $sourceContentDigest)
                    || ! hash_equals($discharge->discharge_coding_source_provenance_digest, $sourceProvenanceDigest)) {
                    throw new InpatientRmDenied('source_binding_stale', 'Versi atau digest sumber coding telah berubah.');
                }
                $source = $sourceVersion->source;
                if (! $source instanceof InpatientDischargeCodingSource) {
                    throw new InpatientRmDenied('source_binding_invalid', 'Sumber coding Final tidak dapat dimuat.', 503);
                }
                $canonicalAssignments = $this->normalizeAssignments($sourceVersion, $assignments);
                $codingDigest = hash('sha256', CanonicalJson::encode([
                    'definition_version' => InpatientRmCoding::DEFINITION_VERSION,
                    'profile' => InpatientRmCoding::PROFILE,
                    'source_version_public_id' => $sourceVersion->public_id,
                    'source_content_digest' => $sourceContentDigest,
                    'source_provenance_digest' => $sourceProvenanceDigest,
                    'assignments' => array_map(fn (array $row): array => collect($row)->except(['ordinal'])->all(), $canonicalAssignments),
                ]));
                $coding = InpatientRmCoding::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
                $currentVersion = $coding instanceof InpatientRmCoding ? $coding->version : 0;
                if ($currentVersion !== $expectedVersion) {
                    throw new InpatientRmDenied('stale_coding_version', 'Versi coding telah berubah.', metadata: ['current_version' => $currentVersion]);
                }
                if ($coding instanceof InpatientRmCoding && $coding->coding_state === InpatientRmCoding::STATE_FINAL) {
                    throw new InpatientRmDenied('coding_final', 'Coding Final tidak dapat dikoreksi tanpa alur addendum.');
                }
                $newVersion = $currentVersion + 1;
                InpatientRmMutationScope::run(function () use (&$coding, $encounter, $actor, $newVersion, $codingDigest): void {
                    if ($coding === null) {
                        $coding = InpatientRmCoding::query()->create([
                            'encounter_id' => $encounter->id, 'created_by_user_id' => $actor->id,
                            'definition_version' => InpatientRmCoding::DEFINITION_VERSION,
                            'profile' => InpatientRmCoding::PROFILE, 'version' => 1,
                            'coding_state' => InpatientRmCoding::STATE_DRAFT,
                            'current_is_complete' => true, 'current_content_digest' => $codingDigest,
                        ]);
                    } else {
                        $coding->version = $newVersion;
                        $coding->coding_state = InpatientRmCoding::STATE_DRAFT;
                        $coding->current_is_complete = true;
                        $coding->current_content_digest = $codingDigest;
                        $coding->save();
                    }
                });
                if (! $coding instanceof InpatientRmCoding) {
                    throw new InpatientRmDenied('coding_write_failed', 'Coding tidak dapat dimuat setelah penyimpanan.', 503);
                }
                $version = InpatientRmMutationScope::run(fn () => InpatientRmCodingVersion::query()->create([
                    'inpatient_rm_coding_id' => $coding->id,
                    'actor_user_id' => $actor->id,
                    'inpatient_discharge_coding_source_version_id' => $sourceVersion->id,
                    'version' => $newVersion, 'coding_state' => InpatientRmCoding::STATE_DRAFT,
                    'definition_version' => InpatientRmCoding::DEFINITION_VERSION,
                    'profile' => InpatientRmCoding::PROFILE, 'source_public_id' => $source->public_id,
                    'source_version' => $sourceVersion->version,
                    'source_version_public_id' => $sourceVersion->public_id,
                    'source_content_digest' => $sourceContentDigest,
                    'source_provenance_digest' => $sourceProvenanceDigest,
                    'is_complete' => true, 'content_digest' => $codingDigest,
                ]));
                InpatientRmMutationScope::run(fn () => $version->assignments()->createMany(array_map(
                    fn (array $assignment): array => [...$assignment, 'actor_user_id' => $actor->id],
                    $canonicalAssignments,
                )));
                $this->recordSuccess(self::ACTION_CODING, 'inpatient_rm_coding', $coding->public_id, $actor, [
                    'encounter_public_id' => $encounter->public_id,
                    'definition_version' => InpatientRmCoding::DEFINITION_VERSION,
                    'profile' => InpatientRmCoding::PROFILE,
                    'coding_version' => $newVersion,
                    'source_version_public_id' => $sourceVersion->public_id,
                    'source_content_digest' => $sourceContentDigest,
                    'coding_digest' => $codingDigest,
                    'assignment_count' => count($canonicalAssignments),
                ]);
                $this->receipt($encounter, $actor, $sourceVersion, $coding, $version, null, InpatientRmOperationReceipt::OPERATION_CODING_SAVE, $canonicalKey, $payloadDigest, 'CODING', $coding->public_id, $newVersion, $requestCorrelationId);

                return new InpatientRmResult($coding->fresh(), $version->load('assignments'), null, false);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replay($actor, InpatientRmOperationReceipt::OPERATION_CODING_SAVE, $canonicalKey, $payloadDigest)) {
                return $replay;
            }
            $denial = new InpatientRmDenied('concurrent_change', 'Coding berubah bersamaan. Muat ulang.');
            $this->recordDenial(self::ACTION_CODING, $encounterPublicId, $actor, $denial);
            throw $denial;
        } catch (InpatientRmDenied $denial) {
            if ($denial->reason !== 'idempotency_key_conflict'
                && ($replay = $this->replay($actor, InpatientRmOperationReceipt::OPERATION_CODING_SAVE, $canonicalKey, $payloadDigest))) {
                return $replay;
            }
            $this->recordDenial(self::ACTION_CODING, $encounterPublicId, $actor, $denial);
            throw $denial;
        }
    }

    public function saveReview(
        string $encounterPublicId,
        User $actor,
        int $expectedReviewVersion,
        string $sourceFingerprint,
        int $codingVersion,
        string $codingDigest,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientRmResult {
        return $this->reviewMutation(false, $encounterPublicId, $actor, $expectedReviewVersion, $sourceFingerprint, $codingVersion, $codingDigest, $idempotencyKey, $requestCorrelationId);
    }

    public function signoff(
        string $encounterPublicId,
        User $actor,
        int $expectedReviewVersion,
        string $sourceFingerprint,
        int $codingVersion,
        string $codingDigest,
        string $idempotencyKey,
        ?string $requestCorrelationId = null,
    ): InpatientRmResult {
        return $this->reviewMutation(true, $encounterPublicId, $actor, $expectedReviewVersion, $sourceFingerprint, $codingVersion, $codingDigest, $idempotencyKey, $requestCorrelationId);
    }

    private function reviewMutation(bool $signoff, string $encounterPublicId, User $actor, int $expectedReviewVersion, string $sourceFingerprint, int $codingVersion, string $codingDigest, string $idempotencyKey, ?string $requestCorrelationId): InpatientRmResult
    {
        $action = $signoff ? self::ACTION_SIGNOFF : self::ACTION_REVIEW;
        $operation = $signoff ? InpatientRmOperationReceipt::OPERATION_SIGNOFF : InpatientRmOperationReceipt::OPERATION_REVIEW_SAVE;
        $this->authorize($actor, $action, $encounterPublicId, fn () => $signoff ? $this->actorPolicy->authorizeSignoff($actor) : $this->actorPolicy->authorizeReview($actor));
        $canonicalKey = null;
        $payloadDigest = null;
        try {
            $this->validateCommon($encounterPublicId, $expectedReviewVersion, $idempotencyKey, $requestCorrelationId);
            $this->sha($sourceFingerprint, 'Sidik sumber');
            $this->sha($codingDigest, 'Digest coding');
            if ($codingVersion < 1) {
                throw new InpatientRmDenied('validation_failed', 'Versi coding harus positif.');
            }
            $canonicalKey = mb_strtolower($idempotencyKey);
            $payloadDigest = hash('sha256', CanonicalJson::encode([
                'operation' => $operation, 'encounter_public_id' => $encounterPublicId,
                'expected_review_version' => $expectedReviewVersion, 'source_fingerprint' => $sourceFingerprint,
                'coding_version' => $codingVersion, 'coding_digest' => $codingDigest,
            ]));
            if ($replay = $this->replay($actor, $operation, $canonicalKey, $payloadDigest)) {
                return $replay;
            }

            return DB::transaction(function () use ($signoff, $action, $operation, $encounterPublicId, $actor, $expectedReviewVersion, $sourceFingerprint, $codingVersion, $codingDigest, $canonicalKey, $payloadDigest, $requestCorrelationId): InpatientRmResult {
                if ($signoff) {
                    $candidate = Encounter::query()->where('public_id', $encounterPublicId)->first();
                    if ($candidate instanceof Encounter) {
                        $this->pharmacy->lockInventoryForEncounter((int) $candidate->id);
                    }
                }
                Encounter::query()->where('public_id', $encounterPublicId)->lockForUpdate()->first();
                if ($replay = $this->replay($actor, $operation, $canonicalKey, $payloadDigest)) {
                    return $replay;
                }
                $encounter = $this->lockReadyEncounter($encounterPublicId);
                if ($signoff && $this->pharmacy->inspect($encounter)['active_prescription_public_ids'] !== []) {
                    throw new InpatientRmDenied(
                        'active_pharmacy_prescriptions',
                        'Episode belum dapat ditutup karena masih ada resep obat aktif.',
                        metadata: ['failed_item_ids' => ['NO_ACTIVE_MEDICATION_PRESCRIPTIONS']],
                    );
                }
                $latest = InpatientRmCompletenessReview::query()->where('encounter_id', $encounter->id)->orderByDesc('version')->first();
                $currentVersion = $latest instanceof InpatientRmCompletenessReview ? $latest->version : 0;
                if ($currentVersion !== $expectedReviewVersion) {
                    throw new InpatientRmDenied('stale_review_version', 'Versi review telah berubah.', metadata: ['current_version' => $currentVersion]);
                }
                $sourceVersion = $this->lockedFinalSourceVersion($encounter);
                $snapshot = $this->snapshot($encounter->fresh());
                if (! hash_equals($snapshot['source_fingerprint'], $sourceFingerprint)
                    || $snapshot['coding_version'] !== $codingVersion
                    || ! hash_equals($snapshot['coding_digest'], $codingDigest)) {
                    throw new InpatientRmDenied('source_binding_stale', 'Sumber review atau coding telah berubah.');
                }
                $draftCodingVersion = $this->codingVersion($encounter, $codingVersion, $codingDigest);
                if ($draftCodingVersion->coding_state !== InpatientRmCoding::STATE_DRAFT) {
                    throw new InpatientRmDenied('coding_final', 'Review baru hanya dapat dibuat terhadap coding Draft current.');
                }
                if ($signoff) {
                    if (! $latest instanceof InpatientRmCompletenessReview
                        || $latest->review_state !== InpatientRmCompletenessReview::STATE_DRAFT
                        || ! hash_equals($latest->source_fingerprint, $sourceFingerprint)
                        || $latest->inpatient_rm_coding_version_id !== $draftCodingVersion->id
                        || $latest->coding_version !== $draftCodingVersion->version
                        || ! hash_equals($latest->coding_digest, $draftCodingVersion->content_digest)
                        || $latest->blocker_count !== 0) {
                        throw new InpatientRmDenied('review_not_current_complete', 'Review nol-blocker yang current wajib tersedia sebelum signoff.');
                    }
                    if ($snapshot['blockers'] !== []) {
                        throw new InpatientRmDenied('checklist_incomplete', 'Episode masih memiliki blocker.', metadata: ['failed_item_ids' => $snapshot['blockers']]);
                    }
                }
                $boundCodingVersion = $draftCodingVersion;
                if ($signoff) {
                    $coding = InpatientRmCoding::query()->whereKey($draftCodingVersion->inpatient_rm_coding_id)->lockForUpdate()->firstOrFail();
                    if ($coding->coding_state !== InpatientRmCoding::STATE_DRAFT || $coding->version !== $draftCodingVersion->version) {
                        throw new InpatientRmDenied('coding_not_current_complete', 'Coding Draft berubah sebelum signoff.');
                    }
                    $finalVersionNumber = $draftCodingVersion->version + 1;
                    $boundCodingVersion = InpatientRmMutationScope::run(fn () => InpatientRmCodingVersion::query()->create([
                        'inpatient_rm_coding_id' => $coding->id,
                        'actor_user_id' => $actor->id,
                        'inpatient_discharge_coding_source_version_id' => $sourceVersion->id,
                        'version' => $finalVersionNumber,
                        'coding_state' => InpatientRmCoding::STATE_FINAL,
                        'definition_version' => $draftCodingVersion->definition_version,
                        'profile' => $draftCodingVersion->profile,
                        'source_public_id' => $draftCodingVersion->source_public_id,
                        'source_version' => $draftCodingVersion->source_version,
                        'source_version_public_id' => $draftCodingVersion->source_version_public_id,
                        'source_content_digest' => $draftCodingVersion->source_content_digest,
                        'source_provenance_digest' => $draftCodingVersion->source_provenance_digest,
                        'is_complete' => true,
                        'content_digest' => $draftCodingVersion->content_digest,
                    ]));
                    $draftAssignments = $draftCodingVersion->assignments()->orderBy('ordinal')->get();
                    InpatientRmMutationScope::run(fn () => $boundCodingVersion->assignments()->createMany($draftAssignments->map(fn (InpatientRmCodingAssignment $assignment): array => [
                        'actor_user_id' => $actor->id,
                        'source_statement_kind' => $assignment->source_statement_kind,
                        'source_statement_index' => $assignment->source_statement_index,
                        'source_statement_text_hash' => $assignment->source_statement_text_hash,
                        'source_statement_reference' => $assignment->source_statement_reference,
                        'code_system' => $assignment->code_system,
                        'profile' => $assignment->profile,
                        'normalized_code' => $assignment->normalized_code,
                        'display' => $assignment->display,
                        'ordinal' => $assignment->ordinal,
                        'is_no_procedure_attestation' => $assignment->is_no_procedure_attestation,
                    ])->all()));
                    InpatientRmMutationScope::run(function () use ($coding, $finalVersionNumber): void {
                        $coding->version = $finalVersionNumber;
                        $coding->coding_state = InpatientRmCoding::STATE_FINAL;
                        $coding->current_is_complete = true;
                        $coding->save();
                    });
                    if (! $this->codingCoverageComplete($boundCodingVersion->load('assignments'), $sourceVersion)) {
                        throw new InpatientRmDenied('coding_binding_invalid', 'Salinan coding Final kehilangan coverage.', 503);
                    }
                    $snapshot = $this->snapshot($encounter->fresh());
                    if ($snapshot['blockers'] !== []
                        || $snapshot['coding_version'] !== $boundCodingVersion->version
                        || ! hash_equals($snapshot['coding_digest'], $boundCodingVersion->content_digest)) {
                        throw new InpatientRmDenied('final_snapshot_invalid', 'Snapshot setelah finalisasi coding tidak valid.', 503);
                    }
                }
                $newVersion = $currentVersion + 1;
                $state = $signoff ? InpatientRmCompletenessReview::STATE_SIGNED_OFF : InpatientRmCompletenessReview::STATE_DRAFT;
                $review = InpatientRmMutationScope::run(fn () => InpatientRmCompletenessReview::query()->create([
                    'encounter_id' => $encounter->id,
                    'inpatient_rm_coding_version_id' => $boundCodingVersion->id,
                    'reviewed_by_user_id' => $actor->id,
                    'signed_off_by_user_id' => $signoff ? $actor->id : null,
                    'definition_version' => InpatientRmCompletenessReview::DEFINITION_VERSION,
                    'version' => $newVersion, 'source_fingerprint' => $snapshot['source_fingerprint'],
                    'coding_version' => $boundCodingVersion->version,
                    'coding_digest' => $boundCodingVersion->content_digest,
                    'source_version_public_id' => $snapshot['source_version_public_id'],
                    'source_content_digest' => $snapshot['source_content_digest'],
                    'source_provenance_digest' => $snapshot['source_provenance_digest'],
                    'review_state' => $state, 'blocker_count' => count($snapshot['blockers']),
                    'reviewed_at' => now(), 'signed_off_at' => $signoff ? now() : null,
                ]));
                InpatientRmMutationScope::run(fn () => $review->items()->createMany($snapshot['items']));
                if ($signoff) {
                    $encounter->status = Encounter::STATUS_CLOSED;
                    $encounter->save();
                }
                $this->recordSuccess($action, 'inpatient_rm_completeness_review', $review->public_id, $actor, [
                    'encounter_public_id' => $encounter->public_id,
                    'definition_version' => InpatientRmCompletenessReview::DEFINITION_VERSION,
                    'review_version' => $newVersion,
                    'source_fingerprint' => $snapshot['source_fingerprint'],
                    'coding_version' => $boundCodingVersion->version,
                    'coding_digest' => $boundCodingVersion->content_digest,
                    'failed_item_ids' => $snapshot['blockers'],
                    'encounter_status_after' => $encounter->status,
                ]);
                $coding = InpatientRmCoding::query()->whereKey($boundCodingVersion->inpatient_rm_coding_id)->firstOrFail();
                $this->receipt($encounter, $actor, $sourceVersion, $coding, $boundCodingVersion, $review, $operation, $canonicalKey, $payloadDigest, 'REVIEW', $review->public_id, $newVersion, $requestCorrelationId);

                return new InpatientRmResult($encounter->inpatientRmCoding()->first(), $boundCodingVersion->load('assignments'), $review->load('items'), false);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replay($actor, $operation, $canonicalKey, $payloadDigest)) {
                return $replay;
            }
            $denial = new InpatientRmDenied('concurrent_change', 'Review berubah bersamaan. Muat ulang.');
            $this->recordDenial($action, $encounterPublicId, $actor, $denial);
            throw $denial;
        } catch (InpatientRmDenied $denial) {
            if ($denial->reason !== 'idempotency_key_conflict'
                && ($replay = $this->replay($actor, $operation, $canonicalKey, $payloadDigest))) {
                return $replay;
            }
            $this->recordDenial($action, $encounterPublicId, $actor, $denial);
            throw $denial;
        }
    }

    /**
     * @param  array<array-key, mixed>  $submitted
     * @return list<array<string, mixed>>
     */
    private function normalizeAssignments(InpatientDischargeCodingSourceVersion $source, array $submitted): array
    {
        if (! array_is_list($submitted)) {
            throw new InpatientRmDenied('assignment_coverage_invalid', 'Assignment coding harus berupa daftar.');
        }
        $expected = $this->sourceStatements($source);
        $expectedAuthored = array_filter($expected, fn (array $row): bool => ! $row['is_no_procedure_attestation']);
        if (count($submitted) !== count($expectedAuthored)) {
            throw new InpatientRmDenied('assignment_coverage_invalid', 'Setiap pernyataan sumber wajib memiliki tepat satu assignment.');
        }
        $normalized = [];
        foreach ($submitted as $ordinal => $assignment) {
            if (! is_array($assignment)) {
                throw new InpatientRmDenied('assignment_coverage_invalid', 'Assignment coding tidak valid.');
            }
            $allowed = ['source_statement_kind', 'source_statement_index', 'source_statement_text_hash', 'code', 'description'];
            if (array_diff(array_keys($assignment), $allowed) !== []
                || array_diff(['source_statement_kind', 'source_statement_index', 'source_statement_text_hash', 'code', 'description'], array_keys($assignment)) !== []) {
                throw new InpatientRmDenied('assignment_coverage_invalid', 'Field assignment coding harus tepat sesuai kontrak.');
            }
            $kind = $assignment['source_statement_kind'];
            $index = $assignment['source_statement_index'];
            if (! is_string($kind) || ! is_int($index)) {
                throw new InpatientRmDenied('assignment_coverage_invalid', 'Binding kind/index assignment tidak valid.');
            }
            $key = $kind.':'.$index;
            $row = $expectedAuthored[$key] ?? null;
            if ($row === null || isset($normalized[$key])) {
                throw new InpatientRmDenied('assignment_coverage_invalid', 'Assignment tidak boleh unknown, orphan, atau duplikat.');
            }
            if (! is_string($assignment['source_statement_text_hash'])
                || ! hash_equals($row['source_statement_text_hash'], $assignment['source_statement_text_hash'])) {
                throw new InpatientRmDenied('assignment_binding_invalid', 'Hash pernyataan assignment tidak cocok dengan sumber Final.');
            }
            $display = $this->text($assignment['description'], 'Deskripsi kode', 1, 255);
            $code = mb_strtoupper($this->text($assignment['code'], 'Kode manual', 1, 64));
            if (preg_match('/\A[A-Z0-9][A-Z0-9.+\-]*\z/', $code) !== 1) {
                throw new InpatientRmDenied('assignment_coverage_invalid', 'Format kode manual tidak valid.');
            }
            $normalized[$key] = [
                'source_statement_kind' => $kind,
                'source_statement_index' => $index,
                'source_statement_text_hash' => $row['source_statement_text_hash'],
                'source_statement_reference' => $row['source_statement_reference'],
                'code_system' => $row['code_system'], 'profile' => InpatientRmCoding::PROFILE,
                'normalized_code' => $code, 'display' => $display, 'ordinal' => $ordinal + 1,
                'is_no_procedure_attestation' => false,
            ];
        }
        if (array_diff(array_keys($expectedAuthored), array_keys($normalized)) !== []) {
            throw new InpatientRmDenied('assignment_coverage_invalid', 'Assignment sumber belum lengkap.');
        }

        foreach ($expected as $key => $row) {
            if (! $row['is_no_procedure_attestation']) {
                continue;
            }
            $normalized[$key] = [
                ...$row,
                'normalized_code' => null,
                'display' => 'Tidak ada prosedur yang dicatat pada sumber Final',
                'ordinal' => count($normalized) + 1,
            ];
        }

        return array_values($normalized);
    }

    /** @return array<string,array<string,mixed>> */
    private function sourceStatements(InpatientDischargeCodingSourceVersion $source): array
    {
        $rows = [];
        $add = function (string $kind, int $index, string $reference, string $system, bool $noProcedure) use (&$rows): void {
            $rows[$kind.':'.$index] = [
                'source_statement_kind' => $kind, 'source_statement_index' => $index,
                'source_statement_text_hash' => hash('sha256', $reference),
                'source_statement_reference' => $reference, 'code_system' => $system,
                'profile' => InpatientRmCoding::PROFILE,
                'is_no_procedure_attestation' => $noProcedure,
            ];
        };
        $add(InpatientRmCodingAssignment::KIND_PRINCIPAL, 0, (string) $source->principal_diagnosis_statement, InpatientRmCoding::DIAGNOSIS_CODE_SYSTEM, false);
        foreach ($source->secondary_diagnosis_statements as $index => $statement) {
            $add(InpatientRmCodingAssignment::KIND_SECONDARY, $index, $statement, InpatientRmCoding::DIAGNOSIS_CODE_SYSTEM, false);
        }
        if ($source->procedure_attestation === InpatientDischargeCodingSource::ATTESTATION_NONE) {
            $add(InpatientRmCodingAssignment::KIND_NO_PROCEDURE, 0, InpatientDischargeCodingSource::ATTESTATION_NONE, InpatientRmCoding::PROCEDURE_CODE_SYSTEM, true);
        } else {
            foreach ($source->performed_procedure_statements as $index => $statement) {
                $add(InpatientRmCodingAssignment::KIND_PROCEDURE, $index, $statement, InpatientRmCoding::PROCEDURE_CODE_SYSTEM, false);
            }
        }

        return $rows;
    }

    private function lockedFinalSourceVersion(Encounter $encounter): InpatientDischargeCodingSourceVersion
    {
        $discharge = $encounter->inpatientDischarge()->first();
        if (! $discharge instanceof InpatientDischarge) {
            throw new InpatientRmDenied('routine_discharge_missing', 'Pemulangan rutin belum tersedia.');
        }
        $version = InpatientDischargeCodingSourceVersion::query()
            ->with('source')->whereKey($discharge->inpatient_discharge_coding_source_version_id)
            ->first();
        if (! $version instanceof InpatientDischargeCodingSourceVersion
            || ! $version->source instanceof InpatientDischargeCodingSource
            || $version->source_state !== InpatientDischargeCodingSource::STATE_FINAL
            || $version->source->source_state !== InpatientDischargeCodingSource::STATE_FINAL
            || $version->public_id !== $discharge->discharge_coding_source_version_public_id
            || ! hash_equals($this->sourceDigests->content($version), $discharge->discharge_coding_source_content_digest)
            || ! hash_equals($this->sourceDigests->provenance($version->source, $version), $discharge->discharge_coding_source_provenance_digest)) {
            throw new InpatientRmDenied('source_binding_invalid', 'Sumber coding Final tidak valid atau digest tidak cocok.', 503);
        }

        return $version;
    }

    private function codingVersion(Encounter $encounter, int $version, string $digest): InpatientRmCodingVersion
    {
        $coding = InpatientRmCoding::query()->where('encounter_id', $encounter->id)->lockForUpdate()->first();
        if (! $coding instanceof InpatientRmCoding || $coding->version !== $version || ! $coding->current_is_complete || ! hash_equals($coding->current_content_digest, $digest)) {
            throw new InpatientRmDenied('coding_not_current_complete', 'Coding current dan lengkap wajib tersedia.');
        }
        $result = InpatientRmCodingVersion::query()->where('inpatient_rm_coding_id', $coding->id)->where('version', $version)->first();
        $source = $result?->sourceVersion;
        if (! $result instanceof InpatientRmCodingVersion
            || ! $source instanceof InpatientDischargeCodingSourceVersion
            || ! hash_equals($result->content_digest, $digest)
            || ! $this->codingCoverageComplete($result, $source)) {
            throw new InpatientRmDenied('coding_binding_invalid', 'Versi coding tidak valid.', 503);
        }

        return $result;
    }

    private function lockReadyEncounter(string $publicId): Encounter
    {
        $encounter = Encounter::query()->where('public_id', $publicId)->lockForUpdate()->first();
        if (! $encounter instanceof Encounter) {
            throw new InpatientRmDenied('encounter_missing', 'Episode tidak ditemukan.', 404);
        }
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            throw new InpatientRmDenied('not_inpatient', 'Episode bukan rawat inap.', 404);
        }
        if (! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new InpatientRmDenied('synthetic_only', 'Episode berada di luar batas sintetis.', 403);
        }
        if ($encounter->status !== Encounter::STATUS_READY_FOR_RM) {
            throw new InpatientRmDenied($encounter->status === Encounter::STATUS_CLOSED ? 'encounter_closed' : 'encounter_not_ready', 'Episode belum siap untuk RMIK.');
        }

        return $encounter;
    }

    private function replay(User $actor, string $operation, string $key, string $digest): ?InpatientRmResult
    {
        $receipt = InpatientRmOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if (! $receipt instanceof InpatientRmOperationReceipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $digest)) {
            throw new InpatientRmDenied('idempotency_key_conflict', 'Kunci idempotensi telah dipakai untuk payload berbeda.');
        }
        if ($receipt->result_type === 'CODING') {
            $coding = InpatientRmCoding::query()->where('public_id', $receipt->result_public_id)->first();
            $version = $coding?->versions()->where('version', $receipt->result_version)->first();
            if (! $coding instanceof InpatientRmCoding || ! $version instanceof InpatientRmCodingVersion
                || $receipt->inpatient_rm_coding_version_id !== $version->id
                || $receipt->inpatient_discharge_coding_source_version_id !== $version->inpatient_discharge_coding_source_version_id
                || $receipt->coding_public_id !== $coding->public_id
                || $receipt->coding_version !== $version->version
                || ! hash_equals($receipt->coding_digest, $version->content_digest)
                || $receipt->source_version_public_id !== $version->source_version_public_id
                || ! hash_equals($receipt->source_content_digest, $version->source_content_digest)
                || ! hash_equals($receipt->source_provenance_digest, $version->source_provenance_digest)) {
                throw new InpatientRmDenied('receipt_binding_invalid', 'Receipt coding tidak lagi terikat pada hasilnya.', 503);
            }

            return new InpatientRmResult($coding, $version->load('assignments'), null, true);
        }
        $review = InpatientRmCompletenessReview::query()->where('public_id', $receipt->result_public_id)->where('version', $receipt->result_version)->first();
        if (! $review instanceof InpatientRmCompletenessReview
            || $receipt->inpatient_rm_completeness_review_id !== $review->id
            || $receipt->inpatient_rm_coding_version_id !== $review->inpatient_rm_coding_version_id
            || $receipt->coding_version !== $review->coding_version
            || ! hash_equals($receipt->coding_digest, $review->coding_digest)
            || $receipt->source_version_public_id !== $review->source_version_public_id
            || ! hash_equals($receipt->source_content_digest, $review->source_content_digest)
            || ! hash_equals($receipt->source_provenance_digest, $review->source_provenance_digest)) {
            throw new InpatientRmDenied('receipt_binding_invalid', 'Receipt review tidak lagi terikat pada hasilnya.', 503);
        }

        return new InpatientRmResult($review->encounter?->inpatientRmCoding, $review->codingVersion?->load('assignments'), $review->load('items'), true);
    }

    private function receipt(
        Encounter $encounter,
        User $actor,
        InpatientDischargeCodingSourceVersion $sourceVersion,
        InpatientRmCoding $coding,
        InpatientRmCodingVersion $codingVersion,
        ?InpatientRmCompletenessReview $review,
        string $operation,
        string $key,
        string $digest,
        string $type,
        string $publicId,
        int $version,
        ?string $correlation,
    ): void {
        InpatientRmMutationScope::run(fn () => InpatientRmOperationReceipt::query()->create([
            'encounter_id' => $encounter->id, 'actor_user_id' => $actor->id,
            'inpatient_discharge_coding_source_version_id' => $sourceVersion->id,
            'inpatient_rm_coding_version_id' => $codingVersion->id,
            'inpatient_rm_completeness_review_id' => $review?->id,
            'operation' => $operation, 'idempotency_key' => $key, 'payload_digest' => $digest,
            'source_version_public_id' => $sourceVersion->public_id,
            'source_content_digest' => $codingVersion->source_content_digest,
            'source_provenance_digest' => $codingVersion->source_provenance_digest,
            'coding_public_id' => $coding->public_id,
            'coding_version' => $codingVersion->version,
            'coding_digest' => $codingVersion->content_digest,
            'result_type' => $type, 'result_public_id' => $publicId, 'result_version' => $version,
            'request_correlation_id' => $correlation, 'completed_at' => now(),
        ]));
    }

    private function codingCoverageComplete(InpatientRmCodingVersion $version, InpatientDischargeCodingSourceVersion $source): bool
    {
        $expected = $this->sourceStatements($source);
        $assignments = $version->relationLoaded('assignments')
            ? $version->assignments
            : $version->assignments()->orderBy('ordinal')->get();
        $covered = [];
        $digestRows = [];
        foreach ($assignments as $assignment) {
            $key = $assignment->source_statement_kind.':'.$assignment->source_statement_index;
            $sourceRow = $expected[$key] ?? null;
            if ($sourceRow === null
                || ! hash_equals($sourceRow['source_statement_text_hash'], $assignment->source_statement_text_hash)
                || $sourceRow['source_statement_reference'] !== $assignment->source_statement_reference
                || $sourceRow['code_system'] !== $assignment->code_system
                || $assignment->profile !== InpatientRmCoding::PROFILE
                || (bool) $assignment->is_no_procedure_attestation !== $sourceRow['is_no_procedure_attestation']) {
                return false;
            }
            if ($sourceRow['is_no_procedure_attestation']) {
                if ($assignment->normalized_code !== null) {
                    return false;
                }
            } elseif (! is_string($assignment->normalized_code) || $assignment->normalized_code === '') {
                return false;
            }
            $covered[$key] = true;
            $digestRows[] = [
                'source_statement_kind' => $assignment->source_statement_kind,
                'source_statement_index' => $assignment->source_statement_index,
                'source_statement_text_hash' => $assignment->source_statement_text_hash,
                'source_statement_reference' => $assignment->source_statement_reference,
                'code_system' => $assignment->code_system,
                'profile' => $assignment->profile,
                'normalized_code' => $assignment->normalized_code,
                'display' => $assignment->display,
                'is_no_procedure_attestation' => (bool) $assignment->is_no_procedure_attestation,
            ];
        }
        if (array_diff(array_keys($expected), array_keys($covered)) !== []) {
            return false;
        }
        $recomputed = hash('sha256', CanonicalJson::encode([
            'definition_version' => InpatientRmCoding::DEFINITION_VERSION,
            'profile' => InpatientRmCoding::PROFILE,
            'source_version_public_id' => $version->source_version_public_id,
            'source_content_digest' => $version->source_content_digest,
            'source_provenance_digest' => $version->source_provenance_digest,
            'assignments' => $digestRows,
        ]));

        return hash_equals($version->content_digest, $recomputed);
    }

    /** @return array{item_code:string,label:string,is_blocking:bool,is_complete:bool,source_reference:string|null} */
    private function item(string $code, string $label, bool $complete, ?string $reference): array
    {
        return ['item_code' => $code, 'label' => $label, 'is_blocking' => true, 'is_complete' => $complete, 'source_reference' => $reference];
    }

    private function authorize(User $actor, string $action, string $encounterPublicId, callable $authorize): void
    {
        try {
            $authorize();
        } catch (AuthorizationException $exception) {
            $denial = new InpatientRmDenied('unauthorized_actor', 'Aksi hanya tersedia untuk peran RMIK dengan kapabilitas tepat.', 403);
            $this->recordDenial($action, $encounterPublicId, $actor, $denial);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $metadata */
    private function recordSuccess(string $action, string $resourceType, string $resourceId, User $actor, array $metadata): void
    {
        if ($this->audit->record(action: $action, resourceType: $resourceType, resourceId: $resourceId, actor: $actor, metadata: $metadata) === null) {
            throw new InpatientRmAuditUnavailable('Inpatient RMIK mutation rolled back because required audit was unavailable.');
        }
    }

    private function recordDenial(string $action, string $encounterPublicId, User $actor, InpatientRmDenied $denial): void
    {
        if ($this->audit->record(action: $action, resourceType: 'encounter', resourceId: $encounterPublicId, actor: $actor, outcome: 'DENIED', reason: $denial->reason, metadata: $denial->metadata) === null) {
            throw new InpatientRmAuditUnavailable('Inpatient RMIK denial audit was unavailable.');
        }
    }

    private function validateCommon(string $encounterPublicId, int $expectedVersion, string $key, ?string $correlation): void
    {
        if (! Str::isUlid($encounterPublicId)) {
            throw new InpatientRmDenied('validation_failed', 'Identitas episode tidak valid.');
        }
        if ($expectedVersion < 0) {
            throw new InpatientRmDenied('validation_failed', 'Versi expected tidak boleh negatif.');
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InpatientRmDenied('validation_failed', 'Kunci idempotensi harus 8-255 karakter aman.');
        }
        if ($correlation !== null && ! Str::isUlid($correlation)) {
            throw new InpatientRmDenied('validation_failed', 'Identitas korelasi tidak valid.');
        }
    }

    private function sha(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InpatientRmDenied('validation_failed', $label.' harus digest SHA-256 lowercase.');
        }
    }

    private function text(mixed $value, string $label, int $minimum, int $maximum): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InpatientRmDenied('assignment_coverage_invalid', $label.' harus teks UTF-8.');
        }
        $value = trim($value);
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum) {
            throw new InpatientRmDenied('assignment_coverage_invalid', $label." harus {$minimum}-{$maximum} karakter.");
        }

        return $value;
    }
}
