<?php

namespace App\Console\Commands;

use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Services\LaboratoryAccessService;
use App\Modules\Teaching\Services\ReservedDemoAccountRoster;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class LaboratoryAccessCommand extends Command
{
    protected $signature = 'simulation:lab-access
        {action : Exact action: enable or disable}
        {--source=SIM-RJ-UEU-001 : Retained synthetic reference-session code}
        {--confirm= : Exact action-specific confirmation phrase}
        {--json : Emit a machine-readable identity-minimized report}';

    protected $description = 'Enable or disable the exact reserved synthetic laboratory account roster';

    public function handle(
        ReservedDemoAccountRoster $roster,
        LaboratoryAccessService $accessService,
    ): int {
        $action = strtolower(trim((string) $this->argument('action')));
        $sourceCode = trim((string) $this->option('source'));
        $confirmation = trim((string) $this->option('confirm'));
        $validAction = in_array($action, ['enable', 'disable'], true);
        $validSourceCode = preg_match('/^[A-Z0-9][A-Z0-9-]{2,63}$/', $sourceCode) === 1;
        $blockers = $this->runtimeBlockers($action);

        if (! $validAction) {
            $blockers[] = [
                'id' => 'action.invalid',
                'detail' => 'The action must be exactly enable or disable.',
            ];
        }

        if (! $validSourceCode) {
            $blockers[] = [
                'id' => 'source.code',
                'detail' => 'The retained source code must contain 3–64 uppercase letters, numbers, or hyphens.',
            ];
        }

        $expectedConfirmation = $validAction
            ? strtoupper($action).'-RESERVED-DEMO-ACCESS'
            : null;

        if ($expectedConfirmation === null || ! hash_equals($expectedConfirmation, $confirmation)) {
            $blockers[] = [
                'id' => 'confirmation.required',
                'detail' => 'The exact action-specific confirmation phrase is required.',
            ];
        }

        $password = $action === 'enable'
            ? config('simulation.demo_account_password')
            : null;

        if ($action === 'enable' && (! is_string($password) || strlen($password) < 12)) {
            $blockers[] = [
                'id' => 'password.configuration',
                'detail' => 'DEMO_ACCOUNT_PASSWORD must contain at least 12 characters before access is enabled.',
            ];
        }

        if ($blockers !== []) {
            return $this->renderBlocked($validAction ? strtoupper($action) : 'INVALID', $blockers);
        }

        try {
            $users = $roster->usersForSource($sourceCode);
            $result = $accessService->transition(
                $users,
                $action,
                is_string($password) ? $password : null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->renderBlocked(strtoupper($action), [[
                'id' => 'access.transition',
                'detail' => 'The laboratory access transition failed closed; no protected value was displayed.',
            ]]);
        }

        $report = [
            'schemaVersion' => 1,
            'syntheticOnly' => true,
            'status' => $result['changed'] ? strtoupper($action).'D' : 'UNCHANGED',
            'action' => strtoupper($action),
            'summary' => $result,
        ];

        return $this->render($report, self::SUCCESS);
    }

    /** @return list<array{id: string, detail: string}> */
    private function runtimeBlockers(string $action): array
    {
        $blockers = [];

        if (app()->environment('production')) {
            $blockers[] = [
                'id' => 'environment.production',
                'detail' => 'Reserved laboratory access control is prohibited in production.',
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

        if (config('session.driver') !== 'database' || config('session.table') !== 'sessions') {
            $blockers[] = [
                'id' => 'session.revocable_backend',
                'detail' => 'SESSION_DRIVER must be database and SESSION_TABLE must be sessions.',
            ];
        }

        if ($action === 'enable' && config('simulation.demo_seed_enabled') !== true) {
            $blockers[] = [
                'id' => 'simulation.demo_seed_enabled',
                'detail' => 'DEMO_SEED_ENABLED must be true before reserved access is enabled.',
            ];
        }

        return $blockers;
    }

    /**
     * @param  list<array{id: string, detail: string}>  $blockers
     */
    private function renderBlocked(string $action, array $blockers): int
    {
        return $this->render([
            'schemaVersion' => 1,
            'syntheticOnly' => true,
            'status' => 'BLOCKED',
            'action' => $action,
            'blockers' => $blockers,
        ], self::FAILURE);
    }

    /** @param array<string, mixed> $report */
    private function render(array $report, int $exitCode): int
    {
        if ($this->option('json')) {
            try {
                $this->line(json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ));
            } catch (JsonException) {
                $this->error('The sanitized laboratory access report could not be encoded.');

                return self::FAILURE;
            }

            return $exitCode;
        }

        if ($report['status'] === 'BLOCKED') {
            $this->error('Synthetic laboratory access transition: BLOCKED');
            $this->table(
                ['Blocker', 'Sanitized detail'],
                array_map(
                    fn (array $blocker): array => [$blocker['id'], $blocker['detail']],
                    $report['blockers'],
                ),
            );
            $this->warn('No account lifecycle claim is made until the command exits successfully.');

            return $exitCode;
        }

        $this->info('Synthetic laboratory access transition: '.$report['status']);
        $this->table(
            ['Accounts', 'Sessions revoked', 'Passkeys removed', 'Reset tokens removed', 'Password rotated'],
            [[
                $report['summary']['accountCount'],
                $report['summary']['sessionsRevoked'],
                $report['summary']['passkeysRemoved'],
                $report['summary']['resetTokensRemoved'],
                $report['summary']['passwordRotated'] ? 'yes' : 'no',
            ]],
        );
        $this->warn('The command never displays or stores the temporary password in audit metadata.');

        return $exitCode;
    }
}
