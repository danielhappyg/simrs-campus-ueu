<?php

namespace Tests\Feature;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\VisitTerminationOutcome;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Patient\Services\AppointmentCheckInService;
use App\Modules\Patient\Services\AppointmentTerminationService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class CloneReferenceSessionCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_command_clones_only_the_pristine_graph_and_remaps_every_reference(): void
    {
        $this->seedReferenceOutpatient();

        $source = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->sole();
        $sourcePatient = SyntheticPatient::query()
            ->where('session_id', $source->getKey())
            ->where('fixture_source', 'OPD-REF-001-v1')
            ->sole();
        $sourcePopulationCount = SyntheticPatient::query()
            ->where('session_id', $source->getKey())
            ->where('fixture_source', 'like', 'OPD-POP-%')
            ->count();
        $sourceAppointment = AppointmentRegistration::query()->where('session_id', $source->getKey())->sole();
        $sourceEncounter = Encounter::query()->where('session_id', $source->getKey())->sole();
        $sourceAssignments = Assignment::query()->where('session_id', $source->getKey())->get()->keyBy('user_id');
        $sourceIdentifierValues = $sourcePatient->identifiers()->orderBy('type')->pluck('value')->all();
        $sourceTaskStates = WorkTask::query()
            ->where('session_id', $source->getKey())
            ->orderBy('task_type')
            ->pluck('status', 'task_type')
            ->all();
        $sourceStock = MedicationStock::query()->where('session_id', $source->getKey())->sole();
        $sourceUpdatedAt = $source->updated_at;

        $this->artisan('simulation:clone-reference-session', [
            'code' => 'UAT-MAIN-001',
            '--duration' => '240',
        ])->expectsOutputToContain('fresh synthetic outpatient reference session')
            ->assertSuccessful();

        $target = SimulationSession::query()->where('code', 'UAT-MAIN-001')->sole();
        $targetPatient = SyntheticPatient::query()
            ->where('session_id', $target->getKey())
            ->where('fixture_source', 'OPD-REF-001-v1')
            ->sole();
        $this->assertSame(
            $sourcePopulationCount,
            SyntheticPatient::query()
                ->where('session_id', $target->getKey())
                ->where('fixture_source', 'like', 'OPD-POP-%')
                ->count(),
        );
        $targetAppointment = AppointmentRegistration::query()->where('session_id', $target->getKey())->sole();
        $targetEncounter = Encounter::query()->where('session_id', $target->getKey())->sole();
        $targetAssignments = Assignment::query()->where('session_id', $target->getKey())->get()->keyBy('user_id');
        $targetTasks = WorkTask::query()->where('session_id', $target->getKey())->get();
        $targetTransition = EncounterTransition::query()->where('encounter_id', $targetEncounter->getKey())->sole();
        $targetStock = MedicationStock::query()->where('session_id', $target->getKey())->sole();

        $this->assertSame($source->getKey(), $target->source_session_id);
        $this->assertSame(240, (int) $target->starts_at->diffInMinutes($target->ends_at));
        $this->assertNotSame($source->public_id, $target->public_id);
        $this->assertNotSame($sourcePatient->public_id, $targetPatient->public_id);
        $this->assertNotSame($sourceAppointment->appointment_code, $targetAppointment->appointment_code);
        $this->assertNotSame($sourceEncounter->encounter_number, $targetEncounter->encounter_number);
        $this->assertSame(AppointmentStatus::Booked, $targetAppointment->status);
        $this->assertSame(EncounterStatus::Planned, $targetEncounter->status);
        $this->assertNull($targetAppointment->checked_in_at);
        $this->assertSame($targetPatient->getKey(), $targetAppointment->patient_id);
        $this->assertSame($targetPatient->getKey(), $targetEncounter->patient_id);
        $this->assertSame($targetAppointment->getKey(), $targetEncounter->appointment_registration_id);
        $this->assertSame($targetEncounter->getKey(), $targetTransition->encounter_id);
        $this->assertNull($targetTransition->from_status);
        $this->assertSame(EncounterStatus::Planned, $targetTransition->to_status);
        $this->assertSame('reference_session_cloned', $targetTransition->reason);

        $targetIdentifierValues = $targetPatient->identifiers()->orderBy('type')->pluck('value')->all();
        $this->assertCount(2, $targetIdentifierValues);
        $this->assertSame([], array_values(array_intersect($sourceIdentifierValues, $targetIdentifierValues)));
        $this->assertSame(
            $sourcePatient->identifiers()->orderBy('type')->pluck('type')->all(),
            $targetPatient->identifiers()->orderBy('type')->pluck('type')->all(),
        );

        $this->assertCount(10, $targetAssignments);
        $this->assertSame($sourceAssignments->keys()->sort()->values()->all(), $targetAssignments->keys()->sort()->values()->all());
        $targetAssignmentIds = $targetAssignments->pluck('id')->all();

        foreach ($sourceAssignments as $userId => $sourceAssignment) {
            $targetAssignment = $targetAssignments->get($userId);

            $this->assertNotNull($targetAssignment);
            $this->assertNotSame($sourceAssignment->getKey(), $targetAssignment->getKey());
            $this->assertSame($sourceAssignment->program, $targetAssignment->program);
            $this->assertSame($sourceAssignment->application_role, $targetAssignment->application_role);
            $this->assertSame($sourceAssignment->capabilities, $targetAssignment->capabilities);
            $this->assertNotSame($sourcePatient->getKey(), $targetAssignment->patient_id);
            $this->assertNotSame($sourceEncounter->getKey(), $targetAssignment->encounter_id);

            if ($sourceAssignment->patient_id === null) {
                $this->assertNull($targetAssignment->patient_id);
                $this->assertNull($targetAssignment->encounter_id);
            } else {
                $this->assertSame($targetPatient->getKey(), $targetAssignment->patient_id);
                $this->assertSame($targetEncounter->getKey(), $targetAssignment->encounter_id);
            }

            if ($sourceAssignment->supervisor_assignment_id === null) {
                $this->assertNull($targetAssignment->supervisor_assignment_id);
            } else {
                $this->assertContains($targetAssignment->supervisor_assignment_id, $targetAssignmentIds);
                $this->assertNotContains($targetAssignment->supervisor_assignment_id, $sourceAssignments->pluck('id')->all());
            }
        }

        $this->assertCount(4, $targetTasks);
        $this->assertSame([
            WorkTaskType::MedicalAssessment->value => WorkTaskStatus::Waiting->value,
            WorkTaskType::NursingIntake->value => WorkTaskStatus::Waiting->value,
            WorkTaskType::Registration->value => WorkTaskStatus::Ready->value,
            WorkTaskType::SessionOrientation->value => WorkTaskStatus::Complete->value,
        ], $targetTasks->sortBy(fn (WorkTask $task): string => $task->task_type->value)
            ->mapWithKeys(fn (WorkTask $task): array => [$task->task_type->value => $task->status->value])
            ->all());

        foreach ($targetTasks as $task) {
            $this->assertSame($targetEncounter->getKey(), $task->encounter_id);
            $this->assertContains($task->assignment_id, $targetAssignmentIds);
            $this->assertSame([
                'caseLabel' => $targetEncounter->encounter_number,
                'synthetic' => true,
            ], $task->context);
            $this->assertNull($task->clinical_entry_id);
            $this->assertNull($task->clinical_entry_version_id);
        }

        $this->assertNotSame($sourceStock->public_id, $targetStock->public_id);
        $this->assertNotSame($sourceStock->lot_number, $targetStock->lot_number);
        $this->assertSame($sourceStock->authored_medication, $targetStock->authored_medication);
        $this->assertSame($sourceStock->quantity_on_hand, $targetStock->quantity_on_hand);
        $this->assertTrue($targetStock->synthetic_flag);

        foreach ($this->progressedEncounterTables() as $table) {
            $this->assertSame(0, DB::table($table)->where('encounter_id', $targetEncounter->getKey())->count(), $table);
        }
        $this->assertSame(0, DB::table('medication_stock_movements')->where('session_id', $target->getKey())->count());

        $event = AuditEvent::query()
            ->where('action', 'simulation_session.reference_cloned')
            ->where('session_id', $target->getKey())
            ->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->assignment_id);
        $this->assertNull($event->ip_hash);
        $this->assertNull($event->user_agent);
        $this->assertSame($source->public_id, $event->metadata['source_session_public_id']);
        $this->assertSame(15, $event->metadata['appointment_offset_minutes']);
        $this->assertSame(10, $event->metadata['assignment_count']);
        $this->assertSame(4, $event->metadata['task_count']);
        $this->assertFalse($event->metadata['progressed_state_copied']);

        $this->assertSame($sourceUpdatedAt?->toISOString(), $source->fresh()->updated_at?->toISOString());
        $this->assertSame($sourceIdentifierValues, $sourcePatient->identifiers()->orderBy('type')->pluck('value')->all());
        $this->assertSame($sourceTaskStates, WorkTask::query()
            ->where('session_id', $source->getKey())
            ->orderBy('task_type')
            ->pluck('status', 'task_type')
            ->all());
        $this->assertSame(AppointmentStatus::Booked, $sourceAppointment->fresh()->status);
        $this->assertSame(EncounterStatus::Planned, $sourceEncounter->fresh()->status);
        $this->assertSame($sourceStock->quantity_on_hand, $sourceStock->fresh()->quantity_on_hand);
    }

    public function test_json_output_is_identity_minimized(): void
    {
        $this->seedReferenceOutpatient();

        $exitCode = Artisan::call('simulation:clone-reference-session', [
            'code' => 'UAT-JSON-001',
            '--duration' => '120',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Pasien Sintetis Arunika', $output);
        $this->assertStringNotContainsString('MR-SIM-000001', $output);
        $this->assertStringNotContainsString('SYN-NIK-000001', $output);

        $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('CREATED', $summary['state']);
        $this->assertSame('UAT-JSON-001', $summary['session']['code']);
        $this->assertSame(10, $summary['counts']['assignments']);
        $this->assertSame(4, $summary['counts']['tasks']);
        $this->assertTrue($summary['synthetic']);
        $this->assertFalse($summary['progressedStateCopied']);
    }

    public function test_command_rejects_unsafe_invalid_and_duplicate_requests_without_overwriting_data(): void
    {
        $this->seedReferenceOutpatient();

        config(['simulation.synthetic_only' => false]);
        $this->artisan('simulation:clone-reference-session', ['code' => 'UAT-UNSAFE-001'])
            ->expectsOutputToContain('synthetic-only')
            ->assertFailed();
        $this->assertDatabaseMissing('simulation_sessions', ['code' => 'UAT-UNSAFE-001']);

        config(['simulation.synthetic_only' => true]);
        $this->artisan('simulation:clone-reference-session', ['code' => 'lowercase'])
            ->expectsOutputToContain('uppercase')
            ->assertFailed();
        $this->artisan('simulation:clone-reference-session', [
            'code' => 'UAT-SHORT-001',
            '--duration' => '29',
        ])->expectsOutputToContain('between 30 and 10,080')
            ->assertFailed();
        $this->artisan('simulation:clone-reference-session', [
            'code' => 'UAT-OFFSET-001',
            '--duration' => '60',
            '--appointment-offset' => '61',
        ])->expectsOutputToContain('Appointment offset')
            ->assertFailed();

        $this->artisan('simulation:clone-reference-session', ['code' => 'UAT-DUP-001'])
            ->assertSuccessful();
        $this->artisan('simulation:clone-reference-session', ['code' => 'UAT-DUP-001'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
        $this->assertSame(1, SimulationSession::query()->where('code', 'UAT-DUP-001')->count());

        $source = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->sole();
        SyntheticPatient::query()
            ->where('session_id', $source->getKey())
            ->where('fixture_source', 'OPD-REF-001-v1')
            ->sole()
            ->update([
                'phone' => 'synthetic-fixture-contamination',
            ]);
        $this->artisan('simulation:clone-reference-session', ['code' => 'UAT-CONTAMINATED-001'])
            ->expectsOutputToContain('reserved OPD-REF-001-v1 synthetic identity fixture')
            ->assertFailed();
        $this->assertDatabaseMissing('simulation_sessions', ['code' => 'UAT-CONTAMINATED-001']);
    }

    public function test_command_refuses_a_progressed_source_and_a_pristine_clone_can_be_chained(): void
    {
        $this->seedReferenceOutpatient();

        $this->artisan('simulation:clone-reference-session', ['code' => 'UAT-PRISTINE-001'])
            ->assertSuccessful();
        $this->artisan('simulation:clone-reference-session', [
            'code' => 'UAT-CHAINED-001',
            '--source' => 'UAT-PRISTINE-001',
        ])->assertSuccessful();

        $source = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->sole();
        $appointment = AppointmentRegistration::query()->where('session_id', $source->getKey())->sole();
        $registrar = Assignment::query()
            ->where('session_id', $source->getKey())
            ->get()
            ->sole(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PatientRegister));
        app(AppointmentCheckInService::class)->checkIn($appointment, $registrar);

        $this->artisan('simulation:clone-reference-session', ['code' => 'UAT-PROGRESSED-001'])
            ->expectsOutputToContain('progressed')
            ->assertFailed();
        $this->assertDatabaseMissing('simulation_sessions', ['code' => 'UAT-PROGRESSED-001']);

        $pristine = SimulationSession::query()->where('code', 'UAT-PRISTINE-001')->sole();
        $chained = SimulationSession::query()->where('code', 'UAT-CHAINED-001')->sole();
        $this->assertSame($pristine->getKey(), $chained->source_session_id);
    }

    public function test_negative_appointment_offset_prepares_an_immediately_executable_no_show_branch(): void
    {
        $this->seedReferenceOutpatient();

        $this->artisan('simulation:clone-reference-session', [
            'code' => 'UAT-NO-SHOW-001',
            '--appointment-offset' => '-5',
        ])->assertSuccessful();

        $session = SimulationSession::query()->where('code', 'UAT-NO-SHOW-001')->sole();
        $appointment = AppointmentRegistration::query()->where('session_id', $session->getKey())->sole();
        $registrar = Assignment::query()
            ->where('session_id', $session->getKey())
            ->get()
            ->sole(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PatientRegister));

        $this->assertTrue($appointment->scheduled_at->isPast());

        $terminated = app(AppointmentTerminationService::class)->terminate(
            $appointment,
            $registrar,
            VisitTerminationOutcome::NoShow,
            'Pasien sintetis tidak hadir pada jadwal latihan.',
        );

        $this->assertSame(AppointmentStatus::NoShow, $terminated->status);
        $this->assertSame(EncounterStatus::NoShow, $terminated->encounter->status);
        $this->assertDatabaseCount('queue_events', 0);
    }

    public function test_late_audit_failure_rolls_back_the_entire_target_graph(): void
    {
        $this->seedReferenceOutpatient();
        $before = $this->materialCounts();

        $this->mock(AuditRecorder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')
                ->once()
                ->andThrow(new RuntimeException('forced late audit failure'));
        });

        $this->artisan('simulation:clone-reference-session', ['code' => 'UAT-ROLLBACK-001'])
            ->expectsOutputToContain('No partial target graph was retained')
            ->assertFailed();

        $this->assertDatabaseMissing('simulation_sessions', ['code' => 'UAT-ROLLBACK-001']);
        $this->assertSame($before, $this->materialCounts());
    }

    /** @return list<string> */
    private function progressedEncounterTables(): array
    {
        return [
            'queue_events',
            'clinical_entries',
            'service_requests',
            'medication_requests',
            'pharmacy_reviews',
            'medication_dispense_preparations',
            'medication_dispenses',
            'encounter_closures',
            'record_quality_reviews',
            'coding_assignments',
            'outpatient_safety_dispositions',
            'outpatient_early_departures',
            'debrief_notes',
        ];
    }

    /** @return array<string, int> */
    private function materialCounts(): array
    {
        return collect([
            'simulation_sessions',
            'synthetic_patients',
            'patient_identifiers',
            'appointment_registrations',
            'encounters',
            'assignments',
            'encounter_transitions',
            'work_tasks',
            'medication_stocks',
            'audit_events',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
