<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreDiagnosticResultRequest;
use App\Models\User;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Clinical\Services\OrderResultService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreDiagnosticResultController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly OrderResultService $orderResultService,
    ) {}

    public function __invoke(StoreDiagnosticResultRequest $request, ServiceRequest $serviceRequest): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $serviceRequest->load('encounter');
        $assignment = $this->assignmentResolver->forEncounterAny($user, $serviceRequest->encounter, [
            Capability::SessionFacilitate,
            Capability::SupervisionReview,
        ]);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $result = $this->orderResultService->releaseResult($serviceRequest, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.order-results.show', $serviceRequest->encounter)
            ->with('success', "Hasil sintetis v{$result->version_number} dirilis. Versi dan hash tersimpan.");
    }
}
