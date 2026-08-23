<?php

namespace Tests\Feature\Simulation;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Simulation\SyntheticResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;
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

    public function test_reset_deletes_only_the_synthetic_patient_graph_and_preserves_non_synthetic_records(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $user = User::factory()->create();
        $syntheticPatient = Patient::factory()->create([
            'created_by_user_id' => $user->id,
            'is_synthetic' => true,
        ]);
        $nonSyntheticPatient = Patient::factory()->create([
            'created_by_user_id' => $user->id,
            'is_synthetic' => false,
        ]);
        $syntheticEncounter = Encounter::factory()->create([
            'patient_id' => $syntheticPatient->id,
            'registered_by_user_id' => $user->id,
        ]);
        $nonSyntheticEncounter = Encounter::factory()->create([
            'patient_id' => $nonSyntheticPatient->id,
            'registered_by_user_id' => $user->id,
        ]);
        ClinicalEntry::factory()->create([
            'encounter_id' => $syntheticEncounter->id,
            'author_user_id' => $user->id,
        ]);
        $syntheticOrder = LabServiceRequest::factory()->create([
            'encounter_id' => $syntheticEncounter->id,
            'requested_by_user_id' => $user->id,
        ]);
        LabDiagnosticResult::factory()->create([
            'lab_service_request_id' => $syntheticOrder->id,
            'entered_by_user_id' => $user->id,
        ]);
        $nonSyntheticEntry = ClinicalEntry::factory()->create([
            'encounter_id' => $nonSyntheticEncounter->id,
            'author_user_id' => $user->id,
        ]);
        $nonSyntheticOrder = LabServiceRequest::factory()->create([
            'encounter_id' => $nonSyntheticEncounter->id,
            'requested_by_user_id' => $user->id,
        ]);
        $nonSyntheticResult = LabDiagnosticResult::factory()->create([
            'lab_service_request_id' => $nonSyntheticOrder->id,
            'entered_by_user_id' => $user->id,
        ]);

        $this->assertSame(0, Artisan::call('simulation:reset', ['--force' => true]));

        $this->assertDatabaseMissing('patients', ['id' => $syntheticPatient->id]);
        $this->assertDatabaseMissing('encounters', ['id' => $syntheticEncounter->id]);
        $this->assertDatabaseMissing('lab_service_requests', ['id' => $syntheticOrder->id]);
        $this->assertDatabaseHas('patients', ['id' => $nonSyntheticPatient->id, 'is_synthetic' => false]);
        $this->assertDatabaseHas('encounters', ['id' => $nonSyntheticEncounter->id]);
        $this->assertDatabaseHas('clinical_entries', ['id' => $nonSyntheticEntry->id]);
        $this->assertDatabaseHas('lab_service_requests', ['id' => $nonSyntheticOrder->id]);
        $this->assertDatabaseHas('lab_diagnostic_results', ['id' => $nonSyntheticResult->id]);
    }

    public function test_reset_rolls_back_deletion_when_completion_audit_cannot_be_recorded(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $user = User::factory()->create();
        $patient = Patient::factory()->create([
            'created_by_user_id' => $user->id,
            'is_synthetic' => true,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $user->id,
        ]);

        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')
            ->twice()
            ->andReturn(new AuditEvent, null);

        try {
            (new SyntheticResetService($recorder))->reset();
            $this->fail('Reset should roll back when the completion audit cannot be recorded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('completion audit event', $exception->getMessage());
        }

        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
        $this->assertDatabaseHas('encounters', ['id' => $encounter->id]);
    }
}
