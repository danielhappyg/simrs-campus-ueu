<?php

namespace App\Http\Controllers\Claims;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Claims\Services\EClaimWorkflowService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdvanceEClaimSimulationController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly EClaimWorkflowService $workflow,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::enum(EClaimAction::class)],
            'request_key' => ['required', 'ulid'],
        ]);
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::ClaimManage);
        $action = EClaimAction::from((string) $validated['action']);

        try {
            $this->workflow->advance(
                $encounter,
                $assignment,
                $action,
                (string) $validated['request_key'],
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.eclaim-simulation.show', $encounter)
            ->with('success', $action->label().' berhasil dicatat dalam simulasi lokal.');
    }
}
