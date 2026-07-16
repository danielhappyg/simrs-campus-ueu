<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Models\EncounterClosureReviewActionModel;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Coding\Services\CodingInvalidationService;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\RecordQuality\Enums\RecordCorrectionStatus;
use App\Modules\RecordQuality\Models\RecordCorrectionRequest;
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
use Illuminate\Support\Str;
use Throwable;

class EncounterClosureService
{
    public function __construct(
        private readonly ClosureReadinessService $readinessService,
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly CodingInvalidationService $codingInvalidationService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /** @param array<string, mixed> $payload */
    public function save(Encounter $encounter, Assignment $authorAssignment, array $payload): EncounterClosure
    {
        return DB::transaction(function () use ($encounter, $authorAssignment, $payload): EncounterClosure {
            $requestKey = (string) $payload['request_key'];
            $intent = ClinicalSaveIntent::from((string) $payload['intent']);
            $existing = EncounterClosure::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->encounter_id !== $encounter->getKey()
                    || $existing->author_assignment_id !== $authorAssignment->getKey()) {
                    throw new DomainException('The closure request key was already used in another context.');
                }

                return $existing->load(['author', 'authorAssignment', 'procedures', 'reviewActions.reviewer']);
            }

            $lockedEncounter = Encounter::query()
                ->with(['patient', 'session'])
                ->whereKey($encounter->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $session = SimulationSession::query()
                ->whereKey($lockedEncounter->session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $author = Assignment::query()
                ->active()
                ->whereKey($authorAssignment->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertAssignment($author, $lockedEncounter, $session, Capability::MedicalAssessmentWrite);

            $amendmentMode = $lockedEncounter->status === EncounterStatus::AmendmentPending;
            $recordCorrection = $amendmentMode
                ? RecordCorrectionRequest::query()
                    ->where('encounter_id', $lockedEncounter->getKey())
                    ->where('responsible_assignment_id', $author->getKey())
                    ->whereIn('status', [
                        RecordCorrectionStatus::Open->value,
                        RecordCorrectionStatus::ResponseSubmitted->value,
                    ])
                    ->lockForUpdate()
                    ->first()
                : null;
            $codingCorrection = $amendmentMode
                ? CodingDocumentationCorrection::query()
                    ->where('encounter_id', $lockedEncounter->getKey())
                    ->where('responsible_assignment_id', $author->getKey())
                    ->whereIn('status', [
                        CodingDocumentationCorrectionStatus::MedicalApproved->value,
                        CodingDocumentationCorrectionStatus::ClosureResponseSubmitted->value,
                    ])
                    ->with('responseMedicalVersion')
                    ->lockForUpdate()
                    ->first()
                : null;
            $procedureCorrection = $amendmentMode
                ? ProcedureDocumentationCorrection::query()
                    ->where('encounter_id', $lockedEncounter->getKey())
                    ->where('responsible_assignment_id', $author->getKey())
                    ->whereIn('status', [
                        ProcedureDocumentationCorrectionStatus::Open->value,
                        ProcedureDocumentationCorrectionStatus::ClosureResponseSubmitted->value,
                    ])
                    ->with('sourceClosure')
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! in_array($lockedEncounter->status, [EncounterStatus::InConsultation, EncounterStatus::AmendmentPending], true)) {
                throw new DomainException('Encounter closure can be authored only after all downstream care returns to consultation.');
            }

            if ($amendmentMode && collect([$recordCorrection, $codingCorrection, $procedureCorrection])->filter()->count() !== 1) {
                throw new DomainException('A post-closure amendment requires exactly one active correction assigned to this author.');
            }

            $latest = EncounterClosure::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if ($latest?->status === EncounterClosureStatus::Submitted
                || ($latest?->status === EncounterClosureStatus::Approved && ! $amendmentMode)) {
                throw new DomainException('The current encounter closure version is not open for authoring.');
            }

            $changeReason = $this->nullableText($payload['change_reason'] ?? null);

            if (($latest?->status === EncounterClosureStatus::ChangesRequested || $amendmentMode)
                && $changeReason === null) {
                throw new DomainException('A closure correction requires an attributed change reason.');
            }

            $readiness = $this->readinessService->evaluate($lockedEncounter);

            if ($intent === ClinicalSaveIntent::Submit) {
                $this->assertSubmitFields($payload);
                $this->assertReady($readiness);
            }

            $sourceSnapshot = $this->readinessService->sourceSnapshot($lockedEncounter);
            $procedureDocumentation = $this->procedureDocumentation(
                payload: $payload,
                sourceSnapshot: $sourceSnapshot,
                latest: $latest,
                lockToPriorVersion: $codingCorrection !== null,
            );
            $authoredContent = [
                'leavingCondition' => $this->nullableText($payload['leaving_condition'] ?? null),
                'disposition' => $this->nullableText($payload['disposition'] ?? null),
                'followUpPlan' => $this->nullableText($payload['follow_up_plan'] ?? null),
                'referralPlan' => $this->nullableText($payload['referral_plan'] ?? null),
                'educationInstructions' => $this->nullableText($payload['education_instructions'] ?? null),
                'outpatientSummary' => $this->nullableText($payload['outpatient_summary'] ?? null),
            ];
            $clinicalOccurrenceAt = CarbonImmutable::parse((string) $payload['clinical_occurrence_at']);
            $this->assertProcedureCorrectionScope(
                authoredContent: $authoredContent,
                procedureDocumentation: $procedureDocumentation,
                clinicalOccurrenceAt: $clinicalOccurrenceAt,
                correction: $procedureCorrection,
            );
            $content = [
                'authored' => $authoredContent,
                'procedureDocumentation' => $procedureDocumentation,
                'sourceSnapshot' => $sourceSnapshot,
                'readinessAtAuthoring' => $readiness,
                'correctionContext' => $recordCorrection ? [
                    'type' => 'RMIK_CLOSURE_CORRECTION',
                    'correctionRequestPublicId' => $recordCorrection->public_id,
                    'requestedSourceHash' => $recordCorrection->requested_source_hash,
                ] : ($codingCorrection ? [
                    'type' => 'CODING_DOCUMENTATION_CORRECTION',
                    'codingDocumentationCorrectionPublicId' => $codingCorrection->public_id,
                    'requestedSourceHash' => $codingCorrection->requested_source_hash,
                    'responseMedicalVersionPublicId' => $codingCorrection->responseMedicalVersion?->public_id,
                    'responseMedicalContentHash' => $codingCorrection->responseMedicalVersion?->content_hash,
                ] : ($procedureCorrection ? [
                    'type' => 'PROCEDURE_DOCUMENTATION_CORRECTION',
                    'procedureDocumentationCorrectionPublicId' => $procedureCorrection->public_id,
                    'sourceProcedurePublicId' => $procedureCorrection->sourceProcedure->public_id,
                    'sourceClosurePublicId' => $procedureCorrection->sourceClosure->public_id,
                    'requestedSourceHash' => $procedureCorrection->requested_source_hash,
                    'requestedClosureHash' => $procedureCorrection->requested_closure_hash,
                ] : null)),
            ];
            $recordedAt = CarbonImmutable::now();
            $status = $intent === ClinicalSaveIntent::Submit
                ? EncounterClosureStatus::Submitted
                : EncounterClosureStatus::Draft;
            $closure = EncounterClosure::query()->create([
                'request_key' => $requestKey,
                'session_id' => $lockedEncounter->session_id,
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->getKey(),
                'author_user_id' => $author->user_id,
                'author_assignment_id' => $author->getKey(),
                'version_number' => $latest === null ? 1 : $latest->version_number + 1,
                'schema_version' => 'encounter-closure.v2',
                'status' => $status,
                'content' => $content,
                'content_hash' => hash('sha256', CanonicalJson::encode($content)),
                'change_reason' => $changeReason,
                'supersedes_closure_id' => $latest?->getKey(),
                'clinical_occurrence_at' => $clinicalOccurrenceAt,
                'recorded_at' => $recordedAt,
                'submitted_at' => $status === EncounterClosureStatus::Submitted ? $recordedAt : null,
                'reviewed_at' => null,
            ]);

            foreach ($procedureDocumentation['procedures'] as $procedure) {
                ClinicalProcedure::query()->create([
                    'public_id' => $procedure['publicId'],
                    'session_id' => $lockedEncounter->session_id,
                    'patient_id' => $lockedEncounter->patient_id,
                    'encounter_id' => $lockedEncounter->getKey(),
                    'encounter_closure_id' => $closure->getKey(),
                    'reason_condition_id' => $this->modelIdByPublicId(
                        ClinicalCondition::class,
                        $procedure['reasonConditionPublicId'],
                    ),
                    'based_on_service_request_id' => $this->modelIdByPublicId(
                        ServiceRequest::class,
                        $procedure['basedOnServiceRequestPublicId'],
                    ),
                    'recorder_user_id' => $author->user_id,
                    'recorder_assignment_id' => $author->getKey(),
                    'sequence_number' => $procedure['sequenceNumber'],
                    'status' => ClinicalProcedureStatus::Completed,
                    'authored_text' => $procedure['authoredText'],
                    'performed_start_at' => $procedure['performedStartAt'],
                    'performed_end_at' => $procedure['performedEndAt'],
                    'performer_text' => $procedure['performerText'],
                    'body_site_text' => $procedure['bodySiteText'],
                    'outcome_text' => $procedure['outcomeText'],
                    'note' => $procedure['note'],
                    'content_hash' => $procedure['contentHash'],
                    'recorded_at' => $recordedAt,
                ]);
            }

            $this->updateAuthorTask(
                closure: $closure,
                author: $author,
                encounter: $lockedEncounter,
                status: $status === EncounterClosureStatus::Submitted
                    ? WorkTaskStatus::Submitted
                    : WorkTaskStatus::InProgress,
            );

            if ($recordCorrection) {
                $this->updateCorrectionTask(
                    correction: $recordCorrection,
                    closure: $closure,
                    encounter: $lockedEncounter,
                    status: $status === EncounterClosureStatus::Submitted
                        ? WorkTaskStatus::Submitted
                        : WorkTaskStatus::InProgress,
                );
            }

            if ($procedureCorrection) {
                $this->updateProcedureCorrectionTask(
                    correction: $procedureCorrection,
                    closure: $closure,
                    encounter: $lockedEncounter,
                    status: $status === EncounterClosureStatus::Submitted
                        ? WorkTaskStatus::Submitted
                        : WorkTaskStatus::InProgress,
                );
            }

            if ($status === EncounterClosureStatus::Submitted) {
                $recordCorrection?->persistResponse($closure);
                $codingCorrection?->persistClosureResponse($closure);
                $procedureCorrection?->persistClosureResponse($closure);
                $this->submit($closure, $author, $lockedEncounter, $session);
            }

            $this->auditRecorder->record(
                action: $status === EncounterClosureStatus::Submitted
                    ? 'clinical.encounter_closure_submitted'
                    : 'clinical.encounter_closure_draft_saved',
                resourceType: 'encounter_closure',
                resourceId: $closure->public_id,
                actor: $author->user,
                assignment: $author,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'version_number' => $closure->version_number,
                    'schema_version' => $closure->schema_version,
                    'content_hash' => $closure->content_hash,
                    'intent' => $intent->value,
                    'readiness_passed' => $readiness['ready'],
                    'procedure_documentation_state' => $procedureDocumentation['state'],
                    'performed_procedure_count' => count($procedureDocumentation['procedures']),
                    'coding_documentation_correction_public_id' => $codingCorrection?->public_id,
                    'procedure_documentation_correction_public_id' => $procedureCorrection?->public_id,
                ],
            );

            return $closure->refresh()->load(['author', 'authorAssignment', 'procedures', 'reviewActions.reviewer']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    public function review(
        EncounterClosure $closure,
        Assignment $reviewerAssignment,
        EncounterClosureReviewAction $action,
        string $requestKey,
        ?string $comment,
        array $findings = [],
    ): EncounterClosure {
        return DB::transaction(function () use ($closure, $reviewerAssignment, $action, $requestKey, $comment, $findings): EncounterClosure {
            $existing = EncounterClosureReviewActionModel::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->encounter_closure_id !== $closure->getKey()
                    || $existing->reviewer_assignment_id !== $reviewerAssignment->getKey()
                    || $existing->action !== $action) {
                    throw new DomainException('The closure-review request key was already used for another decision.');
                }

                return EncounterClosure::query()
                    ->with(['author', 'authorAssignment', 'reviewActions.reviewer'])
                    ->findOrFail($closure->getKey());
            }

            $lockedClosure = EncounterClosure::query()
                ->with(['authorAssignment', 'author'])
                ->whereKey($closure->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()
                ->whereKey($lockedClosure->encounter_id)
                ->lockForUpdate()
                ->firstOrFail();
            $session = SimulationSession::query()
                ->whereKey($lockedClosure->session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $reviewer = Assignment::query()
                ->active()
                ->whereKey($reviewerAssignment->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertAssignment($reviewer, $encounter, $session, Capability::SupervisionReview);

            if ($lockedClosure->authorAssignment->supervisor_assignment_id !== $reviewer->getKey()) {
                throw new DomainException('Only the supervisor linked to the closure author can review this version.');
            }

            $amendmentMode = $encounter->status === EncounterStatus::AmendmentPending;

            if ($lockedClosure->status !== EncounterClosureStatus::Submitted
                || ! in_array($encounter->status, [EncounterStatus::ClosurePending, EncounterStatus::AmendmentPending], true)) {
                throw new DomainException('Only the current submitted closure can be reviewed.');
            }

            if ($action === EncounterClosureReviewAction::RequestChanges && $findings === []) {
                throw new DomainException('A request for closure changes requires at least one attributed finding.');
            }

            $readiness = $this->readinessService->evaluate($encounter);

            if ($action === EncounterClosureReviewAction::ApproveSimulation) {
                $this->assertReady($readiness);
            }

            $reviewedAt = CarbonImmutable::now();
            EncounterClosureReviewActionModel::query()->create([
                'request_key' => $requestKey,
                'encounter_closure_id' => $lockedClosure->getKey(),
                'reviewer_user_id' => $reviewer->user_id,
                'reviewer_assignment_id' => $reviewer->getKey(),
                'action' => $action,
                'findings' => $findings === [] ? null : $findings,
                'comment' => $this->nullableText($comment),
                'reviewed_content_hash' => $lockedClosure->content_hash,
                'reviewed_at' => $reviewedAt,
            ]);
            $targetStatus = $action === EncounterClosureReviewAction::ApproveSimulation
                ? EncounterClosureStatus::Approved
                : EncounterClosureStatus::ChangesRequested;
            $lockedClosure->persistReviewStatus($targetStatus, $reviewedAt);
            $this->updateAuthorTask(
                closure: $lockedClosure,
                author: $lockedClosure->authorAssignment,
                encounter: $encounter,
                status: $action === EncounterClosureReviewAction::ApproveSimulation
                    ? WorkTaskStatus::Complete
                    : WorkTaskStatus::ChangesRequested,
            );
            $this->completeReviewTask($lockedClosure, $reviewer, $encounter);

            $recordCorrection = $amendmentMode
                ? RecordCorrectionRequest::query()
                    ->where('response_closure_id', $lockedClosure->getKey())
                    ->lockForUpdate()
                    ->first()
                : null;
            $codingCorrection = $amendmentMode
                ? CodingDocumentationCorrection::query()
                    ->where('response_closure_id', $lockedClosure->getKey())
                    ->where('status', CodingDocumentationCorrectionStatus::ClosureResponseSubmitted)
                    ->lockForUpdate()
                    ->first()
                : null;
            $procedureCorrection = $amendmentMode
                ? ProcedureDocumentationCorrection::query()
                    ->where('response_closure_id', $lockedClosure->getKey())
                    ->where('status', ProcedureDocumentationCorrectionStatus::ClosureResponseSubmitted)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($amendmentMode && collect([$recordCorrection, $codingCorrection, $procedureCorrection])->filter()->count() !== 1) {
                throw new DomainException('The submitted amendment must link to exactly one active correction request.');
            }

            $invalidatedProcedureCodingAssignments = 0;

            if ($action === EncounterClosureReviewAction::ApproveSimulation && $amendmentMode) {
                $invalidatedProcedureCodingAssignments = $this->codingInvalidationService
                    ->invalidateForClosureAmendment($encounter, $lockedClosure);
                $recordCorrection?->persistPendingVerification($lockedClosure);
                $codingCorrection?->persistReadyForRmik($lockedClosure);
                $procedureCorrection?->persistReadyForRmik($lockedClosure);
                $encounter = $this->encounterTransitionService->transition(
                    $encounter,
                    EncounterStatus::RecordReview,
                    $reviewer,
                    'approved_closure_amendment_returns_to_rmik',
                );

                if ($recordCorrection) {
                    $this->updateCorrectionTask(
                        correction: $recordCorrection,
                        closure: $lockedClosure,
                        encounter: $encounter,
                        status: WorkTaskStatus::Complete,
                    );
                    $this->resumeRecordReviewTask($recordCorrection, $encounter, $lockedClosure);
                } elseif ($codingCorrection) {
                    $this->resumeCodingRecordReviewTask($codingCorrection, $encounter, $lockedClosure);
                } elseif ($procedureCorrection) {
                    $this->updateProcedureCorrectionTask(
                        correction: $procedureCorrection,
                        closure: $lockedClosure,
                        encounter: $encounter,
                        status: WorkTaskStatus::Complete,
                    );
                    $this->resumeProcedureCorrectionRecordReviewTask($procedureCorrection, $encounter, $lockedClosure);
                } else {
                    throw new DomainException('The approved amendment has no active correction lifecycle.');
                }

                $releasedTasks = 0;
            } elseif ($action === EncounterClosureReviewAction::ApproveSimulation) {
                $encounter = $this->encounterTransitionService->transition(
                    $encounter,
                    EncounterStatus::ClinicallyClosed,
                    $reviewer,
                    'encounter_closure_approved_for_simulation',
                );
                $releasedTasks = $this->releaseRecordReviewTasks($lockedClosure, $encounter);
            } elseif (! $amendmentMode) {
                $encounter = $this->encounterTransitionService->transition(
                    $encounter,
                    EncounterStatus::InConsultation,
                    $reviewer,
                    'encounter_closure_changes_requested',
                );
                $releasedTasks = 0;
            } else {
                if ($recordCorrection) {
                    $this->updateCorrectionTask(
                        correction: $recordCorrection,
                        closure: $lockedClosure,
                        encounter: $encounter,
                        status: WorkTaskStatus::ChangesRequested,
                    );
                } elseif ($procedureCorrection) {
                    $this->updateProcedureCorrectionTask(
                        correction: $procedureCorrection,
                        closure: $lockedClosure,
                        encounter: $encounter,
                        status: WorkTaskStatus::ChangesRequested,
                    );
                }
                $releasedTasks = 0;
            }

            $this->auditRecorder->record(
                action: $action === EncounterClosureReviewAction::ApproveSimulation
                    ? 'clinical.encounter_closure_approved_for_simulation'
                    : 'clinical.encounter_closure_changes_requested',
                resourceType: 'encounter_closure',
                resourceId: $lockedClosure->public_id,
                actor: $reviewer->user,
                assignment: $reviewer,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'version_number' => $lockedClosure->version_number,
                    'content_hash' => $lockedClosure->content_hash,
                    'finding_count' => count($findings),
                    'readiness_passed' => $readiness['ready'],
                    'record_review_tasks_released' => $releasedTasks,
                    'coding_documentation_correction_public_id' => $codingCorrection?->public_id,
                    'procedure_documentation_correction_public_id' => $procedureCorrection?->public_id,
                    'stale_procedure_coding_assignments_invalidated' => $invalidatedProcedureCodingAssignments,
                ],
            );

            return $lockedClosure->refresh()->load(['author', 'authorAssignment', 'reviewActions.reviewer']);
        });
    }

    private function submit(
        EncounterClosure $closure,
        Assignment $author,
        Encounter $encounter,
        SimulationSession $session,
    ): void {
        if ($author->supervisor_assignment_id === null) {
            throw new DomainException('A linked medical supervisor is required before closure submission.');
        }

        $supervisor = Assignment::query()
            ->active()
            ->whereKey($author->supervisor_assignment_id)
            ->lockForUpdate()
            ->first();
        $this->assertAssignment($supervisor, $encounter, $session, Capability::SupervisionReview);
        if ($encounter->status === EncounterStatus::InConsultation) {
            $this->encounterTransitionService->transition(
                $encounter,
                EncounterStatus::ClosurePending,
                $author,
                'encounter_closure_submitted',
            );
        } elseif ($encounter->status !== EncounterStatus::AmendmentPending) {
            throw new DomainException('The encounter is not in a valid state for closure submission.');
        }

        WorkTask::query()->create([
            'session_id' => $session->getKey(),
            'assignment_id' => $supervisor->getKey(),
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::EncounterClosureReview,
            'title' => 'Tinjau Penutupan Encounter v'.$closure->version_number,
            'description' => 'Tinjau isi, provenance, readiness, versi, dan hash yang diajukan. Persetujuan hanya berlaku untuk simulasi.',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
            'source_program' => $author->program,
            'context' => [
                'caseLabel' => $encounter->encounter_number,
                'synthetic' => true,
                'encounterClosurePublicId' => $closure->public_id,
                'encounterClosureVersion' => $closure->version_number,
                'contentHash' => $closure->content_hash,
            ],
            'available_at' => now(),
            'completed_at' => null,
        ]);
    }

    private function updateAuthorTask(
        EncounterClosure $closure,
        Assignment $author,
        Encounter $encounter,
        WorkTaskStatus $status,
    ): void {
        $task = WorkTask::query()->firstOrNew([
            'assignment_id' => $author->getKey(),
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::EncounterClosure,
        ]);
        $task->fill([
            'session_id' => $encounter->session_id,
            'title' => 'Ajukan Penutupan Encounter',
            'description' => $status === WorkTaskStatus::ChangesRequested
                ? 'Supervisor meminta perbaikan. Buat versi penerus dengan alasan perubahan; versi lama tetap utuh.'
                : 'Tinjau hasil, outcome obat, follow-up, edukasi, disposisi, dan ringkasan sebelum penutupan.',
            'status' => $status,
            'priority' => 1,
            'source_program' => Program::Medicine,
            'context' => [
                'caseLabel' => $encounter->encounter_number,
                'synthetic' => true,
                'encounterClosurePublicId' => $closure->public_id,
                'encounterClosureVersion' => $closure->version_number,
                'contentHash' => $closure->content_hash,
            ],
            'available_at' => now(),
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
        ])->save();
    }

    private function updateCorrectionTask(
        RecordCorrectionRequest $correction,
        EncounterClosure $closure,
        Encounter $encounter,
        WorkTaskStatus $status,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $correction->responsible_assignment_id)
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::RecordCorrection)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The assigned RMIK correction task is missing.');
        }

        $task->fill([
            'status' => $status,
            'description' => $status === WorkTaskStatus::ChangesRequested
                ? 'Supervisor meminta perbaikan lanjutan pada versi koreksi. Versi sebelumnya tetap utuh.'
                : 'Koreksi tetap berupa versi penerus dengan alasan dan provenance; RMIK tidak mengubah teks klinis.',
            'context' => array_merge($task->context ?? [], [
                'responseClosurePublicId' => $closure->public_id,
                'responseClosureVersion' => $closure->version_number,
                'responseContentHash' => $closure->content_hash,
            ]),
            'available_at' => now(),
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
        ])->save();
    }

    private function updateProcedureCorrectionTask(
        ProcedureDocumentationCorrection $correction,
        EncounterClosure $closure,
        Encounter $encounter,
        WorkTaskStatus $status,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $correction->responsible_assignment_id)
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::ProcedureSourceCorrection)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The assigned procedure correction task is missing.');
        }

        $task->fill([
            'status' => $status,
            'description' => $status === WorkTaskStatus::ChangesRequested
                ? 'Supervisor meminta perbaikan lanjutan pada prosedur dalam versi penutupan penerus. Versi sebelumnya tetap utuh.'
                : 'Koreksi hanya boleh mengubah dokumentasi prosedur melalui penutupan penerus; sumber lama dan alasan tetap tersimpan.',
            'context' => array_merge($task->context ?? [], [
                'responseClosurePublicId' => $closure->public_id,
                'responseClosureVersion' => $closure->version_number,
                'responseContentHash' => $closure->content_hash,
            ]),
            'available_at' => now(),
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
        ])->save();
    }

    private function resumeRecordReviewTask(
        RecordCorrectionRequest $correction,
        Encounter $encounter,
        EncounterClosure $closure,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $correction->requested_by_assignment_id)
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::RecordReview)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The requesting RMIK review task is missing.');
        }

        $task->fill([
            'status' => WorkTaskStatus::Ready,
            'description' => 'Versi koreksi telah disetujui supervisor. Jalankan ulang checklist dan verifikasi resolusi temuan.',
            'context' => array_merge($task->context ?? [], [
                'correctionRequestPublicId' => $correction->public_id,
                'correctedClosurePublicId' => $closure->public_id,
                'correctedClosureContentHash' => $closure->content_hash,
            ]),
            'available_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    private function resumeCodingRecordReviewTask(
        CodingDocumentationCorrection $correction,
        Encounter $encounter,
        EncounterClosure $closure,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $correction->record_quality_reviewer_assignment_id)
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::RecordReview)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The exact prior RMIK review task is missing for the coding correction.');
        }

        $task->fill([
            'status' => WorkTaskStatus::Ready,
            'description' => 'Dokumentasi medis dan penutupan penerus telah disetujui. Jalankan ulang checklist sebelum coding dilanjutkan.',
            'context' => array_merge($task->context ?? [], [
                'codingDocumentationCorrectionPublicId' => $correction->public_id,
                'correctedClosurePublicId' => $closure->public_id,
                'correctedClosureContentHash' => $closure->content_hash,
            ]),
            'available_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    private function resumeProcedureCorrectionRecordReviewTask(
        ProcedureDocumentationCorrection $correction,
        Encounter $encounter,
        EncounterClosure $closure,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $correction->record_quality_reviewer_assignment_id)
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::RecordReview)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The exact prior RMIK review task is missing for the procedure correction.');
        }

        $task->fill([
            'status' => WorkTaskStatus::Ready,
            'description' => 'Dokumentasi prosedur dalam penutupan penerus telah disetujui. Jalankan ulang checklist sebelum coding dilanjutkan.',
            'context' => array_merge($task->context ?? [], [
                'procedureDocumentationCorrectionPublicId' => $correction->public_id,
                'correctedClosurePublicId' => $closure->public_id,
                'correctedClosureContentHash' => $closure->content_hash,
            ]),
            'available_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    private function completeReviewTask(
        EncounterClosure $closure,
        Assignment $reviewer,
        Encounter $encounter,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $reviewer->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::EncounterClosureReview)
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->lockForUpdate()
            ->get()
            ->first(fn (WorkTask $candidate): bool => data_get($candidate->context, 'encounterClosurePublicId') === $closure->public_id);

        if (! $task) {
            throw new DomainException('The exact encounter-closure review task is missing.');
        }

        $task->fill([
            'status' => WorkTaskStatus::Complete,
            'completed_at' => now(),
        ])->save();
    }

    private function releaseRecordReviewTasks(EncounterClosure $closure, Encounter $encounter): int
    {
        $assignments = Assignment::query()
            ->active()
            ->where('session_id', $encounter->session_id)
            ->where('patient_id', $encounter->patient_id)
            ->where('encounter_id', $encounter->getKey())
            ->where('program', Program::Rmik)
            ->get()
            ->filter(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::RecordReview));

        if ($assignments->isEmpty()) {
            throw new DomainException('An exact case-scoped RMIK record-review assignment is required before closure approval.');
        }

        foreach ($assignments as $assignment) {
            WorkTask::query()->updateOrCreate(
                [
                    'assignment_id' => $assignment->getKey(),
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::RecordReview,
                ],
                [
                    'session_id' => $encounter->session_id,
                    'title' => 'Telaah Kelengkapan Rekam Medis',
                    'description' => 'Telaah versi penutupan klinis yang disetujui, provenance, kelengkapan, dan kesiapan coding.',
                    'status' => WorkTaskStatus::Ready,
                    'priority' => 1,
                    'source_program' => Program::Medicine,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                        'approvedClosurePublicId' => $closure->public_id,
                        'approvedClosureVersion' => $closure->version_number,
                        'approvedClosureContentHash' => $closure->content_hash,
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ],
            );
        }

        return $assignments->count();
    }

    /** @param array{ready: bool, checks: list<array<string, mixed>>} $readiness */
    private function assertReady(array $readiness): void
    {
        if ($readiness['ready']) {
            return;
        }

        $blockers = collect($readiness['checks'])
            ->filter(fn (array $check): bool => (bool) ($check['blocking'] ?? false))
            ->map(fn (array $check): string => (string) ($check['label'] ?? 'Pemeriksaan readiness'))
            ->implode('; ');

        throw new DomainException('Encounter belum siap ditutup: '.$blockers.'.');
    }

    /** @param array<string, mixed> $payload */
    private function assertSubmitFields(array $payload): void
    {
        foreach ([
            'leaving_condition',
            'disposition',
            'follow_up_plan',
            'education_instructions',
            'outpatient_summary',
        ] as $field) {
            if ($this->nullableText($payload[$field] ?? null) === null) {
                throw new DomainException('A submitted encounter closure requires all core authored closure fields.');
            }
        }

        if (! ProcedureDocumentationState::tryFrom((string) ($payload['procedure_documentation_state'] ?? ''))) {
            throw new DomainException('A submitted encounter closure requires an explicit performed-procedure attestation.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $sourceSnapshot
     * @return array{state: string|null, procedures: list<array<string, mixed>>}
     */
    private function procedureDocumentation(
        array $payload,
        array $sourceSnapshot,
        ?EncounterClosure $latest,
        bool $lockToPriorVersion,
    ): array {
        $stateValue = $this->nullableText($payload['procedure_documentation_state'] ?? null);

        if ($stateValue === null) {
            $documentation = ['state' => null, 'procedures' => []];
            $this->assertProcedureDocumentationUnchangedWhenLocked($documentation, $latest, $lockToPriorVersion);

            return $documentation;
        }

        $state = ProcedureDocumentationState::tryFrom($stateValue);

        if (! $state) {
            throw new DomainException('The performed-procedure attestation is not recognized.');
        }

        $rawProcedures = is_array($payload['procedures'] ?? null)
            ? array_values($payload['procedures'])
            : [];

        if ($state === ProcedureDocumentationState::NonePerformed) {
            if ($rawProcedures !== []) {
                throw new DomainException('A no-procedure attestation cannot include performed-procedure entries.');
            }

            $documentation = ['state' => $state->value, 'procedures' => []];
            $this->assertProcedureDocumentationUnchangedWhenLocked($documentation, $latest, $lockToPriorVersion);

            return $documentation;
        }

        if ($rawProcedures === [] || count($rawProcedures) > 20) {
            throw new DomainException('Recorded procedures require between one and twenty complete performed-procedure entries.');
        }

        $diagnosisIds = $this->snapshotPublicIds($sourceSnapshot['diagnoses'] ?? null, 'publicId');
        $serviceRequestIds = $this->snapshotPublicIds($sourceSnapshot['results'] ?? null, 'serviceRequestPublicId');
        $procedures = [];

        foreach ($rawProcedures as $index => $rawProcedure) {
            if (! is_array($rawProcedure)) {
                throw new DomainException('Each performed procedure must be a structured entry.');
            }

            $authoredText = $this->nullableText($rawProcedure['authored_text'] ?? null);
            $performerText = $this->nullableText($rawProcedure['performer_text'] ?? null);
            $start = $this->parseDateTime($rawProcedure['performed_start_at'] ?? null);
            $end = $this->parseNullableDateTime($rawProcedure['performed_end_at'] ?? null);
            $reasonConditionPublicId = $this->nullableText($rawProcedure['reason_condition_public_id'] ?? null);
            $basedOnServiceRequestPublicId = $this->nullableText($rawProcedure['based_on_service_request_public_id'] ?? null);

            if ($authoredText === null || $performerText === null || $start === null) {
                throw new DomainException('Each performed procedure requires clinician-authored text, performer, and performed time.');
            }

            if ($end?->isBefore($start)) {
                throw new DomainException('A performed procedure cannot end before it starts.');
            }

            if ($reasonConditionPublicId !== null && ! in_array($reasonConditionPublicId, $diagnosisIds, true)) {
                throw new DomainException('A procedure reason must reference a diagnosis from the exact approved medical source.');
            }

            if ($basedOnServiceRequestPublicId !== null && ! in_array($basedOnServiceRequestPublicId, $serviceRequestIds, true)) {
                throw new DomainException('A performed procedure can reference only an order from the exact encounter source snapshot.');
            }

            $procedure = [
                'publicId' => (string) Str::ulid(),
                'sequenceNumber' => $index + 1,
                'status' => ClinicalProcedureStatus::Completed->value,
                'authoredText' => $authoredText,
                'performedStartAt' => $start->toIso8601String(),
                'performedEndAt' => $end?->toIso8601String(),
                'performerText' => $performerText,
                'bodySiteText' => $this->nullableText($rawProcedure['body_site_text'] ?? null),
                'outcomeText' => $this->nullableText($rawProcedure['outcome_text'] ?? null),
                'note' => $this->nullableText($rawProcedure['note'] ?? null),
                'reasonConditionPublicId' => $reasonConditionPublicId,
                'basedOnServiceRequestPublicId' => $basedOnServiceRequestPublicId,
            ];
            $procedure['contentHash'] = hash('sha256', CanonicalJson::encode($procedure));
            $procedures[] = $procedure;
        }

        $documentation = ['state' => $state->value, 'procedures' => $procedures];
        $this->assertProcedureDocumentationUnchangedWhenLocked($documentation, $latest, $lockToPriorVersion);

        return $documentation;
    }

    /** @param array{state: string|null, procedures: list<array<string, mixed>>} $documentation */
    private function assertProcedureDocumentationUnchangedWhenLocked(
        array $documentation,
        ?EncounterClosure $latest,
        bool $locked,
    ): void {
        if (! $locked) {
            return;
        }

        $prior = data_get($latest?->content, 'procedureDocumentation');

        if (! is_array($prior)
            || CanonicalJson::encode($this->procedureClinicalPayload($prior)) !== CanonicalJson::encode($this->procedureClinicalPayload($documentation))) {
            throw new DomainException('Performed-procedure documentation is locked during a diagnosis-source coding correction.');
        }
    }

    /**
     * @param  array<string, string|null>  $authoredContent
     * @param  array{state: string|null, procedures: list<array<string, mixed>>}  $procedureDocumentation
     */
    private function assertProcedureCorrectionScope(
        array $authoredContent,
        array $procedureDocumentation,
        CarbonImmutable $clinicalOccurrenceAt,
        ?ProcedureDocumentationCorrection $correction,
    ): void {
        if (! $correction) {
            return;
        }

        $sourceClosure = $correction->sourceClosure;
        $priorAuthored = data_get($sourceClosure->content, 'authored');
        $priorProcedureDocumentation = data_get($sourceClosure->content, 'procedureDocumentation');

        if (! is_array($priorAuthored)
            || ! is_array($priorProcedureDocumentation)
            || CanonicalJson::encode($priorAuthored) !== CanonicalJson::encode($authoredContent)
            || ! $clinicalOccurrenceAt->equalTo($sourceClosure->clinical_occurrence_at)) {
            throw new DomainException('A procedure-source correction may change only performed-procedure documentation; other closure content and occurrence time are locked.');
        }

        if (CanonicalJson::encode($this->procedureClinicalPayload($priorProcedureDocumentation))
            === CanonicalJson::encode($this->procedureClinicalPayload($procedureDocumentation))) {
            throw new DomainException('A procedure-source correction must change the performed-procedure documentation.');
        }
    }

    /**
     * @param  array<string, mixed>  $documentation
     * @return array<string, mixed>
     */
    private function procedureClinicalPayload(array $documentation): array
    {
        $procedures = [];
        $documentedProcedures = $documentation['procedures'] ?? [];

        if (is_array($documentedProcedures)) {
            foreach ($documentedProcedures as $procedure) {
                if (! is_array($procedure)) {
                    continue;
                }

                $procedures[] = [
                    'sequenceNumber' => $procedure['sequenceNumber'] ?? null,
                    'status' => $procedure['status'] ?? null,
                    'authoredText' => $procedure['authoredText'] ?? null,
                    'performedStartAt' => $procedure['performedStartAt'] ?? null,
                    'performedEndAt' => $procedure['performedEndAt'] ?? null,
                    'performerText' => $procedure['performerText'] ?? null,
                    'bodySiteText' => $procedure['bodySiteText'] ?? null,
                    'outcomeText' => $procedure['outcomeText'] ?? null,
                    'note' => $procedure['note'] ?? null,
                    'reasonConditionPublicId' => $procedure['reasonConditionPublicId'] ?? null,
                    'basedOnServiceRequestPublicId' => $procedure['basedOnServiceRequestPublicId'] ?? null,
                ];
            }
        }

        return [
            'state' => $documentation['state'] ?? null,
            'procedures' => $procedures,
        ];
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new DomainException('A performed-procedure time is invalid.');
        }
    }

    /** @return list<string> */
    private function snapshotPublicIds(mixed $items, string $key): array
    {
        if (! is_array($items)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            if (is_array($item) && is_string($item[$key] ?? null) && $item[$key] !== '') {
                $ids[] = $item[$key];
            }
        }

        return $ids;
    }

    private function parseNullableDateTime(mixed $value): ?CarbonImmutable
    {
        return $this->nullableText($value) === null ? null : $this->parseDateTime($value);
    }

    /** @param class-string<ClinicalCondition|ServiceRequest> $model */
    private function modelIdByPublicId(string $model, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = $model::query()->where('public_id', $publicId)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function assertAssignment(
        ?Assignment $assignment,
        Encounter $encounter,
        SimulationSession $session,
        Capability $capability,
    ): void {
        if (! $assignment
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $assignment->session_id !== $session->getKey()
            || $assignment->patient_id !== $encounter->patient_id
            || $assignment->encounter_id !== $encounter->getKey()
            || ! $assignment->hasCapability($capability)) {
            throw new DomainException('The active assignment does not permit this closure action in the exact case context.');
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
