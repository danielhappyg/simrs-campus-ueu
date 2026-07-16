<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\TerminateAppointmentRequest;
use App\Models\User;
use App\Modules\Patient\Enums\VisitTerminationOutcome;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Services\AppointmentTerminationService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;

class TerminateAppointmentController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly AppointmentTerminationService $terminationService,
    ) {}

    public function __invoke(
        TerminateAppointmentRequest $request,
        AppointmentRegistration $appointment,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $appointment->loadMissing(['encounter.session']);
        $encounter = $appointment->encounter;

        if (! $encounter) {
            abort(409, 'Encounter terencana tidak tersedia.');
        }

        $assignment = $this->assignmentResolver->forEncounter(
            $user,
            $encounter,
            Capability::PatientRegister,
        );
        /** @var array{outcome: string, reason: string} $payload */
        $payload = $request->validated();

        try {
            $this->terminationService->terminate(
                $appointment,
                $assignment,
                VisitTerminationOutcome::from($payload['outcome']),
                $payload['reason'],
            );
        } catch (DomainException) {
            abort(409, 'Kunjungan tidak dapat diterminasi dari status atau waktu saat ini.');
        }

        return to_route('sessions.registration', $appointment->session)
            ->with('success', 'Outcome kunjungan sintetis tercatat tanpa menghapus riwayat.');
    }
}
