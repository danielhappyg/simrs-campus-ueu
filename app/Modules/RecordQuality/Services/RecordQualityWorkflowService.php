<?php

namespace App\Modules\RecordQuality\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\RecordQuality\Enums\RecordCorrectionStatus;
use App\Modules\RecordQuality\Enums\RecordQualityFindingSeverity;
use App\Modules\RecordQuality\Enums\RecordQualityReviewAction;
use App\Modules\RecordQuality\Enums\RecordQualityReviewStatus;
use App\Modules\RecordQuality\Models\RecordCorrectionRequest;
use App\Modules\RecordQuality\Models\RecordQualityFinding;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Models\RecordQualityReviewActionModel;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordQualityWorkflowService
{
    public function __construct(
        private readonly RecordCompletenessService $completenessService,
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /** @param array<string, mixed> $payload */
    public function save(Encounter $encounter, Assignment $reviewerAssignment, array $payload): RecordQualityReview
    {
        return DB::transaction(function () use ($encounter, $reviewerAssignment, $payload): RecordQualityReview {
            $requestKey = (string) $payload['request_key'];
            $intent = ClinicalSaveIntent::from((string) $payload['intent']);
            $existing = RecordQualityReview::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->encounter_id !== $encounter->getKey()
                    || $existing->reviewer_assignment_id !== $reviewerAssignment->getKey()) {
                    throw new DomainException('The record-quality request key was already used in another context.');
                }

                return $existing->load(['reviewer', 'findings.correctionRequest', 'reviewActions.reviewer']);
            }

            $lockedEncounter = Encounter::query()->whereKey($encounter->getKey())->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($lockedEncounter->session_id)->lockForUpdate()->firstOrFail();
            $reviewer = Assignment::query()->active()->whereKey($reviewerAssignment->getKey())->lockForUpdate()->first();
            $this->assertAssignment($reviewer, $lockedEncounter, $session, Capability::RecordReview, requireRmik: true);

            if (! in_array($lockedEncounter->status, [EncounterStatus::ClinicallyClosed, EncounterStatus::RecordReview], true)) {
                throw new DomainException('Record-quality review can begin only after clinical closure and outside an active amendment.');
            }

            $latest = RecordQualityReview::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();
            $codingCorrection = CodingDocumentationCorrection::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->where('record_quality_reviewer_assignment_id', $reviewer->getKey())
                ->where('status', CodingDocumentationCorrectionStatus::ReadyForRmik)
                ->with(['responseClosure', 'supersededRecordQualityReview'])
                ->lockForUpdate()
                ->first();
            $procedureCorrection = ProcedureDocumentationCorrection::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->where('record_quality_reviewer_assignment_id', $reviewer->getKey())
                ->where('status', ProcedureDocumentationCorrectionStatus::ReadyForRmik)
                ->with(['responseClosure', 'supersededRecordQualityReview'])
                ->lockForUpdate()
                ->first();

            if ($codingCorrection && $procedureCorrection) {
                throw new DomainException('Only one coding documentation correction can be reviewed at a time.');
            }

            $hasCodingCorrection = $codingCorrection !== null || $procedureCorrection !== null;

            if ($latest?->status === RecordQualityReviewStatus::Submitted
                || ($latest?->status === RecordQualityReviewStatus::Approved && ! $hasCodingCorrection)) {
                throw new DomainException('The current record-quality review is not open for authoring.');
            }

            if ($codingCorrection
                && (($latest?->status === RecordQualityReviewStatus::Approved
                    && $latest->getKey() !== $codingCorrection->superseded_record_quality_review_id)
                    || $codingCorrection->responseClosure?->status !== EncounterClosureStatus::Approved)) {
                throw new DomainException('The coding correction does not point to the current approved RMIK review and successor closure.');
            }

            if ($procedureCorrection
                && (($latest?->status === RecordQualityReviewStatus::Approved
                    && $latest->getKey() !== $procedureCorrection->superseded_record_quality_review_id)
                    || $procedureCorrection->responseClosure?->status !== EncounterClosureStatus::Approved)) {
                throw new DomainException('The procedure correction does not point to the current approved RMIK review and successor closure.');
            }

            $changeReason = $this->nullableText($payload['change_reason'] ?? null);

            if (($latest?->status === RecordQualityReviewStatus::ChangesRequested || $hasCodingCorrection) && $changeReason === null) {
                throw new DomainException('A corrected record-quality review requires an attributed change reason.');
            }

            $closure = EncounterClosure::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->where('status', EncounterClosureStatus::Approved)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if (! $closure) {
                throw new DomainException('Record-quality review requires a current approved encounter closure.');
            }

            $manualFindings = $this->normalizeManualFindings($payload['findings'] ?? [], $closure);
            $resolvedCorrections = $this->resolveCorrections(
                encounter: $lockedEncounter,
                reviewer: $reviewer,
                closure: $closure,
                rawIds: $payload['resolved_correction_public_ids'] ?? [],
                resolve: $intent === ClinicalSaveIntent::Submit,
            );
            $completeness = $this->completenessService->evaluate($lockedEncounter);

            if ($intent === ClinicalSaveIntent::Submit) {
                if (! $completeness['ready']) {
                    throw new DomainException('The deterministic completeness checklist still contains blocking items.');
                }

                if (collect($manualFindings)->contains(
                    fn (array $finding): bool => $finding['severity'] === RecordQualityFindingSeverity::Blocking->value,
                )) {
                    throw new DomainException('A review with a blocking manual finding cannot be submitted for approval.');
                }

                if (RecordCorrectionRequest::query()
                    ->where('encounter_id', $lockedEncounter->getKey())
                    ->where('status', '!=', RecordCorrectionStatus::Resolved)
                    ->exists()) {
                    throw new DomainException('All correction requests must be verified and resolved before submission.');
                }
            }

            $content = [
                'checklistVersion' => RecordCompletenessService::CHECKLIST_VERSION,
                'completeness' => $completeness,
                'assemblySnapshot' => $this->completenessService->assemblySnapshot($lockedEncounter),
                'manualFindings' => $manualFindings,
                'resolvedCorrections' => $resolvedCorrections,
                'codingDocumentationCorrection' => $codingCorrection ? [
                    'publicId' => $codingCorrection->public_id,
                    'priorRecordQualityReviewPublicId' => $codingCorrection->supersededRecordQualityReview->public_id,
                    'requestedSourceHash' => $codingCorrection->requested_source_hash,
                    'responseClosurePublicId' => $codingCorrection->responseClosure->public_id,
                    'responseClosureContentHash' => $codingCorrection->responseClosure->content_hash,
                ] : null,
                'procedureDocumentationCorrection' => $procedureCorrection ? [
                    'publicId' => $procedureCorrection->public_id,
                    'priorRecordQualityReviewPublicId' => $procedureCorrection->supersededRecordQualityReview->public_id,
                    'requestedSourceHash' => $procedureCorrection->requested_source_hash,
                    'requestedClosureHash' => $procedureCorrection->requested_closure_hash,
                    'responseClosurePublicId' => $procedureCorrection->responseClosure->public_id,
                    'responseClosureContentHash' => $procedureCorrection->responseClosure->content_hash,
                ] : null,
            ];
            $recordedAt = CarbonImmutable::now();
            $status = $intent === ClinicalSaveIntent::Submit
                ? RecordQualityReviewStatus::Submitted
                : RecordQualityReviewStatus::Draft;
            $review = RecordQualityReview::query()->create([
                'request_key' => $requestKey,
                'session_id' => $lockedEncounter->session_id,
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->getKey(),
                'reviewer_user_id' => $reviewer->user_id,
                'reviewer_assignment_id' => $reviewer->getKey(),
                'version_number' => $latest === null ? 1 : $latest->version_number + 1,
                'checklist_version' => RecordCompletenessService::CHECKLIST_VERSION,
                'status' => $status,
                'content' => $content,
                'content_hash' => hash('sha256', CanonicalJson::encode($content)),
                'change_reason' => $changeReason,
                'supersedes_review_id' => $latest?->getKey(),
                'recorded_at' => $recordedAt,
                'submitted_at' => $status === RecordQualityReviewStatus::Submitted ? $recordedAt : null,
                'reviewed_at' => null,
            ]);

            foreach ($manualFindings as $index => $finding) {
                RecordQualityFinding::query()->create([
                    'record_quality_review_id' => $review->getKey(),
                    'sequence_number' => $index + 1,
                    ...$finding,
                ]);
            }

            if ($lockedEncounter->status === EncounterStatus::ClinicallyClosed) {
                $lockedEncounter = $this->encounterTransitionService->transition(
                    $lockedEncounter,
                    EncounterStatus::RecordReview,
                    $reviewer,
                    'rmik_record_quality_review_started',
                );
            }

            $this->updateAuthorTask(
                review: $review,
                author: $reviewer,
                encounter: $lockedEncounter,
                status: $status === RecordQualityReviewStatus::Submitted
                    ? WorkTaskStatus::Submitted
                    : WorkTaskStatus::InProgress,
            );

            if ($status === RecordQualityReviewStatus::Submitted) {
                $this->submitReview($review, $reviewer, $lockedEncounter, $session);
            }

            $this->auditRecorder->record(
                action: $status === RecordQualityReviewStatus::Submitted
                    ? 'record_quality.review_submitted'
                    : 'record_quality.review_draft_saved',
                resourceType: 'record_quality_review',
                resourceId: $review->public_id,
                actor: $reviewer->user,
                assignment: $reviewer,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'version_number' => $review->version_number,
                    'checklist_version' => $review->checklist_version,
                    'content_hash' => $review->content_hash,
                    'manual_finding_count' => count($manualFindings),
                    'resolved_correction_count' => count($resolvedCorrections),
                    'coding_documentation_correction_public_id' => $codingCorrection?->public_id,
                    'procedure_documentation_correction_public_id' => $procedureCorrection?->public_id,
                ],
            );

            return $review->refresh()->load(['reviewer', 'findings.correctionRequest', 'reviewActions.reviewer']);
        });
    }

    public function requestCorrection(
        RecordQualityFinding $finding,
        Assignment $requesterAssignment,
        string $requestKey,
        string $reason,
    ): RecordCorrectionRequest {
        return DB::transaction(function () use ($finding, $requesterAssignment, $requestKey, $reason): RecordCorrectionRequest {
            $existing = RecordCorrectionRequest::query()->where('request_key', $requestKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->record_quality_finding_id !== $finding->getKey()
                    || $existing->requested_by_assignment_id !== $requesterAssignment->getKey()) {
                    throw new DomainException('The correction request key was already used in another context.');
                }

                return $existing;
            }

            $lockedFinding = RecordQualityFinding::query()
                ->with(['review', 'responsibleAssignment'])
                ->whereKey($finding->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($lockedFinding->review->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($encounter->session_id)->lockForUpdate()->firstOrFail();
            $requester = Assignment::query()->active()->whereKey($requesterAssignment->getKey())->lockForUpdate()->first();
            $this->assertAssignment($requester, $encounter, $session, Capability::RecordReview, requireRmik: true);

            if ($lockedFinding->review->reviewer_assignment_id !== $requester->getKey()
                || $lockedFinding->review->status !== RecordQualityReviewStatus::Draft
                || $encounter->status !== EncounterStatus::RecordReview) {
                throw new DomainException('Only the author of the current draft RMIK review can request correction.');
            }

            if (RecordCorrectionRequest::query()->where('record_quality_finding_id', $lockedFinding->getKey())->exists()) {
                throw new DomainException('This finding already has a correction request.');
            }

            $correction = RecordCorrectionRequest::query()->create([
                'request_key' => $requestKey,
                'record_quality_finding_id' => $lockedFinding->getKey(),
                'session_id' => $encounter->session_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'requested_by_user_id' => $requester->user_id,
                'requested_by_assignment_id' => $requester->getKey(),
                'responsible_assignment_id' => $lockedFinding->responsible_assignment_id,
                'status' => RecordCorrectionStatus::Open,
                'reason' => trim($reason),
                'requested_source_hash' => $lockedFinding->affected_content_hash,
                'requested_at' => CarbonImmutable::now(),
                'response_closure_id' => null,
                'responded_at' => null,
                'resolved_by_assignment_id' => null,
                'resolution_note' => null,
                'resolved_at' => null,
            ]);
            $encounter = $this->encounterTransitionService->transition(
                $encounter,
                EncounterStatus::AmendmentPending,
                $requester,
                'rmik_closure_correction_requested',
            );
            $this->updateAuthorTask(
                review: $lockedFinding->review,
                author: $requester,
                encounter: $encounter,
                status: WorkTaskStatus::Blocked,
            );
            WorkTask::query()->updateOrCreate(
                [
                    'assignment_id' => $lockedFinding->responsible_assignment_id,
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::RecordCorrection,
                ],
                [
                    'session_id' => $encounter->session_id,
                    'title' => 'Koreksi Penutupan untuk Temuan RMIK',
                    'description' => 'Buat versi penerus dengan alasan perubahan. RMIK tidak dapat mengubah teks klinis Anda.',
                    'status' => WorkTaskStatus::Ready,
                    'priority' => 1,
                    'source_program' => Program::Rmik,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                        'correctionRequestPublicId' => $correction->public_id,
                        'findingPublicId' => $lockedFinding->public_id,
                        'affectedClosurePublicId' => $lockedFinding->affected_resource_public_id,
                        'affectedContentHash' => $lockedFinding->affected_content_hash,
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ],
            );

            $this->auditRecorder->record(
                action: 'record_quality.correction_requested',
                resourceType: 'record_correction_request',
                resourceId: $correction->public_id,
                actor: $requester->user,
                assignment: $requester,
                session: $session,
                encounter: $encounter,
                reason: $reason,
                metadata: [
                    'finding_public_id' => $lockedFinding->public_id,
                    'affected_content_hash' => $lockedFinding->affected_content_hash,
                    'responsible_assignment_public_id' => $lockedFinding->responsibleAssignment->public_id,
                ],
            );

            return $correction->load(['finding.review', 'responsibleAssignment.user']);
        });
    }

    /** @param list<array<string, mixed>> $findings */
    public function review(
        RecordQualityReview $review,
        Assignment $supervisorAssignment,
        RecordQualityReviewAction $action,
        string $requestKey,
        ?string $comment,
        array $findings = [],
    ): RecordQualityReview {
        return DB::transaction(function () use ($review, $supervisorAssignment, $action, $requestKey, $comment, $findings): RecordQualityReview {
            $existing = RecordQualityReviewActionModel::query()->where('request_key', $requestKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->record_quality_review_id !== $review->getKey()
                    || $existing->reviewer_assignment_id !== $supervisorAssignment->getKey()
                    || $existing->action !== $action) {
                    throw new DomainException('The RMIK review-decision key was already used in another context.');
                }

                return RecordQualityReview::query()
                    ->with(['reviewer', 'findings.correctionRequest', 'reviewActions.reviewer'])
                    ->findOrFail($review->getKey());
            }

            $lockedReview = RecordQualityReview::query()
                ->with(['reviewerAssignment', 'findings.correctionRequest'])
                ->whereKey($review->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($lockedReview->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($lockedReview->session_id)->lockForUpdate()->firstOrFail();
            $supervisor = Assignment::query()->active()->whereKey($supervisorAssignment->getKey())->lockForUpdate()->first();
            $this->assertAssignment($supervisor, $encounter, $session, Capability::SupervisionReview, requireRmik: true);

            if ($lockedReview->reviewerAssignment->supervisor_assignment_id !== $supervisor->getKey()) {
                throw new DomainException('Only the supervisor linked to the RMIK reviewer can decide this version.');
            }

            if ($lockedReview->status !== RecordQualityReviewStatus::Submitted
                || $encounter->status !== EncounterStatus::RecordReview) {
                throw new DomainException('Only the current submitted RMIK review can be decided.');
            }

            if ($action === RecordQualityReviewAction::RequestChanges && $findings === []) {
                throw new DomainException('A request for RMIK review changes requires at least one finding.');
            }

            if ($action === RecordQualityReviewAction::ApproveSimulation
                && ! $this->completenessService->evaluate($encounter)['ready']) {
                throw new DomainException('The completeness checklist must still pass at supervisor approval time.');
            }

            $reviewedAt = CarbonImmutable::now();
            RecordQualityReviewActionModel::query()->create([
                'request_key' => $requestKey,
                'record_quality_review_id' => $lockedReview->getKey(),
                'reviewer_user_id' => $supervisor->user_id,
                'reviewer_assignment_id' => $supervisor->getKey(),
                'action' => $action,
                'findings' => $findings === [] ? null : $findings,
                'comment' => $this->nullableText($comment),
                'reviewed_content_hash' => $lockedReview->content_hash,
                'reviewed_at' => $reviewedAt,
            ]);
            $targetStatus = $action === RecordQualityReviewAction::ApproveSimulation
                ? RecordQualityReviewStatus::Approved
                : RecordQualityReviewStatus::ChangesRequested;
            $lockedReview->persistReviewStatus($targetStatus, $reviewedAt);
            $this->updateAuthorTask(
                review: $lockedReview,
                author: $lockedReview->reviewerAssignment,
                encounter: $encounter,
                status: $action === RecordQualityReviewAction::ApproveSimulation
                    ? WorkTaskStatus::Complete
                    : WorkTaskStatus::ChangesRequested,
            );
            $this->completeSupervisorTask($lockedReview, $supervisor, $encounter);

            if ($action === RecordQualityReviewAction::ApproveSimulation) {
                $this->releaseCodingTask($lockedReview, $lockedReview->reviewerAssignment, $encounter);
            }

            $resolvedCodingCorrections = 0;
            $resolvedProcedureCorrections = 0;

            if ($action === RecordQualityReviewAction::ApproveSimulation) {
                $codingCorrections = CodingDocumentationCorrection::query()
                    ->where('encounter_id', $encounter->getKey())
                    ->where('record_quality_reviewer_assignment_id', $lockedReview->reviewer_assignment_id)
                    ->where('status', CodingDocumentationCorrectionStatus::ReadyForRmik)
                    ->lockForUpdate()
                    ->get();

                foreach ($codingCorrections as $codingCorrection) {
                    $codingCorrection->persistResolved($lockedReview);
                }

                $resolvedCodingCorrections = $codingCorrections->count();

                $procedureCorrections = ProcedureDocumentationCorrection::query()
                    ->where('encounter_id', $encounter->getKey())
                    ->where('record_quality_reviewer_assignment_id', $lockedReview->reviewer_assignment_id)
                    ->where('status', ProcedureDocumentationCorrectionStatus::ReadyForRmik)
                    ->lockForUpdate()
                    ->get();

                foreach ($procedureCorrections as $procedureCorrection) {
                    $procedureCorrection->persistResolved($lockedReview);
                }

                $resolvedProcedureCorrections = $procedureCorrections->count();
            }

            $this->auditRecorder->record(
                action: $action === RecordQualityReviewAction::ApproveSimulation
                    ? 'record_quality.review_approved_for_simulation'
                    : 'record_quality.review_changes_requested',
                resourceType: 'record_quality_review',
                resourceId: $lockedReview->public_id,
                actor: $supervisor->user,
                assignment: $supervisor,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'version_number' => $lockedReview->version_number,
                    'content_hash' => $lockedReview->content_hash,
                    'finding_count' => count($findings),
                    'resolved_coding_documentation_corrections' => $resolvedCodingCorrections,
                    'resolved_procedure_documentation_corrections' => $resolvedProcedureCorrections,
                ],
            );

            return $lockedReview->refresh()->load(['reviewer', 'findings.correctionRequest', 'reviewActions.reviewer']);
        });
    }

    private function submitReview(
        RecordQualityReview $review,
        Assignment $author,
        Encounter $encounter,
        SimulationSession $session,
    ): void {
        if ($author->supervisor_assignment_id === null) {
            throw new DomainException('A linked RMIK supervisor is required before review submission.');
        }

        $supervisor = Assignment::query()->active()->whereKey($author->supervisor_assignment_id)->lockForUpdate()->first();
        $this->assertAssignment($supervisor, $encounter, $session, Capability::SupervisionReview, requireRmik: true);
        WorkTask::query()->create([
            'session_id' => $session->getKey(),
            'assignment_id' => $supervisor->getKey(),
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::RecordQualityReview,
            'title' => 'Tinjau Kelengkapan RMIK v'.$review->version_number,
            'description' => 'Tinjau checklist, temuan, resolusi koreksi, versi, dan hash yang diajukan.',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
            'source_program' => Program::Rmik,
            'context' => [
                'caseLabel' => $encounter->encounter_number,
                'synthetic' => true,
                'recordQualityReviewPublicId' => $review->public_id,
                'recordQualityReviewVersion' => $review->version_number,
                'contentHash' => $review->content_hash,
            ],
            'available_at' => now(),
            'completed_at' => null,
        ]);
    }

    private function updateAuthorTask(
        RecordQualityReview $review,
        Assignment $author,
        Encounter $encounter,
        WorkTaskStatus $status,
    ): void {
        $task = WorkTask::query()->firstOrNew([
            'assignment_id' => $author->getKey(),
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::RecordReview,
        ]);
        $task->fill([
            'session_id' => $encounter->session_id,
            'title' => 'Telaah Kelengkapan Rekam Medis',
            'description' => $status === WorkTaskStatus::Blocked
                ? 'Menunggu versi koreksi dari penulis klinis dan verifikasi ulang RMIK.'
                : 'Jalankan checklist versi, catat temuan, dan pertahankan provenance setiap sumber.',
            'status' => $status,
            'priority' => 1,
            'source_program' => Program::Medicine,
            'context' => [
                'caseLabel' => $encounter->encounter_number,
                'synthetic' => true,
                'recordQualityReviewPublicId' => $review->public_id,
                'recordQualityReviewVersion' => $review->version_number,
                'contentHash' => $review->content_hash,
            ],
            'available_at' => now(),
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
        ])->save();
    }

    private function completeSupervisorTask(
        RecordQualityReview $review,
        Assignment $supervisor,
        Encounter $encounter,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $supervisor->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::RecordQualityReview)
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->lockForUpdate()
            ->get()
            ->first(fn (WorkTask $candidate): bool => data_get($candidate->context, 'recordQualityReviewPublicId') === $review->public_id);

        if (! $task) {
            throw new DomainException('The exact RMIK supervisor review task is missing.');
        }

        $task->fill(['status' => WorkTaskStatus::Complete, 'completed_at' => now()])->save();
    }

    private function releaseCodingTask(
        RecordQualityReview $review,
        Assignment $coder,
        Encounter $encounter,
    ): void {
        if (! $coder->hasCapability(Capability::CodingWrite)) {
            throw new DomainException('The approved RMIK reviewer lacks coding authority for the next workflow step.');
        }

        $task = WorkTask::query()->firstOrNew([
            'assignment_id' => $coder->getKey(),
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::Coding,
        ]);
        $task->fill([
            'session_id' => $encounter->session_id,
            'title' => 'Koding diagnosis dan prosedur rawat jalan',
            'description' => 'Tinjau sumber diagnosis dan tindakan, jalankan kandidat ICD-10 atau ICD-9-CM, lalu pilih kode secara manual dan ajukan kepada supervisor.',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
            'source_program' => Program::Rmik,
            'context' => [
                'caseLabel' => $encounter->encounter_number,
                'synthetic' => true,
                'recordQualityReviewPublicId' => $review->public_id,
                'recordQualityReviewVersion' => $review->version_number,
                'recordQualityContentHash' => $review->content_hash,
                'automaticFinalization' => false,
            ],
            'available_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeManualFindings(mixed $rawFindings, EncounterClosure $closure): array
    {
        if (! is_array($rawFindings)) {
            return [];
        }

        $findings = [];

        foreach ($rawFindings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $severityValue = (string) ($finding['severity'] ?? '');
            $severity = RecordQualityFindingSeverity::tryFrom($severityValue);
            $code = trim((string) ($finding['code'] ?? ''));
            $message = trim((string) ($finding['message'] ?? ''));
            $requestedAction = trim((string) ($finding['requested_action'] ?? ''));

            if (! $severity || $code === '' || $message === '' || $requestedAction === '') {
                throw new DomainException('Each manual record-quality finding requires code, severity, message, and requested action.');
            }

            $findings[] = [
                'code' => $code,
                'severity' => $severity->value,
                'message' => $message,
                'affected_resource_type' => RecordQualityFinding::AFFECTED_ENCOUNTER_CLOSURE,
                'affected_resource_public_id' => $closure->public_id,
                'affected_version_number' => $closure->version_number,
                'affected_content_hash' => $closure->content_hash,
                'responsible_assignment_id' => $closure->author_assignment_id,
                'requested_action' => $requestedAction,
            ];
        }

        return $findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveCorrections(
        Encounter $encounter,
        Assignment $reviewer,
        EncounterClosure $closure,
        mixed $rawIds,
        bool $resolve,
    ): array {
        if (! is_array($rawIds)) {
            return [];
        }

        $ids = collect($rawIds)
            ->filter(fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
        $resolved = [];

        foreach ($ids as $publicId) {
            $correction = RecordCorrectionRequest::query()
                ->where('public_id', $publicId)
                ->where('encounter_id', $encounter->getKey())
                ->lockForUpdate()
                ->first();

            if (! $correction
                || $correction->requested_by_assignment_id !== $reviewer->getKey()
                || $correction->status !== RecordCorrectionStatus::CorrectedPendingVerification
                || $correction->response_closure_id !== $closure->getKey()
                || hash_equals($correction->requested_source_hash, $closure->content_hash)) {
                throw new DomainException('A resolved correction must reference the approved successor closure and requesting RMIK assignment.');
            }

            if ($resolve) {
                $correction->persistResolved($reviewer, 'Versi penerus yang disetujui memenuhi temuan pada checklist saat ini.');
            }

            $resolved[] = [
                'correctionRequestPublicId' => $correction->public_id,
                'priorSourceHash' => $correction->requested_source_hash,
                'responseClosurePublicId' => $closure->public_id,
                'responseClosureContentHash' => $closure->content_hash,
                'status' => $resolve ? RecordCorrectionStatus::Resolved->value : $correction->status->value,
            ];
        }

        return $resolved;
    }

    private function assertAssignment(
        ?Assignment $assignment,
        Encounter $encounter,
        SimulationSession $session,
        Capability $capability,
        bool $requireRmik,
    ): void {
        if (! $assignment
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $assignment->session_id !== $session->getKey()
            || $assignment->patient_id !== $encounter->patient_id
            || $assignment->encounter_id !== $encounter->getKey()
            || ($requireRmik && $assignment->program !== Program::Rmik)
            || ! $assignment->hasCapability($capability)) {
            throw new DomainException('The active assignment does not permit this RMIK action in the exact case context.');
        }
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
