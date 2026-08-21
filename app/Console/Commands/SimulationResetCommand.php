<?php

namespace App\Console\Commands;

use App\Support\Simulation\SyntheticResetService;
use Illuminate\Console\Command;
use Throwable;

class SimulationResetCommand extends Command
{
    protected $signature = 'simulation:reset
                            {--force : Required confirmation flag for destructive reset}
                            {--purge-audit : Also delete audit_events (teaching evidence usually kept)}';

    protected $description = 'Reset synthetic domain data while preserving identity/RBAC (simulation only)';

    public function handle(SyntheticResetService $resetService): int
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            $this->error('Refusing reset: APP_MODE must be SIMULATION and APP_SYNTHETIC_ONLY must be true.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Refusing reset: pass --force to confirm.');

            return self::FAILURE;
        }

        try {
            $resetService->reset([
                'purge_audit' => (bool) $this->option('purge-audit'),
                'reason' => 'artisan_simulation_reset',
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Synthetic simulation reset completed.');

        if ($this->option('purge-audit')) {
            $this->warn('Audit events were purged; only the completion event remains.');
        }

        return self::SUCCESS;
    }
}
