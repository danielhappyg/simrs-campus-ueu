<?php

namespace App\Http\Controllers\Coding;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Coding\Services\CodingWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubmitCodingAssignmentController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly CodingWorkflowService $workflowService,
    ) {}

    public function __invoke(Request $request, CodingAssignment $codingAssignment): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter = $codingAssignment->encounter()->firstOrFail();
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::CodingWrite);

        try {
            $this->workflowService->submit($codingAssignment, $assignment);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.coding.show', ['encounter' => $encounter, 'source' => $codingAssignment->sourcePublicId()])
            ->with('success', 'Draf kode diajukan kepada supervisor RMIK yang terhubung.');
    }
}
