<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\OutpatientAmendmentOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\CanonicalJson;
use App\Support\Clinical\OutpatientPostClosureAmendmentService;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class OutpatientPostClosureAmendmentRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, OutpatientMastersSeeder::class]);
    }

    public function test_physician_submits_exact_request_and_replay_is_stable_while_changed_payload_conflicts(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $original = $this->normalizedClinicalDocumentAttributes($document);
        $payload = $this->submitPayload($document, ['idempotency_key' => 'amend-submit-0001']);

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $payload)
            ->assertRedirect();
        $created = OutpatientPostClosureAmendmentRequest::query()->sole();
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED, $created->request_state);
        $this->assertSame(1, $created->version);
        $this->assertSame($document->id, $created->original_document_id);
        $this->assertSame($requester->id, $created->requested_by_user_id);

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $payload)
            ->assertRedirect()
            ->assertSessionHas('success', 'Permintaan addendum sudah tercatat.');

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), [
                ...$payload,
                'reason_code' => OutpatientPostClosureAmendmentRequest::REASON_WRONG_ENTRY,
            ])->assertStatus(422);

        $this->assertDatabaseCount('outpatient_post_closure_amendment_requests', 1);
        $this->assertDatabaseCount('outpatient_amendment_operation_receipts', 1);
        $current = $this->normalizedClinicalDocumentAttributes($document->fresh());
        $this->assertSame($original, $current);
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'clinical.outpatient.amendment.request.submit')
            ->where('outcome', 'SUCCESS')->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.outpatient.amendment.request.submit',
            'outcome' => 'DENIED',
            'reason' => 'idempotency_key_conflict',
        ]);
    }

    public function test_different_physician_can_approve_or_deny_but_requester_cannot_decide(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->submit($requester, $encounter, $document, 'amend-submit-0002');

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), $this->decisionPayload('amend-decide-self-0001'))
            ->assertStatus(422);
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED, $amendment->fresh()->request_state);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.outpatient.amendment.request.decide',
            'resource_id' => $amendment->public_id,
            'outcome' => 'DENIED',
            'reason' => 'requester_cannot_decide',
        ]);

        $payload = $this->decisionPayload('amend-decide-approve-0001');
        $this->actingAs($approver)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), $payload)
            ->assertRedirect();
        $approved = $amendment->fresh();
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_APPROVED, $approved->request_state);
        $this->assertSame(2, $approved->version);
        $this->assertSame($approver->id, $approved->decided_by_user_id);

        $this->actingAs($approver)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), $payload)
            ->assertRedirect()
            ->assertSessionHas('success', 'Keputusan addendum sudah tercatat.');
        $this->assertSame(2, OutpatientAmendmentOperationReceipt::query()->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'clinical.outpatient.amendment.request.decide')
            ->where('outcome', 'SUCCESS')->count());
    }

    public function test_denial_requires_note_and_is_terminal(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->submit($requester, $encounter, $document, 'amend-submit-0003');

        $this->actingAs($approver)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), [
                ...$this->decisionPayload('amend-decide-deny-0001'),
                'decision' => OutpatientPostClosureAmendmentRequest::STATE_DENIED,
                'decision_note' => null,
            ])->assertSessionHasErrors('decision_note');

        $this->actingAs($approver)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), [
                ...$this->decisionPayload('amend-decide-deny-0002'),
                'decision' => OutpatientPostClosureAmendmentRequest::STATE_DENIED,
                'decision_note' => 'Sumber awal sudah sesuai dan tidak memerlukan addendum.',
            ])->assertRedirect();
        $denied = $amendment->fresh();
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_DENIED, $denied->request_state);

        $this->expectException(LogicException::class);
        $denied->update(['decision_note' => 'Tidak boleh berubah']);
    }

    public function test_validation_is_enforced_at_http_and_service_boundaries(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), [
                ...$this->submitPayload($document, ['reason_code' => OutpatientPostClosureAmendmentRequest::REASON_OTHER]),
                'note' => null,
            ])->assertSessionHasErrors('note');

        $this->expectException(InvalidArgumentException::class);
        app(OutpatientPostClosureAmendmentService::class)->submit(
            encounter: $encounter,
            actor: $requester,
            originalDocumentPublicId: $document->public_id,
            originalDocumentVersion: $document->version,
            reasonCode: 'UNREGISTERED_REASON',
            note: null,
            idempotencyKey: 'amend-service-invalid-0001',
            requestCorrelationId: null,
        );
    }

    public function test_closed_final_source_and_baseline_signoff_are_required_without_source_mutation(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$draftEncounter, $draftDocument] = $this->closedEncounterWithEvidence(
            $requester,
            documentState: OutpatientClinicalDocument::STATE_DRAFT,
        );
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $draftEncounter), $this->submitPayload($draftDocument, ['idempotency_key' => 'amend-source-draft-0001']))
            ->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['reason' => 'original_document_not_final']);

        [$encounter, $document] = $this->closedEncounterWithEvidence($requester, withBaselineSignoff: false);
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $this->submitPayload($document, ['idempotency_key' => 'amend-no-baseline-0001']))
            ->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['reason' => 'baseline_signoff_missing']);

        $encounter->update(['status' => Encounter::STATUS_READY_FOR_RM]);
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $this->submitPayload($document, ['idempotency_key' => 'amend-not-closed-0001']))
            ->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['reason' => 'encounter_not_closed']);
        $this->assertDatabaseCount('outpatient_post_closure_amendment_requests', 0);
    }

    public function test_admin_and_wrong_role_are_denied_before_request_or_encounter_lookup(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $systemAdmin = User::factory()->create(['is_system_administrator' => true]);
        [$encounter, $document] = $this->closedEncounterWithEvidence($physician);
        $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        foreach ([$nurse, $admin] as $actor) {
            $existing = $this->actingAs($actor)
                ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $this->submitPayload($document));
            $unknownResponse = $this->actingAs($actor)
                ->post(route('pemeriksaan.rawat-jalan.amendments.store', $unknown), $this->submitPayload($document));
            $this->assertSame(403, $existing->getStatusCode());
            $this->assertSame($existing->getStatusCode(), $unknownResponse->getStatusCode());
        }

        $this->actingAs($systemAdmin)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $this->submitPayload($document, [
                'idempotency_key' => 'amend-system-admin-submit-0001',
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('outpatient_post_closure_amendment_requests', 1);
        $this->assertSame(4, AuditEvent::query()
            ->where('action', 'authorization.denied')
            ->where('resource_id', 'pemeriksaan.rawat-jalan.amendments.store')->count());
    }

    public function test_audit_failure_rolls_back_request(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $this->submitPayload($document, ['idempotency_key' => 'amend-audit-submit-0001']))
            ->assertStatus(503);
        $this->assertDatabaseCount('outpatient_post_closure_amendment_requests', 0);
        $this->assertDatabaseCount('outpatient_amendment_operation_receipts', 0);
    }

    public function test_audit_failure_rolls_back_decision(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->submit($requester, $encounter, $document, 'amend-audit-seed-0001');
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);
        $this->actingAs($approver)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), $this->decisionPayload('amend-audit-decide-0001'))
            ->assertStatus(503);
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED, $amendment->fresh()->request_state);
        $this->assertSame(1, $amendment->fresh()->version);
    }

    public function test_projection_exposes_request_and_decision_only_with_backend_owned_reason_options(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->submit($requester, $encounter, $document, 'amend-projection-0001');

        $this->actingAs($approver)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_request_amendment', true)
                ->where('amendmentReasonOptions.3.value', OutpatientPostClosureAmendmentRequest::REASON_OTHER)
                ->where('amendmentReasonOptions.3.requires_note', true)
                ->where('amendments.0.public_id', $amendment->public_id)
                ->where('amendments.0.original_document.public_id', $document->public_id)
                ->where('amendments.0.permissions.can_decide', true)
                ->where('amendments.0.permissions.can_write_addendum', false)
                ->where('amendments.0.addendum', null)
                ->where('amendments.0.renewed_review', null));
    }

    public function test_synthetic_reset_cascades_request_and_receipt_but_preserves_audit(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->approvedRequest($requester, $approver, $encounter, $document, 'amend-reset');
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), [
                'expected_version' => 0,
                'fields' => ['addendum_text' => 'Addendum untuk reset sintetis.'],
                'idempotency_key' => 'amend-reset-write-0001',
            ])->assertRedirect();
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.finalize', $amendment), [
                'expected_version' => 1,
                'idempotency_key' => 'amend-reset-final-0001',
            ])->assertRedirect();
        $successAuditId = AuditEvent::query()
            ->where('action', 'clinical.outpatient.amendment.request.submit')
            ->where('outcome', 'SUCCESS')->value('id');

        $this->assertSame(0, Artisan::call('simulation:reset', ['--force' => true]));

        $this->assertDatabaseCount('outpatient_post_closure_amendment_requests', 0);
        $this->assertDatabaseCount('outpatient_clinical_document_addenda', 0);
        $this->assertDatabaseCount('outpatient_clinical_document_addendum_versions', 0);
        $this->assertDatabaseCount('outpatient_amendment_operation_receipts', 0);
        $this->assertDatabaseHas('audit_events', ['id' => $successAuditId]);
        $this->assertDatabaseHas('audit_events', ['action' => 'teaching.reset.completed']);
    }

    public function test_approved_requester_writes_replays_and_finalizes_append_only_addendum(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $original = $this->normalizedClinicalDocumentAttributes($document);
        $amendment = $this->approvedRequest($requester, $approver, $encounter, $document, 'amend-addendum-normal');
        $this->actingAs($requester)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('amendments.0.permissions.can_write_addendum', true)
                ->where('amendments.0.permissions.can_finalize_addendum', false)
                ->where('amendments.0.actions.save_addendum_url', route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment)));
        $write = [
            'expected_version' => 0,
            'fields' => ['addendum_text' => 'Informasi klinis tambahan sintetis.'],
            'idempotency_key' => 'amend-write-normal-0001',
        ];

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), $write)
            ->assertRedirect();
        $addendum = OutpatientClinicalDocumentAddendum::query()->sole();
        $this->assertSame(OutpatientClinicalDocumentAddendum::STATE_DRAFT, $addendum->addendum_state);
        $this->assertSame(1, $addendum->version);
        $this->assertSame($requester->id, $addendum->author_user_id);
        $this->actingAs($requester)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('amendments.0.addendum.fields.addendum_text', 'Informasi klinis tambahan sintetis.')
                ->where('amendments.0.permissions.can_finalize_addendum', true)
                ->where('amendments.0.actions.finalize_addendum_url', route('pemeriksaan.rawat-jalan.amendments.addendum.finalize', $amendment)));

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), $write)
            ->assertRedirect()
            ->assertSessionHas('success', 'Draf addendum sudah tercatat.');
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), [
                ...$write,
                'fields' => ['addendum_text' => 'Payload yang berbeda.'],
            ])->assertStatus(422);

        $finalize = ['expected_version' => 1, 'idempotency_key' => 'amend-final-normal-0001'];
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.finalize', $amendment), $finalize)
            ->assertRedirect();
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.finalize', $amendment), $finalize)
            ->assertRedirect()
            ->assertSessionHas('success', 'Finalisasi addendum sudah tercatat.');

        $final = $addendum->fresh();
        $this->assertSame(OutpatientClinicalDocumentAddendum::STATE_FINAL, $final->addendum_state);
        $this->assertSame(2, $final->version);
        $this->assertSame($requester->id, $final->finalized_by_user_id);
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_CONSUMED, $amendment->fresh()->request_state);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
        $this->assertDatabaseCount('outpatient_clinical_document_addendum_versions', 2);
        $this->assertSame(4, OutpatientAmendmentOperationReceipt::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'clinical.outpatient.amendment.addendum.write')->where('outcome', 'SUCCESS')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'clinical.outpatient.amendment.addendum.finalize')->where('outcome', 'SUCCESS')->count());
        $current = $this->normalizedClinicalDocumentAttributes($document->fresh());
        $this->assertSame($original, $current);

        $this->expectException(LogicException::class);
        $final->update(['fields' => ['addendum_text' => 'Tidak boleh berubah.']]);
    }

    public function test_only_approved_requester_can_write_or_finalize_and_wrong_roles_are_denied_before_lookup(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $other = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->approvedRequest($requester, $approver, $encounter, $document, 'amend-addendum-auth');
        $payload = [
            'expected_version' => 0,
            'fields' => ['addendum_text' => 'Addendum sintetis.'],
            'idempotency_key' => 'amend-write-auth-0001',
        ];

        foreach ([$approver, $other] as $actor) {
            $this->actingAs($actor)
                ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), $payload)
                ->assertStatus(422);
        }
        $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $existing = $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), $payload);
        $missing = $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $unknown), $payload);
        $this->assertSame(403, $existing->getStatusCode());
        $this->assertSame($existing->getStatusCode(), $missing->getStatusCode());
        $this->assertDatabaseCount('outpatient_clinical_document_addenda', 0);
        $this->assertDatabaseHas('audit_events', ['reason' => 'actor_not_approved_author']);
    }

    public function test_addendum_requires_approved_current_source_and_exact_versions(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->submit($requester, $encounter, $document, 'amend-addendum-guard-submit');
        $payload = [
            'expected_version' => 0,
            'fields' => ['addendum_text' => 'Addendum sintetis.'],
            'idempotency_key' => 'amend-write-guard-0001',
        ];
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), $payload)
            ->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['reason' => 'request_not_approved']);

        $this->actingAs($approver)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), $this->decisionPayload('amend-addendum-guard-decide'))
            ->assertRedirect();
        DB::table($document->getTable())->where('id', $document->id)->update([
            'document_state' => OutpatientClinicalDocument::STATE_DRAFT,
        ]);
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), [
                ...$payload,
                'idempotency_key' => 'amend-write-guard-0002',
            ])->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['reason' => 'original_document_not_final']);

        DB::table($document->getTable())->where('id', $document->id)->update([
            'document_state' => OutpatientClinicalDocument::STATE_FINAL,
        ]);
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), [
                ...$payload,
                'expected_version' => 3,
                'idempotency_key' => 'amend-write-guard-0003',
            ])->assertStatus(422);
        $this->assertDatabaseHas('audit_events', ['reason' => 'stale_version']);
    }

    public function test_addendum_audit_failure_rolls_back_write(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->approvedRequest($requester, $approver, $encounter, $document, 'amend-addendum-audit');
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), [
                'expected_version' => 0,
                'fields' => ['addendum_text' => 'Rollback sintetis.'],
                'idempotency_key' => 'amend-write-audit-0001',
            ])->assertStatus(503);
        $this->assertDatabaseCount('outpatient_clinical_document_addenda', 0);
        $this->assertDatabaseCount('outpatient_clinical_document_addendum_versions', 0);
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_APPROVED, $amendment->fresh()->request_state);
    }

    public function test_addendum_audit_failure_rolls_back_finalization_and_consumption(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->approvedRequest($requester, $approver, $encounter, $document, 'amend-final-audit');
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), [
                'expected_version' => 0,
                'fields' => ['addendum_text' => 'Draf sebelum audit final gagal.'],
                'idempotency_key' => 'amend-final-audit-write',
            ])->assertRedirect();
        $addendum = OutpatientClinicalDocumentAddendum::query()->sole();

        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.finalize', $amendment), [
                'expected_version' => 1,
                'idempotency_key' => 'amend-final-audit-final',
            ])->assertStatus(503);

        $this->assertSame(OutpatientClinicalDocumentAddendum::STATE_DRAFT, $addendum->fresh()->addendum_state);
        $this->assertSame(1, $addendum->fresh()->version);
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_APPROVED, $amendment->fresh()->request_state);
        $this->assertDatabaseCount('outpatient_clinical_document_addendum_versions', 1);
    }

    public function test_non_clinical_projection_exposes_no_amendment_facts(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $this->approvedRequest($requester, $approver, $encounter, $document, 'amend-addendum-projection');

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('amendments', 0));
    }

    public function test_request_model_rejects_identity_drift_self_decision_and_illegal_transitions(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->submit($requester, $encounter, $document, 'amend-request-model-guard');

        $this->assertLogicException(fn () => $amendment->update([
            'note' => 'Alasan tidak boleh diganti.',
            'request_state' => OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
            'version' => 2,
            'decided_by_user_id' => $requester->id,
            'decided_at' => now(),
        ]));
        $this->assertLogicException(fn () => $amendment->fresh()->update([
            'request_state' => OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
            'version' => 2,
            'decided_by_user_id' => $requester->id,
            'decided_at' => now(),
        ]));
        $this->assertLogicException(fn () => $amendment->fresh()->update([
            'request_state' => OutpatientPostClosureAmendmentRequest::STATE_CONSUMED,
            'version' => 2,
            'consumed_at' => now(),
        ]));
        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED, $amendment->fresh()->request_state);
        $this->assertSame(1, $amendment->fresh()->version);
    }

    public function test_addendum_aggregate_models_reject_direct_final_creation_provenance_drift_and_orphan_consumption(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $approver = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $otherPhysician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $amendment = $this->approvedRequest($requester, $approver, $encounter, $document, 'amend-aggregate-model');

        $this->assertLogicException(fn () => $amendment->update([
            'request_state' => OutpatientPostClosureAmendmentRequest::STATE_CONSUMED,
            'version' => $amendment->version + 1,
            'consumed_at' => now(),
        ]));
        $this->assertLogicException(fn () => OutpatientClinicalDocumentAddendum::query()->create([
            'amendment_request_id' => $amendment->id,
            'encounter_id' => $encounter->id,
            'original_document_id' => $document->id,
            'original_document_version' => $document->version,
            'author_user_id' => $requester->id,
            'finalized_by_user_id' => $requester->id,
            'addendum_state' => OutpatientClinicalDocumentAddendum::STATE_FINAL,
            'definition_version' => OutpatientClinicalDocumentAddendum::DEFINITION_VERSION,
            'version' => 1,
            'fields' => ['addendum_text' => 'Final langsung tidak sah.'],
            'finalized_at' => now(),
        ]));

        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.addendum.store', $amendment), [
                'expected_version' => 0,
                'fields' => ['addendum_text' => 'Draf sah untuk pengujian guard agregat.'],
                'idempotency_key' => 'amend-aggregate-model-write',
            ])->assertRedirect();
        $draft = OutpatientClinicalDocumentAddendum::query()->sole();

        $this->assertLogicException(fn () => $draft->update([
            'author_user_id' => $otherPhysician->id,
            'version' => $draft->version + 1,
        ]));
        $draft = $draft->fresh();
        $this->assertLogicException(fn () => $draft->update([
            'addendum_state' => OutpatientClinicalDocumentAddendum::STATE_FINAL,
            'version' => $draft->version + 1,
            'finalized_by_user_id' => $requester->id,
            'finalized_at' => now(),
        ]));

        $this->assertSame(OutpatientPostClosureAmendmentRequest::STATE_APPROVED, $amendment->fresh()->request_state);
        $this->assertSame(OutpatientClinicalDocumentAddendum::STATE_DRAFT, $draft->fresh()->addendum_state);
        $this->assertSame($requester->id, $draft->fresh()->author_user_id);
        $this->assertDatabaseCount('outpatient_clinical_document_addendum_versions', 1);
    }

    public function test_source_foreign_key_refuses_direct_document_deletion_while_request_is_retained(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $this->submit($requester, $encounter, $document, 'amend-source-fk-guard');

        $this->expectException(QueryException::class);
        DB::table($document->getTable())->where('id', $document->id)->delete();
    }

    public function test_reset_then_down_still_refuses_to_erase_correlated_audit_evidence(): void
    {
        $requester = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $document] = $this->closedEncounterWithEvidence($requester);
        $this->submit($requester, $encounter, $document, 'amend-reset-down-guard');
        $this->assertSame(0, Artisan::call('simulation:reset', ['--force' => true]));
        $this->assertDatabaseCount('outpatient_post_closure_amendment_requests', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.amendment.request.submit']);

        $migration = require database_path('migrations/2026_08_30_000200_create_outpatient_post_closure_amendment_tables.php');
        try {
            $migration->down();
            $this->fail('Expected rollback to refuse retained correlated amendment audit evidence.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertTrue(Schema::hasTable('outpatient_post_closure_amendment_requests'));
    }

    /** @return array{Encounter, OutpatientClinicalDocument} */
    private function closedEncounterWithEvidence(
        User $physician,
        string $documentState = OutpatientClinicalDocument::STATE_FINAL,
        bool $withBaselineSignoff = true,
    ): array {
        $patient = Patient::factory()->create(['created_by_user_id' => $physician->id, 'is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $physician->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_CLOSED,
        ]);
        $document = OutpatientClinicalDocument::query()->create([
            'encounter_id' => $encounter->id,
            'author_user_id' => $physician->id,
            'finalized_by_user_id' => $physician->id,
            'document_type' => OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
            'document_state' => $documentState,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'version' => 1,
            'fields' => ['anamnesis' => 'Sintetis', 'objective_examination' => 'Sintetis', 'clinical_assessment' => 'Sintetis', 'care_plan' => 'Sintetis'],
            'finalized_at' => $documentState === OutpatientClinicalDocument::STATE_FINAL ? now() : null,
        ]);
        OutpatientClinicalDocumentVersion::query()->create([
            'outpatient_clinical_document_id' => $document->id,
            'actor_user_id' => $physician->id,
            'version' => 1,
            'document_state' => $documentState,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'fields' => $document->fields,
            'finalized_at' => $documentState === OutpatientClinicalDocument::STATE_FINAL ? now() : null,
        ]);
        if ($withBaselineSignoff) {
            OutpatientRmCompletenessReview::query()->create([
                'encounter_id' => $encounter->id,
                'reviewed_by_user_id' => $physician->id,
                'signed_off_by_user_id' => $physician->id,
                'definition_version' => OutpatientRmCompletenessReview::DEFINITION_VERSION,
                'version' => 1,
                'source_fingerprint' => str_repeat('a', 64),
                'review_state' => OutpatientRmCompletenessReview::STATE_SIGNED_OFF,
                'reviewed_at' => now(),
                'signed_off_at' => now(),
            ]);
        }

        return [$encounter, $document];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function submitPayload(OutpatientClinicalDocument $document, array $overrides = []): array
    {
        return array_merge([
            'original_document_public_id' => $document->public_id,
            'original_document_version' => $document->version,
            'reason_code' => OutpatientPostClosureAmendmentRequest::REASON_CLINICAL_CORRECTION,
            'note' => 'Koreksi lokal sintetis.',
            'idempotency_key' => 'amend-submit-default-0001',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function decisionPayload(string $key): array
    {
        return [
            'decision' => OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
            'decision_note' => 'Disetujui untuk addendum sintetis.',
            'expected_version' => 1,
            'idempotency_key' => $key,
        ];
    }

    private function submit(
        User $requester,
        Encounter $encounter,
        OutpatientClinicalDocument $document,
        string $key,
    ): OutpatientPostClosureAmendmentRequest {
        $this->actingAs($requester)
            ->post(route('pemeriksaan.rawat-jalan.amendments.store', $encounter), $this->submitPayload($document, ['idempotency_key' => $key]))
            ->assertRedirect();

        return OutpatientPostClosureAmendmentRequest::query()->latest('id')->firstOrFail();
    }

    private function approvedRequest(
        User $requester,
        User $approver,
        Encounter $encounter,
        OutpatientClinicalDocument $document,
        string $keyPrefix,
    ): OutpatientPostClosureAmendmentRequest {
        $amendment = $this->submit($requester, $encounter, $document, $keyPrefix.'-submit');
        $this->actingAs($approver)
            ->post(route('pemeriksaan.rawat-jalan.amendments.decision', $amendment), $this->decisionPayload($keyPrefix.'-decide'))
            ->assertRedirect();

        return $amendment->fresh();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function normalizedClinicalDocumentAttributes(OutpatientClinicalDocument $document): array
    {
        $attributes = $document->getAttributes();
        $fields = json_decode((string) $attributes['fields'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($fields)) {
            throw new LogicException('Clinical document fields must decode to an array.');
        }
        $attributes['fields'] = json_decode(CanonicalJson::encode($fields), true, 512, JSON_THROW_ON_ERROR);
        ksort($attributes);

        return $attributes;
    }

    private function assertLogicException(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected immutable amendment model guard to reject the mutation.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }
}
