<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\OutpatientEarlyDepartureOutcome;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\OutpatientEarlyDeparture;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutpatientEarlyDepartureService
{
    /** @var list<EncounterStatus> */
    public const ALLOWED_SOURCE_STATES = [
        EncounterStatus::InIntake,
        EncounterStatus::WaitingClinician,
        EncounterStatus::InConsultation,
        EncounterStatus::AwaitingResult,
        EncounterStatus::AwaitingPharmacy,
        EncounterStatus::ClosurePending,
    ];

    public function __construct(
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function record(
        Encounter $encounter,
        Assignment $actorAssignment,
        string $requestKey,
        string $statedReason,
        string $communicationSummary,
    ): OutpatientEarlyDeparture {
        $normalizedReason = trim($statedReason);
        $normalizedSummary = trim($communicationSummary);

        if (! Str::isUlid($requestKey)) {
            throw new DomainException('The early-departure request key must be a ULID.');
        }

        if (mb_strlen($normalizedReason) < 10 || mb_strlen($normalizedReason) > 1000) {
            throw new DomainException('The stated reason must contain between 10 and 1000 characters.');
        }

        if (mb_strlen($normalizedSummary) < 10 || mb_strlen($normalizedSummary) > 2000) {
            throw new DomainException('The communication summary must contain between 10 and 2000 characters.');
        }

        return DB::transaction(function () use (
            $encounter,
            $actorAssignment,
            $requestKey,
            $normalizedReason,
            $normalizedSummary,
        ): OutpatientEarlyDeparture {
            $existingRequest = OutpatientEarlyDeparture::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existingRequest) {
                if ($existingRequest->encounter_id !== $encounter->getKey()
                    || $existingRequest->actor_assignment_id !== $actorAssignment->getKey()
                    || $existingRequest->stated_reason !== $normalizedReason
                    || $existingRequest->communication_summary !== $normalizedSummary) {
                    throw new DomainException('The early-departure request key was already used in another context.');
                }

                return $existingRequest->load(['encounter', 'actor', 'actorAssignment']);
            }

            $lockedEncounter = Encounter::query()
                ->whereKey($encounter->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $session = SimulationSession::query()
                ->whereKey($lockedEncounter->session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $activeActor = Assignment::query()
                ->active()
                ->whereKey($actorAssignment->getKey())
                ->lockForUpdate()
                ->first();
            $appointment = AppointmentRegistration::query()
                ->whereKey($lockedEncounter->appointment_registration_id)
                ->lockForUpdate()
                ->firstOrFail();
            $competingDeparture = OutpatientEarlyDeparture::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->lockForUpdate()
                ->first();

            if ($competingDeparture) {
                throw new DomainException('This encounter already has a recorded early departure.');
            }

            $this->assertActorAndSource($activeActor, $lockedEncounter, $session, $appointment);

            $sourceSnapshot = $this->sourceSnapshot($lockedEncounter);
            $sourceSnapshotHash = hash('sha256', CanonicalJson::encode($sourceSnapshot));
            $sourceStatus = $lockedEncounter->status;
            $occurredAt = now();
            $departure = OutpatientEarlyDeparture::query()->create([
                'request_key' => $requestKey,
                'session_id' => $lockedEncounter->session_id,
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->getKey(),
                'actor_user_id' => $activeActor->user_id,
                'actor_assignment_id' => $activeActor->getKey(),
                'outcome' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture,
                'source_encounter_status' => $sourceStatus,
                'source_snapshot' => $sourceSnapshot,
                'source_snapshot_hash' => $sourceSnapshotHash,
                'stated_reason' => $normalizedReason,
                'communication_summary' => $normalizedSummary,
                'occurred_at' => $occurredAt,
            ]);

            $transitioned = $this->encounterTransitionService->transition(
                $lockedEncounter,
                EncounterStatus::DepartedOnRequest,
                $activeActor,
                'patient_requested_departure_recorded',
            );
            $appointment->forceFill(['status' => AppointmentStatus::DepartedOnRequest])->save();

            $queueEventsCompleted = QueueEvent::query()
                ->where('encounter_id', $transitioned->getKey())
                ->whereIn('status', [
                    QueueEventStatus::Waiting->value,
                    QueueEventStatus::Called->value,
                    QueueEventStatus::InService->value,
                    QueueEventStatus::Held->value,
                ])
                ->update([
                    'status' => QueueEventStatus::Completed->value,
                    'reason' => 'patient_requested_departure',
                    'ended_at' => $occurredAt,
                ]);
            $tasksCancelled = WorkTask::query()
                ->where('encounter_id', $transitioned->getKey())
                ->whereNotIn('status', [
                    WorkTaskStatus::Complete->value,
                    WorkTaskStatus::Cancelled->value,
                ])
                ->update([
                    'status' => WorkTaskStatus::Cancelled->value,
                    'completed_at' => $occurredAt,
                ]);

            $this->auditRecorder->record(
                action: 'clinical.outpatient_early_departure_recorded',
                resourceType: 'outpatient_early_departure',
                resourceId: $departure->public_id,
                actor: $activeActor->user,
                assignment: $activeActor,
                session: $session,
                encounter: $transitioned,
                metadata: [
                    'outcome' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture->value,
                    'interoperability_disposition_code' => OutpatientEarlyDepartureOutcome::PatientRequestedDeparture->interoperabilityCode(),
                    'source_encounter_status' => $sourceStatus->value,
                    'target_encounter_status' => EncounterStatus::DepartedOnRequest->value,
                    'source_snapshot_hash' => $sourceSnapshotHash,
                    'source_count' => count($sourceSnapshot['clinicalSources']),
                    'queue_events_completed' => $queueEventsCompleted,
                    'tasks_cancelled' => $tasksCancelled,
                ],
            );

            return $departure->refresh()->load(['encounter', 'actor', 'actorAssignment']);
        });
    }

    private function assertActorAndSource(
        ?Assignment $actor,
        Encounter $encounter,
        SimulationSession $session,
        AppointmentRegistration $appointment,
    ): void {
        if (! $actor
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $actor->session_id !== $session->getKey()
            || ! $actor->hasCapability(Capability::EarlyDepartureRecord)) {
            throw new DomainException('The active assignment does not permit early-departure recording in this simulation session.');
        }

        $matchesCaseSupervisor = $actor->patient_id === $encounter->patient_id
            && $actor->encounter_id === $encounter->getKey()
            && $actor->hasCapability(Capability::SupervisionReview);
        $sessionWideFacilitator = $actor->patient_id === null
            && $actor->encounter_id === null
            && $actor->hasCapability(Capability::SessionFacilitate);

        if (! $matchesCaseSupervisor && ! $sessionWideFacilitator) {
            throw new DomainException('The acting assignment is outside the early-departure encounter context.');
        }

        if (! in_array($encounter->status, self::ALLOWED_SOURCE_STATES, true)
            || $appointment->status !== AppointmentStatus::CheckedIn
            || $appointment->checked_in_at === null) {
            throw new DomainException('Early departure requires an attended encounter in an eligible active clinical state.');
        }
    }

    /** @return array{encounterStatus: string, clinicalSources: list<array<string, mixed>>} */
    public function sourceSnapshot(Encounter $encounter): array
    {
        $sources = ClinicalEntry::query()
            ->with('latestVersion')
            ->where('encounter_id', $encounter->getKey())
            ->get()
            ->sortBy(fn (ClinicalEntry $entry): string => $entry->document_type->value)
            ->map(function (ClinicalEntry $entry): ?array {
                $version = $entry->latestVersion;

                if (! $version) {
                    return null;
                }

                return [
                    'documentType' => $entry->document_type->value,
                    'entryPublicId' => $entry->public_id,
                    'versionPublicId' => $version->public_id,
                    'versionNumber' => $version->version_number,
                    'status' => $version->status->value,
                    'contentHash' => $version->content_hash,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'encounterStatus' => $encounter->status->value,
            'clinicalSources' => array_values($sources),
        ];
    }
}
