<?php

namespace App\Console\Commands;

use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Services\LaboratorySessionMonitor;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class LaboratorySessionStatusCommand extends Command
{
    protected $signature = 'simulation:lab-session-status
        {session : Exact disposable synthetic session code}
        {--json : Emit a machine-readable identity-minimized report}';

    protected $description = 'Read the bounded progress state of one disposable outpatient laboratory session';

    public function handle(LaboratorySessionMonitor $monitor): int
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

        $report = $monitor->report($session);

        return $this->render(
            $report,
            ($report['status'] ?? null) === 'OK' ? self::SUCCESS : self::FAILURE,
        );
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
