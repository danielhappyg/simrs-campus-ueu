<?php

namespace Tests\Feature\Inpatient;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientClinicalDocumentVersion;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\LockedClinicalEntryWriter;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientDocumentationActorPolicy;
use App\Support\Inpatient\InpatientDocumentationAuditUnavailable;
use App\Support\Inpatient\InpatientDocumentationDenied;
use App\Support\Inpatient\InpatientDocumentationService;
use App\Support\Inpatient\InpatientDocumentationSqlWriteGuard;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
use RuntimeException;
use Tests\TestCase;

class StructuredInpatientLongitudinalDocumentationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $registrar;

    private InpatientBed $bed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [, $this->bed] = $this->createWardAndBed();
    }

    public function test_nurse_versions_and_finalizes_daily_document_with_server_snapshots_and_replay(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $service = app(InpatientDocumentationService::class);

        $draft = $service->saveDraft(
            $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 0,
            ['additional_notes' => '  Catatan tambahan  '], 'NURSE-DRAFT-0001', null,
        );
        $this->assertFalse($draft->replayed);
        $this->assertSame(InpatientClinicalDocument::TYPE_NURSING_DAILY, $draft->resultVersion->document_type);
        $this->assertSame(1, $draft->resultVersion->version);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertSame(['additional_notes' => 'Catatan tambahan'], $draft->document->fields);

        $complete = $this->nursingFields();
        $service->saveDraft(
            $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 1, $complete, 'NURSE-DRAFT-0002', null,
        );
        $final = $service->finalize(
            $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 2, 'NURSE-FINAL-0001', null,
        );
        $this->assertSame(InpatientClinicalDocument::STATE_FINAL, $final->document->document_state);
        $this->assertSame(3, $final->document->version);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertSame([1, 2, 3], $final->document->versions()->orderBy('version')->pluck('version')->all());

        $firstVersion = $final->document->versions()->orderBy('version')->firstOrFail();
        $this->assertSame($encounter->public_id, $firstVersion->encounter_public_id);
        $this->assertSame('RI-MELATI', $firstVersion->ward_code);
        $this->assertSame('A-01', $firstVersion->bed_code);
        $this->assertSame(Encounter::STATUS_REGISTERED, $firstVersion->encounter_status);

        $replay = $service->finalize(
            $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 2, 'nurse-final-0001', null,
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame(3, $replay->resultVersion->version);
        $this->assertDatabaseCount('inpatient_clinical_document_versions', 3);
        $this->assertDatabaseCount('inpatient_document_operation_receipts', 3);
        $this->assertSame(1, AuditEvent::query()->where('action', 'clinical.inpatient.nursing.finalize')->where('outcome', 'SUCCESS')->count());
    }

    public function test_replay_returns_original_immutable_version_after_head_advances_and_next_day_status_changes(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $service = app(InpatientDocumentationService::class);
        $first = $service->saveDraft(
            $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 0,
            ['nursing_observation' => 'original'], 'REPLAY-TRUTH-0001',
        );
        $service->saveDraft(
            $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 1,
            ['nursing_observation' => 'later'], 'REPLAY-TRUTH-0002',
        );
        $encounter->update(['status' => Encounter::STATUS_CANCELLED]);
        $this->travel(1)->days();

        $replay = $service->saveDraft(
            $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 0,
            ['nursing_observation' => 'original'], 'replay-truth-0001',
        );

        $this->assertTrue($replay->replayed);
        $this->assertSame(2, $replay->document->version, 'Current-head projection remains explicitly current.');
        $this->assertSame(1, $replay->resultVersion->version, 'Receipt truth resolves to the immutable original version.');
        $this->assertSame(['nursing_observation' => 'original'], $replay->resultVersion->fields);
        $this->assertSame($first->resultVersion->public_id, $replay->resultVersion->public_id);
        $this->assertDatabaseCount('inpatient_clinical_documents', 1);
        $this->assertDatabaseCount('inpatient_clinical_document_versions', 2);
        $this->assertDatabaseCount('inpatient_document_operation_receipts', 2);
    }

    public function test_protected_documentation_ddl_requires_explicit_schema_scope(): void
    {
        $guard = app(InpatientDocumentationSqlWriteGuard::class);
        $denied = 0;

        foreach ([
            'CREATE TABLE inpatient_clinical_documents (id integer)',
            'ALTER TABLE laravel.inpatient_clinical_document_versions ADD COLUMN bypass integer',
            'DROP TABLE `inpatient_document_operation_receipts`',
        ] as $sql) {
            try {
                $guard->assertAllowed($sql);
                $this->fail('Protected documentation DDL unexpectedly bypassed the guard.');
            } catch (LogicException) {
                $denied++;
            }
        }
        $this->assertSame(3, $denied);
    }

    public function test_physician_and_multiple_authors_have_independent_heads_for_same_service_day(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $otherNurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $service = app(InpatientDocumentationService::class);

        $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, $this->nursingFields(), 'HEAD-NURSE-0001');
        $service->saveDraft($encounter->public_id, $otherNurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, ['nursing_observation' => 'Observasi kedua'], 'HEAD-NURSE-0002');
        $service->saveDraft($encounter->public_id, $physician, InpatientClinicalDocument::TYPE_MEDICAL_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, $this->medicalFields(), 'HEAD-MEDICAL-001');

        $this->assertDatabaseCount('inpatient_clinical_documents', 3);
        $this->assertSame(2, InpatientClinicalDocument::query()->where('document_type', InpatientClinicalDocument::TYPE_NURSING_DAILY)->count());
        $this->assertSame(1, InpatientClinicalDocument::query()->where('document_type', InpatientClinicalDocument::TYPE_MEDICAL_DAILY)->count());
        $this->assertSame(1, InpatientClinicalDocument::query()->where('author_user_id', $physician->id)->firstOrFail()->version);
    }

    public function test_role_and_capability_are_denied_before_unknown_encounter_lookup(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->expectException(AuthorizationException::class);
        try {
            app(InpatientDocumentationService::class)->saveDraft(
                '01ARZ3NDEKTSV4RRFFQ69G5FAV', $physician, InpatientClinicalDocument::TYPE_NURSING_DAILY,
                InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'DENY-BEFORE-0001',
            );
        } finally {
            $this->assertDatabaseCount('inpatient_clinical_documents', 0);
            $this->assertDatabaseCount('inpatient_document_operation_receipts', 0);
        }
    }

    public function test_validation_stale_conflict_and_incomplete_finalization_fail_without_partial_mutation(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $service = app(InpatientDocumentationService::class);
        $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'VALIDATION-0001');

        foreach ([
            fn () => $service->finalize($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 1, 'VALIDATION-0002'),
            fn () => $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'VALIDATION-0003'),
            fn () => $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 1, ['unknown' => 'x'], 'VALIDATION-0004'),
            fn () => $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 1, ['additional_notes' => 'changed'], 'VALIDATION-0001'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected governed inpatient documentation denial.');
            } catch (InpatientDocumentationDenied) {
                $this->assertSame(1, InpatientClinicalDocument::query()->firstOrFail()->version);
                $this->assertDatabaseCount('inpatient_clinical_document_versions', 1);
            }
        }
    }

    public function test_snapshot_history_survives_master_rename_and_later_version_uses_new_display_values(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $service = app(InpatientDocumentationService::class);
        $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'SNAPSHOT-0001');

        $master = app(InpatientMasterService::class);
        $ward = $this->bed->ward;
        $master->updateWard($this->admin, $ward->public_id, 'Melati Baru', 1, InpatientMasterService::REASON_OPERATIONAL_CHANGE, 'SNAP-WARD-0001', null);
        $master->updateBed($this->admin, $this->bed->public_id, 'Bed Baru', 'Ruang Baru', 'Kelas Baru', 1, InpatientMasterService::REASON_OPERATIONAL_CHANGE, 'SNAP-BED-00001', null);
        $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 1, [], 'SNAPSHOT-0002');

        $versions = InpatientClinicalDocumentVersion::query()->orderBy('version')->get();
        $this->assertSame('Melati', $versions[0]->ward_display_name);
        $this->assertSame('Tempat Tidur A-01', $versions[0]->bed_display_name);
        $this->assertSame('Melati Baru', $versions[1]->ward_display_name);
        $this->assertSame('Bed Baru', $versions[1]->bed_display_name);
        $this->assertSame($versions[0]->ward_code, $versions[1]->ward_code);
        $this->assertSame($versions[0]->bed_code, $versions[1]->bed_code);
    }

    public function test_missing_managed_placement_is_readable_but_actions_are_null_and_writes_fail_closed(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $patient = Patient::factory()->create(['created_by_user_id' => $this->registrar->id, 'is_synthetic' => true]);
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'patient_id' => $patient->id, 'registered_by_user_id' => $this->registrar->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT, 'status' => Encounter::STATUS_REGISTERED,
            'inpatient_bed_id' => null, 'ward_name' => 'Bangsal lama', 'bed_code' => 'LAMA-01',
        ]));
        app(LockedClinicalEntryWriter::class)->write($encounter, $nurse, Encounter::CARE_SETTING_INPATIENT, ClinicalEntry::TYPE_NURSING_INTAKE, 'Catatan lama');

        $this->actingAs($nurse)->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('pemeriksaan/rawat-inap/show')
            ->where('documentation.available', false)
            ->where('encounter.placement', null)
            ->where('permissions.nursing.can_save_draft', false)
            ->where('actions.nursing.save_draft_url', null)
            ->has('legacyEntries', 1));

        try {
            app(InpatientDocumentationService::class)->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'NO-PLACEMENT-0001');
            $this->fail('Missing placement unexpectedly accepted.');
        } catch (InpatientDocumentationDenied $denial) {
            $this->assertSame('placement_missing', $denial->reason);
        }
    }

    public function test_audit_failure_rolls_back_document_status_version_and_receipt(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $audit = Mockery::mock(AuditRecorder::class);
        $expectation = $audit->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $audit);
        $service = new InpatientDocumentationService(
            app(AuditRecorder::class),
            app(InpatientDocumentationActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
        );

        $this->expectException(InpatientDocumentationAuditUnavailable::class);
        try {
            $service->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'AUDIT-FAIL-0001');
        } finally {
            $this->assertDatabaseCount('inpatient_clinical_documents', 0);
            $this->assertDatabaseCount('inpatient_clinical_document_versions', 0);
            $this->assertDatabaseCount('inpatient_document_operation_receipts', 0);
            $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        }
    }

    public function test_immutable_document_chain_rejects_eloquent_and_direct_sql_bypasses(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        app(InpatientDocumentationService::class)->saveDraft(
            $encounter->public_id,
            $nurse,
            InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION,
            0,
            [],
            'IMMUTABLE-DOC-0001',
        );
        $document = InpatientClinicalDocument::query()->firstOrFail();

        foreach ([
            fn () => $document->update(['version' => 2]),
            fn () => InpatientClinicalDocumentVersion::query()->firstOrFail()->update(['version' => 2]),
            fn () => DB::table('inpatient_clinical_document_versions')->where('id', 1)->update(['version' => 2]),
            fn () => DB::table('inpatient_document_operation_receipts')->delete(),
        ] as $bypass) {
            try {
                $bypass();
                $this->fail('Inpatient documentation bypass unexpectedly succeeded.');
            } catch (LogicException) {
                $this->assertSame(1, InpatientClinicalDocument::query()->firstOrFail()->version);
            }
        }
    }

    public function test_reset_removes_document_chain_and_down_refuses_when_correlated_audit_remains(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        app(InpatientDocumentationService::class)->saveDraft($encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'RESET-DOC-00001');
        app(SyntheticResetService::class)->reset(['actor' => $this->admin, 'reason' => 'reset_inpatient_document_test']);

        $this->assertDatabaseCount('inpatient_clinical_documents', 0);
        $this->assertDatabaseCount('inpatient_clinical_document_versions', 0);
        $this->assertDatabaseCount('inpatient_document_operation_receipts', 0);
        $migration = require database_path('migrations/2026_08_30_000400_create_inpatient_longitudinal_documentation_tables.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('correlated audit evidence remains');
        $migration->down();
    }

    public function test_legacy_free_text_mutation_route_is_absent(): void
    {
        $this->assertFalse(Route::has('pemeriksaan.rawat-inap.entries.store'));
    }

    /** @return array{Encounter, User} */
    private function encounterAndActor(string $role): array
    {
        $actor = $this->userWithRole($role);
        $patient = Patient::factory()->create(['created_by_user_id' => $this->registrar->id, 'is_synthetic' => true]);
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'patient_id' => $patient->id, 'registered_by_user_id' => $this->registrar->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT, 'status' => Encounter::STATUS_REGISTERED,
            'inpatient_bed_id' => $this->bed->id, 'ward_name' => 'Melati', 'ward_class' => 'Kelas 1', 'bed_code' => 'A-01',
        ]));

        return [$encounter, $actor];
    }

    /** @return array{InpatientWard, InpatientBed} */
    private function createWardAndBed(): array
    {
        $master = app(InpatientMasterService::class);
        $ward = $master->createWard($this->admin, 'RI-MELATI', 'Melati', InpatientMasterService::REASON_INITIAL_SETUP, 'DOC-WARD-00001', null)->master;
        if (! $ward instanceof InpatientWard) {
            throw new LogicException('Expected inpatient ward result.');
        }
        $bed = $master->createBed($this->admin, $ward->public_id, 'A-01', 'Tempat Tidur A-01', 'Ruang Melati', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'DOC-BED-000001', null)->master;
        if (! $bed instanceof InpatientBed) {
            throw new LogicException('Expected inpatient bed result.');
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
    private function nursingFields(): array
    {
        return ['nursing_observation' => 'Stabil', 'nursing_intervention' => 'Observasi', 'nursing_evaluation' => 'Terpantau'];
    }

    /** @return array<string, string> */
    private function medicalFields(): array
    {
        return ['subjective' => 'Keluhan membaik', 'objective' => 'Stabil', 'assessment' => 'Observasi', 'plan' => 'Lanjut pantau'];
    }
}
