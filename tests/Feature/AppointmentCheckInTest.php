<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class AppointmentCheckInTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_check_in_transitions_encounter_and_releases_nursing_handoff_transactionally(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->where('appointment_code', 'APT-SIM-000001')->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)
            ->post(route('appointments.check-in', $appointment))
            ->assertRedirect(route('encounters.show', $encounter));

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::Arrived, $encounter->refresh()->status);
        $this->assertNotNull($encounter->period_start);
        $this->assertDatabaseHas('queue_events', [
            'encounter_id' => $encounter->getKey(),
            'status' => QueueEventStatus::Waiting->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Registration->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::NursingIntake->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'appointment.checked_in',
            'patient_id' => $appointment->patient_id,
            'encounter_id' => $encounter->getKey(),
        ]);
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();
        $this->actingAs($registrar)
            ->get(route('sessions.registration', $session))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canCreateRegistration', false)
                ->where('appointments.0.status.code', AppointmentStatus::CheckedIn->value)
                ->where('appointments.0.canCheckIn', false)
                ->where('appointments.0.encounter.status.code', EncounterStatus::Arrived->value));
    }

    public function test_repeated_check_in_is_idempotent(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));
        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));

        $this->assertSame(1, QueueEvent::query()->where('encounter_id', $encounter->getKey())->count());
        $this->assertSame(1, EncounterTransition::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('to_status', EncounterStatus::Arrived)
            ->count());
        $this->assertSame(1, WorkTask::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::NursingIntake)
            ->count());
    }

    public function test_wrong_discipline_cannot_call_check_in_endpoint_directly(): void
    {
        $this->seedReferenceOutpatient();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();

        $this->actingAs($nurse)
            ->post(route('appointments.check-in', $appointment))
            ->assertForbidden();

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    public function test_public_queue_projection_excludes_identity_and_clinical_payload(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));
        $response = $this->getJson(route('queue-display', $session));

        $response
            ->assertOk()
            ->assertJsonPath('simulation', true)
            ->assertJsonPath('label', 'SIMULASI — DATA SINTETIS')
            ->assertJsonCount(1, 'entries')
            ->assertJsonStructure([
                'entries' => [['ticket', 'cue', 'status']],
            ]);

        $payload = $response->getContent();
        $this->assertStringNotContainsString('Pasien Sintetis Arunika', $payload);
        $this->assertStringNotContainsString('MR-SIM-000001', $payload);
        $this->assertStringNotContainsString('SYN-NIK-000001', $payload);
        $this->assertStringNotContainsString('Evaluasi keluhan', $payload);
    }

    public function test_encounter_overview_requires_exact_case_assignment_or_session_wide_operator(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $unassigned = User::factory()->create(['email_verified_at' => now()]);
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)
            ->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/show')
                ->where('patient.synthetic', true)
                ->where('encounter.number', 'ENC-SIM-000001')
                ->where('timeline.0.toStatusCode', EncounterStatus::Planned->value));

        $this->actingAs($nurse)->get(route('encounters.show', $encounter))->assertOk();
        $this->actingAs($unassigned)->get(route('encounters.show', $encounter))->assertForbidden();
    }

    public function test_public_queue_is_not_exposed_after_simulation_session_closes(): void
    {
        $this->seedReferenceOutpatient();
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();
        $session->update(['status' => SessionStatus::Completed]);

        $this->getJson(route('queue-display', $session))->assertNotFound();
    }
}
