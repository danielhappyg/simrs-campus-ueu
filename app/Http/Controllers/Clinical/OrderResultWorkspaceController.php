<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrderResultWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
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
            Capability::MedicalAssessmentWrite,
            Capability::SessionFacilitate,
            Capability::SupervisionReview,
        ]);
        $serviceRequests = ServiceRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with([
                'sourceEntryVersion',
                'sourceCondition',
                'requester',
                'results' => fn ($query) => $query
                    ->with(['performer', 'acknowledgements.actor'])
                    ->orderByDesc('version_number'),
            ])
            ->orderBy('sequence_number')
            ->get();
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $canRelease = $assignment->hasCapability(Capability::SessionFacilitate)
            || $assignment->hasCapability(Capability::SupervisionReview);
        $canAcknowledge = $assignment->hasCapability(Capability::MedicalAssessmentWrite);

        $this->auditRecorder->record(
            action: 'clinical.order_result_workspace_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            request: $request,
        );

        return Inertia::render('clinical/order-results', [
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
                'canRelease' => $canRelease,
                'canAcknowledge' => $canAcknowledge,
            ],
            'serviceRequests' => $serviceRequests->map(function (ServiceRequest $serviceRequest) use ($assignment, $canRelease, $canAcknowledge): array {
                /** @var DiagnosticResult|null $currentResult */
                $currentResult = $serviceRequest->results->first();

                return [
                    'publicId' => $serviceRequest->public_id,
                    'sequenceNumber' => $serviceRequest->sequence_number,
                    'requestType' => $serviceRequest->request_type,
                    'authoredService' => $serviceRequest->authored_service,
                    'clinicalQuestion' => $serviceRequest->clinical_question,
                    'priority' => $serviceRequest->priority,
                    'status' => $serviceRequest->status->value,
                    'authoredAt' => $serviceRequest->authored_at->toIso8601String(),
                    'requester' => $serviceRequest->requester->name,
                    'source' => [
                        'medicalVersionPublicId' => $serviceRequest->sourceEntryVersion->public_id,
                        'medicalVersionNumber' => $serviceRequest->sourceEntryVersion->version_number,
                        'medicalContentHash' => $serviceRequest->sourceEntryVersion->content_hash,
                        'diagnosis' => $serviceRequest->sourceCondition?->authored_text,
                    ],
                    'release' => [
                        'allowed' => $canRelease,
                        'requestKey' => (string) Str::ulid(),
                        'url' => route('service-requests.results.store', $serviceRequest),
                    ],
                    'currentResultPublicId' => $currentResult?->public_id,
                    'results' => $serviceRequest->results->map(fn (DiagnosticResult $result): array => [
                        'publicId' => $result->public_id,
                        'versionNumber' => $result->version_number,
                        'status' => [
                            'code' => $result->status->value,
                            'label' => $result->status->label(),
                        ],
                        'reportCode' => $result->report_code,
                        'reportDisplay' => $result->report_display,
                        'content' => $result->content,
                        'contentHash' => $result->content_hash,
                        'effectiveAt' => $result->effective_at->toIso8601String(),
                        'issuedAt' => $result->issued_at->toIso8601String(),
                        'performer' => $result->performer->name,
                        'current' => $currentResult?->getKey() === $result->getKey(),
                        'acknowledgements' => $result->acknowledgements->map(fn ($acknowledgement): array => [
                            'publicId' => $acknowledgement->public_id,
                            'actor' => $acknowledgement->actor->name,
                            'outcome' => $acknowledgement->outcome,
                            'comment' => $acknowledgement->comment,
                            'acknowledgedAt' => $acknowledgement->acknowledged_at->toIso8601String(),
                        ])->values()->all(),
                        'acknowledgement' => [
                            'allowed' => $canAcknowledge
                                && $assignment->getKey() === $serviceRequest->requester_assignment_id
                                && $currentResult?->getKey() === $result->getKey()
                                && ! $result->acknowledgements->contains('actor_assignment_id', $assignment->getKey()),
                            'requestKey' => (string) Str::ulid(),
                            'url' => route('diagnostic-results.acknowledgements.store', $result),
                        ],
                    ])->values()->all(),
                ];
            })->values()->all(),
            'urls' => [
                'encounter' => route('encounters.show', $encounter),
                'workQueue' => route('work'),
            ],
        ]);
    }
}
