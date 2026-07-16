<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class EncounterDebriefTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_assigned_participant_can_reconstruct_a_curated_chronological_debrief_after_finalization(): void
    {
        $case = $this->seedCaseWithNursingSource();
        $this->finalize($case['encounter'], $case['facilitatorAssignment']);

        AuditEvent::query()->create([
            'recorded_at' => now()->subSecond(),
            'actor_user_id' => $case['facilitator']->getKey(),
            'assignment_id' => $case['facilitatorAssignment']->getKey(),
            'session_id' => $case['encounter']->session_id,
            'patient_id' => $case['encounter']->patient_id,
            'encounter_id' => $case['encounter']->getKey(),
            'action' => 'record_quality.correction_requested',
            'resource_type' => 'record_correction_request',
            'resource_id' => (string) Str::ulid(),
            'outcome' => 'SUCCESS',
            'reason' => 'raw-secret-reason',
            'request_correlation_id' => (string) Str::ulid(),
            'ip_hash' => hash('sha256', 'raw-secret-ip'),
            'user_agent' => 'raw-secret-user-agent',
            'metadata' => [
                'finding_public_id' => (string) Str::ulid(),
                'raw_secret_token' => 'raw-secret-token',
            ],
        ]);
        AuditEvent::query()->create([
            'recorded_at' => now(),
            'actor_user_id' => $case['facilitator']->getKey(),
            'assignment_id' => $case['facilitatorAssignment']->getKey(),
            'session_id' => $case['encounter']->session_id,
            'patient_id' => $case['encounter']->patient_id,
            'encounter_id' => $case['encounter']->getKey(),
            'action' => 'encounter.overview_viewed',
            'resource_type' => 'encounter',
            'resource_id' => $case['encounter']->public_id,
            'outcome' => 'SUCCESS',
            'metadata' => ['raw_secret_token' => 'raw-secret-view-event'],
        ]);
        $task = WorkTask::query()->create([
            'session_id' => $case['encounter']->session_id,
            'assignment_id' => $case['nursingAssignment']->getKey(),
            'encounter_id' => $case['encounter']->getKey(),
            'task_type' => WorkTaskType::Debrief,
            'title' => 'Buka linimasa dan debrief encounter',
            'status' => WorkTaskStatus::Ready,
            'priority' => 2,
            'source_program' => Program::Facilitation,
            'context' => ['synthetic' => true],
            'available_at' => now(),
        ]);

        $response = $this->actingAs($case['nurse'])
            ->get(route('encounters.debrief.show', $case['encounter']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('encounter/debrief')
            ->where('encounter.status.code', EncounterStatus::Finalized->value)
            ->where('assignment.program', Program::Nursing->label())
            ->where('release.gate', EncounterStatus::Finalized->value)
            ->where('release.ordinaryEditsLocked', true)
            ->where('teachingEvidence.authoring.canAuthorNotes', false)
            ->has('teachingEvidence.rubricReferences', 1)
            ->where('teachingEvidence.rubricReferences.0.nonScoring', true)
            ->has('session.learningOutcomes', 3)
            ->where('summary.truncated', false)
            ->where('events', fn (mixed $events): bool => $this->projectedEventsAreSafe($events)),
        );
        $response
            ->assertDontSee('raw-secret-reason')
            ->assertDontSee('raw-secret-user-agent')
            ->assertDontSee('raw-secret-token')
            ->assertDontSee('raw-secret-view-event');
        $this->assertSame(WorkTaskStatus::Complete, $task->refresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $case['nurse']->getKey(),
            'action' => 'debrief.workspace_viewed',
            'resource_id' => $case['encounter']->public_id,
        ]);
    }

    public function test_debrief_release_gate_is_checked_after_authorization(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $unassigned = User::factory()->create();
        Assignment::query()->create([
            'session_id' => $encounter->session_id,
            'user_id' => $unassigned->getKey(),
            'program' => Program::Nursing,
            'application_role' => ApplicationRole::Learner,
            'capabilities' => [Capability::SessionView->value],
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->getKey(),
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);

        $this->actingAs($unassigned)
            ->get(route('encounters.debrief.show', $encounter))
            ->assertForbidden();
        $this->actingAs($nurse)
            ->get(route('encounters.debrief.show', $encounter))
            ->assertStatus(409);
    }

    public function test_same_role_in_another_session_and_wrong_case_assignment_are_denied(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $facilitatorAssignment = Assignment::query()
            ->where('user_id', $facilitator->getKey())
            ->sole();
        $this->finalize($encounter, $facilitatorAssignment);

        $otherSessionUser = User::factory()->create();
        $otherSession = SimulationSession::query()->create([
            'scenario_id' => $encounter->session->scenario_id,
            'code' => 'SIM-RJ-OTHER-SESSION',
            'course_code' => 'SIMRS-RJ',
            'cohort_code' => 'OTHER-2026',
            'environment_mode' => EnvironmentMode::Simulation,
            'status' => SessionStatus::Active,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'facilitator_user_id' => $otherSessionUser->getKey(),
        ]);
        Assignment::query()->create([
            'session_id' => $otherSession->getKey(),
            'user_id' => $otherSessionUser->getKey(),
            'program' => Program::Nursing,
            'application_role' => ApplicationRole::Learner,
            'capabilities' => [Capability::DebriefView->value],
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);

        $wrongCaseUser = User::factory()->create();
        Assignment::query()->create([
            'session_id' => $encounter->session_id,
            'user_id' => $wrongCaseUser->getKey(),
            'program' => Program::Nursing,
            'application_role' => ApplicationRole::Learner,
            'capabilities' => [Capability::DebriefView->value],
            'patient_id' => $encounter->patient_id,
            'encounter_id' => null,
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);

        $this->actingAs($otherSessionUser)
            ->get(route('encounters.debrief.show', $encounter))
            ->assertForbidden();
        $this->actingAs($wrongCaseUser)
            ->get(route('encounters.debrief.show', $encounter))
            ->assertForbidden();
    }

    public function test_finalized_debrief_remains_readable_when_the_session_is_completed(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $facilitatorAssignment = Assignment::query()
            ->where('user_id', $facilitator->getKey())
            ->sole();
        $this->finalize($encounter, $facilitatorAssignment);
        $encounter->session->update(['status' => SessionStatus::Completed]);

        $this->actingAs($facilitator)
            ->get(route('encounters.debrief.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/debrief')
                ->where('session.status.code', SessionStatus::Completed->value),
            );
    }

    /** @return array<string, mixed> */
    private function seedCaseWithNursingSource(): array
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $encounter->appointment))->assertRedirect();
        $this->actingAs($nurse)->post(route('encounters.nursing-intake.versions.store', $encounter), [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->subHours(2)->toIso8601String(),
            'history_source' => 'Pasien sintetis',
            'chief_complaint' => 'Pusing pada skenario simulasi.',
            'onset_duration' => 'Dua hari',
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
            'handoff_summary' => 'Diteruskan untuk asesmen medis.',
        ])->assertRedirect();

        return [
            'encounter' => $encounter->refresh(),
            'nurse' => $nurse,
            'nursingAssignment' => Assignment::query()->where('user_id', $nurse->getKey())->sole(),
            'facilitator' => $facilitator,
            'facilitatorAssignment' => Assignment::query()->where('user_id', $facilitator->getKey())->sole(),
        ];
    }

    private function finalize(Encounter $encounter, Assignment $facilitator): Encounter
    {
        $service = app(EncounterTransitionService::class);
        $paths = [
            EncounterStatus::Planned->value => [
                EncounterStatus::Arrived,
                EncounterStatus::InIntake,
                EncounterStatus::WaitingClinician,
                EncounterStatus::InConsultation,
                EncounterStatus::ClosurePending,
                EncounterStatus::ClinicallyClosed,
                EncounterStatus::RecordReview,
                EncounterStatus::Finalized,
            ],
            EncounterStatus::Arrived->value => [
                EncounterStatus::InIntake,
                EncounterStatus::WaitingClinician,
                EncounterStatus::InConsultation,
                EncounterStatus::ClosurePending,
                EncounterStatus::ClinicallyClosed,
                EncounterStatus::RecordReview,
                EncounterStatus::Finalized,
            ],
            EncounterStatus::InIntake->value => [
                EncounterStatus::WaitingClinician,
                EncounterStatus::InConsultation,
                EncounterStatus::ClosurePending,
                EncounterStatus::ClinicallyClosed,
                EncounterStatus::RecordReview,
                EncounterStatus::Finalized,
            ],
        ];

        foreach ($paths[$encounter->status->value] ?? [] as $status) {
            $encounter = $service->transition($encounter, $status, $facilitator, 'debrief_test_progression');
        }

        $this->assertSame(EncounterStatus::Finalized, $encounter->status);
        $this->assertNotNull($encounter->finalized_at);

        return $encounter;
    }

    private function projectedEventsAreSafe(mixed $events): bool
    {
        if ($events instanceof Collection) {
            $events = $events->all();
        }

        if (! is_array($events)) {
            return false;
        }

        $primaryTimes = [];
        $nursingSource = null;
        $correction = null;

        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['primaryAt'] ?? null)) {
                return false;
            }

            $primaryTimes[] = $event['primaryAt'];

            if (($event['title'] ?? null) === 'Versi asesmen awal dibuat') {
                $nursingSource = $event;
            }

            if (($event['title'] ?? null) === 'Koreksi dokumentasi diminta') {
                $correction = $event;
            }
        }

        $sortedTimes = $primaryTimes;
        sort($sortedTimes);

        return $primaryTimes === $sortedTimes
            && is_array($nursingSource)
            && data_get($nursingSource, 'category.code') === 'NURSING'
            && data_get($nursingSource, 'source.version') === 'v1'
            && ($nursingSource['showsRecordedTimeDifference'] ?? null) === true
            && is_array($correction)
            && ! array_key_exists('actionCode', $correction)
            && ! array_key_exists('metadata', $correction)
            && ! array_key_exists('reason', $correction)
            && ! array_key_exists('requestCorrelationId', $correction)
            && ! array_key_exists('ipHash', $correction)
            && ! array_key_exists('userAgent', $correction);
    }
}
