<?php

namespace App\Http\Controllers\Coding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coding\StoreCodingReviewDecisionRequest;
use App\Models\User;
use App\Modules\Coding\Enums\CodingReviewAction;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Coding\Services\CodingWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreCodingReviewDecisionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly CodingWorkflowService $workflowService,
    ) {}

    public function __invoke(
        StoreCodingReviewDecisionRequest $request,
        CodingAssignment $codingAssignment,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter = $codingAssignment->encounter()->firstOrFail();
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::SupervisionReview);

        if ($codingAssignment->coderAssignment()->value('supervisor_assignment_id') !== $assignment->getKey()) {
            abort(403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validated();
        $action = CodingReviewAction::from((string) $payload['action']);
        /** @var list<array<string, mixed>> $findings */
        $findings = is_array($payload['findings'] ?? null) ? array_values($payload['findings']) : [];

        try {
            $this->workflowService->review(
                codingAssignment: $codingAssignment,
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
            ->route('encounters.coding.show', ['encounter' => $encounter, 'source' => $codingAssignment->sourcePublicId()])
            ->with('success', $action === CodingReviewAction::ApproveSimulation
                ? 'Kode disetujui untuk skenario simulasi.'
                : 'Perbaikan kode diminta dengan temuan yang tercatat.');
    }
}
