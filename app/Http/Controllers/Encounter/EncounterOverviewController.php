<?php

namespace App\Http\Controllers\Encounter;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EncounterOverviewController extends Controller
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

        $encounter->load([
            'session.scenario',
            'patient.identifiers',
            'location',
            'appointment',
            'queueEvents' => fn ($query) => $query->orderBy('started_at'),
            'transitions.actorAssignment.user',
        ]);
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::SessionView);
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);

        $this->auditRecorder->record(
            action: 'encounter.overview_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            request: $request,
        );

        return Inertia::render('encounter/show', [
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
                'allergyStatus' => 'Belum dinilai',
                'synthetic' => true,
            ],
            'assignment' => [
                'publicId' => $assignment->public_id,
                'program' => $assignment->program->label(),
                'role' => $assignment->application_role->label(),
                'canViewDebrief' => $encounter->status === EncounterStatus::Finalized
                    && $assignment->hasCapability(Capability::DebriefView),
                'canViewReports' => $encounter->status === EncounterStatus::Finalized
                    && $assignment->hasCapability(Capability::ReportView),
            ],
            'session' => [
                'publicId' => $encounter->session->public_id,
                'code' => $encounter->session->code,
                'scenarioTitle' => $encounter->session->scenario->title,
            ],
            'queue' => $encounter->queueEvents->map(fn (QueueEvent $event): array => [
                'publicId' => $event->public_id,
                'ticketNumber' => $event->ticket_number,
                'status' => [
                    'code' => $event->status->value,
                    'label' => $event->status->label(),
                ],
                'startedAt' => $event->started_at->toIso8601String(),
            ])->values()->all(),
            'timeline' => $encounter->transitions->map(fn (EncounterTransition $transition): array => [
                'publicId' => $transition->public_id,
                'fromStatus' => $transition->from_status?->label(),
                'fromStatusCode' => $transition->from_status?->value,
                'toStatus' => $transition->to_status->label(),
                'toStatusCode' => $transition->to_status->value,
                'reason' => $transition->reason,
                'occurredAt' => $transition->occurred_at->toIso8601String(),
                'actor' => $transition->actorAssignment->user->name,
                'role' => $transition->actorAssignment->application_role->label(),
            ])->values()->all(),
            'workflow' => collect(EncounterStatus::cases())->map(fn (EncounterStatus $status): array => [
                'code' => $status->value,
                'label' => $status->label(),
                'current' => $status === $encounter->status,
            ])->all(),
            'urls' => [
                'debrief' => route('encounters.debrief.show', $encounter),
                'timeline' => $this->assignmentResolver->canViewRecordTimeline($user, $encounter)
                    ? route('encounters.timeline.show', $encounter)
                    : null,
                'outpatientSummaryReport' => route('encounters.reports.outpatient-summary', $encounter),
                'debriefEvidenceReport' => route('encounters.reports.debrief-evidence', $encounter),
                'interoperabilityPreview' => $encounter->status === EncounterStatus::Finalized
                    && $assignment->hasCapability(Capability::ReportView)
                    ? route('encounters.interoperability-preview.show', $encounter)
                    : null,
            ],
        ]);
    }
}
