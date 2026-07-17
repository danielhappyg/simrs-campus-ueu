<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreOutpatientSafetyDispositionRequest;
use App\Models\User;
use App\Modules\Clinical\Enums\OutpatientSafetyDispositionOutcome;
use App\Modules\Clinical\Services\OutpatientSafetyDispositionService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreOutpatientSafetyDispositionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly OutpatientSafetyDispositionService $dispositionService,
    ) {}

    public function __invoke(
        StoreOutpatientSafetyDispositionRequest $request,
        Encounter $encounter,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load('session');
        $assignment = $this->assignmentResolver->forSafetyDisposition($user, $encounter);
        /** @var array{request_key: string, outcome: string, rationale: string} $payload */
        $payload = $request->validated();

        try {
            $this->dispositionService->record(
                encounter: $encounter,
                actorAssignment: $assignment,
                outcome: OutpatientSafetyDispositionOutcome::from($payload['outcome']),
                requestKey: $payload['request_key'],
                rationale: $payload['rationale'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.safety-disposition.show', $encounter)
            ->with('success', 'Keputusan eskalasi manusia telah dicatat untuk simulasi.');
    }
}
