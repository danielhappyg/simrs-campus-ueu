<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientRmCoding;
use App\Models\InpatientRmCodingAssignment;
use App\Models\InpatientRmCompletenessReview;
use App\Models\InpatientSummaryCorrectionRequest;
use App\Models\InpatientWard;
use App\Models\LabServiceRequest;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyPrescription;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientDischargeCodingSourceResult;
use App\Support\Inpatient\InpatientDischargeCodingSourceService;
use App\Support\Inpatient\InpatientDischargeService;
use App\Support\Inpatient\InpatientDischargeSummaryService;
use App\Support\Inpatient\InpatientDocumentationService;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Inpatient\InpatientRmDenied;
use App\Support\Inpatient\InpatientRmService;
use App\Support\Inpatient\InpatientSummaryAddendumDenied;
use App\Support\Inpatient\InpatientSummaryAddendumService;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class InpatientRmClosureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $registrar;

    private User $physician;

    private User $rmik;

    private InpatientWard $ward;

    private InpatientBed $bed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->user(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->user(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->physician = $this->user(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->rmik = $this->user(RoleCapabilityMatrix::ROLE_RMIK);
        [$this->ward, $this->bed] = $this->masters();
    }

    public function test_closed_episode_summary_addendum_request_approval_draft_and_final_preserve_baseline(): void
    {
        [$encounter, $source] = $this->readyEpisode();
        $rm = app(InpatientRmService::class);
        $discharge = $encounter->inpatientDischarge;
        $draft = $rm->saveCodingDraft($encounter->public_id, $this->rmik, 0, $source->resultVersion->public_id, $discharge->discharge_coding_source_content_digest, $discharge->discharge_coding_source_provenance_digest, $this->assignments(), 'ISA-CODING-0001');
        $snapshot = $rm->snapshot($encounter->fresh());
        $rm->saveReview($encounter->public_id, $this->rmik, 0, $snapshot['source_fingerprint'], $draft->codingVersion->version, $draft->codingVersion->content_digest, 'ISA-REVIEW-0001');
        $rm->signoff($encounter->public_id, $this->rmik, 1, $snapshot['source_fingerprint'], $draft->codingVersion->version, $draft->codingVersion->content_digest, 'ISA-SIGNOFF-0001');
        $baseline = $encounter->fresh()->only(['status', 'inpatient_bed_id', 'ward_name', 'bed_code']);
        $baselineSummary = $encounter->fresh()->inpatientDischargeSummary->getAttributes();

        $service = app(InpatientSummaryAddendumService::class);
        $submitted = $service->submit($encounter->fresh(), $this->physician, 'MISSING_INFORMATION', 'Tambahkan instruksi keluarga.', 'ISA-REQUEST-0001');
        $decider = $this->user(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approved = $service->decide($submitted->request, $decider, 'APPROVED', 'Disetujui sebagai addendum.', 1, 'ISA-DECIDE-0001');
        $saved = $service->saveDraft($approved->request, $this->physician, [
            'admission_reason' => null,
            'significant_findings' => null,
            'care_and_treatment_summary' => null,
            'condition_at_discharge' => null,
            'follow_up_plan' => 'Keluarga diminta memantau asupan cairan dan kembali bila memburuk.',
        ], 0, 'ISA-DRAFT-0001');
        $final = $service->finalize($approved->request, $this->physician, 1, 'ISA-FINAL-0001');
        $amendmentSnapshot = $service->snapshot($approved->request->fresh());
        $review = $service->saveReview($approved->request->fresh(), $this->rmik, 0, $amendmentSnapshot['source_fingerprint'], 'ISA-RM-REVIEW-0001');
        $signed = $service->signoff($approved->request->fresh(), $this->rmik, 1, $amendmentSnapshot['source_fingerprint'], 'ISA-RM-SIGNOFF-0001');

        $this->assertSame('FINAL', $final->addendum->addendum_state);
        $this->assertSame($saved->addendum->current_content_digest, $final->addendum->current_content_digest);
        $this->assertSame('DRAFT', $review->review->review_state);
        $this->assertCount(7, $review->review->items);
        $this->assertSame('SIGNED_OFF', $signed->review->review_state);
        $this->assertSame('CONSUMED', $approved->request->fresh()->request_state);
        $this->assertSame($baseline, $encounter->fresh()->only(['status', 'inpatient_bed_id', 'ward_name', 'bed_code']));
        $this->assertSame($baselineSummary, $encounter->fresh()->inpatientDischargeSummary->getAttributes());
        $this->assertDatabaseCount('inpatient_summary_correction_request_versions', 3);
        $this->assertDatabaseCount('inpatient_summary_addendum_versions', 2);
        $this->assertDatabaseCount('inpatient_summary_addendum_operation_receipts', 6);
    }

    public function test_manual_coding_review_and_signoff_append_exact_final_evidence_and_close_only_episode_status(): void
    {
        [$encounter, $source] = $this->readyEpisode();
        $service = app(InpatientRmService::class);
        $discharge = $encounter->fresh()->inpatientDischarge;
        $before = $encounter->fresh()->only(['inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class']);

        $draft = $service->saveCodingDraft(
            $encounter->public_id, $this->rmik, 0,
            $source->resultVersion->public_id,
            $discharge->discharge_coding_source_content_digest,
            $discharge->discharge_coding_source_provenance_digest,
            $this->assignments(), 'IRM-CODING-0001',
        );
        $this->assertSame(InpatientRmCoding::STATE_DRAFT, $draft->codingVersion->coding_state);
        $this->assertSame(InpatientRmCoding::PROFILE, $draft->codingVersion->profile);
        $this->assertCount(3, $draft->codingVersion->assignments);
        $this->assertSame([InpatientRmCoding::DIAGNOSIS_CODE_SYSTEM, InpatientRmCoding::DIAGNOSIS_CODE_SYSTEM, InpatientRmCoding::PROCEDURE_CODE_SYSTEM], $draft->codingVersion->assignments->pluck('code_system')->all());
        $this->assertTrue($draft->codingVersion->assignments->last()->is_no_procedure_attestation);
        $this->assertNull($draft->codingVersion->assignments->last()->normalized_code);

        $snapshot = $service->snapshot($encounter->fresh());
        $this->assertSame([], $snapshot['blockers']);
        $review = $service->saveReview(
            $encounter->public_id, $this->rmik, 0,
            $snapshot['source_fingerprint'], $snapshot['coding_version'], $snapshot['coding_digest'],
            'IRM-REVIEW-0001',
        );
        $this->assertSame(InpatientRmCompletenessReview::STATE_DRAFT, $review->review->review_state);
        $this->assertSame(12, $review->review->items->count());

        $signed = $service->signoff(
            $encounter->public_id, $this->rmik, 1,
            $snapshot['source_fingerprint'], $snapshot['coding_version'], $snapshot['coding_digest'],
            'IRM-SIGNOFF-0001',
        );
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
        $this->assertSame($before, $encounter->fresh()->only(['inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class']));
        $this->assertSame($discharge->public_id, $encounter->fresh()->inpatientDischarge->public_id);
        $this->assertSame(InpatientRmCoding::STATE_FINAL, $signed->coding->coding_state);
        $this->assertSame(InpatientRmCoding::STATE_FINAL, $signed->codingVersion->coding_state);
        $this->assertSame(2, $signed->codingVersion->version);
        $this->assertSame($draft->codingVersion->content_digest, $signed->codingVersion->content_digest);
        $this->assertSame(6, DB::table('inpatient_rm_coding_assignments')->count());
        $this->assertSame($signed->codingVersion->id, $signed->review->inpatient_rm_coding_version_id);
        $this->assertSame(InpatientRmCompletenessReview::STATE_SIGNED_OFF, $signed->review->review_state);
        $this->assertSame($service->snapshot($encounter->fresh())['source_fingerprint'], $signed->review->source_fingerprint);

        $replay = $service->signoff(
            $encounter->public_id, $this->rmik, 1,
            $snapshot['source_fingerprint'], $snapshot['coding_version'], $snapshot['coding_digest'],
            'irm-signoff-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame($signed->review->public_id, $replay->review->public_id);
        $this->assertDatabaseCount('inpatient_rm_completeness_reviews', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'rmik.inpatient.episode.signoff', 'outcome' => 'SUCCESS']);
    }

    public function test_coding_change_after_saved_review_makes_review_stale_until_a_new_snapshot_is_saved(): void
    {
        [$encounter, $source] = $this->readyEpisode();
        $service = app(InpatientRmService::class);
        $discharge = $encounter->fresh()->inpatientDischarge;
        $first = $service->saveCodingDraft(
            $encounter->public_id, $this->rmik, 0, $source->resultVersion->public_id,
            $discharge->discharge_coding_source_content_digest, $discharge->discharge_coding_source_provenance_digest,
            $this->assignments(), 'IRM-CODING-1001',
        );
        $old = $service->snapshot($encounter->fresh());
        $service->saveReview($encounter->public_id, $this->rmik, 0, $old['source_fingerprint'], 1, $old['coding_digest'], 'IRM-REVIEW-1001');
        $corrected = $this->assignments();
        $corrected[0]['code'] = 'A09.9';
        $second = $service->saveCodingDraft(
            $encounter->public_id, $this->rmik, $first->codingVersion->version, $source->resultVersion->public_id,
            $discharge->discharge_coding_source_content_digest, $discharge->discharge_coding_source_provenance_digest,
            $corrected, 'IRM-CODING-1002',
        );
        $this->assertNotSame($first->codingVersion->content_digest, $second->codingVersion->content_digest);
        try {
            $service->signoff($encounter->public_id, $this->rmik, 1, $old['source_fingerprint'], 1, $old['coding_digest'], 'IRM-SIGNOFF-STALE');
            $this->fail('Expected stale source denial.');
        } catch (InpatientRmDenied $denial) {
            $this->assertSame('source_binding_stale', $denial->reason);
        }
        $fresh = $service->snapshot($encounter->fresh());
        $service->saveReview($encounter->public_id, $this->rmik, 1, $fresh['source_fingerprint'], 2, $fresh['coding_digest'], 'IRM-REVIEW-1002');
        $signed = $service->signoff($encounter->public_id, $this->rmik, 2, $fresh['source_fingerprint'], 2, $fresh['coding_digest'], 'IRM-SIGNOFF-1002');
        $this->assertSame(3, $signed->codingVersion->version);
        $this->assertSame(3, $signed->review->version);
    }

    public function test_assignments_are_server_bound_and_unknown_missing_or_forged_source_rows_are_rejected(): void
    {
        [$encounter, $source] = $this->readyEpisode();
        $discharge = $encounter->fresh()->inpatientDischarge;
        $payload = $this->assignments();
        $payload[0]['source_statement_text_hash'] = hash('sha256', 'forged');
        try {
            app(InpatientRmService::class)->saveCodingDraft(
                $encounter->public_id, $this->rmik, 0, $source->resultVersion->public_id,
                $discharge->discharge_coding_source_content_digest, $discharge->discharge_coding_source_provenance_digest,
                $payload, 'IRM-FORGED-0001',
            );
            $this->fail('Expected forged binding denial.');
        } catch (InpatientRmDenied $denial) {
            $this->assertSame('assignment_binding_invalid', $denial->reason);
        }
        $this->assertDatabaseCount('inpatient_rm_codings', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'rmik.inpatient.coding.draft.save', 'outcome' => 'DENIED', 'reason' => 'assignment_binding_invalid']);
    }

    public function test_system_administrator_can_open_inpatient_rm_index_like_super_user(): void
    {
        $systemAdmin = User::factory()->create(['is_system_administrator' => true]);

        $this->actingAs($this->admin)->get(route('rm.rawat-inap.index'))
            ->assertForbidden();

        $this->actingAs($systemAdmin)->get(route('rm.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rm/rawat-inap')
                ->has('inpatient_rm.encounters')
                ->where('inpatient_rm.actions.show_url', route('rm.rawat-inap.index')));
    }

    public function test_frontend_contract_saves_after_reload_then_reviews_and_signs_off(): void
    {
        [$encounter] = $this->readyEpisode();
        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rm/rawat-inap')
                ->has('inpatient_rm.filters.payer')
                ->has('inpatient_rm.filter_options.wards')
                ->where('inpatient_rm.actions.show_url', route('rm.rawat-inap.index')));
        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rm/rawat-inap/show')
                ->has('inpatient_rm.coding.source_statements', 2)
                ->has('inpatient_rm.coding.assignments', 0)
                ->where('inpatient_rm.coding.can_save_draft', true));

        $this->actingAs($this->rmik)->post(route('rm.rawat-inap.coding.draft', $encounter), [
            'expected_version' => 0,
            'assignments' => $this->assignments(),
            'idempotency_key' => 'IRM-HTTP-CODING-0001',
        ])->assertRedirect(route('rm.rawat-inap.show', $encounter));

        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.show', $encounter))
            ->assertInertia(fn (Assert $page) => $page
                ->has('inpatient_rm.coding.assignments', 2)
                ->where('inpatient_rm.coding.assignments.0.kind', InpatientRmCodingAssignment::KIND_PRINCIPAL)
                ->where('inpatient_rm.coding.assignments.0.source_statement', 'Gastroenteritis akut'));

        $snapshot = app(InpatientRmService::class)->snapshot($encounter->fresh());
        $this->actingAs($this->rmik)->post(route('rm.rawat-inap.reviews.store', $encounter), [
            'expected_version' => 0,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'coding_version' => $snapshot['coding_version'],
            'coding_digest' => $snapshot['coding_digest'],
            'idempotency_key' => 'IRM-HTTP-REVIEW-0001',
        ])->assertRedirect(route('rm.rawat-inap.show', $encounter));

        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.show', $encounter))
            ->assertInertia(fn (Assert $page) => $page
                ->where('inpatient_rm.completeness.status', 'COMPLETE')
                ->where('inpatient_rm.completeness.version', 1)
                ->where('inpatient_rm.completeness.can_signoff', true)
                ->where('inpatient_rm.completeness.items.0.status', 'PASS'));

        $this->actingAs($this->rmik)->post(route('rm.rawat-inap.signoff', $encounter), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'coding_version' => $snapshot['coding_version'],
            'coding_digest' => $snapshot['coding_digest'],
            'idempotency_key' => 'IRM-HTTP-SIGNOFF-0001',
        ])->assertRedirect(route('rm.rawat-inap.show', $encounter));

        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.show', $encounter->fresh()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('inpatient_rm.encounter.status', Encounter::STATUS_CLOSED)
                ->where('inpatient_rm.completeness.status', 'SIGNED_OFF')
                ->where('inpatient_rm.actions.save_coding_draft_url', null)
                ->where('inpatient_rm.actions.save_review_url', null)
                ->where('inpatient_rm.actions.signoff_url', null));
    }

    public function test_completeness_is_system_derived_and_exact_rmik_role_is_required(): void
    {
        [$encounter, $source] = $this->readyEpisode(withDraftDocument: true, withActiveLab: true);
        $discharge = $encounter->fresh()->inpatientDischarge;
        $service = app(InpatientRmService::class);
        try {
            $service->saveCodingDraft(
                $encounter->public_id, $this->physician, 0, $source->resultVersion->public_id,
                $discharge->discharge_coding_source_content_digest, $discharge->discharge_coding_source_provenance_digest,
                $this->assignments(), 'IRM-WRONG-ROLE-0001',
            );
            $this->fail('Expected exact RMIK role denial.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('audit_events', [
                'action' => 'rmik.inpatient.coding.draft.save', 'outcome' => 'DENIED', 'reason' => 'unauthorized_actor',
            ]);
        }
        $service->saveCodingDraft(
            $encounter->public_id, $this->rmik, 0, $source->resultVersion->public_id,
            $discharge->discharge_coding_source_content_digest, $discharge->discharge_coding_source_provenance_digest,
            $this->assignments(), 'IRM-BLOCKERS-CODING-0001',
        );
        $snapshot = $service->snapshot($encounter->fresh());
        $this->assertContains('NO_INPATIENT_DRAFT_DOCUMENTS', $snapshot['blockers']);
        $this->assertContains('NO_ACTIVE_LAB_ORDERS', $snapshot['blockers']);
        $review = $service->saveReview($encounter->public_id, $this->rmik, 0, $snapshot['source_fingerprint'], 1, $snapshot['coding_digest'], 'IRM-BLOCKERS-REVIEW-0001');
        $this->assertSame(2, $review->review->blocker_count);
        $this->actingAs($this->rmik)->post(route('rm.rawat-inap.reviews.store', $encounter), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'coding_version' => 1,
            'coding_digest' => $snapshot['coding_digest'],
            'idempotency_key' => 'IRM-ITEMS-OVERRIDE-0001',
            'items' => [['item_code' => 'NO_ACTIVE_LAB_ORDERS', 'is_complete' => true]],
        ])->assertSessionHasErrors('inpatient_rm');
        $this->assertDatabaseCount('inpatient_rm_completeness_reviews', 1);
    }

    public function test_active_pharmacy_prescription_is_a_system_derived_blocker_and_denies_rmik_signoff(): void
    {
        [$encounter, $source] = $this->readyEpisode();
        $service = app(InpatientRmService::class);
        $discharge = $encounter->fresh()->inpatientDischarge;
        $service->saveCodingDraft(
            $encounter->public_id,
            $this->rmik,
            0,
            $source->resultVersion->public_id,
            $discharge->discharge_coding_source_content_digest,
            $discharge->discharge_coding_source_provenance_digest,
            $this->assignments(),
            'IRM-PHARMACY-CODING-0001',
        );
        PharmacyMutationScope::run(function () use ($encounter): void {
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEPO_RI_RMIK',
                'display_name' => 'Depo Rawat Inap RMIK',
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
                'status' => PharmacyPrescription::PARTIALLY_HANDED_OVER,
                'version' => 1,
                'current_content_digest' => str_repeat('c', 64),
                'ordered_at' => now(),
            ]);
        });

        $snapshot = $service->snapshot($encounter->fresh());
        $this->assertContains('NO_ACTIVE_MEDICATION_PRESCRIPTIONS', $snapshot['blockers']);
        $review = $service->saveReview(
            $encounter->public_id,
            $this->rmik,
            0,
            $snapshot['source_fingerprint'],
            $snapshot['coding_version'],
            $snapshot['coding_digest'],
            'IRM-PHARMACY-REVIEW-0001',
        );
        $this->assertSame(1, $review->review->blocker_count);

        try {
            $service->signoff(
                $encounter->public_id,
                $this->rmik,
                1,
                $snapshot['source_fingerprint'],
                $snapshot['coding_version'],
                $snapshot['coding_digest'],
                'IRM-PHARMACY-SIGNOFF-0001',
            );
            $this->fail('Expected active pharmacy prescription denial.');
        } catch (InpatientRmDenied $denial) {
            $this->assertSame('active_pharmacy_prescriptions', $denial->reason);
            $this->assertSame(['NO_ACTIVE_MEDICATION_PRESCRIPTIONS'], $denial->metadata['failed_item_ids']);
        }

        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()?->status);
        $this->assertDatabaseMissing('inpatient_rm_completeness_reviews', [
            'encounter_id' => $encounter->id,
            'review_state' => InpatientRmCompletenessReview::STATE_SIGNED_OFF,
        ]);
    }

    public function test_summary_addendum_denials_are_audited_and_wrong_role_is_denied_before_lookup(): void
    {
        $encounter = $this->closedEpisode();
        $service = app(InpatientSummaryAddendumService::class);

        foreach ([
            [InpatientSummaryCorrectionRequest::REASON_OTHER, null, 'ISA-OTHER-NOTE-0001', 'request_note_required'],
            [InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION, 'Catatan', 'short', 'validation_failed'],
        ] as [$reason, $note, $key, $expected]) {
            try {
                $service->submit($encounter, $this->physician, $reason, $note, $key);
                $this->fail('Expected summary addendum validation denial.');
            } catch (InpatientSummaryAddendumDenied $denial) {
                $this->assertSame($expected, $denial->reason);
            }
            $this->assertDatabaseHas('audit_events', [
                'action' => 'clinical.inpatient.summary-addendum.request.submit',
                'outcome' => 'DENIED',
                'reason' => $expected,
            ]);
        }

        try {
            $service->submit($encounter, $this->admin, InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION, 'Catatan', 'ISA-WRONG-ROLE-0001');
            $this->fail('Expected exact physician denial.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('audit_events', [
                'action' => 'clinical.inpatient.summary-addendum.request.submit',
                'outcome' => 'DENIED',
                'reason' => 'unauthorized_actor',
            ]);
        }

        $unknown = (string) Str::ulid();
        $this->actingAs($this->admin)->post(route('pemeriksaan.rawat-inap.summary-addenda.requests.decide', $unknown), [
            'decision' => InpatientSummaryCorrectionRequest::STATE_APPROVED,
            'expected_version' => 1,
            'idempotency_key' => 'ISA-UNKNOWN-ROLE-0001',
        ])->assertForbidden();
        $this->assertDatabaseCount('inpatient_summary_correction_requests', 0);
    }

    public function test_summary_addendum_http_projection_validation_review_and_signoff_contract(): void
    {
        $encounter = $this->closedEpisode();
        $otherPhysician = $this->user(RoleCapabilityMatrix::ROLE_PHYSICIAN);

        $this->actingAs($this->physician)->get(route('pemeriksaan.rawat-inap.index', ['scope' => 'correction']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.scope', 'correction')
            ->where('canAccessCorrections', true)
            ->where('encounters.0.public_id', $encounter->public_id));
        $this->actingAs($this->registrar)->get(route('pemeriksaan.rawat-inap.index', ['scope' => 'correction']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.scope', 'active')
            ->where('canAccessCorrections', false)
            ->has('encounters', 0));

        $this->actingAs($this->physician)->post(route('pemeriksaan.rawat-inap.summary-addenda.requests.store', $encounter), [
            'reason_code' => InpatientSummaryCorrectionRequest::REASON_OTHER,
            'idempotency_key' => 'ISA-HTTP-OTHER-0001',
        ])->assertSessionHasErrors('note');
        $this->actingAs($this->physician)->post(route('pemeriksaan.rawat-inap.summary-addenda.requests.store', $encounter), [
            'reason_code' => InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION,
            'note' => 'Tambahkan edukasi keluarga.',
            'idempotency_key' => 'ISA-HTTP-SUBMIT-0001',
        ])->assertSessionHasNoErrors();
        $request = InpatientSummaryCorrectionRequest::query()->sole();

        $this->actingAs($this->physician)->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('pemeriksaan/rawat-inap/show')
            ->where('inpatient_summary_addendum.definition_version', 'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1')
            ->where('inpatient_summary_addendum.can_request', false)
            ->where('inpatient_summary_addendum.requests.0.public_id', $request->public_id)
            ->where('inpatient_summary_addendum.requests.0.permissions.can_decide', false)
            ->where('inpatient_summary_addendum.requests.0.actions.decision_url', null));
        $this->actingAs($otherPhysician)->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('inpatient_summary_addendum.requests.0.permissions.can_decide', true)
            ->where('inpatient_summary_addendum.requests.0.actions.decision_url', route('pemeriksaan.rawat-inap.summary-addenda.requests.decide', $request->public_id)));
        $this->actingAs($this->admin)->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('inpatient_summary_addendum.can_request', false)
            ->has('inpatient_summary_addendum.requests', 0));
        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.show', $encounter))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('inpatient_rm.summary_addendum.requests.0.public_id', $request->public_id)
            ->where('inpatient_rm.summary_addendum.requests.0.actions.save_renewed_review_url', null));

        $this->actingAs($otherPhysician)->post(route('pemeriksaan.rawat-inap.summary-addenda.requests.decide', $request->public_id), [
            'decision' => InpatientSummaryCorrectionRequest::STATE_DENIED,
            'expected_version' => 1,
            'idempotency_key' => 'ISA-HTTP-DENY-VALIDATE-0001',
        ])->assertSessionHasErrors('decision_note');
        $this->actingAs($otherPhysician)->post(route('pemeriksaan.rawat-inap.summary-addenda.requests.decide', $request->public_id), [
            'decision' => InpatientSummaryCorrectionRequest::STATE_APPROVED,
            'decision_note' => 'Disetujui.',
            'expected_version' => 1,
            'idempotency_key' => 'ISA-HTTP-APPROVE-0001',
        ])->assertSessionHasNoErrors();

        $fields = [
            'admission_reason' => null,
            'significant_findings' => null,
            'care_and_treatment_summary' => null,
            'condition_at_discharge' => null,
            'follow_up_plan' => 'Keluarga memantau asupan cairan dan tanda bahaya.',
        ];
        $this->actingAs($this->physician)->post(route('pemeriksaan.rawat-inap.summary-addenda.draft', $request->public_id), [
            'expected_version' => 0,
            'fields' => [...$fields, 'unexpected' => 'ditolak'],
            'idempotency_key' => 'ISA-HTTP-FIELDS-INVALID-0001',
        ])->assertSessionHasErrors('fields');
        $this->actingAs($this->physician)->post(route('pemeriksaan.rawat-inap.summary-addenda.draft', $request->public_id), [
            'expected_version' => 0,
            'fields' => $fields,
            'idempotency_key' => 'ISA-HTTP-DRAFT-0001',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->physician)->post(route('pemeriksaan.rawat-inap.summary-addenda.finalize', $request->public_id), [
            'expected_version' => 1,
            'idempotency_key' => 'ISA-HTTP-FINAL-0001',
        ])->assertSessionHasNoErrors();

        $snapshot = app(InpatientSummaryAddendumService::class)->snapshot($request->fresh());
        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.show', $encounter))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('inpatient_rm.summary_addendum.requests.0.permissions.can_save_renewed_review', true)
            ->where('inpatient_rm.summary_addendum.requests.0.current_review_version', 0)
            ->where('inpatient_rm.summary_addendum.requests.0.current_review_source_fingerprint', $snapshot['source_fingerprint']));
        $this->actingAs($this->rmik)->post(route('rm.rawat-inap.summary-addenda.reviews.store', $request->public_id), [
            'expected_version' => 0,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'idempotency_key' => 'ISA-HTTP-REVIEW-0001',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->rmik)->post(route('rm.rawat-inap.summary-addenda.signoff', $request->public_id), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'idempotency_key' => 'ISA-HTTP-SIGNOFF-0001',
        ])->assertSessionHasNoErrors();

        $this->assertSame(InpatientSummaryCorrectionRequest::STATE_CONSUMED, $request->fresh()->request_state);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
        $this->actingAs($this->rmik)->get(route('rm.rawat-inap.show', $encounter))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('inpatient_rm.summary_addendum.requests.0.renewed_review.state', 'SIGNED_OFF')
            ->where('inpatient_rm.summary_addendum.requests.0.actions.save_renewed_review_url', null)
            ->where('inpatient_rm.summary_addendum.requests.0.actions.signoff_renewed_review_url', null));
    }

    public function test_summary_addendum_service_authorizes_before_request_or_encounter_lookup(): void
    {
        $encounter = $this->closedEpisode();
        $service = app(InpatientSummaryAddendumService::class);
        $submitted = $service->submit(
            $encounter,
            $this->physician,
            InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION,
            'Tambahkan edukasi.',
            'ISA-AUTH-ORDER-SUBMIT-0001',
        );
        $this->admin->roleSlugs();
        $this->admin->capabilityList();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        try {
            $service->decide(
                $submitted->request,
                $this->admin,
                InpatientSummaryCorrectionRequest::STATE_APPROVED,
                'Tidak boleh diproses.',
                1,
                'ISA-AUTH-ORDER-DECIDE-0001',
            );
            $this->fail('Expected exact physician authorization denial.');
        } catch (AuthorizationException) {
            $requestReads = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'inpatient_summary_correction_requests') || str_contains($sql, ' from "encounters"') || str_contains($sql, ' from `encounters`'));
            $this->assertCount(0, $requestReads->all());
            $this->assertDatabaseHas('audit_events', [
                'action' => 'clinical.inpatient.summary-addendum.request.decide',
                'resource_id' => $submitted->request->public_id,
                'outcome' => 'DENIED',
                'reason' => 'unauthorized_actor',
            ]);
        }
    }

    public function test_synthetic_reset_removes_addendum_chain_before_retained_baseline_graph(): void
    {
        $encounter = $this->closedEpisode();
        $service = app(InpatientSummaryAddendumService::class);
        $request = $service->submit(
            $encounter,
            $this->physician,
            InpatientSummaryCorrectionRequest::REASON_MISSING_INFORMATION,
            'Tambahkan edukasi keluarga.',
            'ISA-RESET-SUBMIT-0001',
        )->request;
        $decider = $this->user(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $service->decide($request, $decider, InpatientSummaryCorrectionRequest::STATE_APPROVED, 'Disetujui.', 1, 'ISA-RESET-DECIDE-0001');
        $service->saveDraft($request->fresh(), $this->physician, [
            'admission_reason' => null,
            'significant_findings' => null,
            'care_and_treatment_summary' => null,
            'condition_at_discharge' => null,
            'follow_up_plan' => 'Kontrol tujuh hari.',
        ], 0, 'ISA-RESET-DRAFT-0001');

        app(SyntheticResetService::class)->reset(['actor' => $this->admin, 'reason' => 'summary_addendum_reset_test']);

        foreach ([
            'inpatient_summary_addendum_operation_receipts',
            'inpatient_summary_addendum_review_items',
            'inpatient_summary_addendum_reviews',
            'inpatient_summary_addendum_versions',
            'inpatient_summary_addenda',
            'inpatient_summary_correction_request_versions',
            'inpatient_summary_correction_requests',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.inpatient.summary-addendum.request.submit',
            'resource_id' => $request->public_id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'teaching.reset.completed']);
    }

    /** @return array{Encounter,InpatientDischargeCodingSourceResult} */
    private function readyEpisode(bool $withDraftDocument = false, bool $withActiveLab = false): array
    {
        $patient = Patient::factory()->create(['created_by_user_id' => $this->registrar->id, 'is_synthetic' => true]);
        $encounter = InpatientLocationMutationScope::run(fn () => Encounter::factory()->create([
            'patient_id' => $patient->id, 'registered_by_user_id' => $this->registrar->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT, 'status' => Encounter::STATUS_REGISTERED,
            'inpatient_bed_id' => $this->bed->id, 'ward_name' => $this->ward->display_name,
            'ward_class' => $this->bed->service_class, 'bed_code' => $this->bed->code,
        ]));
        DB::transaction(fn () => app(InpatientBedTransferService::class)->recordAdmission($encounter, $this->ward, $this->bed, $this->registrar, null));
        if ($withDraftDocument) {
            $nurse = $this->user(RoleCapabilityMatrix::ROLE_NURSE);
            app(InpatientDocumentationService::class)->saveDraft(
                $encounter->public_id, $nurse, InpatientClinicalDocument::TYPE_NURSING_DAILY,
                InpatientClinicalDocument::DEFINITION_VERSION, 0,
                ['nursing_observation' => 'Observasi', 'nursing_intervention' => '', 'nursing_evaluation' => '', 'additional_notes' => ''],
                'IRM-DRAFT-DOCUMENT-'.$encounter->id,
            );
        }
        if ($withActiveLab) {
            LabServiceRequest::factory()->create([
                'encounter_id' => $encounter->id,
                'requested_by_user_id' => $this->physician->id,
                'status' => LabServiceRequest::STATUS_ACTIVE,
            ]);
        }
        $summaryService = app(InpatientDischargeSummaryService::class);
        $summaryDraft = $summaryService->saveDraft($encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION, 0, [
            'admission_reason' => 'Demam', 'significant_findings' => 'Dehidrasi',
            'care_and_treatment_summary' => 'Rehidrasi', 'condition_at_discharge' => 'Stabil',
            'follow_up_plan' => 'Kontrol',
        ], 'IRM-SUMMARY-DRAFT-'.$encounter->id);
        $summary = $summaryService->finalize($encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION, $summaryDraft->summary->version, 'IRM-SUMMARY-FINAL-'.$encounter->id)->summary;
        $sourceService = app(InpatientDischargeCodingSourceService::class);
        $sourceDraft = $sourceService->saveDraft($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0, [
            'principal_diagnosis_statement' => 'Gastroenteritis akut',
            'secondary_diagnosis_statements' => ['Dehidrasi ringan'],
            'procedure_attestation' => InpatientDischargeCodingSource::ATTESTATION_NONE,
            'performed_procedure_statements' => [],
        ], 'IRM-SOURCE-DRAFT-'.$encounter->id);
        $source = $sourceService->finalize($encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, $sourceDraft->source->version, 'IRM-SOURCE-FINAL-'.$encounter->id);
        app(InpatientDischargeService::class)->execute(
            $encounter->public_id, $this->physician, $summary->version, 1,
            $this->bed->public_id, 'IRM-DISCHARGE-'.$encounter->id,
        );

        return [$encounter->fresh(), $source];
    }

    private function closedEpisode(): Encounter
    {
        [$encounter, $source] = $this->readyEpisode();
        $rm = app(InpatientRmService::class);
        $discharge = $encounter->inpatientDischarge;
        $coding = $rm->saveCodingDraft(
            $encounter->public_id,
            $this->rmik,
            0,
            $source->resultVersion->public_id,
            $discharge->discharge_coding_source_content_digest,
            $discharge->discharge_coding_source_provenance_digest,
            $this->assignments(),
            'ISA-CLOSE-CODING-'.$encounter->id,
        );
        $snapshot = $rm->snapshot($encounter->fresh());
        $rm->saveReview(
            $encounter->public_id,
            $this->rmik,
            0,
            $snapshot['source_fingerprint'],
            $coding->codingVersion->version,
            $coding->codingVersion->content_digest,
            'ISA-CLOSE-REVIEW-'.$encounter->id,
        );
        $rm->signoff(
            $encounter->public_id,
            $this->rmik,
            1,
            $snapshot['source_fingerprint'],
            $coding->codingVersion->version,
            $coding->codingVersion->content_digest,
            'ISA-CLOSE-SIGNOFF-'.$encounter->id,
        );

        return $encounter->fresh();
    }

    /** @return list<array<string,mixed>> */
    private function assignments(): array
    {
        return [
            [
                'source_statement_kind' => InpatientRmCodingAssignment::KIND_PRINCIPAL,
                'source_statement_index' => 0,
                'source_statement_text_hash' => hash('sha256', 'Gastroenteritis akut'),
                'code' => 'A09', 'description' => 'Gastroenteritis and colitis of unspecified origin',
            ],
            [
                'source_statement_kind' => InpatientRmCodingAssignment::KIND_SECONDARY,
                'source_statement_index' => 0,
                'source_statement_text_hash' => hash('sha256', 'Dehidrasi ringan'),
                'code' => 'E86.0', 'description' => 'Dehydration',
            ],
        ];
    }

    /** @return array{InpatientWard,InpatientBed} */
    private function masters(): array
    {
        $service = app(InpatientMasterService::class);
        $ward = $service->createWard($this->admin, 'RI-RMIK', 'Bangsal RMIK', InpatientMasterService::REASON_INITIAL_SETUP, 'IRM-WARD-0001', null)->master;
        $bed = $service->createBed($this->admin, $ward->public_id, 'RM-01', 'Bed RM-01', 'Ruang RMIK', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'IRM-BED-0001', null)->master;

        return [$ward, $bed];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->firstOrFail()->id]);

        return $user;
    }
}
