<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Services\ApprovedAllergyAssessmentResolver;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalReviewWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly AuditRecorder $auditRecorder,
        private readonly ApprovedAllergyAssessmentResolver $allergyResolver,
    ) {}

    public function __invoke(Request $request, ClinicalEntryVersion $version): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $version->load([
            'clinicalEntry.encounter.patient.identifiers',
            'clinicalEntry.encounter.location',
            'clinicalEntry.encounter.session.scenario',
            'author',
            'authorAssignment',
            'observations',
            'conditions',
            'serviceRequests.sourceCondition',
            'medicationRequests.sourceCondition',
            'allergyAssessment',
            'reviewActions.actorAssignment.user',
        ]);
        $encounter = $version->clinicalEntry->encounter;
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::SupervisionReview);

        if ($version->authorAssignment->supervisor_assignment_id !== $assignment->getKey()) {
            abort(403, 'This submitted version is assigned to another supervisor context.');
        }

        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $allergyAssessment = $version->allergyAssessment
            ?? $this->allergyResolver->forEncounter($encounter);

        $this->auditRecorder->record(
            action: 'clinical.review_workspace_viewed',
            resourceType: 'clinical_entry_version',
            resourceId: $version->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: ['content_hash' => $version->content_hash],
            request: $request,
        );

        return Inertia::render('clinical/review', [
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
            'document' => [
                'entryPublicId' => $version->clinicalEntry->public_id,
                'versionPublicId' => $version->public_id,
                'type' => $version->clinicalEntry->document_type->value,
                'label' => $version->clinicalEntry->document_type->label(),
                'versionNumber' => $version->version_number,
                'schemaVersion' => $version->schema_version,
                'content' => $version->content,
                'contentHash' => $version->content_hash,
                'status' => [
                    'code' => $version->status->value,
                    'label' => $version->status->label(),
                ],
                'author' => $version->author->name,
                'authorRole' => $version->authorAssignment->application_role->label(),
                'clinicalOccurrenceAt' => $version->clinical_occurrence_at->toIso8601String(),
                'recordedAt' => $version->recorded_at->toIso8601String(),
                'observations' => $version->observations->map(fn ($observation): array => [
                    'codeSystem' => $observation->code_system,
                    'code' => $observation->code,
                    'display' => $observation->display,
                    'value' => $observation->value_numeric,
                    'unitCode' => $observation->unit_code,
                    'unitDisplay' => $observation->unit_display,
                    'mappingVersion' => $observation->mapping_version,
                ])->values()->all(),
                'conditions' => $version->conditions->map(fn (ClinicalCondition $condition): array => [
                    'publicId' => $condition->public_id,
                    'authoredText' => $condition->authored_text,
                    'certainty' => [
                        'code' => $condition->certainty->value,
                        'label' => $condition->certainty->label(),
                    ],
                    'role' => [
                        'code' => $condition->role->value,
                        'label' => $condition->role->label(),
                    ],
                    'clinicalStatus' => $condition->clinical_status,
                    'codeSystem' => $condition->code_system,
                    'code' => $condition->code,
                    'display' => $condition->display,
                    'codeVersion' => $condition->code_version,
                    'onsetAt' => $condition->onset_at?->toIso8601String(),
                    'recordedAt' => $condition->recorded_at->toIso8601String(),
                ])->values()->all(),
                'serviceRequests' => $version->serviceRequests->map(fn ($serviceRequest): array => [
                    'publicId' => $serviceRequest->public_id,
                    'requestType' => $serviceRequest->request_type,
                    'authoredService' => $serviceRequest->authored_service,
                    'clinicalQuestion' => $serviceRequest->clinical_question,
                    'priority' => $serviceRequest->priority,
                    'status' => $serviceRequest->status->value,
                    'sourceDiagnosis' => $serviceRequest->sourceCondition?->authored_text,
                ])->values()->all(),
                'medicationRequests' => $version->medicationRequests->map(fn ($medicationRequest): array => [
                    'publicId' => $medicationRequest->public_id,
                    'authoredMedication' => $medicationRequest->authored_medication,
                    'form' => $medicationRequest->form,
                    'strength' => $medicationRequest->strength,
                    'doseValue' => $medicationRequest->dose_value,
                    'doseUnit' => $medicationRequest->dose_unit,
                    'route' => $medicationRequest->route,
                    'frequency' => $medicationRequest->frequency,
                    'duration' => $medicationRequest->duration,
                    'quantityValue' => $medicationRequest->quantity_value,
                    'quantityUnit' => $medicationRequest->quantity_unit,
                    'directions' => $medicationRequest->directions,
                    'indicationText' => $medicationRequest->indication_text,
                    'status' => $medicationRequest->status->value,
                    'sourceDiagnosis' => $medicationRequest->sourceCondition?->authored_text,
                ])->values()->all(),
                'reviews' => $version->reviewActions->map(fn ($review): array => [
                    'publicId' => $review->public_id,
                    'action' => [
                        'code' => $review->action->value,
                        'label' => $review->action->label(),
                    ],
                    'actor' => $review->actorAssignment->user->name,
                    'comment' => $review->comment,
                    'findings' => $review->findings,
                    'occurredAt' => $review->occurred_at->toIso8601String(),
                ])->values()->all(),
                'canReview' => $version->status->value === 'SUBMITTED',
            ],
            'formOptions' => [
                'requestKey' => (string) Str::ulid(),
                'actions' => [
                    ClinicalReviewAction::RequestChanges->value,
                    ClinicalReviewAction::ApproveSimulation->value,
                ],
            ],
            'urls' => [
                'storeDecision' => route('clinical-versions.review-decisions.store', $version),
                'encounter' => route('encounters.show', $encounter),
                'workQueue' => route('work'),
            ],
        ]);
    }
}
