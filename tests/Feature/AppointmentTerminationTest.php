<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class AppointmentTerminationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_planned_cancellation_updates_appointment_and_encounter_atomically(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Janji sintetis dibatalkan sebelum kedatangan.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::Cancelled, $encounter->refresh()->status);
        $this->assertNotNull($encounter->period_end);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Registration->value,
            'status' => WorkTaskStatus::Cancelled->value,
        ]);
    }

    public function test_arrived_cancellation_preserves_check_in_and_cancels_only_open_work(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));
        $checkedInAt = $appointment->refresh()->checked_in_at;

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Kunjungan simulasi dibatalkan setelah check-in untuk latihan.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertTrue($appointment->checked_in_at?->equalTo($checkedInAt) ?? false);
        $this->assertSame(EncounterStatus::Cancelled, $encounter->refresh()->status);
        $this->assertNotNull($encounter->period_end);
        $this->assertDatabaseHas('queue_events', [
            'encounter_id' => $encounter->getKey(),
            'status' => QueueEventStatus::Cancelled->value,
            'reason' => 'encounter_cancelled',
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Registration->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::NursingIntake->value,
            'status' => WorkTaskStatus::Cancelled->value,
        ]);
        $this->assertDatabaseHas('encounter_transitions', [
            'encounter_id' => $encounter->getKey(),
            'to_status' => EncounterStatus::Cancelled->value,
            'reason' => 'Kunjungan simulasi dibatalkan setelah check-in untuk latihan.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'appointment.terminated',
            'resource_id' => $appointment->public_id,
            'encounter_id' => $encounter->getKey(),
        ]);

        $publicQueue = $this->getJson(route('queue-display', $appointment->session));
        $publicQueue->assertOk()->assertJsonCount(0, 'entries');
        $publicQueue->assertDontSee(
            'Kunjungan simulasi dibatalkan setelah check-in untuk latihan.',
            false,
        );
    }

    public function test_overdue_planned_appointment_can_be_marked_no_show_without_queue_creation(): void
    {
        Carbon::setTestNow('2026-07-16 10:00:00');
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $appointment->forceFill(['scheduled_at' => now()->subHour()])->save();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'NO_SHOW',
            'reason' => 'Pasien sintetis tidak hadir pada waktu latihan terjadwal.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(AppointmentStatus::NoShow, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::NoShow, $encounter->refresh()->status);
        $this->assertNotNull($encounter->period_end);
        $this->assertSame(0, QueueEvent::query()->where('encounter_id', $encounter->getKey())->count());
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Registration->value,
            'status' => WorkTaskStatus::Cancelled->value,
        ]);
    }

    public function test_repeated_same_outcome_is_idempotent_and_preserves_the_first_reason(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Pembatalan sintetis pertama yang dapat diaudit.',
        ]);
        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Alasan kedua tidak boleh mengganti provenance pertama.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(1, EncounterTransition::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('to_status', EncounterStatus::Cancelled->value)
            ->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'appointment.terminated')
            ->where('resource_id', $appointment->public_id)
            ->count());
        $this->assertDatabaseHas('encounter_transitions', [
            'encounter_id' => $encounter->getKey(),
            'to_status' => EncounterStatus::Cancelled->value,
            'reason' => 'Pembatalan sintetis pertama yang dapat diaudit.',
        ]);
        $this->assertDatabaseMissing('encounter_transitions', [
            'encounter_id' => $encounter->getKey(),
            'reason' => 'Alasan kedua tidak boleh mengganti provenance pertama.',
        ]);
    }

    public function test_conflicting_terminal_outcome_is_rejected_without_mutation(): void
    {
        Carbon::setTestNow('2026-07-16 10:00:00');
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $appointment->forceFill(['scheduled_at' => now()->subHour()])->save();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Outcome terminal pertama harus tetap dipertahankan.',
        ]);

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'NO_SHOW',
            'reason' => 'Outcome terminal yang berbeda harus ditolak.',
        ])->assertStatus(409);

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::Cancelled, $encounter->refresh()->status);
        $this->assertSame(1, EncounterTransition::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('to_status', [EncounterStatus::Cancelled->value, EncounterStatus::NoShow->value])
            ->count());
    }

    public function test_invalid_reason_outcome_and_future_no_show_are_rejected_without_mutation(): void
    {
        Carbon::setTestNow('2026-07-16 08:00:00');
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $appointment->forceFill(['scheduled_at' => now()->addHour()])->save();

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'NO_SHOW',
            'reason' => 'Belum waktunya menandai tidak hadir.',
        ])->assertStatus(409);
        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'pendek',
        ])->assertSessionHasErrors('reason');
        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'DELETED',
            'reason' => 'Outcome yang tidak dikenal harus ditolak oleh validasi.',
        ])->assertSessionHasErrors('outcome');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::Planned, $appointment->encounter()->firstOrFail()->status);
    }

    public function test_registrar_endpoint_rejects_later_clinical_states(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));
        $nursingAssignment = Assignment::query()
            ->where('user_id', $nurse->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $this->app->make(EncounterTransitionService::class)->transition(
            $encounter->refresh(),
            EncounterStatus::InIntake,
            $nursingAssignment,
            'test_fixture_intake_started',
        );

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Registrasi tidak boleh mengakhiri tahap klinis aktif.',
        ])->assertStatus(409);

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
        $this->assertSame(EncounterStatus::InIntake, $encounter->refresh()->status);
    }

    public function test_wrong_discipline_unassigned_and_revoked_registrars_are_denied(): void
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $registrar = $this->registrar();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $unassigned = User::factory()->create(['email_verified_at' => now()]);
        $payload = [
            'outcome' => 'CANCELLED',
            'reason' => 'Permintaan terminasi dengan konteks yang tidak sah.',
        ];

        $this->actingAs($nurse)
            ->post(route('appointments.termination.store', $appointment), $payload)
            ->assertForbidden();
        $this->actingAs($unassigned)
            ->post(route('appointments.termination.store', $appointment), $payload)
            ->assertForbidden();

        Assignment::query()
            ->where('user_id', $registrar->getKey())
            ->get()
            ->filter(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PatientRegister))
            ->each->update(['revoked_at' => now()]);

        $this->actingAs($registrar)
            ->post(route('appointments.termination.store', $appointment), $payload)
            ->assertForbidden();

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    public function test_active_exact_case_registrar_assignment_can_terminate_the_visit(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $assignment = Assignment::query()
            ->where('user_id', $registrar->getKey())
            ->get()
            ->first(fn (Assignment $candidate): bool => $candidate->hasCapability(Capability::PatientRegister));

        $this->assertNotNull($assignment);
        $assignment->update([
            'patient_id' => $appointment->patient_id,
            'encounter_id' => $encounter->getKey(),
        ]);

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Registrar kasus aktif mengakhiri kunjungan sintetis.',
        ])->assertRedirect(route('sessions.registration', $appointment->session));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
    }

    public function test_registration_payload_derives_only_allowed_termination_actions(): void
    {
        Carbon::setTestNow('2026-07-16 10:00:00');
        $this->seedReferenceOutpatient();
        $registrar = $this->registrar();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $appointment->forceFill(['scheduled_at' => now()->subHour()])->save();
        $session = $appointment->session;

        $this->actingAs($registrar)
            ->get(route('sessions.registration', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.0.termination.canCancel', true)
                ->where('appointments.0.termination.canMarkNoShow', true)
                ->where(
                    'appointments.0.termination.url',
                    route('appointments.termination.store', $appointment),
                ));

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment));

        $this->actingAs($registrar)
            ->get(route('sessions.registration', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.0.termination.canCancel', true)
                ->where('appointments.0.termination.canMarkNoShow', false));

        $this->actingAs($registrar)->post(route('appointments.termination.store', $appointment), [
            'outcome' => 'CANCELLED',
            'reason' => 'Terminasi payload sintetis mempertahankan riwayat.',
        ]);

        $this->actingAs($registrar)
            ->get(route('sessions.registration', $session))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.0.termination.canCancel', false)
                ->where('appointments.0.termination.canMarkNoShow', false));
    }

    private function registrar(): User
    {
        return User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
    }
}
