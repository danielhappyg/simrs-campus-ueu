<?php

namespace App\Support\Clinical;

use App\Models\Encounter;
use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\OutpatientRmAmendmentReview;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;

final class OutpatientAmendmentProjection
{
    public function __construct(
        private readonly OutpatientAmendmentActorPolicy $actorPolicy,
        private readonly OutpatientRmAmendmentService $rmAmendmentService,
    ) {}

    /** @return list<array{value: string, label: string, requires_note: bool}> */
    public function reasonOptions(): array
    {
        return array_map(
            static fn (string $code): array => [
                'value' => $code,
                'label' => __(OutpatientPostClosureAmendmentRequest::REASON_LABELS[$code]),
                'requires_note' => $code === OutpatientPostClosureAmendmentRequest::REASON_OTHER,
            ],
            OutpatientPostClosureAmendmentRequest::REASON_CODES,
        );
    }

    /** @return array{can_request_amendment: bool} */
    public function topPermissions(Encounter $encounter, User $actor): array
    {
        $eligibleEvidence = $encounter->outpatientClinicalDocuments
            ->contains(fn ($document): bool => $document->document_state === 'FINAL')
            && $encounter->outpatientRmCompletenessReviews
                ->contains(fn ($review): bool => $review->review_state === OutpatientRmCompletenessReview::STATE_SIGNED_OFF);

        return [
            'can_request_amendment' => $encounter->status === Encounter::STATUS_CLOSED
                && $eligibleEvidence
                && $this->actorPolicy->canPhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_REQUEST),
        ];
    }

    /** @return array{store_amendment_url: string|null} */
    public function topActions(Encounter $encounter, User $actor): array
    {
        $canRequest = $this->topPermissions($encounter, $actor)['can_request_amendment'];

        return [
            'store_amendment_url' => $canRequest
                ? route('pemeriksaan.rawat-jalan.amendments.store', $encounter)
                : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function amendments(Encounter $encounter, User $actor): array
    {
        if (! $this->canReadAmendments($encounter, $actor)) {
            return [];
        }

        $amendments = $encounter->outpatientPostClosureAmendmentRequests
            ->sortByDesc('created_at')
            ->values()
            ->map(function (OutpatientPostClosureAmendmentRequest $request) use ($actor): array {
                $canDecide = $request->request_state === OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED
                    && $request->requested_by_user_id !== $actor->id
                    && $this->actorPolicy->canPhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_APPROVE);
                $isApprovedAuthor = $request->request_state === OutpatientPostClosureAmendmentRequest::STATE_APPROVED
                    && $request->requested_by_user_id === $actor->id
                    && $request->decided_by_user_id !== $actor->id;
                $addendum = $request->addendum;
                $canWrite = $isApprovedAuthor
                    && (! $addendum instanceof OutpatientClinicalDocumentAddendum
                        || $addendum->addendum_state === OutpatientClinicalDocumentAddendum::STATE_DRAFT)
                    && $this->actorPolicy->canPhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_WRITE);
                $canFinalize = $isApprovedAuthor
                    && $addendum instanceof OutpatientClinicalDocumentAddendum
                    && $addendum->addendum_state === OutpatientClinicalDocumentAddendum::STATE_DRAFT
                    && $this->actorPolicy->canPhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_FINALIZE);
                $latestReview = $request->renewedReviews->sortByDesc('version')->first();
                $canRmikReview = $request->request_state === OutpatientPostClosureAmendmentRequest::STATE_CONSUMED
                    && $addendum instanceof OutpatientClinicalDocumentAddendum
                    && $addendum->addendum_state === OutpatientClinicalDocumentAddendum::STATE_FINAL
                    && $this->actorPolicy->canRmik($actor, Capability::RMIK_REVIEW);
                $snapshot = $canRmikReview ? $this->rmAmendmentService->snapshot($request) : null;
                $canSaveReview = $canRmikReview
                    && (! $latestReview instanceof OutpatientRmAmendmentReview
                        || $latestReview->review_state === OutpatientRmAmendmentReview::STATE_DRAFT);
                $canSignoffReview = $latestReview instanceof OutpatientRmAmendmentReview
                    && $latestReview->review_state === OutpatientRmAmendmentReview::STATE_DRAFT
                    && $snapshot !== null
                    && hash_equals($latestReview->source_fingerprint, $snapshot['source_fingerprint'])
                    && $snapshot['blockers'] === []
                    && $this->actorPolicy->canRmik($actor, Capability::RMIK_COMPLETENESS_SIGNOFF);

                return [
                    'public_id' => $request->public_id,
                    'state' => $request->request_state,
                    'version' => $request->version,
                    'reason_code' => $request->reason_code,
                    'reason_label' => __(OutpatientPostClosureAmendmentRequest::REASON_LABELS[$request->reason_code] ?? $request->reason_code),
                    'note' => $request->note,
                    'requested_at' => $request->created_at?->toIso8601String(),
                    'requester_name' => $request->requester?->name,
                    'decided_at' => $request->decided_at?->toIso8601String(),
                    'decider_name' => $request->decidedBy?->name,
                    'decision_note' => $request->decision_note,
                    'original_document' => [
                        'public_id' => $request->originalDocument?->public_id,
                        'document_type' => $request->originalDocument?->document_type,
                        'version' => $request->original_document_version,
                    ],
                    'addendum' => $addendum instanceof OutpatientClinicalDocumentAddendum ? [
                        'public_id' => $addendum->public_id,
                        'state' => $addendum->addendum_state,
                        'version' => $addendum->version,
                        'definition_version' => $addendum->definition_version,
                        'fields' => $addendum->fields,
                        'author_name' => $addendum->author?->name,
                        'finalized_by_name' => $addendum->finalizedBy?->name,
                        'created_at' => $addendum->created_at?->toIso8601String(),
                        'finalized_at' => $addendum->finalized_at?->toIso8601String(),
                    ] : null,
                    'renewed_review' => $latestReview instanceof OutpatientRmAmendmentReview ? [
                        'public_id' => $latestReview->public_id,
                        'state' => $latestReview->review_state,
                        'version' => $latestReview->version,
                        'definition_version' => $latestReview->definition_version,
                        'source_fingerprint' => $latestReview->source_fingerprint,
                        'reviewed_at' => $latestReview->reviewed_at->toIso8601String(),
                        'reviewer_name' => $latestReview->reviewedBy?->name,
                        'signed_off_at' => $latestReview->signed_off_at?->toIso8601String(),
                        'signed_off_by_name' => $latestReview->signedOffBy?->name,
                        'items' => $latestReview->items->map(fn ($item): array => [
                            'item_code' => $item->item_code,
                            'label' => __($item->label),
                            'is_blocking' => $item->is_blocking,
                            'is_complete' => $item->is_complete,
                            'source_reference' => $item->source_reference,
                        ])->values()->all(),
                    ] : null,
                    'current_review_source_fingerprint' => $snapshot['source_fingerprint'] ?? null,
                    'current_review_version' => $latestReview instanceof OutpatientRmAmendmentReview
                        ? $latestReview->version
                        : 0,
                    'permissions' => [
                        'can_decide' => $canDecide,
                        'can_write_addendum' => $canWrite,
                        'can_finalize_addendum' => $canFinalize,
                        'can_save_renewed_review' => $canSaveReview,
                        'can_signoff_renewed_review' => $canSignoffReview,
                    ],
                    'actions' => [
                        'decision_url' => $canDecide
                            ? route('pemeriksaan.rawat-jalan.amendments.decision', $request)
                            : null,
                        'save_addendum_url' => $canWrite
                            ? route('pemeriksaan.rawat-jalan.amendments.addendum.store', $request)
                            : null,
                        'finalize_addendum_url' => $canFinalize
                            ? route('pemeriksaan.rawat-jalan.amendments.addendum.finalize', $request)
                            : null,
                        'save_renewed_review_url' => $canSaveReview
                            ? route('rm.rawat-jalan.amendments.reviews.store', $request)
                            : null,
                        'signoff_renewed_review_url' => $canSignoffReview
                            ? route('rm.rawat-jalan.amendments.signoff', $request)
                            : null,
                    ],
                ];
            })->all();

        return array_values($amendments);
    }

    private function canReadAmendments(Encounter $encounter, User $actor): bool
    {
        if ($actor->is_system_administrator || $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)) {
            return false;
        }
        if ($actor->hasRole(RoleCapabilityMatrix::ROLE_RMIK)) {
            return true;
        }
        if (! $actor->hasRole(RoleCapabilityMatrix::ROLE_PHYSICIAN)) {
            return false;
        }

        return $this->actorPolicy->canPhysician($actor, Capability::CLINICAL_OUTPATIENT_AMENDMENT_APPROVE)
            || $encounter->outpatientPostClosureAmendmentRequests->contains(
                fn (OutpatientPostClosureAmendmentRequest $request): bool => in_array($actor->id, [
                    $request->requested_by_user_id,
                    $request->decided_by_user_id,
                ], true),
            );
    }
}
