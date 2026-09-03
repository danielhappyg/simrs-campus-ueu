<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientDischarge;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyPrescription;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientBedTransferDenied;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientDischargeActorPolicy;
use App\Support\Inpatient\InpatientDischargeAuditUnavailable;
use App\Support\Inpatient\InpatientDischargeCodingSourceEvidenceDigest;
use App\Support\Inpatient\InpatientDischargeCodingSourceService;
use App\Support\Inpatient\InpatientDischargeDenied;
use App\Support\Inpatient\InpatientDischargeMutationScope;
use App\Support\Inpatient\InpatientDischargeService;
use App\Support\Inpatient\InpatientDischargeSummaryEvidenceDigest;
use App\Support\Inpatient\InpatientDischargeSummaryService;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Registration\InpatientBedClaimGuard;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Support\ExactEngineTestFixture;
use Tests\TestCase;

class RoutineInpatientDischargeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $registrar;

    private User $physician;

    private InpatientWard $ward;

    private InpatientBed $bed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$this->ward, $this->bed] = $this->createWardAndBed();
    }

    public function test_assigned_physician_executes_one_atomic_routine_discharge_and_identical_retry_replays(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        $service = app(InpatientDischargeService::class);
        $result = $service->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'DISCHARGE-EXECUTE-0001');

        $this->assertFalse($result->replayed);
        $this->assertSame(InpatientDischarge::DISPOSITION_ROUTINE_HOME, $result->discharge->disposition_code);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()?->status);
        $this->assertSame($this->bed->id, $encounter->fresh()?->inpatient_bed_id);
        $this->assertSame($this->bed->code, $encounter->fresh()?->bed_code);
        $this->assertFalse($encounter->fresh()?->occupiesInpatientBed());
        DB::transaction(fn () => app(InpatientBedClaimGuard::class)->assertAvailable($this->bed->code, $this->bed->id));
        $this->assertDatabaseCount('inpatient_discharges', 1);
        $this->assertDatabaseCount('inpatient_discharge_operation_receipts', 1);
        $this->assertDatabaseCount('inpatient_location_events', 1);
        $finalVersion = $summary->versions()->where('version', $summary->version)->sole();
        $this->assertSame($finalVersion->id, $result->discharge->inpatient_discharge_summary_version_id);
        $this->assertSame($finalVersion->public_id, $result->discharge->discharge_summary_version_public_id);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $result->discharge->discharge_summary_content_digest);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $result->discharge->discharge_summary_provenance_digest);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $result->discharge->discharge_coding_source_content_digest);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $result->discharge->discharge_coding_source_provenance_digest);

        $audit = AuditEvent::query()->where('action', 'clinical.inpatient.discharge.execute')->where('outcome', 'SUCCESS')->sole();
        $this->assertTrue($audit->metadata['inpatient_bed_released']);
        $this->assertArrayNotHasKey('condition_at_discharge', $audit->metadata);

        $replay = $service->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'discharge-execute-0001');
        $this->assertTrue($replay->replayed);
        $this->assertSame($result->discharge->public_id, $replay->discharge->public_id);
        $this->assertDatabaseCount('inpatient_discharges', 1);
        $this->assertDatabaseCount('inpatient_discharge_operation_receipts', 1);
    }

    public function test_http_projection_and_command_expose_only_the_bounded_routine_discharge(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        $this->actingAs($this->physician)->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inpatient_discharge.disposition.code', InpatientDischarge::DISPOSITION_ROUTINE_HOME)
                ->where('inpatient_discharge.disposition.label', InpatientDischarge::DISPOSITION_ROUTINE_HOME_LABEL)
                ->where('inpatient_discharge.permission.can_execute', true)
                ->where('inpatient_discharge.requirements.expected_summary_version', $summary->version)
                ->where('inpatient_discharge.requirements.current_location_sequence', 1)
                ->where('inpatient_discharge.requirements.source_bed_public_id', $this->bed->public_id)
                ->where('inpatient_discharge.actions.execute_url', route('pemeriksaan.rawat-inap.discharge.execute', $encounter, false)));

        $this->actingAs($this->physician)->post(route('pemeriksaan.rawat-inap.discharge.execute', $encounter), [
            'expected_summary_version' => $summary->version,
            'expected_location_sequence' => 1,
            'source_bed_public_id' => $this->bed->public_id,
            'idempotency_key' => 'DISCHARGE-HTTP-00001',
        ])->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()?->status);
    }

    public function test_final_summary_owner_source_bed_and_location_sequence_are_fail_closed(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        $other = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        foreach ([
            fn () => app(InpatientDischargeService::class)->execute($encounter->public_id, $other, $summary->version, 1, $this->bed->public_id, 'DISCHARGE-OWNER-0001'),
            fn () => app(InpatientDischargeService::class)->execute($encounter->public_id, $this->physician, $summary->version, 0, $this->bed->public_id, 'DISCHARGE-LOCATION-01'),
            fn () => app(InpatientDischargeService::class)->execute($encounter->public_id, $this->physician, $summary->version, 1, (string) Str::ulid(), 'DISCHARGE-BED-000001'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected governed discharge denial.');
            } catch (InpatientDischargeDenied) {
                $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()?->status);
                $this->assertDatabaseCount('inpatient_discharges', 0);
            }
        }
    }

    public function test_transfer_is_denied_after_final_summary_but_transfer_first_finalizes_at_new_bed(): void
    {
        $target = $this->createAdditionalBed('D-02', 'DISCHARGE-BED-MASTER-02');
        [$finalEncounter, $finalSummary] = $this->finalSummaryEncounter();
        try {
            app(InpatientBedTransferService::class)->transfer(
                $finalEncounter->public_id, $this->registrar, 1, $this->bed->public_id,
                $target->public_id, 'Pindah setelah ringkasan Final', 'TRANSFER-AFTER-FINAL-01',
            );
            $this->fail('Expected transfer denial after Final summary.');
        } catch (InpatientBedTransferDenied $denial) {
            $this->assertSame('discharge_summary_final', $denial->reason);
            $this->assertSame($this->bed->id, $finalEncounter->fresh()?->inpatient_bed_id);
        }
        app(InpatientDischargeService::class)->execute(
            $finalEncounter->public_id, $this->physician, $finalSummary->version, 1,
            $this->bed->public_id, 'DISCHARGE-AFTER-TRANSFER-DENIAL',
        );

        $encounter = $this->createActiveEncounter();
        app(InpatientBedTransferService::class)->transfer(
            $encounter->public_id, $this->registrar, 1, $this->bed->public_id,
            $target->public_id, 'Pindah sebelum ringkasan Final', 'TRANSFER-BEFORE-FINAL-1',
        );
        $summaries = app(InpatientDischargeSummaryService::class);
        $draft = $summaries->saveDraft(
            $encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, $this->completeFields(), 'TRANSFERRED-SUMMARY-DRAFT',
        );
        $final = $summaries->finalize(
            $encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION,
            $draft->summary->version, 'TRANSFERRED-SUMMARY-FINAL',
        );
        $this->assertSame(2, $final->resultVersion->location_sequence);
        $this->assertSame($target->public_id, $final->resultVersion->bed_public_id);
        $this->finalCodingSource($encounter);

        $discharge = app(InpatientDischargeService::class)->execute(
            $encounter->public_id, $this->physician, $final->summary->version, 2,
            $target->public_id, 'TRANSFERRED-DISCHARGE-01',
        )->discharge;
        $this->assertSame($target->public_id, $discharge->source_bed_public_id);
        $this->assertSame($final->resultVersion->id, $discharge->inpatient_discharge_summary_version_id);
    }

    public function test_replay_recomputes_exact_summary_version_content_and_provenance_bindings(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        $service = app(InpatientDischargeService::class);
        $service->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'DISCHARGE-REPLAY-BIND-1');
        ExactEngineTestFixture::corruptWithPostgresTriggersDisabled(
            ['inpatient_discharge_operation_receipts'],
            function () use ($service, $encounter, $summary): void {
                InpatientDischargeMutationScope::run(fn () => DB::table('inpatient_discharge_operation_receipts')->update([
                    'discharge_summary_provenance_digest' => str_repeat('0', 64),
                ]));

                try {
                    $service->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'DISCHARGE-REPLAY-BIND-1');
                    $this->fail('Expected corrupt replay binding denial.');
                } catch (InpatientDischargeDenied $denial) {
                    $this->assertSame('receipt_binding_invalid', $denial->reason);
                }
            },
        );
    }

    public function test_exact_role_capability_and_idempotency_conflicts_are_enforced(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $this->expectException(AuthorizationException::class);
        try {
            app(InpatientDischargeService::class)->execute($encounter->public_id, $nurse, $summary->version, 1, $this->bed->public_id, 'DISCHARGE-AUTH-00001');
        } finally {
            $this->assertDatabaseCount('inpatient_discharges', 0);
        }
    }

    public function test_required_audit_failure_rolls_back_discharge_status_release_and_receipt(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andReturnNull();
        $service = new InpatientDischargeService(
            $audit,
            app(InpatientDischargeActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
            app(InpatientBedClaimGuard::class),
            app(InpatientDischargeSummaryEvidenceDigest::class),
            app(InpatientDischargeCodingSourceEvidenceDigest::class),
            app(PharmacyEncounterLifecycleGate::class),
        );

        try {
            $service->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'DISCHARGE-AUDIT-0001');
            $this->fail('Expected audit failure.');
        } catch (InpatientDischargeAuditUnavailable) {
            $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()?->status);
            $this->assertTrue($encounter->fresh()?->occupiesInpatientBed());
            $this->assertDatabaseCount('inpatient_discharges', 0);
            $this->assertDatabaseCount('inpatient_discharge_operation_receipts', 0);
        }
    }

    public function test_active_pharmacy_prescription_blocks_discharge_and_keeps_bed_claim(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        PharmacyMutationScope::run(function () use ($encounter): void {
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEPO_RI_DISCHARGE',
                'display_name' => 'Depo Rawat Inap Pemulangan',
                'eligible_care_settings' => [Encounter::CARE_SETTING_INPATIENT],
                'state' => PharmacyDepot::ACTIVE,
                'version' => 1,
                'current_content_digest' => str_repeat('a', 64),
            ]);
            PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordering_physician_user_id' => $this->physician->id,
                'depot_id' => $depot->id,
                'care_setting' => $encounter->care_setting,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => $encounter->ward_name.' · '.$encounter->bed_code,
                'location_fingerprint' => str_repeat('b', 64),
                'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code,
                'status' => PharmacyPrescription::ORDERED,
                'version' => 1,
                'current_content_digest' => str_repeat('c', 64),
                'ordered_at' => now(),
            ]);
        });

        try {
            app(InpatientDischargeService::class)->execute(
                $encounter->public_id,
                $this->physician,
                $summary->version,
                1,
                $this->bed->public_id,
                'discharge-active-pharmacy-0001',
            );
            $this->fail('Expected active pharmacy prescription denial.');
        } catch (InpatientDischargeDenied $denial) {
            $this->assertSame('active_pharmacy_prescriptions', $denial->reason);
        }

        $fresh = $encounter->fresh();
        $this->assertSame(Encounter::STATUS_REGISTERED, $fresh?->status);
        $this->assertSame($this->bed->id, $fresh?->inpatient_bed_id);
        $this->assertTrue($fresh?->occupiesInpatientBed() ?? false);
        $this->assertDatabaseCount('inpatient_discharges', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.inpatient.discharge.execute',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'active_pharmacy_prescriptions',
        ]);
    }

    public function test_idempotency_conflict_append_only_guards_reset_and_down_evidence_guard(): void
    {
        [$encounter, $summary] = $this->finalSummaryEncounter();
        $service = app(InpatientDischargeService::class);
        $service->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'DISCHARGE-GUARD-0001');

        try {
            $service->execute($encounter->public_id, $this->physician, $summary->version + 1, 1, $this->bed->public_id, 'DISCHARGE-GUARD-0001');
            $this->fail('Expected idempotency conflict.');
        } catch (InpatientDischargeDenied $denial) {
            $this->assertSame('idempotency_key_conflict', $denial->reason);
        }
        try {
            InpatientDischarge::query()->sole()->update(['source_bed_code' => 'MUTATED']);
            $this->fail('Expected immutable model denial.');
        } catch (LogicException) {
            $this->assertDatabaseCount('inpatient_discharges', 1);
        }
        try {
            DB::table('inpatient_discharge_operation_receipts')->delete();
            $this->fail('Expected direct SQL guard denial.');
        } catch (LogicException) {
            $this->assertDatabaseCount('inpatient_discharge_operation_receipts', 1);
        }

        app(SyntheticResetService::class)->reset(['actor' => $this->admin, 'reason' => 'reset_routine_discharge_test']);
        $this->assertDatabaseCount('inpatient_discharges', 0);
        $this->assertDatabaseCount('inpatient_discharge_operation_receipts', 0);
        $migration = require database_path('migrations/2026_08_31_000700_create_inpatient_discharge_tables.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    /** @return array{Encounter, InpatientDischargeSummary} */
    private function finalSummaryEncounter(): array
    {
        $encounter = $this->createActiveEncounter();
        $summaries = app(InpatientDischargeSummaryService::class);
        $draft = $summaries->saveDraft(
            $encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, $this->completeFields(), 'DISCHARGE-SUMMARY-DRAFT',
        );
        $final = $summaries->finalize($encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION, $draft->summary->version, 'DISCHARGE-SUMMARY-FINAL');

        $this->finalCodingSource($encounter);

        return [$encounter, $final->summary];
    }

    private function finalCodingSource(Encounter $encounter): void
    {
        $service = app(InpatientDischargeCodingSourceService::class);
        $draft = $service->saveDraft($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0, ['principal_diagnosis_statement' => 'Gastroenteritis akut', 'secondary_diagnosis_statements' => ['Dehidrasi ringan'], 'procedure_attestation' => InpatientDischargeCodingSource::ATTESTATION_NONE, 'performed_procedure_statements' => []], 'CODING-SOURCE-DRAFT-'.$encounter->id);
        $service->finalize($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, $draft->source->version, 'CODING-SOURCE-FINAL-'.$encounter->id);
    }

    private function createActiveEncounter(): Encounter
    {
        $patient = Patient::factory()->create(['created_by_user_id' => $this->registrar->id, 'is_synthetic' => true]);
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
        DB::transaction(fn () => app(InpatientBedTransferService::class)->recordAdmission($encounter, $this->ward, $this->bed, $this->registrar, null));

        return $encounter;
    }

    /** @return array<string, string> */
    private function completeFields(): array
    {
        return [
            'admission_reason' => 'Demam tiga hari',
            'significant_findings' => 'Dehidrasi ringan',
            'care_and_treatment_summary' => 'Rehidrasi dan observasi',
            'condition_at_discharge' => 'Stabil',
            'follow_up_plan' => 'Kontrol tujuh hari',
        ];
    }

    /** @return array{InpatientWard, InpatientBed} */
    private function createWardAndBed(): array
    {
        $service = app(InpatientMasterService::class);
        $ward = $service->createWard($this->admin, 'RI-DISCHARGE', 'Bangsal Pulang', InpatientMasterService::REASON_INITIAL_SETUP, 'DISCHARGE-WARD-0001', null)->master;
        if (! $ward instanceof InpatientWard) {
            throw new LogicException('Expected ward.');
        }
        $bed = $service->createBed($this->admin, $ward->public_id, 'D-01', 'Bed D-01', 'Ruang Pulang', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'DISCHARGE-BED-MASTER-01', null)->master;
        if (! $bed instanceof InpatientBed) {
            throw new LogicException('Expected bed.');
        }

        return [$ward, $bed];
    }

    private function createAdditionalBed(string $code, string $key): InpatientBed
    {
        $bed = app(InpatientMasterService::class)->createBed(
            $this->admin, $this->ward->public_id, $code, 'Bed '.$code, 'Ruang Pulang', 'Kelas 1',
            InpatientMasterService::REASON_INITIAL_SETUP, $key, null,
        )->master;
        if (! $bed instanceof InpatientBed) {
            throw new LogicException('Expected bed.');
        }

        return $bed;
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $slug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
