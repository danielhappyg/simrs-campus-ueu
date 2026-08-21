<?php

namespace App\Modules\Teaching\Services;

use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Support\Collection;

class LaboratorySessionMonitor
{
    /**
     * Return an identity-minimized, read-only projection for one selected session.
     *
     * @return array<string, mixed>
     */
    public function report(SimulationSession $session): array
    {
        $session->loadMissing('sourceSession');
        $blockers = $this->sessionBlockers($session);

        if ($blockers !== []) {
            return [
                'schemaVersion' => 1,
                'readOnly' => true,
                'status' => 'BLOCKED',
                'session' => 'UNAVAILABLE',
                'blockers' => $blockers,
            ];
        }

        $encounter = Encounter::query()
            ->where('session_id', $session->getKey())
            ->sole();
        $tasks = WorkTask::query()
            ->with('assignment')
            ->where('session_id', $session->getKey())
            ->orderBy('priority')
            ->orderBy('task_type')
            ->get();
        $counts = collect(WorkTaskStatus::cases())
            ->mapWithKeys(fn (WorkTaskStatus $status): array => [
                $status->value => $tasks
                    ->filter(fn (WorkTask $task): bool => $task->status === $status)
                    ->count(),
            ])
            ->all();
        $openTasks = $tasks
            ->filter(fn (WorkTask $task): bool => ! in_array(
                $task->status,
                [WorkTaskStatus::Complete, WorkTaskStatus::Cancelled],
                true,
            ))
            ->count();

        return [
            'schemaVersion' => 1,
            'readOnly' => true,
            'status' => 'OK',
            'phase' => $this->phase($session->status, $encounter->status),
            'session' => [
                'code' => $session->code,
                'status' => $session->status->value,
                'source' => $session->sourceSession?->code,
            ],
            'encounter' => [
                'number' => $encounter->encounter_number,
                'status' => $encounter->status->value,
                'synthetic' => (bool) $encounter->patient()->value('synthetic_flag'),
            ],
            'summary' => [
                'activeAssignments' => Assignment::query()
                    ->where('session_id', $session->getKey())
                    ->active()
                    ->count(),
                'totalTasks' => $tasks->count(),
                'openTasks' => $openTasks,
                'byStatus' => $counts,
            ],
            'readyTasks' => $this->readyTasks($tasks),
            'attention' => $this->attention($session, $counts),
            'workQueuePath' => '/work?session='.$session->code,
        ];
    }

    /**
     * @return list<array{id: string, detail: string}>
     */
    private function sessionBlockers(SimulationSession $session): array
    {
        $blockers = [];
        $encounters = Encounter::query()->where('session_id', $session->getKey())->get();
        $patients = $session->patients()->get();
        $appointments = AppointmentRegistration::query()
            ->where('session_id', $session->getKey())
            ->get();
        $assignments = Assignment::query()->where('session_id', $session->getKey())->get();
        $activeAssignments = Assignment::query()
            ->where('session_id', $session->getKey())
            ->active()
            ->count();

        if ($session->source_session_id === null || ! $session->sourceSession) {
            $blockers[] = [
                'id' => 'session.disposable_clone',
                'detail' => 'The monitor accepts only a disposable session cloned from a retained synthetic source.',
            ];
        }

        if ($session->environment_mode !== EnvironmentMode::Simulation) {
            $blockers[] = [
                'id' => 'session.environment_mode',
                'detail' => 'The disposable session must remain in SIMULATION mode.',
            ];
        }

        if ($encounters->count() !== 1
            || $appointments->count() !== 1
            || $patients->isEmpty()
            || $patients->where('fixture_source', 'OPD-REF-001-v1')->count() !== 1
            || $patients->contains(fn ($patient): bool => ! $patient->synthetic_flag)
            || $patients->contains(fn ($patient): bool => $patient->fixture_source !== 'OPD-REF-001-v1'
                && ! str_starts_with((string) $patient->fixture_source, 'OPD-POP-'))
            || $encounters->contains(fn (Encounter $encounter): bool => $encounter->environment_mode !== EnvironmentMode::Simulation)) {
            $blockers[] = [
                'id' => 'session.one_synthetic_case',
                'detail' => 'The disposable session must retain exactly one reference patient case, one appointment, and one encounter, with only optional OPD-POP population patients.',
            ];
        }

        if ($assignments->count() !== 10 || $activeAssignments !== 10) {
            $blockers[] = [
                'id' => 'session.assignment_roster',
                'detail' => 'The disposable reference session must retain all ten active role assignments.',
            ];
        }

        if (WorkTask::query()->where('session_id', $session->getKey())->count() < 4) {
            $blockers[] = [
                'id' => 'session.task_graph',
                'detail' => 'The disposable reference session is missing required work-task provenance.',
            ];
        }

        return $blockers;
    }

    private function phase(SessionStatus $sessionStatus, EncounterStatus $encounterStatus): string
    {
        if ($encounterStatus === EncounterStatus::Finalized) {
            return 'FINALIZED';
        }

        if ($sessionStatus === SessionStatus::Scheduled) {
            return 'SCHEDULED';
        }

        if ($sessionStatus === SessionStatus::Paused) {
            return 'PAUSED';
        }

        if ($sessionStatus !== SessionStatus::Active
            || in_array($encounterStatus, [
                EncounterStatus::Cancelled,
                EncounterStatus::NoShow,
                EncounterStatus::TransferredSimulation,
                EncounterStatus::DepartedOnRequest,
            ], true)) {
            return 'ENDED';
        }

        return $encounterStatus === EncounterStatus::Planned
            ? 'READY_TO_START'
            : 'IN_PROGRESS';
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function attention(SimulationSession $session, array $counts): array
    {
        $attention = [];

        if ($session->status !== SessionStatus::Active) {
            $attention[] = 'SESSION_NOT_ACTIVE';
        }

        if (($counts[WorkTaskStatus::Blocked->value] ?? 0) > 0) {
            $attention[] = 'TASKS_BLOCKED';
        }

        if (($counts[WorkTaskStatus::ChangesRequested->value] ?? 0) > 0) {
            $attention[] = 'CHANGES_REQUESTED';
        }

        if (($counts[WorkTaskStatus::Submitted->value] ?? 0) > 0) {
            $attention[] = 'SUPERVISOR_REVIEW_PENDING';
        }

        return $attention;
    }

    /**
     * @param  Collection<int, WorkTask>  $tasks
     * @return list<array{type: string, program: string, role: string, priority: int}>
     */
    private function readyTasks(Collection $tasks): array
    {
        $readyTasks = [];

        foreach ($tasks as $task) {
            if ($task->status !== WorkTaskStatus::Ready) {
                continue;
            }

            $readyTasks[] = [
                'type' => $task->task_type->value,
                'program' => $task->assignment->program->value,
                'role' => $task->assignment->application_role->value,
                'priority' => $task->priority,
            ];
        }

        return $readyTasks;
    }
}
