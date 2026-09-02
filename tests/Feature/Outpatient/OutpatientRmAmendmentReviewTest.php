<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\OutpatientAmendmentOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\OutpatientRmAmendmentReview;
use App\Models\OutpatientRmAmendmentReviewItem;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\CanonicalJson;
use App\Support\Clinical\OutpatientRmAmendmentService;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class OutpatientRmAmendmentReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, OutpatientMastersSeeder::class]);
    }

    public function test_fingerprint_uses_exact_canonical_baseline_final_addenda_and_sorted_active_orders(): void
    {
        [$request, $encounter, $baseline] = $this->finalAddendumChain();
        $service = app(OutpatientRmAmendmentService::class);
        $snapshot = $service->snapshot($request);
        $addendum = OutpatientClinicalDocumentAddendum::query()->sole();
        $contentDigest = hash('sha256', CanonicalJson::encode([
            'definition_version' => $addendum->definition_version,
            'fields' => $addendum->fields,
        ]));
        $expected = hash('sha256', CanonicalJson::encode([
            'active_lab_order_public_ids' => [],
            'baseline_review' => [
                'public_id' => $baseline->public_id,
                'source_fingerprint' => $baseline->source_fingerprint,
                'version' => $baseline->version,
            ],
            'final_addenda' => [[
                'content_digest' => $contentDigest,
                'public_id' => $addendum->public_id,
                'version' => $addendum->version,
            ]],
            'profile' => OutpatientRmAmendmentService::FINGERPRINT_PROFILE,
        ]));
        $this->assertSame($expected, $snapshot['source_fingerprint']);
        $this->assertSame([], $snapshot['blockers']);
        $this->assertSame(
            ['BASELINE_SIGNOFF', 'FINAL_ADDENDUM', 'CURRENT_SOURCE_REFERENCE', 'NO_ACTIVE_LAB_ORDERS'],
            array_column($snapshot['items'], 'item_code'),
        );

        $first = $this->activeLabOrder($encounter, 'ZZZ-TEST');
        $second = $this->activeLabOrder($encounter, 'AAA-TEST');
        $withOrders = $service->snapshot($request);
        $expectedIds = [$first->public_id, $second->public_id];
        sort($expectedIds, SORT_STRING);
        $this->assertNotSame($snapshot['source_fingerprint'], $withOrders['source_fingerprint']);
        $this->assertSame(['NO_ACTIVE_LAB_ORDERS'], $withOrders['blockers']);
        $expectedWithOrders = hash('sha256', CanonicalJson::encode([
            'active_lab_order_public_ids' => $expectedIds,
            'baseline_review' => [
                'public_id' => $baseline->public_id,
                'source_fingerprint' => $baseline->source_fingerprint,
                'version' => $baseline->version,
            ],
            'final_addenda' => [[
                'content_digest' => $contentDigest,
                'public_id' => $addendum->public_id,
                'version' => $addendum->version,
            ]],
            'profile' => OutpatientRmAmendmentService::FINGERPRINT_PROFILE,
        ]));
        $this->assertSame($expectedWithOrders, $withOrders['source_fingerprint']);
    }

    public function test_rmik_saves_replays_and_signs_off_append_only_snapshots_without_reopening_encounter(): void
    {
        [$request, $encounter, $baseline] = $this->finalAddendumChain();
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $baselineOriginal = $baseline->getAttributes();
        $snapshot = app(OutpatientRmAmendmentService::class)->snapshot($request);

        $this->actingAs($rmik)
            ->get(route('rm.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('amendments.0.current_review_source_fingerprint', $snapshot['source_fingerprint'])
                ->where('amendments.0.current_review_version', 0)
                ->where('amendments.0.permissions.can_save_renewed_review', true)
                ->where('amendments.0.actions.save_renewed_review_url', route('rm.rawat-jalan.amendments.reviews.store', $request)));

        $save = $this->reviewPayload(0, $snapshot['source_fingerprint'], 'amend-review-save-0001');
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $save)
            ->assertRedirect();
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $save)
            ->assertRedirect()
            ->assertSessionHas('success', 'Review addendum sudah tercatat.');
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), [
                ...$save,
                'source_fingerprint' => str_repeat('b', 64),
            ])->assertStatus(422);

        $draft = OutpatientRmAmendmentReview::query()->sole();
        $this->assertSame(OutpatientRmAmendmentReview::STATE_DRAFT, $draft->review_state);
        $this->assertSame(1, $draft->version);
        $this->assertCount(4, $draft->items);

        $signoff = $this->reviewPayload(1, $snapshot['source_fingerprint'], 'amend-review-signoff-0001');
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.signoff', $request), $signoff)
            ->assertRedirect();
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.signoff', $request), $signoff)
            ->assertRedirect()
            ->assertSessionHas('success', 'Sign-off review addendum sudah tercatat.');

        $signed = OutpatientRmAmendmentReview::query()->orderByDesc('version')->firstOrFail();
        $this->assertSame(OutpatientRmAmendmentReview::STATE_SIGNED_OFF, $signed->review_state);
        $this->assertSame(2, $signed->version);
        $this->assertSame($rmik->id, $signed->signed_off_by_user_id);
        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 2);
        $this->assertDatabaseCount('outpatient_rm_amendment_review_items', 8);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_CONSUMED, $request->fresh()->request_state);
        $currentBaseline = $baseline->fresh()->getAttributes();
        ksort($baselineOriginal);
        ksort($currentBaseline);
        $this->assertSame($baselineOriginal, $currentBaseline);
        $this->assertSame(1, AuditEvent::query()->where('action', 'rmik.outpatient.amendment.review.save')->where('outcome', 'SUCCESS')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'rmik.outpatient.amendment.signoff')->where('outcome', 'SUCCESS')->count());

        $receiptsBeforeTerminalSave = OutpatientAmendmentOperationReceipt::query()->count();
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $save)
            ->assertStatus(422);
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $this->reviewPayload(2, $snapshot['source_fingerprint'], 'amend-review-after-signoff'))
            ->assertStatus(422);
        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 2);
        $this->assertDatabaseCount('outpatient_rm_amendment_review_items', 8);
        $this->assertSame($receiptsBeforeTerminalSave, OutpatientAmendmentOperationReceipt::query()->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'rmik.outpatient.amendment.review.save',
            'outcome' => 'DENIED',
            'reason' => 'stale_version',
        ]);

        $this->expectException(LogicException::class);
        $draft->update(['source_fingerprint' => str_repeat('c', 64)]);
    }

    public function test_direct_review_creation_is_rejected_even_with_plausible_aggregate_values(): void
    {
        [$request, $encounter, $baseline] = $this->finalAddendumChain();
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $addendum = OutpatientClinicalDocumentAddendum::query()->sole();
        $snapshot = app(OutpatientRmAmendmentService::class)->snapshot($request);

        try {
            OutpatientRmAmendmentReview::query()->create([
                'amendment_request_id' => $request->id,
                'addendum_id' => $addendum->id,
                'encounter_id' => $encounter->id,
                'baseline_review_id' => $baseline->id,
                'reviewed_by_user_id' => $rmik->id,
                'signed_off_by_user_id' => null,
                'definition_version' => OutpatientRmAmendmentReview::DEFINITION_VERSION,
                'version' => 1,
                'source_fingerprint' => $snapshot['source_fingerprint'],
                'review_state' => OutpatientRmAmendmentReview::STATE_DRAFT,
                'reviewed_at' => now(),
                'signed_off_at' => null,
            ]);
            $this->fail('Direct renewed review creation unexpectedly succeeded.');
        } catch (LogicException $exception) {
            $this->assertSame('Renewed RMIK reviews may only be created as exact guarded aggregate snapshots.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 0);
        $this->assertDatabaseCount('outpatient_rm_amendment_review_items', 0);
    }

    public function test_direct_review_item_creation_is_rejected_outside_the_guarded_snapshot(): void
    {
        [$request] = $this->finalAddendumChain();
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $snapshot = app(OutpatientRmAmendmentService::class)->snapshot($request);
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $this->reviewPayload(0, $snapshot['source_fingerprint'], 'amend-guarded-review-save'))
            ->assertRedirect();
        $review = OutpatientRmAmendmentReview::query()->sole();

        try {
            OutpatientRmAmendmentReviewItem::query()->create([
                'review_id' => $review->id,
                'item_code' => 'UNBOUNDED_EVIDENCE',
                'label' => 'Evidence not defined by the aggregate',
                'is_blocking' => false,
                'is_complete' => true,
                'source_reference' => 'forged',
            ]);
            $this->fail('Direct renewed review item creation unexpectedly succeeded.');
        } catch (LogicException $exception) {
            $this->assertSame('Renewed RMIK review items may only be created inside their exact guarded snapshot.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outpatient_rm_amendment_review_items', 4);
    }

    public function test_active_lab_order_is_fingerprinted_saved_as_blocker_and_blocks_signoff(): void
    {
        [$request, $encounter] = $this->finalAddendumChain();
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $this->activeLabOrder($encounter, 'HB-ACTIVE');
        $snapshot = app(OutpatientRmAmendmentService::class)->snapshot($request);
        $this->assertSame(['NO_ACTIVE_LAB_ORDERS'], $snapshot['blockers']);

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $this->reviewPayload(0, $snapshot['source_fingerprint'], 'amend-active-save-0001'))
            ->assertRedirect();
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.signoff', $request), $this->reviewPayload(1, $snapshot['source_fingerprint'], 'amend-active-sign-0001'))
            ->assertStatus(422);
        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'rmik.outpatient.amendment.signoff',
            'outcome' => 'DENIED',
            'reason' => 'active_lab_orders',
        ]);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
    }

    public function test_only_rmik_can_access_review_operations_before_request_lookup(): void
    {
        [$request] = $this->finalAddendumChain();
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $systemAdmin = User::factory()->create(['is_system_administrator' => true]);
        $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $payload = $this->reviewPayload(0, str_repeat('a', 64), 'amend-review-auth-0001');

        foreach ([$physician, $admin, $systemAdmin] as $actor) {
            $existing = $this->actingAs($actor)
                ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $payload);
            $missing = $this->actingAs($actor)
                ->post(route('rm.rawat-jalan.amendments.reviews.store', $unknown), $payload);
            $this->assertSame(403, $existing->getStatusCode());
            $this->assertSame($existing->getStatusCode(), $missing->getStatusCode());
        }
        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 0);
    }

    public function test_review_audit_failure_rolls_back_snapshot_and_receipt(): void
    {
        [$request] = $this->finalAddendumChain();
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $snapshot = app(OutpatientRmAmendmentService::class)->snapshot($request);
        $receiptsBefore = OutpatientAmendmentOperationReceipt::query()->count();
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $this->reviewPayload(0, $snapshot['source_fingerprint'], 'amend-review-audit-0001'))
            ->assertStatus(503);
        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 0);
        $this->assertDatabaseCount('outpatient_rm_amendment_review_items', 0);
        $this->assertSame($receiptsBefore, OutpatientAmendmentOperationReceipt::query()->count());
    }

    public function test_signoff_audit_failure_rolls_back_signed_snapshot(): void
    {
        [$request] = $this->finalAddendumChain();
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $snapshot = app(OutpatientRmAmendmentService::class)->snapshot($request);
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $this->reviewPayload(0, $snapshot['source_fingerprint'], 'amend-sign-audit-save'))
            ->assertRedirect();
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.signoff', $request), $this->reviewPayload(1, $snapshot['source_fingerprint'], 'amend-sign-audit-final'))
            ->assertStatus(503);
        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 1);
        $this->assertDatabaseHas('outpatient_rm_amendment_reviews', ['review_state' => OutpatientRmAmendmentReview::STATE_DRAFT]);
    }

    public function test_reset_cascades_renewed_review_chain_and_preserves_audit(): void
    {
        [$request] = $this->finalAddendumChain();
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $snapshot = app(OutpatientRmAmendmentService::class)->snapshot($request);
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.amendments.reviews.store', $request), $this->reviewPayload(0, $snapshot['source_fingerprint'], 'amend-review-reset-0001'))
            ->assertRedirect();
        $auditId = AuditEvent::query()->where('action', 'rmik.outpatient.amendment.review.save')->value('id');

        $this->assertSame(0, Artisan::call('simulation:reset', ['--force' => true]));
        $this->assertDatabaseCount('outpatient_rm_amendment_reviews', 0);
        $this->assertDatabaseCount('outpatient_rm_amendment_review_items', 0);
        $this->assertDatabaseHas('audit_events', ['id' => $auditId]);
    }

    /** @return array{OutpatientPostClosureAmendmentRequest, Encounter, OutpatientRmCompletenessReview} */
    private function finalAddendumChain(): array
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $patient = Patient::factory()->create(['created_by_user_id' => $requester->id, 'is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $requester->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_CLOSED,
        ]);
        $document = OutpatientClinicalDocument::query()->create([
            'encounter_id' => $encounter->id,
            'author_user_id' => $requester->id,
            'finalized_by_user_id' => $requester->id,
            'document_type' => OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
            'document_state' => OutpatientClinicalDocument::STATE_FINAL,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'version' => 1,
            'fields' => ['anamnesis' => 'Sintetis', 'objective_examination' => 'Sintetis', 'clinical_assessment' => 'Sintetis', 'care_plan' => 'Sintetis'],
            'finalized_at' => now(),
        ]);
        OutpatientClinicalDocumentVersion::query()->create([
            'outpatient_clinical_document_id' => $document->id,
            'actor_user_id' => $requester->id,
            'version' => 1,
            'document_state' => OutpatientClinicalDocument::STATE_FINAL,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'fields' => $document->fields,
            'finalized_at' => now(),
        ]);
        $baseline = OutpatientRmCompletenessReview::query()->create([
            'encounter_id' => $encounter->id,
            'reviewed_by_user_id' => $requester->id,
            'signed_off_by_user_id' => $requester->id,
            'definition_version' => OutpatientRmCompletenessReview::DEFINITION_VERSION,
            'version' => 1,
            'source_fingerprint' => str_repeat('a', 64),
            'review_state' => OutpatientRmCompletenessReview::STATE_SIGNED_OFF,
            'reviewed_at' => now(),
            'signed_off_at' => now(),
        ]);

        $this->actingAs($requester)->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), [
            'original_document_public_id' => $document->public_id,
            'original_document_version' => 1,
            'reason_code' => OutpatientPostClosureAmendmentRequest::REASON_CLINICAL_CORRECTION,
            'note' => 'Koreksi sintetis.',
            'idempotency_key' => 'rm-amend-submit-0001',
        ])->assertRedirect();
        $request = OutpatientPostClosureAmendmentRequest::query()->sole();
        $this->actingAs($approver)->post(route('pemeriksaan.rawat-jalan.amendments.decision', $request), [
            'decision' => OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
            'decision_note' => 'Disetujui.',
            'expected_version' => 1,
            'idempotency_key' => 'rm-amend-decide-0001',
        ])->assertRedirect();
        $this->actingAs($requester)->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $request), [
            'expected_version' => 0,
            'fields' => ['addendum_text' => 'Addendum Final sintetis.'],
            'idempotency_key' => 'rm-amend-write-0001',
        ])->assertRedirect();
        $this->actingAs($requester)->post(route('pemeriksaan.rawat-jalan.amendments.addendum.finalize', $request), [
            'expected_version' => 1,
            'idempotency_key' => 'rm-amend-finalize-0001',
        ])->assertRedirect();

        return [$request->fresh(), $encounter->fresh(), $baseline->fresh()];
    }

    private function activeLabOrder(Encounter $encounter, string $testCode): LabServiceRequest
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);

        return LabServiceRequest::query()->create([
            'encounter_id' => $encounter->id,
            'requested_by_user_id' => $physician->id,
            'test_code' => $testCode,
            'test_label' => $testCode,
            'clinical_question' => 'Sintetis',
            'status' => LabServiceRequest::STATUS_ACTIVE,
            'requested_at' => now(),
        ]);
    }

    /** @return array{expected_version: int, source_fingerprint: string, idempotency_key: string} */
    private function reviewPayload(int $version, string $fingerprint, string $key): array
    {
        return [
            'expected_version' => $version,
            'source_fingerprint' => $fingerprint,
            'idempotency_key' => $key,
        ];
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
