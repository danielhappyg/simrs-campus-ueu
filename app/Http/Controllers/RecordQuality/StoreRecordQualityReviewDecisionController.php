<?php

namespace App\Http\Controllers\RecordQuality;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordQuality\StoreRecordQualityReviewDecisionRequest;
use App\Models\User;
use App\Modules\RecordQuality\Enums\RecordQualityReviewAction;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Services\RecordQualityWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreRecordQualityReviewDecisionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly RecordQualityWorkflowService $workflowService,
    ) {}

    public function __invoke(
        StoreRecordQualityReviewDecisionRequest $request,
        RecordQualityReview $review,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter = $review->encounter()->firstOrFail();
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::SupervisionReview);

        if ($review->reviewerAssignment()->value('supervisor_assignment_id') !== $assignment->getKey()) {
            abort(403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validated();
        $action = RecordQualityReviewAction::from((string) $payload['action']);
        /** @var list<array<string, mixed>> $findings */
        $findings = is_array($payload['findings'] ?? null) ? array_values($payload['findings']) : [];

        try {
            $this->workflowService->review(
                review: $review,
                supervisorAssignment: $assignment,
                action: $action,
                requestKey: (string) $payload['request_key'],
                comment: is_string($payload['comment'] ?? null) ? $payload['comment'] : null,
                findings: $findings,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.record-quality.show', $encounter)
            ->with('success', $action === RecordQualityReviewAction::ApproveSimulation
                ? 'Telaah kelengkapan RMIK disetujui untuk simulasi.'
                : 'Perbaikan telaah RMIK diminta dengan temuan yang tercatat.');
    }
}
