<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientBed;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientDischargeSummaryOperationReceipt;
use App\Models\InpatientDischargeSummaryVersion;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientDischargeSummaryActorPolicy;
use App\Support\Inpatient\InpatientDischargeSummaryAuditUnavailable;
use App\Support\Inpatient\InpatientDischargeSummaryDenied;
use App\Support\Inpatient\InpatientDischargeSummaryMutationScope;
use App\Support\Inpatient\InpatientDischargeSummaryService;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RoutineInpatientDischargeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $registrar;

    private InpatientWard $ward;

    private InpatientBed $bed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [$this->ward, $this->bed] = $this->createWardAndBed();
    }

    public function test_assigned_physician_versions_and_finalizes_one_episode_summary_without_workflow_side_effects(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter();
        $service = app(InpatientDischargeSummaryService::class);

        $draft = $service->saveDraft(
            $encounter->public_id,
            $physician,
            InpatientDischargeSummary::DEFINITION_VERSION,
            0,
            ['admission_reason' => '  Demam tinggi  '],
            'DISCHARGE-DRAFT-0001',
        );

        $this->assertFalse($draft->replayed);
        $this->assertSame(InpatientDischargeSummary::STATE_DRAFT, $draft->summary->summary_state);
        $this->assertSame('Demam tinggi', $draft->summary->admission_reason);
        $this->assertNull($draft->summary->follow_up_plan);
        $this->assertSame($physician->id, $draft->summary->assigned_physician_user_id);
        $this->assertSame(1, $draft->resultVersion->location_sequence);
        $this->assertSame(InpatientDischargeSummary::DEFINITION_VERSION, $draft->resultVersion->definition_version);
        $this->assertSame('ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1', $draft->resultVersion->definition_version);
        $this->assertNotNull($draft->resultVersion->location_event_public_id);
        $this->assertSame('ADMISSION_LOCATION', $draft->resultVersion->location_event_type);
        $this->assertNull($draft->resultVersion->history_baseline);
        $this->assertTrue($draft->resultVersion->history_complete);
        $this->assertSame($this->ward->public_id, $draft->resultVersion->ward_public_id);
        $this->assertSame($this->bed->public_id, $draft->resultVersion->bed_public_id);

        $complete = $this->completeFields();
        $service->saveDraft(
            $encounter->public_id,
            $physician,
            InpatientDischargeSummary::DEFINITION_VERSION,
            1,
            $complete,
            'DISCHARGE-DRAFT-0002',
        );

        $beforeEncounter = $encounter->fresh()->only(['status', 'inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class']);
        $beforeCounts = [
            'audit' => AuditEvent::query()->count(),
            'location' => $encounter->inpatientLocationEvents()->count(),
        ];
        $final = $service->finalize(
            $encounter->public_id,
            $physician,
            InpatientDischargeSummary::DEFINITION_VERSION,
            2,
            'DISCHARGE-FINAL-0001',
        );

        $this->assertSame(InpatientDischargeSummary::STATE_FINAL, $final->summary->summary_state);
        $this->assertSame(3, $final->summary->version);
        $this->assertSame($physician->id, $final->summary->finalized_by_user_id);
        $this->assertNotNull($final->summary->finalized_at);
        $this->assertSame([1, 2, 3], $final->summary->versions()->orderBy('version')->pluck('version')->all());
        $this->assertSame($beforeEncounter, $encounter->fresh()->only(['status', 'inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class']));
        $this->assertSame($beforeCounts['location'], $encounter->inpatientLocationEvents()->count());
        $this->assertSame($beforeCounts['audit'] + 1, AuditEvent::query()->count());

        $audit = AuditEvent::query()->where('action', 'clinical.inpatient.discharge-summary.finalize')->sole();
        $encodedMetadata = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        foreach ($complete as $narrative) {
            $this->assertStringNotContainsString($narrative, $encodedMetadata);
        }

        $replay = $service->finalize(
            $encounter->public_id,
            $physician,
            InpatientDischargeSummary::DEFINITION_VERSION,
            2,
            'discharge-final-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame(3, $replay->resultVersion->version);
        $this->assertDatabaseCount('inpatient_discharge_summaries', 1);
        $this->assertDatabaseCount('inpatient_discharge_summary_versions', 3);
        $this->assertDatabaseCount('inpatient_discharge_summary_operation_receipts', 3);
        $this->assertSame(
            ['DISCHARGE_SUMMARY_DRAFT_SAVE', 'DISCHARGE_SUMMARY_DRAFT_SAVE', 'DISCHARGE_SUMMARY_FINALIZE'],
            InpatientDischargeSummaryOperationReceipt::query()->orderBy('id')->pluck('operation')->all(),
        );
    }

    public function test_legacy_current_placement_is_snapshotted_truthfully_without_fabricating_an_event(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter(recordAdmission: false);

        $result = app(InpatientDischargeSummaryService::class)->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, [], 'LEGACY-DRAFT-0001',
        );

        $version = $result->resultVersion;
        $this->assertSame(0, $version->location_sequence);
        $this->assertNull($version->location_event_public_id);
        $this->assertNull($version->location_event_type);
        $this->assertSame(InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT, $version->history_baseline);
        $this->assertFalse($version->history_complete);
        $this->assertDatabaseCount('inpatient_location_events', 0);
    }

    public function test_locked_episode_with_retained_cancellation_fact_is_denied_even_if_status_is_active(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter();
        EncounterCancellation::query()->create([
            'encounter_id' => $encounter->id,
            'cancelled_by_user_id' => $this->registrar->id,
            'reason_code' => EncounterCancellation::REASON_WRONG_REGISTRATION,
            'note' => null,
            'idempotency_key' => 'retained-cancellation-fact',
            'payload_digest' => str_repeat('a', 64),
            'request_correlation_id' => null,
            'cancelled_at' => now(),
        ]);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);

        try {
            app(InpatientDischargeSummaryService::class)->saveDraft(
                $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
                0, [], 'CANCEL-FACT-0001',
            );
            $this->fail('Retained cancellation fact unexpectedly allowed a summary.');
        } catch (InpatientDischargeSummaryDenied $denial) {
            $this->assertSame('encounter_cancelled', $denial->reason);
            $this->assertDatabaseCount('inpatient_discharge_summaries', 0);
        }
    }

    public function test_final_digest_binds_stored_content_and_replay_rejects_corrupt_receipt_binding(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter();
        $service = app(InpatientDischargeSummaryService::class);
        $service->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, $this->completeFields(), 'DIGEST-DRAFT-0001',
        );
        $final = $service->finalize(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            1, 'DIGEST-FINAL-0001',
        );
        $receipt = InpatientDischargeSummaryOperationReceipt::query()
            ->where('operation', InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE)
            ->sole();
        $fields = [];
        foreach (InpatientDischargeSummary::NARRATIVE_FIELDS as $field) {
            $fields[$field] = $final->resultVersion->getAttribute($field);
        }
        $contentDigest = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $expectedPayload = [
            'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
            'encounter_public_id' => $encounter->public_id,
            'expected_version' => 1,
            'operation' => InpatientDischargeSummaryOperationReceipt::OPERATION_FINALIZE,
            'current_summary_public_id' => $final->summary->public_id,
            'current_summary_version' => 1,
            'stored_content_digest' => $contentDigest,
        ];
        $this->assertSame(
            hash('sha256', json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $receipt->payload_digest,
        );

        InpatientDischargeSummaryMutationScope::run(fn () => DB::table('inpatient_discharge_summary_operation_receipts')
            ->where('id', $receipt->id)
            ->update(['payload_digest' => str_repeat('f', 64)]));
        try {
            $service->finalize(
                $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
                1, 'digest-final-0001',
            );
            $this->fail('Corrupt receipt binding unexpectedly replayed.');
        } catch (InpatientDischargeSummaryDenied $denial) {
            $this->assertSame('receipt_binding_invalid', $denial->reason);
        }
    }

    public function test_exact_physician_role_capability_and_first_creator_ownership_are_enforced(): void
    {
        $owner = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $other = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->createActiveEncounter();
        $service = app(InpatientDischargeSummaryService::class);

        $this->assertTrue($owner->canCapability(Capability::INPATIENT_DISCHARGE_SUMMARY_WRITE));
        $this->assertFalse($nurse->canCapability(Capability::INPATIENT_DISCHARGE_SUMMARY_WRITE));
        $service->saveDraft(
            $encounter->public_id, $owner, InpatientDischargeSummary::DEFINITION_VERSION,
            0, [], 'OWNER-DRAFT-0001',
        );

        try {
            $service->saveDraft(
                $encounter->public_id, $other, InpatientDischargeSummary::DEFINITION_VERSION,
                1, $this->completeFields(), 'OTHER-DRAFT-0001',
            );
            $this->fail('A different physician unexpectedly changed the assigned summary.');
        } catch (InpatientDischargeSummaryDenied $denial) {
            $this->assertSame('physician_assignment_mismatch', $denial->reason);
        }

        $this->expectException(AuthorizationException::class);
        $service->saveDraft(
            $encounter->public_id, $nurse, InpatientDischargeSummary::DEFINITION_VERSION,
            1, [], 'NURSE-DRAFT-0001',
        );
    }

    public function test_validation_optimistic_lock_terminal_state_and_active_episode_boundaries_fail_closed(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter();
        $service = app(InpatientDischargeSummaryService::class);
        $service->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, [], 'BOUNDARY-DRAFT-001',
        );

        foreach ([
            fn () => $service->finalize($encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION, 1, 'BOUNDARY-FINAL-001'),
            fn () => $service->saveDraft($encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION, 0, [], 'BOUNDARY-DRAFT-002'),
            fn () => $service->saveDraft($encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION, 1, ['unknown' => 'narrative'], 'BOUNDARY-DRAFT-003'),
            fn () => $service->saveDraft($encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION, 1, ['admission_reason' => 'different'], 'BOUNDARY-DRAFT-001'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected governed discharge summary denial.');
            } catch (InpatientDischargeSummaryDenied) {
                $this->assertSame(1, InpatientDischargeSummary::query()->firstOrFail()->version);
                $this->assertDatabaseCount('inpatient_discharge_summary_versions', 1);
            }
        }

        $service->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            1, $this->completeFields(), 'BOUNDARY-DRAFT-004',
        );
        $service->finalize(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            2, 'BOUNDARY-FINAL-002',
        );
        try {
            $service->saveDraft(
                $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
                3, $this->completeFields(), 'BOUNDARY-DRAFT-005',
            );
            $this->fail('Final summary unexpectedly changed.');
        } catch (InpatientDischargeSummaryDenied $denial) {
            $this->assertSame('summary_final', $denial->reason);
        }

        $otherEncounter = $this->createActiveEncounter();
        $otherEncounter->update(['status' => Encounter::STATUS_CLOSED]);
        try {
            $service->saveDraft(
                $otherEncounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
                0, [], 'CLOSED-DRAFT-0001',
            );
            $this->fail('Closed episode unexpectedly accepted a discharge summary.');
        } catch (InpatientDischargeSummaryDenied $denial) {
            $this->assertSame('encounter_closed', $denial->reason);
        }
    }

    public function test_required_audit_failure_rolls_back_head_version_and_receipt_atomically(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter();
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andReturnNull();
        $service = new InpatientDischargeSummaryService(
            $audit,
            app(InpatientDischargeSummaryActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
        );

        try {
            $service->saveDraft(
                $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
                0, $this->completeFields(), 'AUDIT-FAIL-DRAFT1',
            );
            $this->fail('Audit failure unexpectedly committed the summary.');
        } catch (InpatientDischargeSummaryAuditUnavailable) {
            $this->assertDatabaseCount('inpatient_discharge_summaries', 0);
            $this->assertDatabaseCount('inpatient_discharge_summary_versions', 0);
            $this->assertDatabaseCount('inpatient_discharge_summary_operation_receipts', 0);
        }
    }

    public function test_model_and_sql_guards_protect_head_and_append_only_evidence(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter();
        app(InpatientDischargeSummaryService::class)->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, [], 'GUARD-DRAFT-00001',
        );
        $summary = InpatientDischargeSummary::query()->firstOrFail();

        foreach ([
            fn () => $summary->update(['version' => 2]),
            fn () => InpatientDischargeSummaryVersion::query()->firstOrFail()->update(['version' => 2]),
            fn () => InpatientDischargeSummaryOperationReceipt::query()->firstOrFail()->delete(),
            fn () => DB::table('inpatient_discharge_summary_versions')->where('id', 1)->update(['version' => 2]),
            fn () => DB::table('inpatient_discharge_summaries')->delete(),
        ] as $bypass) {
            try {
                $bypass();
                $this->fail('Discharge summary bypass unexpectedly succeeded.');
            } catch (LogicException) {
                $this->assertSame(1, $summary->fresh()->version);
                $this->assertDatabaseCount('inpatient_discharge_summary_versions', 1);
            }
        }
    }

    public function test_bounded_synthetic_reset_removes_summary_chain_and_down_refuses_retained_audit(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->createActiveEncounter();
        app(InpatientDischargeSummaryService::class)->saveDraft(
            $encounter->public_id, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, [], 'RESET-DRAFT-00001',
        );

        app(SyntheticResetService::class)->reset(['actor' => $this->admin, 'reason' => 'reset_discharge_summary_test']);
        $this->assertDatabaseCount('inpatient_discharge_summaries', 0);
        $this->assertDatabaseCount('inpatient_discharge_summary_versions', 0);
        $this->assertDatabaseCount('inpatient_discharge_summary_operation_receipts', 0);

        $migration = require database_path('migrations/2026_08_31_000600_create_inpatient_discharge_summary_tables.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('correlated audit evidence remains');
        $migration->down();
    }

    private function createActiveEncounter(bool $recordAdmission = true): Encounter
    {
        $patient = Patient::factory()->create([
            'created_by_user_id' => $this->registrar->id,
            'is_synthetic' => true,
        ]);
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $this->registrar->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'inpatient_bed_id' => $this->bed->id,
            'ward_name' => $this->ward->display_name,
            'ward_class' => $this->bed->service_class,
            'bed_code' => $this->bed->code,
        ]));
        if ($recordAdmission) {
            DB::transaction(fn () => app(InpatientBedTransferService::class)->recordAdmission(
                $encounter,
                $this->ward,
                $this->bed,
                $this->registrar,
                null,
            ));
        }

        return $encounter;
    }

    /** @return array{InpatientWard, InpatientBed} */
    private function createWardAndBed(): array
    {
        $master = app(InpatientMasterService::class);
        $ward = $master->createWard(
            $this->admin, 'RI-PULANG', 'Bangsal Pulang', InpatientMasterService::REASON_INITIAL_SETUP,
            'SUMMARY-WARD-0001', null,
        )->master;
        if (! $ward instanceof InpatientWard) {
            throw new LogicException('Expected inpatient ward.');
        }
        $bed = $master->createBed(
            $this->admin, $ward->public_id, 'P-01', 'Bed P-01', 'Ruang Pulang', 'Kelas 1',
            InpatientMasterService::REASON_INITIAL_SETUP, 'SUMMARY-BED-00001', null,
        )->master;
        if (! $bed instanceof InpatientBed) {
            throw new LogicException('Expected inpatient bed.');
        }

        return [$ward, $bed];
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /** @return array<string, string> */
    private function completeFields(): array
    {
        return [
            'admission_reason' => 'Demam tinggi selama tiga hari',
            'significant_findings' => 'Suhu meningkat dan dehidrasi ringan',
            'care_and_treatment_summary' => 'Rehidrasi dan pemantauan rutin',
            'condition_at_discharge' => 'Stabil dan afebris',
            'follow_up_plan' => 'Kontrol poliklinik tujuh hari lagi',
        ];
    }
}
