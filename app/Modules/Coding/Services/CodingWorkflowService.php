<?php

namespace App\Modules\Coding\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\CodingReviewAction;
use App\Modules\Coding\Enums\CodingSelectionMethod;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\CodingReviewActionModel;
use App\Modules\Coding\Models\CodingSuggestionCandidate;
use App\Modules\Coding\Models\CodingSuggestionDecision;
use App\Modules\Coding\Models\CodingSuggestionRun;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Services\RecordCompletenessService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CodingWorkflowService
{
    public function __construct(
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly RecordCompletenessService $recordCompletenessService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function decide(
        CodingSuggestionRun $run,
        Assignment $coderAssignment,
        CodingDecisionType $decision,
        string $requestKey,
        ?CodingSuggestionCandidate $candidate = null,
        ?TerminologyConcept $manualConcept = null,
        ?string $reason = null,
        ?string $rationale = null,
        ?string $changeReason = null,
    ): CodingSuggestionDecision {
        return DB::transaction(function () use (
            $candidate,
            $changeReason,
            $coderAssignment,
            $decision,
            $manualConcept,
            $rationale,
            $reason,
            $requestKey,
            $run,
        ): CodingSuggestionDecision {
            $existing = CodingSuggestionDecision::query()->where('request_key', $requestKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->coding_suggestion_run_id !== $run->getKey()
                    || $existing->decided_by_assignment_id !== $coderAssignment->getKey()
                    || $existing->decision !== $decision) {
                    throw new DomainException('The coding-decision request key was already used in another context.');
                }

                return $existing->load(['run', 'candidate.concept', 'resultingAssignment.concept']);
            }

            $lockedRun = CodingSuggestionRun::query()
                ->with(['sourceCondition', 'sourceEntryVersion.clinicalEntry', 'sourceProcedure.closure', 'release'])
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($lockedRun->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($lockedRun->session_id)->lockForUpdate()->firstOrFail();
            $coder = Assignment::query()->active()->whereKey($coderAssignment->getKey())->lockForUpdate()->first();
            $this->assertCoderContext($encounter, $session, $coder, $lockedRun);
            $latestRunId = $this->latestRunForSourceQuery($lockedRun)->value('id');

            if ($latestRunId !== $lockedRun->getKey()) {
                throw new DomainException('A stale suggestion run cannot be used for a coding decision. Regenerate candidates.');
            }

            $lockedCandidate = $candidate === null
                ? null
                : CodingSuggestionCandidate::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            $lockedManualConcept = $manualConcept === null
                ? null
                : TerminologyConcept::query()->whereKey($manualConcept->getKey())->lockForUpdate()->firstOrFail();

            if ($decision === CodingDecisionType::AcceptedToDraft
                && (! $lockedCandidate || $lockedCandidate->coding_suggestion_run_id !== $lockedRun->getKey())) {
                throw new DomainException('Accepting a suggestion requires a candidate from the exact current run.');
            }

            if ($decision === CodingDecisionType::ManualAlternative
                && (! $lockedManualConcept
                    || $lockedManualConcept->terminology_release_id !== $lockedRun->terminology_release_id
                    || ! $lockedManualConcept->active)) {
                throw new DomainException('A manual alternative must be an active concept from the exact run release.');
            }

            if (in_array($decision, [CodingDecisionType::Rejected, CodingDecisionType::CorrectionRequested], true)
                && blank($reason)) {
                throw new DomainException('Rejecting candidates or requesting documentation correction requires a reason.');
            }

            $assignment = null;

            if (in_array($decision, [CodingDecisionType::AcceptedToDraft, CodingDecisionType::ManualAlternative], true)) {
                $concept = $decision === CodingDecisionType::AcceptedToDraft
                    ? $lockedCandidate->concept()->firstOrFail()
                    : $lockedManualConcept;
                $assignment = $this->createDraft(
                    run: $lockedRun,
                    coder: $coder,
                    concept: $concept,
                    candidate: $lockedCandidate,
                    requestKey: $requestKey,
                    rationale: $this->nullableText($rationale),
                    changeReason: $this->nullableText($changeReason),
                );
            }

            $decidedAt = CarbonImmutable::now();
            $record = CodingSuggestionDecision::query()->create([
                'request_key' => $requestKey,
                'coding_suggestion_run_id' => $lockedRun->getKey(),
                'coding_suggestion_candidate_id' => $lockedCandidate?->getKey(),
                'decision' => $decision,
                'decided_by_user_id' => $coder->user_id,
                'decided_by_assignment_id' => $coder->getKey(),
                'reason' => $this->nullableText($reason),
                'resulting_assignment_id' => $assignment?->getKey(),
                'decided_at' => $decidedAt,
            ]);

            $correction = $decision === CodingDecisionType::CorrectionRequested
                ? ($lockedRun->source_type === CodingSourceType::Diagnosis
                    ? $this->openDocumentationCorrection(
                        decision: $record,
                        run: $lockedRun,
                        coder: $coder,
                        encounter: $encounter,
                        reason: trim((string) $reason),
                    )
                    : $this->openProcedureDocumentationCorrection(
                        decision: $record,
                        run: $lockedRun,
                        coder: $coder,
                        encounter: $encounter,
                        reason: trim((string) $reason),
                    ))
                : null;

            $this->auditRecorder->record(
                action: match ($decision) {
                    CodingDecisionType::AcceptedToDraft => 'coding.candidate_accepted_to_draft',
                    CodingDecisionType::ManualAlternative => 'coding.manual_alternative_selected',
                    CodingDecisionType::Rejected => 'coding.candidates_rejected',
                    CodingDecisionType::CorrectionRequested => $lockedRun->source_type === CodingSourceType::Procedure
                        ? 'coding.procedure_documentation_correction_requested'
                        : 'coding.documentation_correction_requested',
                },
                resourceType: 'coding_suggestion_decision',
                resourceId: $record->public_id,
                actor: $coder->user,
                assignment: $coder,
                session: $session,
                encounter: $encounter,
                reason: $record->reason,
                metadata: [
                    'suggestion_run_public_id' => $lockedRun->public_id,
                    'candidate_public_id' => $lockedCandidate?->public_id,
                    'resulting_assignment_public_id' => $assignment?->public_id,
                    ...$this->runSourceAuditMetadata($lockedRun),
                    'terminology_source_hash' => $lockedRun->release->source_sha256,
                    'automatic_finalization' => false,
                    'documentation_correction_public_id' => $correction?->public_id,
                    'documentation_correction_source_type' => $decision === CodingDecisionType::CorrectionRequested
                        ? $lockedRun->source_type->value
                        : null,
                ],
            );

            return $record->load(['run', 'candidate.concept', 'resultingAssignment.concept']);
        });
    }

    public function submit(CodingAssignment $codingAssignment, Assignment $coderAssignment): CodingAssignment
    {
        return DB::transaction(function () use ($coderAssignment, $codingAssignment): CodingAssignment {
            $coding = CodingAssignment::query()
                ->with(['sourceCondition', 'sourceEntryVersion.clinicalEntry', 'sourceProcedure.closure', 'coderAssignment', 'concept', 'release'])
                ->whereKey($codingAssignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($coding->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($coding->session_id)->lockForUpdate()->firstOrFail();
            $coder = Assignment::query()->active()->whereKey($coderAssignment->getKey())->lockForUpdate()->first();
            $this->assertAssignmentAuthor($coding, $coder, $encounter);

            if ($coding->status === CodingAssignmentStatus::Submitted) {
                return $coding;
            }

            if ($coding->status !== CodingAssignmentStatus::Draft) {
                throw new DomainException('Only a current draft code assignment can be submitted.');
            }

            $this->assertSourceCurrent($coding, $encounter);

            if ($coder->supervisor_assignment_id === null) {
                throw new DomainException('A linked RMIK supervisor is required before coding submission.');
            }

            $supervisor = Assignment::query()->active()->whereKey($coder->supervisor_assignment_id)->lockForUpdate()->first();

            if (! $supervisor
                || $supervisor->session_id !== $coding->session_id
                || $supervisor->patient_id !== $coding->patient_id
                || $supervisor->encounter_id !== $coding->encounter_id
                || ! $supervisor->hasCapability(Capability::SupervisionReview)) {
                throw new DomainException('The linked RMIK supervisor is not active in this exact case.');
            }

            $coding->persistStatus(CodingAssignmentStatus::Submitted, CarbonImmutable::now());
            $this->updateCoderTask($coding, $coder, WorkTaskStatus::Submitted);
            WorkTask::query()->create([
                'session_id' => $coding->session_id,
                'assignment_id' => $supervisor->getKey(),
                'encounter_id' => $coding->encounter_id,
                'task_type' => WorkTaskType::CodingReview,
                'title' => 'Tinjau Kode '.$coding->concept->code,
                'description' => 'Tinjau sumber '.$coding->source_type->label().', release terminologi, metode pemilihan, dan hash kode yang diajukan.',
                'status' => WorkTaskStatus::Ready,
                'priority' => 1,
                'source_program' => Program::Rmik,
                'context' => [
                    'caseLabel' => $encounter->encounter_number,
                    'synthetic' => true,
                    'codingAssignmentPublicId' => $coding->public_id,
                    'sourceType' => $coding->source_type->value,
                    'sourcePublicId' => $coding->sourcePublicId(),
                    'contentHash' => $coding->content_hash,
                ],
                'available_at' => now(),
                'completed_at' => null,
            ]);

            $this->auditRecorder->record(
                action: 'coding.assignment_submitted',
                resourceType: 'coding_assignment',
                resourceId: $coding->public_id,
                actor: $coder->user,
                assignment: $coder,
                session: $session,
                encounter: $encounter,
                metadata: $this->assignmentAuditMetadata($coding),
            );

            return $coding->refresh()->load(['concept', 'release', 'sourceCondition', 'sourceProcedure.closure', 'reviewActions.reviewer']);
        });
    }

    /** @param list<array<string, mixed>> $findings */
    public function review(
        CodingAssignment $codingAssignment,
        Assignment $supervisorAssignment,
        CodingReviewAction $action,
        string $requestKey,
        ?string $comment,
        array $findings = [],
    ): CodingAssignment {
        return DB::transaction(function () use (
            $action,
            $codingAssignment,
            $comment,
            $findings,
            $requestKey,
            $supervisorAssignment,
        ): CodingAssignment {
            $existing = CodingReviewActionModel::query()->where('request_key', $requestKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->coding_assignment_id !== $codingAssignment->getKey()
                    || $existing->reviewer_assignment_id !== $supervisorAssignment->getKey()
                    || $existing->action !== $action) {
                    throw new DomainException('The coding-review request key was already used in another context.');
                }

                return CodingAssignment::query()->with(['concept', 'release', 'sourceCondition', 'sourceProcedure.closure', 'reviewActions.reviewer'])->findOrFail($codingAssignment->getKey());
            }

            $coding = CodingAssignment::query()
                ->with(['sourceCondition', 'sourceEntryVersion.clinicalEntry', 'sourceProcedure.closure', 'coderAssignment', 'concept', 'release'])
                ->whereKey($codingAssignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($coding->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($coding->session_id)->lockForUpdate()->firstOrFail();
            $supervisor = Assignment::query()->active()->whereKey($supervisorAssignment->getKey())->lockForUpdate()->first();

            if (! $supervisor
                || $coding->coderAssignment->supervisor_assignment_id !== $supervisor->getKey()
                || $supervisor->session_id !== $coding->session_id
                || $supervisor->patient_id !== $coding->patient_id
                || $supervisor->encounter_id !== $coding->encounter_id
                || ! $supervisor->hasCapability(Capability::SupervisionReview)) {
                throw new DomainException('Only the linked RMIK supervisor can review this code assignment.');
            }

            if ($coding->status !== CodingAssignmentStatus::Submitted) {
                throw new DomainException('Only a submitted code assignment can be reviewed.');
            }

            if ($action === CodingReviewAction::RequestChanges && $findings === []) {
                throw new DomainException('A request for coding changes requires at least one finding.');
            }

            $this->assertSourceCurrent($coding, $encounter);
            $reviewedAt = CarbonImmutable::now();
            CodingReviewActionModel::query()->create([
                'request_key' => $requestKey,
                'coding_assignment_id' => $coding->getKey(),
                'reviewer_user_id' => $supervisor->user_id,
                'reviewer_assignment_id' => $supervisor->getKey(),
                'action' => $action,
                'findings' => $findings === [] ? null : $findings,
                'comment' => $this->nullableText($comment),
                'reviewed_content_hash' => $coding->content_hash,
                'reviewed_at' => $reviewedAt,
            ]);
            $target = $action === CodingReviewAction::ApproveSimulation
                ? CodingAssignmentStatus::Approved
                : CodingAssignmentStatus::ChangesRequested;
            $coding->persistStatus($target, $reviewedAt);
            $allSourcesApproved = $target === CodingAssignmentStatus::Approved
                && $this->allCurrentSourcesApproved($encounter);
            $this->updateCoderTask(
                $coding,
                $coding->coderAssignment,
                $allSourcesApproved
                    ? WorkTaskStatus::Complete
                    : ($target === CodingAssignmentStatus::Approved
                        ? WorkTaskStatus::InProgress
                        : WorkTaskStatus::ChangesRequested),
            );
            $this->completeSupervisorTask($coding, $supervisor);

            if ($allSourcesApproved) {
                $encounter = $this->encounterTransitionService->transition(
                    $encounter,
                    EncounterStatus::Finalized,
                    $supervisor,
                    'all_current_diagnoses_and_performed_procedures_human_coded_and_supervisor_approved',
                );
                $this->releaseDebriefTasks($encounter);
            }

            $this->auditRecorder->record(
                action: $target === CodingAssignmentStatus::Approved
                    ? 'coding.assignment_approved_for_simulation'
                    : 'coding.assignment_changes_requested',
                resourceType: 'coding_assignment',
                resourceId: $coding->public_id,
                actor: $supervisor->user,
                assignment: $supervisor,
                session: $session,
                encounter: $encounter,
                metadata: [
                    ...$this->assignmentAuditMetadata($coding),
                    'finding_count' => count($findings),
                    'encounter_finalized' => $encounter->status === EncounterStatus::Finalized,
                ],
            );

            return $coding->refresh()->load(['concept', 'release', 'sourceCondition', 'sourceProcedure.closure', 'reviewActions.reviewer']);
        });
    }

    private function createDraft(
        CodingSuggestionRun $run,
        Assignment $coder,
        TerminologyConcept $concept,
        ?CodingSuggestionCandidate $candidate,
        string $requestKey,
        ?string $rationale,
        ?string $changeReason,
    ): CodingAssignment {
        $latest = $this->assignmentSourceQuery($run)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($latest && $latest->status !== CodingAssignmentStatus::ChangesRequested) {
            throw new DomainException('This clinical source already has a current code assignment.');
        }

        if ($latest && $changeReason === null) {
            throw new DomainException('A successor code assignment requires an attributed change reason.');
        }

        $selectionMethod = $candidate
            ? CodingSelectionMethod::Suggested
            : CodingSelectionMethod::Manual;
        $coding = new CodingAssignment([
            'request_key' => $requestKey,
            'session_id' => $run->session_id,
            'patient_id' => $run->patient_id,
            'encounter_id' => $run->encounter_id,
            'source_type' => $run->source_type,
            'source_condition_id' => $run->source_condition_id,
            'source_entry_version_id' => $run->source_entry_version_id,
            'source_procedure_id' => $run->source_procedure_id,
            'terminology_release_id' => $run->terminology_release_id,
            'terminology_concept_id' => $concept->getKey(),
            'coder_user_id' => $coder->user_id,
            'coder_assignment_id' => $coder->getKey(),
            'status' => CodingAssignmentStatus::Draft,
            'selection_method' => $selectionMethod,
            'source_suggestion_run_id' => $candidate ? $run->getKey() : null,
            'source_candidate_id' => $candidate?->getKey(),
            'source_clinical_content_hash' => $run->sourceContentHash(),
            'source_statement_hash' => hash('sha256', $run->sourceAuthoredText()),
            'terminology_source_hash' => $run->release->source_sha256,
            'rationale' => $rationale,
            'change_reason' => $changeReason,
            'supersedes_assignment_id' => $latest?->getKey(),
            'recorded_at' => CarbonImmutable::now(),
            'submitted_at' => null,
            'reviewed_at' => null,
        ]);
        $coding->content_hash = hash('sha256', CanonicalJson::encode($coding->integrityPayload()));
        $coding->save();
        $this->updateCoderTask($coding, $coder, WorkTaskStatus::InProgress);

        return $coding->load(['concept', 'release', 'sourceCondition', 'sourceProcedure.closure']);
    }

    private function assertCoderContext(
        Encounter $encounter,
        SimulationSession $session,
        ?Assignment $coder,
        CodingSuggestionRun $run,
    ): void {
        $latestQuality = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();

        if (! $coder
            || $encounter->status !== EncounterStatus::RecordReview
            || $session->getKey() !== $encounter->session_id
            || $coder->session_id !== $encounter->session_id
            || $coder->patient_id !== $encounter->patient_id
            || $coder->encounter_id !== $encounter->getKey()
            || ! $coder->hasCapability(Capability::CodingWrite)
            || $coder->getKey() !== $run->requested_by_assignment_id
            || $run->release->status !== TerminologyReleaseStatus::Active
            || ! $this->recordCompletenessService->approvedReviewIsCurrent($latestQuality, $encounter)) {
            throw new DomainException('Coding decisions require the approved RMIK record and exact authorized coder.');
        }

        $this->assertSourceCurrentFromRun($run);
    }

    private function assertSourceCurrentFromRun(CodingSuggestionRun $run): void
    {
        if ($run->source_type === CodingSourceType::Diagnosis) {
            $latestVersion = $run->sourceEntryVersion?->clinicalEntry
                ->versions()
                ->orderByDesc('version_number')
                ->first();

            if (! $latestVersion
                || $latestVersion->getKey() !== $run->source_entry_version_id
                || $latestVersion->status !== ClinicalEntryStatus::Approved) {
                throw new DomainException('The source diagnosis version changed. Regenerate coding suggestions.');
            }

            return;
        }

        $procedure = $run->sourceProcedure;
        $latestClosure = EncounterClosure::query()
            ->where('encounter_id', $run->encounter_id)
            ->orderByDesc('version_number')
            ->first();

        if (! $procedure
            || ! $latestClosure
            || $procedure->encounter_closure_id !== $latestClosure->getKey()
            || $procedure->getRawOriginal('status') !== ClinicalProcedureStatus::Completed->value
            || $latestClosure->status !== EncounterClosureStatus::Approved) {
            throw new DomainException('The performed-procedure source changed. Regenerate coding suggestions.');
        }
    }

    private function assertAssignmentAuthor(CodingAssignment $coding, ?Assignment $coder, Encounter $encounter): void
    {
        if (! $coder
            || $coder->getKey() !== $coding->coder_assignment_id
            || $coder->session_id !== $coding->session_id
            || $coder->patient_id !== $coding->patient_id
            || $coder->encounter_id !== $coding->encounter_id
            || ! $coder->hasCapability(Capability::CodingWrite)
            || $encounter->status !== EncounterStatus::RecordReview) {
            throw new DomainException('Only the exact authorized coder can submit this code assignment.');
        }
    }

    private function assertSourceCurrent(CodingAssignment $coding, Encounter $encounter): void
    {
        $latestQuality = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();

        if (! $this->recordCompletenessService->approvedReviewIsCurrent($latestQuality, $encounter)) {
            throw new DomainException('The approved RMIK review changed; this assignment must be regenerated.');
        }

        if ($coding->source_type === CodingSourceType::Diagnosis) {
            $latestVersion = $coding->sourceEntryVersion?->clinicalEntry
                ->versions()
                ->orderByDesc('version_number')
                ->first();

            if (! $latestVersion
                || $latestVersion->getKey() !== $coding->source_entry_version_id
                || $latestVersion->status !== ClinicalEntryStatus::Approved
                || ! hash_equals($latestVersion->content_hash, $coding->source_clinical_content_hash)
                || ! hash_equals(hash('sha256', $coding->sourceAuthoredText()), $coding->source_statement_hash)) {
                throw new DomainException('The diagnosis coding source changed; this assignment must be regenerated.');
            }

            return;
        }

        $procedure = $coding->sourceProcedure;
        $latestClosure = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();

        if (! $procedure
            || ! $latestClosure
            || $procedure->encounter_closure_id !== $latestClosure->getKey()
            || $procedure->getRawOriginal('status') !== ClinicalProcedureStatus::Completed->value
            || $latestClosure->status !== EncounterClosureStatus::Approved
            || ! hash_equals($procedure->content_hash, $coding->source_clinical_content_hash)
            || ! hash_equals(hash('sha256', $procedure->authored_text), $coding->source_statement_hash)) {
            throw new DomainException('The procedure coding source changed; this assignment must be regenerated.');
        }
    }

    private function openDocumentationCorrection(
        CodingSuggestionDecision $decision,
        CodingSuggestionRun $run,
        Assignment $coder,
        Encounter $encounter,
        string $reason,
    ): CodingDocumentationCorrection {
        $sourceCondition = $run->sourceCondition;
        $sourceVersion = $run->sourceEntryVersion;

        if ($run->source_type !== CodingSourceType::Diagnosis
            || ! $sourceCondition
            || ! $sourceVersion
            || $run->source_condition_id === null
            || $run->source_entry_version_id === null) {
            throw new DomainException('Only an exact diagnosis source can enter the diagnosis-documentation correction workflow.');
        }

        $activeCorrection = CodingDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('status', '!=', CodingDocumentationCorrectionStatus::Resolved->value)
            ->lockForUpdate()
            ->first();

        $activeProcedureCorrection = ProcedureDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('status', '!=', ProcedureDocumentationCorrectionStatus::Resolved->value)
            ->lockForUpdate()
            ->first();

        if ($activeCorrection || $activeProcedureCorrection) {
            throw new DomainException('This encounter already has an active coding documentation correction.');
        }

        $latestQuality = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->lockForUpdate()
            ->first();
        $responsible = Assignment::query()
            ->active()
            ->whereKey($sourceVersion->author_assignment_id)
            ->lockForUpdate()
            ->first();

        if (! $latestQuality
            || ! $this->recordCompletenessService->approvedReviewIsCurrent($latestQuality, $encounter)
            || ! $responsible
            || ! $responsible->hasCapability(Capability::MedicalAssessmentWrite)) {
            throw new DomainException('A correction requires the current approved RMIK review and exact active medical author.');
        }

        $correction = CodingDocumentationCorrection::query()->create([
            'coding_suggestion_decision_id' => $decision->getKey(),
            'session_id' => $encounter->session_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->getKey(),
            'source_condition_id' => $run->source_condition_id,
            'source_entry_version_id' => $run->source_entry_version_id,
            'superseded_record_quality_review_id' => $latestQuality->getKey(),
            'record_quality_reviewer_assignment_id' => $latestQuality->reviewer_assignment_id,
            'requested_by_user_id' => $coder->user_id,
            'requested_by_assignment_id' => $coder->getKey(),
            'responsible_user_id' => $responsible->user_id,
            'responsible_assignment_id' => $responsible->getKey(),
            'status' => CodingDocumentationCorrectionStatus::Open,
            'reason' => $reason,
            'requested_source_hash' => $sourceVersion->content_hash,
            'requested_statement_hash' => hash('sha256', $sourceCondition->authored_text),
            'requested_at' => CarbonImmutable::now(),
        ]);

        $encounter = $this->encounterTransitionService->transition(
            $encounter,
            EncounterStatus::AmendmentPending,
            $coder,
            'coding_source_documentation_correction_requested',
        );

        WorkTask::query()->updateOrCreate(
            [
                'assignment_id' => $responsible->getKey(),
                'encounter_id' => $encounter->getKey(),
                'task_type' => WorkTaskType::CodingSourceCorrection,
            ],
            [
                'session_id' => $encounter->session_id,
                'title' => 'Amendemen Sumber Diagnosis untuk Koding',
                'description' => 'Koder meminta klarifikasi dokumentasi. Buat versi medis penerus; koder tidak dapat mengubah diagnosis klinis.',
                'status' => WorkTaskStatus::Ready,
                'priority' => 1,
                'source_program' => Program::Rmik,
                'context' => [
                    'caseLabel' => $encounter->encounter_number,
                    'synthetic' => true,
                    'codingDocumentationCorrectionPublicId' => $correction->public_id,
                    'sourceConditionPublicId' => $sourceCondition->public_id,
                    'sourceMedicalVersionPublicId' => $sourceVersion->public_id,
                    'sourceMedicalContentHash' => $sourceVersion->content_hash,
                    'reason' => $reason,
                ],
                'available_at' => now(),
                'completed_at' => null,
            ],
        );

        $codingTask = WorkTask::query()
            ->where('assignment_id', $coder->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::Coding)
            ->lockForUpdate()
            ->first();

        if (! $codingTask) {
            throw new DomainException('The active coder task is missing for this correction request.');
        }

        $codingTask->fill([
            'description' => 'Koding diblokir sampai amendemen medis, penutupan, dan telaah ulang RMIK selesai.',
            'status' => WorkTaskStatus::Blocked,
            'context' => array_merge($codingTask->context ?? [], [
                'codingDocumentationCorrectionPublicId' => $correction->public_id,
                'sourceConditionPublicId' => $sourceCondition->public_id,
                'requestedSourceHash' => $correction->requested_source_hash,
            ]),
            'available_at' => now(),
            'completed_at' => null,
        ])->save();

        return $correction;
    }

    private function openProcedureDocumentationCorrection(
        CodingSuggestionDecision $decision,
        CodingSuggestionRun $run,
        Assignment $coder,
        Encounter $encounter,
        string $reason,
    ): ProcedureDocumentationCorrection {
        $procedure = $run->sourceProcedure;
        $sourceClosure = $procedure?->closure;

        if ($run->source_type !== CodingSourceType::Procedure
            || ! $procedure
            || ! $sourceClosure
            || $run->source_procedure_id === null
            || $procedure->getRawOriginal('status') !== ClinicalProcedureStatus::Completed->value) {
            throw new DomainException('Only an exact completed performed-procedure source can enter the procedure correction workflow.');
        }

        $latestClosure = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->lockForUpdate()
            ->first();
        $activeDiagnosisCorrection = CodingDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('status', '!=', CodingDocumentationCorrectionStatus::Resolved->value)
            ->lockForUpdate()
            ->first();
        $activeProcedureCorrection = ProcedureDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('status', '!=', ProcedureDocumentationCorrectionStatus::Resolved->value)
            ->lockForUpdate()
            ->first();

        if ($activeDiagnosisCorrection || $activeProcedureCorrection) {
            throw new DomainException('This encounter already has an active coding documentation correction.');
        }

        $latestQuality = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->lockForUpdate()
            ->first();
        $responsible = Assignment::query()
            ->active()
            ->whereKey($procedure->recorder_assignment_id)
            ->lockForUpdate()
            ->first();

        if (! $latestClosure
            || $latestClosure->getKey() !== $sourceClosure->getKey()
            || $latestClosure->status !== EncounterClosureStatus::Approved
            || ! $latestQuality
            || ! $this->recordCompletenessService->approvedReviewIsCurrent($latestQuality, $encounter)
            || ! $responsible
            || $responsible->getKey() !== $sourceClosure->author_assignment_id
            || ! $responsible->hasCapability(Capability::MedicalAssessmentWrite)) {
            throw new DomainException('A procedure correction requires the current approved closure, RMIK review, and exact active closure author.');
        }

        $correction = ProcedureDocumentationCorrection::query()->create([
            'coding_suggestion_decision_id' => $decision->getKey(),
            'session_id' => $encounter->session_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->getKey(),
            'source_procedure_id' => $procedure->getKey(),
            'source_closure_id' => $sourceClosure->getKey(),
            'superseded_record_quality_review_id' => $latestQuality->getKey(),
            'record_quality_reviewer_assignment_id' => $latestQuality->reviewer_assignment_id,
            'requested_by_user_id' => $coder->user_id,
            'requested_by_assignment_id' => $coder->getKey(),
            'responsible_user_id' => $responsible->user_id,
            'responsible_assignment_id' => $responsible->getKey(),
            'status' => ProcedureDocumentationCorrectionStatus::Open,
            'reason' => $reason,
            'requested_source_hash' => $procedure->content_hash,
            'requested_closure_hash' => $sourceClosure->content_hash,
            'requested_statement_hash' => hash('sha256', $procedure->authored_text),
            'requested_at' => CarbonImmutable::now(),
        ]);

        $encounter = $this->encounterTransitionService->transition(
            $encounter,
            EncounterStatus::AmendmentPending,
            $coder,
            'procedure_source_documentation_correction_requested',
        );

        WorkTask::query()->updateOrCreate(
            [
                'assignment_id' => $responsible->getKey(),
                'encounter_id' => $encounter->getKey(),
                'task_type' => WorkTaskType::ProcedureSourceCorrection,
            ],
            [
                'session_id' => $encounter->session_id,
                'title' => 'Amendemen Sumber Prosedur untuk Koding',
                'description' => 'Koder meminta koreksi catatan prosedur yang telah dilakukan. Buat penutupan penerus; koder tidak dapat mengubah sumber klinis.',
                'status' => WorkTaskStatus::Ready,
                'priority' => 1,
                'source_program' => Program::Rmik,
                'context' => [
                    'caseLabel' => $encounter->encounter_number,
                    'synthetic' => true,
                    'procedureDocumentationCorrectionPublicId' => $correction->public_id,
                    'sourceProcedurePublicId' => $procedure->public_id,
                    'sourceClosurePublicId' => $sourceClosure->public_id,
                    'sourceProcedureContentHash' => $procedure->content_hash,
                    'sourceClosureContentHash' => $sourceClosure->content_hash,
                    'reason' => $reason,
                ],
                'available_at' => now(),
                'completed_at' => null,
            ],
        );

        $codingTask = WorkTask::query()
            ->where('assignment_id', $coder->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::Coding)
            ->lockForUpdate()
            ->first();

        if (! $codingTask) {
            throw new DomainException('The active coder task is missing for this procedure correction request.');
        }

        $codingTask->fill([
            'description' => 'Koding diblokir sampai amendemen prosedur, supervisi penutupan, dan telaah ulang RMIK selesai.',
            'status' => WorkTaskStatus::Blocked,
            'context' => array_merge($codingTask->context ?? [], [
                'procedureDocumentationCorrectionPublicId' => $correction->public_id,
                'sourceProcedurePublicId' => $procedure->public_id,
                'requestedSourceHash' => $correction->requested_source_hash,
                'requestedClosureHash' => $correction->requested_closure_hash,
            ]),
            'available_at' => now(),
            'completed_at' => null,
        ])->save();

        return $correction;
    }

    private function allCurrentSourcesApproved(Encounter $encounter): bool
    {
        $entry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('document_type', ClinicalDocumentType::MedicalAssessment)
            ->first();
        $version = $entry?->versions()->orderByDesc('version_number')->first();

        if (! $version || $version->status !== ClinicalEntryStatus::Approved) {
            return false;
        }

        $conditions = ClinicalCondition::query()
            ->where('source_entry_version_id', $version->getKey())
            ->get();

        if ($conditions->isEmpty()) {
            return false;
        }

        $diagnosesApproved = $conditions->every(function (ClinicalCondition $condition) use ($version): bool {
            $latest = CodingAssignment::query()
                ->where('source_type', CodingSourceType::Diagnosis)
                ->where('source_condition_id', $condition->getKey())
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();

            return $latest?->status === CodingAssignmentStatus::Approved
                && hash_equals($version->content_hash, $latest->source_clinical_content_hash);
        });

        if (! $diagnosesApproved) {
            return false;
        }

        $closure = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();

        if (! $closure || $closure->status !== EncounterClosureStatus::Approved) {
            return false;
        }

        $documentationState = ProcedureDocumentationState::tryFrom(
            (string) data_get($closure->content, 'procedureDocumentation.state'),
        );

        if ($documentationState === ProcedureDocumentationState::NonePerformed) {
            return true;
        }

        if ($documentationState !== ProcedureDocumentationState::ProceduresRecorded) {
            return false;
        }

        $procedures = ClinicalProcedure::query()
            ->where('encounter_closure_id', $closure->getKey())
            ->where('status', ClinicalProcedureStatus::Completed)
            ->get();

        if ($procedures->isEmpty()) {
            return false;
        }

        return $procedures->every(function (ClinicalProcedure $procedure): bool {
            $latest = CodingAssignment::query()
                ->where('source_type', CodingSourceType::Procedure)
                ->where('source_procedure_id', $procedure->getKey())
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();

            return $latest?->status === CodingAssignmentStatus::Approved
                && hash_equals($procedure->content_hash, $latest->source_clinical_content_hash);
        });
    }

    private function updateCoderTask(CodingAssignment $coding, Assignment $coder, WorkTaskStatus $status): void
    {
        $encounterNumber = Encounter::query()
            ->whereKey($coding->encounter_id)
            ->value('encounter_number');
        $task = WorkTask::query()->firstOrNew([
            'assignment_id' => $coder->getKey(),
            'encounter_id' => $coding->encounter_id,
            'task_type' => WorkTaskType::Coding,
        ]);
        $task->fill([
            'session_id' => $coding->session_id,
            'title' => 'Koding diagnosis dan prosedur rawat jalan',
            'description' => 'Tinjau setiap sumber klinis dan kandidat ICD-10/ICD-9-CM; kode tidak pernah difinalkan otomatis.',
            'status' => $status,
            'priority' => 1,
            'source_program' => Program::Rmik,
            'context' => [
                'caseLabel' => $encounterNumber,
                'synthetic' => true,
                'codingAssignmentPublicId' => $coding->public_id,
                'sourceType' => $coding->source_type->value,
                'sourcePublicId' => $coding->sourcePublicId(),
                'contentHash' => $coding->content_hash,
            ],
            'available_at' => now(),
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
        ])->save();
    }

    private function completeSupervisorTask(CodingAssignment $coding, Assignment $supervisor): void
    {
        $task = WorkTask::query()
            ->where('assignment_id', $supervisor->getKey())
            ->where('encounter_id', $coding->encounter_id)
            ->where('task_type', WorkTaskType::CodingReview)
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->lockForUpdate()
            ->get()
            ->first(fn (WorkTask $candidate): bool => data_get($candidate->context, 'codingAssignmentPublicId') === $coding->public_id);

        if (! $task) {
            throw new DomainException('The exact coding supervisor task is missing.');
        }

        $task->fill(['status' => WorkTaskStatus::Complete, 'completed_at' => now()])->save();
    }

    /** @return array<string, mixed> */
    private function assignmentAuditMetadata(CodingAssignment $coding): array
    {
        return [
            'source_type' => $coding->source_type->value,
            'source_public_id' => $coding->sourcePublicId(),
            'source_clinical_content_hash' => $coding->source_clinical_content_hash,
            'classification_system' => $coding->release->classification_system->value,
            'logical_version' => $coding->release->logical_version,
            'terminology_source_hash' => $coding->terminology_source_hash,
            'code' => $coding->concept->code,
            'selection_method' => $coding->selection_method->value,
            'content_hash' => $coding->content_hash,
            'automatic_finalization' => false,
        ];
    }

    /** @return Builder<CodingSuggestionRun> */
    private function latestRunForSourceQuery(CodingSuggestionRun $run): Builder
    {
        $query = CodingSuggestionRun::query()->where('source_type', $run->source_type);

        if ($run->source_type === CodingSourceType::Diagnosis) {
            if ($run->source_condition_id === null) {
                throw new DomainException('The diagnosis suggestion run has no diagnosis source.');
            }

            $query->where('source_condition_id', $run->source_condition_id);
        } else {
            if ($run->source_procedure_id === null) {
                throw new DomainException('The procedure suggestion run has no procedure source.');
            }

            $query->where('source_procedure_id', $run->source_procedure_id);
        }

        return $query
            ->orderByDesc('generated_at')
            ->orderByDesc('id');
    }

    /** @return Builder<CodingAssignment> */
    private function assignmentSourceQuery(CodingSuggestionRun $run): Builder
    {
        $query = CodingAssignment::query()->where('source_type', $run->source_type);

        if ($run->source_type === CodingSourceType::Diagnosis) {
            if ($run->source_condition_id === null) {
                throw new DomainException('The diagnosis suggestion run has no diagnosis source.');
            }

            return $query->where('source_condition_id', $run->source_condition_id);
        }

        if ($run->source_procedure_id === null) {
            throw new DomainException('The procedure suggestion run has no procedure source.');
        }

        return $query->where('source_procedure_id', $run->source_procedure_id);
    }

    private function releaseDebriefTasks(Encounter $encounter): void
    {
        Assignment::query()
            ->active()
            ->where('session_id', $encounter->session_id)
            ->get()
            ->filter(function (Assignment $assignment) use ($encounter): bool {
                if (! $assignment->hasCapability(Capability::DebriefView)) {
                    return false;
                }

                $matchesCase = $assignment->patient_id === $encounter->patient_id
                    && $assignment->encounter_id === $encounter->getKey();
                $sessionWide = $assignment->patient_id === null
                    && $assignment->encounter_id === null;

                return $matchesCase || $sessionWide;
            })
            ->each(function (Assignment $assignment) use ($encounter): void {
                $task = WorkTask::query()->firstOrNew([
                    'assignment_id' => $assignment->getKey(),
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::Debrief,
                ]);
                $task->fill([
                    'session_id' => $encounter->session_id,
                    'title' => 'Buka linimasa dan debrief encounter',
                    'description' => 'Tinjau handoff, versi, koreksi, supervisi, dan keputusan koding dari sumber encounter yang telah difinalisasi.',
                    'status' => WorkTaskStatus::Ready,
                    'priority' => 2,
                    'source_program' => Program::Facilitation,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                        'releaseGate' => EncounterStatus::Finalized->value,
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ])->save();
            });
    }

    /** @return array<string, mixed> */
    private function runSourceAuditMetadata(CodingSuggestionRun $run): array
    {
        if ($run->source_type === CodingSourceType::Diagnosis) {
            $condition = $run->sourceCondition;
            $version = $run->sourceEntryVersion;

            if (! $condition || ! $version) {
                throw new DomainException('The diagnosis suggestion source is unavailable for audit.');
            }

            return [
                'source_type' => CodingSourceType::Diagnosis->value,
                'source_condition_public_id' => $condition->public_id,
                'source_entry_version_public_id' => $version->public_id,
                'source_clinical_content_hash' => $version->content_hash,
            ];
        }

        $procedure = $run->sourceProcedure;

        if (! $procedure) {
            throw new DomainException('The procedure suggestion source is unavailable for audit.');
        }

        return [
            'source_type' => CodingSourceType::Procedure->value,
            'source_procedure_public_id' => $procedure->public_id,
            'source_procedure_content_hash' => $procedure->content_hash,
            'source_closure_public_id' => $procedure->closure->public_id,
            'source_closure_content_hash' => $procedure->closure->content_hash,
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
