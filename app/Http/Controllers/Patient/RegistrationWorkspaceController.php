<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\ServiceLocation;
use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Enums\VisitSource;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Patient\Services\PatientSearchService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly PatientSearchService $patientSearch,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, SimulationSession $session): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignment = $this->assignmentResolver->forSession($user, $session, Capability::PatientRegister);
        $query = trim((string) $request->query('q', ''));
        $searchAssignment = $query === ''
            ? $assignment
            : $this->assignmentResolver->forSession($user, $session, Capability::PatientSearch);
        $candidates = $query === '' ? collect() : $this->patientSearch->search($session, $query);

        if ($query !== '') {
            $this->auditRecorder->record(
                action: 'synthetic_patient.searched',
                resourceType: 'synthetic_patient_search',
                actor: $user,
                assignment: $searchAssignment,
                session: $session,
                metadata: [
                    'query_length' => mb_strlen($query),
                    'result_count' => $candidates->count(),
                ],
                request: $request,
            );
        }

        $appointments = AppointmentRegistration::query()
            ->where('session_id', $session->getKey())
            ->with(['patient.identifiers', 'encounter.location'])
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get();

        $locations = ServiceLocation::query()->where('is_active', true)->orderBy('name')->get();

        return Inertia::render('patient/registration', [
            'session' => [
                'publicId' => $session->public_id,
                'code' => $session->code,
                'courseCode' => $session->course_code,
                'scenarioTitle' => $session->scenario()->value('title'),
            ],
            'assignmentPublicId' => $assignment->public_id,
            'searchUrl' => route('sessions.registration', $session),
            'storeUrl' => route('sessions.registrations.store', $session),
            'searchQuery' => $query,
            'candidates' => $candidates->map(fn (SyntheticPatient $patient): array => $this->patientPayload($patient))->values()->all(),
            'appointments' => $appointments->map(fn (AppointmentRegistration $appointment): array => $this->appointmentPayload($appointment))->values()->all(),
            'canCreateRegistration' => $appointments->isEmpty(),
            'locations' => $locations->map(fn (ServiceLocation $location): array => [
                'publicId' => $location->public_id,
                'code' => $location->code,
                'name' => $location->name,
            ])->values()->all(),
            'registrationKey' => (string) Str::ulid(),
            'defaultScheduledAt' => now()->addHour()->timezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
            'options' => [
                'administrativeSex' => collect(AdministrativeSex::cases())->map(fn (AdministrativeSex $sex): array => [
                    'value' => $sex->value,
                    'label' => $sex->label(),
                ])->all(),
                'visitSources' => collect(VisitSource::cases())->map(fn (VisitSource $source): array => [
                    'value' => $source->value,
                    'label' => $source->label(),
                ])->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function patientPayload(SyntheticPatient $patient): array
    {
        $mrn = $patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);

        return [
            'publicId' => $patient->public_id,
            'fullName' => $patient->full_name,
            'birthDate' => $patient->birth_date->toDateString(),
            'administrativeSex' => $patient->administrative_sex->label(),
            'mrn' => $mrn?->value,
            'recordStatus' => $patient->record_status->value,
            'synthetic' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentPayload(AppointmentRegistration $appointment): array
    {
        $patient = $appointment->patient;
        $encounter = $appointment->encounter;
        $mrn = $patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $planned = $appointment->status === AppointmentStatus::Booked
            && $encounter?->status === EncounterStatus::Planned;
        $arrived = $appointment->status === AppointmentStatus::CheckedIn
            && $encounter?->status === EncounterStatus::Arrived;

        return [
            'publicId' => $appointment->public_id,
            'appointmentCode' => $appointment->appointment_code,
            'scheduledAt' => $appointment->scheduled_at->toIso8601String(),
            'visitReason' => $appointment->visit_reason,
            'status' => [
                'code' => $appointment->status->value,
                'label' => $appointment->status->label(),
            ],
            'canCheckIn' => $appointment->status === AppointmentStatus::Booked,
            'checkInUrl' => route('appointments.check-in', $appointment),
            'termination' => [
                'url' => route('appointments.termination.store', $appointment),
                'canCancel' => $planned || $arrived,
                'canMarkNoShow' => $planned && $appointment->scheduled_at->lessThanOrEqualTo(now()),
            ],
            'patient' => [
                'publicId' => $patient->public_id,
                'fullName' => $patient->full_name,
                'birthDate' => $patient->birth_date->toDateString(),
                'mrn' => $mrn?->value,
                'synthetic' => true,
            ],
            'encounter' => $encounter ? [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'status' => [
                    'code' => $encounter->status->value,
                    'label' => $encounter->status->label(),
                ],
                'location' => $encounter->location->name,
                'url' => route('encounters.show', $encounter),
            ] : null,
        ];
    }
}
