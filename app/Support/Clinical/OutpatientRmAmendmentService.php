<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\OutpatientAmendmentOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\OutpatientRmAmendmentReview;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OutpatientRmAmendmentService
{
    public const OPERATION_REVIEW_SAVE = 'RMIK_REVIEW_SAVE';

    public const OPERATION_REVIEW_SIGNOFF = 'RMIK_REVIEW_SIGNOFF';

    public const FINGERPRINT_PROFILE = 'outpatient-amendment-source-fingerprint-v1';

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly OutpatientAmendmentActorPolicy $actorPolicy,
    ) {}

    /**
     * @return array{
     *   source_fingerprint: string,
     *   items: list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>,
     *   blockers: list<string>,
     *   baseline_review: OutpatientRmCompletenessReview,
     *   addendum: OutpatientClinicalDocumentAddendum
     * }
     */
    public function snapshot(OutpatientPostClosureAmendmentRequest $amendmentRequest): array
    {
        $encounter = $amendmentRequest->encounter()->firstOrFail();
        $this->assertClosedSyntheticOutpatient($encounter);
        $request = OutpatientPostClosureAmendmentRequest::query()->whereKey($amendmentRequest->id)->firstOrFail();
        [$addendum, $baseline] = $this->assertReviewSources($encounter, $request);

        return $this->buildSnapshot($encounter, $request, $addendum, $baseline, lock: false);
    }

    public function saveReview(
        OutpatientPostClosureAmendmentRequest $amendmentRequest,
        User $actor,
        int $expectedVersion,
        string $expectedFingerprint,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): OutpatientRmAmendmentReviewResult {
        $this->actorPolicy->authorizeRmik($actor, Capability::RMIK_REVIEW);
        $this->validateInput($expectedVersion, $expectedFingerprint, $idempotencyKey, $requestCorrelationId);
        $digest = $this->digest([
            'amendment_request_public_id' => $amendmentRequest->public_id,
            'expected_source_fingerprint' => $expectedFingerprint,
            'expected_version' => $expectedVersion,
        ]);
        $encounter = $amendmentRequest->encounter()->firstOrFail();

        try {
            return DB::transaction(function () use (
                $amendmentRequest,
                $actor,
                $expectedVersion,
                $expectedFingerprint,
                $idempotencyKey,
                $requestCorrelationId,
                $digest,
                $encounter,
            ): OutpatientRmAmendmentReviewResult {
                $lockedEncounter = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
                $this->assertClosedSyntheticOutpatient($lockedEncounter);
                $terminalReview = OutpatientRmAmendmentReview::query()
                    ->where('amendment_request_id', $amendmentRequest->id)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();
                if ($terminalReview instanceof OutpatientRmAmendmentReview
                    && $terminalReview->review_state === OutpatientRmAmendmentReview::STATE_SIGNED_OFF) {
                    throw new OutpatientAmendmentDenied('stale_version', 'Review addendum sudah ditandatangani dan tidak dapat diubah.');
                }
                if ($replay = $this->replayOrConflict($actor, self::OPERATION_REVIEW_SAVE, $idempotencyKey, $digest)) {
                    return $replay;
                }

                $lockedRequest = OutpatientPostClosureAmendmentRequest::query()
                    ->whereKey($amendmentRequest->id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                [$addendum, $baseline] = $this->assertReviewSources($lockedEncounter, $lockedRequest, lock: true);
                $latest = $this->latestReview($lockedRequest, lock: true);
                $currentVersion = $latest instanceof OutpatientRmAmendmentReview ? $latest->version : 0;
                if ($currentVersion !== $expectedVersion) {
                    throw new OutpatientAmendmentDenied('stale_version', 'Review addendum telah berubah. Muat ulang sebelum melanjutkan.');
                }

                $snapshot = $this->buildSnapshot($lockedEncounter, $lockedRequest, $addendum, $baseline, lock: true);
                if (! hash_equals($snapshot['source_fingerprint'], $expectedFingerprint)) {
                    throw new OutpatientAmendmentDenied('source_stale', 'Sumber review addendum telah berubah. Muat ulang sebelum melanjutkan.');
                }

                $review = $this->createReview(
                    $lockedEncounter,
                    $lockedRequest,
                    $addendum,
                    $baseline,
                    $actor,
                    $currentVersion + 1,
                    OutpatientRmAmendmentReview::STATE_DRAFT,
                    $snapshot,
                );
                $this->recordSuccess('rmik.outpatient.amendment.review.save', $lockedEncounter, $lockedRequest, $review, $actor, $snapshot['blockers']);
                $this->recordReceipt($lockedEncounter, $actor, self::OPERATION_REVIEW_SAVE, $idempotencyKey, $digest, $review, $requestCorrelationId);

                return new OutpatientRmAmendmentReviewResult($review, replayed: false);
            }, 3);
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileReceiptAfterRace($actor, self::OPERATION_REVIEW_SAVE, $idempotencyKey, $digest, $race);
            } catch (OutpatientAmendmentDenied $denial) {
                $this->recordDenialOrFail('rmik.outpatient.amendment.review.save', $encounter, $amendmentRequest, $actor, $denial);
            }
        } catch (OutpatientAmendmentDenied $denial) {
            $this->recordDenialOrFail('rmik.outpatient.amendment.review.save', $encounter, $amendmentRequest, $actor, $denial);
        }
    }

    public function signoff(
        OutpatientPostClosureAmendmentRequest $amendmentRequest,
        User $actor,
        int $expectedVersion,
        string $expectedFingerprint,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): OutpatientRmAmendmentReviewResult {
        $this->actorPolicy->authorizeRmik($actor, Capability::RMIK_COMPLETENESS_SIGNOFF);
        $this->validateInput($expectedVersion, $expectedFingerprint, $idempotencyKey, $requestCorrelationId, versionMayBeZero: false);
        $digest = $this->digest([
            'amendment_request_public_id' => $amendmentRequest->public_id,
            'expected_source_fingerprint' => $expectedFingerprint,
            'expected_version' => $expectedVersion,
        ]);
        $encounter = $amendmentRequest->encounter()->firstOrFail();

        try {
            return DB::transaction(function () use (
                $amendmentRequest,
                $actor,
                $expectedVersion,
                $expectedFingerprint,
                $idempotencyKey,
                $requestCorrelationId,
                $digest,
                $encounter,
            ): OutpatientRmAmendmentReviewResult {
                $lockedEncounter = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
                $this->assertClosedSyntheticOutpatient($lockedEncounter);
                if ($replay = $this->replayOrConflict($actor, self::OPERATION_REVIEW_SIGNOFF, $idempotencyKey, $digest)) {
                    return $replay;
                }

                $lockedRequest = OutpatientPostClosureAmendmentRequest::query()
                    ->whereKey($amendmentRequest->id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                [$addendum, $baseline] = $this->assertReviewSources($lockedEncounter, $lockedRequest, lock: true);
                $latest = $this->latestReview($lockedRequest, lock: true);
                if (! $latest instanceof OutpatientRmAmendmentReview
                    || $latest->version !== $expectedVersion
                    || $latest->review_state !== OutpatientRmAmendmentReview::STATE_DRAFT) {
                    throw new OutpatientAmendmentDenied('stale_version', 'Draf review addendum tidak lagi sesuai.');
                }

                $snapshot = $this->buildSnapshot($lockedEncounter, $lockedRequest, $addendum, $baseline, lock: true);
                if (! hash_equals($snapshot['source_fingerprint'], $expectedFingerprint)
                    || ! hash_equals($latest->source_fingerprint, $expectedFingerprint)) {
                    throw new OutpatientAmendmentDenied('source_stale', 'Sumber review addendum telah berubah. Muat ulang sebelum melanjutkan.');
                }
                if ($snapshot['blockers'] !== []) {
                    $reason = in_array('NO_ACTIVE_LAB_ORDERS', $snapshot['blockers'], true)
                        ? 'active_lab_orders'
                        : 'checklist_incomplete';
                    throw new OutpatientAmendmentDenied($reason, 'Review addendum belum dapat ditandatangani.');
                }

                $review = $this->createReview(
                    $lockedEncounter,
                    $lockedRequest,
                    $addendum,
                    $baseline,
                    $actor,
                    $expectedVersion + 1,
                    OutpatientRmAmendmentReview::STATE_SIGNED_OFF,
                    $snapshot,
                );
                $this->recordSuccess('rmik.outpatient.amendment.signoff', $lockedEncounter, $lockedRequest, $review, $actor, []);
                $this->recordReceipt($lockedEncounter, $actor, self::OPERATION_REVIEW_SIGNOFF, $idempotencyKey, $digest, $review, $requestCorrelationId);

                return new OutpatientRmAmendmentReviewResult($review, replayed: false);
            }, 3);
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileReceiptAfterRace($actor, self::OPERATION_REVIEW_SIGNOFF, $idempotencyKey, $digest, $race);
            } catch (OutpatientAmendmentDenied $denial) {
                $this->recordDenialOrFail('rmik.outpatient.amendment.signoff', $encounter, $amendmentRequest, $actor, $denial);
            }
        } catch (OutpatientAmendmentDenied $denial) {
            $this->recordDenialOrFail('rmik.outpatient.amendment.signoff', $encounter, $amendmentRequest, $actor, $denial);
        }
    }

    private function assertClosedSyntheticOutpatient(Encounter $encounter): void
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_OUTPATIENT) {
            throw new OutpatientAmendmentDenied('not_outpatient', 'Review addendum hanya tersedia untuk rawat jalan.');
        }
        if (! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new OutpatientAmendmentDenied('synthetic_only', 'Review addendum hanya tersedia untuk data sintetis.');
        }
        if ($encounter->status !== Encounter::STATUS_CLOSED) {
            throw new OutpatientAmendmentDenied('encounter_not_closed', 'Review addendum hanya tersedia pada kunjungan yang sudah ditutup.');
        }
    }

    /** @return array{OutpatientClinicalDocumentAddendum, OutpatientRmCompletenessReview} */
    private function assertReviewSources(
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        bool $lock = false,
    ): array {
        if ($request->request_state !== OutpatientPostClosureAmendmentRequest::STATE_CONSUMED) {
            throw new OutpatientAmendmentDenied('request_not_consumed', 'Addendum Final belum tersedia untuk review ulang.');
        }
        $addendumQuery = OutpatientClinicalDocumentAddendum::query()
            ->where('amendment_request_id', $request->id)
            ->where('encounter_id', $encounter->id);
        $addendum = ($lock ? $addendumQuery->lockForUpdate() : $addendumQuery)->first();
        if (! $addendum instanceof OutpatientClinicalDocumentAddendum
            || $addendum->addendum_state !== OutpatientClinicalDocumentAddendum::STATE_FINAL) {
            throw new OutpatientAmendmentDenied('final_addendum_missing', 'Addendum Final tidak tersedia.');
        }

        $originalQuery = OutpatientClinicalDocument::query()
            ->whereKey($request->original_document_id)
            ->where('encounter_id', $encounter->id);
        $original = ($lock ? $originalQuery->lockForUpdate() : $originalQuery)->first();
        if (! $original instanceof OutpatientClinicalDocument
            || $original->document_state !== OutpatientClinicalDocument::STATE_FINAL
            || $original->version !== $request->original_document_version
            || ! OutpatientClinicalDocumentVersion::query()
                ->where('outpatient_clinical_document_id', $request->original_document_id)
                ->where('version', $request->original_document_version)
                ->where('document_state', OutpatientClinicalDocument::STATE_FINAL)
                ->exists()) {
            throw new OutpatientAmendmentDenied('original_source_stale', 'Dokumen sumber Final tidak lagi sesuai.');
        }

        $baselineQuery = OutpatientRmCompletenessReview::query()
            ->where('encounter_id', $encounter->id)
            ->where('review_state', OutpatientRmCompletenessReview::STATE_SIGNED_OFF)
            ->orderByDesc('version');
        $baseline = ($lock ? $baselineQuery->lockForUpdate() : $baselineQuery)->first();
        if (! $baseline instanceof OutpatientRmCompletenessReview) {
            throw new OutpatientAmendmentDenied('baseline_signoff_missing', 'Sign-off RMIK awal tidak tersedia.');
        }

        return [$addendum, $baseline];
    }

    /**
     * @return array{
     *   source_fingerprint: string,
     *   items: list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>,
     *   blockers: list<string>,
     *   baseline_review: OutpatientRmCompletenessReview,
     *   addendum: OutpatientClinicalDocumentAddendum
     * }
     */
    private function buildSnapshot(
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        OutpatientClinicalDocumentAddendum $addendum,
        OutpatientRmCompletenessReview $baseline,
        bool $lock,
    ): array {
        $addendaQuery = OutpatientClinicalDocumentAddendum::query()
            ->where('encounter_id', $encounter->id)
            ->where('addendum_state', OutpatientClinicalDocumentAddendum::STATE_FINAL)
            ->orderBy('public_id');
        $finalAddenda = ($lock ? $addendaQuery->lockForUpdate() : $addendaQuery)->get();
        $activeQuery = LabServiceRequest::query()
            ->where('encounter_id', $encounter->id)
            ->where('status', LabServiceRequest::STATUS_ACTIVE)
            ->orderBy('public_id');
        $activeOrderIds = ($lock ? $activeQuery->lockForUpdate() : $activeQuery)
            ->pluck('public_id')->all();

        $finalAddendumEvidence = $finalAddenda->map(fn (OutpatientClinicalDocumentAddendum $item): array => [
            'content_digest' => $this->contentDigest($item),
            'public_id' => $item->public_id,
            'version' => $item->version,
        ])->all();
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'active_lab_order_public_ids' => $activeOrderIds,
            'baseline_review' => [
                'public_id' => $baseline->public_id,
                'source_fingerprint' => $baseline->source_fingerprint,
                'version' => $baseline->version,
            ],
            'final_addenda' => $finalAddendumEvidence,
            'profile' => self::FINGERPRINT_PROFILE,
        ]));

        $items = [
            $this->item('BASELINE_SIGNOFF', OutpatientRmAmendmentReview::ITEM_LABELS['BASELINE_SIGNOFF'], true, $baseline->public_id),
            $this->item('FINAL_ADDENDUM', OutpatientRmAmendmentReview::ITEM_LABELS['FINAL_ADDENDUM'], $finalAddenda->contains('id', $addendum->id), $addendum->public_id),
            $this->item('CURRENT_SOURCE_REFERENCE', OutpatientRmAmendmentReview::ITEM_LABELS['CURRENT_SOURCE_REFERENCE'], true, $request->originalDocument()->value('public_id')),
            $this->item('NO_ACTIVE_LAB_ORDERS', OutpatientRmAmendmentReview::ITEM_LABELS['NO_ACTIVE_LAB_ORDERS'], $activeOrderIds === [], null),
        ];
        $blockers = array_values(array_map(
            static fn (array $item): string => $item['item_code'],
            array_filter($items, static fn (array $item): bool => $item['is_blocking'] && ! $item['is_complete']),
        ));

        return [
            'source_fingerprint' => $fingerprint,
            'items' => $items,
            'blockers' => $blockers,
            'baseline_review' => $baseline,
            'addendum' => $addendum,
        ];
    }

    /** @return array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null} */
    private function item(string $code, string $label, bool $complete, ?string $source): array
    {
        return [
            'item_code' => $code,
            'label' => $label,
            'is_blocking' => true,
            'is_complete' => $complete,
            'source_reference' => $source,
        ];
    }

    private function latestReview(OutpatientPostClosureAmendmentRequest $request, bool $lock): ?OutpatientRmAmendmentReview
    {
        $query = OutpatientRmAmendmentReview::query()
            ->where('amendment_request_id', $request->id)
            ->orderByDesc('version');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @param array{source_fingerprint: string, items: list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>, blockers: list<string>, baseline_review: OutpatientRmCompletenessReview, addendum: OutpatientClinicalDocumentAddendum} $snapshot */
    private function createReview(
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        OutpatientClinicalDocumentAddendum $addendum,
        OutpatientRmCompletenessReview $baseline,
        User $actor,
        int $version,
        string $state,
        array $snapshot,
    ): OutpatientRmAmendmentReview {
        $signed = $state === OutpatientRmAmendmentReview::STATE_SIGNED_OFF;
        $occurredAt = now()->startOfSecond();

        return OutpatientRmAmendmentReview::withinAggregateCreation(
            $encounter,
            $request,
            $addendum,
            $baseline,
            $actor,
            $version,
            $state,
            $snapshot['source_fingerprint'],
            $occurredAt,
            $snapshot['items'],
            static function () use (
                $request,
                $addendum,
                $encounter,
                $baseline,
                $actor,
                $version,
                $snapshot,
                $state,
                $signed,
                $occurredAt,
            ): OutpatientRmAmendmentReview {
                $review = OutpatientRmAmendmentReview::query()->create([
                    'amendment_request_id' => $request->id,
                    'addendum_id' => $addendum->id,
                    'encounter_id' => $encounter->id,
                    'baseline_review_id' => $baseline->id,
                    'reviewed_by_user_id' => $actor->id,
                    'signed_off_by_user_id' => $signed ? $actor->id : null,
                    'definition_version' => OutpatientRmAmendmentReview::DEFINITION_VERSION,
                    'version' => $version,
                    'source_fingerprint' => $snapshot['source_fingerprint'],
                    'review_state' => $state,
                    'reviewed_at' => $occurredAt,
                    'signed_off_at' => $signed ? $occurredAt : null,
                ]);
                $review->items()->createMany($snapshot['items']);

                return $review->load(['items', 'reviewedBy', 'signedOffBy']);
            },
        );
    }

    private function contentDigest(OutpatientClinicalDocumentAddendum $addendum): string
    {
        return hash('sha256', CanonicalJson::encode([
            'definition_version' => $addendum->definition_version,
            'fields' => $addendum->fields,
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function digest(array $payload): string
    {
        return hash('sha256', CanonicalJson::encode($payload));
    }

    private function validateInput(
        int $expectedVersion,
        string $fingerprint,
        string $key,
        ?string $correlationId,
        bool $versionMayBeZero = true,
    ): void {
        if ($expectedVersion < ($versionMayBeZero ? 0 : 1)) {
            throw new InvalidArgumentException('Expected amendment review version is invalid.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Expected amendment source fingerprint must be lowercase SHA-256.');
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('Amendment review idempotency key is invalid.');
        }
        if ($correlationId !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $correlationId) !== 1) {
            throw new InvalidArgumentException('Amendment review correlation ID is invalid.');
        }
    }

    private function replayOrConflict(User $actor, string $operation, string $key, string $digest): ?OutpatientRmAmendmentReviewResult
    {
        $receipt = OutpatientAmendmentOperationReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();
        if (! $receipt instanceof OutpatientAmendmentOperationReceipt) {
            return null;
        }
        if (! hash_equals($receipt->payload_digest, $digest)) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Kunci idempotensi telah digunakan untuk review addendum yang berbeda.');
        }
        if ($receipt->result_type !== OutpatientAmendmentOperationReceipt::RESULT_REVIEW) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Bukti idempotensi tidak merujuk hasil review yang sah.');
        }
        $review = OutpatientRmAmendmentReview::query()
            ->where('public_id', $receipt->result_public_id)
            ->lockForUpdate()
            ->first();
        if (! $review instanceof OutpatientRmAmendmentReview) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Hasil review dari bukti idempotensi tidak tersedia.');
        }

        return new OutpatientRmAmendmentReviewResult($review, replayed: true);
    }

    private function reconcileReceiptAfterRace(
        User $actor,
        string $operation,
        string $key,
        string $digest,
        UniqueConstraintViolationException $race,
    ): OutpatientRmAmendmentReviewResult {
        return DB::transaction(function () use ($actor, $operation, $key, $digest, $race): OutpatientRmAmendmentReviewResult {
            $receipt = OutpatientAmendmentOperationReceipt::query()
                ->where('actor_user_id', $actor->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if (! $receipt instanceof OutpatientAmendmentOperationReceipt) {
                throw $race;
            }
            if (! hash_equals($receipt->payload_digest, $digest)
                || $receipt->result_type !== OutpatientAmendmentOperationReceipt::RESULT_REVIEW) {
                throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Bukti idempotensi review tidak cocok dengan hasil pemenang.');
            }
            $review = OutpatientRmAmendmentReview::query()
                ->where('public_id', $receipt->result_public_id)
                ->lockForUpdate()
                ->first();
            if (! $review instanceof OutpatientRmAmendmentReview) {
                throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Hasil review pemenang tidak tersedia.');
            }

            return new OutpatientRmAmendmentReviewResult($review, replayed: true);
        }, 3);
    }

    private function recordReceipt(
        Encounter $encounter,
        User $actor,
        string $operation,
        string $key,
        string $digest,
        OutpatientRmAmendmentReview $review,
        ?string $correlationId,
    ): void {
        OutpatientAmendmentOperationReceipt::query()->create([
            'encounter_id' => $encounter->id,
            'actor_user_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
            'payload_digest' => $digest,
            'result_type' => OutpatientAmendmentOperationReceipt::RESULT_REVIEW,
            'result_public_id' => $review->public_id,
            'request_correlation_id' => $correlationId,
        ]);
    }

    /** @param list<string> $blockers */
    private function recordSuccess(
        string $action,
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        OutpatientRmAmendmentReview $review,
        User $actor,
        array $blockers,
    ): void {
        if ($this->auditRecorder->record(
            action: $action,
            resourceType: 'outpatient_rm_amendment_review',
            resourceId: $review->public_id,
            actor: $actor,
            outcome: 'SUCCESS',
            metadata: [
                'addendum_id' => $review->addendum()->value('public_id'),
                'amendment_request_id' => $request->public_id,
                'baseline_review_id' => $review->baselineReview()->value('public_id'),
                'definition_version' => $review->definition_version,
                'encounter_id' => $encounter->public_id,
                'failed_item_ids' => $blockers,
                'review_version' => $review->version,
                'source_fingerprint' => $review->source_fingerprint,
            ],
        ) === null) {
            throw new OutpatientAmendmentAuditUnavailable('Review addendum tidak dapat disimpan karena audit gagal direkam.');
        }
    }

    private function recordDenialOrFail(
        string $action,
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        User $actor,
        OutpatientAmendmentDenied $denial,
    ): never {
        if ($this->auditRecorder->record(
            action: $action,
            resourceType: 'outpatient_post_closure_amendment_request',
            resourceId: $request->public_id,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: [
                'amendment_request_id' => $request->public_id,
                'encounter_id' => $encounter->public_id,
            ],
        ) === null) {
            throw new OutpatientAmendmentAuditUnavailable('Penolakan review addendum tidak dapat direkam dalam audit.');
        }

        throw $denial;
    }
}
