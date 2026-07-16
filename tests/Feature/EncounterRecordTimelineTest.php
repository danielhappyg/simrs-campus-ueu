<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class EncounterRecordTimelineTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_exact_case_participant_can_open_the_record_timeline_before_finalization(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();

        $this->actingAs($nurse)
            ->get(route('encounters.timeline.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/timeline')
                ->where('encounter.status.code', EncounterStatus::Planned->value)
                ->where('assignment.program', Program::Nursing->label())
                ->where('session.status.code', SessionStatus::Active->value)
                ->where('release.readOnly', true),
            );
    }

    public function test_exact_case_participant_can_read_the_record_after_the_session_is_completed(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $encounter->session->update(['status' => SessionStatus::Completed]);

        $this->actingAs($nurse)
            ->get(route('encounters.timeline.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/timeline')
                ->where('session.status.code', SessionStatus::Completed->value)
                ->where('release.sessionCompleted', true),
            );
    }

    public function test_session_wide_facilitator_can_open_the_record_timeline(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();

        $this->actingAs($facilitator)
            ->get(route('encounters.timeline.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/timeline')
                ->where('assignment.program', Program::Facilitation->label()),
            );
    }

    public function test_wrong_context_missing_capability_and_configuration_only_assignments_are_denied(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();

        $otherSessionUser = User::factory()->create();
        $otherSession = SimulationSession::query()->create([
            'scenario_id' => $encounter->session->scenario_id,
            'code' => 'SIM-RJ-TIMELINE-OTHER',
            'course_code' => 'SIMRS-RJ',
            'cohort_code' => 'OTHER-2026',
            'environment_mode' => EnvironmentMode::Simulation,
            'status' => SessionStatus::Active,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'facilitator_user_id' => $otherSessionUser->getKey(),
        ]);
        $this->createAssignment(
            user: $otherSessionUser,
            session: $otherSession,
            capabilities: [Capability::SessionView->value],
        );

        $wrongCaseUser = User::factory()->create();
        $this->createAssignment(
            user: $wrongCaseUser,
            session: $encounter->session,
            capabilities: [Capability::SessionView->value],
            patientId: $encounter->patient_id,
        );

        $missingCapabilityUser = User::factory()->create();
        $this->createAssignment(
            user: $missingCapabilityUser,
            session: $encounter->session,
            capabilities: [Capability::PatientSearch->value],
            patientId: $encounter->patient_id,
            encounterId: $encounter->getKey(),
        );

        $administrator = User::factory()->create();
        $this->createAssignment(
            user: $administrator,
            session: $encounter->session,
            capabilities: [Capability::SystemConfigure->value],
            patientId: $encounter->patient_id,
            encounterId: $encounter->getKey(),
            program: Program::System,
            role: ApplicationRole::SystemAdministrator,
        );

        foreach ([$otherSessionUser, $wrongCaseUser, $missingCapabilityUser, $administrator] as $user) {
            $this->actingAs($user)
                ->get(route('encounters.timeline.show', $encounter))
                ->assertForbidden();
        }
    }

    /** @param list<string> $capabilities */
    private function createAssignment(
        User $user,
        SimulationSession $session,
        array $capabilities,
        ?int $patientId = null,
        ?int $encounterId = null,
        Program $program = Program::Nursing,
        ApplicationRole $role = ApplicationRole::Learner,
    ): Assignment {
        return Assignment::query()->create([
            'session_id' => $session->getKey(),
            'user_id' => $user->getKey(),
            'program' => $program,
            'application_role' => $role,
            'capabilities' => $capabilities,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);
    }
}

