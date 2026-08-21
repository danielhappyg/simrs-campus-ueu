<?php

namespace Tests\Feature\Simulation;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SimulationResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_refuses_when_synthetic_only_is_false(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => false,
        ]);

        $exitCode = Artisan::call('simulation:reset', ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Refusing reset', Artisan::output());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_reset_succeeds_with_force_in_simulation(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $user = User::factory()->create();
        $patient = Patient::factory()->create([
            'created_by_user_id' => $user->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $user->id,
        ]);
        ClinicalEntry::factory()->create([
            'encounter_id' => $encounter->id,
            'author_user_id' => $user->id,
        ]);

        $exitCode = Artisan::call('simulation:reset', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Synthetic simulation reset completed', Artisan::output());

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('encounters', 0);
        $this->assertDatabaseCount('clinical_entries', 0);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.started',
            'resource_type' => 'simulation',
            'outcome' => 'SUCCESS',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.completed',
            'resource_type' => 'simulation',
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_reset_refuses_without_force(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $exitCode = Artisan::call('simulation:reset');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--force', Artisan::output());
    }
}
