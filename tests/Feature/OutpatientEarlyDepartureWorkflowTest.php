<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\OutpatientEarlyDepartureOutcome;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\OutpatientEarlyDeparture;
use App\Modules\Clinical\Services\OutpatientEarlyDepartureService;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class OutpatientEarlyDepartureWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    #[Test]
    public function patient_requested_departure_has_distinct_terminal_encounter_and_appointment_states(): void
    {
        $encounterStatus = EncounterStatus::tryFrom('DEPARTED_ON_REQUEST');
        $appointmentStatus = AppointmentStatus::tryFrom('DEPARTED_ON_REQUEST');

        $this->assertNotNull($encounterStatus);
        $this->assertSame('Pulang atas permintaan sendiri', $encounterStatus->label());
        $this->assertSame([], $encounterStatus->allowedNextStates());
        $this->assertNotNull($appointmentStatus);
        $this->assertSame('Pulang atas permintaan sendiri', $appointmentStatus->label());
    }

    #[Test]
    public function early_departure_storage_has_the_required_provenance_contract(): void
    {
        $this->assertTrue(Schema::hasTable('outpatient_early_departures'));
        $this->assertTrue(Schema::hasColumns('outpatient_early_departures', [
            'public_id',
            'request_key',
            'session_id',
            'patient_id',
            'encounter_id',
            'actor_user_id',
            'actor_assignment_id',
            'outcome',
            'source_encounter_status',
            'source_snapshot',
            'source_snapshot_hash',
            'stated_reason',
            'communication_summary',
            'occurred_at',
        ]));
    }

    #[Test]
    public function early_departure_is_append_only_and_preserves_exact_context_and_snapshot_provenance(): void
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment))->assertRedirect();

        $nursingAssignment = Assignment::query()
            ->where('user_id', $nurse->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $this->app->make(EncounterTransitionService::class)->transition(
            $encounter->refresh(),
            EncounterStatus::InIntake,
            $nursingAssignment,
            'test_intake_started',
        );

        $actor = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $actor->update(['capabilities' => [...$actor->capabilities, 'early-departure.record']]);
        $snapshot = [
            'encounterStatus' => EncounterStatus::InIntake->value,
            'clinicalSources' => [],
        ];

        $departure = OutpatientEarlyDeparture::query()->create([
            'request_key' => (string) Str::ulid(),
            'session_id' => $encounter->session_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->getKey(),
            'actor_user_id' => $medicalSupervisor->getKey(),
            'actor_assignment_id' => $actor->getKey(),
            'outcome' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture,
            'source_encounter_status' => EncounterStatus::InIntake,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => hash('sha256', CanonicalJson::encode($snapshot)),
            'stated_reason' => 'Pasien sintetis meminta mengakhiri kunjungan lebih awal.',
            'communication_summary' => 'Supervisor mencatat bahwa informasi simulasi telah disampaikan kepada pasien sintetis.',
            'occurred_at' => now(),
        ]);

        $this->assertTrue(Str::isUlid($departure->public_id));
        $this->assertSame($encounter->getKey(), $departure->encounter->getKey());
        $this->assertSame($actor->getKey(), $departure->actorAssignment->getKey());
        $this->assertSame(OutpatientEarlyDepartureOutcome::PatientRequestedDeparture, $departure->outcome);
        $this->assertSame(EncounterStatus::InIntake, $departure->source_encounter_status);
        $this->assertSame($snapshot, $departure->source_snapshot);

        $this->expectException(DomainException::class);
        $departure->update(['stated_reason' => 'Riwayat tidak boleh ditimpa.']);
    }

    #[Test]
    public function early_departure_rejects_a_snapshot_that_invents_a_clinical_source(): void
    {
        [, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $actor = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $snapshot = [
            'encounterStatus' => EncounterStatus::InIntake->value,
            'clinicalSources' => [[
                'documentType' => 'NURSING_INTAKE',
                'entryPublicId' => (string) Str::ulid(),
                'versionPublicId' => (string) Str::ulid(),
                'versionNumber' => 1,
                'status' => 'DRAFT',
                'contentHash' => str_repeat('a', 64),
            ]],
        ];

        $this->expectException(DomainException::class);
        OutpatientEarlyDeparture::query()->create([
            'request_key' => (string) Str::ulid(),
            'session_id' => $encounter->session_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->getKey(),
            'actor_user_id' => $medicalSupervisor->getKey(),
            'actor_assignment_id' => $actor->getKey(),
            'outcome' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture,
            'source_encounter_status' => EncounterStatus::InIntake,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => hash('sha256', CanonicalJson::encode($snapshot)),
            'stated_reason' => 'Pasien sintetis meminta mengakhiri kunjungan lebih awal.',
            'communication_summary' => 'Supervisor mencatat komunikasi faktual dalam simulasi.',
            'occurred_at' => now(),
        ]);
    }

    #[Test]
    public function early_departure_snapshot_cannot_omit_a_current_clinical_version(): void
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment))->assertRedirect();
        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $this->nursingDraftPayload())
            ->assertRedirect();

        $actor = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $snapshot = [
            'encounterStatus' => EncounterStatus::InIntake->value,
            'clinicalSources' => [],
        ];

        $this->expectException(DomainException::class);
        OutpatientEarlyDeparture::query()->create([
            'request_key' => (string) Str::ulid(),
            'session_id' => $encounter->session_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->getKey(),
            'actor_user_id' => $medicalSupervisor->getKey(),
            'actor_assignment_id' => $actor->getKey(),
            'outcome' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture,
            'source_encounter_status' => EncounterStatus::InIntake,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => hash('sha256', CanonicalJson::encode($snapshot)),
            'stated_reason' => 'Pasien sintetis meminta mengakhiri kunjungan lebih awal.',
            'communication_summary' => 'Supervisor mencatat komunikasi faktual dalam simulasi.',
            'occurred_at' => now(),
        ]);
    }

    #[Test]
    public function authorized_medical_supervisor_records_departure_transactionally_without_erasing_prior_work(): void
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment))->assertRedirect();
        $nursingAssignment = Assignment::query()
            ->where('user_id', $nurse->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $this->app->make(EncounterTransitionService::class)->transition(
            $encounter->refresh(),
            EncounterStatus::InIntake,
            $nursingAssignment,
            'test_intake_started',
        );
        $actor = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();

        $this->assertTrue($actor->hasCapability('early-departure.record'));

        $checkedInAt = $appointment->refresh()->checked_in_at;
        $requestKey = (string) Str::ulid();
        $departure = $this->app->make(OutpatientEarlyDepartureService::class)->record(
            encounter: $encounter->refresh(),
            actorAssignment: $actor,
            requestKey: $requestKey,
            statedReason: 'Pasien sintetis meminta pulang sebelum alur rutin selesai.',
            communicationSummary: 'Supervisor mencatat informasi simulasi dan tindak lanjut faktual telah disampaikan.',
        );

        $this->assertSame($requestKey, $departure->request_key);
        $this->assertSame(EncounterStatus::DepartedOnRequest, $encounter->refresh()->status);
        $this->assertNotNull($encounter->period_end);
        $this->assertSame(AppointmentStatus::DepartedOnRequest, $appointment->refresh()->status);
        $this->assertTrue($appointment->checked_in_at?->equalTo($checkedInAt) ?? false);
        $this->assertDatabaseHas('queue_events', [
            'encounter_id' => $encounter->getKey(),
            'status' => QueueEventStatus::Completed->value,
            'reason' => 'patient_requested_departure',
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Registration->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::MedicalAssessment->value,
            'status' => WorkTaskStatus::Cancelled->value,
        ]);
        $this->assertSame(1, EncounterTransition::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('to_status', EncounterStatus::DepartedOnRequest->value)
            ->where('reason', 'patient_requested_departure_recorded')
            ->count());

        $audit = AuditEvent::query()
            ->where('action', 'clinical.outpatient_early_departure_recorded')
            ->where('resource_id', $departure->public_id)
            ->firstOrFail();
        $this->assertSame(EncounterStatus::InIntake->value, $audit->metadata['source_encounter_status']);
        $this->assertSame(EncounterStatus::DepartedOnRequest->value, $audit->metadata['target_encounter_status']);
        $this->assertArrayNotHasKey('stated_reason', $audit->metadata);
        $this->assertArrayNotHasKey('communication_summary', $audit->metadata);
        $this->assertStringNotContainsString('Pasien sintetis meminta pulang', json_encode($audit->metadata));

        $again = $this->app->make(OutpatientEarlyDepartureService::class)->record(
            encounter: $encounter->refresh(),
            actorAssignment: $actor,
            requestKey: $requestKey,
            statedReason: 'Pasien sintetis meminta pulang sebelum alur rutin selesai.',
            communicationSummary: 'Supervisor mencatat informasi simulasi dan tindak lanjut faktual telah disampaikan.',
        );

        $this->assertSame($departure->getKey(), $again->getKey());
        $this->assertSame(1, OutpatientEarlyDeparture::query()->where('encounter_id', $encounter->getKey())->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'clinical.outpatient_early_departure_recorded')
            ->where('resource_id', $departure->public_id)
            ->count());
        $this->assertSame(0, QueueEvent::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('status', [
                QueueEventStatus::Waiting->value,
                QueueEventStatus::Called->value,
                QueueEventStatus::InService->value,
                QueueEventStatus::Held->value,
            ])
            ->count());
    }

    #[Test]
    public function departure_captures_the_exact_current_clinical_source_version_and_hash(): void
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment))->assertRedirect();
        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $this->nursingDraftPayload())
            ->assertRedirect();

        $entry = ClinicalEntry::query()
            ->with('latestVersion')
            ->where('encounter_id', $encounter->getKey())
            ->sole();
        $version = $entry->latestVersion;
        $this->assertNotNull($version);
        $actor = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();

        $departure = $this->app->make(OutpatientEarlyDepartureService::class)->record(
            encounter: $encounter->refresh(),
            actorAssignment: $actor,
            requestKey: (string) Str::ulid(),
            statedReason: 'Pasien sintetis meminta pulang setelah asesmen keperawatan dimulai.',
            communicationSummary: 'Supervisor mencatat komunikasi faktual dan status dokumentasi simulasi yang belum selesai.',
        );

        $this->assertSame(EncounterStatus::InIntake->value, $departure->source_snapshot['encounterStatus']);
        $this->assertCount(1, $departure->source_snapshot['clinicalSources']);
        $source = $departure->source_snapshot['clinicalSources'][0];
        $this->assertSame($entry->public_id, $source['entryPublicId']);
        $this->assertSame($version->public_id, $source['versionPublicId']);
        $this->assertSame($version->version_number, $source['versionNumber']);
        $this->assertSame($version->content_hash, $source['contentHash']);
        $this->assertSame(
            hash('sha256', CanonicalJson::encode($departure->source_snapshot)),
            $departure->source_snapshot_hash,
        );
    }

    #[Test]
    public function session_facilitator_can_record_early_departure_for_the_active_case(): void
    {
        [, $encounter] = $this->startIntakeFixture();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $assignment = Assignment::query()
            ->where('user_id', $facilitator->getKey())
            ->where('session_id', $encounter->session_id)
            ->whereNull('patient_id')
            ->whereNull('encounter_id')
            ->firstOrFail();

        $this->assertTrue($assignment->hasCapability('early-departure.record'));
        $departure = $this->app->make(OutpatientEarlyDepartureService::class)->record(
            encounter: $encounter,
            actorAssignment: $assignment,
            requestKey: (string) Str::ulid(),
            statedReason: 'Pasien sintetis meminta mengakhiri kunjungan sebelum alur selesai.',
            communicationSummary: 'Fasilitator mencatat komunikasi faktual tanpa memberi rekomendasi klinis.',
        );

        $this->assertSame($facilitator->getKey(), $departure->actor_user_id);
        $this->assertSame($assignment->getKey(), $departure->actor_assignment_id);
        $this->assertSame(EncounterStatus::DepartedOnRequest, $encounter->refresh()->status);
    }

    #[Test]
    public function a_competing_departure_request_cannot_create_a_second_terminal_record(): void
    {
        [, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $actor = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $service = $this->app->make(OutpatientEarlyDepartureService::class);

        $service->record(
            encounter: $encounter,
            actorAssignment: $actor,
            requestKey: (string) Str::ulid(),
            statedReason: 'Pasien sintetis meminta pulang sebelum alur rutin selesai.',
            communicationSummary: 'Supervisor mencatat komunikasi faktual untuk simulasi keberangkatan awal.',
        );

        try {
            $service->record(
                encounter: $encounter->refresh(),
                actorAssignment: $actor,
                requestKey: (string) Str::ulid(),
                statedReason: 'Permintaan kedua tidak boleh membuat catatan terminal baru.',
                communicationSummary: 'Permintaan kedua ini sengaja berbeda untuk menguji konflik transaksi.',
            );
            $this->fail('A competing early-departure request must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('This encounter already has a recorded early departure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outpatient_early_departures', 1);
        $this->assertSame(EncounterStatus::DepartedOnRequest, $encounter->refresh()->status);
    }

    #[Test]
    public function downstream_transition_failure_rolls_back_departure_appointment_queue_tasks_and_audit(): void
    {
        [$appointment, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $actor = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $queueStateBefore = QueueEvent::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('id')
            ->get(['id', 'status', 'reason', 'ended_at'])
            ->map(fn (QueueEvent $event): array => [
                'id' => $event->getKey(),
                'status' => $event->status->value,
                'reason' => $event->reason,
                'endedAt' => $event->ended_at?->toIso8601String(),
            ])
            ->all();
        $taskStateBefore = WorkTask::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('id')
            ->get(['id', 'status', 'completed_at'])
            ->map(fn (WorkTask $task): array => [
                'id' => $task->getKey(),
                'status' => $task->status->value,
                'completedAt' => $task->completed_at?->toIso8601String(),
            ])
            ->all();

        $this->app->instance(
            EncounterTransitionService::class,
            new class(app(AuditRecorder::class)) extends EncounterTransitionService
            {
                public function transition(
                    Encounter $encounter,
                    EncounterStatus $target,
                    Assignment $actorAssignment,
                    ?string $reason = null,
                ): Encounter {
                    throw new DomainException('Forced early-departure transition failure.');
                }
            },
        );

        try {
            app(OutpatientEarlyDepartureService::class)->record(
                encounter: $encounter,
                actorAssignment: $actor,
                requestKey: (string) Str::ulid(),
                statedReason: 'Pasien sintetis meminta pulang sebelum alur rutin selesai.',
                communicationSummary: 'Supervisor mencatat komunikasi faktual sebelum kegagalan transaksi dipaksakan.',
            );
            $this->fail('The forced downstream failure must escape the transaction.');
        } catch (DomainException $exception) {
            $this->assertSame('Forced early-departure transition failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outpatient_early_departures', 0);
        $this->assertSame(EncounterStatus::InIntake, $encounter->refresh()->status);
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
        $this->assertSame(
            $queueStateBefore,
            QueueEvent::query()
                ->where('encounter_id', $encounter->getKey())
                ->orderBy('id')
                ->get(['id', 'status', 'reason', 'ended_at'])
                ->map(fn (QueueEvent $event): array => [
                    'id' => $event->getKey(),
                    'status' => $event->status->value,
                    'reason' => $event->reason,
                    'endedAt' => $event->ended_at?->toIso8601String(),
                ])
                ->all(),
        );
        $this->assertSame(
            $taskStateBefore,
            WorkTask::query()
                ->where('encounter_id', $encounter->getKey())
                ->orderBy('id')
                ->get(['id', 'status', 'completed_at'])
                ->map(fn (WorkTask $task): array => [
                    'id' => $task->getKey(),
                    'status' => $task->status->value,
                    'completedAt' => $task->completed_at?->toIso8601String(),
                ])
                ->all(),
        );
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'clinical.outpatient_early_departure_recorded',
        ]);
    }

    #[Test]
    public function authorized_workspace_records_an_explicit_confirmation_and_becomes_read_only(): void
    {
        [$appointment, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $url = "/encounters/{$encounter->public_id}/early-departure";

        $this->actingAs($medicalSupervisor)
            ->get($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/early-departure')
                ->where('boundary.classification', 'SIMULASI — DATA SINTETIS')
                ->where('boundary.clinicalRecommendation', false)
                ->where('patient.allergyStatus', 'Belum dinilai')
                ->where('authorization.canRecord', true)
                ->where('source.encounterStatus.code', EncounterStatus::InIntake->value)
                ->where('departure', null)
                ->where('form.requestKey', fn (string $value): bool => Str::isUlid($value)));

        $requestKey = (string) Str::ulid();
        $this->actingAs($medicalSupervisor)
            ->post($url, [
                'request_key' => $requestKey,
                'confirmed' => '1',
                'stated_reason' => 'Pasien sintetis meminta pulang sebelum alur rutin selesai.',
                'communication_summary' => 'Supervisor mencatat informasi simulasi dan tindak lanjut faktual telah disampaikan.',
            ])
            ->assertRedirect($url);

        $this->assertSame(AppointmentStatus::DepartedOnRequest, $appointment->refresh()->status);
        $this->actingAs($medicalSupervisor)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/early-departure')
                ->where('authorization.canRecord', false)
                ->where('form.requestKey', null)
                ->where('departure.outcome.code', OutpatientEarlyDepartureOutcome::PatientRequestedDeparture->value)
                ->where('departure.statedReason', 'Pasien sintetis meminta pulang sebelum alur rutin selesai.')
                ->where('departure.communicationSummary', 'Supervisor mencatat informasi simulasi dan tindak lanjut faktual telah disampaikan.')
                ->where('departure.actor', $medicalSupervisor->name));
    }

    #[Test]
    public function workspace_denies_unqualified_roles_and_requires_an_eligible_clinical_source_state(): void
    {
        [$appointment, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $url = "/encounters/{$encounter->public_id}/early-departure";
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $medicalLearner = User::query()->where('email', 'mahasiswa.kedokteran@example.invalid')->firstOrFail();
        $rmikSupervisor = User::query()->where('email', 'supervisor.rmik@example.invalid')->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->get($url)->assertForbidden();
        $this->actingAs($medicalLearner)->get($url)->assertForbidden();
        $this->actingAs($rmikSupervisor)->get($url)->assertForbidden();
        $this->actingAs($facilitator)->get($url)->assertOk();

        $assignment = Assignment::query()
            ->where('user_id', $medicalSupervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $assignment->update(['revoked_at' => now()]);
        $this->actingAs($medicalSupervisor)->get($url)->assertForbidden();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
    }

    #[Test]
    public function workspace_rejects_direct_access_before_clinical_service_starts(): void
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment))->assertRedirect();

        $this->actingAs($medicalSupervisor)
            ->get(route('encounters.early-departure.show', $encounter))
            ->assertStatus(409);
        $this->assertSame(EncounterStatus::Arrived, $encounter->refresh()->status);
        $this->assertDatabaseCount('outpatient_early_departures', 0);
    }

    #[Test]
    public function workspace_rejects_missing_confirmation_and_attributed_text(): void
    {
        [, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $url = "/encounters/{$encounter->public_id}/early-departure";

        $this->actingAs($medicalSupervisor)
            ->post($url, [
                'request_key' => (string) Str::ulid(),
                'confirmed' => false,
                'stated_reason' => 'pendek',
                'communication_summary' => '',
            ])
            ->assertSessionHasErrors(['confirmed', 'stated_reason', 'communication_summary']);

        $this->assertDatabaseCount('outpatient_early_departures', 0);
        $this->assertSame(EncounterStatus::InIntake, $encounter->refresh()->status);
    }

    #[Test]
    public function encounter_overview_exposes_only_the_server_authorized_early_departure_action(): void
    {
        [, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $expectedUrl = route('encounters.early-departure.show', $encounter);

        $this->actingAs($medicalSupervisor)
            ->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment.canRecordEarlyDeparture', true)
                ->where('urls.earlyDeparture', $expectedUrl));

        $this->actingAs($registrar)
            ->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment.canRecordEarlyDeparture', false)
                ->where('urls.earlyDeparture', null));

        $registrarAssignment = Assignment::query()
            ->where('user_id', $registrar->getKey())
            ->where('session_id', $encounter->session_id)
            ->whereNull('patient_id')
            ->whereNull('encounter_id')
            ->firstOrFail();
        $registrarAssignment->update([
            'capabilities' => [...$registrarAssignment->capabilities, 'early-departure.record'],
        ]);

        $this->actingAs($registrar)
            ->get(route('encounters.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment.canRecordEarlyDeparture', false)
                ->where('urls.earlyDeparture', null));
        $this->actingAs($registrar)
            ->get($expectedUrl)
            ->assertForbidden();
    }

    #[Test]
    public function longitudinal_record_projects_the_departure_without_protected_free_text(): void
    {
        [, $encounter, $medicalSupervisor] = $this->startIntakeFixture();
        $url = route('encounters.early-departure.store', $encounter);
        $reason = 'Alasan sintetis unik yang hanya boleh tampil di workspace terlindungi.';
        $summary = 'Ringkasan komunikasi sintetis unik yang tidak boleh disalin ke kartu linimasa.';

        $this->actingAs($medicalSupervisor)
            ->post($url, [
                'request_key' => (string) Str::ulid(),
                'confirmed' => '1',
                'stated_reason' => $reason,
                'communication_summary' => $summary,
            ])
            ->assertRedirect();

        $response = $this->actingAs($medicalSupervisor)
            ->get(route('encounters.timeline.show', $encounter));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', fn (mixed $events): bool => $this->containsSafeEarlyDeparture($events)),
        );
        $response->assertDontSee($reason)->assertDontSee($summary);
    }

    /** @return array{AppointmentRegistration, Encounter, User} */
    private function startIntakeFixture(): array
    {
        $this->seedReferenceOutpatient();
        $appointment = AppointmentRegistration::query()->firstOrFail();
        $encounter = $appointment->encounter()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $appointment))->assertRedirect();
        $nursingAssignment = Assignment::query()
            ->where('user_id', $nurse->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->firstOrFail();
        $this->app->make(EncounterTransitionService::class)->transition(
            $encounter->refresh(),
            EncounterStatus::InIntake,
            $nursingAssignment,
            'test_intake_started',
        );

        return [$appointment, $encounter->refresh(), $medicalSupervisor];
    }

    private function containsSafeEarlyDeparture(mixed $events): bool
    {
        if ($events instanceof Collection) {
            $events = $events->all();
        }

        if (! is_array($events)) {
            return false;
        }

        return collect($events)->contains(fn (mixed $event): bool => is_array($event)
            && data_get($event, 'category.code') === 'DEPARTURE'
            && data_get($event, 'title') === 'Pulang atas permintaan sendiri dicatat'
            && data_get($event, 'detail') === 'Encounter berakhir lebih awal atas permintaan pasien sintetis; rekam tidak difinalisasi otomatis.');
    }

    /** @return array<string, mixed> */
    private function nursingDraftPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::SaveDraft->value,
            'clinical_occurrence_at' => now()->subMinutes(10)->toIso8601String(),
            'history_source' => 'Pasien sintetis',
            'chief_complaint' => 'Keluhan sintetis untuk uji snapshot.',
            'onset_duration' => 'Satu hari',
            'consciousness' => 'Sadar penuh.',
            'allergy_state' => AllergyAssessmentState::NoKnownAllergyReported->value,
            'current_medication_state' => CurrentMedicationState::NoneReported->value,
            'vitals' => [
                'temperature' => 36.8,
                'heart_rate' => 82,
                'respiratory_rate' => 18,
                'systolic_blood_pressure' => 118,
                'diastolic_blood_pressure' => 76,
                'oxygen_saturation' => 98,
            ],
            'safety_responses' => [[
                'question_code' => 'SUPERVISOR_CONCERN',
                'response' => 'NO',
                'note' => null,
            ]],
            'safety_decision' => IntakeSafetyDecision::RoutineFlow->value,
            'note' => 'Catatan simulasi.',
            'handoff_summary' => 'Handoff sintetis belum diajukan.',
        ];
    }
}
