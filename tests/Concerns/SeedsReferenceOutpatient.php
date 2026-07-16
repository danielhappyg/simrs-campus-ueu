<?php

namespace Tests\Concerns;

use Database\Seeders\DatabaseSeeder;

trait SeedsReferenceOutpatient
{
    protected function seedReferenceOutpatient(): void
    {
        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.demo_account_password' => 'local-demo-password-only',
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $this->seed(DatabaseSeeder::class);
    }
}
