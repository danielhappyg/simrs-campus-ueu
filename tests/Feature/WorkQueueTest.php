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
        $this->assertDatabaseMissing('audit_events', ['action' => 'authorization.denied']);
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
            ->where('selectedSessionCode', $ownTask->session->code)
            ->where('selectionRequired', false)
            ->where('summary.ready', 1),
        );

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->getKey(),
            'action' => 'work_queue.viewed',
            'resource_type' => 'work_queue',
        ]);
    }

    public function test_multiple_active_sessions_require_an_explicit_selection_before_tasks_are_disclosed(): void
    {
        $user = User::factory()->create();
        $firstTask = $this->createTaskFor($user, 'Tugas sesi pertama');
        $this->createTaskFor($user, 'Tugas sesi kedua');

        $this->actingAs($user)
            ->get(route('work'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('work/index')
                ->has('assignments', 2)
                ->has('tasks', 0)
                ->where('selectedSessionCode', null)
                ->where('selectionRequired', true)
                ->where('summary.ready', 0),
            );

        $event = AuditEvent::query()->where('action', 'work_queue.viewed')->sole();

        $this->assertSame(2, $event->metadata['available_session_count']);
        $this->assertSame(0, $event->metadata['selected_assignment_count']);
        $this->assertTrue($event->metadata['selection_required']);
        $this->assertNull($event->metadata['selected_session_public_id']);
        $this->assertNotSame($firstTask->session->public_id, $event->metadata['selected_session_public_id']);
    }

    public function test_authorized_session_selection_scopes_tasks_summary_and_context_to_that_session(): void
    {
        $user = User::factory()->create();
        $firstTask = $this->createTaskFor($user, 'Tugas sesi pertama');
        $secondTask = $this->createTaskFor($user, 'Tugas sesi kedua');

        $this->actingAs($user)
            ->get(route('work', ['session' => $secondTask->session->code]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('work/index')
                ->has('assignments', 2)
                ->has('tasks', 1)
                ->where('tasks.0.publicId', $secondTask->public_id)
                ->where('tasks.0.sessionCode', $secondTask->session->code)
                ->where('selectedSessionCode', $secondTask->session->code)
                ->where('selectionRequired', false)
                ->where('summary.ready', 1),
            );

        $event = AuditEvent::query()->where('action', 'work_queue.viewed')->sole();

        $this->assertSame($secondTask->session->public_id, $event->metadata['selected_session_public_id']);
        $this->assertSame(1, $event->metadata['selected_assignment_count']);
        $this->assertNotSame($firstTask->session->public_id, $event->metadata['selected_session_public_id']);
    }

    public function test_unavailable_session_selection_fails_closed_without_echoing_the_selector_to_audit_metadata(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createTaskFor($user, 'Tugas pengguna');
        $otherTask = $this->createTaskFor($otherUser, 'Tugas sesi tidak berwenang');

        $this->actingAs($user)
            ->get(route('work', ['session' => $otherTask->session->code]))
            ->assertNotFound();

        $event = AuditEvent::query()->where('action', 'work_queue.selection_denied')->sole();

        $this->assertSame($user->getKey(), $event->actor_user_id);
        $this->assertSame('DENIED', $event->outcome);
        $this->assertSame('unavailable_session_selector', $event->reason);
        $this->assertSame([
            'available_session_count' => 1,
            'session_selector_present' => true,
        ], $event->metadata);
        $this->assertNull($event->ip_hash);
        $this->assertNull($event->user_agent);
        $this->assertDatabaseMissing('audit_events', ['action' => 'work_queue.viewed']);
    }

    public function test_invalid_session_selection_shape_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->createTaskFor($user, 'Tugas pengguna');

        $this->actingAs($user)
            ->get(route('work', ['session' => ['not-a-string']]))
            ->assertNotFound();

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->getKey(),
            'action' => 'work_queue.selection_denied',
            'outcome' => 'DENIED',
            'reason' => 'invalid_session_selector',
        ]);
    }

    public function test_assignment_from_an_inactive_session_is_not_presented_as_available_work(): void
    {
        $user = User::factory()->create();
        $task = $this->createTaskFor($user, 'Tugas sesi selesai');
        $task->session()->update(['status' => SessionStatus::Completed]);

        $this->actingAs($user)
            ->get(route('work'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('assignments', 0)
                ->has('tasks', 0)
                ->where('selectedSessionCode', null)
                ->where('selectionRequired', false),
            );
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

        $event = AuditEvent::query()->where('action', 'authorization.denied')->sole();

        $this->assertSame($user->getKey(), $event->actor_user_id);
        $this->assertSame('http_route', $event->resource_type);
        $this->assertSame('work', $event->resource_id);
        $this->assertSame('DENIED', $event->outcome);
        $this->assertSame('authorization_check_failed', $event->reason);
        $this->assertSame(['http_method' => 'GET', 'http_status' => 403], $event->metadata);
        $this->assertNull($event->ip_hash);
        $this->assertNull($event->user_agent);
        $this->assertNotNull($event->request_correlation_id);
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
