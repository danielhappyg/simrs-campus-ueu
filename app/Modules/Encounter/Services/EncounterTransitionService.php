<?php

namespace App\Modules\Encounter\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use DomainException;
use Illuminate\Support\Facades\DB;

class EncounterTransitionService
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function transition(
        Encounter $encounter,
        EncounterStatus $target,
        Assignment $actorAssignment,
        ?string $reason = null,
    ): Encounter {
        return DB::transaction(function () use ($encounter, $target, $actorAssignment, $reason): Encounter {
            $locked = Encounter::query()
                ->whereKey($encounter->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $session = SimulationSession::query()
                ->whereKey($locked->session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $activeActor = Assignment::query()
                ->active()
                ->whereKey($actorAssignment->getKey())
                ->lockForUpdate()
                ->first();

            if (! $activeActor) {
                throw new DomainException('The acting assignment is not active.');
            }

            if ($session->environment_mode !== EnvironmentMode::Simulation
                || $session->status !== SessionStatus::Active) {
                throw new DomainException('The encounter session is not active for workflow transitions.');
            }

            $matchesCase = $activeActor->patient_id === $locked->patient_id
                && $activeActor->encounter_id === $locked->getKey();
            $sessionWideOperator = $activeActor->patient_id === null
                && $activeActor->encounter_id === null
                && ($activeActor->hasCapability(Capability::PatientRegister)
                    || $activeActor->hasCapability(Capability::SessionFacilitate));

            if ($activeActor->session_id !== $locked->session_id || (! $matchesCase && ! $sessionWideOperator)) {
                throw new DomainException('The acting assignment is outside the encounter context.');
            }

            $requiredCapabilities = $this->requiredCapabilitiesFor($target);
            $hasWorkflowCapability = collect($requiredCapabilities)
                ->contains(fn (Capability $capability): bool => $activeActor->hasCapability($capability));

            if (! $hasWorkflowCapability && ! $activeActor->hasCapability(Capability::SessionFacilitate)) {
                throw new DomainException("The acting assignment cannot transition the encounter to {$target->value}.");
            }

            $current = $locked->status;

            if ($current === $target) {
                return $locked;
            }

            if (! $current->canTransitionTo($target)) {
                throw new DomainException("Encounter cannot transition from {$current->value} to {$target->value}.");
            }

            if ($target === EncounterStatus::Arrived && $locked->period_start === null) {
                $locked->period_start = now();
            }

            if ($target === EncounterStatus::ClosurePending && $locked->closure_requested_at === null) {
                $locked->closure_requested_at = now();
            }

            if ($target === EncounterStatus::ClinicallyClosed && $locked->clinically_closed_at === null) {
                $locked->clinically_closed_at = now();
            }

            if ($target === EncounterStatus::Finalized && $locked->finalized_at === null) {
                $locked->finalized_at = now();
            }

            if (in_array($target, [EncounterStatus::Cancelled, EncounterStatus::NoShow, EncounterStatus::Finalized], true)) {
                $locked->period_end ??= now();
            }

            $locked->persistTransitionState($target);

            EncounterTransition::query()->create([
                'encounter_id' => $locked->getKey(),
                'actor_assignment_id' => $activeActor->getKey(),
                'from_status' => $current,
                'to_status' => $target,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            $this->auditRecorder->record(
                action: 'encounter.transitioned',
                resourceType: 'encounter',
                resourceId: $locked->public_id,
                actor: $activeActor->user,
                assignment: $activeActor,
                session: $session,
                encounter: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $current->value,
                    'to_status' => $target->value,
                ],
            );

            return $locked->refresh();
        });
    }

    /** @return list<Capability> */
    private function requiredCapabilitiesFor(EncounterStatus $target): array
    {
        return match ($target) {
            EncounterStatus::Arrived => [Capability::PatientRegister],
            EncounterStatus::InIntake => [Capability::IntakeWrite],
            EncounterStatus::Escalated,
            EncounterStatus::WaitingClinician => [Capability::IntakeWrite, Capability::SupervisionReview],
            EncounterStatus::InConsultation => [
                Capability::MedicalAssessmentWrite,
                Capability::SupervisionReview,
                Capability::Dispense,
            ],
            EncounterStatus::AwaitingResult,
            EncounterStatus::AwaitingPharmacy,
            EncounterStatus::ClosurePending,
            EncounterStatus::ClinicallyClosed => [Capability::MedicalAssessmentWrite, Capability::SupervisionReview],
            EncounterStatus::RecordReview,
            EncounterStatus::AmendmentPending,
            EncounterStatus::Finalized => [Capability::RecordReview, Capability::SupervisionReview],
            EncounterStatus::Cancelled,
            EncounterStatus::NoShow => [Capability::PatientRegister, Capability::SessionFacilitate],
            EncounterStatus::TransferredSimulation => [Capability::SupervisionReview, Capability::SessionFacilitate],
            EncounterStatus::Planned => [Capability::SessionFacilitate],
        };
    }
}
