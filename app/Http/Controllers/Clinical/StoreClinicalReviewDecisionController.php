<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreClinicalReviewDecisionRequest;
use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Services\ClinicalDocumentationService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreClinicalReviewDecisionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly ClinicalDocumentationService $documentationService,
    ) {}

    public function __invoke(
        StoreClinicalReviewDecisionRequest $request,
        ClinicalEntryVersion $version,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $version->load('clinicalEntry.encounter');
        $encounter = $version->clinicalEntry->encounter;
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::SupervisionReview);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();
        $decision = ClinicalReviewAction::from((string) $payload['action']);

        try {
            $this->documentationService->review(
                version: $version,
                reviewerAssignment: $assignment,
                decision: $decision,
                requestKey: (string) $payload['request_key'],
                comment: isset($payload['comment']) ? (string) $payload['comment'] : null,
                findings: is_array($payload['findings'] ?? null) ? $payload['findings'] : [],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('clinical-versions.review.show', $version)
            ->with('success', $decision === ClinicalReviewAction::ApproveSimulation
                ? 'Versi disetujui untuk simulasi. Persetujuan berlaku hanya untuk versi dan hash yang ditampilkan.'
                : 'Permintaan perbaikan telah dikirim kepada penulis versi.');
    }
}
