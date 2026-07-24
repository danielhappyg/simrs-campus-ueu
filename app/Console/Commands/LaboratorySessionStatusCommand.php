<?php

namespace App\Console\Commands;

use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use JsonException;
use Throwable;

class LaboratorySessionStatusCommand extends Command
{
    protected $signature = 'simulation:lab-session-status
        {session : Exact disposable synthetic session code}
        {--json : Emit a machine-readable identity-minimized report}';

    protected $description = 'Read the bounded progress state of one disposable outpatient laboratory session';

    public function handle(): int
    {
        $requestedCode = trim((string) $this->argument('session'));
        $validCode = preg_match('/^[A-Z0-9][A-Z0-9-]{2,63}$/', $requestedCode) === 1;
        $blockers = $this->runtimeBlockers();

        if (! $validCode) {
            $blockers[] = [
                'id' => 'session.code',
                'detail' => 'The session code must contain 3–64 uppercase letters, numbers, or hyphens.',
            ];
        }

        $session = null;

        if ($validCode && $blockers === []) {
            try {
                $session = SimulationSession::query()
                    ->with('sourceSession')
                    ->where('code', $requestedCode)
                    ->first();
            } catch (Throwable $exception) {
                report($exception);
                $blockers[] = [
                    'id' => 'database.read',
                    'detail' => 'The session monitor could not complete its read-only database query.',
                ];
            }

            if (! $session) {
                $blockers[] = [
                    'id' => 'session.not_found',
                    'detail' => 'No disposable laboratory session matched the supplied valid code.',
                ];
            }
        }

        if ($session && $blockers === []) {
            $blockers = $this->sessionBlockers($session);
        }

        if ($blockers !== []) {
            return $this->render([
                'schemaVersion' => 1,
                'readOnly' => true,
                'status' => 'BLOCKED',
                'session' => $validCode ? 'UNAVAILABLE' : 'INVALID',
                'blockers' => $blockers,
            ], self::FAILURE);
        }

        if (! $session) {
            return self::FAILURE;
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
        $attention = $this->attention($session, $counts);
        $report = [
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
            'attention' => $attention,
            'workQueuePath' => '/work?session='.$session->code,
        ];

        return $this->render($report, self::SUCCESS);
    }

    /** @return list<array{id: string, detail: string}> */
    private function runtimeBlockers(): array
    {
        $blockers = [];

        if (app()->environment('production')) {
            $blockers[] = [
                'id' => 'environment.production',
                'detail' => 'Laboratory session monitoring is prohibited in the production application environment.',
            ];
        }

        if (config('simulation.mode') !== EnvironmentMode::Simulation->value) {
            $blockers[] = [
                'id' => 'simulation.mode',
                'detail' => 'APP_MODE must be SIMULATION.',
            ];
        }

        if (config('simulation.synthetic_only') !== true) {
            $blockers[] = [
                'id' => 'simulation.synthetic_only',
                'detail' => 'APP_SYNTHETIC_ONLY must be true.',
            ];
        }

        return $blockers;
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
            || $patients->count() !== 1
            || $appointments->count() !== 1
            || $patients->contains(fn ($patient): bool => ! $patient->synthetic_flag)
            || $encounters->contains(fn (Encounter $encounter): bool => $encounter->environment_mode !== EnvironmentMode::Simulation)) {
            $blockers[] = [
                'id' => 'session.one_synthetic_case',
                'detail' => 'The disposable session must retain exactly one internally scoped synthetic patient, appointment, and encounter.',
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

    /** @param array<string, mixed> $report */
    private function render(array $report, int $exitCode): int
    {
        if ($this->option('json')) {
            try {
                $this->line(json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
            } catch (JsonException) {
                $this->error('The sanitized laboratory-session report could not be encoded.');

                return self::FAILURE;
            }

            return $exitCode;
        }

        if (($report['status'] ?? null) === 'BLOCKED') {
            $this->error('Synthetic outpatient laboratory session monitor: BLOCKED');
            $this->table(
                ['Blocker', 'Sanitized detail'],
                array_map(
                    fn (array $blocker): array => [$blocker['id'], $blocker['detail']],
                    $report['blockers'],
                ),
            );
            $this->warn('Do not continue the participant session until every blocker is resolved through the documented setup path.');

            return $exitCode;
        }

        $this->info('Synthetic outpatient laboratory session monitor: '.$report['phase']);
        $this->table(
            ['Session', 'Session status', 'Encounter', 'Encounter status', 'Open tasks'],
            [[
                $report['session']['code'],
                $report['session']['status'],
                $report['encounter']['number'],
                $report['encounter']['status'],
                $report['summary']['openTasks'],
            ]],
        );
        $this->table(
            ['Ready task', 'Program', 'Role', 'Priority'],
            array_map(
                fn (array $task): array => [
                    $task['type'],
                    $task['program'],
                    $task['role'],
                    $task['priority'],
                ],
                $report['readyTasks'],
            ),
        );
        $this->line('Work queue: '.$report['workQueuePath']);
        $this->line('Attention: '.($report['attention'] === [] ? 'none' : implode(', ', $report['attention'])));
        $this->warn('Read-only synthetic progress evidence only; this command does not score, accept, reset, merge, deploy, or authorize a pilot.');

        return $exitCode;
    }
}
