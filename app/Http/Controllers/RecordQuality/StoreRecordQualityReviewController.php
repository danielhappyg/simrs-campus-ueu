<?php

namespace App\Http\Controllers\RecordQuality;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordQuality\StoreRecordQualityReviewRequest;
use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\RecordQuality\Services\RecordQualityWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreRecordQualityReviewController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly RecordQualityWorkflowService $workflowService,
    ) {}

    public function __invoke(StoreRecordQualityReviewRequest $request, Encounter $encounter): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::RecordReview);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $review = $this->workflowService->save($encounter, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.record-quality.show', $encounter)
            ->with('success', $payload['intent'] === ClinicalSaveIntent::Submit->value
                ? "Telaah RMIK v{$review->version_number} diajukan kepada supervisor."
                : "Draf telaah RMIK v{$review->version_number} disimpan.");
    }
}
