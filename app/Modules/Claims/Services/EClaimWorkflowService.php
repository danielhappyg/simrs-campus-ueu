<?php

namespace App\Modules\Claims\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Claims\Contracts\EClaimGateway;
use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Claims\Enums\EClaimCaseStatus;
use App\Modules\Claims\Models\EClaimCase;
use App\Modules\Claims\Models\EClaimEvent;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EClaimWorkflowService
{
    public function __construct(
        private readonly EClaimGateway $gateway,
        private readonly EClaimPayloadFactory $payloadFactory,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function advance(
        Encounter $encounter,
        Assignment $actorAssignment,
        EClaimAction $action,
        string $requestKey,
    ): EClaimCase {
        return DB::transaction(function () use ($encounter, $actorAssignment, $action, $requestKey): EClaimCase {
            $existingEvent = EClaimEvent::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existingEvent) {
                if ($existingEvent->action !== $action
                    || $existingEvent->claimCase->encounter_id !== $encounter->getKey()
                    || $existingEvent->actor_assignment_id !== $actorAssignment->getKey()) {
                    throw new DomainException('The E-Klaim request key was already used in another workflow context.');
                }

                return $existingEvent->claimCase->load('events');
            }

            $lockedEncounter = Encounter::query()
                ->with(['patient', 'session'])
                ->whereKey($encounter->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $actor = Assignment::query()->active()->whereKey($actorAssignment->getKey())->lockForUpdate()->first();
            $this->assertSafeContext($lockedEncounter, $actor);
            $case = EClaimCase::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->lockForUpdate()
                ->first();

            if ($action === EClaimAction::CreateClaim) {
                if ($case) {
                    throw new DomainException('This encounter already has an E-Klaim simulation case.');
                }

                $snapshot = $this->payloadFactory->snapshot($lockedEncounter);
                $case = EClaimCase::query()->create([
                    'session_id' => $lockedEncounter->session_id,
                    'patient_id' => $lockedEncounter->patient_id,
                    'encounter_id' => $lockedEncounter->getKey(),
                    'created_by_user_id' => $actor->user_id,
                    'created_by_assignment_id' => $actor->getKey(),
                    'status' => EClaimCaseStatus::ClaimCreated,
                    'compatibility_profile' => (string) config('eclaim.compatibility_profile'),
                    'synthetic_sep' => (string) data_get($snapshot, 'identifiers.nomorSep'),
                    'source_snapshot' => $snapshot,
                    'source_snapshot_hash' => hash('sha256', CanonicalJson::encode($snapshot)),
                ]);
                $case->load(['patient']);
            } else {
                if (! $case || $case->status !== $action->requiredStatus()) {
                    $required = $action->requiredStatus()?->label() ?? 'belum dibuat';
                    throw new DomainException("Langkah {$action->label()} memerlukan status {$required}.");
                }

                $case->load(['patient']);
            }

            $request = $this->payloadFactory->request($action, $case);
            $response = $this->gateway->exchange($action, $request, $case);
            $responseCode = (int) data_get($response, 'metadata.code', 0);

            if ($responseCode !== 200
                || data_get($response, 'boundary.transport_state') !== 'NOT_SENT'
                || data_get($response, 'boundary.external_endpoint') !== null) {
                throw new DomainException('The educational E-Klaim gateway returned an unsafe or unsuccessful response.');
            }

            $event = EClaimEvent::query()->create([
                'request_key' => $requestKey,
                'e_claim_case_id' => $case->getKey(),
                'sequence_number' => (int) EClaimEvent::query()
                    ->where('e_claim_case_id', $case->getKey())
                    ->max('sequence_number') + 1,
                'action' => $action,
                'method' => $action->method(),
                'request_payload' => $request,
                'response_payload' => $response,
                'request_hash' => hash('sha256', CanonicalJson::encode($request)),
                'response_hash' => hash('sha256', CanonicalJson::encode($response)),
                'response_code' => $responseCode,
                'transport_state' => 'NOT_SENT',
                'actor_user_id' => $actor->user_id,
                'actor_assignment_id' => $actor->getKey(),
                'recorded_at' => CarbonImmutable::now(),
            ]);

            if ($action !== EClaimAction::CreateClaim) {
                $case->persistTransition(
                    $action->resultingStatus(),
                    CarbonImmutable::now(),
                    $action === EClaimAction::GroupClaim ? [
                        'grouper_code' => data_get($response, 'response.cbg.code'),
                        'grouper_description' => data_get($response, 'response.cbg.description'),
                        'simulated_tariff' => data_get($response, 'response.cbg.tariff'),
                    ] : [],
                );
            }

            $this->auditRecorder->record(
                action: 'claims.eclaim_simulation_'.strtolower($action->value),
                resourceType: 'eclaim_simulation_case',
                resourceId: $case->public_id,
                actor: $actor->user,
                assignment: $actor,
                session: $lockedEncounter->session,
                encounter: $lockedEncounter,
                metadata: [
                    'workflow_action' => $action->value,
                    'method' => $action->method(),
                    'resulting_status' => $action->resultingStatus()->value,
                    'request_hash' => $event->request_hash,
                    'response_hash' => $event->response_hash,
                    'transport_state' => 'NOT_SENT',
                    'outbound_enabled' => false,
                ],
            );

            return $case->refresh()->load('events');
        });
    }

    private function assertSafeContext(Encounter $encounter, ?Assignment $actor): void
    {
        if (config('simulation.mode') !== 'SIMULATION'
            || config('simulation.synthetic_only') !== true
            || config('eclaim.mode') !== 'SIMULATION_ONLY'
            || config('eclaim.outbound_enabled') !== false
            || config('eclaim.endpoint') !== null
            || $encounter->status !== EncounterStatus::Finalized
            || $encounter->environment_mode !== EnvironmentMode::Simulation
            || $encounter->session->status !== SessionStatus::Active
            || ! $encounter->patient->synthetic_flag) {
            throw new DomainException('E-Klaim workflow is restricted to a finalized, active, synthetic simulation with outbound transport disabled.');
        }

        if (! $actor
            || ! $actor->hasCapability(Capability::ClaimManage)
            || $actor->session_id !== $encounter->session_id
            || $actor->patient_id !== $encounter->patient_id
            || $actor->encounter_id !== $encounter->getKey()) {
            throw new DomainException('The active assignment does not permit claim simulation for this encounter.');
        }
    }
}
