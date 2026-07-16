<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreMedicalAssessmentVersionRequest;
use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Services\ClinicalDocumentationService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreMedicalAssessmentVersionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly ClinicalDocumentationService $documentationService,
    ) {}

    public function __invoke(StoreMedicalAssessmentVersionRequest $request, Encounter $encounter): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::MedicalAssessmentWrite);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $version = $this->documentationService->saveMedicalAssessment($encounter, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        $submitted = $payload['intent'] === ClinicalSaveIntent::Submit->value;

        return redirect()
            ->route('encounters.medical-assessment.show', $encounter)
            ->with('success', $submitted
                ? "Asesmen medis v{$version->version_number} diajukan untuk tinjauan supervisor."
                : "Draf asesmen medis v{$version->version_number} disimpan.");
    }
}
