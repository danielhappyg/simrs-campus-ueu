<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\ScenarioStatus;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationScenario;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkTaskInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_cannot_cross_assignment_session_boundary(): void
    {
        [$assignment] = $this->createAssignment([Capability::IntakeWrite->value]);
        [, $otherSession] = $this->createAssignment([Capability::IntakeWrite->value]);

        $this->expectException(DomainException::class);

        WorkTask::query()->create([
            'session_id' => $otherSession->getKey(),
            'assignment_id' => $assignment->getKey(),
            'task_type' => WorkTaskType::NursingIntake,
            'title' => 'Invalid cross-session task',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
        ]);
    }

    public function test_task_cannot_exceed_assignment_capability(): void
    {
        [$assignment, $session] = $this->createAssignment([Capability::SessionView->value]);

        $this->expectException(DomainException::class);

        WorkTask::query()->create([
            'session_id' => $session->getKey(),
            'assignment_id' => $assignment->getKey(),
            'task_type' => WorkTaskType::PharmacyReview,
            'title' => 'Unauthorized pharmacy task',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
        ]);
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array{Assignment, SimulationSession}
     */
    private function createAssignment(array $capabilities): array
    {
        $suffix = Str::lower((string) Str::ulid());
        $facilitator = User::factory()->create();
        $learner = User::factory()->create();
        $scenario = SimulationScenario::query()->create([
            'code' => "invariant-{$suffix}",
            'title' => 'Synthetic invariant scenario',
            'version' => 1,
            'status' => ScenarioStatus::Published,
            'fixture_spec' => ['synthetic_only' => true],
            'published_at' => now(),
        ]);
        $session = SimulationSession::query()->create([
            'scenario_id' => $scenario->getKey(),
            'code' => "invariant-session-{$suffix}",
            'course_code' => 'SIMRS-TEST',
            'cohort_code' => 'TEST-2026',
            'environment_mode' => EnvironmentMode::Simulation,
            'status' => SessionStatus::Active,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'facilitator_user_id' => $facilitator->getKey(),
        ]);
        $assignment = Assignment::query()->create([
            'session_id' => $session->getKey(),
            'user_id' => $learner->getKey(),
            'program' => Program::Nursing,
            'application_role' => ApplicationRole::Learner,
            'capabilities' => $capabilities,
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);

        return [$assignment, $session];
    }
}
