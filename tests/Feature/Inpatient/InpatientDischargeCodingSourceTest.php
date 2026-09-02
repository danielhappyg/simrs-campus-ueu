<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientBedTransferDenied;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientDischargeCodingSourceActorPolicy;
use App\Support\Inpatient\InpatientDischargeCodingSourceAuditUnavailable;
use App\Support\Inpatient\InpatientDischargeCodingSourceDenied;
use App\Support\Inpatient\InpatientDischargeCodingSourceResult;
use App\Support\Inpatient\InpatientDischargeCodingSourceService;
use App\Support\Inpatient\InpatientDischargeDenied;
use App\Support\Inpatient\InpatientDischargeService;
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

class InpatientDischargeCodingSourceTest extends TestCase
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
        $this->admin = $this->user(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->user(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->physician = $this->user(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$this->ward,$this->bed] = $this->masters();
    }

    public function test_original_assigned_physician_versions_finalizes_and_replays_exact_source_without_side_effects(): void
    {
        $encounter = $this->encounter();
        $this->summaryDraft($encounter);
        $service = app(InpatientDischargeCodingSourceService::class);
        $draft = $service->saveDraft($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0, $this->fields(principal: ''), 'CODING-DRAFT-0001');
        $this->assertSame(InpatientDischargeCodingSource::STATE_DRAFT, $draft->source->source_state);
        $this->assertSame(1, $draft->resultVersion->location_sequence);
        $this->assertNull($draft->resultVersion->principal_diagnosis_statement);
        $this->assertSame($this->bed->public_id, $draft->resultVersion->bed_public_id);
        $updated = $service->saveDraft($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 1, $this->fields(), 'CODING-DRAFT-0002');
        $before = $encounter->fresh()->only(['status', 'inpatient_bed_id', 'bed_code']);
        $final = $service->finalize($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 2, 'CODING-FINAL-0001');
        $this->assertSame(InpatientDischargeCodingSource::STATE_FINAL, $final->source->source_state);
        $this->assertSame([1, 2, 3], $final->source->versions()->orderBy('version')->pluck('version')->all());
        $this->assertSame($before, $encounter->fresh()->only(['status', 'inpatient_bed_id', 'bed_code']));
        $audit = AuditEvent::query()->where('action', 'clinical.inpatient.discharge-coding-source.finalize')->sole();
        $encoded = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Gastroenteritis akut', $encoded);
        $replay = $service->finalize($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 2, 'coding-final-0001');
        $this->assertTrue($replay->replayed);
        $this->assertDatabaseCount('inpatient_discharge_coding_source_versions', 3);
        $this->assertDatabaseCount('inpatient_discharge_coding_source_operation_receipts', 3);
    }

    public function test_validation_exact_actor_author_and_optimistic_boundaries_fail_closed(): void
    {
        $encounter = $this->encounter();
        $this->summaryDraft($encounter);
        $service = app(InpatientDischargeCodingSourceService::class);
        foreach ([['fields' => $this->fields(attestation: InpatientDischargeCodingSource::ATTESTATION_NONE, performed: ['Biopsi']), 'reason' => 'validation_failed'], ['fields' => $this->fields(secondary: ['Duplikat', 'Duplikat']), 'reason' => 'validation_failed']] as $case) {
            try {
                $service->saveDraft($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0, $case['fields'], uniqid('CODING-INVALID-', true));
                $this->fail('Expected validation denial.');
            } catch (InpatientDischargeCodingSourceDenied $d) {
                $this->assertSame($case['reason'], $d->reason);
            }
        }
        $draft = $service->saveDraft($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0, $this->fields(), 'CODING-OWNER-DRAFT');
        $other = $this->user(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        try {
            $service->saveDraft($encounter->public_id, $other, InpatientDischargeCodingSource::DEFINITION_VERSION, 1, $this->fields(), 'CODING-OTHER-DRAFT');
            $this->fail('Expected assigned physician denial.');
        } catch (InpatientDischargeCodingSourceDenied $d) {
            $this->assertSame('physician_assignment_mismatch', $d->reason);
        }
        $nurse = $this->user(RoleCapabilityMatrix::ROLE_NURSE);
        $this->expectException(AuthorizationException::class);
        $service->saveDraft($encounter->public_id, $nurse, InpatientDischargeCodingSource::DEFINITION_VERSION, $draft->source->version, $this->fields(), 'CODING-NURSE-DRAFT');
    }

    public function test_final_source_blocks_transfer_but_transfer_first_snapshots_new_placement(): void
    {
        $target = $this->extraBed();
        $encounter = $this->encounter();
        $this->summaryDraft($encounter);
        $this->finalSource($encounter);
        try {
            app(InpatientBedTransferService::class)->transfer($encounter->public_id, $this->registrar, 1, $this->bed->public_id, $target->public_id, 'Setelah diagnosis Final', 'CODING-TRANSFER-DENIED');
            $this->fail('Expected denial.');
        } catch (InpatientBedTransferDenied $d) {
            $this->assertSame('discharge_coding_source_final', $d->reason);
        }
        $source = $this->extraBed('C-03', 'CODING-BED-0003');
        $other = $this->encounterAt($source);
        $this->summaryDraft($other);
        app(InpatientBedTransferService::class)->transfer($other->public_id, $this->registrar, 1, $source->public_id, $target->public_id, 'Sebelum diagnosis Final', 'CODING-TRANSFER-FIRST');
        $final = $this->finalSource($other);
        $this->assertSame(2, $final->resultVersion->location_sequence);
        $this->assertSame($target->public_id, $final->resultVersion->bed_public_id);
    }

    public function test_routine_discharge_requires_exact_final_source(): void
    {
        $encounter = $this->encounter();
        $summary = $this->finalSummary($encounter);
        try {
            app(InpatientDischargeService::class)->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'CODING-PREREQ-DENIED');
            $this->fail('Expected source prerequisite.');
        } catch (InpatientDischargeDenied $d) {
            $this->assertSame('discharge_coding_source_not_final', $d->reason);
            $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()?->status);
        }
        $source = $this->finalSource($encounter);
        $discharge = app(InpatientDischargeService::class)->execute($encounter->public_id, $this->physician, $summary->version, 1, $this->bed->public_id, 'CODING-PREREQ-SUCCESS')->discharge;
        $this->assertSame($source->resultVersion->id, $discharge->inpatient_discharge_coding_source_version_id);
        $this->assertSame($source->resultVersion->public_id, $discharge->discharge_coding_source_version_public_id);
    }

    public function test_audit_failure_rolls_back_and_reset_down_guards_preserve_evidence_boundary(): void
    {
        $encounter = $this->encounter();
        $this->summaryDraft($encounter);
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andReturnNull();
        $service = new InpatientDischargeCodingSourceService($audit, app(InpatientDischargeCodingSourceActorPolicy::class), app(CanonicalInpatientBedOperationLockCoordinator::class));
        try {
            $service->saveDraft($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0, $this->fields(), 'CODING-AUDIT-FAIL');
            $this->fail('Expected audit failure.');
        } catch (InpatientDischargeCodingSourceAuditUnavailable) {
            $this->assertDatabaseCount('inpatient_discharge_coding_sources', 0);
            $this->assertDatabaseCount('inpatient_discharge_coding_source_versions', 0);
        }
        $this->finalSource($encounter);
        try {
            InpatientDischargeCodingSourceVersion::query()->firstOrFail()->update(['ward_code' => 'MUTATED']);
            $this->fail('Expected immutability.');
        } catch (LogicException) {
        }
        app(SyntheticResetService::class)->reset(['actor' => $this->admin, 'reason' => 'reset_coding_source_test']);
        $this->assertDatabaseCount('inpatient_discharge_coding_sources', 0);
        $this->assertDatabaseCount('inpatient_discharge_coding_source_versions', 0);
        $migration = require database_path('migrations/2026_08_31_000800_create_inpatient_discharge_coding_source_tables.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function summaryDraft(Encounter $e): void
    {
        app(InpatientDischargeSummaryService::class)->saveDraft($e->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION, 0, ['admission_reason' => 'Demam'], 'CODING-SUMMARY-DRAFT-'.$e->id);
    }

    private function finalSummary(Encounter $e): InpatientDischargeSummary
    {
        $s = app(InpatientDischargeSummaryService::class);
        $d = $s->saveDraft($e->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION, 0, ['admission_reason' => 'Demam', 'significant_findings' => 'Dehidrasi', 'care_and_treatment_summary' => 'Rehidrasi', 'condition_at_discharge' => 'Stabil', 'follow_up_plan' => 'Kontrol'], 'CODING-SUMMARY-FD-'.$e->id);

        return $s->finalize($e->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION, $d->summary->version, 'CODING-SUMMARY-FF-'.$e->id)->summary;
    }

    private function finalSource(Encounter $e): InpatientDischargeCodingSourceResult
    {
        $s = app(InpatientDischargeCodingSourceService::class);
        $d = $s->saveDraft($e->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0, $this->fields(), 'CODING-SOURCE-D-'.$e->id);

        return $s->finalize($e->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, $d->source->version, 'CODING-SOURCE-F-'.$e->id);
    }

    /** @return array<string,mixed> */
    private function fields(string $principal = 'Gastroenteritis akut', array $secondary = ['Dehidrasi ringan'], string $attestation = InpatientDischargeCodingSource::ATTESTATION_NONE, array $performed = []): array
    {
        return ['principal_diagnosis_statement' => $principal, 'secondary_diagnosis_statements' => $secondary, 'procedure_attestation' => $attestation, 'performed_procedure_statements' => $performed];
    }

    private function encounter(): Encounter
    {
        return $this->encounterAt($this->bed);
    }

    private function encounterAt(InpatientBed $bed): Encounter
    {
        $p = Patient::factory()->create(['created_by_user_id' => $this->registrar->id, 'is_synthetic' => true]);
        $e = InpatientLocationMutationScope::run(fn () => Encounter::factory()->create(['patient_id' => $p->id, 'registered_by_user_id' => $this->registrar->id, 'care_setting' => Encounter::CARE_SETTING_INPATIENT, 'status' => Encounter::STATUS_REGISTERED, 'inpatient_bed_id' => $bed->id, 'ward_name' => $this->ward->display_name, 'ward_class' => $bed->service_class, 'bed_code' => $bed->code]));
        DB::transaction(fn () => app(InpatientBedTransferService::class)->recordAdmission($e, $this->ward, $bed, $this->registrar, null));

        return $e;
    }

    /** @return array{InpatientWard,InpatientBed} */
    private function masters(): array
    {
        $s = app(InpatientMasterService::class);
        $w = $s->createWard($this->admin, 'RI-CODING', 'Bangsal Coding', InpatientMasterService::REASON_INITIAL_SETUP, 'CODING-WARD-0001', null)->master;
        $b = $s->createBed($this->admin, $w->public_id, 'C-01', 'Bed C-01', 'Ruang Coding', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'CODING-BED-0001', null)->master;

        return [$w, $b];
    }

    private function extraBed(string $code = 'C-02', string $key = 'CODING-BED-0002'): InpatientBed
    {
        return app(InpatientMasterService::class)->createBed($this->admin, $this->ward->public_id, $code, 'Bed '.$code, 'Ruang Coding', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, $key, null)->master;
    }

    private function user(string $slug): User
    {
        $u = User::factory()->create();
        $r = Role::query()->where('slug', $slug)->firstOrFail();
        $u->roles()->sync([$r->id]);

        return $u;
    }
}
