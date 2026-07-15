<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoSimulationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_fixture_is_opt_in_and_synthetic(): void
    {
        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.demo_account_password' => 'local-demo-password-only',
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'mahasiswa.keperawatan@example.invalid',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('simulation_scenarios', ['code' => 'OPD-REF-001']);
        $this->assertDatabaseHas('work_tasks', ['status' => 'READY']);

        $fixtureSpec = json_decode(
            (string) $this->getConnection()->table('simulation_scenarios')->value('fixture_spec'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($fixtureSpec['synthetic_only']);
    }

    public function test_demo_fixture_refuses_unsafe_environment(): void
    {
        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.demo_account_password' => 'local-demo-password-only',
            'simulation.synthetic_only' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->seed(DatabaseSeeder::class);
    }
}
