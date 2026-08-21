<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Services\HospitalSessionContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HospitalDeskController extends Controller
{
    public function __construct(
        private readonly HospitalSessionContext $sessionContext,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignments = $this->sessionContext->activeAssignments($user);
        $session = $this->sessionContext->resolveSession(
            $assignments,
            is_string($request->query('session')) ? $request->query('session') : null,
        );
        $capabilities = $assignments
            ->when(
                $session,
                fn ($collection) => $collection->filter(
                    fn (Assignment $assignment): bool => $assignment->session_id === $session?->getKey(),
                ),
            )
            ->flatMap(fn (Assignment $assignment): array => $assignment->capabilities ?? [])
            ->unique()
            ->values()
            ->all();

        $patients = $session
            ? SyntheticPatient::query()
                ->where('session_id', $session->getKey())
                ->with('identifiers')
                ->orderBy('full_name')
                ->get()
            : collect();

        $appointments = $session
            ? AppointmentRegistration::query()
                ->where('session_id', $session->getKey())
                ->with(['patient.identifiers', 'encounter.location'])
                ->orderBy('scheduled_at')
                ->get()
            : collect();

        $encounters = $session
            ? Encounter::query()
                ->where('session_id', $session->getKey())
                ->with(['patient.identifiers', 'location', 'appointment'])
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get()
            : collect();

        $returningCount = $patients->filter(
            fn (SyntheticPatient $patient): bool => str_starts_with((string) $patient->fixture_source, 'OPD-POP-RETURNING'),
        )->count();
        $referenceCount = $patients->filter(
            fn (SyntheticPatient $patient): bool => str_starts_with((string) $patient->fixture_source, 'OPD-REF'),
        )->count();
        $checkedIn = $appointments->where('status', AppointmentStatus::CheckedIn)->count();
        $booked = $appointments->where('status', AppointmentStatus::Booked)->count();

        $canRegister = in_array(Capability::PatientRegister->value, $capabilities, true);

        return Inertia::render('hospital/desk', [
            'sessions' => $this->sessionContext->sessionOptions($assignments),
            'selectedSession' => $session ? [
                'publicId' => $session->public_id,
                'code' => $session->code,
                'courseCode' => $session->course_code,
                'scenarioTitle' => $session->scenario->title,
            ] : null,
            'capabilities' => $capabilities,
            'canOpenPendaftaran' => $canRegister,
            'summary' => [
                'population' => $patients->count(),
                'pasienLama' => $returningCount + $referenceCount,
                'pasienBaruPool' => max(0, $patients->count() - $returningCount - $referenceCount),
                'kunjunganTerjadwal' => $booked,
                'antreanPoli' => $checkedIn,
                'encounters' => $encounters->count(),
            ],
            'clinicQueue' => $appointments
                ->filter(fn (AppointmentRegistration $appointment): bool => in_array(
                    $appointment->status,
                    [AppointmentStatus::Booked, AppointmentStatus::CheckedIn],
                    true,
                ))
                ->map(fn (AppointmentRegistration $appointment): array => $this->appointmentRow($appointment))
                ->values()
                ->all(),
            'recentEncounters' => $encounters->map(fn (Encounter $encounter): array => [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'status' => [
                    'code' => $encounter->status->value,
                    'label' => $encounter->status->label(),
                ],
                'patientName' => $encounter->patient->full_name,
                'mrn' => $encounter->patient->identifiers
                    ->firstWhere('type', IdentifierType::MedicalRecordNumber)
                    ?->value,
                'location' => $encounter->location->name,
                'url' => route('encounters.show', $encounter),
            ])->values()->all(),
            'urls' => [
                'pendaftaran' => $session && $canRegister
                    ? route('sessions.registration', $session)
                    : route('desk.pendaftaran'),
                'pemeriksaan' => route('desk.pemeriksaan', $session ? ['session' => $session->code] : []),
                'rekamMedis' => route('desk.rekam-medis', $session ? ['session' => $session->code] : []),
                'apotek' => route('desk.apotek', $session ? ['session' => $session->code] : []),
                'klaim' => route('desk.klaim', $session ? ['session' => $session->code] : []),
                'kerjaSaya' => route('work', $session ? ['session' => $session->code] : []),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentRow(AppointmentRegistration $appointment): array
    {
        $patient = $appointment->patient;
        $encounter = $appointment->encounter;
        $mrn = $patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);

        return [
            'publicId' => $appointment->public_id,
            'appointmentCode' => $appointment->appointment_code,
            'status' => [
                'code' => $appointment->status->value,
                'label' => $appointment->status->label(),
            ],
            'patientName' => $patient->full_name,
            'mrn' => $mrn?->value,
            'location' => $encounter?->location?->name,
            'encounterUrl' => $encounter ? route('encounters.show', $encounter) : null,
            'canCheckIn' => $appointment->status === AppointmentStatus::Booked,
        ];
    }
}
