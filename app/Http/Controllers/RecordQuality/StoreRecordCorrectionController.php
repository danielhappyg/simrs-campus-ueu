<?php

namespace App\Http\Controllers\RecordQuality;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordQuality\StoreRecordCorrectionRequest;
use App\Models\User;
use App\Modules\RecordQuality\Models\RecordQualityFinding;
use App\Modules\RecordQuality\Services\RecordQualityWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreRecordCorrectionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly RecordQualityWorkflowService $workflowService,
    ) {}

    public function __invoke(StoreRecordCorrectionRequest $request, RecordQualityFinding $finding): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter = $finding->review()->firstOrFail()->encounter()->firstOrFail();
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::RecordReview);
        /** @var array{request_key: string, reason: string} $payload */
        $payload = $request->validated();

        try {
            $this->workflowService->requestCorrection(
                finding: $finding,
                requesterAssignment: $assignment,
                requestKey: $payload['request_key'],
                reason: $payload['reason'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.record-quality.show', $encounter)
            ->with('success', 'Permintaan koreksi dikirim kepada penulis klinis tanpa mengubah sumber.');
    }
}
