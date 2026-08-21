<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationSafetyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_middleware_blocks_non_simulation_mode(): void
    {
        config([
            'simulation.mode' => 'PRODUCTION',
            'simulation.synthetic_only' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertStatus(503);
    }

    public function test_simulation_middleware_blocks_when_synthetic_only_is_disabled(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => false,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertStatus(503);
    }
}
