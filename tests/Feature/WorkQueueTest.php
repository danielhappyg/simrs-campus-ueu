<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('work'))->assertRedirect(route('login'));
    }

    public function test_user_only_sees_tasks_from_their_active_assignment(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownTask = $this->createTaskFor($user, 'Tugas milik pengguna');
        $this->createTaskFor($otherUser, 'Tugas milik pengguna lain');

        $response = $this->actingAs($user)->get(route('work'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('work/index')
            ->has('assignments', 1)
            ->where('assignments.0.capabilities.0.code', Capability::SessionView->value)
            ->has('tasks', 1)
            ->where('tasks.0.publicId', $ownTask->public_id)
            ->where('tasks.0.title', 'Tugas milik pengguna')
            ->where('summary.ready', 1),
        );

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->getKey(),
            'action' => 'work_queue.viewed',
            'resource_type' => 'work_queue',
        ]);
    }

    public function test_completed_future_and_other_users_tasks_are_not_disclosed(): void
    {
        $user = User::factory()->create();
        $readyTask = $this->createTaskFor($user, 'Tugas siap');

        WorkTask::query()->create([
            'session_id' => $readyTask->session_id,
            'assignment_id' => $readyTask->assignment_id,
            'task_type' => WorkTaskType::NursingIntake,
            'title' => 'Tugas selesai',
            'status' => WorkTaskStatus::Complete,
            'priority' => 2,
            'available_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        WorkTask::query()->create([
            'session_id' => $readyTask->session_id,
            'assignment_id' => $readyTask->assignment_id,
            'task_type' => WorkTaskType::NursingIntake,
            'title' => 'Tugas masa depan',
            'status' => WorkTaskStatus::Ready,
            'priority' => 3,
            'available_at' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->get(route('work'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 1)
                ->where('tasks.0.publicId', $readyTask->public_id),
            );
    }

    public function test_revoked_assignment_is_removed_from_work_context(): void
    {
        $user = User::factory()->create();
        $task = $this->createTaskFor($user, 'Tugas yang dicabut');
        $task->assignment()->update([
            'revoked_at' => now(),
            'revocation_reason' => 'Sesi latihan berakhir',
        ]);

        $this->actingAs($user)
            ->get(route('work'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assignments', 0)
                ->has('tasks', 0),
            );
    }

    public function test_unsafe_environment_configuration_fails_closed(): void
    {
        config(['simulation.synthetic_only' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('work'))->assertStatus(503);
    }

    public function test_authentication_route_also_fails_closed_in_unsafe_environment(): void
    {
        config(['simulation.synthetic_only' => false]);

        $this->get(route('login'))->assertStatus(503);
    }

    public function test_inactive_account_cannot_open_work_queue(): void
    {
        $user = User::factory()->create(['status' => 'SUSPENDED']);

        $this->actingAs($user)->get(route('work'))->assertForbidden();
        $this->assertSame(0, AuditEvent::query()->count());
    }

    private function createTaskFor(User $user, string $title): WorkTask
    {
        $suffix = Str::lower((string) Str::ulid());
        $facilitator = User::factory()->create();
        $scenario = SimulationScenario::query()->create([
            'code' => "scenario-{$suffix}",
            'title' => 'Skenario sintetis',
            'version' => 1,
            'status' => ScenarioStatus::Published,
            'fixture_spec' => ['synthetic_only' => true],
            'published_at' => now(),
        ]);
        $session = SimulationSession::query()->create([
            'scenario_id' => $scenario->getKey(),
            'code' => "session-{$suffix}",
            'course_code' => 'SIMRS-RJ',
            'cohort_code' => 'TEST-2026',
            'environment_mode' => EnvironmentMode::Simulation,
            'status' => SessionStatus::Active,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'facilitator_user_id' => $facilitator->getKey(),
        ]);
        $assignment = Assignment::query()->create([
            'session_id' => $session->getKey(),
            'user_id' => $user->getKey(),
            'program' => Program::Nursing,
            'application_role' => ApplicationRole::Learner,
            'capabilities' => [Capability::SessionView->value, Capability::IntakeWrite->value],
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);

        return WorkTask::query()->create([
            'session_id' => $session->getKey(),
            'assignment_id' => $assignment->getKey(),
            'task_type' => WorkTaskType::NursingIntake,
            'title' => $title,
            'description' => 'Data sintetis untuk pengujian isolasi.',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
            'source_program' => Program::Nursing,
            'context' => ['caseLabel' => "CASE-{$suffix}", 'synthetic' => true],
            'available_at' => now()->subMinute(),
        ]);
    }
}
