<?php

namespace App\Http\Controllers\Claims;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Claims\Models\EClaimCase;
use App\Modules\Claims\Services\EClaimPayloadFactory;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class EClaimSimulationWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly EClaimPayloadFactory $payloadFactory,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['session.scenario', 'patient.identifiers', 'location']);
        $assignment = $this->assignmentResolver->forEncounterAny($user, $encounter, [
            Capability::ClaimManage,
            Capability::ClaimReview,
            Capability::ReportView,
        ]);

        if ($encounter->status !== EncounterStatus::Finalized) {
            abort(409, 'Simulasi E-Klaim tersedia setelah encounter difinalisasi.');
        }

        $case = EClaimCase::query()
            ->with(['events.actorAssignment.user'])
            ->where('encounter_id', $encounter->getKey())
            ->first();
        $snapshot = $case->source_snapshot ?? $this->payloadFactory->snapshot($encounter);
        $canAdvance = $assignment->hasCapability(Capability::ClaimManage);
        $eventsByAction = $case?->events->keyBy(fn ($event): string => $event->action->value) ?? collect();
        $steps = collect(EClaimAction::cases())->map(function (EClaimAction $action) use (
            $case,
            $canAdvance,
            $eventsByAction,
            $encounter,
        ): array {
            $event = $eventsByAction->get($action->value);
            $available = $canAdvance && ($action === EClaimAction::CreateClaim
                ? $case === null
                : $case?->status === $action->requiredStatus());

            return [
                'action' => $action->value,
                'method' => $action->method(),
                'label' => $action->label(),
                'completed' => $event !== null,
                'available' => $available,
                'requestKey' => $available ? (string) Str::ulid() : null,
                'actionUrl' => route('encounters.eclaim-simulation.advance', $encounter),
            ];
        })->all();

        $this->auditRecorder->record(
            action: 'claims.eclaim_simulation_viewed',
            resourceType: 'eclaim_simulation_case',
            resourceId: $case->public_id ?? $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'case_exists' => $case !== null,
                'status' => $case?->status->value,
                'event_count' => $case?->events->count() ?? 0,
                'transport_state' => 'NOT_SENT',
                'can_advance' => $canAdvance,
            ],
            request: $request,
        );

        $response = Inertia::render('claims/eclaim-simulation', [
            'boundary' => [
                'classification' => 'SIMULASI — DATA SINTETIS',
                'mode' => 'SIMULATION_ONLY',
                'transportState' => 'NOT_SENT',
                'externalEndpoint' => null,
                'outboundEnabled' => false,
                'certifiedGrouper' => false,
                'compatibilityProfile' => config('eclaim.compatibility_profile'),
                'observedInstallationVersion' => config('eclaim.observed_installation_version'),
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
                'environmentMode' => $encounter->environment_mode->value,
            ],
            'patient' => [
                'publicId' => $encounter->patient->public_id,
                'fullName' => $encounter->patient->full_name,
                'birthDate' => $encounter->patient->birth_date->toDateString(),
                'administrativeSex' => $encounter->patient->administrative_sex->label(),
                'mrn' => data_get($snapshot, 'identifiers.nomorRm'),
                'allergyStatus' => 'Lihat sumber asesmen pada linimasa',
                'synthetic' => true,
            ],
            'assignment' => [
                'program' => $assignment->program->label(),
                'role' => $assignment->application_role->label(),
                'canAdvance' => $canAdvance,
            ],
            'claimCase' => $case ? [
                'publicId' => $case->public_id,
                'status' => [
                    'code' => $case->status->value,
                    'label' => $case->status->label(),
                ],
                'syntheticSep' => $case->synthetic_sep,
                'sourceSnapshotHash' => $case->source_snapshot_hash,
                'grouperCode' => $case->grouper_code,
                'grouperDescription' => $case->grouper_description,
                'simulatedTariff' => $case->simulated_tariff,
            ] : null,
            'snapshot' => $snapshot,
            'steps' => $steps,
            'events' => $case?->events->map(fn ($event): array => [
                'publicId' => $event->public_id,
                'sequenceNumber' => $event->sequence_number,
                'action' => $event->action->value,
                'label' => $event->action->label(),
                'method' => $event->method,
                'request' => $event->request_payload,
                'response' => $event->response_payload,
                'requestHash' => $event->request_hash,
                'responseHash' => $event->response_hash,
                'responseCode' => $event->response_code,
                'transportState' => $event->transport_state,
                'actor' => $event->actorAssignment->user->name,
                'recordedAt' => $event->recorded_at->toIso8601String(),
            ])->values()->all() ?? [],
            'urls' => [
                'back' => route('encounters.debrief.show', $encounter),
                'timeline' => route('encounters.timeline.show', $encounter),
                'interoperabilityPreview' => route('encounters.interoperability-preview.show', $encounter),
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
