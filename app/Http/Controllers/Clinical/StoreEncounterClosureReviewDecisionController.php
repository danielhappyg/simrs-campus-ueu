<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreEncounterClosureReviewDecisionRequest;
use App\Models\User;
use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Services\EncounterClosureService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreEncounterClosureReviewDecisionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly EncounterClosureService $closureService,
    ) {}

    public function __invoke(
        StoreEncounterClosureReviewDecisionRequest $request,
        EncounterClosure $closure,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter = $closure->encounter()->firstOrFail();
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::SupervisionReview);

        if ($closure->authorAssignment()->value('supervisor_assignment_id') !== $assignment->getKey()) {
            abort(403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validated();
        $action = EncounterClosureReviewAction::from((string) $payload['action']);
        /** @var list<array<string, mixed>> $findings */
        $findings = is_array($payload['findings'] ?? null) ? array_values($payload['findings']) : [];

        try {
            $this->closureService->review(
                closure: $closure,
                reviewerAssignment: $assignment,
                action: $action,
                requestKey: (string) $payload['request_key'],
                comment: is_string($payload['comment'] ?? null) ? $payload['comment'] : null,
                findings: $findings,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.closure.show', $encounter)
            ->with('success', $action === EncounterClosureReviewAction::ApproveSimulation
                ? 'Penutupan encounter disetujui untuk simulasi dan diteruskan ke telaah RMIK.'
                : 'Perbaikan penutupan encounter diminta dengan temuan yang tercatat.');
    }
}
