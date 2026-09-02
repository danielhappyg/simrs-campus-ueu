<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\OutpatientAmendmentOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientClinicalDocumentAddendumVersion;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OutpatientPostClosureAmendmentService
{
    public const OPERATION_SUBMIT = 'REQUEST_SUBMIT';

    public const OPERATION_DECIDE = 'REQUEST_DECIDE';

    public const OPERATION_ADDENDUM_WRITE = 'ADDENDUM_WRITE';

    public const OPERATION_ADDENDUM_FINALIZE = 'ADDENDUM_FINALIZE';

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly OutpatientAmendmentActorPolicy $actorPolicy,
    ) {}

    public function submit(
        Encounter $encounter,
        User $actor,
        string $originalDocumentPublicId,
        int $originalDocumentVersion,
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): OutpatientAmendmentRequestResult {
        $this->actorPolicy->authorizePhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_REQUEST);
        $note = $this->normalizeNote($note);
        $this->validateSubmitInput(
            $originalDocumentPublicId,
            $originalDocumentVersion,
            $reasonCode,
            $note,
            $idempotencyKey,
            $requestCorrelationId,
        );
        $digest = $this->digest([
            'encounter_public_id' => $encounter->public_id,
            'note' => $note,
            'original_document_public_id' => $originalDocumentPublicId,
            'original_document_version' => $originalDocumentVersion,
            'reason_code' => $reasonCode,
        ]);

        try {
            return DB::transaction(function () use (
                $encounter,
                $actor,
                $originalDocumentPublicId,
                $originalDocumentVersion,
                $reasonCode,
                $note,
                $idempotencyKey,
                $requestCorrelationId,
                $digest,
            ): OutpatientAmendmentRequestResult {
                $lockedEncounter = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
                $this->assertClosedSyntheticOutpatient($lockedEncounter);

                if ($replay = $this->replayOrConflict($actor, self::OPERATION_SUBMIT, $idempotencyKey, $digest)) {
                    return $replay;
                }

                $original = OutpatientClinicalDocument::query()
                    ->where('encounter_id', $lockedEncounter->id)
                    ->where('public_id', $originalDocumentPublicId)
                    ->lockForUpdate()
                    ->first();
                $this->assertFinalOriginal($original, $originalDocumentVersion);
                $this->assertBaselineSignoffExists($lockedEncounter);

                $request = OutpatientPostClosureAmendmentRequest::query()->create([
                    'encounter_id' => $lockedEncounter->id,
                    'original_document_id' => $original->id,
                    'original_document_version' => $originalDocumentVersion,
                    'requested_by_user_id' => $actor->id,
                    'reason_code' => $reasonCode,
                    'note' => $note,
                    'request_state' => OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED,
                    'version' => 1,
                    'request_correlation_id' => $requestCorrelationId,
                ]);

                $this->recordSuccessOrFail(
                    action: 'clinical.outpatient.amendment.request.submit',
                    resourceType: 'outpatient_post_closure_amendment_request',
                    resourceId: $request->public_id,
                    actor: $actor,
                    metadata: [
                        'encounter_id' => $lockedEncounter->public_id,
                        'original_document_id' => $original->public_id,
                        'original_document_version' => $originalDocumentVersion,
                        'reason_code' => $reasonCode,
                        'request_state' => $request->request_state,
                        'request_version' => $request->version,
                    ],
                );
                $this->recordReceipt(
                    $lockedEncounter,
                    $actor,
                    self::OPERATION_SUBMIT,
                    $idempotencyKey,
                    $digest,
                    $request,
                    $requestCorrelationId,
                );

                return new OutpatientAmendmentRequestResult($request, replayed: false);
            }, 3);
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileReceiptAfterRace(
                    actor: $actor,
                    operation: self::OPERATION_SUBMIT,
                    key: $idempotencyKey,
                    digest: $digest,
                    race: $race,
                );
            } catch (OutpatientAmendmentDenied $denial) {
                $this->recordDenialOrFail(
                    action: 'clinical.outpatient.amendment.request.submit',
                    encounter: $encounter,
                    request: null,
                    actor: $actor,
                    denial: $denial,
                );
            }
        } catch (OutpatientAmendmentDenied $denial) {
            $this->recordDenialOrFail(
                action: 'clinical.outpatient.amendment.request.submit',
                encounter: $encounter,
                request: null,
                actor: $actor,
                denial: $denial,
            );
        }
    }

    public function decide(
        OutpatientPostClosureAmendmentRequest $amendmentRequest,
        User $actor,
        string $decision,
        ?string $decisionNote,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): OutpatientAmendmentRequestResult {
        $this->actorPolicy->authorizePhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_APPROVE);
        $decisionNote = $this->normalizeNote($decisionNote);
        $this->validateDecisionInput($decision, $decisionNote, $expectedVersion, $idempotencyKey, $requestCorrelationId);
        $digest = $this->digest([
            'amendment_request_public_id' => $amendmentRequest->public_id,
            'decision' => $decision,
            'decision_note' => $decisionNote,
            'expected_version' => $expectedVersion,
        ]);

        $encounter = $amendmentRequest->encounter()->firstOrFail();

        try {
            return DB::transaction(function () use (
                $amendmentRequest,
                $encounter,
                $actor,
                $decision,
                $decisionNote,
                $expectedVersion,
                $idempotencyKey,
                $requestCorrelationId,
                $digest,
            ): OutpatientAmendmentRequestResult {
                $lockedEncounter = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
                $this->assertClosedSyntheticOutpatient($lockedEncounter);

                if ($replay = $this->replayOrConflict($actor, self::OPERATION_DECIDE, $idempotencyKey, $digest)) {
                    return $replay;
                }

                $lockedRequest = OutpatientPostClosureAmendmentRequest::query()
                    ->whereKey($amendmentRequest->id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRequest->requested_by_user_id === $actor->id) {
                    throw new OutpatientAmendmentDenied(
                        'requester_cannot_decide',
                        'Pemohon tidak dapat menyetujui atau menolak permintaan addendumnya sendiri.',
                    );
                }
                if ($lockedRequest->request_state !== OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED) {
                    throw new OutpatientAmendmentDenied('request_not_submitted', 'Permintaan addendum tidak lagi menunggu keputusan.');
                }
                if ($lockedRequest->version !== $expectedVersion) {
                    throw new OutpatientAmendmentDenied('stale_version', 'Permintaan addendum telah berubah. Muat ulang sebelum melanjutkan.');
                }

                $original = OutpatientClinicalDocument::query()
                    ->whereKey($lockedRequest->original_document_id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->first();
                $this->assertFinalOriginal($original, $lockedRequest->original_document_version);

                $lockedRequest->update([
                    'decided_by_user_id' => $actor->id,
                    'decision_note' => $decisionNote,
                    'request_state' => $decision,
                    'version' => $lockedRequest->version + 1,
                    'decided_at' => now(),
                ]);
                $lockedRequest->refresh();

                $this->recordSuccessOrFail(
                    action: 'clinical.outpatient.amendment.request.decide',
                    resourceType: 'outpatient_post_closure_amendment_request',
                    resourceId: $lockedRequest->public_id,
                    actor: $actor,
                    metadata: [
                        'decision' => $decision,
                        'encounter_id' => $lockedEncounter->public_id,
                        'request_state' => $lockedRequest->request_state,
                        'request_version' => $lockedRequest->version,
                    ],
                );
                $this->recordReceipt(
                    $lockedEncounter,
                    $actor,
                    self::OPERATION_DECIDE,
                    $idempotencyKey,
                    $digest,
                    $lockedRequest,
                    $requestCorrelationId,
                );

                return new OutpatientAmendmentRequestResult($lockedRequest, replayed: false);
            }, 3);
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileReceiptAfterRace(
                    actor: $actor,
                    operation: self::OPERATION_DECIDE,
                    key: $idempotencyKey,
                    digest: $digest,
                    race: $race,
                );
            } catch (OutpatientAmendmentDenied $denial) {
                $this->recordDenialOrFail(
                    action: 'clinical.outpatient.amendment.request.decide',
                    encounter: $encounter,
                    request: $amendmentRequest,
                    actor: $actor,
                    denial: $denial,
                );
            }
        } catch (OutpatientAmendmentDenied $denial) {
            $this->recordDenialOrFail(
                action: 'clinical.outpatient.amendment.request.decide',
                encounter: $encounter,
                request: $amendmentRequest,
                actor: $actor,
                denial: $denial,
            );
        }
    }

    /** @param array<string, mixed> $fields */
    public function saveAddendum(
        OutpatientPostClosureAmendmentRequest $amendmentRequest,
        User $actor,
        array $fields,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): OutpatientAmendmentAddendumResult {
        $this->actorPolicy->authorizePhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_WRITE);
        $fields = $this->validateAddendumInput($fields, $expectedVersion, $idempotencyKey, $requestCorrelationId);
        $digest = $this->digest([
            'amendment_request_public_id' => $amendmentRequest->public_id,
            'expected_version' => $expectedVersion,
            'fields' => $fields,
        ]);
        $encounter = $amendmentRequest->encounter()->firstOrFail();

        try {
            return DB::transaction(function () use (
                $amendmentRequest,
                $encounter,
                $actor,
                $fields,
                $expectedVersion,
                $idempotencyKey,
                $requestCorrelationId,
                $digest,
            ): OutpatientAmendmentAddendumResult {
                $lockedEncounter = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
                $this->assertClosedSyntheticOutpatient($lockedEncounter);

                if ($replay = $this->replayAddendumOrConflict($actor, self::OPERATION_ADDENDUM_WRITE, $idempotencyKey, $digest)) {
                    return $replay;
                }

                $lockedRequest = OutpatientPostClosureAmendmentRequest::query()
                    ->whereKey($amendmentRequest->id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertApprovedAuthor($lockedRequest, $actor);

                $original = OutpatientClinicalDocument::query()
                    ->whereKey($lockedRequest->original_document_id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->first();
                $this->assertFinalOriginal($original, $lockedRequest->original_document_version);

                $addendum = OutpatientClinicalDocumentAddendum::query()
                    ->where('amendment_request_id', $lockedRequest->id)
                    ->lockForUpdate()
                    ->first();
                if ($addendum instanceof OutpatientClinicalDocumentAddendum) {
                    if ($addendum->addendum_state !== OutpatientClinicalDocumentAddendum::STATE_DRAFT) {
                        throw new OutpatientAmendmentDenied('addendum_already_final', 'Addendum Final tidak dapat diubah.');
                    }
                    if ($addendum->version !== $expectedVersion) {
                        throw new OutpatientAmendmentDenied('stale_version', 'Draf addendum telah berubah. Muat ulang sebelum melanjutkan.');
                    }
                    $addendum->update([
                        'fields' => $fields,
                        'version' => $addendum->version + 1,
                    ]);
                    $addendum->refresh();
                } else {
                    if ($expectedVersion !== 0) {
                        throw new OutpatientAmendmentDenied('stale_version', 'Draf addendum belum tersedia pada versi yang diminta.');
                    }
                    $addendum = OutpatientClinicalDocumentAddendum::query()->create([
                        'amendment_request_id' => $lockedRequest->id,
                        'encounter_id' => $lockedEncounter->id,
                        'original_document_id' => $original->id,
                        'original_document_version' => $lockedRequest->original_document_version,
                        'author_user_id' => $actor->id,
                        'addendum_state' => OutpatientClinicalDocumentAddendum::STATE_DRAFT,
                        'definition_version' => OutpatientClinicalDocumentAddendum::DEFINITION_VERSION,
                        'version' => 1,
                        'fields' => $fields,
                    ]);
                }

                $this->recordAddendumVersion($addendum, $actor);
                $contentDigest = $this->addendumContentDigest($addendum);
                $this->recordSuccessOrFail(
                    action: 'clinical.outpatient.amendment.addendum.write',
                    resourceType: 'outpatient_clinical_document_addendum',
                    resourceId: $addendum->public_id,
                    actor: $actor,
                    metadata: $this->addendumAuditMetadata($lockedEncounter, $lockedRequest, $addendum, $contentDigest),
                );
                $this->recordAddendumReceipt(
                    $lockedEncounter,
                    $actor,
                    self::OPERATION_ADDENDUM_WRITE,
                    $idempotencyKey,
                    $digest,
                    $addendum,
                    $requestCorrelationId,
                );

                return new OutpatientAmendmentAddendumResult($lockedRequest, $addendum, replayed: false);
            }, 3);
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileAddendumReceiptAfterRace($actor, self::OPERATION_ADDENDUM_WRITE, $idempotencyKey, $digest, $race);
            } catch (OutpatientAmendmentDenied $denial) {
                $this->recordAddendumDenialOrFail('clinical.outpatient.amendment.addendum.write', $encounter, $amendmentRequest, $actor, $denial);
            }
        } catch (OutpatientAmendmentDenied $denial) {
            $this->recordAddendumDenialOrFail('clinical.outpatient.amendment.addendum.write', $encounter, $amendmentRequest, $actor, $denial);
        }
    }

    public function finalizeAddendum(
        OutpatientPostClosureAmendmentRequest $amendmentRequest,
        User $actor,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): OutpatientAmendmentAddendumResult {
        $this->actorPolicy->authorizePhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_FINALIZE);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Expected outpatient addendum version must be positive.');
        }
        $this->validateOperationInput($idempotencyKey, $requestCorrelationId);
        $digest = $this->digest([
            'amendment_request_public_id' => $amendmentRequest->public_id,
            'expected_version' => $expectedVersion,
        ]);
        $encounter = $amendmentRequest->encounter()->firstOrFail();

        try {
            return DB::transaction(function () use (
                $amendmentRequest,
                $encounter,
                $actor,
                $expectedVersion,
                $idempotencyKey,
                $requestCorrelationId,
                $digest,
            ): OutpatientAmendmentAddendumResult {
                $lockedEncounter = Encounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
                $this->assertClosedSyntheticOutpatient($lockedEncounter);

                if ($replay = $this->replayAddendumOrConflict($actor, self::OPERATION_ADDENDUM_FINALIZE, $idempotencyKey, $digest)) {
                    return $replay;
                }

                $lockedRequest = OutpatientPostClosureAmendmentRequest::query()
                    ->whereKey($amendmentRequest->id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertApprovedAuthor($lockedRequest, $actor);

                $original = OutpatientClinicalDocument::query()
                    ->whereKey($lockedRequest->original_document_id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->first();
                $this->assertFinalOriginal($original, $lockedRequest->original_document_version);

                $addendum = OutpatientClinicalDocumentAddendum::query()
                    ->where('amendment_request_id', $lockedRequest->id)
                    ->lockForUpdate()
                    ->first();
                if (! $addendum instanceof OutpatientClinicalDocumentAddendum) {
                    throw new OutpatientAmendmentDenied('addendum_missing', 'Draf addendum belum tersedia.');
                }
                if ($addendum->addendum_state !== OutpatientClinicalDocumentAddendum::STATE_DRAFT) {
                    throw new OutpatientAmendmentDenied('addendum_already_final', 'Addendum sudah Final.');
                }
                if ($addendum->version !== $expectedVersion) {
                    throw new OutpatientAmendmentDenied('stale_version', 'Draf addendum telah berubah. Muat ulang sebelum melanjutkan.');
                }
                if ($addendum->author_user_id !== $actor->id || $lockedRequest->decided_by_user_id === $actor->id) {
                    throw new OutpatientAmendmentDenied('actor_not_approved_author', 'Hanya dokter pemohon yang dapat memfinalisasi addendum.');
                }

                $finalizedAt = now();
                OutpatientPostClosureAmendmentRequest::withinAggregateFinalization(
                    $lockedRequest,
                    $addendum,
                    function () use ($addendum, $actor, $lockedRequest, $finalizedAt): void {
                        $addendum->update([
                            'addendum_state' => OutpatientClinicalDocumentAddendum::STATE_FINAL,
                            'version' => $addendum->version + 1,
                            'finalized_by_user_id' => $actor->id,
                            'finalized_at' => $finalizedAt,
                        ]);
                        $addendum->refresh();
                        $this->recordAddendumVersion($addendum, $actor);

                        $lockedRequest->update([
                            'request_state' => OutpatientPostClosureAmendmentRequest::STATE_CONSUMED,
                            'version' => $lockedRequest->version + 1,
                            'consumed_at' => $finalizedAt,
                        ]);
                        $lockedRequest->refresh();
                    },
                );

                $contentDigest = $this->addendumContentDigest($addendum);
                $metadata = $this->addendumAuditMetadata($lockedEncounter, $lockedRequest, $addendum, $contentDigest);
                $metadata['request_state'] = $lockedRequest->request_state;
                $metadata['request_version'] = $lockedRequest->version;
                $this->recordSuccessOrFail(
                    action: 'clinical.outpatient.amendment.addendum.finalize',
                    resourceType: 'outpatient_clinical_document_addendum',
                    resourceId: $addendum->public_id,
                    actor: $actor,
                    metadata: $metadata,
                );
                $this->recordAddendumReceipt(
                    $lockedEncounter,
                    $actor,
                    self::OPERATION_ADDENDUM_FINALIZE,
                    $idempotencyKey,
                    $digest,
                    $addendum,
                    $requestCorrelationId,
                );

                return new OutpatientAmendmentAddendumResult($lockedRequest, $addendum, replayed: false);
            }, 3);
        } catch (UniqueConstraintViolationException $race) {
            try {
                return $this->reconcileAddendumReceiptAfterRace($actor, self::OPERATION_ADDENDUM_FINALIZE, $idempotencyKey, $digest, $race);
            } catch (OutpatientAmendmentDenied $denial) {
                $this->recordAddendumDenialOrFail('clinical.outpatient.amendment.addendum.finalize', $encounter, $amendmentRequest, $actor, $denial);
            }
        } catch (OutpatientAmendmentDenied $denial) {
            $this->recordAddendumDenialOrFail('clinical.outpatient.amendment.addendum.finalize', $encounter, $amendmentRequest, $actor, $denial);
        }
    }

    private function assertClosedSyntheticOutpatient(Encounter $encounter): void
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_OUTPATIENT) {
            throw new OutpatientAmendmentDenied('not_outpatient', 'Permintaan addendum hanya tersedia untuk rawat jalan.');
        }
        if (! $encounter->patient()->where('is_synthetic', true)->exists()) {
            throw new OutpatientAmendmentDenied('synthetic_only', 'Permintaan addendum hanya tersedia untuk data sintetis.');
        }
        if ($encounter->status !== Encounter::STATUS_CLOSED) {
            throw new OutpatientAmendmentDenied('encounter_not_closed', 'Permintaan addendum hanya tersedia setelah kunjungan ditutup.');
        }
    }

    private function assertFinalOriginal(?OutpatientClinicalDocument $original, int $version): void
    {
        if (! $original instanceof OutpatientClinicalDocument) {
            throw new OutpatientAmendmentDenied('original_document_missing', 'Dokumen sumber tidak ditemukan.');
        }
        if ($original->document_state !== OutpatientClinicalDocument::STATE_FINAL) {
            throw new OutpatientAmendmentDenied('original_document_not_final', 'Hanya dokumen Final yang dapat dirujuk.');
        }
        if ($original->version !== $version) {
            throw new OutpatientAmendmentDenied('original_document_version_mismatch', 'Versi dokumen sumber telah berubah.');
        }
        $versionExists = OutpatientClinicalDocumentVersion::query()
            ->where('outpatient_clinical_document_id', $original->id)
            ->where('version', $version)
            ->where('document_state', OutpatientClinicalDocument::STATE_FINAL)
            ->exists();
        if (! $versionExists) {
            throw new OutpatientAmendmentDenied('original_document_version_missing', 'Versi Final dokumen sumber tidak tersedia.');
        }
    }

    private function assertBaselineSignoffExists(Encounter $encounter): void
    {
        if (! OutpatientRmCompletenessReview::query()
            ->where('encounter_id', $encounter->id)
            ->where('review_state', OutpatientRmCompletenessReview::STATE_SIGNED_OFF)
            ->exists()) {
            throw new OutpatientAmendmentDenied('baseline_signoff_missing', 'Bukti penutupan RMIK awal tidak tersedia.');
        }
    }

    private function validateSubmitInput(
        string $originalDocumentPublicId,
        int $originalDocumentVersion,
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): void {
        $this->assertPublicId($originalDocumentPublicId, 'Original document public ID');
        if ($originalDocumentVersion < 1) {
            throw new InvalidArgumentException('Original document version must be positive.');
        }
        if (! in_array($reasonCode, OutpatientPostClosureAmendmentRequest::REASON_CODES, true)) {
            throw new InvalidArgumentException('Unknown outpatient amendment reason code.');
        }
        if ($reasonCode === OutpatientPostClosureAmendmentRequest::REASON_OTHER && $note === null) {
            throw new InvalidArgumentException('OTHER outpatient amendment reason requires a note.');
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Outpatient amendment note exceeds 500 characters.');
        }
        $this->validateOperationInput($idempotencyKey, $requestCorrelationId);
    }

    private function validateDecisionInput(
        string $decision,
        ?string $decisionNote,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): void {
        if (! in_array($decision, [
            OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
            OutpatientPostClosureAmendmentRequest::STATE_DENIED,
        ], true)) {
            throw new InvalidArgumentException('Unknown outpatient amendment decision.');
        }
        if ($decision === OutpatientPostClosureAmendmentRequest::STATE_DENIED && $decisionNote === null) {
            throw new InvalidArgumentException('Denied outpatient amendment request requires a decision note.');
        }
        if ($decisionNote !== null && mb_strlen($decisionNote) > 500) {
            throw new InvalidArgumentException('Outpatient amendment decision note exceeds 500 characters.');
        }
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Expected outpatient amendment request version must be positive.');
        }
        $this->validateOperationInput($idempotencyKey, $requestCorrelationId);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{addendum_text: string}
     */
    private function validateAddendumInput(
        array $fields,
        int $expectedVersion,
        string $idempotencyKey,
        ?string $requestCorrelationId,
    ): array {
        if (array_keys($fields) !== ['addendum_text'] || ! is_string($fields['addendum_text'])) {
            throw new InvalidArgumentException('Outpatient addendum fields must contain exactly addendum_text.');
        }
        $text = trim($fields['addendum_text']);
        if ($text === '' || mb_strlen($text) > 5000) {
            throw new InvalidArgumentException('Outpatient addendum text must contain 1 to 5000 characters.');
        }
        if ($expectedVersion < 0) {
            throw new InvalidArgumentException('Expected outpatient addendum version cannot be negative.');
        }
        $this->validateOperationInput($idempotencyKey, $requestCorrelationId);

        return ['addendum_text' => $text];
    }

    private function assertApprovedAuthor(OutpatientPostClosureAmendmentRequest $request, User $actor): void
    {
        if ($request->request_state !== OutpatientPostClosureAmendmentRequest::STATE_APPROVED) {
            $reason = match ($request->request_state) {
                OutpatientPostClosureAmendmentRequest::STATE_DENIED => 'request_denied',
                OutpatientPostClosureAmendmentRequest::STATE_CONSUMED => 'request_already_consumed',
                default => 'request_not_approved',
            };
            throw new OutpatientAmendmentDenied($reason, 'Permintaan belum tersedia untuk penulisan addendum.');
        }
        if ($request->requested_by_user_id !== $actor->id || $request->decided_by_user_id === $actor->id) {
            throw new OutpatientAmendmentDenied('actor_not_approved_author', 'Hanya dokter pemohon yang dapat menulis addendum.');
        }
    }

    private function validateOperationInput(string $idempotencyKey, ?string $requestCorrelationId): void
    {
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Outpatient amendment idempotency key is invalid.');
        }
        if ($requestCorrelationId !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $requestCorrelationId) !== 1) {
            throw new InvalidArgumentException('Outpatient amendment request correlation ID is invalid.');
        }
    }

    private function assertPublicId(string $value, string $label): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new InvalidArgumentException("{$label} must be a ULID.");
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }

    /** @param array<string, mixed> $payload */
    private function digest(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function replayOrConflict(User $actor, string $operation, string $key, string $digest): ?OutpatientAmendmentRequestResult
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
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Kunci idempotensi telah digunakan untuk permintaan yang berbeda.');
        }
        if ($receipt->result_type !== OutpatientAmendmentOperationReceipt::RESULT_REQUEST) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Bukti idempotensi tidak merujuk hasil permintaan yang sah.');
        }

        $request = OutpatientPostClosureAmendmentRequest::query()
            ->where('public_id', $receipt->result_public_id)
            ->lockForUpdate()
            ->first();
        if (! $request instanceof OutpatientPostClosureAmendmentRequest) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Hasil permintaan dari bukti idempotensi tidak tersedia.');
        }

        return new OutpatientAmendmentRequestResult($request, replayed: true);
    }

    private function replayAddendumOrConflict(User $actor, string $operation, string $key, string $digest): ?OutpatientAmendmentAddendumResult
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
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Kunci idempotensi telah digunakan untuk operasi addendum yang berbeda.');
        }
        if ($receipt->result_type !== OutpatientAmendmentOperationReceipt::RESULT_ADDENDUM) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Bukti idempotensi tidak merujuk hasil addendum yang sah.');
        }

        $addendum = OutpatientClinicalDocumentAddendum::query()
            ->where('public_id', $receipt->result_public_id)
            ->lockForUpdate()
            ->first();
        if (! $addendum instanceof OutpatientClinicalDocumentAddendum) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Hasil addendum dari bukti idempotensi tidak tersedia.');
        }
        $request = OutpatientPostClosureAmendmentRequest::query()
            ->whereKey($addendum->amendment_request_id)
            ->lockForUpdate()
            ->first();
        if (! $request instanceof OutpatientPostClosureAmendmentRequest) {
            throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Permintaan induk dari hasil addendum tidak tersedia.');
        }

        return new OutpatientAmendmentAddendumResult($request, $addendum, replayed: true);
    }

    private function reconcileReceiptAfterRace(
        User $actor,
        string $operation,
        string $key,
        string $digest,
        UniqueConstraintViolationException $race,
    ): OutpatientAmendmentRequestResult {
        return DB::transaction(function () use ($actor, $operation, $key, $digest, $race): OutpatientAmendmentRequestResult {
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
                || $receipt->result_type !== OutpatientAmendmentOperationReceipt::RESULT_REQUEST) {
                throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Bukti idempotensi permintaan tidak cocok dengan hasil pemenang.');
            }

            $request = OutpatientPostClosureAmendmentRequest::query()
                ->where('public_id', $receipt->result_public_id)
                ->lockForUpdate()
                ->first();
            if (! $request instanceof OutpatientPostClosureAmendmentRequest) {
                throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Hasil permintaan pemenang tidak tersedia.');
            }

            return new OutpatientAmendmentRequestResult($request, replayed: true);
        }, 3);
    }

    private function reconcileAddendumReceiptAfterRace(
        User $actor,
        string $operation,
        string $key,
        string $digest,
        UniqueConstraintViolationException $race,
    ): OutpatientAmendmentAddendumResult {
        return DB::transaction(function () use ($actor, $operation, $key, $digest, $race): OutpatientAmendmentAddendumResult {
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
                || $receipt->result_type !== OutpatientAmendmentOperationReceipt::RESULT_ADDENDUM) {
                throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Bukti idempotensi addendum tidak cocok dengan hasil pemenang.');
            }

            $addendum = OutpatientClinicalDocumentAddendum::query()
                ->where('public_id', $receipt->result_public_id)
                ->lockForUpdate()
                ->first();
            if (! $addendum instanceof OutpatientClinicalDocumentAddendum) {
                throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Hasil addendum pemenang tidak tersedia.');
            }
            $request = OutpatientPostClosureAmendmentRequest::query()
                ->whereKey($addendum->amendment_request_id)
                ->lockForUpdate()
                ->first();
            if (! $request instanceof OutpatientPostClosureAmendmentRequest) {
                throw new OutpatientAmendmentDenied('idempotency_key_conflict', 'Permintaan induk dari hasil addendum pemenang tidak tersedia.');
            }

            return new OutpatientAmendmentAddendumResult($request, $addendum, replayed: true);
        }, 3);
    }

    private function recordReceipt(
        Encounter $encounter,
        User $actor,
        string $operation,
        string $key,
        string $digest,
        OutpatientPostClosureAmendmentRequest $request,
        ?string $requestCorrelationId,
    ): void {
        OutpatientAmendmentOperationReceipt::query()->create([
            'encounter_id' => $encounter->id,
            'actor_user_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
            'payload_digest' => $digest,
            'result_type' => OutpatientAmendmentOperationReceipt::RESULT_REQUEST,
            'result_public_id' => $request->public_id,
            'request_correlation_id' => $requestCorrelationId,
        ]);
    }

    private function recordAddendumReceipt(
        Encounter $encounter,
        User $actor,
        string $operation,
        string $key,
        string $digest,
        OutpatientClinicalDocumentAddendum $addendum,
        ?string $requestCorrelationId,
    ): void {
        OutpatientAmendmentOperationReceipt::query()->create([
            'encounter_id' => $encounter->id,
            'actor_user_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
            'payload_digest' => $digest,
            'result_type' => OutpatientAmendmentOperationReceipt::RESULT_ADDENDUM,
            'result_public_id' => $addendum->public_id,
            'request_correlation_id' => $requestCorrelationId,
        ]);
    }

    private function recordAddendumVersion(OutpatientClinicalDocumentAddendum $addendum, User $actor): void
    {
        OutpatientClinicalDocumentAddendumVersion::query()->create([
            'addendum_id' => $addendum->id,
            'actor_user_id' => $actor->id,
            'version' => $addendum->version,
            'addendum_state' => $addendum->addendum_state,
            'definition_version' => $addendum->definition_version,
            'fields' => $addendum->fields,
            'finalized_at' => $addendum->finalized_at,
        ]);
    }

    private function addendumContentDigest(OutpatientClinicalDocumentAddendum $addendum): string
    {
        return $this->digest([
            'definition_version' => $addendum->definition_version,
            'fields' => $addendum->fields,
        ]);
    }

    /** @return array<string, mixed> */
    private function addendumAuditMetadata(
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        OutpatientClinicalDocumentAddendum $addendum,
        string $contentDigest,
    ): array {
        return [
            'addendum_state' => $addendum->addendum_state,
            'addendum_version' => $addendum->version,
            'amendment_request_id' => $request->public_id,
            'content_digest' => $contentDigest,
            'definition_version' => $addendum->definition_version,
            'encounter_id' => $encounter->public_id,
            'original_document_id' => $request->originalDocument()->value('public_id'),
            'original_document_version' => $request->original_document_version,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function recordSuccessOrFail(
        string $action,
        string $resourceType,
        string $resourceId,
        User $actor,
        array $metadata,
    ): void {
        if ($this->auditRecorder->record(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            outcome: 'SUCCESS',
            metadata: $metadata,
        ) === null) {
            throw new OutpatientAmendmentAuditUnavailable('Aksi addendum tidak dapat disimpan karena audit gagal direkam.');
        }
    }

    private function recordDenialOrFail(
        string $action,
        Encounter $encounter,
        ?OutpatientPostClosureAmendmentRequest $request,
        User $actor,
        OutpatientAmendmentDenied $denial,
    ): never {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: $request instanceof OutpatientPostClosureAmendmentRequest
                ? 'outpatient_post_closure_amendment_request'
                : 'encounter',
            resourceId: $request instanceof OutpatientPostClosureAmendmentRequest
                ? $request->public_id
                : $encounter->public_id,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: [
                'amendment_request_id' => $request?->public_id,
                'encounter_id' => $encounter->public_id,
            ],
        );
        if ($event === null) {
            throw new OutpatientAmendmentAuditUnavailable('Penolakan addendum tidak dapat direkam dalam audit.');
        }

        throw $denial;
    }

    private function recordAddendumDenialOrFail(
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
            throw new OutpatientAmendmentAuditUnavailable('Penolakan addendum tidak dapat direkam dalam audit.');
        }

        throw $denial;
    }
}
