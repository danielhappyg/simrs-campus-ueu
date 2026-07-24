<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StoreMedicationDispenseRequest;
use App\Models\User;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationDispensePreparation;
use App\Modules\Clinical\Models\MedicationDispensePreparationReview;
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
        $assignment = $this->assignmentResolver->forEncounterAny(
            $user,
            $medicationRequest->encounter,
            [Capability::Dispense, Capability::SupervisionReview],
        );
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            if ($payload['action'] === 'PREPARE') {
                $result = $this->pharmacyWorkflow->prepareDispense($medicationRequest, $assignment, $payload);
            } else {
                $preparation = MedicationDispensePreparation::query()
                    ->where('public_id', $payload['preparation_public_id'])
                    ->where('medication_request_id', $medicationRequest->getKey())
                    ->firstOrFail();
                $result = $this->pharmacyWorkflow->reviewDispensePreparation(
                    $preparation,
                    $assignment,
                    $payload,
                );
            }
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        $message = match (true) {
            $result instanceof MedicationDispense => "Outcome dispensing {$result->outcome->label()} tersimpan setelah pemeriksaan akhir supervisor.",
            $result instanceof MedicationDispensePreparationReview => $result->action->label().' tersimpan terhadap versi dan hash penyiapan.',
            default => "Penyiapan obat v{$result->version_number} diajukan kepada supervisor farmasi.",
        };

        return redirect()
            ->route('encounters.pharmacy.show', $medicationRequest->encounter)
            ->with('success', $message);
    }
}
