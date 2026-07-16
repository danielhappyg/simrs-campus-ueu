<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\RegisterSyntheticPatientRequest;
use App\Models\User;
use App\Modules\Encounter\Models\ServiceLocation;
use App\Modules\Patient\Services\SyntheticRegistrationService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\RedirectResponse;

class SyntheticRegistrationController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly SyntheticRegistrationService $registrationService,
    ) {}

    public function store(RegisterSyntheticPatientRequest $request, SimulationSession $session): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignment = $this->assignmentResolver->forSession($user, $session, Capability::PatientRegister);
        $data = $request->validated();
        $location = ServiceLocation::query()
            ->where('public_id', $data['location_public_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $this->registrationService->register($session, $assignment, $location, $data);

        return to_route('sessions.registration', $session)
            ->with('success', 'Pasien, janji, dan encounter sintetis berhasil dibuat. Lanjutkan check-in saat pasien tiba.');
    }
}
