<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Services\AppointmentCheckInService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppointmentCheckInController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly AppointmentCheckInService $checkInService,
    ) {}

    public function __invoke(Request $request, AppointmentRegistration $appointment): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $appointment->loadMissing(['encounter.session']);
        $encounter = $appointment->encounter;

        if (! $encounter) {
            abort(409, 'Encounter terencana tidak tersedia.');
        }

        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::PatientRegister);
        $checkedIn = $this->checkInService->checkIn($appointment, $assignment);

        return to_route('encounters.show', $checkedIn->encounter)
            ->with('success', 'Check-in tercatat. Pasien sintetis masuk antrean rawat jalan.');
    }
}
