<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\ServiceLocation;
use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\DuplicateDecision;
use App\Modules\Patient\Enums\IdentifierStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Enums\PatientRecordStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\PatientIdentifier;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationScenario;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class OutpatientRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_assigned_registrar_can_open_workspace_but_nursing_learner_cannot(): void
    {
        $this->seedReferenceOutpatient();
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->where('appointment_code', 'APT-SIM-000001')->firstOrFail();

        $this->actingAs($registrar)
            ->get(route('sessions.registration', [$session, 'q' => 'Arunika']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patient/registration')
                ->where('session.code', 'SIM-RJ-UEU-001')
                ->has('candidates', 1)
                ->where('candidates.0.synthetic', true)
                ->where('canCreateRegistration', false)
                ->where('appointments.0.status.code', AppointmentStatus::Booked->value)
                ->where('appointments.0.canCheckIn', true)
                ->where('appointments.0.checkInUrl', route('appointments.check-in', $appointment))
                ->where('appointments.0.encounter.status.code', EncounterStatus::Planned->value));

        $this->actingAs($nurse)
            ->get(route('sessions.registration', $session))
            ->assertForbidden();

        $denial = AuditEvent::query()
            ->where('action', 'authorization.denied')
            ->where('actor_user_id', $nurse->getKey())
            ->sole();

        $this->assertSame('sessions.registration', $denial->resource_id);
        $this->assertSame('DENIED', $denial->outcome);
        $this->assertSame(['http_method' => 'GET', 'http_status' => 403], $denial->metadata);
        $this->assertNull($denial->ip_hash);
        $this->assertNull($denial->user_agent);
    }

    public function test_registrar_creates_synthetic_identity_appointment_and_planned_encounter_atomically(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();
        $requestKey = (string) Str::ulid();

        $response = $this->actingAs($registrar)->post(
            route('sessions.registrations.store', $session),
            $this->validRegistrationPayload($location, $requestKey),
        );

        $response->assertRedirect(route('sessions.registration', $session));
        $appointment = AppointmentRegistration::query()->where('request_key', $requestKey)->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $nursingAssignment = Assignment::query()
            ->where('session_id', $session->getKey())
            ->where('program', Program::Nursing)
            ->firstOrFail();

        $this->assertTrue($appointment->patient->synthetic_flag);
        $this->assertSame(2, $appointment->patient->identifiers()->count());
        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertSame(EncounterStatus::Planned, $encounter->status);
        $this->assertSame($appointment->patient_id, $nursingAssignment->patient_id);
        $this->assertSame($encounter->getKey(), $nursingAssignment->encounter_id);
        $this->assertDatabaseHas('encounter_transitions', [
            'encounter_id' => $encounter->getKey(),
            'from_status' => null,
            'to_status' => EncounterStatus::Planned->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'synthetic_registration.created',
            'patient_id' => $appointment->patient_id,
            'encounter_id' => $encounter->getKey(),
        ]);
    }

    public function test_possible_duplicate_requires_an_explicit_decision_and_reason(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();
        $this->seedDuplicateCandidate($session);
        $payload = $this->validRegistrationPayload($location, (string) Str::ulid());
        $payload['full_name'] = 'Pasien Sintetis Arunika';
        $payload['birth_date'] = '1992-04-18';

        $this->actingAs($registrar)
            ->post(route('sessions.registrations.store', $session), $payload)
            ->assertSessionHasErrors('duplicate_decision');

        $this->assertSame(1, SyntheticPatient::query()->where('session_id', $session->getKey())->count());
    }

    public function test_create_new_duplicate_decision_preserves_both_records_and_audits_reason(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();
        $this->seedDuplicateCandidate($session);
        $payload = $this->validRegistrationPayload($location, (string) Str::ulid());
        $payload['full_name'] = 'Pasien Sintetis Arunika';
        $payload['birth_date'] = '1992-04-18';
        $payload['duplicate_decision'] = DuplicateDecision::CreateNew->value;
        $payload['duplicate_reason'] = 'Variasi fixture yang sengaja dibedakan.';

        $this->actingAs($registrar)
            ->post(route('sessions.registrations.store', $session), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SyntheticPatient::query()->where('session_id', $session->getKey())->count());
        $this->assertDatabaseHas('synthetic_patients', [
            'session_id' => $session->getKey(),
            'record_status' => 'POTENTIAL_DUPLICATE',
        ]);
        $event = AuditEvent::query()->where('action', 'synthetic_registration.created')->latest('recorded_at')->firstOrFail();
        $this->assertSame(DuplicateDecision::CreateNew->value, $event->metadata['duplicate_decision']);
        $this->assertSame('Variasi fixture yang sengaja dibedakan.', $event->reason);
    }

    public function test_existing_synthetic_patient_can_be_selected_without_copying_identity(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();
        $patient = $this->seedDuplicateCandidate($session);
        $payload = $this->validRegistrationPayload($location, (string) Str::ulid());
        $payload['existing_patient_public_id'] = $patient->public_id;
        $payload['identity_verification_method'] = 'TWO_SYNTHETIC_IDENTIFIERS';
        unset($payload['full_name'], $payload['birth_date'], $payload['administrative_sex']);

        $this->actingAs($registrar)
            ->post(route('sessions.registrations.store', $session), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SyntheticPatient::query()->where('session_id', $session->getKey())->count());
        $this->assertDatabaseHas('appointment_registrations', [
            'patient_id' => $patient->getKey(),
            'duplicate_decision' => DuplicateDecision::UseExisting->value,
        ]);
    }

    public function test_registration_request_key_is_idempotent(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();
        $requestKey = (string) Str::ulid();
        $payload = $this->validRegistrationPayload($location, $requestKey);

        $this->actingAs($registrar)->post(route('sessions.registrations.store', $session), $payload);
        $this->actingAs($registrar)->post(route('sessions.registrations.store', $session), $payload);

        $this->assertSame(1, AppointmentRegistration::query()->where('request_key', $requestKey)->count());
        $appointment = AppointmentRegistration::query()->where('request_key', $requestKey)->firstOrFail();
        $this->assertSame(1, Encounter::query()->where('appointment_registration_id', $appointment->getKey())->count());
    }

    public function test_second_encounter_in_the_same_simulation_session_is_rejected(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();

        $this->actingAs($registrar)->post(
            route('sessions.registrations.store', $session),
            $this->validRegistrationPayload($location, (string) Str::ulid()),
        );

        $secondPayload = $this->validRegistrationPayload($location, (string) Str::ulid());
        $secondPayload['full_name'] = 'Pasien Sintetis Cakrawala';
        $secondPayload['birth_date'] = '1995-05-12';

        $this->actingAs($registrar)
            ->post(route('sessions.registrations.store', $session), $secondPayload)
            ->assertSessionHasErrors('request_key');

        $this->assertSame(1, Encounter::query()->where('session_id', $session->getKey())->count());
    }

    public function test_identity_verification_method_must_match_new_or_existing_identity(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();
        $candidate = $this->seedDuplicateCandidate($session);

        $newPatientPayload = $this->validRegistrationPayload($location, (string) Str::ulid());
        $newPatientPayload['identity_verification_method'] = 'TWO_SYNTHETIC_IDENTIFIERS';

        $this->actingAs($registrar)
            ->post(route('sessions.registrations.store', $session), $newPatientPayload)
            ->assertSessionHasErrors('identity_verification_method');

        $existingPatientPayload = $this->validRegistrationPayload($location, (string) Str::ulid());
        $existingPatientPayload['existing_patient_public_id'] = $candidate->public_id;
        $existingPatientPayload['identity_verification_method'] = 'SCENARIO_BRIEF';
        unset($existingPatientPayload['full_name'], $existingPatientPayload['birth_date'], $existingPatientPayload['administrative_sex']);

        $this->actingAs($registrar)
            ->post(route('sessions.registrations.store', $session), $existingPatientPayload)
            ->assertSessionHasErrors('identity_verification_method');

        $this->assertSame(0, Encounter::query()->where('session_id', $session->getKey())->count());
    }

    public function test_inactive_session_rejects_workspace_and_registration_endpoint(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar, $location] = $this->emptyRegistrationContext();
        $session->update(['status' => SessionStatus::Completed]);

        $this->actingAs($registrar)
            ->get(route('sessions.registration', $session))
            ->assertForbidden();
        $this->actingAs($registrar)
            ->post(
                route('sessions.registrations.store', $session),
                $this->validRegistrationPayload($location, (string) Str::ulid()),
            )
            ->assertForbidden();
    }

    public function test_patient_search_requires_search_capability_in_addition_to_registration_access(): void
    {
        $this->seedReferenceOutpatient();
        [$session, $registrar] = $this->emptyRegistrationContext();
        $assignment = Assignment::query()
            ->where('session_id', $session->getKey())
            ->where('user_id', $registrar->getKey())
            ->where('application_role', ApplicationRole::Registrar)
            ->firstOrFail();
        $assignment->update([
            'capabilities' => [Capability::SessionView->value, Capability::PatientRegister->value],
        ]);

        $this->actingAs($registrar)
            ->get(route('sessions.registration', $session))
            ->assertOk();
        $this->actingAs($registrar)
            ->get(route('sessions.registration', [$session, 'q' => 'Pasien']))
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function validRegistrationPayload(ServiceLocation $location, string $requestKey): array
    {
        return [
            'request_key' => $requestKey,
            'existing_patient_public_id' => null,
            'full_name' => 'Pasien Sintetis Bimasena',
            'birth_date' => '1988-09-09',
            'administrative_sex' => 'MALE',
            'location_public_id' => $location->public_id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'visit_reason' => 'Kunjungan terencana dalam skenario pengujian.',
            'visit_source' => 'SCHEDULED',
            'identity_verification_method' => 'SCENARIO_BRIEF',
            'consent_acknowledged' => true,
            'duplicate_decision' => DuplicateDecision::NoCandidate->value,
            'duplicate_reason' => null,
        ];
    }

    /**
     * @return array{SimulationSession, User, ServiceLocation}
     */
    private function emptyRegistrationContext(): array
    {
        $scenario = SimulationScenario::query()->where('code', 'OPD-REF-001')->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $location = ServiceLocation::query()->where('code', 'POLI-UMUM-SIM')->firstOrFail();
        $session = SimulationSession::query()->create([
            'scenario_id' => $scenario->getKey(),
            'code' => 'SIM-RJ-UEU-EMPTY',
            'course_code' => 'SIMRS-RJ',
            'cohort_code' => 'TEST-2026',
            'environment_mode' => EnvironmentMode::Simulation,
            'status' => SessionStatus::Active,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'facilitator_user_id' => $facilitator->getKey(),
        ]);

        Assignment::query()->create([
            'session_id' => $session->getKey(),
            'user_id' => $registrar->getKey(),
            'program' => Program::Rmik,
            'application_role' => ApplicationRole::Registrar,
            'capabilities' => [
                Capability::SessionView->value,
                Capability::PatientSearch->value,
                Capability::PatientRegister->value,
            ],
            'active_from' => now()->subHour(),
            'active_until' => now()->addDay(),
        ]);
        Assignment::query()->create([
            'session_id' => $session->getKey(),
            'user_id' => $nurse->getKey(),
            'program' => Program::Nursing,
            'application_role' => ApplicationRole::Learner,
            'capabilities' => [Capability::SessionView->value, Capability::IntakeWrite->value],
            'active_from' => now()->subHour(),
            'active_until' => now()->addDay(),
        ]);

        return [$session, $registrar, $location];
    }

    private function seedDuplicateCandidate(SimulationSession $session): SyntheticPatient
    {
        $patient = SyntheticPatient::query()->create([
            'session_id' => $session->getKey(),
            'synthetic_flag' => true,
            'fixture_source' => 'OPD-REF-001-v1-duplicate-candidate',
            'full_name' => 'Pasien Sintetis Arunika',
            'birth_date' => '1992-04-18',
            'administrative_sex' => AdministrativeSex::Female,
            'deceased_flag' => false,
            'record_status' => PatientRecordStatus::Active,
        ]);

        PatientIdentifier::query()->create([
            'patient_id' => $patient->getKey(),
            'type' => IdentifierType::MedicalRecordNumber,
            'system' => PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX.'mrn',
            'value' => 'MR-SIM-CANDIDATE-002',
            'synthetic_flag' => true,
            'valid_from' => now(),
            'status' => IdentifierStatus::Active,
        ]);

        return $patient->load('identifiers');
    }
}
