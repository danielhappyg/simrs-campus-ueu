<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StorePharmacyInterventionResponseRequest;
use App\Models\User;
use App\Modules\Clinical\Models\PharmacyIntervention;
use App\Modules\Clinical\Services\PharmacyWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StorePharmacyInterventionResponseController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly PharmacyWorkflowService $pharmacyWorkflow,
    ) {}

    public function __invoke(
        StorePharmacyInterventionResponseRequest $request,
        PharmacyIntervention $intervention,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $intervention->load('medicationRequest.encounter');
        $encounter = $intervention->medicationRequest->encounter;
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::PrescriptionWrite);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $this->pharmacyWorkflow->respondToIntervention($intervention, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.pharmacy.show', $encounter)
            ->with('success', 'Tanggapan prescriber tersimpan; permintaan lama tetap dipertahankan.');
    }
}
