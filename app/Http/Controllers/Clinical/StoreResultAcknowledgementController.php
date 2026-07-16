<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreResultAcknowledgementRequest;
use App\Models\User;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Services\OrderResultService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreResultAcknowledgementController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly OrderResultService $orderResultService,
    ) {}

    public function __invoke(
        StoreResultAcknowledgementRequest $request,
        DiagnosticResult $result,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $result->load('serviceRequest.encounter');
        $encounter = $result->serviceRequest->encounter;
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::MedicalAssessmentWrite);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $this->orderResultService->acknowledgeResult($result, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.order-results.show', $encounter)
            ->with('success', 'Hasil sintetis saat ini telah diakui dengan provenance pengguna dan versi.');
    }
}
