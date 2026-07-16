<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\StorePharmacyReviewRequest;
use App\Models\User;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Services\PharmacyWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StorePharmacyReviewController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly PharmacyWorkflowService $pharmacyWorkflow,
    ) {}

    public function __invoke(
        StorePharmacyReviewRequest $request,
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
            Capability::PharmacyReview,
        );
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $review = $this->pharmacyWorkflow->submitReview($medicationRequest, $assignment, $payload);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.pharmacy.show', $medicationRequest->encounter)
            ->with('success', "Telaah farmasi v{$review->version_number} tersimpan sebagai penilaian manusia.");
    }
}
