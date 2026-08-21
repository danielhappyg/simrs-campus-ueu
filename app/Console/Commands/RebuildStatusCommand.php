<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RebuildStatusCommand extends Command
{
    protected $signature = 'rebuild:status';

    protected $description = 'Show clean-slate rebuild foundation status';

    public function handle(): int
    {
        $this->info('SIMRS Campus UEU — clean-slate rebuild foundation');
        $this->line('Branch intent: rebuild/clean-slate');
        $this->line('Phase: 0 foundation ready for Phase 2 identity/authorization work');
        $this->line('APP_MODE: '.config('simulation.mode'));
        $this->line('APP_SYNTHETIC_ONLY: '.(config('simulation.synthetic_only') ? 'true' : 'false'));
        $this->line('Docs: docs/new-simrs-rebuild/');

        return self::SUCCESS;
    }
}
