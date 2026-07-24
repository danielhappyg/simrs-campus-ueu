<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Enums\DiagnosticResultStatus;
use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewItemOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Models\MedicationDispensePreparation;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Clinical\Models\ResultAcknowledgement;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\CodingReviewAction;
use App\Modules\Coding\Enums\CodingSelectionMethod;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\CodingSuggestionOutcome;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\CodingReviewActionModel;
use App\Modules\Coding\Models\CodingSuggestionCandidate;
use App\Modules\Coding\Models\CodingSuggestionDecision;
use App\Modules\Coding\Models\CodingSuggestionRun;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\RecordQuality\Enums\RecordCorrectionStatus;
use App\Modules\RecordQuality\Enums\RecordQualityFindingSeverity;
use App\Modules\RecordQuality\Enums\RecordQualityReviewAction;
use App\Modules\RecordQuality\Enums\RecordQualityReviewStatus;
use App\Modules\RecordQuality\Models\RecordCorrectionRequest;
use App\Modules\RecordQuality\Models\RecordQualityFinding;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Models\RecordQualityReviewActionModel;
use App\Modules\RecordQuality\Services\RecordCompletenessService;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class RecordQualityWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_versioned_checklist_draft_is_reproducible_and_idempotent(): void
    {
        $case = $this->prepareClinicallyClosedCase();
        $payload = $this->recordReviewPayload(ClinicalSaveIntent::SaveDraft);

        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.record-quality.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('record-quality/workspace')
                ->where('patient.allergyStatus', AllergyAssessmentState::NoKnownAllergyReported->label())
                ->where('completeness.checklistVersion', RecordCompletenessService::CHECKLIST_VERSION)
                ->where('completeness.ready', true)
                ->where('assignment.canAuthor', true)
                ->has('completeness.checks', 9)
            );
        $this->actingAs($case['rmikCoder'])
            ->post(route('encounters.record-quality.reviews.store', $case['encounter']), $payload)
            ->assertRedirect(route('encounters.record-quality.show', $case['encounter']));
        $this->actingAs($case['rmikCoder'])
            ->post(route('encounters.record-quality.reviews.store', $case['encounter']), $payload)
            ->assertRedirect(route('encounters.record-quality.show', $case['encounter']));

        $review = RecordQualityReview::query()->sole();
        $this->assertSame(RecordQualityReviewStatus::Draft, $review->status);
        $this->assertSame(1, $review->version_number);
        $this->assertSame(RecordCompletenessService::CHECKLIST_VERSION, $review->checklist_version);
        $this->assertSame(hash('sha256', CanonicalJson::encode($review->content)), $review->content_hash);
        $this->assertTrue((bool) data_get($review->content, 'completeness.ready'));
        $this->assertSame(EncounterStatus::RecordReview, $case['encounter']->refresh()->status);
        $this->assertDatabaseCount('record_quality_reviews', 1);
    }

    public function test_linked_supervisor_receives_the_exact_submitted_record_quality_snapshot(): void
    {
        $case = $this->prepareClinicallyClosedCase();
        $closure = EncounterClosure::query()->sole();

        $this->actingAs($case['rmikCoder'])
            ->post(
                route('encounters.record-quality.reviews.store', $case['encounter']),
                $this->recordReviewPayload(ClinicalSaveIntent::Submit),
            )
            ->assertRedirect(route('encounters.record-quality.show', $case['encounter']));

        $review = RecordQualityReview::query()->sole();

        $this->actingAs($case['rmikSupervisor'])
            ->get(route('encounters.record-quality.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('record-quality/workspace')
                ->where('document.canReview', true)
                ->where('document.latestVersion.publicId', $review->public_id)
                ->where('document.latestVersion.contentHash', $review->content_hash)
                ->where('document.latestVersion.content.checklistVersion', RecordCompletenessService::CHECKLIST_VERSION)
                ->where('document.latestVersion.content.completeness.ready', true)
                ->has('document.latestVersion.content.completeness.checks', 9)
                ->where('document.latestVersion.content.assemblySnapshot.closure.publicId', $closure->public_id)
                ->where('document.latestVersion.content.assemblySnapshot.closure.contentHash', $closure->content_hash)
                ->has('document.latestVersion.findings', 0));
    }

    public function test_blocking_finding_routes_an_immutable_correction_to_the_exact_clinical_author(): void
    {
        $case = $this->prepareClinicallyClosedCase();
        $this->saveBlockingReview($case);
        $finding = RecordQualityFinding::query()->sole();
        $payload = ['request_key' => (string) Str::ulid(), 'reason' => $finding->requested_action];

        $this->actingAs($case['nurse'])
            ->post(route('record-quality-findings.corrections.store', $finding), $payload)
            ->assertForbidden();
        $this->actingAs($case['rmikCoder'])
            ->post(route('record-quality-findings.corrections.store', $finding), $payload)
            ->assertRedirect(route('encounters.record-quality.show', $case['encounter']));
        $this->actingAs($case['rmikCoder'])
            ->post(route('record-quality-findings.corrections.store', $finding), $payload)
            ->assertRedirect(route('encounters.record-quality.show', $case['encounter']));

        $correction = RecordCorrectionRequest::query()->sole();
        $oldClosure = EncounterClosure::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(RecordCorrectionStatus::Open, $correction->status);
        $this->assertSame($case['medicalAssignment']->getKey(), $correction->responsible_assignment_id);
        $this->assertSame($oldClosure->content_hash, $correction->requested_source_hash);
        $this->assertSame(EncounterStatus::AmendmentPending, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::RecordCorrection->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::RecordReview->value,
            'status' => WorkTaskStatus::Blocked->value,
        ]);
    }

    public function test_author_correction_and_medical_approval_return_the_case_to_rmik_without_overwrite(): void
    {
        $state = $this->prepareApprovedCorrectionCase();

        $this->assertSame(2, $state['correctedClosure']->version_number);
        $this->assertSame($state['oldClosure']->getKey(), $state['correctedClosure']->supersedes_closure_id);
        $this->assertNotSame($state['oldHash'], $state['correctedClosure']->content_hash);
        $this->assertSame($state['oldHash'], $state['oldClosure']->refresh()->content_hash);
        $this->assertSame(RecordCorrectionStatus::CorrectedPendingVerification, $state['correction']->refresh()->status);
        $this->assertSame($state['correctedClosure']->getKey(), $state['correction']->response_closure_id);
        $this->assertSame(EncounterStatus::RecordReview, $state['case']['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $state['case']['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::RecordReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
    }

    public function test_rerun_resolves_correction_preserves_history_and_requires_linked_rmik_supervisor(): void
    {
        $state = $this->prepareApprovedCorrectionCase();
        $payload = $this->recordReviewPayload(
            ClinicalSaveIntent::Submit,
            resolvedCorrectionIds: [$state['correction']->public_id],
        );

        $this->actingAs($state['case']['rmikCoder'])
            ->post(route('encounters.record-quality.reviews.store', $state['case']['encounter']), $payload)
            ->assertRedirect(route('encounters.record-quality.show', $state['case']['encounter']));

        $second = RecordQualityReview::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(2, $second->version_number);
        $this->assertSame(RecordQualityReviewStatus::Submitted, $second->status);
        $this->assertSame(RecordCorrectionStatus::Resolved, $state['correction']->refresh()->status);
        $this->assertDatabaseCount('record_quality_reviews', 2);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $state['case']['rmikSupervisorAssignment']->getKey(),
            'task_type' => WorkTaskType::RecordQualityReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);

        $this->actingAs($state['case']['nursingSupervisor'])
            ->post(
                route('record-quality-reviews.decisions.store', $second),
                $this->rmikDecisionPayload(RecordQualityReviewAction::ApproveSimulation),
            )
            ->assertForbidden();

        $decision = $this->rmikDecisionPayload(RecordQualityReviewAction::ApproveSimulation);
        $this->actingAs($state['case']['rmikSupervisor'])
            ->post(route('record-quality-reviews.decisions.store', $second), $decision)
            ->assertRedirect(route('encounters.record-quality.show', $state['case']['encounter']));
        $this->actingAs($state['case']['rmikSupervisor'])
            ->post(route('record-quality-reviews.decisions.store', $second), $decision)
            ->assertRedirect(route('encounters.record-quality.show', $state['case']['encounter']));

        $this->assertSame(RecordQualityReviewStatus::Approved, $second->refresh()->status);
        $this->assertDatabaseCount('record_quality_review_actions', 1);
        $this->assertSame(EncounterStatus::RecordReview, $state['case']['encounter']->refresh()->status);
    }

    public function test_submission_rechecks_deterministic_blockers_and_quality_records_are_immutable(): void
    {
        $case = $this->prepareClinicallyClosedCase();
        $firstResult = DiagnosticResult::query()->sole();
        $facilitatorAssignment = Assignment::query()
            ->where('application_role', ApplicationRole::Facilitator)
            ->sole();
        $correctedContent = $firstResult->content;
        $correctedContent['narrativeConclusion'] = 'Koreksi hasil sintetis diterima setelah penutupan klinis.';
        $correctedResult = DiagnosticResult::query()->create([
            'request_key' => (string) Str::ulid(),
            'service_request_id' => $firstResult->service_request_id,
            'version_number' => 2,
            'status' => DiagnosticResultStatus::Corrected,
            'report_code' => $firstResult->report_code,
            'report_display' => $firstResult->report_display,
            'content' => $correctedContent,
            'content_hash' => hash('sha256', CanonicalJson::encode($correctedContent)),
            'performer_user_id' => $facilitatorAssignment->user_id,
            'performer_assignment_id' => $facilitatorAssignment->getKey(),
            'effective_at' => now(),
            'issued_at' => now(),
            'supersedes_result_id' => $firstResult->getKey(),
        ]);

        $this->actingAs($case['rmikCoder'])
            ->post(
                route('encounters.record-quality.reviews.store', $case['encounter']),
                $this->recordReviewPayload(ClinicalSaveIntent::Submit),
            )
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('record_quality_reviews', 0);

        ResultAcknowledgement::query()->create([
            'request_key' => (string) Str::ulid(),
            'diagnostic_result_id' => $correctedResult->getKey(),
            'actor_user_id' => $case['medicalAssignment']->user_id,
            'actor_assignment_id' => $case['medicalAssignment']->getKey(),
            'outcome' => 'ACKNOWLEDGED',
            'comment' => 'Koreksi hasil ditinjau sebelum telaah RMIK dilanjutkan.',
            'acknowledged_at' => now(),
        ]);
        $this->actingAs($case['rmikCoder'])
            ->post(
                route('encounters.record-quality.reviews.store', $case['encounter']),
                $this->recordReviewPayload(ClinicalSaveIntent::Submit),
            )
            ->assertRedirect(route('encounters.record-quality.show', $case['encounter']));
        $review = RecordQualityReview::query()->sole();

        $this->actingAs($case['rmikSupervisor'])->post(
            route('record-quality-reviews.decisions.store', $review),
            $this->rmikDecisionPayload(RecordQualityReviewAction::ApproveSimulation),
        );
        $action = RecordQualityReviewActionModel::query()->sole();

        $this->assertDomainFailure(fn () => $review->update(['content' => ['forbidden' => true]]), 'review update');
        $review->refresh();
        $this->assertDomainFailure(fn () => $review->delete(), 'review deletion');
        $this->assertDomainFailure(fn () => $action->update(['comment' => 'forbidden']), 'action update');
        $action->refresh();
        $this->assertDomainFailure(fn () => $action->delete(), 'action deletion');
    }

    public function test_coding_candidates_never_become_final_without_coder_and_linked_rmik_supervisor(): void
    {
        $case = $this->prepareClinicallyClosedCase();
        $condition = ClinicalCondition::query()->sole();
        $release = $this->activateSyntheticIcd10Release($condition);
        $qualityReview = $this->approveRecordQualityForCoding($case);

        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::Coding->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->actingAs($case['nursingSupervisor'])
            ->get(route('encounters.coding.show', $case['encounter']))
            ->assertForbidden();
        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.coding.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coding/workspace')
                ->where('patient.allergyStatus', AllergyAssessmentState::NoKnownAllergyReported->label())
                ->where('assignment.canCode', true)
                ->where('assignment.canReview', false)
                ->where('prerequisites.qualityApproved', true)
                ->where('prerequisites.qualityReviewPublicId', $qualityReview->public_id)
                ->where('sources.0.authoredText', $condition->authored_text)
                ->where('sources.0.latestRun', null)
                ->where('releases.0.release.sourceSha256', $release->source_sha256)
            );

        $suggestionRequestKey = (string) Str::ulid();
        $suggestionRoute = route('encounters.conditions.coding-suggestions.store', [
            'encounter' => $case['encounter'],
            'condition' => $condition,
        ]);
        $this->actingAs($case['nurse'])
            ->post($suggestionRoute, ['request_key' => (string) Str::ulid()])
            ->assertForbidden();
        $this->actingAs($case['rmikCoder'])
            ->post($suggestionRoute, ['request_key' => $suggestionRequestKey])
            ->assertRedirect();
        $this->actingAs($case['rmikCoder'])
            ->post($suggestionRoute, ['request_key' => $suggestionRequestKey])
            ->assertRedirect();

        $run = CodingSuggestionRun::query()->sole();
        $candidate = CodingSuggestionCandidate::query()->sole();
        $this->assertDatabaseCount('coding_suggestion_runs', 1);
        $this->assertDatabaseCount('coding_suggestion_candidates', 1);
        $this->assertDatabaseCount('coding_assignments', 0);
        $this->assertSame(EncounterStatus::RecordReview, $case['encounter']->refresh()->status);
        $this->assertSame($condition->getKey(), $run->source_condition_id);
        $this->assertSame($release->source_sha256, data_get($candidate->evidence, 'terminologySourceHash'));
        $this->assertSame(
            $run->sourceEntryVersion()->value('content_hash'),
            data_get($candidate->evidence, 'sourceClinicalContentHash'),
        );

        $decisionRequestKey = (string) Str::ulid();
        $decisionPayload = [
            'request_key' => $decisionRequestKey,
            'decision' => CodingDecisionType::AcceptedToDraft->value,
            'candidate_public_id' => $candidate->public_id,
            'manual_concept_public_id' => null,
            'reason' => null,
            'rationale' => 'Koder meninjau diagnosis, konteks, dan kandidat sebelum membuat draf.',
            'change_reason' => null,
        ];
        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $run), $decisionPayload)
            ->assertRedirect();
        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $run), $decisionPayload)
            ->assertRedirect();

        $coding = CodingAssignment::query()->sole();
        $originalContentHash = $coding->content_hash;
        $this->assertSame(CodingAssignmentStatus::Draft, $coding->status);
        $this->assertSame(CodingSelectionMethod::Suggested, $coding->selection_method);
        $this->assertSame('R42', $coding->concept()->value('code'));
        $this->assertDatabaseCount('coding_suggestion_decisions', 1);
        $this->assertDatabaseCount('coding_assignments', 1);
        $this->assertSame(EncounterStatus::RecordReview, $case['encounter']->refresh()->status);

        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-assignments.submit', $coding))
            ->assertRedirect();
        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-assignments.submit', $coding))
            ->assertRedirect();
        $this->assertSame(CodingAssignmentStatus::Submitted, $coding->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikSupervisorAssignment']->getKey(),
            'task_type' => WorkTaskType::CodingReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseCount('coding_review_actions', 0);
        $this->assertSame(EncounterStatus::RecordReview, $case['encounter']->refresh()->status);

        $reviewPayload = [
            'request_key' => (string) Str::ulid(),
            'action' => CodingReviewAction::ApproveSimulation->value,
            'comment' => 'Supervisor RMIK meninjau sumber, release, pemilihan, dan hash.',
            'findings' => [],
        ];
        $this->actingAs($case['nursingSupervisor'])
            ->post(route('coding-assignments.reviews.store', $coding), $reviewPayload)
            ->assertForbidden();
        $this->actingAs($case['rmikSupervisor'])
            ->post(route('coding-assignments.reviews.store', $coding), $reviewPayload)
            ->assertRedirect();
        $this->actingAs($case['rmikSupervisor'])
            ->post(route('coding-assignments.reviews.store', $coding), $reviewPayload)
            ->assertRedirect();

        $this->assertSame(CodingAssignmentStatus::Approved, $coding->refresh()->status);
        $this->assertSame($originalContentHash, $coding->content_hash);
        $this->assertSame(EncounterStatus::Finalized, $case['encounter']->refresh()->status);
        $this->assertNotNull($case['encounter']->finalized_at);
        $this->assertSame(10, WorkTask::query()->where('task_type', WorkTaskType::Debrief)->count());
        $this->assertDatabaseCount('coding_review_actions', 1);
        $this->assertDatabaseCount('coding_suggestion_decisions', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.assignment_approved_for_simulation',
            'resource_id' => $coding->public_id,
        ]);
        $decision = CodingSuggestionDecision::query()->sole();
        $reviewAction = CodingReviewActionModel::query()->sole();
        $this->assertSame($coding->getKey(), $decision->resulting_assignment_id);
        $this->assertSame($coding->content_hash, $reviewAction->reviewed_content_hash);
        $this->assertDomainFailure(fn () => $coding->update(['rationale' => 'forbidden']), 'coding assignment update');
        $coding->refresh();
        $this->assertDomainFailure(fn () => $coding->delete(), 'coding assignment deletion');
    }

    public function test_completed_procedure_uses_icd9cm_and_requires_separate_human_approval_before_finalization(): void
    {
        $case = $this->prepareClinicallyClosedCase(withPerformedProcedure: true);
        $condition = ClinicalCondition::query()->sole();
        $procedure = ClinicalProcedure::query()->sole();
        $icd10Release = $this->activateSyntheticIcd10Release($condition);
        $icd9Release = $this->activateSyntheticRelease(
            system: TerminologySystem::Icd9Cm,
            code: '38.99',
            display: $procedure->authored_text,
        );
        $icd10Concept = TerminologyConcept::query()
            ->where('terminology_release_id', $icd10Release->getKey())
            ->sole();
        $this->approveRecordQualityForCoding($case);

        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.coding.show', [
                'encounter' => $case['encounter'],
                'source' => $procedure->public_id,
                'q' => '38.99',
                'system' => TerminologySystem::Icd10->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coding/workspace')
                ->has('sources', 2)
                ->where('sources.0.sourceType.code', CodingSourceType::Diagnosis->value)
                ->where('sources.0.terminologySystem', TerminologySystem::Icd10->value)
                ->where('sources.1.publicId', $procedure->public_id)
                ->where('sources.1.sourceType.code', CodingSourceType::Procedure->value)
                ->where('sources.1.terminologySystem', TerminologySystem::Icd9Cm->value)
                ->where('sources.1.correctionSupported', true)
                ->where('sources.1.procedureDetails.performerText', $procedure->performer_text)
                ->where('sources.1.sourceVersion.contentHash', $procedure->content_hash)
                ->where('sources.1.canGenerate', true)
                ->where('selectedSourcePublicId', $procedure->public_id)
                ->where('manualSearch.system', TerminologySystem::Icd9Cm->value)
                ->where('manualSearch.results.0.concept.code', '38.99')
                ->where('prerequisites.closureSourceApproved', true)
            );

        $suggestionRoute = route('encounters.procedures.coding-suggestions.store', [
            'encounter' => $case['encounter'],
            'procedure' => $procedure,
        ]);
        $this->actingAs($case['nurse'])
            ->post($suggestionRoute, ['request_key' => (string) Str::ulid()])
            ->assertForbidden();
        $requestKey = (string) Str::ulid();
        $this->actingAs($case['rmikCoder'])
            ->post($suggestionRoute, ['request_key' => $requestKey])
            ->assertRedirect();
        $this->actingAs($case['rmikCoder'])
            ->post($suggestionRoute, ['request_key' => $requestKey])
            ->assertRedirect();

        $procedureRun = CodingSuggestionRun::query()
            ->where('source_type', CodingSourceType::Procedure)
            ->sole();
        $procedureCandidate = CodingSuggestionCandidate::query()
            ->where('coding_suggestion_run_id', $procedureRun->getKey())
            ->sole();
        $this->assertSame($procedure->getKey(), $procedureRun->source_procedure_id);
        $this->assertNull($procedureRun->source_condition_id);
        $this->assertNull($procedureRun->source_entry_version_id);
        $this->assertSame($icd9Release->getKey(), $procedureRun->terminology_release_id);
        $this->assertSame($procedure->public_id, data_get($procedureCandidate->evidence, 'sourceProcedurePublicId'));
        $this->assertSame($procedure->content_hash, data_get($procedureCandidate->evidence, 'sourceProcedureContentHash'));
        $this->assertSame($icd9Release->source_sha256, data_get($procedureCandidate->evidence, 'terminologySourceHash'));
        $this->assertDatabaseCount('coding_assignments', 0);

        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $procedureRun), [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::ManualAlternative->value,
                'candidate_public_id' => null,
                'manual_concept_public_id' => $icd10Concept->public_id,
                'reason' => null,
                'rationale' => 'Percobaan lintas sistem harus ditolak.',
                'change_reason' => null,
            ])
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('coding_suggestion_decisions', 0);

        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $procedureRun), [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::AcceptedToDraft->value,
                'candidate_public_id' => $procedureCandidate->public_id,
                'manual_concept_public_id' => null,
                'reason' => null,
                'rationale' => 'Koder meninjau catatan tindakan, waktu, pelaksana, dan kandidat ICD-9-CM.',
                'change_reason' => null,
            ])
            ->assertRedirect();

        $procedureCoding = CodingAssignment::query()
            ->where('source_type', CodingSourceType::Procedure)
            ->sole();
        $this->assertSame(CodingAssignmentStatus::Draft, $procedureCoding->status);
        $this->assertSame($procedure->getKey(), $procedureCoding->source_procedure_id);
        $this->assertNull($procedureCoding->source_condition_id);
        $this->assertNull($procedureCoding->source_entry_version_id);
        $this->assertSame($procedure->content_hash, $procedureCoding->source_clinical_content_hash);
        $this->assertSame('38.99', $procedureCoding->concept()->value('code'));

        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-assignments.submit', $procedureCoding))
            ->assertRedirect();
        $this->actingAs($case['rmikSupervisor'])
            ->post(route('coding-assignments.reviews.store', $procedureCoding), [
                'request_key' => (string) Str::ulid(),
                'action' => CodingReviewAction::ApproveSimulation->value,
                'comment' => 'Sumber prosedur, release ICD-9-CM, dan hash ditinjau.',
                'findings' => [],
            ])
            ->assertRedirect();

        $this->assertSame(CodingAssignmentStatus::Approved, $procedureCoding->refresh()->status);
        $this->assertSame(EncounterStatus::RecordReview, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::Coding->value,
            'status' => WorkTaskStatus::InProgress->value,
        ]);

        $diagnosisSuggestionRoute = route('encounters.conditions.coding-suggestions.store', [
            'encounter' => $case['encounter'],
            'condition' => $condition,
        ]);
        $this->actingAs($case['rmikCoder'])
            ->post($diagnosisSuggestionRoute, ['request_key' => (string) Str::ulid()])
            ->assertRedirect();
        $diagnosisRun = CodingSuggestionRun::query()
            ->where('source_type', CodingSourceType::Diagnosis)
            ->sole();
        $diagnosisCandidate = CodingSuggestionCandidate::query()
            ->where('coding_suggestion_run_id', $diagnosisRun->getKey())
            ->sole();
        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $diagnosisRun), [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::AcceptedToDraft->value,
                'candidate_public_id' => $diagnosisCandidate->public_id,
                'manual_concept_public_id' => null,
                'reason' => null,
                'rationale' => 'Diagnosis ditinjau terpisah dari tindakan.',
                'change_reason' => null,
            ])
            ->assertRedirect();
        $diagnosisCoding = CodingAssignment::query()
            ->where('source_type', CodingSourceType::Diagnosis)
            ->sole();
        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-assignments.submit', $diagnosisCoding))
            ->assertRedirect();
        $this->actingAs($case['rmikSupervisor'])
            ->post(route('coding-assignments.reviews.store', $diagnosisCoding), [
                'request_key' => (string) Str::ulid(),
                'action' => CodingReviewAction::ApproveSimulation->value,
                'comment' => 'Diagnosis dan release ICD-10 ditinjau terpisah.',
                'findings' => [],
            ])
            ->assertRedirect();

        $this->assertSame(CodingAssignmentStatus::Approved, $diagnosisCoding->refresh()->status);
        $this->assertSame(EncounterStatus::Finalized, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::Coding->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.procedure_suggestions_generated',
            'resource_id' => $procedureRun->public_id,
        ]);
        $this->assertDatabaseCount('coding_assignments', 2);
        $this->assertDatabaseCount('coding_review_actions', 2);
    }

    public function test_procedure_correction_preserves_the_source_and_requires_closure_supervision_and_rmik_reapproval(): void
    {
        $case = $this->prepareClinicallyClosedCase(withPerformedProcedure: true);
        $oldClosure = EncounterClosure::query()->sole();
        $oldProcedure = ClinicalProcedure::query()->sole();
        $oldQuality = $this->approveRecordQualityForCoding($case);
        $this->activateSyntheticRelease(
            system: TerminologySystem::Icd9Cm,
            code: '38.99',
            display: $oldProcedure->authored_text,
        );

        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.procedures.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'procedure' => $oldProcedure,
            ]),
            ['request_key' => (string) Str::ulid()],
        )->assertRedirect();
        $run = CodingSuggestionRun::query()
            ->where('source_type', CodingSourceType::Procedure)
            ->sole();
        $candidate = CodingSuggestionCandidate::query()
            ->where('coding_suggestion_run_id', $run->getKey())
            ->sole();
        $this->actingAs($case['rmikCoder'])->post(
            route('coding-suggestion-runs.decisions.store', $run),
            [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::AcceptedToDraft->value,
                'candidate_public_id' => $candidate->public_id,
                'manual_concept_public_id' => null,
                'reason' => null,
                'rationale' => 'Draf dibuat sebelum koreksi sumber prosedur ditemukan.',
                'change_reason' => null,
            ],
        )->assertRedirect();
        $staleCoding = CodingAssignment::query()
            ->where('source_type', CodingSourceType::Procedure)
            ->sole();
        $correctionPayload = [
            'request_key' => (string) Str::ulid(),
            'decision' => CodingDecisionType::CorrectionRequested->value,
            'candidate_public_id' => null,
            'manual_concept_public_id' => null,
            'reason' => 'Lokasi pengambilan sampel harus diperjelas sebelum pemilihan ICD-9-CM.',
            'rationale' => null,
            'change_reason' => null,
        ];

        $this->actingAs($case['nurse'])
            ->post(route('coding-suggestion-runs.decisions.store', $run), $correctionPayload)
            ->assertForbidden();
        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $run), $correctionPayload)
            ->assertRedirect();

        $correction = ProcedureDocumentationCorrection::query()->sole();
        $this->assertSame(ProcedureDocumentationCorrectionStatus::Open, $correction->status);
        $this->assertSame($oldProcedure->getKey(), $correction->source_procedure_id);
        $this->assertSame($oldClosure->getKey(), $correction->source_closure_id);
        $this->assertSame($oldQuality->getKey(), $correction->superseded_record_quality_review_id);
        $this->assertSame($oldProcedure->content_hash, $correction->requested_source_hash);
        $this->assertSame($oldClosure->content_hash, $correction->requested_closure_hash);
        $this->assertSame(EncounterStatus::AmendmentPending, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::ProcedureSourceCorrection->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::Coding->value,
            'status' => WorkTaskStatus::Blocked->value,
        ]);
        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.coding.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentationCorrection.publicId', $correction->public_id)
                ->where('documentationCorrection.sourceType', CodingSourceType::Procedure->value)
                ->where('selectedSourcePublicId', $oldProcedure->public_id)
                ->where('assignment.canCode', false)
            );
        $this->actingAs($case['rmikCoder'])
            ->get(route('work'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.actionUrl', route('encounters.coding.show', [
                    'encounter' => $case['encounter'],
                    'source' => $oldProcedure->public_id,
                ]))
            );
        $this->actingAs($case['medicalLearner'])
            ->get(route('encounters.closure.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('procedureCodingCorrection.publicId', $correction->public_id)
                ->where('procedureCodingCorrection.sourceProcedurePublicId', $oldProcedure->public_id)
                ->where('document.authoredFieldsLocked', true)
                ->where('document.canAuthor', true)
            );

        /** @var array<string, mixed> $authored */
        $authored = data_get($oldClosure->content, 'authored');
        /** @var array<string, mixed> $priorProcedure */
        $priorProcedure = data_get($oldClosure->content, 'procedureDocumentation.procedures.0');
        $successorPayload = [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => $oldClosure->clinical_occurrence_at->toIso8601String(),
            'leaving_condition' => $authored['leavingCondition'],
            'disposition' => $authored['disposition'],
            'follow_up_plan' => $authored['followUpPlan'],
            'referral_plan' => $authored['referralPlan'],
            'education_instructions' => $authored['educationInstructions'],
            'outpatient_summary' => $authored['outpatientSummary'],
            'procedure_documentation_state' => ProcedureDocumentationState::ProceduresRecorded->value,
            'procedures' => [[
                'authored_text' => 'Pengambilan sampel darah vena lengan kanan untuk pemeriksaan sintetis.',
                'performed_start_at' => $priorProcedure['performedStartAt'],
                'performed_end_at' => $priorProcedure['performedEndAt'],
                'performer_text' => $priorProcedure['performerText'],
                'body_site_text' => $priorProcedure['bodySiteText'],
                'outcome_text' => $priorProcedure['outcomeText'],
                'note' => $priorProcedure['note'],
                'reason_condition_public_id' => $priorProcedure['reasonConditionPublicId'],
                'based_on_service_request_public_id' => $priorProcedure['basedOnServiceRequestPublicId'],
            ]],
            'change_reason' => 'Lokasi prosedur diperjelas sesuai permintaan koder.',
        ];
        $invalidPayload = $successorPayload;
        $invalidPayload['request_key'] = (string) Str::ulid();
        $invalidPayload['outpatient_summary'] = 'Perubahan di luar lingkup koreksi prosedur.';
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $invalidPayload)
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('encounter_closures', 1);

        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $successorPayload)
            ->assertRedirect();
        $successorClosure = EncounterClosure::query()->orderByDesc('version_number')->firstOrFail();
        $successorProcedure = ClinicalProcedure::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(2, $successorClosure->version_number);
        $this->assertNotSame($oldProcedure->getKey(), $successorProcedure->getKey());
        $this->assertSame(2, ClinicalProcedure::query()->count());
        $this->assertSame(ProcedureDocumentationCorrectionStatus::ClosureResponseSubmitted, $correction->refresh()->status);
        $this->assertSame($oldClosure->content_hash, $oldClosure->refresh()->content_hash);
        $this->assertSame($oldProcedure->content_hash, $oldProcedure->refresh()->content_hash);

        $this->actingAs($case['nursingSupervisor'])->post(
            route('encounter-closures.review-decisions.store', $successorClosure),
            $this->closureDecisionPayload(EncounterClosureReviewAction::ApproveSimulation),
        )->assertForbidden();
        $this->actingAs($case['medicalSupervisor'])->post(
            route('encounter-closures.review-decisions.store', $successorClosure),
            $this->closureDecisionPayload(EncounterClosureReviewAction::ApproveSimulation),
        )->assertRedirect();

        $this->assertSame(ProcedureDocumentationCorrectionStatus::ReadyForRmik, $correction->refresh()->status);
        $this->assertSame(CodingAssignmentStatus::ReviewRequired, $staleCoding->refresh()->status);
        $this->assertSame(EncounterStatus::RecordReview, $case['encounter']->refresh()->status);
        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.record-quality.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('codingCorrection.publicId', $correction->public_id)
                ->where('codingCorrection.sourceType', CodingSourceType::Procedure->value)
                ->where('codingCorrection.responseClosurePublicId', $successorClosure->public_id)
                ->where('document.requiresChangeReason', true)
                ->where('document.canAuthor', true)
            );
        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.procedures.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'procedure' => $successorProcedure,
            ]),
            ['request_key' => (string) Str::ulid()],
        )->assertSessionHasErrors('workflow');

        $replacementPayload = $this->recordReviewPayload(ClinicalSaveIntent::Submit);
        $replacementPayload['change_reason'] = 'Telaah RMIK diulang setelah koreksi sumber prosedur disetujui.';
        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.record-quality.reviews.store', $case['encounter']),
            $replacementPayload,
        )->assertRedirect();
        $replacementReview = RecordQualityReview::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame($correction->public_id, data_get($replacementReview->content, 'procedureDocumentationCorrection.publicId'));
        $this->actingAs($case['rmikSupervisor'])->post(
            route('record-quality-reviews.decisions.store', $replacementReview),
            $this->rmikDecisionPayload(RecordQualityReviewAction::ApproveSimulation),
        )->assertRedirect();

        $this->assertSame(ProcedureDocumentationCorrectionStatus::Resolved, $correction->refresh()->status);
        $this->assertSame($replacementReview->getKey(), $correction->replacement_record_quality_review_id);
        $this->assertSame(WorkTaskStatus::Ready, $case['encounter']->workTasks()
            ->where('assignment_id', $case['rmikAssignment']->getKey())
            ->where('task_type', WorkTaskType::Coding)
            ->sole()->status);
        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.procedures.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'procedure' => $successorProcedure,
            ]),
            ['request_key' => (string) Str::ulid()],
        )->assertRedirect();
        $this->assertDatabaseCount('coding_suggestion_runs', 2);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.procedure_documentation_correction_requested',
            'resource_type' => 'coding_suggestion_decision',
        ]);
        $this->assertDomainFailure(fn () => $correction->update(['reason' => 'forbidden']), 'procedure correction update');
        $correction->refresh();
        $this->assertDomainFailure(fn () => $correction->delete(), 'procedure correction deletion');
    }

    public function test_no_reliable_candidate_stays_honest_and_manual_selection_is_attributed(): void
    {
        $case = $this->prepareClinicallyClosedCase();
        $condition = ClinicalCondition::query()->sole();
        $release = $this->activateSyntheticIcd10Release(
            condition: $condition,
            code: 'K35.8',
            display: 'Other and unspecified acute appendicitis',
        );
        $this->approveRecordQualityForCoding($case);
        $requestKey = (string) Str::ulid();

        $this->actingAs($case['rmikCoder'])
            ->post(route('encounters.conditions.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'condition' => $condition,
            ]), ['request_key' => $requestKey])
            ->assertRedirect();

        $run = CodingSuggestionRun::query()->sole();
        $concept = TerminologyConcept::query()->sole();
        $this->assertSame(CodingSuggestionOutcome::NoReliableCandidate, $run->outcome);
        $this->assertDatabaseCount('coding_suggestion_candidates', 0);
        $this->assertDatabaseCount('coding_assignments', 0);
        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.coding.show', [
                'encounter' => $case['encounter'],
                'source' => $condition->public_id,
                'q' => 'K35.8',
                'system' => TerminologySystem::Icd10->value,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coding/workspace')
                ->where('sources.0.latestRun.outcome.code', CodingSuggestionOutcome::NoReliableCandidate->value)
                ->has('sources.0.latestRun.candidates', 0)
                ->where('manualSearch.results.0.concept.publicId', $concept->public_id)
                ->where('manualSearch.results.0.concept.code', 'K35.8')
            );

        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $run), [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::ManualAlternative->value,
                'candidate_public_id' => null,
                'manual_concept_public_id' => $concept->public_id,
                'reason' => null,
                'rationale' => 'Dipilih manual setelah meninjau sumber dan indeks terminologi dalam skenario.',
                'change_reason' => null,
            ])
            ->assertRedirect();

        $coding = CodingAssignment::query()->sole();
        $this->assertSame(CodingSelectionMethod::Manual, $coding->selection_method);
        $this->assertSame(CodingAssignmentStatus::Draft, $coding->status);
        $this->assertNull($coding->source_suggestion_run_id);
        $this->assertNull($coding->source_candidate_id);
        $this->assertSame($release->source_sha256, $coding->terminology_source_hash);
        $this->assertDatabaseCount('coding_suggestion_decisions', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.manual_alternative_selected',
            'resource_type' => 'coding_suggestion_decision',
        ]);

        $this->activateSyntheticIcd10Release(
            condition: $condition,
            code: 'R51.9',
            display: 'Headache, unspecified',
        );
        $this->actingAs($case['rmikCoder'])
            ->post(route('coding-suggestion-runs.decisions.store', $run), [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::Rejected->value,
                'candidate_public_id' => null,
                'manual_concept_public_id' => null,
                'reason' => 'Run lama tidak boleh dipakai setelah release diganti.',
                'rationale' => null,
                'change_reason' => null,
            ])
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('coding_suggestion_decisions', 1);
    }

    public function test_coder_correction_reopens_attributed_sources_and_blocks_stale_coding_until_rmik_reapproval(): void
    {
        $case = $this->prepareClinicallyClosedCase(withPerformedProcedure: true);
        $oldMedical = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->sole();
        $oldClosure = EncounterClosure::query()->sole();
        $condition = ClinicalCondition::query()->sole();
        $procedure = ClinicalProcedure::query()->sole();
        $this->activateSyntheticIcd10Release($condition);
        $this->activateSyntheticRelease(
            system: TerminologySystem::Icd9Cm,
            code: '38.99',
            display: $procedure->authored_text,
        );
        $oldQuality = $this->approveRecordQualityForCoding($case);

        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.procedures.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'procedure' => $procedure,
            ]),
            ['request_key' => (string) Str::ulid()],
        )->assertRedirect();
        $procedureRun = CodingSuggestionRun::query()
            ->where('source_type', CodingSourceType::Procedure)
            ->sole();
        $procedureCandidate = CodingSuggestionCandidate::query()
            ->where('coding_suggestion_run_id', $procedureRun->getKey())
            ->sole();
        $this->actingAs($case['rmikCoder'])->post(
            route('coding-suggestion-runs.decisions.store', $procedureRun),
            [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::AcceptedToDraft->value,
                'candidate_public_id' => $procedureCandidate->public_id,
                'manual_concept_public_id' => null,
                'reason' => null,
                'rationale' => 'Draf prosedur dibuat sebelum sumber closure berubah.',
                'change_reason' => null,
            ],
        )->assertRedirect();
        $staleProcedureCoding = CodingAssignment::query()
            ->where('source_type', CodingSourceType::Procedure)
            ->sole();

        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.conditions.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'condition' => $condition,
            ]),
            ['request_key' => (string) Str::ulid()],
        )->assertRedirect();

        $run = CodingSuggestionRun::query()
            ->where('source_type', CodingSourceType::Diagnosis)
            ->sole();
        $candidate = CodingSuggestionCandidate::query()->where('coding_suggestion_run_id', $run->getKey())->firstOrFail();
        $this->actingAs($case['rmikCoder'])->post(
            route('coding-suggestion-runs.decisions.store', $run),
            [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::AcceptedToDraft->value,
                'candidate_public_id' => $candidate->public_id,
                'manual_concept_public_id' => null,
                'reason' => null,
                'rationale' => 'Draf awal dibuat setelah tinjauan manusia.',
                'change_reason' => null,
            ],
        )->assertRedirect();
        $staleCoding = CodingAssignment::query()
            ->where('source_type', CodingSourceType::Diagnosis)
            ->sole();

        $this->actingAs($case['rmikCoder'])->post(
            route('coding-suggestion-runs.decisions.store', $run),
            [
                'request_key' => (string) Str::ulid(),
                'decision' => CodingDecisionType::CorrectionRequested->value,
                'candidate_public_id' => null,
                'manual_concept_public_id' => null,
                'reason' => 'Pernyataan diagnosis perlu menjelaskan sifat pusing sebelum kode dipilih.',
                'rationale' => null,
                'change_reason' => null,
            ],
        )->assertRedirect();

        $correction = CodingDocumentationCorrection::query()->sole();
        $this->assertSame(CodingDocumentationCorrectionStatus::Open, $correction->status);
        $this->assertSame(EncounterStatus::AmendmentPending, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::CodingSourceCorrection->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::Coding->value,
            'status' => WorkTaskStatus::Blocked->value,
        ]);
        $this->actingAs($case['medicalLearner'])
            ->get(route('encounters.medical-assessment.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('codingCorrection.publicId', $correction->public_id)
                ->where('document.amendmentMode', true)
                ->where('document.ordersLocked', true)
                ->where('document.canEdit', true)
            );
        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.coding.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documentationCorrection.publicId', $correction->public_id)
                ->where('selectedSourcePublicId', $condition->public_id)
                ->where('assignment.canCode', false)
                ->where('task.status.code', WorkTaskStatus::Blocked->value)
            );
        $this->actingAs($case['rmikCoder'])
            ->get(route('work'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.actionUrl', route('encounters.coding.show', [
                    'encounter' => $case['encounter'],
                    'source' => $condition->public_id,
                ]))
            );

        $invalidAmendment = $this->medicalPayload();
        $invalidAmendment['clinical_occurrence_at'] = $oldMedical->clinical_occurrence_at->toIso8601String();
        $invalidAmendment['change_reason'] = 'Klarifikasi sumber diagnosis untuk koding.';
        $invalidAmendment['diagnoses'][0]['authored_text'] = 'Vertigo perifer sebagai diagnosis kerja pada skenario simulasi.';
        $invalidAmendment['medication_requests'][0]['quantity_value'] = 8;
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.medical-assessment.versions.store', $case['encounter']), $invalidAmendment)
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('clinical_entry_versions', 2);

        $medicalAmendment = $this->medicalPayload();
        $medicalAmendment['clinical_occurrence_at'] = $oldMedical->clinical_occurrence_at->toIso8601String();
        $medicalAmendment['change_reason'] = 'Klarifikasi sifat pusing sesuai permintaan koder.';
        $medicalAmendment['assessment_summary'] = 'Temuan mendukung vertigo perifer sebagai diagnosis kerja dalam simulasi.';
        $medicalAmendment['diagnoses'][0]['authored_text'] = 'Vertigo perifer sebagai diagnosis kerja pada skenario simulasi.';
        $medicalDraft = $medicalAmendment;
        $medicalDraft['request_key'] = (string) Str::ulid();
        $medicalDraft['intent'] = ClinicalSaveIntent::SaveDraft->value;
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.medical-assessment.versions.store', $case['encounter']), $medicalDraft)
            ->assertRedirect();
        $this->assertSame(CodingDocumentationCorrectionStatus::Open, $correction->refresh()->status);

        $medicalAmendment['request_key'] = (string) Str::ulid();
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.medical-assessment.versions.store', $case['encounter']), $medicalAmendment)
            ->assertRedirect();

        $newMedical = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(3, $newMedical->version_number);
        $this->assertSame(CodingDocumentationCorrectionStatus::MedicalResponseSubmitted, $correction->refresh()->status);
        $this->actingAs($case['medicalSupervisor'])->post(
            route('clinical-versions.review-decisions.store', $newMedical),
            $this->clinicalDecisionPayload(),
        )->assertRedirect();

        $this->assertSame(CodingDocumentationCorrectionStatus::MedicalApproved, $correction->refresh()->status);
        $this->assertSame(CodingAssignmentStatus::ReviewRequired, $staleCoding->refresh()->status);
        $this->assertSame(EncounterStatus::AmendmentPending, $case['encounter']->refresh()->status);
        $this->assertSame(ClinicalEntryStatus::Amended, $oldMedical->refresh()->status);
        $this->actingAs($case['medicalLearner'])
            ->get(route('encounters.closure.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('codingCorrection.publicId', $correction->public_id)
                ->where('assignment.canAuthor', true)
                ->where('document.requiresChangeReason', true)
            );

        $closureAmendment = $this->closurePayload(ClinicalSaveIntent::Submit);
        $closureAmendment['change_reason'] = 'Closure diperbarui untuk menunjuk asesmen medis penerus.';
        $closureAmendment['outpatient_summary'] = 'Ringkasan penerus mencatat diagnosis kerja vertigo perifer dan tindak lanjut simulasi.';
        $closureAmendment['procedure_documentation_state'] = ProcedureDocumentationState::ProceduresRecorded->value;
        $closureAmendment['procedures'] = [[
            'authored_text' => $procedure->authored_text,
            'performed_start_at' => $procedure->performed_start_at->toIso8601String(),
            'performed_end_at' => $procedure->performed_end_at?->toIso8601String(),
            'performer_text' => $procedure->performer_text,
            'body_site_text' => $procedure->body_site_text,
            'outcome_text' => $procedure->outcome_text,
            'note' => $procedure->note,
            'reason_condition_public_id' => null,
            'based_on_service_request_public_id' => $procedure->basedOnServiceRequest?->public_id,
        ]];
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $closureAmendment)
            ->assertRedirect();
        $newClosure = EncounterClosure::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(CodingDocumentationCorrectionStatus::ClosureResponseSubmitted, $correction->refresh()->status);
        $this->actingAs($case['medicalSupervisor'])->post(
            route('encounter-closures.review-decisions.store', $newClosure),
            $this->closureDecisionPayload(EncounterClosureReviewAction::ApproveSimulation),
        )->assertRedirect();

        $this->assertSame(CodingDocumentationCorrectionStatus::ReadyForRmik, $correction->refresh()->status);
        $this->assertSame(CodingAssignmentStatus::ReviewRequired, $staleProcedureCoding->refresh()->status);
        $this->assertDatabaseCount('clinical_procedures', 2);
        $this->assertSame(EncounterStatus::RecordReview, $case['encounter']->refresh()->status);
        $this->actingAs($case['rmikCoder'])
            ->get(route('encounters.record-quality.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('codingCorrection.publicId', $correction->public_id)
                ->where('assignment.canAuthor', true)
                ->where('document.requiresChangeReason', true)
            );
        $newCondition = ClinicalCondition::query()->where('source_entry_version_id', $newMedical->getKey())->sole();
        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.conditions.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'condition' => $newCondition,
            ]),
            ['request_key' => (string) Str::ulid()],
        )->assertSessionHasErrors('workflow');

        $replacementReviewPayload = $this->recordReviewPayload(ClinicalSaveIntent::Submit);
        $replacementReviewPayload['change_reason'] = 'Telaah diulang setelah sumber medis dan closure penerus disetujui.';
        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.record-quality.reviews.store', $case['encounter']),
            $replacementReviewPayload,
        )->assertRedirect();
        $replacementReview = RecordQualityReview::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(2, $replacementReview->version_number);
        $this->actingAs($case['rmikSupervisor'])->post(
            route('record-quality-reviews.decisions.store', $replacementReview),
            $this->rmikDecisionPayload(RecordQualityReviewAction::ApproveSimulation),
        )->assertRedirect();

        $this->assertSame(CodingDocumentationCorrectionStatus::Resolved, $correction->refresh()->status);
        $this->assertSame($replacementReview->getKey(), $correction->replacement_record_quality_review_id);
        $this->assertSame($oldQuality->content_hash, $oldQuality->refresh()->content_hash);
        $this->assertSame($oldClosure->content_hash, $oldClosure->refresh()->content_hash);
        $this->assertSame(WorkTaskStatus::Ready, $case['encounter']->workTasks()
            ->where('assignment_id', $case['rmikAssignment']->getKey())
            ->where('task_type', WorkTaskType::Coding)
            ->sole()->status);
        $this->actingAs($case['rmikCoder'])->post(
            route('encounters.conditions.coding-suggestions.store', [
                'encounter' => $case['encounter'],
                'condition' => $newCondition,
            ]),
            ['request_key' => (string) Str::ulid()],
        )->assertRedirect();
        $this->assertDatabaseCount('coding_suggestion_runs', 3);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.documentation_correction_requested',
            'resource_type' => 'coding_suggestion_decision',
        ]);
    }

    /**
     * @return array{
     *   case: array<string, mixed>,
     *   oldClosure: EncounterClosure,
     *   oldHash: string,
     *   correction: RecordCorrectionRequest,
     *   correctedClosure: EncounterClosure
     * }
     */
    private function prepareApprovedCorrectionCase(): array
    {
        $case = $this->prepareClinicallyClosedCase();
        $this->saveBlockingReview($case);
        $finding = RecordQualityFinding::query()->sole();
        $this->actingAs($case['rmikCoder'])->post(
            route('record-quality-findings.corrections.store', $finding),
            ['request_key' => (string) Str::ulid(), 'reason' => $finding->requested_action],
        );
        $correction = RecordCorrectionRequest::query()->sole();
        $oldClosure = EncounterClosure::query()->orderByDesc('version_number')->firstOrFail();
        $oldHash = $oldClosure->content_hash;
        $amendment = $this->closurePayload(ClinicalSaveIntent::Submit);
        $amendment['change_reason'] = 'Ringkasan closure dikoreksi sesuai temuan RMIK yang terhubung.';
        $amendment['outpatient_summary'] = 'Ringkasan rawat jalan versi koreksi menjelaskan tindak lanjut secara lebih spesifik.';

        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $amendment)
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));
        $correctedClosure = EncounterClosure::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(RecordCorrectionStatus::ResponseSubmitted, $correction->refresh()->status);
        $this->assertSame(EncounterStatus::AmendmentPending, $case['encounter']->refresh()->status);

        $this->actingAs($case['medicalSupervisor'])->post(
            route('encounter-closures.review-decisions.store', $correctedClosure),
            $this->closureDecisionPayload(EncounterClosureReviewAction::ApproveSimulation),
        )->assertRedirect(route('encounters.closure.show', $case['encounter']));

        return compact('case', 'oldClosure', 'oldHash', 'correction', 'correctedClosure');
    }

    /** @param array<string, mixed> $case */
    private function approveRecordQualityForCoding(array $case): RecordQualityReview
    {
        $this->actingAs($case['rmikCoder'])
            ->post(
                route('encounters.record-quality.reviews.store', $case['encounter']),
                $this->recordReviewPayload(ClinicalSaveIntent::Submit),
            )
            ->assertRedirect();
        $review = RecordQualityReview::query()->sole();
        $this->actingAs($case['rmikSupervisor'])
            ->post(
                route('record-quality-reviews.decisions.store', $review),
                $this->rmikDecisionPayload(RecordQualityReviewAction::ApproveSimulation),
            )
            ->assertRedirect();

        $this->assertSame(RecordQualityReviewStatus::Approved, $review->refresh()->status);

        return $review;
    }

    private function activateSyntheticIcd10Release(
        ClinicalCondition $condition,
        string $code = 'R42',
        ?string $display = null,
    ): TerminologyRelease {
        return $this->activateSyntheticRelease(
            system: TerminologySystem::Icd10,
            code: $code,
            display: $display ?? $condition->authored_text,
        );
    }

    private function activateSyntheticRelease(
        TerminologySystem $system,
        string $code,
        string $display,
    ): TerminologyRelease {
        $facilitator = User::query()
            ->where('email', 'fasilitator.simulasi@example.invalid')
            ->firstOrFail();
        $actor = Assignment::query()->where('user_id', $facilitator->getKey())->sole();
        $sourceHash = hash('sha256', "synthetic-{$system->value}-workflow-fixture-v1\n{$code}\n{$display}");
        $release = TerminologyRelease::query()->create([
            'request_key' => (string) Str::ulid(),
            'classification_system' => $system,
            'logical_version' => $system->logicalVersion(),
            'status' => TerminologyReleaseStatus::Imported,
            'source_filename' => 'synthetic-'.strtolower($system->value).'-workflow-fixture.xlsx',
            'source_sha256' => $sourceHash,
            'sheet_name' => $system->expectedSheetName(),
            'source_provenance_status' => TerminologyProvenanceStatus::SyntheticFixture,
            'imported_by_user_id' => $actor->user_id,
            'imported_by_assignment_id' => $actor->getKey(),
            'row_count' => 1,
            'ignored_blank_rows' => 0,
            'validation_report' => [
                'schema' => 'terminology-import-validation.v1',
                'classificationSystem' => $system->value,
                'sourceHashVerified' => true,
                'syntheticFixture' => true,
            ],
            'imported_at' => now(),
        ]);
        $normalizer = app(TerminologyNormalizer::class);
        $normalizedDisplay = $normalizer->text($display);
        TerminologyConcept::query()->create([
            'terminology_release_id' => $release->getKey(),
            'code' => $code,
            'display' => $display,
            'normalized_code' => $normalizer->code($code),
            'normalized_display' => $normalizedDisplay,
            'search_tokens' => implode(' ', $normalizer->tokens($normalizedDisplay)),
            'active' => true,
        ]);
        $superseded = TerminologyRelease::query()
            ->where('classification_system', $system)
            ->where('status', TerminologyReleaseStatus::Active)
            ->first();
        $superseded?->persistSuperseded();
        $release->persistActivation($actor, $superseded);

        return $release->refresh();
    }

    /** @param array<string, mixed> $case */
    private function saveBlockingReview(array $case): void
    {
        $this->actingAs($case['rmikCoder'])
            ->post(
                route('encounters.record-quality.reviews.store', $case['encounter']),
                $this->recordReviewPayload(ClinicalSaveIntent::SaveDraft, [[
                    'code' => 'FOLLOW_UP_CLARITY',
                    'severity' => RecordQualityFindingSeverity::Blocking->value,
                    'message' => 'Ringkasan belum menjelaskan hubungan disposisi dan tindak lanjut secara cukup untuk latihan.',
                    'requested_action' => 'Perjelas ringkasan closure melalui versi penerus yang disetujui supervisor.',
                ]]),
            )
            ->assertRedirect(route('encounters.record-quality.show', $case['encounter']));
    }

    /**
     * @return array{
     *   encounter: Encounter,
     *   nurse: User,
     *   nursingSupervisor: User,
     *   medicalLearner: User,
     *   medicalSupervisor: User,
     *   pharmacyLearner: User,
     *   rmikCoder: User,
     *   rmikSupervisor: User,
     *   medicalAssignment: Assignment,
     *   rmikAssignment: Assignment,
     *   rmikSupervisorAssignment: Assignment
     * }
     */
    private function prepareClinicallyClosedCase(bool $withPerformedProcedure = false): array
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $nursingSupervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();
        $medicalLearner = User::query()->where('email', 'mahasiswa.kedokteran@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();
        $pharmacyLearner = User::query()->where('email', 'mahasiswa.farmasi@example.invalid')->firstOrFail();
        $pharmacySupervisor = User::query()->where('email', 'supervisor.farmasi@example.invalid')->firstOrFail();
        $rmikCoder = User::query()->where('email', 'koder.rmik@example.invalid')->firstOrFail();
        $rmikSupervisor = User::query()->where('email', 'supervisor.rmik@example.invalid')->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $encounter->appointment));
        $this->actingAs($nurse)->post(route('encounters.nursing-intake.versions.store', $encounter), $this->nursingPayload());
        $nursingVersion = ClinicalEntryVersion::query()->where('schema_version', 'nursing-intake.v1')->sole();
        $this->actingAs($nursingSupervisor)->post(
            route('clinical-versions.review-decisions.store', $nursingVersion),
            $this->clinicalDecisionPayload(),
        );
        $this->actingAs($medicalLearner)->post(route('encounters.medical-assessment.versions.store', $encounter), $this->medicalPayload());
        $medicalVersion = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->sole();
        $this->actingAs($medicalSupervisor)->post(
            route('clinical-versions.review-decisions.store', $medicalVersion),
            $this->clinicalDecisionPayload(),
        );
        $serviceRequest = ServiceRequest::query()->sole();
        $this->actingAs($facilitator)->post(route('service-requests.results.store', $serviceRequest), $this->resultPayload());
        $result = DiagnosticResult::query()->sole();
        $this->actingAs($medicalLearner)->post(route('diagnostic-results.acknowledgements.store', $result), $this->acknowledgementPayload());
        $medicationRequest = MedicationRequest::query()->sole();
        $this->actingAs($pharmacyLearner)->post(route('medication-requests.pharmacy-reviews.store', $medicationRequest), $this->pharmacyReviewPayload());
        $stock = MedicationStock::query()->where('lot_number', 'LOT-SIM-A-001')->sole();
        $this->actingAs($pharmacyLearner)->post(route('medication-requests.dispenses.store', $medicationRequest), $this->dispensePayload($stock));
        $preparation = MedicationDispensePreparation::query()->sole();
        $this->actingAs($pharmacySupervisor)->post(
            route('medication-requests.dispenses.store', $medicationRequest),
            $this->dispenseFinalCheckPayload($preparation),
        );
        $closurePayload = $this->closurePayload(ClinicalSaveIntent::Submit);

        if ($withPerformedProcedure) {
            $closurePayload['procedure_documentation_state'] = ProcedureDocumentationState::ProceduresRecorded->value;
            $closurePayload['procedures'] = [[
                'authored_text' => 'Pengambilan sampel darah vena untuk pemeriksaan sintetis.',
                'performed_start_at' => now()->subMinutes(15)->toIso8601String(),
                'performed_end_at' => now()->subMinutes(5)->toIso8601String(),
                'performer_text' => 'Petugas laboratorium simulasi',
                'body_site_text' => 'Vena lengan kanan',
                'outcome_text' => 'Sampel sintetis berhasil diperoleh',
                'note' => 'Tidak ada komplikasi pada skenario.',
                'reason_condition_public_id' => null,
                'based_on_service_request_public_id' => $serviceRequest->public_id,
            ]];
        }

        $this->actingAs($medicalLearner)->post(route('encounters.closure.versions.store', $encounter), $closurePayload);
        $closure = EncounterClosure::query()->sole();
        $this->actingAs($medicalSupervisor)->post(
            route('encounter-closures.review-decisions.store', $closure),
            $this->closureDecisionPayload(EncounterClosureReviewAction::ApproveSimulation),
        );

        return [
            'encounter' => $encounter->refresh(),
            'nurse' => $nurse,
            'nursingSupervisor' => $nursingSupervisor,
            'medicalLearner' => $medicalLearner,
            'medicalSupervisor' => $medicalSupervisor,
            'pharmacyLearner' => $pharmacyLearner,
            'rmikCoder' => $rmikCoder,
            'rmikSupervisor' => $rmikSupervisor,
            'medicalAssignment' => Assignment::query()->where('user_id', $medicalLearner->getKey())->sole(),
            'rmikAssignment' => Assignment::query()->where('user_id', $rmikCoder->getKey())->where('application_role', ApplicationRole::Coder)->sole(),
            'rmikSupervisorAssignment' => Assignment::query()->where('user_id', $rmikSupervisor->getKey())->sole(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $resolvedCorrectionIds
     * @return array<string, mixed>
     */
    private function recordReviewPayload(
        ClinicalSaveIntent $intent,
        array $findings = [],
        array $resolvedCorrectionIds = [],
    ): array {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => $intent->value,
            'findings' => $findings,
            'resolved_correction_public_ids' => $resolvedCorrectionIds,
            'change_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function rmikDecisionPayload(RecordQualityReviewAction $action): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'action' => $action->value,
            'comment' => 'Checklist, resolusi, versi, dan hash ditinjau oleh supervisor RMIK.',
            'findings' => $action === RecordQualityReviewAction::RequestChanges ? [[
                'code' => 'RMIK_REVIEW_QUALITY',
                'severity' => 'BLOCKING',
                'message' => 'Perjelas bukti resolusi pada versi penerus telaah.',
            ]] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function closureDecisionPayload(EncounterClosureReviewAction $action): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'action' => $action->value,
            'comment' => 'Versi closure dan hash ditinjau untuk simulasi.',
            'findings' => $action === EncounterClosureReviewAction::RequestChanges ? [[
                'code' => 'CLOSURE_REVISION',
                'severity' => 'BLOCKING',
                'message' => 'Perbaiki ringkasan closure.',
            ]] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function closurePayload(ClinicalSaveIntent $intent): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => $intent->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'leaving_condition' => 'Kondisi stabil untuk menyelesaikan encounter rawat jalan simulasi.',
            'disposition' => 'Pulang dari poliklinik simulasi.',
            'follow_up_plan' => 'Kontrol simulasi sesuai jadwal skenario.',
            'referral_plan' => null,
            'education_instructions' => 'Instruksi penggunaan obat sintetis dan tanda kembali dijelaskan.',
            'outpatient_summary' => 'Asesmen, hasil, farmasi, dan rencana tindak lanjut telah ditinjau.',
            'procedure_documentation_state' => ProcedureDocumentationState::NonePerformed->value,
            'procedures' => [],
            'change_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function nursingPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'history_source' => 'Pasien sintetis',
            'chief_complaint' => 'Pusing sejak dua hari sebelum kunjungan simulasi.',
            'onset_duration' => 'Dua hari',
            'consciousness' => 'Sadar penuh.',
            'allergy_state' => AllergyAssessmentState::NoKnownAllergyReported->value,
            'current_medication_state' => CurrentMedicationState::NoneReported->value,
            'vitals' => ['temperature' => 36.8, 'heart_rate' => 82, 'respiratory_rate' => 18, 'systolic_blood_pressure' => 118, 'diastolic_blood_pressure' => 76, 'oxygen_saturation' => 98],
            'safety_responses' => [['question_code' => 'SUPERVISOR_CONCERN', 'response' => 'NO', 'note' => null]],
            'safety_decision' => IntakeSafetyDecision::RoutineFlow->value,
            'note' => 'Catatan skenario.',
            'handoff_summary' => 'Diteruskan untuk asesmen medis.',
        ];
    }

    /** @return array<string, mixed> */
    private function medicalPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'history_source' => 'Pasien sintetis dan handoff',
            'present_illness' => 'Pusing episodik selama dua hari.',
            'past_medical_history' => 'Tidak ada riwayat tambahan.',
            'family_history' => 'Tidak ada riwayat relevan.',
            'social_history' => 'Fixture pembelajaran.',
            'general_examination' => 'Keadaan umum stabil.',
            'focused_examination' => 'Pemeriksaan terfokus sesuai skenario.',
            'assessment_summary' => 'Temuan disintesis oleh mahasiswa.',
            'diagnoses' => [['authored_text' => 'Sindrom pusing dalam evaluasi pada skenario simulasi.', 'certainty' => DiagnosisCertainty::Working->value, 'role' => DiagnosisRole::Primary->value, 'onset_at' => now()->subDays(2)->toDateString()]],
            'service_requests' => [['request_type' => 'LABORATORY', 'authored_service' => 'Pemeriksaan darah sintetis skenario', 'clinical_question' => 'Dokumentasikan hasil fixture.', 'priority' => 'ROUTINE', 'source_diagnosis_index' => 0]],
            'medication_requests' => [['authored_medication' => 'Obat Simulasi A', 'form' => 'Tablet', 'strength' => '500 mg', 'dose_value' => 1, 'dose_unit' => 'tablet', 'route' => 'Oral', 'frequency' => 'Dua kali sehari', 'duration' => 'Tiga hari', 'quantity_value' => 6, 'quantity_unit' => 'tablet', 'directions' => 'Gunakan sesuai instruksi skenario.', 'indication_text' => 'Sindrom pusing.', 'source_diagnosis_index' => 0]],
            'care_plan' => 'Rencana simulasi mencakup hasil dan farmasi.',
            'education' => 'Edukasi skenario.',
            'follow_up_plan' => 'Kontrol simulasi.',
            'intended_disposition' => 'Rawat jalan.',
        ];
    }

    /** @return array<string, mixed> */
    private function resultPayload(): array
    {
        return ['request_key' => (string) Str::ulid(), 'report_code' => 'LAB-SIM-001', 'report_display' => 'Panel darah sintetis', 'effective_at' => now()->toIso8601String(), 'narrative_conclusion' => 'Tidak ada temuan kritis dalam skenario.', 'components' => [['code' => 'SIM-HGB', 'display' => 'Hemoglobin sintetis', 'value' => '13.4', 'unit' => 'g/dL']]];
    }

    /** @return array<string, mixed> */
    private function acknowledgementPayload(): array
    {
        return ['request_key' => (string) Str::ulid(), 'outcome' => 'ACKNOWLEDGED', 'comment' => 'Hasil ditinjau.'];
    }

    /** @return array<string, mixed> */
    private function pharmacyReviewPayload(): array
    {
        $clear = PharmacyReviewItemOutcome::Clear->value;

        return ['request_key' => (string) Str::ulid(), 'overall_outcome' => PharmacyReviewOutcome::Accept->value, 'domain_results' => ['administrative' => [['criterion_code' => 'PATIENT_IDENTITY', 'outcome' => $clear, 'comment' => null], ['criterion_code' => 'PRESCRIBER_AND_DATE', 'outcome' => $clear, 'comment' => null], ['criterion_code' => 'CLINIC_CONTEXT', 'outcome' => $clear, 'comment' => null]], 'pharmaceutical' => [['criterion_code' => 'MEDICINE_FORM_STRENGTH', 'outcome' => $clear, 'comment' => null], ['criterion_code' => 'DOSE_DIRECTIONS_QUANTITY', 'outcome' => $clear, 'comment' => null], ['criterion_code' => 'PREPARATION_STABILITY', 'outcome' => $clear, 'comment' => null]], 'clinical' => [['criterion_code' => 'INDICATION_AND_DOSE', 'outcome' => $clear, 'comment' => null], ['criterion_code' => 'DUPLICATION', 'outcome' => $clear, 'comment' => null], ['criterion_code' => 'ALLERGY_ADVERSE_REACTION', 'outcome' => $clear, 'comment' => null], ['criterion_code' => 'CONTRAINDICATION_INTERACTION', 'outcome' => $clear, 'comment' => null]]], 'intervention' => null];
    }

    /** @return array<string, mixed> */
    private function dispensePayload(MedicationStock $stock): array
    {
        return ['request_key' => (string) Str::ulid(), 'action' => 'PREPARE', 'outcome' => MedicationDispenseOutcome::Complete->value, 'quantity' => 6, 'medication_stock_id' => $stock->getKey(), 'outcome_reason' => null, 'preparation_notes' => 'Obat sintetis disiapkan.', 'handoff_recipient' => 'Pasien sintetis', 'counseling_topics' => ['Cara penggunaan'], 'counseling_acknowledged' => true];
    }

    /** @return array<string, mixed> */
    private function dispenseFinalCheckPayload(MedicationDispensePreparation $preparation): array
    {
        return ['request_key' => (string) Str::ulid(), 'action' => 'FINAL_CHECK', 'preparation_public_id' => $preparation->public_id, 'review_action' => 'APPROVE_SIMULATION', 'comment' => 'Penyiapan disetujui terhadap versi dan hash yang tepat.', 'final_check_confirmed' => true, 'final_check_notes' => 'Identitas, obat, jumlah, etiket, lot, dan versi penyiapan diperiksa.'];
    }

    /** @return array<string, mixed> */
    private function clinicalDecisionPayload(): array
    {
        return ['request_key' => (string) Str::ulid(), 'action' => ClinicalReviewAction::ApproveSimulation->value, 'comment' => 'Disetujui untuk skenario.', 'findings' => []];
    }

    private function assertDomainFailure(callable $action, string $label): void
    {
        try {
            $action();
            $this->fail("The protected RMIK record accepted a forbidden direct mutation: {$label}.");
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
