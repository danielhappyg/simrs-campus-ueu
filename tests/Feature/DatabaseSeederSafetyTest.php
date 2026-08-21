<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_refuses_unsafe_environment(): void
    {
        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => false,
            'simulation.demo_account_password' => 'rebuild-admin-password',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo fixtures require SIMULATION mode with synthetic-only data enforced.');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeder_creates_rebuild_admin_when_opted_in(): void
    {
        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => 'rebuild-admin-password',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'admin.rebuild@example.invalid',
            'status' => 'ACTIVE',
            'is_system_administrator' => true,
        ]);
    }
}
