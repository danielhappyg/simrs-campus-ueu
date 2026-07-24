<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\OutpatientEarlyDepartureOutcome;
use App\Modules\Clinical\Models\OutpatientEarlyDeparture;
use App\Modules\Clinical\Services\ApprovedAllergyAssessmentResolver;
use App\Modules\Clinical\Services\OutpatientEarlyDepartureService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class OutpatientEarlyDepartureWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly OutpatientEarlyDepartureService $departureService,
        private readonly AuditRecorder $auditRecorder,
        private readonly ApprovedAllergyAssessmentResolver $allergyResolver,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['patient.identifiers', 'session.scenario', 'location', 'appointment']);
        $assignment = $this->assignmentResolver->forEarlyDeparture($user, $encounter);
        $departure = OutpatientEarlyDeparture::query()
            ->with(['actor', 'actorAssignment'])
            ->where('encounter_id', $encounter->getKey())
            ->first();

        if (! $departure
            && ! in_array($encounter->status, OutpatientEarlyDepartureService::ALLOWED_SOURCE_STATES, true)) {
            abort(409, 'Pulang atas permintaan sendiri tidak tersedia pada tahap encounter ini.');
        }

        $canRecord = ! $departure;

        if ($departure) {
            $sourceSnapshot = $departure->source_snapshot;
            $sourceStatus = $departure->source_encounter_status;
        } else {
            $sourceSnapshot = $this->departureService->sourceSnapshot($encounter);
            $sourceStatus = $encounter->status;
        }

        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $allergyAssessment = $this->allergyResolver->forEncounter($encounter);

        $this->auditRecorder->record(
            action: 'clinical.outpatient_early_departure_workspace_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'encounter_status' => $encounter->status->value,
                'source_encounter_status' => $sourceStatus->value,
                'source_count' => count($sourceSnapshot['clinicalSources'] ?? []),
                'departure_recorded' => $departure !== null,
            ],
            request: $request,
        );

        $response = Inertia::render('clinical/early-departure', [
            'boundary' => [
                'classification' => 'SIMULASI — DATA SINTETIS',
                'clinicalRecommendation' => false,
                'automaticFinalization' => false,
            ],
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
                'periodEnd' => $encounter->period_end?->toIso8601String(),
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
            'source' => [
                'encounterStatus' => [
                    'code' => $sourceStatus->value,
                    'label' => $sourceStatus->label(),
                ],
                'clinicalSources' => $sourceSnapshot['clinicalSources'] ?? [],
                'snapshotHash' => $departure?->source_snapshot_hash,
            ],
            'authorization' => [
                'assignmentPublicId' => $assignment->public_id,
                'role' => $assignment->application_role->label(),
                'canRecord' => $canRecord,
            ],
            'departure' => $departure ? [
                'publicId' => $departure->public_id,
                'outcome' => [
                    'code' => $departure->outcome->value,
                    'label' => $departure->outcome->label(),
                    'interoperabilityCode' => $departure->outcome->interoperabilityCode(),
                ],
                'statedReason' => $departure->stated_reason,
                'communicationSummary' => $departure->communication_summary,
                'actor' => $departure->actor->name,
                'role' => $departure->actorAssignment->application_role->label(),
                'occurredAt' => $departure->occurred_at->toIso8601String(),
                'sourceSnapshotHash' => $departure->source_snapshot_hash,
            ] : null,
            'form' => [
                'requestKey' => $canRecord ? (string) Str::ulid() : null,
                'outcome' => [
                    'code' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture->value,
                    'label' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture->label(),
                    'interoperabilityCode' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture->interoperabilityCode(),
                ],
            ],
            'urls' => [
                'store' => route('encounters.early-departure.store', $encounter),
                'encounter' => route('encounters.show', $encounter),
                'timeline' => route('encounters.timeline.show', $encounter),
                'workQueue' => route('work'),
            ],
        ])->toResponse($request);
        $response->headers->add([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);

        return $response;
    }
}
