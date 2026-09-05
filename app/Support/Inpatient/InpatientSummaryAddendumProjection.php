<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeSummaryVersion;
use App\Models\InpatientRmCodingVersion;
use App\Models\InpatientRmCompletenessReview;
use App\Models\InpatientSummaryAddendum;
use App\Models\InpatientSummaryAddendumReview;
use App\Models\InpatientSummaryAddendumVersion;
use App\Models\InpatientSummaryCorrectionRequest;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Support\Collection;

final class InpatientSummaryAddendumProjection
{
    public function __construct(private readonly InpatientSummaryAddendumService $service) {}

    /**
     * @return array{
     *   definition_version:string,available:bool,
     *   reason_options:list<array{value:string,label:string,requires_note:bool}>,
     *   can_request:bool,store_url:string|null,requests:list<array<string, mixed>>
     * }
     */
    public function forEncounter(Encounter $encounter, User $actor): array
    {
        $available = $encounter->care_setting === Encounter::CARE_SETTING_INPATIENT
            && $encounter->status === Encounter::STATUS_CLOSED;
        $isPhysician = $this->hasExactOperationalRole($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $isRmik = $this->hasExactOperationalRole($actor, RoleCapabilityMatrix::ROLE_RMIK);
        if (! $isPhysician && ! $isRmik) {
            return $this->emptyProjection();
        }

        $requests = InpatientSummaryCorrectionRequest::query()
            ->where('encounter_id', $encounter->id)
            ->with(['requester', 'addendum.versions', 'reviews.items'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $canRead = $isRmik || (
            $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_APPROVE)
            || $requests->contains(fn (InpatientSummaryCorrectionRequest $request): bool => in_array($actor->id, [
                $request->requested_by_user_id,
                $request->decided_by_user_id,
            ], true))
        );
        $visibleRequests = $canRead ? $requests : collect();
        $canRequest = $available
            && $isPhysician
            && $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_REQUEST)
            && ! $requests->contains(fn (InpatientSummaryCorrectionRequest $request): bool => $request->active_slot === 'ACTIVE');
        $names = $this->actorNames($visibleRequests);
        $summaryVersions = InpatientDischargeSummaryVersion::query()
            ->whereKey($visibleRequests->pluck('inpatient_discharge_summary_version_id')->filter()->all())
            ->pluck('version', 'id');
        $sourceVersions = InpatientDischargeCodingSourceVersion::query()
            ->whereKey($visibleRequests->pluck('inpatient_discharge_coding_source_version_id')->filter()->all())
            ->pluck('version', 'id');
        $codingVersions = InpatientRmCodingVersion::query()
            ->whereKey($visibleRequests->pluck('inpatient_rm_coding_version_id')->filter()->all())
            ->pluck('version', 'id');
        $baselineReviewVersions = InpatientRmCompletenessReview::query()
            ->whereKey($visibleRequests->pluck('baseline_review_id')->filter()->all())
            ->pluck('version', 'id');

        return [
            'definition_version' => InpatientSummaryAddendum::DEFINITION_VERSION,
            'available' => $available,
            'reason_options' => [
                ['value' => InpatientSummaryCorrectionRequest::REASON_CLINICAL_CORRECTION, 'label' => __('Koreksi klinis'), 'requires_note' => false],
                ['value' => InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION, 'label' => __('Informasi belum lengkap'), 'requires_note' => false],
                ['value' => InpatientSummaryCorrectionRequest::REASON_WRONG_ENTRY, 'label' => __('Entri tidak tepat'), 'requires_note' => false],
                ['value' => InpatientSummaryCorrectionRequest::REASON_OTHER, 'label' => __('Lainnya'), 'requires_note' => true],
            ],
            'can_request' => $canRequest,
            'store_url' => $canRequest
                ? route('pemeriksaan.rawat-inap.summary-addenda.requests.store', $encounter->public_id)
                : null,
            'requests' => array_values($visibleRequests->map(function (InpatientSummaryCorrectionRequest $request) use (
                $actor,
                $isPhysician,
                $isRmik,
                $names,
                $summaryVersions,
                $sourceVersions,
                $codingVersions,
                $baselineReviewVersions,
            ): array {
                $latestReview = $request->reviews->sortByDesc('version')->first();
                $snapshot = $this->snapshot($request);
                $isRequester = $request->requested_by_user_id === $actor->id;
                $canDecide = $isPhysician
                    && $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_APPROVE)
                    && ! $isRequester
                    && $request->request_state === InpatientSummaryCorrectionRequest::STATE_SUBMITTED;
                $canWrite = $isPhysician
                    && $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_WRITE)
                    && $isRequester
                    && $request->request_state === InpatientSummaryCorrectionRequest::STATE_APPROVED
                    && $request->addendum?->addendum_state !== InpatientSummaryAddendum::STATE_FINAL;
                $canFinalize = $isPhysician
                    && $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_FINALIZE)
                    && $isRequester
                    && $request->request_state === InpatientSummaryCorrectionRequest::STATE_APPROVED
                    && $request->addendum?->addendum_state === InpatientSummaryAddendum::STATE_DRAFT;
                $canReview = $isRmik
                    && $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_REVIEW)
                    && $request->request_state === InpatientSummaryCorrectionRequest::STATE_APPROVED
                    && $request->addendum?->addendum_state === InpatientSummaryAddendum::STATE_FINAL
                    && $snapshot !== null;
                $canSignoff = $isRmik
                    && $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_SIGNOFF)
                    && $request->request_state === InpatientSummaryCorrectionRequest::STATE_APPROVED
                    && $latestReview?->review_state === InpatientSummaryAddendumReview::STATE_DRAFT
                    && ($snapshot['blockers'] ?? null) === [];

                return [
                    'public_id' => $request->public_id,
                    'state' => $request->request_state,
                    'version' => $request->version,
                    'reason_code' => $request->reason_code,
                    'reason_label' => $this->reasonLabel($request->reason_code),
                    'note' => $request->note,
                    'requested_at' => $request->created_at?->toIso8601String(),
                    'requester_name' => $names->get($request->requested_by_user_id),
                    'decided_at' => $request->decided_at?->toIso8601String(),
                    'decider_name' => $names->get($request->decided_by_user_id),
                    'decision_note' => $request->decision_note,
                    'baseline' => [
                        'discharge_summary_version' => (int) $summaryVersions->get($request->inpatient_discharge_summary_version_id, 0),
                        'coding_source_version' => (int) $sourceVersions->get($request->inpatient_discharge_coding_source_version_id, 0),
                        'coding_version' => (int) $codingVersions->get($request->inpatient_rm_coding_version_id, 0),
                        'review_version' => (int) $baselineReviewVersions->get($request->baseline_review_id, 0),
                        'fingerprint' => $request->baseline_fingerprint,
                    ],
                    'addendum' => $request->addendum === null ? null : [
                        'public_id' => $request->addendum->public_id,
                        'state' => $request->addendum->addendum_state,
                        'version' => $request->addendum->version,
                        'definition_version' => $request->addendum->definition_version,
                        'fields' => collect(InpatientSummaryAddendum::FIELDS)
                            ->mapWithKeys(fn (string $field): array => [
                                $field => (string) ($request->addendum->{$field} ?? ''),
                            ])->all(),
                        'author_name' => $names->get($request->addendum->author_user_id),
                        'finalized_by_name' => $names->get($request->addendum->finalized_by_user_id),
                        'created_at' => $request->addendum->created_at->toIso8601String(),
                        'finalized_at' => $request->addendum->finalized_at?->toIso8601String(),
                    ],
                    'renewed_review' => $latestReview === null ? null : [
                        'public_id' => $latestReview->public_id,
                        'state' => $latestReview->review_state,
                        'version' => $latestReview->version,
                        'definition_version' => $latestReview->definition_version,
                        'source_fingerprint' => $latestReview->source_fingerprint,
                        'reviewed_at' => $latestReview->reviewed_at->toIso8601String(),
                        'reviewer_name' => $names->get($latestReview->reviewed_by_user_id),
                        'signed_off_at' => $latestReview->signed_off_at?->toIso8601String(),
                        'signed_off_by_name' => $names->get($latestReview->signed_off_by_user_id),
                        'items' => $latestReview->items->map(fn ($item): array => [
                            'item_code' => $item->item_code,
                            'label' => __($item->label),
                            'is_blocking' => $item->is_blocking,
                            'is_complete' => $item->is_complete,
                            'source_reference' => $item->source_reference,
                        ])->values()->all(),
                    ],
                    'current_review_source_fingerprint' => $snapshot['source_fingerprint'] ?? null,
                    'current_review_version' => $latestReview instanceof InpatientSummaryAddendumReview ? $latestReview->version : 0,
                    'permissions' => [
                        'can_decide' => $canDecide,
                        'can_write_addendum' => $canWrite,
                        'can_finalize_addendum' => $canFinalize,
                        'can_save_renewed_review' => $canReview,
                        'can_signoff_renewed_review' => $canSignoff,
                    ],
                    'actions' => [
                        'decision_url' => $canDecide
                            ? route('pemeriksaan.rawat-inap.summary-addenda.requests.decide', $request->public_id)
                            : null,
                        'save_addendum_url' => $canWrite
                            ? route('pemeriksaan.rawat-inap.summary-addenda.draft', $request->public_id)
                            : null,
                        'finalize_addendum_url' => $canFinalize
                            ? route('pemeriksaan.rawat-inap.summary-addenda.finalize', $request->public_id)
                            : null,
                        'save_renewed_review_url' => $canReview
                            ? route('rm.rawat-inap.summary-addenda.reviews.store', $request->public_id)
                            : null,
                        'signoff_renewed_review_url' => $canSignoff
                            ? route('rm.rawat-inap.summary-addenda.signoff', $request->public_id)
                            : null,
                    ],
                ];
            })->values()->all()),
        ];
    }

    private function hasExactOperationalRole(User $actor, string $role): bool
    {
        return ! $actor->is_system_administrator
            && ! $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)
            && $actor->hasRole($role);
    }

    /**
     * @return array{
     *   definition_version:string,available:bool,
     *   reason_options:list<array{value:string,label:string,requires_note:bool}>,
     *   can_request:bool,store_url:string|null,requests:list<array<string, mixed>>
     * }
     */
    private function emptyProjection(): array
    {
        return [
            'definition_version' => InpatientSummaryAddendum::DEFINITION_VERSION,
            'available' => false,
            'reason_options' => [],
            'can_request' => false,
            'store_url' => null,
            'requests' => [],
        ];
    }

    /**
     * @return array{
     *   source_fingerprint:string,
     *   items:list<array{item_code:string,label:string,is_blocking:bool,is_complete:bool,source_reference:string|null}>,
     *   blockers:list<string>,
     *   addendum_version:InpatientSummaryAddendumVersion|null,
     *   addendum_content_digest:string|null
     * }|null
     */
    private function snapshot(InpatientSummaryCorrectionRequest $request): ?array
    {
        if (! $request->addendum instanceof InpatientSummaryAddendum
            || $request->addendum->addendum_state !== InpatientSummaryAddendum::STATE_FINAL) {
            return null;
        }

        try {
            return $this->service->snapshot($request);
        } catch (InpatientSummaryAddendumDenied) {
            return null;
        }
    }

    private function reasonLabel(string $reason): string
    {
        return __(match ($reason) {
            InpatientSummaryCorrectionRequest::REASON_CLINICAL_CORRECTION => 'Koreksi klinis',
            InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION => 'Informasi belum lengkap',
            InpatientSummaryCorrectionRequest::REASON_WRONG_ENTRY => 'Entri tidak tepat',
            default => 'Lainnya',
        });
    }

    /**
     * @param  Collection<int, InpatientSummaryCorrectionRequest>  $requests
     * @return Collection<int, string>
     */
    private function actorNames(Collection $requests): Collection
    {
        $ids = collect();
        foreach ($requests as $request) {
            $ids->push($request->requested_by_user_id, $request->decided_by_user_id);
            $ids->push($request->addendum?->author_user_id, $request->addendum?->finalized_by_user_id);
            foreach ($request->reviews as $review) {
                $ids->push($review->reviewed_by_user_id, $review->signed_off_by_user_id);
            }
        }

        return User::query()
            ->whereKey($ids->filter()->unique()->values()->all())
            ->pluck('name', 'id');
    }
}
