<?php

namespace App\Http\Controllers\RecordQuality;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\RecordQuality\Enums\RecordCorrectionStatus;
use App\Modules\RecordQuality\Enums\RecordQualityFindingSeverity;
use App\Modules\RecordQuality\Enums\RecordQualityReviewAction;
use App\Modules\RecordQuality\Enums\RecordQualityReviewStatus;
use App\Modules\RecordQuality\Models\RecordCorrectionRequest;
use App\Modules\RecordQuality\Models\RecordQualityFinding;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Services\RecordCompletenessService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RecordQualityWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly RecordCompletenessService $completenessService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['patient.identifiers', 'session.scenario', 'location']);
        $assignment = $this->assignmentResolver->forEncounterAny($user, $encounter, [
            Capability::RecordReview,
            Capability::SupervisionReview,
        ]);
        $reviews = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->with([
                'reviewer',
                'reviewerAssignment',
                'findings.responsibleAssignment.user',
                'findings.correctionRequest.responseClosure',
                'reviewActions.reviewer',
            ])
            ->orderByDesc('version_number')
            ->limit(20)
            ->get();
        /** @var RecordQualityReview|null $latest */
        $latest = $reviews->first();
        $closure = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();
        $corrections = RecordCorrectionRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['finding', 'responsibleAssignment.user', 'responseClosure'])
            ->orderByDesc('requested_at')
            ->get();
        $codingCorrection = CodingDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('record_quality_reviewer_assignment_id', $assignment->getKey())
            ->where('status', CodingDocumentationCorrectionStatus::ReadyForRmik)
            ->with(['requestedBy', 'responsibleUser', 'responseMedicalVersion', 'responseClosure'])
            ->first();
        $procedureCorrection = ProcedureDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('record_quality_reviewer_assignment_id', $assignment->getKey())
            ->where('status', ProcedureDocumentationCorrectionStatus::ReadyForRmik)
            ->with(['requestedBy', 'responsibleUser', 'responseClosure'])
            ->first();

        if ($codingCorrection && $procedureCorrection) {
            abort(409, 'Only one coding documentation correction can be reviewed at a time.');
        }

        $activeCodingCorrection = $codingCorrection ?? $procedureCorrection;
        $task = $encounter->workTasks()
            ->where('assignment_id', $assignment->getKey())
            ->whereIn('task_type', [WorkTaskType::RecordReview, WorkTaskType::RecordQualityReview])
            ->orderByDesc('id')
            ->first();
        $completeness = $this->completenessService->evaluate($encounter);
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $canAuthor = $assignment->hasCapability(Capability::RecordReview)
            && in_array($encounter->status, [EncounterStatus::ClinicallyClosed, EncounterStatus::RecordReview], true)
            && (! $latest || in_array($latest->status, [
                RecordQualityReviewStatus::Draft,
                RecordQualityReviewStatus::ChangesRequested,
                ...($activeCodingCorrection ? [RecordQualityReviewStatus::Approved] : []),
            ], true));
        $canReview = $assignment->hasCapability(Capability::SupervisionReview)
            && $encounter->status === EncounterStatus::RecordReview
            && $latest?->status === RecordQualityReviewStatus::Submitted
            && $latest->reviewerAssignment->supervisor_assignment_id === $assignment->getKey();
        $resolvableCorrections = $corrections
            ->where('requested_by_assignment_id', $assignment->getKey())
            ->where('status', RecordCorrectionStatus::CorrectedPendingVerification);

        $this->auditRecorder->record(
            action: 'record_quality.workspace_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            request: $request,
            metadata: [
                'checklist_version' => $completeness['checklistVersion'],
                'completeness_ready' => $completeness['ready'],
                'review_count' => $reviews->count(),
                'correction_count' => $corrections->count(),
                'coding_documentation_correction_public_id' => $codingCorrection?->public_id,
                'procedure_documentation_correction_public_id' => $procedureCorrection?->public_id,
            ],
        );

        return Inertia::render('record-quality/workspace', [
            'encounter' => [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'status' => [
                    'code' => $encounter->status->value,
                    'label' => $encounter->status->label(),
                ],
                'serviceType' => $encounter->service_type_display,
                'location' => $encounter->location->name,
                'periodStart' => $encounter->period_start?->toIso8601String(),
                'environmentMode' => $encounter->environment_mode->value,
            ],
            'patient' => [
                'publicId' => $encounter->patient->public_id,
                'fullName' => $encounter->patient->full_name,
                'mrn' => $mrn?->value,
                'birthDate' => $encounter->patient->birth_date->toDateString(),
                'administrativeSex' => $encounter->patient->administrative_sex->label(),
                'allergyStatus' => 'Lihat asesmen awal yang disetujui',
                'synthetic' => true,
            ],
            'session' => [
                'code' => $encounter->session->code,
                'scenarioTitle' => $encounter->session->scenario->title,
            ],
            'assignment' => [
                'publicId' => $assignment->public_id,
                'program' => $assignment->program->label(),
                'role' => $assignment->application_role->label(),
                'canAuthor' => $canAuthor,
                'canReview' => $canReview,
                'canCode' => $assignment->hasCapability(Capability::CodingWrite),
            ],
            'task' => $task ? [
                'publicId' => $task->public_id,
                'type' => $task->task_type->value,
                'status' => [
                    'code' => $task->status->value,
                    'label' => $task->status->label(),
                ],
            ] : null,
            'completeness' => $completeness,
            'assembly' => $this->completenessService->assemblySnapshot($encounter),
            'currentClosure' => $closure ? [
                'publicId' => $closure->public_id,
                'versionNumber' => $closure->version_number,
                'status' => [
                    'code' => $closure->status->value,
                    'label' => $closure->status->label(),
                ],
                'contentHash' => $closure->content_hash,
                'authorAssignmentPublicId' => $closure->authorAssignment()->value('public_id'),
            ] : null,
            'document' => [
                'latestVersion' => $latest ? $this->reviewPayload($latest, $canAuthor) : null,
                'history' => $reviews->map(fn (RecordQualityReview $review): array => $this->reviewPayload($review, $canAuthor))->values()->all(),
                'canAuthor' => $canAuthor,
                'canReview' => $canReview,
                'requiresChangeReason' => $activeCodingCorrection !== null
                    || $latest?->status === RecordQualityReviewStatus::ChangesRequested,
            ],
            'codingCorrection' => $activeCodingCorrection ? [
                'publicId' => $activeCodingCorrection->public_id,
                'status' => [
                    'code' => $activeCodingCorrection->status->value,
                    'label' => $activeCodingCorrection->status->label(),
                ],
                'sourceType' => $codingCorrection
                    ? CodingSourceType::Diagnosis->value
                    : CodingSourceType::Procedure->value,
                'reason' => $activeCodingCorrection->reason,
                'requestedBy' => $activeCodingCorrection->requestedBy->name,
                'responsibleAuthor' => $activeCodingCorrection->responsibleUser->name,
                'requestedSourceHash' => $activeCodingCorrection->requested_source_hash,
                'requestedClosureHash' => $procedureCorrection?->requested_closure_hash,
                'responseMedicalVersionPublicId' => $codingCorrection?->responseMedicalVersion?->public_id,
                'responseMedicalContentHash' => $codingCorrection?->responseMedicalVersion?->content_hash,
                'responseClosurePublicId' => $activeCodingCorrection->responseClosure?->public_id,
                'responseClosureContentHash' => $activeCodingCorrection->responseClosure?->content_hash,
            ] : null,
            'corrections' => $corrections->map(fn (RecordCorrectionRequest $correction): array => [
                'publicId' => $correction->public_id,
                'status' => [
                    'code' => $correction->status->value,
                    'label' => $correction->status->label(),
                ],
                'reason' => $correction->reason,
                'requestedSourceHash' => $correction->requested_source_hash,
                'requestedAt' => $correction->requested_at->toIso8601String(),
                'findingCode' => $correction->finding->code,
                'findingMessage' => $correction->finding->message,
                'responsibleAuthor' => $correction->responsibleAssignment->user->name,
                'responseClosurePublicId' => $correction->responseClosure?->public_id,
                'responseClosureVersion' => $correction->responseClosure?->version_number,
                'responseContentHash' => $correction->responseClosure?->content_hash,
                'resolvable' => $resolvableCorrections->contains('id', $correction->getKey()),
            ])->values()->all(),
            'formOptions' => [
                'requestKey' => (string) Str::ulid(),
                'reviewRequestKey' => (string) Str::ulid(),
                'correctionRequestKey' => (string) Str::ulid(),
                'intents' => collect(ClinicalSaveIntent::cases())->map(fn (ClinicalSaveIntent $intent): string => $intent->value)->all(),
                'findingSeverities' => collect(RecordQualityFindingSeverity::cases())->map(fn (RecordQualityFindingSeverity $severity): array => [
                    'code' => $severity->value,
                    'label' => $severity->label(),
                ])->all(),
                'reviewActions' => collect(RecordQualityReviewAction::cases())->map(fn (RecordQualityReviewAction $action): array => [
                    'code' => $action->value,
                    'label' => $action->label(),
                ])->all(),
            ],
            'urls' => [
                'store' => route('encounters.record-quality.reviews.store', $encounter),
                'review' => $latest ? route('record-quality-reviews.decisions.store', $latest) : null,
                'encounter' => route('encounters.show', $encounter),
                'closure' => route('encounters.closure.show', $encounter),
                'coding' => route('encounters.coding.show', $encounter),
                'workQueue' => route('work'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewPayload(RecordQualityReview $review, bool $canAuthor): array
    {
        return [
            'publicId' => $review->public_id,
            'versionNumber' => $review->version_number,
            'checklistVersion' => $review->checklist_version,
            'status' => [
                'code' => $review->status->value,
                'label' => $review->status->label(),
            ],
            'content' => $review->content,
            'contentHash' => $review->content_hash,
            'reviewer' => $review->reviewer->name,
            'reviewerRole' => $review->reviewerAssignment->application_role->label(),
            'recordedAt' => $review->recorded_at->toIso8601String(),
            'submittedAt' => $review->submitted_at?->toIso8601String(),
            'reviewedAt' => $review->reviewed_at?->toIso8601String(),
            'changeReason' => $review->change_reason,
            'findings' => $review->findings->map(fn (RecordQualityFinding $finding): array => [
                'publicId' => $finding->public_id,
                'code' => $finding->code,
                'severity' => [
                    'code' => $finding->severity->value,
                    'label' => $finding->severity->label(),
                ],
                'message' => $finding->message,
                'affectedResourceType' => $finding->affected_resource_type,
                'affectedResourcePublicId' => $finding->affected_resource_public_id,
                'affectedVersionNumber' => $finding->affected_version_number,
                'affectedContentHash' => $finding->affected_content_hash,
                'responsibleAuthor' => $finding->responsibleAssignment->user->name,
                'requestedAction' => $finding->requested_action,
                'correctionRequest' => $finding->correctionRequest ? [
                    'publicId' => $finding->correctionRequest->public_id,
                    'status' => [
                        'code' => $finding->correctionRequest->status->value,
                        'label' => $finding->correctionRequest->status->label(),
                    ],
                ] : null,
                'canRequestCorrection' => $canAuthor
                    && $review->status === RecordQualityReviewStatus::Draft
                    && $finding->correctionRequest === null,
                'correctionRequestKey' => (string) Str::ulid(),
                'correctionUrl' => route('record-quality-findings.corrections.store', $finding),
            ])->values()->all(),
            'reviews' => $review->reviewActions->map(fn ($action): array => [
                'publicId' => $action->public_id,
                'action' => [
                    'code' => $action->action->value,
                    'label' => $action->action->label(),
                ],
                'reviewer' => $action->reviewer->name,
                'comment' => $action->comment,
                'findings' => $action->findings,
                'reviewedContentHash' => $action->reviewed_content_hash,
                'reviewedAt' => $action->reviewed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
