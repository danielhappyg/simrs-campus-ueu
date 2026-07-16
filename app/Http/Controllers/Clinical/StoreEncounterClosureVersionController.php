<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreEncounterClosureVersionRequest;
use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Services\EncounterClosureService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreEncounterClosureVersionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly EncounterClosureService $closureService,
    ) {}

    public function __invoke(StoreEncounterClosureVersionRequest $request, Encounter $encounter): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::MedicalAssessmentWrite);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $closure = $this->closureService->save($encounter, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        $submitted = $payload['intent'] === ClinicalSaveIntent::Submit->value;

        return redirect()
            ->route('encounters.closure.show', $encounter)
            ->with('success', $submitted
                ? "Penutupan encounter v{$closure->version_number} diajukan untuk tinjauan supervisor."
                : "Draf penutupan encounter v{$closure->version_number} disimpan.");
    }
}
