<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreMedicationDispenseRequest;
use App\Models\User;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Services\PharmacyWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreMedicationDispenseController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly PharmacyWorkflowService $pharmacyWorkflow,
    ) {}

    public function __invoke(
        StoreMedicationDispenseRequest $request,
        MedicationRequest $medicationRequest,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $medicationRequest->load('encounter');
        $assignment = $this->assignmentResolver->forEncounter(
            $user,
            $medicationRequest->encounter,
            Capability::Dispense,
        );
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $dispense = $this->pharmacyWorkflow->dispense($medicationRequest, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.pharmacy.show', $medicationRequest->encounter)
            ->with('success', "Outcome dispensing {$dispense->outcome->label()} tersimpan atomik dengan stok sintetis.");
    }
}
