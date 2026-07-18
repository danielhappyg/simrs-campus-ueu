<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreOutpatientEarlyDepartureRequest;
use App\Models\User;
use App\Modules\Clinical\Services\OutpatientEarlyDepartureService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreOutpatientEarlyDepartureController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly OutpatientEarlyDepartureService $departureService,
    ) {}

    public function __invoke(
        StoreOutpatientEarlyDepartureRequest $request,
        Encounter $encounter,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load('session');
        $assignment = $this->assignmentResolver->forEarlyDeparture($user, $encounter);
        /** @var array{request_key: string, confirmed: string|bool, stated_reason: string, communication_summary: string} $payload */
        $payload = $request->validated();

        try {
            $this->departureService->record(
                encounter: $encounter,
                actorAssignment: $assignment,
                requestKey: $payload['request_key'],
                statedReason: $payload['stated_reason'],
                communicationSummary: $payload['communication_summary'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.early-departure.show', $encounter)
            ->with('success', 'Pulang atas permintaan sendiri telah dicatat untuk simulasi.');
    }
}
