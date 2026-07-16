<?php

namespace App\Http\Controllers\Coding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coding\StoreCodingDecisionRequest;
use App\Models\User;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Models\CodingSuggestionCandidate;
use App\Modules\Coding\Models\CodingSuggestionRun;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Services\CodingWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreCodingDecisionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly CodingWorkflowService $workflowService,
    ) {}

    public function __invoke(StoreCodingDecisionRequest $request, CodingSuggestionRun $run): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter = $run->encounter()->firstOrFail();
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::CodingWrite);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();
        $decision = CodingDecisionType::from((string) $payload['decision']);
        $candidate = is_string($payload['candidate_public_id'] ?? null)
            ? CodingSuggestionCandidate::query()->where('public_id', $payload['candidate_public_id'])->first()
            : null;
        $manualConcept = is_string($payload['manual_concept_public_id'] ?? null)
            ? TerminologyConcept::query()->where('public_id', $payload['manual_concept_public_id'])->first()
            : null;

        try {
            $record = $this->workflowService->decide(
                run: $run,
                coderAssignment: $assignment,
                decision: $decision,
                requestKey: (string) $payload['request_key'],
                candidate: $candidate,
                manualConcept: $manualConcept,
                reason: is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
                rationale: is_string($payload['rationale'] ?? null) ? $payload['rationale'] : null,
                changeReason: is_string($payload['change_reason'] ?? null) ? $payload['change_reason'] : null,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.coding.show', ['encounter' => $encounter, 'source' => $run->sourcePublicId()])
            ->with('success', $record->resulting_assignment_id
                ? 'Keputusan koder disimpan sebagai draf. Belum ada kode yang difinalkan.'
                : 'Keputusan dan alasannya disimpan tanpa membuat kode final.');
    }
}
