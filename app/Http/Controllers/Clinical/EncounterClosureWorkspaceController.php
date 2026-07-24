<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Services\ApprovedAllergyAssessmentResolver;
use App\Modules\Clinical\Services\ClosureReadinessService;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\RecordQuality\Enums\RecordCorrectionStatus;
use App\Modules\RecordQuality\Models\RecordCorrectionRequest;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EncounterClosureWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly ClosureReadinessService $readinessService,
        private readonly AuditRecorder $auditRecorder,
        private readonly ApprovedAllergyAssessmentResolver $allergyResolver,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['patient.identifiers', 'session.scenario', 'location']);
        $assignment = $this->assignmentResolver->forEncounterAny($user, $encounter, [
            Capability::MedicalAssessmentWrite,
            Capability::SupervisionReview,
        ]);
        $closures = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['author', 'authorAssignment', 'reviewActions.reviewer'])
            ->orderByDesc('version_number')
            ->limit(20)
            ->get();
        /** @var EncounterClosure|null $latest */
        $latest = $closures->first();
        $correction = RecordCorrectionRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('responsible_assignment_id', $assignment->getKey())
            ->whereIn('status', [
                RecordCorrectionStatus::Open->value,
                RecordCorrectionStatus::ResponseSubmitted->value,
            ])
            ->with('finding')
            ->orderByDesc('requested_at')
            ->first();
        $codingCorrection = CodingDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('responsible_assignment_id', $assignment->getKey())
            ->whereIn('status', [
                CodingDocumentationCorrectionStatus::MedicalApproved->value,
                CodingDocumentationCorrectionStatus::ClosureResponseSubmitted->value,
            ])
            ->with(['requestedBy', 'responseMedicalVersion'])
            ->orderByDesc('requested_at')
            ->first();
        $procedureCorrection = ProcedureDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('responsible_assignment_id', $assignment->getKey())
            ->whereIn('status', [
                ProcedureDocumentationCorrectionStatus::Open->value,
                ProcedureDocumentationCorrectionStatus::ClosureResponseSubmitted->value,
            ])
            ->with(['requestedBy', 'sourceProcedure', 'sourceClosure'])
            ->orderByDesc('requested_at')
            ->first();
        $task = $encounter->workTasks()
            ->where('assignment_id', $assignment->getKey())
            ->whereIn('task_type', [
                WorkTaskType::EncounterClosure,
                WorkTaskType::EncounterClosureReview,
                WorkTaskType::RecordCorrection,
                WorkTaskType::ProcedureSourceCorrection,
            ])
            ->orderByDesc('id')
            ->first();
        $readiness = $this->readinessService->evaluate($encounter);
        $sourceSnapshot = $this->readinessService->sourceSnapshot($encounter);
        $allergyAssessment = $this->allergyResolver->forEncounter($encounter);
        $diagnosisOptions = [];

        foreach ($sourceSnapshot['diagnoses'] as $diagnosis) {
            if (is_array($diagnosis)
                && is_string($diagnosis['publicId'] ?? null)
                && is_string($diagnosis['authoredText'] ?? null)) {
                $diagnosisOptions[] = [
                    'publicId' => $diagnosis['publicId'],
                    'label' => $diagnosis['authoredText'],
                ];
            }
        }

        $serviceRequestOptions = [];

        foreach ($sourceSnapshot['results'] as $result) {
            if (is_array($result)
                && is_string($result['serviceRequestPublicId'] ?? null)
                && is_string($result['authoredService'] ?? null)) {
                $serviceRequestOptions[] = [
                    'publicId' => $result['serviceRequestPublicId'],
                    'label' => $result['authoredService'],
                ];
            }
        }
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $canAuthor = $assignment->hasCapability(Capability::MedicalAssessmentWrite)
            && (($encounter->status === EncounterStatus::InConsultation
                && (! $latest || in_array($latest->status, [EncounterClosureStatus::Draft, EncounterClosureStatus::ChangesRequested], true)))
                || ($encounter->status === EncounterStatus::AmendmentPending
                    && collect([$correction, $codingCorrection, $procedureCorrection])->filter()->count() === 1
                    && $latest !== null
                    && in_array($latest->status, [EncounterClosureStatus::Approved, EncounterClosureStatus::ChangesRequested], true)));
        $canReview = $assignment->hasCapability(Capability::SupervisionReview)
            && in_array($encounter->status, [EncounterStatus::ClosurePending, EncounterStatus::AmendmentPending], true)
            && $latest?->status === EncounterClosureStatus::Submitted
            && $latest->authorAssignment->supervisor_assignment_id === $assignment->getKey();

        $this->auditRecorder->record(
            action: 'clinical.encounter_closure_workspace_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            request: $request,
            metadata: [
                'readiness_passed' => $readiness['ready'],
                'latest_closure_public_id' => $latest?->public_id,
                'procedure_documentation_correction_public_id' => $procedureCorrection?->public_id,
            ],
        );

        return Inertia::render('clinical/closure', [
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
                'allergyStatus' => $this->allergyResolver->label($allergyAssessment),
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
            ],
            'task' => $task ? [
                'publicId' => $task->public_id,
                'type' => $task->task_type->value,
                'status' => [
                    'code' => $task->status->value,
                    'label' => $task->status->label(),
                ],
            ] : null,
            'readiness' => $readiness,
            'correctionRequest' => $correction ? [
                'publicId' => $correction->public_id,
                'status' => [
                    'code' => $correction->status->value,
                    'label' => $correction->status->label(),
                ],
                'reason' => $correction->reason,
                'requestedSourceHash' => $correction->requested_source_hash,
                'finding' => [
                    'code' => $correction->finding->code,
                    'severity' => $correction->finding->severity->value,
                    'message' => $correction->finding->message,
                    'requestedAction' => $correction->finding->requested_action,
                ],
            ] : null,
            'codingCorrection' => $codingCorrection ? [
                'publicId' => $codingCorrection->public_id,
                'status' => [
                    'code' => $codingCorrection->status->value,
                    'label' => $codingCorrection->status->label(),
                ],
                'reason' => $codingCorrection->reason,
                'requestedBy' => $codingCorrection->requestedBy->name,
                'requestedSourceHash' => $codingCorrection->requested_source_hash,
                'responseMedicalVersionPublicId' => $codingCorrection->responseMedicalVersion?->public_id,
                'responseMedicalVersionNumber' => $codingCorrection->responseMedicalVersion?->version_number,
                'responseMedicalContentHash' => $codingCorrection->responseMedicalVersion?->content_hash,
            ] : null,
            'procedureCodingCorrection' => $procedureCorrection ? [
                'publicId' => $procedureCorrection->public_id,
                'status' => [
                    'code' => $procedureCorrection->status->value,
                    'label' => $procedureCorrection->status->label(),
                ],
                'reason' => $procedureCorrection->reason,
                'requestedBy' => $procedureCorrection->requestedBy->name,
                'sourceProcedurePublicId' => $procedureCorrection->sourceProcedure->public_id,
                'sourceStatement' => $procedureCorrection->sourceProcedure->authored_text,
                'sourceClosurePublicId' => $procedureCorrection->sourceClosure->public_id,
                'requestedSourceHash' => $procedureCorrection->requested_source_hash,
                'requestedClosureHash' => $procedureCorrection->requested_closure_hash,
            ] : null,
            'sourceSnapshot' => $sourceSnapshot,
            'document' => [
                'schemaVersion' => 'encounter-closure.v2',
                'latestVersion' => $latest ? $this->versionPayload($latest) : null,
                'history' => $closures->map(fn (EncounterClosure $closure): array => $this->versionPayload($closure))->values()->all(),
                'canAuthor' => $canAuthor,
                'canReview' => $canReview,
                'requiresChangeReason' => $latest?->status === EncounterClosureStatus::ChangesRequested
                    || ($encounter->status === EncounterStatus::AmendmentPending
                        && ($correction !== null || $codingCorrection !== null || $procedureCorrection !== null)),
                'authoredFieldsLocked' => $procedureCorrection !== null,
            ],
            'formOptions' => [
                'requestKey' => (string) Str::ulid(),
                'reviewRequestKey' => (string) Str::ulid(),
                'defaultOccurrenceAt' => now('Asia/Jakarta')->format('Y-m-d\TH:i'),
                'intents' => collect(ClinicalSaveIntent::cases())->map(fn (ClinicalSaveIntent $intent): string => $intent->value)->all(),
                'procedureDocumentationStates' => collect(ProcedureDocumentationState::cases())->map(
                    fn (ProcedureDocumentationState $state): array => [
                        'code' => $state->value,
                        'label' => $state->label(),
                    ],
                )->all(),
                'diagnosisOptions' => $diagnosisOptions,
                'serviceRequestOptions' => $serviceRequestOptions,
                'reviewActions' => collect(EncounterClosureReviewAction::cases())->map(fn (EncounterClosureReviewAction $action): array => [
                    'code' => $action->value,
                    'label' => $action->label(),
                ])->all(),
            ],
            'urls' => [
                'store' => route('encounters.closure.versions.store', $encounter),
                'review' => $latest ? route('encounter-closures.review-decisions.store', $latest) : null,
                'encounter' => route('encounters.show', $encounter),
                'workQueue' => route('work'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function versionPayload(EncounterClosure $closure): array
    {
        return [
            'publicId' => $closure->public_id,
            'versionNumber' => $closure->version_number,
            'schemaVersion' => $closure->schema_version,
            'content' => $closure->content,
            'contentHash' => $closure->content_hash,
            'status' => [
                'code' => $closure->status->value,
                'label' => $closure->status->label(),
            ],
            'author' => $closure->author->name,
            'authorRole' => $closure->authorAssignment->application_role->label(),
            'clinicalOccurrenceAt' => $closure->clinical_occurrence_at->toIso8601String(),
            'recordedAt' => $closure->recorded_at->toIso8601String(),
            'submittedAt' => $closure->submitted_at?->toIso8601String(),
            'reviewedAt' => $closure->reviewed_at?->toIso8601String(),
            'changeReason' => $closure->change_reason,
            'reviews' => $closure->reviewActions->map(fn ($review): array => [
                'publicId' => $review->public_id,
                'action' => [
                    'code' => $review->action->value,
                    'label' => $review->action->label(),
                ],
                'reviewer' => $review->reviewer->name,
                'comment' => $review->comment,
                'findings' => $review->findings,
                'reviewedContentHash' => $review->reviewed_content_hash,
                'reviewedAt' => $review->reviewed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
