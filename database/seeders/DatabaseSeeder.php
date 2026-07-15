<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! config('simulation.demo_seed_enabled')) {
            return;
        }

        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Demo fixtures require SIMULATION mode with synthetic-only data enforced.');
        }

        $this->call(DemoSimulationSeeder::class);
    }
}
