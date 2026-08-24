<?php

namespace Tests\Feature\Outpatient;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\OutpatientLabLifecycle;
use App\Support\Clinical\OutpatientRmCompletenessService;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class StructuredOutpatientDocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_draft_is_versioned_and_finalization_uses_persisted_fields(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $draftUrl = route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]);
        $finalUrl = route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]);

        $this->actingAs($nurse)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => ['nursing_assessment' => 'Keluhan sintetis.'],
        ])->assertRedirect();
        $this->actingAs($nurse)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 1,
            'fields' => ['nursing_assessment' => 'Keluhan sintetis diperbarui.', 'additional_notes' => 'Simulasi.'],
        ])->assertRedirect();
        $this->actingAs($nurse)->post($finalUrl, ['expected_version' => 2])->assertRedirect();

        $document = OutpatientClinicalDocument::query()->firstOrFail();
        $this->assertSame(OutpatientClinicalDocument::STATE_FINAL, $document->document_state);
        $this->assertSame(3, $document->version);
        $this->assertSame('Keluhan sintetis diperbarui.', $document->fields['nursing_assessment']);
        $this->assertCount(3, $document->versions);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->actingAs($nurse)->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documentation.versions', 3)
                ->where('documentation.versions.0.document_type', OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT)
                ->where('documentation.versions.0.version', 1)
                ->where('documentation.versions.0.actor_name', $nurse->name));
    }

    public function test_validation_concurrency_author_and_final_guards_are_enforced_and_audited(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $otherNurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $draftUrl = route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]);
        $finalUrl = route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]);

        $this->actingAs($nurse)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => ['unknown' => 'x'],
        ])->assertStatus(422);
        $this->actingAs($nurse)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => ['nursing_assessment' => 'Asesmen sintetis.'],
        ])->assertRedirect();
        $this->actingAs($nurse)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => ['nursing_assessment' => 'Stale.'],
        ])->assertStatus(422);
        $this->actingAs($otherNurse)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 1,
            'fields' => ['nursing_assessment' => 'Bukan penulis.'],
        ])->assertForbidden();
        $this->actingAs($nurse)->post($finalUrl, ['expected_version' => 1])->assertRedirect();
        $this->actingAs($nurse)->post($finalUrl, ['expected_version' => 2])->assertStatus(422);

        foreach (['validation_failed', 'stale_version', 'author_mismatch', 'document_final'] as $reason) {
            $this->assertDatabaseHas('audit_events', ['outcome' => 'DENIED', 'reason' => $reason]);
        }
    }

    public function test_medical_final_moves_to_rm_and_complete_snapshot_signoff_closes_atomically(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $this->saveAndFinalize($encounter, $nurse, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, [
            'nursing_assessment' => 'Asesmen keperawatan sintetis.',
        ]);
        $this->saveAndFinalize($encounter, $physician, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, [
            'anamnesis' => 'Anamnesis sintetis.',
            'objective_examination' => 'Pemeriksaan objektif sintetis.',
            'clinical_assessment' => 'Asesmen klinis sintetis.',
            'care_plan' => 'Rencana pelayanan sintetis.',
        ]);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-jalan.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('encounters.0.public_id', $encounter->public_id));

        $snapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->assertSame([], $snapshot['blockers']);
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [
            'expected_version' => 0,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect();
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.signoff', $encounter), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect(route('rm.rawat-jalan.show', $encounter));

        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
        $this->assertDatabaseHas('outpatient_rm_completeness_reviews', ['encounter_id' => $encounter->id, 'version' => 2, 'review_state' => 'SIGNED_OFF']);
        $this->assertDatabaseHas('audit_events', ['action' => 'rmik.completeness.signoff', 'outcome' => 'SUCCESS']);
        $signedReview = $encounter->outpatientRmCompletenessReviews()->where('version', 2)->firstOrFail();
        $storedFingerprint = $signedReview->source_fingerprint;
        $signedReview->update(['definition_version' => 'OUTPATIENT_RM_COMPLETENESS_ARCHIVED_V0']);
        $medical = $encounter->outpatientClinicalDocuments()
            ->where('document_type', OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT)
            ->firstOrFail();
        $medical->update([
            'version' => 99,
            'fields' => ['anamnesis' => 'Sumber diubah setelah arsip'],
        ]);
        LabServiceRequest::factory()->create([
            'encounter_id' => $encounter->id,
            'requested_by_user_id' => $physician->id,
            'status' => LabServiceRequest::STATUS_ACTIVE,
        ]);
        $this->actingAs($rmik)->get(route('rm.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('encounter.status', Encounter::STATUS_CLOSED)
                ->where('review.status', 'SIGNED_OFF')
                ->where('review.definition_version', 'OUTPATIENT_RM_COMPLETENESS_ARCHIVED_V0')
                ->where('review.source_fingerprint', $storedFingerprint)
                ->where('review.checklist_items', fn ($items): bool => collect($items)->contains(
                    fn ($item): bool => $item['code'] === 'NO_ACTIVE_LAB_ORDERS' && $item['status'] === 'PASS',
                ))
                ->has('blockers', 0)
                ->where('permissions.can_save_review', false)
                ->where('permissions.can_signoff', false)
                ->has('documentVersions', 4));
        $this->actingAs($rmik)->get(route('rm.rawat-jalan.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.status', Encounter::STATUS_CLOSED)
                ->where('encounters.0.completeness_status', 'COMPLETE'));
    }

    public function test_stale_source_and_active_lab_block_signoff_without_mutation(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $this->saveAndFinalize($encounter, $physician, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, [
            'anamnesis' => 'A', 'objective_examination' => 'B', 'clinical_assessment' => 'C', 'care_plan' => 'D',
        ]);
        $snapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [
            'expected_version' => 0, 'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect();
        $this->saveAndFinalize($encounter, $nurse, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, ['nursing_assessment' => 'N']);
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.signoff', $encounter), [
            'expected_version' => 1, 'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['action' => 'rmik.completeness.signoff', 'reason' => 'source_stale']);

        $current = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [
            'expected_version' => 1, 'source_fingerprint' => $current['source_fingerprint'],
        ])->assertRedirect();
        LabServiceRequest::factory()->create(['encounter_id' => $encounter->id, 'requested_by_user_id' => $physician->id, 'status' => LabServiceRequest::STATUS_ACTIVE]);
        $withLab = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [
            'expected_version' => 2, 'source_fingerprint' => $withLab['source_fingerprint'],
        ])->assertRedirect();
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.signoff', $encounter), [
            'expected_version' => 3, 'source_fingerprint' => $withLab['source_fingerprint'],
        ])->assertStatus(422);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'rmik.completeness.signoff', 'reason' => 'active_lab_orders']);
    }

    public function test_legacy_entries_are_read_only_projection_and_old_route_is_retired(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        ClinicalEntry::factory()->create(['encounter_id' => $encounter->id, 'author_user_id' => $nurse->id]);
        $this->assertFalse(Route::has('pemeriksaan.rawat-jalan.entries.store'));
        $this->actingAs($nurse)->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertInertia(fn (Assert $page) => $page
                ->where('variant', 'rawat-jalan')
                ->has('legacyEntries', 1)
                ->has('documentation.documents', 0)
                ->has('documentation.active_drafts', 0));
    }

    public function test_successful_document_write_rolls_back_when_audit_write_fails(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $recorder);
        $this->actingAs($nurse)->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]), [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => ['nursing_assessment' => 'Rollback sintetis.'],
        ])->assertServerError();
        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
    }

    public function test_rm_signoff_rolls_back_when_audit_write_fails(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $this->saveAndFinalize($encounter, $nurse, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, [
            'nursing_assessment' => 'Asesmen keperawatan sintetis.',
        ]);
        $this->saveAndFinalize($encounter, $physician, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, [
            'anamnesis' => 'Anamnesis sintetis.',
            'objective_examination' => 'Pemeriksaan objektif sintetis.',
            'clinical_assessment' => 'Asesmen klinis sintetis.',
            'care_plan' => 'Rencana pelayanan sintetis.',
        ]);
        $snapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [
            'expected_version' => 0,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect();

        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($rmik)->post(route('rm.rawat-jalan.signoff', $encounter), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertServerError();

        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertDatabaseMissing('outpatient_rm_completeness_reviews', [
            'encounter_id' => $encounter->id,
            'review_state' => 'SIGNED_OFF',
        ]);
    }

    public function test_required_final_fields_rbac_closed_state_and_synthetic_binding_fail_closed(): void
    {
        [$encounter, $physician] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $draftUrl = route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]);
        $finalUrl = route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]);

        $this->actingAs($registrar)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => ['anamnesis' => 'Tidak berhak'],
        ])->assertForbidden();
        $this->actingAs($physician)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => ['anamnesis' => 'Belum lengkap'],
        ])->assertRedirect();
        $this->actingAs($physician)->post($finalUrl, ['expected_version' => 1])->assertStatus(422);
        $this->assertSame(OutpatientClinicalDocument::STATE_DRAFT, OutpatientClinicalDocument::query()->firstOrFail()->document_state);

        $encounter->update(['status' => Encounter::STATUS_CLOSED]);
        $this->actingAs($physician)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 1,
            'fields' => ['anamnesis' => 'Ditutup'],
        ])->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['reason' => 'encounter_closed', 'outcome' => 'DENIED']);

        $nonSyntheticPatient = Patient::factory()->create(['created_by_user_id' => $registrar->id, 'is_synthetic' => false]);
        $nonSyntheticEncounter = Encounter::factory()->create([
            'patient_id' => $nonSyntheticPatient->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
        ]);
        $this->actingAs($physician)->get('/pemeriksaan/rawat-jalan/'.$nonSyntheticEncounter->public_id)->assertNotFound();
    }

    public function test_review_save_requires_concurrency_contract_and_legacy_close_bypass_is_absent(): void
    {
        [$encounter] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter->update(['status' => Encounter::STATUS_READY_FOR_RM]);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);

        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [])
            ->assertSessionHasErrors(['expected_version', 'source_fingerprint']);
        $this->assertDatabaseCount('outpatient_rm_completeness_reviews', 0);
        $this->assertFalse(method_exists(OutpatientLabLifecycle::class, 'closeEncounter'));
        $this->assertFalse(method_exists(OutpatientLabLifecycle::class, 'writeClinicalEntry'));
    }

    public function test_new_migration_qualifies_all_postgres_sequences(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_24_000100_create_outpatient_documentation_tables.php'));
        $this->assertIsString($migration);
        $this->assertStringContainsString('SchemaQualifier::primarySchema()', $migration);
        $this->assertStringNotContainsString("'laravel.'.\$table", $migration);
        foreach ([
            'outpatient_clinical_documents',
            'outpatient_clinical_document_versions',
            'outpatient_rm_completeness_reviews',
            'outpatient_rm_completeness_items',
        ] as $table) {
            $this->assertStringContainsString("'{$table}'", $migration);
        }

        $migrationObject = require database_path('migrations/2026_08_24_000100_create_outpatient_documentation_tables.php');
        $reflection = new \ReflectionClass($migrationObject);
        $quoteIdentifier = $reflection->getMethod('quoteIdentifier');
        $quoteLiteral = $reflection->getMethod('quoteLiteral');
        $this->assertSame('"Preview""Schema"', $quoteIdentifier->invoke($migrationObject, 'Preview"Schema'));
        $this->assertSame("'\"Preview\".\"table''seq\"'", $quoteLiteral->invoke($migrationObject, '"Preview"."table\'seq"'));
    }

    public function test_rm_index_eager_loads_completeness_sources_without_per_encounter_queries(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        foreach (range(1, 5) as $index) {
            $patient = Patient::factory()->create([
                'created_by_user_id' => $registrar->id,
                'is_synthetic' => true,
                'medical_record_number' => 'SYNTH-N1-'.$index,
            ]);
            Encounter::factory()->create([
                'patient_id' => $patient->id,
                'registered_by_user_id' => $registrar->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => Encounter::STATUS_READY_FOR_RM,
            ]);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $this->actingAs($rmik)->get(route('rm.rawat-jalan.index'))->assertOk();

        $this->assertLessThanOrEqual(12, $queryCount, 'RM index issued per-encounter completeness queries.');
    }

    /** @return array{Encounter, User} */
    private function encounterAndActor(string $role): array
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $actor = $this->userWithRole($role);
        $patient = Patient::factory()->create(['created_by_user_id' => $registrar->id, 'is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        return [$encounter, $actor];
    }

    /** @param array<string, string> $fields */
    private function saveAndFinalize(Encounter $encounter, User $actor, string $type, array $fields): void
    {
        $this->actingAs($actor)->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, $type]), [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => $fields,
        ])->assertRedirect();
        $this->actingAs($actor)->post(route('pemeriksaan.rawat-jalan.documents.final', [$encounter, $type]), [
            'expected_version' => 1,
        ])->assertRedirect();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
