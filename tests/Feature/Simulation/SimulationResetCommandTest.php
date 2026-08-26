<?php

namespace Tests\Feature\Simulation;

use App\Models\ClinicalEntry;
use App\Models\DailyQueueCounter;
use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\Patient;
use App\Models\SecurityLedgerEntry;
use App\Models\SecurityLedgerOutbox;
use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Simulation\SyntheticResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Mockery;
use Mockery\CompositeExpectation;
use RuntimeException;
use Tests\TestCase;

class SimulationResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_command_has_no_audit_purge_option(): void
    {
        $command = Artisan::all()['simulation:reset'];

        $this->assertFalse($command->getDefinition()->hasOption('purge-audit'));
    }

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
        $existingAudit = $this->app->make(AuditRecorder::class)->record(
            action: 'authorization.denied',
            resourceType: 'http_route',
            resourceId: 'simulation.reset.preserved_evidence',
            actor: $user,
            outcome: 'DENIED',
            reason: 'authorization_check_failed',
            metadata: ['http_method' => 'POST', 'http_status' => 403],
            includeRequestFingerprint: false,
        );
        $this->assertNotNull($existingAudit);
        $ledgerEntry = SecurityLedgerEntry::query()->create([
            'recorded_at' => now(),
            'actor_user_id' => $user->id,
            'actor_type' => 'USER',
            'actor_reference' => $user->email,
            'event_type' => 'security.evidence.preexisting',
            'resource_type' => 'simulation',
            'resource_public_id' => 'preserve-through-reset',
            'outcome' => 'SUCCESS',
            'reason' => 'reset_preservation_test',
            'environment' => 'testing',
            'release_sha' => str_repeat('a', 40),
            'schema_version' => 1,
            'payload' => ['evidence_class' => 'security_ledger'],
            'payload_digest' => str_repeat('b', 64),
            'semantic_key' => str_repeat('c', 64),
            'integrity_digest' => str_repeat('d', 64),
        ]);
        $ledgerOutbox = SecurityLedgerOutbox::query()->create([
            'security_ledger_entry_id' => $ledgerEntry->id,
            'destination' => 'teaching-evidence-sink',
            'delivery_state' => SecurityLedgerOutbox::STATE_PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);
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
        DailyQueueCounter::query()->create([
            'queue_date' => now()->toDateString(),
            'last_number' => 9,
        ]);

        $exitCode = Artisan::call('simulation:reset', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Synthetic simulation reset completed', Artisan::output());

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('encounters', 0);
        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertDatabaseHas('daily_queue_counters', [
            'queue_date' => now()->toDateString(),
            'last_number' => 9,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'id' => $existingAudit->id,
            'action' => 'authorization.denied',
            'resource_id' => 'simulation.reset.preserved_evidence',
        ]);
        $this->assertDatabaseHas('security_ledger_entries', [
            'id' => $ledgerEntry->id,
            'event_type' => 'security.evidence.preexisting',
            'resource_public_id' => 'preserve-through-reset',
        ]);
        $this->assertDatabaseHas('security_ledger_outboxes', [
            'id' => $ledgerOutbox->id,
            'security_ledger_entry_id' => $ledgerEntry->id,
            'delivery_state' => SecurityLedgerOutbox::STATE_PENDING,
        ]);

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

        $resetEvents = AuditEvent::query()
            ->whereIn('action', ['teaching.reset.started', 'teaching.reset.completed'])
            ->get();

        $this->assertCount(2, $resetEvents);
        $resetEvents->each(function (AuditEvent $event): void {
            $this->assertNull($event->actor_user_id);
            $this->assertSame(AuditActorAttribution::TYPE_SERVICE, $event->actor_type);
            $this->assertSame(AuditActorAttribution::SYNTHETIC_RESET_SERVICE, $event->actor_reference);
            $this->assertArrayNotHasKey('purge_audit', $event->metadata ?? []);
            $this->assertTrue($event->metadata['evidence_preserved']);
            $this->assertTrue($event->metadata['queue_counter_high_water_preserved']);
        });
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

    public function test_reset_rejects_overlong_reason_before_deleting_synthetic_data(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create([
            'created_by_user_id' => $user->id,
            'is_synthetic' => true,
        ]);

        try {
            $this->app->make(SyntheticResetService::class)->reset([
                'reason' => str_repeat('r', 256),
            ]);
            $this->fail('Overlong reset attribution must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('255', $exception->getMessage());
        }

        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
        $this->assertDatabaseCount('audit_events', 0);
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
        $recordExpectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $recordExpectation);
        $recordExpectation->andReturn(new AuditEvent, null);

        $this->app->instance(AuditRecorder::class, $recorder);

        try {
            (new SyntheticResetService($this->app->make(AuditRecorder::class)))->reset();
            $this->fail('Reset should roll back when the completion audit cannot be recorded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('completion audit event', $exception->getMessage());
        }

        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
        $this->assertDatabaseHas('encounters', ['id' => $encounter->id]);
    }
}
