<?php

namespace App\Http\Controllers\Encounter;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use App\Modules\Teaching\Services\EncounterDebriefTimeline;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EncounterRecordTimelineController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly EncounterDebriefTimeline $timeline,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['session.scenario', 'patient.identifiers', 'location']);
        $assignment = $this->assignmentResolver->forRecordTimeline($user, $encounter);
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $canViewDebrief = $encounter->status === EncounterStatus::Finalized
            && $assignment->hasCapability(Capability::DebriefView);
        $canViewInteroperabilityPreview = $encounter->status === EncounterStatus::Finalized
            && $assignment->hasCapability(Capability::ReportView);
        $timeline = $this->timeline->build($encounter);

        $this->auditRecorder->record(
            action: 'record.timeline_viewed',
            resourceType: 'encounter_record_timeline',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'displayed_event_count' => data_get($timeline, 'summary.displayedEventCount'),
                'total_available_event_count' => data_get($timeline, 'summary.totalAvailableEventCount'),
                'truncated' => data_get($timeline, 'summary.truncated'),
            ],
            request: $request,
        );

        return Inertia::render('encounter/timeline', [
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
                'allergyStatus' => 'Lihat sumber asesmen pada linimasa',
                'synthetic' => true,
            ],
            'assignment' => [
                'publicId' => $assignment->public_id,
                'program' => $assignment->program->label(),
                'role' => $assignment->application_role->label(),
            ],
            'session' => [
                'publicId' => $encounter->session->public_id,
                'code' => $encounter->session->code,
                'status' => [
                    'code' => $encounter->session->status->value,
                    'label' => $encounter->session->status->label(),
                ],
                'scenarioTitle' => $encounter->session->scenario->title,
            ],
            'release' => [
                'readOnly' => true,
                'sessionCompleted' => $encounter->session->status === SessionStatus::Completed,
                'encounterStatus' => $encounter->status->value,
            ],
            ...$timeline,
            'urls' => [
                'encounter' => route('encounters.show', $encounter),
                'self' => route('encounters.timeline.show', $encounter),
                'debrief' => $canViewDebrief ? route('encounters.debrief.show', $encounter) : null,
                'interoperabilityPreview' => $canViewInteroperabilityPreview
                    ? route('encounters.interoperability-preview.show', $encounter)
                    : null,
                'eClaimSimulation' => $encounter->status === EncounterStatus::Finalized
                    && ($assignment->hasCapability(Capability::ReportView)
                        || $assignment->hasCapability(Capability::ClaimManage)
                        || $assignment->hasCapability(Capability::ClaimReview))
                    ? route('encounters.eclaim-simulation.show', $encounter)
                    : null,
                'workQueue' => route('work'),
            ],
        ]);
    }
}
