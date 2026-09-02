<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientDischarge;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientDischargeSummaryVersion;
use App\Models\InpatientRmCoding;
use App\Models\InpatientRmCodingVersion;
use App\Models\InpatientRmCompletenessReview;
use App\Models\InpatientSummaryAddendum;
use App\Models\InpatientSummaryAddendumOperationReceipt;
use App\Models\InpatientSummaryAddendumReview;
use App\Models\InpatientSummaryAddendumVersion;
use App\Models\InpatientSummaryCorrectionRequest;
use App\Models\InpatientSummaryCorrectionRequestVersion;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InpatientSummaryAddendumService
{
    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InpatientSummaryAddendumActorPolicy $policy,
        private readonly InpatientDischargeSummaryEvidenceDigest $summaryDigests,
        private readonly InpatientDischargeCodingSourceEvidenceDigest $sourceDigests,
        private readonly InpatientRmService $rmService,
    ) {}

    public function submit(Encounter $encounter, User $actor, string $reasonCode, ?string $note, string $key, ?string $correlation = null): InpatientSummaryAddendumResult
    {
        $this->authorizePhysician($actor, Capability::INPATIENT_SUMMARY_ADDENDUM_REQUEST, 'clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null);
        $this->validateOrAudit('clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null, $actor, function () use ($key, $correlation, $reasonCode, $note): void {
            $this->validateKey($key, $correlation);
            if (! in_array($reasonCode, [InpatientSummaryCorrectionRequest::REASON_CLINICAL_CORRECTION, InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION, InpatientSummaryCorrectionRequest::REASON_WRONG_ENTRY, InpatientSummaryCorrectionRequest::REASON_OTHER], true)) {
                throw new InpatientSummaryAddendumDenied('validation_failed', 'Alasan koreksi tidak valid.', 422);
            }
            if ($reasonCode === InpatientSummaryCorrectionRequest::REASON_OTHER && $this->text($note) === null) {
                throw new InpatientSummaryAddendumDenied('request_note_required', 'Catatan wajib diisi untuk alasan lainnya.', 422);
            }
        });
        $digest = $this->digest(['encounter' => $encounter->public_id, 'reason_code' => $reasonCode, 'note' => $this->text($note)]);
        if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::SUBMIT, $key, $digest, 'clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null)) {
            return $replay;
        }
        try {
            return DB::transaction(function () use ($encounter, $actor, $reasonCode, $note, $key, $correlation, $digest): InpatientSummaryAddendumResult {
                // CLOSED encounters are immutable here; mutable addendum heads carry the workflow locks.
                $locked = Encounter::query()->whereKey($encounter->id)->firstOrFail();
                if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::SUBMIT, $key, $digest, 'clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null)) {
                    return $replay;
                }
                $baseline = $this->baseline($locked);
                if (InpatientSummaryCorrectionRequest::query()->where('encounter_id', $locked->id)->where('active_slot', 'ACTIVE')->exists()) {
                    throw new InpatientSummaryAddendumDenied('active_request_exists', 'Masih ada permintaan koreksi aktif.');
                }
                $now = now();
                [$request, $version] = InpatientSummaryAddendumMutationScope::run(function () use ($locked, $baseline, $actor, $reasonCode, $note, $correlation): array {
                    $request = InpatientSummaryCorrectionRequest::query()->create([
                        'encounter_id' => $locked->id, 'inpatient_discharge_id' => $baseline['discharge']->id,
                        'inpatient_discharge_summary_id' => $baseline['summary']->id, 'inpatient_discharge_summary_version_id' => $baseline['summary_version']->id,
                        'inpatient_discharge_coding_source_id' => $baseline['source']->id, 'inpatient_discharge_coding_source_version_id' => $baseline['source_version']->id,
                        'inpatient_rm_coding_id' => $baseline['coding']->id, 'inpatient_rm_coding_version_id' => $baseline['coding_version']->id,
                        'baseline_review_id' => $baseline['review']->id, 'requested_by_user_id' => $actor->id,
                        'reason_code' => $reasonCode, 'note' => $this->text($note), 'request_state' => InpatientSummaryCorrectionRequest::STATE_SUBMITTED,
                        'active_slot' => 'ACTIVE', 'version' => 1, 'summary_content_digest' => $baseline['discharge']->discharge_summary_content_digest,
                        'summary_provenance_digest' => $baseline['discharge']->discharge_summary_provenance_digest,
                        'source_content_digest' => $baseline['discharge']->discharge_coding_source_content_digest,
                        'source_provenance_digest' => $baseline['discharge']->discharge_coding_source_provenance_digest,
                        'coding_content_digest' => $baseline['coding_version']->content_digest, 'baseline_fingerprint' => $baseline['review']->source_fingerprint,
                        'request_correlation_id' => $correlation,
                    ]);

                    return [$request, $request->versions()->create(['actor_user_id' => $actor->id, 'version' => 1, 'request_state' => $request->request_state, 'active_slot' => 'ACTIVE'])];
                });
                $this->audit('clinical.inpatient.summary-addendum.request.submit', $request, $actor);
                $this->receipt($request, $version, $actor, InpatientSummaryAddendumOperationReceipt::SUBMIT, $key, $digest, 'REQUEST', $request->public_id, 1, null, null, null, $correlation);

                return new InpatientSummaryAddendumResult($request);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::SUBMIT, $key, $digest, 'clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null)) {
                return $replay;
            }
            $denial = new InpatientSummaryAddendumDenied('concurrent_change', 'Permintaan berubah bersamaan.');
            $this->recordDenial('clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null, $actor, $denial);
            throw $denial;
        } catch (InpatientSummaryAddendumDenied $denial) {
            if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::SUBMIT, $key, $digest, 'clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null)) {
                return $replay;
            }
            $this->recordDenial('clinical.inpatient.summary-addendum.request.submit', $encounter->public_id, null, $actor, $denial);
            throw $denial;
        }
    }

    public function decide(InpatientSummaryCorrectionRequest $request, User $actor, string $decision, ?string $decisionNote, int $expectedVersion, string $key, ?string $correlation = null): InpatientSummaryAddendumResult
    {
        $action = 'clinical.inpatient.summary-addendum.request.decide';
        $this->authorizePhysician($actor, Capability::INPATIENT_SUMMARY_ADDENDUM_APPROVE, $action, null, $request->public_id);
        $encounterPublicId = $this->requestEncounterPublicId($request);
        $this->validateOrAudit($action, $encounterPublicId, $request->public_id, $actor, fn () => $this->validateMutation($expectedVersion, $key, $correlation));
        $digest = $this->digest(['request' => $request->public_id, 'decision' => $decision, 'decision_note' => $this->text($decisionNote), 'expected_version' => $expectedVersion]);
        if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::DECIDE, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($request, $actor, $decision, $decisionNote, $expectedVersion, $key, $correlation, $digest, $action, $encounterPublicId): InpatientSummaryAddendumResult {
                $encounter = Encounter::query()->whereKey($request->encounter_id)->firstOrFail();
                $this->closed($encounter);
                if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::DECIDE, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
                    return $replay;
                }
                $head = InpatientSummaryCorrectionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($head->version !== $expectedVersion || $head->request_state !== InpatientSummaryCorrectionRequest::STATE_SUBMITTED) {
                    throw new InpatientSummaryAddendumDenied('request_not_submitted', 'Permintaan bukan Submitted current.');
                }
                if ($head->requested_by_user_id === $actor->id) {
                    throw new InpatientSummaryAddendumDenied('self_decision_forbidden', 'Dokter pemohon tidak boleh memutus permintaannya sendiri.', 403);
                }
                if (! in_array($decision, [InpatientSummaryCorrectionRequest::STATE_APPROVED, InpatientSummaryCorrectionRequest::STATE_DENIED], true)) {
                    throw new InpatientSummaryAddendumDenied('validation_failed', 'Keputusan tidak valid.', 422);
                }
                if ($decision === InpatientSummaryCorrectionRequest::STATE_DENIED && $this->text($decisionNote) === null) {
                    throw new InpatientSummaryAddendumDenied('decision_note_required', 'Alasan penolakan wajib diisi.', 422);
                }
                $now = now();
                $version = InpatientSummaryAddendumMutationScope::run(function () use ($head, $actor, $decision, $decisionNote, $now): InpatientSummaryCorrectionRequestVersion {
                    $head->request_state = $decision;
                    $head->active_slot = $decision === InpatientSummaryCorrectionRequest::STATE_APPROVED ? 'ACTIVE' : null;
                    $head->version++;
                    $head->decided_by_user_id = $actor->id;
                    $head->decision_note = $this->text($decisionNote);
                    $head->decided_at = $now;
                    $head->save();

                    return $head->versions()->create(['actor_user_id' => $actor->id, 'version' => $head->version, 'request_state' => $decision, 'active_slot' => $head->active_slot, 'decided_by_user_id' => $actor->id, 'decision_note' => $head->decision_note, 'decided_at' => $now]);
                });
                $this->audit('clinical.inpatient.summary-addendum.request.decide', $head, $actor);
                $this->receipt($head, $version, $actor, InpatientSummaryAddendumOperationReceipt::DECIDE, $key, $digest, 'REQUEST', $head->public_id, $head->version, null, null, null, $correlation);

                return new InpatientSummaryAddendumResult($head);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::DECIDE, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
                return $replay;
            }
            $denial = new InpatientSummaryAddendumDenied('concurrent_change', 'Permintaan berubah bersamaan.');
            $this->recordDenial($action, $encounterPublicId, $request->public_id, $actor, $denial);
            throw $denial;
        } catch (InpatientSummaryAddendumDenied $denial) {
            if ($replay = $this->replayOrAudit($actor, InpatientSummaryAddendumOperationReceipt::DECIDE, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
                return $replay;
            }
            $this->recordDenial($action, $encounterPublicId, $request->public_id, $actor, $denial);
            throw $denial;
        }
    }

    /** @param array<array-key, mixed> $fields */
    public function saveDraft(InpatientSummaryCorrectionRequest $request, User $actor, array $fields, int $expectedVersion, string $key, ?string $correlation = null): InpatientSummaryAddendumResult
    {
        return $this->write($request, $actor, $fields, $expectedVersion, $key, $correlation, false);
    }

    public function finalize(InpatientSummaryCorrectionRequest $request, User $actor, int $expectedVersion, string $key, ?string $correlation = null): InpatientSummaryAddendumResult
    {
        return $this->write($request, $actor, null, $expectedVersion, $key, $correlation, true);
    }

    /**
     * @return array{
     *   source_fingerprint:string,
     *   items:list<array{item_code:string,label:string,is_blocking:bool,is_complete:bool,source_reference:string|null}>,
     *   blockers:list<string>,
     *   addendum_version:InpatientSummaryAddendumVersion|null,
     *   addendum_content_digest:string|null
     * }
     */
    public function snapshot(InpatientSummaryCorrectionRequest $request): array
    {
        $request->loadMissing(['encounter.inpatientDischarge.summary', 'encounter.inpatientDischarge.summaryVersion', 'encounter.inpatientDischarge.codingSourceVersion.source', 'encounter.inpatientRmCoding.versions', 'encounter.inpatientClinicalDocuments', 'encounter.labServiceRequests', 'addendum.versions']);
        $encounter = $request->encounter;
        $discharge = $encounter?->inpatientDischarge;
        $coding = $encounter?->inpatientRmCoding;
        $codingVersion = $coding?->versions->sortByDesc('version')->first();
        $baselineReview = InpatientRmCompletenessReview::query()->find($request->baseline_review_id);
        $addendum = $request->addendum;
        $addendumVersion = $addendum?->versions->sortByDesc('version')->first();
        $drafts = $encounter?->inpatientClinicalDocuments->where('document_state', 'DRAFT')->pluck('public_id')->sort()->values()->all() ?? [];
        $labs = $encounter?->labServiceRequests->where('status', 'ACTIVE')->pluck('public_id')->sort()->values()->all() ?? [];
        $exact = $encounter instanceof Encounter
            && $discharge instanceof InpatientDischarge
            && $coding instanceof InpatientRmCoding
            && $codingVersion instanceof InpatientRmCodingVersion
            && $baselineReview instanceof InpatientRmCompletenessReview
            && $encounter->status === Encounter::STATUS_CLOSED
            && $discharge->id === $request->inpatient_discharge_id
            && $discharge->inpatient_discharge_summary_id === $request->inpatient_discharge_summary_id
            && $discharge->inpatient_discharge_summary_version_id === $request->inpatient_discharge_summary_version_id
            && $discharge->inpatient_discharge_coding_source_version_id === $request->inpatient_discharge_coding_source_version_id
            && $coding->id === $request->inpatient_rm_coding_id
            && $codingVersion->id === $request->inpatient_rm_coding_version_id
            && $baselineReview->id === $request->baseline_review_id
            && $baselineReview->review_state === InpatientRmCompletenessReview::STATE_SIGNED_OFF
            && hash_equals($discharge->discharge_summary_content_digest, $request->summary_content_digest)
            && hash_equals($discharge->discharge_summary_provenance_digest, $request->summary_provenance_digest)
            && hash_equals($discharge->discharge_coding_source_content_digest, $request->source_content_digest)
            && hash_equals($discharge->discharge_coding_source_provenance_digest, $request->source_provenance_digest)
            && hash_equals($codingVersion->content_digest, $request->coding_content_digest)
            && hash_equals($baselineReview->source_fingerprint, $request->baseline_fingerprint);
        $addendumFinal = $addendum instanceof InpatientSummaryAddendum
            && $addendumVersion instanceof InpatientSummaryAddendumVersion
            && $addendum->addendum_state === InpatientSummaryAddendum::STATE_FINAL
            && $addendumVersion->addendum_state === InpatientSummaryAddendum::STATE_FINAL
            && hash_equals($addendum->current_content_digest, $addendumVersion->content_digest);
        $items = [
            $this->item('BASELINE_CLOSURE_EXACT', 'Baseline penutupan tetap terikat tepat', $exact, $baselineReview?->public_id),
            $this->item('ENCOUNTER_STILL_CLOSED', 'Episode tetap Closed', $encounter?->status === Encounter::STATUS_CLOSED, $encounter?->public_id),
            $this->item('APPROVED_REQUEST', 'Permintaan koreksi disetujui', $request->request_state === InpatientSummaryCorrectionRequest::STATE_APPROVED, $request->public_id),
            $this->item('FINAL_SUMMARY_ADDENDUM', 'Addendum ringkasan Final tersedia', $addendumFinal, $addendum?->public_id),
            $this->item('UNCHANGED_BASELINE_CODING_SOURCE', 'Sumber dan coding baseline tetap sama', $exact, $codingVersion?->public_id),
            $this->item('NO_NEW_ACTIVE_LAB_ORDERS', 'Tidak ada order laboratorium aktif baru', $labs === [], null),
            $this->item('NO_NEW_INPATIENT_DRAFTS', 'Tidak ada dokumen rawat inap Draft baru', $drafts === [], null),
        ];
        $blockers = array_values(collect($items)->where('is_complete', false)->pluck('item_code')
            ->map(static fn (mixed $code): string => (string) $code)->all());
        $fingerprint = $this->digest(['definition_version' => InpatientSummaryAddendumReview::DEFINITION_VERSION, 'request_public_id' => $request->public_id, 'baseline_fingerprint' => $request->baseline_fingerprint, 'summary_digest' => $request->summary_content_digest, 'source_digest' => $request->source_content_digest, 'coding_digest' => $request->coding_content_digest, 'addendum_version_public_id' => $addendumVersion?->public_id, 'addendum_digest' => $addendumVersion?->content_digest, 'drafts' => $drafts, 'labs' => $labs]);

        return ['source_fingerprint' => $fingerprint, 'items' => $items, 'blockers' => $blockers, 'addendum_version' => $addendumVersion, 'addendum_content_digest' => $addendumVersion?->content_digest];
    }

    public function saveReview(InpatientSummaryCorrectionRequest $request, User $actor, int $expectedVersion, string $fingerprint, string $key, ?string $correlation = null): InpatientSummaryAddendumResult
    {
        return $this->review($request, $actor, $expectedVersion, $fingerprint, $key, $correlation, false);
    }

    public function signoff(InpatientSummaryCorrectionRequest $request, User $actor, int $expectedVersion, string $fingerprint, string $key, ?string $correlation = null): InpatientSummaryAddendumResult
    {
        return $this->review($request, $actor, $expectedVersion, $fingerprint, $key, $correlation, true);
    }

    private function review(InpatientSummaryCorrectionRequest $request, User $actor, int $expectedVersion, string $fingerprint, string $key, ?string $correlation, bool $signoff): InpatientSummaryAddendumResult
    {
        $operation = $signoff ? InpatientSummaryAddendumOperationReceipt::SIGNOFF : InpatientSummaryAddendumOperationReceipt::REVIEW;
        $action = $signoff ? 'rmik.inpatient.summary-addendum.signoff' : 'rmik.inpatient.summary-addendum.review.save';
        $this->authorizeRmik($actor, $signoff ? Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_SIGNOFF : Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_REVIEW, $action, null, $request->public_id);
        $encounterPublicId = $this->requestEncounterPublicId($request);
        $this->validateOrAudit($action, $encounterPublicId, $request->public_id, $actor, function () use ($expectedVersion, $key, $correlation, $fingerprint): void {
            $this->validateMutation($expectedVersion, $key, $correlation);
            $this->validateDigest($fingerprint, 'Fingerprint sumber');
        });
        $payload = $this->digest(['request' => $request->public_id, 'expected_version' => $expectedVersion, 'source_fingerprint' => $fingerprint, 'signoff' => $signoff]);
        if ($replay = $this->replayOrAudit($actor, $operation, $key, $payload, $action, $encounterPublicId, $request->public_id)) {
            return $replay;
        }
        try {
            return DB::transaction(function () use ($request, $actor, $expectedVersion, $fingerprint, $key, $correlation, $signoff, $operation, $payload, $action, $encounterPublicId): InpatientSummaryAddendumResult {
                $encounter = Encounter::query()->whereKey($request->encounter_id)->firstOrFail();
                $this->closed($encounter);
                if ($replay = $this->replayOrAudit($actor, $operation, $key, $payload, $action, $encounterPublicId, $request->public_id)) {
                    return $replay;
                }
                $head = InpatientSummaryCorrectionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                $addendum = InpatientSummaryAddendum::query()->where('correction_request_id', $head->id)->first();
                $latest = InpatientSummaryAddendumReview::query()->where('correction_request_id', $head->id)->orderByDesc('version')->first();
                if (($latest instanceof InpatientSummaryAddendumReview ? $latest->version : 0) !== $expectedVersion) {
                    throw new InpatientSummaryAddendumDenied('stale_review_version', 'Versi review addendum berubah.');
                }
                $snapshot = $this->snapshot($head->fresh());
                if (! hash_equals($snapshot['source_fingerprint'], $fingerprint)) {
                    throw new InpatientSummaryAddendumDenied('source_binding_stale', 'Sumber review addendum berubah.');
                }
                if ($signoff && (! $latest || $latest->review_state !== InpatientSummaryAddendumReview::STATE_DRAFT || ! hash_equals($latest->source_fingerprint, $fingerprint) || $latest->blocker_count !== 0)) {
                    throw new InpatientSummaryAddendumDenied('review_not_current_complete', 'Review Draft nol-blocker current wajib tersedia.');
                }
                if ($snapshot['blockers'] !== []) {
                    throw new InpatientSummaryAddendumDenied('checklist_incomplete', 'Addendum masih memiliki blocker.');
                }
                if (! $snapshot['addendum_version'] instanceof InpatientSummaryAddendumVersion) {
                    throw new InpatientSummaryAddendumDenied('addendum_version_missing', 'Versi addendum Final tidak tersedia.', 503);
                }
                $new = ($latest instanceof InpatientSummaryAddendumReview ? $latest->version : 0) + 1;
                $state = $signoff ? InpatientSummaryAddendumReview::STATE_SIGNED_OFF : InpatientSummaryAddendumReview::STATE_DRAFT;
                $now = now();
                $review = InpatientSummaryAddendumMutationScope::run(fn () => InpatientSummaryAddendumReview::query()->create(['correction_request_id' => $head->id, 'encounter_id' => $encounter->id, 'addendum_version_id' => $snapshot['addendum_version']->id, 'baseline_review_id' => $head->baseline_review_id, 'reviewed_by_user_id' => $actor->id, 'signed_off_by_user_id' => $signoff ? $actor->id : null, 'definition_version' => InpatientSummaryAddendumReview::DEFINITION_VERSION, 'version' => $new, 'source_fingerprint' => $fingerprint, 'addendum_content_digest' => $snapshot['addendum_content_digest'], 'review_state' => $state, 'blocker_count' => 0, 'reviewed_at' => $now, 'signed_off_at' => $signoff ? $now : null]));
                InpatientSummaryAddendumMutationScope::run(fn () => $review->items()->createMany($snapshot['items']));
                if ($signoff) {
                    $requestVersion = InpatientSummaryAddendumMutationScope::run(function () use ($head, $actor, $now): InpatientSummaryCorrectionRequestVersion {
                        $head->request_state = InpatientSummaryCorrectionRequest::STATE_CONSUMED;
                        $head->active_slot = null;
                        $head->version++;
                        $head->consumed_at = $now;
                        $head->save();

                        return $head->versions()->create(['actor_user_id' => $actor->id, 'version' => $head->version, 'request_state' => $head->request_state, 'active_slot' => null, 'decided_by_user_id' => $head->decided_by_user_id, 'decision_note' => $head->decision_note, 'decided_at' => $head->decided_at, 'consumed_at' => $now]);
                    });
                } else {
                    $requestVersion = $head->versions()->where('version', $head->version)->firstOrFail();
                }
                $this->audit($signoff ? 'rmik.inpatient.summary-addendum.signoff' : 'rmik.inpatient.summary-addendum.review.save', $head, $actor);
                $this->receipt($head, $requestVersion, $actor, $operation, $key, $payload, 'REVIEW', $review->public_id, $new, $addendum, $snapshot['addendum_version'], $review, $correlation);

                return new InpatientSummaryAddendumResult($head, $addendum, $review);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replayOrAudit($actor, $operation, $key, $payload, $action, $encounterPublicId, $request->public_id)) {
                return $replay;
            }
            $denial = new InpatientSummaryAddendumDenied('concurrent_change', 'Review addendum berubah bersamaan.');
            $this->recordDenial($action, $encounterPublicId, $request->public_id, $actor, $denial);
            throw $denial;
        } catch (InpatientSummaryAddendumDenied $denial) {
            if ($replay = $this->replayOrAudit($actor, $operation, $key, $payload, $action, $encounterPublicId, $request->public_id)) {
                return $replay;
            }
            $this->recordDenial($action, $encounterPublicId, $request->public_id, $actor, $denial);
            throw $denial;
        }
    }

    /** @param array<array-key, mixed>|null $fields */
    private function write(InpatientSummaryCorrectionRequest $request, User $actor, ?array $fields, int $expectedVersion, string $key, ?string $correlation, bool $finalize): InpatientSummaryAddendumResult
    {
        $cap = $finalize ? Capability::INPATIENT_SUMMARY_ADDENDUM_FINALIZE : Capability::INPATIENT_SUMMARY_ADDENDUM_WRITE;
        $action = $finalize ? 'clinical.inpatient.summary-addendum.finalize' : 'clinical.inpatient.summary-addendum.draft.save';
        $this->authorizePhysician($actor, $cap, $action, null, $request->public_id);
        $encounterPublicId = $this->requestEncounterPublicId($request);
        $this->validateOrAudit($action, $encounterPublicId, $request->public_id, $actor, fn () => $this->validateMutation($expectedVersion, $key, $correlation));
        $operation = $finalize ? InpatientSummaryAddendumOperationReceipt::FINALIZE : InpatientSummaryAddendumOperationReceipt::SAVE;
        $normalized = $finalize ? null : $this->validateOrAudit(
            $action,
            $encounterPublicId,
            $request->public_id,
            $actor,
            fn (): array => $this->fields($fields ?? []),
        );
        $digest = $this->digest(['request' => $request->public_id, 'expected_version' => $expectedVersion, 'fields' => $normalized, 'finalize' => $finalize]);
        if ($replay = $this->replayOrAudit($actor, $operation, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($request, $actor, $expectedVersion, $key, $correlation, $finalize, $operation, $normalized, $digest, $action, $encounterPublicId): InpatientSummaryAddendumResult {
                $encounter = Encounter::query()->whereKey($request->encounter_id)->firstOrFail();
                $this->closed($encounter);
                if ($replay = $this->replayOrAudit($actor, $operation, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
                    return $replay;
                }
                $headRequest = InpatientSummaryCorrectionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($headRequest->request_state !== InpatientSummaryCorrectionRequest::STATE_APPROVED || $headRequest->requested_by_user_id !== $actor->id) {
                    throw new InpatientSummaryAddendumDenied('request_not_authorized', 'Hanya dokter pemohon yang disetujui dapat menulis addendum.', 403);
                }
                $addendum = InpatientSummaryAddendum::query()->where('correction_request_id', $request->id)->lockForUpdate()->first();
                $current = $addendum instanceof InpatientSummaryAddendum ? $addendum->version : 0;
                if ($current !== $expectedVersion || $addendum?->addendum_state === InpatientSummaryAddendum::STATE_FINAL || ($finalize && $addendum === null)) {
                    throw new InpatientSummaryAddendumDenied('stale_addendum_version', 'Versi addendum berubah.');
                }
                $effective = $finalize ? collect(InpatientSummaryAddendum::FIELDS)->mapWithKeys(fn ($f) => [$f => $addendum->{$f}])->all() : $normalized;
                $content = $this->digest(['definition_version' => InpatientSummaryAddendum::DEFINITION_VERSION, 'fields' => $effective]);
                $state = $finalize ? InpatientSummaryAddendum::STATE_FINAL : InpatientSummaryAddendum::STATE_DRAFT;
                $new = $current + 1;
                $now = $finalize ? now() : null;
                [$addendum, $version] = InpatientSummaryAddendumMutationScope::run(function () use ($addendum, $headRequest, $actor, $effective, $content, $state, $new, $now): array {
                    if ($addendum === null) {
                        $addendum = InpatientSummaryAddendum::query()->create(['correction_request_id' => $headRequest->id, 'encounter_id' => $headRequest->encounter_id, 'author_user_id' => $actor->id, 'definition_version' => InpatientSummaryAddendum::DEFINITION_VERSION, 'addendum_state' => $state, 'version' => 1, ...$effective, 'current_content_digest' => $content]);
                    } else {
                        $addendum->fill([...$effective, 'addendum_state' => $state, 'version' => $new, 'current_content_digest' => $content, 'finalized_by_user_id' => $now ? $actor->id : null, 'finalized_at' => $now]);
                        $addendum->save();
                    }
                    $version = $addendum->versions()->create(['actor_user_id' => $actor->id, 'version' => $new, 'addendum_state' => $state, 'definition_version' => InpatientSummaryAddendum::DEFINITION_VERSION, ...$effective, 'content_digest' => $content, 'finalized_at' => $now]);

                    return [$addendum, $version];
                });
                $requestVersion = $headRequest->versions()->where('version', $headRequest->version)->firstOrFail();
                $this->audit($finalize ? 'clinical.inpatient.summary-addendum.finalize' : 'clinical.inpatient.summary-addendum.draft.save', $headRequest, $actor);
                $this->receipt($headRequest, $requestVersion, $actor, $operation, $key, $digest, 'ADDENDUM', $addendum->public_id, $new, $addendum, $version, null, $correlation);

                return new InpatientSummaryAddendumResult($headRequest, $addendum);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            if ($replay = $this->replayOrAudit($actor, $operation, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
                return $replay;
            }
            $denial = new InpatientSummaryAddendumDenied('concurrent_change', 'Addendum berubah bersamaan.');
            $this->recordDenial($action, $encounterPublicId, $request->public_id, $actor, $denial);
            throw $denial;
        } catch (InpatientSummaryAddendumDenied $denial) {
            if ($replay = $this->replayOrAudit($actor, $operation, $key, $digest, $action, $encounterPublicId, $request->public_id)) {
                return $replay;
            }
            $this->recordDenial($action, $encounterPublicId, $request->public_id, $actor, $denial);
            throw $denial;
        }
    }

    /**
     * @return array{
     *   discharge:InpatientDischarge,summary:InpatientDischargeSummary,summary_version:InpatientDischargeSummaryVersion,
     *   source:InpatientDischargeCodingSource,source_version:InpatientDischargeCodingSourceVersion,
     *   coding:InpatientRmCoding,coding_version:InpatientRmCodingVersion,review:InpatientRmCompletenessReview
     * }
     */
    private function baseline(Encounter $e): array
    {
        $this->closed($e);
        $e->load([
            'patient', 'inpatientDischarge.summary', 'inpatientDischarge.summaryVersion',
            'inpatientDischarge.codingSourceVersion.source', 'inpatientRmCoding.versions.sourceVersion',
            'inpatientRmCompletenessReviews', 'inpatientClinicalDocuments', 'labServiceRequests',
        ]);
        $d = $e->inpatientDischarge;
        $s = $d?->summary;
        $sv = $d?->summaryVersion;
        $srcv = $d instanceof InpatientDischarge ? $d->codingSourceVersion : null;
        $src = $srcv instanceof InpatientDischargeCodingSourceVersion ? $srcv->source : null;
        $c = $e->inpatientRmCoding;
        $cv = $c?->versions->sortByDesc('version')->first();
        $r = $e->inpatientRmCompletenessReviews->where('review_state', InpatientRmCompletenessReview::STATE_SIGNED_OFF)->sortByDesc('version')->first();
        $rmSnapshot = $this->rmService->snapshot($e);
        if (! $d instanceof InpatientDischarge
            || $d->encounter_id !== $e->id
            || $d->encounter_status_after !== Encounter::STATUS_READY_FOR_RM
            || ! $s instanceof InpatientDischargeSummary
            || ! $sv instanceof InpatientDischargeSummaryVersion
            || $s->id !== $d->inpatient_discharge_summary_id
            || $sv->id !== $d->inpatient_discharge_summary_version_id
            || $sv->inpatient_discharge_summary_id !== $s->id
            || $s->summary_state !== InpatientDischargeSummary::STATE_FINAL
            || $sv->summary_state !== InpatientDischargeSummary::STATE_FINAL
            || $s->version !== $sv->version
            || $s->public_id !== $d->discharge_summary_public_id
            || $sv->public_id !== $d->discharge_summary_version_public_id
            || $sv->version !== $d->discharge_summary_version
            || ! hash_equals($this->summaryDigests->content($sv), $d->discharge_summary_content_digest)
            || ! hash_equals($this->summaryDigests->provenance($s, $sv), $d->discharge_summary_provenance_digest)
            || ! $srcv instanceof InpatientDischargeCodingSourceVersion
            || ! $src instanceof InpatientDischargeCodingSource
            || $srcv->id !== $d->inpatient_discharge_coding_source_version_id
            || $srcv->inpatient_discharge_coding_source_id !== $src->id
            || $src->source_state !== InpatientDischargeCodingSource::STATE_FINAL
            || $srcv->source_state !== InpatientDischargeCodingSource::STATE_FINAL
            || $src->version !== $srcv->version
            || $src->public_id !== $d->discharge_coding_source_public_id
            || $srcv->public_id !== $d->discharge_coding_source_version_public_id
            || $srcv->version !== $d->discharge_coding_source_version
            || ! hash_equals($this->sourceDigests->content($srcv), $d->discharge_coding_source_content_digest)
            || ! hash_equals($this->sourceDigests->provenance($src, $srcv), $d->discharge_coding_source_provenance_digest)
            || ! $c instanceof InpatientRmCoding
            || ! $cv instanceof InpatientRmCodingVersion
            || $c->coding_state !== InpatientRmCoding::STATE_FINAL
            || $cv->coding_state !== InpatientRmCoding::STATE_FINAL
            || ! $c->current_is_complete
            || ! $cv->is_complete
            || $c->version !== $cv->version
            || ! hash_equals($c->current_content_digest, $cv->content_digest)
            || $cv->inpatient_discharge_coding_source_version_id !== $srcv->id
            || $cv->source_version_public_id !== $srcv->public_id
            || ! hash_equals($cv->source_content_digest, $d->discharge_coding_source_content_digest)
            || ! hash_equals($cv->source_provenance_digest, $d->discharge_coding_source_provenance_digest)
            || ! $r instanceof InpatientRmCompletenessReview
            || $r->encounter_id !== $e->id
            || $r->inpatient_rm_coding_version_id !== $cv->id
            || $r->coding_version !== $cv->version
            || $r->blocker_count !== 0
            || ! hash_equals($r->coding_digest, $cv->content_digest)
            || $r->source_version_public_id !== $srcv->public_id
            || ! hash_equals($r->source_content_digest, $d->discharge_coding_source_content_digest)
            || ! hash_equals($r->source_provenance_digest, $d->discharge_coding_source_provenance_digest)
            || $rmSnapshot['blockers'] !== []
            || $rmSnapshot['coding_version'] !== $cv->version
            || ! hash_equals($rmSnapshot['coding_digest'], $cv->content_digest)
            || ! hash_equals($rmSnapshot['source_fingerprint'], $r->source_fingerprint)) {
            throw new InpatientSummaryAddendumDenied('baseline_invalid', 'Baseline penutupan tidak lengkap.', 503);
        }

        return ['discharge' => $d, 'summary' => $s, 'summary_version' => $sv, 'source' => $src, 'source_version' => $srcv, 'coding' => $c, 'coding_version' => $cv, 'review' => $r];
    }

    private function closed(Encounter $e): void
    {
        if ($e->status !== Encounter::STATUS_CLOSED || $e->care_setting !== Encounter::CARE_SETTING_INPATIENT || ! $e->patient()->where('is_synthetic', true)->exists()) {
            throw new InpatientSummaryAddendumDenied('encounter_not_closed', 'Episode rawat inap harus Closed.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<string, string|null>
     */
    private function fields(array $fields): array
    {
        $keys = array_keys($fields);
        sort($keys);
        $expected = InpatientSummaryAddendum::FIELDS;
        sort($expected);
        if ($keys !== $expected) {
            throw new InpatientSummaryAddendumDenied('validation_failed', 'Field narasi addendum harus tepat sesuai kontrak.', 422);
        }
        $out = [];
        foreach (InpatientSummaryAddendum::FIELDS as $f) {
            $out[$f] = $this->text($fields[$f] ?? null);
        }
        if (! collect($out)->contains(fn ($v) => $v !== null)) {
            throw new InpatientSummaryAddendumDenied('addendum_empty', 'Sedikitnya satu narasi addendum wajib diisi.', 422);
        }

        return $out;
    }

    private function text(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }

    /** @param array<string, mixed> $v */
    private function digest(array $v): string
    {
        return hash('sha256', CanonicalJson::encode($v));
    }

    /** @return array{item_code:string,label:string,is_blocking:bool,is_complete:bool,source_reference:string|null} */
    private function item(string $code, string $label, bool $complete, ?string $reference): array
    {
        return ['item_code' => $code, 'label' => $label, 'is_blocking' => true, 'is_complete' => $complete, 'source_reference' => $reference];
    }

    private function audit(string $action, InpatientSummaryCorrectionRequest $request, User $actor): void
    {
        if ($this->audit->record(action: $action, resourceType: 'inpatient_summary_correction_request', resourceId: $request->public_id, actor: $actor, outcome: 'SUCCESS', metadata: ['encounter_public_id' => $request->encounter?->public_id, 'request_state' => $request->request_state, 'request_version' => $request->version]) === null) {
            throw new InpatientSummaryAddendumAuditUnavailable('Audit addendum gagal direkam.');
        }
    }

    public function recordAuthorizationDenial(
        string $action,
        string $encounterPublicId,
        ?string $requestPublicId,
        User $actor,
    ): void {
        $this->recordDenial(
            $action,
            $encounterPublicId,
            $requestPublicId,
            $actor,
            new InpatientSummaryAddendumDenied('unauthorized_actor', 'Aksi tidak tersedia untuk peran ini.', 403),
        );
    }

    private function authorizePhysician(User $actor, string $capability, string $action, ?string $encounterPublicId, ?string $requestPublicId): void
    {
        try {
            $this->policy->physician($actor, $capability);
        } catch (AuthorizationException $exception) {
            if ($encounterPublicId !== null) {
                $this->recordAuthorizationDenial($action, $encounterPublicId, $requestPublicId, $actor);
            } elseif ($requestPublicId !== null) {
                $this->recordDenial($action, null, $requestPublicId, $actor, new InpatientSummaryAddendumDenied('unauthorized_actor', 'Aksi tidak tersedia untuk peran ini.', 403));
            }
            throw $exception;
        }
    }

    private function authorizeRmik(User $actor, string $capability, string $action, ?string $encounterPublicId, ?string $requestPublicId): void
    {
        try {
            $this->policy->rmik($actor, $capability);
        } catch (AuthorizationException $exception) {
            if ($encounterPublicId !== null) {
                $this->recordAuthorizationDenial($action, $encounterPublicId, $requestPublicId, $actor);
            } elseif ($requestPublicId !== null) {
                $this->recordDenial($action, null, $requestPublicId, $actor, new InpatientSummaryAddendumDenied('unauthorized_actor', 'Aksi tidak tersedia untuk peran ini.', 403));
            }
            throw $exception;
        }
    }

    private function recordDenial(
        string $action,
        ?string $encounterPublicId,
        ?string $requestPublicId,
        User $actor,
        InpatientSummaryAddendumDenied $denial,
    ): void {
        if ($encounterPublicId === null && ($requestPublicId === null || $denial->reason !== 'unauthorized_actor')) {
            throw new InpatientSummaryAddendumAuditUnavailable('Penolakan addendum tidak memiliki binding episode yang dapat diaudit.');
        }
        $resourceType = $requestPublicId === null ? 'encounter' : 'inpatient_summary_correction_request';
        $resourceId = $requestPublicId ?? $encounterPublicId;
        if ($this->audit->record(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: [
                'encounter_public_id' => $encounterPublicId,
                'request_public_id' => $requestPublicId,
            ],
        ) === null) {
            throw new InpatientSummaryAddendumAuditUnavailable('Penolakan addendum tidak dapat direkam dalam audit.');
        }
    }

    private function validateOrAudit(
        string $action,
        ?string $encounterPublicId,
        ?string $requestPublicId,
        User $actor,
        callable $validation,
    ): mixed {
        try {
            return $validation();
        } catch (InpatientSummaryAddendumDenied $denial) {
            $this->recordDenial($action, $encounterPublicId, $requestPublicId, $actor, $denial);
            throw $denial;
        }
    }

    private function receipt(InpatientSummaryCorrectionRequest $r, InpatientSummaryCorrectionRequestVersion $rv, User $a, string $op, string $key, string $payload, string $type, string $public, int $version, ?InpatientSummaryAddendum $add, ?InpatientSummaryAddendumVersion $addv, ?InpatientSummaryAddendumReview $review, ?string $correlation): void
    {
        InpatientSummaryAddendumMutationScope::run(fn () => InpatientSummaryAddendumOperationReceipt::query()->create(['encounter_id' => $r->encounter_id, 'actor_user_id' => $a->id, 'correction_request_id' => $r->id, 'request_version_id' => $rv->id, 'addendum_id' => $add?->id, 'addendum_version_id' => $addv?->id, 'review_id' => $review?->id, 'baseline_review_id' => $r->baseline_review_id, 'baseline_summary_version_id' => $r->inpatient_discharge_summary_version_id, 'baseline_source_version_id' => $r->inpatient_discharge_coding_source_version_id, 'baseline_coding_version_id' => $r->inpatient_rm_coding_version_id, 'operation' => $op, 'idempotency_key' => mb_strtolower($key), 'payload_digest' => $payload, 'baseline_fingerprint' => $r->baseline_fingerprint, 'addendum_content_digest' => $addv?->content_digest, 'result_type' => $type, 'result_public_id' => $public, 'result_version' => $version, 'request_correlation_id' => $correlation, 'completed_at' => now()]));
    }

    private function replay(User $actor, string $op, string $key, string $digest): ?InpatientSummaryAddendumResult
    {
        $x = InpatientSummaryAddendumOperationReceipt::query()->where('actor_user_id', $actor->id)->where('operation', $op)->where('idempotency_key', mb_strtolower($key))->first();
        if (! $x instanceof InpatientSummaryAddendumOperationReceipt) {
            return null;
        }
        if (! hash_equals($x->payload_digest, $digest)) {
            throw new InpatientSummaryAddendumDenied('idempotency_key_conflict', 'Kunci idempotensi berbeda payload.');
        }
        $r = InpatientSummaryCorrectionRequest::query()->find($x->correction_request_id);
        $rv = InpatientSummaryCorrectionRequestVersion::query()->find($x->request_version_id);
        $a = $x->addendum_id ? InpatientSummaryAddendum::query()->find($x->addendum_id) : null;
        $av = $x->addendum_version_id ? InpatientSummaryAddendumVersion::query()->find($x->addendum_version_id) : null;
        $review = $x->review_id ? InpatientSummaryAddendumReview::query()->find($x->review_id) : null;
        $valid = $r instanceof InpatientSummaryCorrectionRequest
            && $rv instanceof InpatientSummaryCorrectionRequestVersion
            && $x->encounter_id === $r->encounter_id
            && $rv->correction_request_id === $r->id
            && $x->baseline_review_id === $r->baseline_review_id
            && $x->baseline_summary_version_id === $r->inpatient_discharge_summary_version_id
            && $x->baseline_source_version_id === $r->inpatient_discharge_coding_source_version_id
            && $x->baseline_coding_version_id === $r->inpatient_rm_coding_version_id
            && hash_equals($x->baseline_fingerprint, $r->baseline_fingerprint);
        if (in_array($op, [InpatientSummaryAddendumOperationReceipt::SUBMIT, InpatientSummaryAddendumOperationReceipt::DECIDE], true)) {
            $valid = $valid && $x->result_type === 'REQUEST' && $x->result_public_id === $r->public_id
                && $x->result_version === $rv->version && $a === null && $av === null && $review === null;
        } elseif (in_array($op, [InpatientSummaryAddendumOperationReceipt::SAVE, InpatientSummaryAddendumOperationReceipt::FINALIZE], true)) {
            $valid = $valid && $x->result_type === 'ADDENDUM'
                && $a instanceof InpatientSummaryAddendum && $av instanceof InpatientSummaryAddendumVersion
                && $av->addendum_id === $a->id && $a->correction_request_id === $r->id
                && $x->result_public_id === $a->public_id && $x->result_version === $av->version
                && hash_equals((string) $x->addendum_content_digest, $av->content_digest);
        } else {
            $valid = $valid && $x->result_type === 'REVIEW'
                && $a instanceof InpatientSummaryAddendum && $av instanceof InpatientSummaryAddendumVersion
                && $review instanceof InpatientSummaryAddendumReview
                && $review->correction_request_id === $r->id && $review->addendum_version_id === $av->id
                && $review->baseline_review_id === $r->baseline_review_id
                && $review->source_fingerprint !== ''
                && $x->result_public_id === $review->public_id && $x->result_version === $review->version
                && hash_equals((string) $x->addendum_content_digest, $av->content_digest)
                && hash_equals($review->addendum_content_digest, $av->content_digest);
        }
        if (! $valid) {
            throw new InpatientSummaryAddendumDenied('receipt_binding_invalid', 'Receipt addendum tidak lagi terikat tepat pada hasilnya.', 503);
        }

        return new InpatientSummaryAddendumResult($r, $a, $review, true);
    }

    private function replayOrAudit(
        User $actor,
        string $operation,
        string $key,
        string $digest,
        string $action,
        ?string $encounterPublicId,
        ?string $requestPublicId,
    ): ?InpatientSummaryAddendumResult {
        try {
            return $this->replay($actor, $operation, $key, $digest);
        } catch (InpatientSummaryAddendumDenied $denial) {
            $this->recordDenial($action, $encounterPublicId, $requestPublicId, $actor, $denial);
            throw $denial;
        }
    }

    private function validateMutation(int $expectedVersion, string $key, ?string $correlation): void
    {
        if ($expectedVersion < 0) {
            throw new InpatientSummaryAddendumDenied('validation_failed', 'Versi expected tidak boleh negatif.', 422);
        }
        $this->validateKey($key, $correlation);
    }

    private function requestEncounterPublicId(InpatientSummaryCorrectionRequest $request): string
    {
        $publicId = Encounter::query()->whereKey($request->encounter_id)->value('public_id');
        if (! is_string($publicId) || ! Str::isUlid($publicId)) {
            throw new InpatientSummaryAddendumAuditUnavailable('Permintaan addendum tidak memiliki binding episode yang valid.');
        }

        return $publicId;
    }

    private function validateKey(string $key, ?string $correlation): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InpatientSummaryAddendumDenied('validation_failed', 'Kunci idempotensi harus 8-255 karakter aman.', 422);
        }
        if ($correlation !== null && ! Str::isUlid($correlation)) {
            throw new InpatientSummaryAddendumDenied('validation_failed', 'Identitas korelasi tidak valid.', 422);
        }
    }

    private function validateDigest(string $digest, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            throw new InpatientSummaryAddendumDenied('validation_failed', $label.' harus digest SHA-256 lowercase.', 422);
        }
    }
}
