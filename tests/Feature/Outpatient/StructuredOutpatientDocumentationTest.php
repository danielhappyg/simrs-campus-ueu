<?php

namespace Tests\Feature\Outpatient;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyPrescription;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\OutpatientLabLifecycle;
use App\Support\Clinical\OutpatientRmCompletenessService;
use App\Support\Pharmacy\PharmacyMutationScope;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
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

    public function test_unknown_document_field_keys_are_audited_only_as_bounded_digests(): void
    {
        [$encounter, $nurse] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $longKey = str_repeat('x', 300);
        $unknownFields = [
            0 => 'numeric key',
            'password' => 'secret-like key',
            $longKey => 'oversized key',
        ];
        for ($index = 0; $index < 48; $index++) {
            $unknownFields['unknown_'.$index] = 'bounded digest input';
        }

        $this->actingAs($nurse)->post(
            route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]),
            [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $unknownFields,
            ],
        )->assertStatus(422);

        $event = AuditEvent::query()->where('reason', 'validation_failed')->sole();
        $this->assertSame(51, $event->metadata['invalid_field_key_count']);
        $this->assertCount(50, $event->metadata['invalid_field_key_digests']);
        $this->assertSame([
            hash('sha256', 'integer:0'),
            hash('sha256', 'string:password'),
            hash('sha256', 'string:'.$longKey),
        ], array_slice($event->metadata['invalid_field_key_digests'], 0, 3));
        $encodedMetadata = json_encode($event->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', $encodedMetadata);
        $this->assertStringNotContainsString($longKey, $encodedMetadata);
        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
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
                ->where('review.definition_version', OutpatientRmCompletenessReview::DEFINITION_VERSION)
                ->where('review.source_fingerprint', $storedFingerprint)
                ->where('review.checklist_items', static function (mixed $items): bool {
                    if (! is_iterable($items)) {
                        return false;
                    }

                    foreach ($items as $item) {
                        if (is_array($item)
                            && ($item['code'] ?? null) === 'NO_ACTIVE_LAB_ORDERS'
                            && ($item['status'] ?? null) === 'PASS') {
                            return true;
                        }
                    }

                    return false;
                })
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

    public function test_active_pharmacy_prescription_blocks_medical_final_and_rm_handoff(): void
    {
        [$encounter, $physician] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $draftUrl = route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]);
        $finalUrl = route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]);
        $this->actingAs($physician)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => [
                'anamnesis' => 'Anamnesis resep aktif.',
                'objective_examination' => 'Pemeriksaan objektif resep aktif.',
                'clinical_assessment' => 'Asesmen resep aktif.',
                'care_plan' => 'Selesaikan resep sebelum episode klinis ditutup.',
            ],
        ])->assertRedirect();
        PharmacyMutationScope::run(function () use ($encounter, $physician): void {
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEPO_RJ_CLOSURE',
                'display_name' => 'Depo Rawat Jalan Penutupan',
                'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
                'state' => PharmacyDepot::ACTIVE,
                'version' => 1,
                'current_content_digest' => str_repeat('a', 64),
            ]);
            PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordering_physician_user_id' => $physician->id,
                'depot_id' => $depot->id,
                'care_setting' => $encounter->care_setting,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => $encounter->clinic_name,
                'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code,
                'status' => PharmacyPrescription::ORDERED,
                'version' => 1,
                'current_content_digest' => str_repeat('b', 64),
                'ordered_at' => now(),
            ]);
        });

        $this->actingAs($physician)->post($finalUrl, ['expected_version' => 1])->assertStatus(422);

        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()?->status);
        $this->assertSame(OutpatientClinicalDocument::STATE_DRAFT, OutpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->sole()->document_state);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.medical.finalize',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'active_pharmacy_prescriptions',
        ]);
    }

    public function test_final_documents_versions_and_rm_snapshot_evidence_reject_direct_model_mutation_and_delete(): void
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
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.signoff', $encounter), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect();

        $document = OutpatientClinicalDocument::query()
            ->where('encounter_id', $encounter->id)
            ->where('document_type', OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT)
            ->firstOrFail();
        $version = OutpatientClinicalDocumentVersion::query()
            ->where('outpatient_clinical_document_id', $document->id)
            ->firstOrFail();
        $review = OutpatientRmCompletenessReview::query()
            ->where('encounter_id', $encounter->id)
            ->where('review_state', OutpatientRmCompletenessReview::STATE_SIGNED_OFF)
            ->firstOrFail();
        $item = $review->items()->firstOrFail();

        $this->assertLogicException(fn () => $document->update(['version' => 99]), 'document update');
        $this->assertLogicException(fn () => $document->fresh()->delete(), 'document delete');
        $this->assertLogicException(fn () => $version->update(['version' => 99]), 'version update');
        $this->assertLogicException(fn () => $version->fresh()->delete(), 'version delete');
        $this->assertLogicException(fn () => $review->update(['definition_version' => 'MUTATED']), 'review update');
        $this->assertLogicException(fn () => $review->fresh()->delete(), 'review delete');
        $this->assertLogicException(fn () => $item->update(['is_complete' => false]), 'item update');
        $this->assertLogicException(fn () => $item->fresh()->delete(), 'item delete');

        $this->assertSame(OutpatientClinicalDocument::STATE_FINAL, $document->fresh()->document_state);
        $this->assertSame(2, $document->fresh()->version);
        $this->assertSame(OutpatientRmCompletenessReview::STATE_SIGNED_OFF, $review->fresh()->review_state);
        $this->assertTrue($item->fresh()->is_complete);
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
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
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
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
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

    public function test_cancelled_encounter_disables_document_permissions_and_rejects_direct_document_and_rm_writes(): void
    {
        [$encounter, $physician] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter->update(['status' => Encounter::STATUS_CANCELLED]);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.medical.can_save_draft', false)
                ->where('permissions.medical.can_finalize', false)
                ->where('permissions.can_create_lab_order', false));

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [
                $encounter,
                OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
            ]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => ['anamnesis' => 'Tidak boleh tersimpan.'],
            ])
            ->assertStatus(422);
        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
        $this->assertDatabaseHas('audit_events', [
            'reason' => 'encounter_cancelled',
            'outcome' => 'DENIED',
        ]);

        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $snapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.reviews.store', $encounter), [
                'expected_version' => 0,
                'source_fingerprint' => $snapshot['source_fingerprint'],
            ])
            ->assertStatus(422);
        $this->assertDatabaseCount('outpatient_rm_completeness_reviews', 0);
        $this->assertSame(Encounter::STATUS_CANCELLED, $encounter->fresh()->status);
    }

    public function test_review_save_requires_concurrency_contract_and_legacy_close_bypass_is_absent(): void
    {
        [$encounter] = $this->encounterAndActor(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter->update(['status' => Encounter::STATUS_READY_FOR_RM]);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);

        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [])
            ->assertSessionHasErrors(['expected_version', 'source_fingerprint']);
        $this->assertDatabaseCount('outpatient_rm_completeness_reviews', 0);
        $methods = get_class_methods(OutpatientLabLifecycle::class);
        $this->assertNotContains('closeEncounter', $methods);
        $this->assertNotContains('writeClinicalEntry', $methods);
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
        foreach ([
            'ocd_encounter_fk',
            'ocd_author_user_fk',
            'ocd_finalized_by_user_fk',
            'ocd_public_id_uq',
            'ocd_encounter_type_uq',
            'ocd_encounter_state_idx',
            'ocdv_document_fk',
            'ocdv_actor_user_fk',
            'ocdv_public_id_uq',
            'ocdv_document_version_uq',
            'ormcr_encounter_fk',
            'ormcr_reviewed_by_user_fk',
            'ormcr_signed_off_by_user_fk',
            'ormcr_public_id_uq',
            'ormcr_encounter_version_uq',
            'ormcr_encounter_state_idx',
            'ormci_review_fk',
            'ormci_review_item_uq',
        ] as $identifier) {
            $this->assertLessThanOrEqual(63, strlen($identifier));
            $this->assertStringContainsString("'{$identifier}'", $migration);
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

        $this->assertLessThanOrEqual(13, $queryCount, 'RM index issued per-encounter completeness queries.');
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

    private function assertLogicException(callable $operation, string $label): void
    {
        try {
            $operation();
            $this->fail("Expected LogicException for {$label}.");
        } catch (LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage(), $label);
        }
    }
}
