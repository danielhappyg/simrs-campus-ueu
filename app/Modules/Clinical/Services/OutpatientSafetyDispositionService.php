<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\OutpatientSafetyDispositionOutcome;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\OutpatientSafetyDisposition;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutpatientSafetyDispositionService
{
    public function __construct(
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function record(
        Encounter $encounter,
        Assignment $actorAssignment,
        OutpatientSafetyDispositionOutcome $outcome,
        string $requestKey,
        string $rationale,
    ): OutpatientSafetyDisposition {
        $normalizedRationale = trim($rationale);

        if (! Str::isUlid($requestKey)) {
            throw new DomainException('The safety disposition request key must be a ULID.');
        }

        if (mb_strlen($normalizedRationale) < 10 || mb_strlen($normalizedRationale) > 2000) {
            throw new DomainException('The safety disposition rationale must contain between 10 and 2000 characters.');
        }

        return DB::transaction(function () use (
            $encounter,
            $actorAssignment,
            $outcome,
            $requestKey,
            $normalizedRationale,
        ): OutpatientSafetyDisposition {
            $existingRequest = OutpatientSafetyDisposition::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existingRequest) {
                if ($existingRequest->encounter_id !== $encounter->getKey()
                    || $existingRequest->actor_assignment_id !== $actorAssignment->getKey()
                    || $existingRequest->outcome !== $outcome
                    || $existingRequest->rationale !== $normalizedRationale) {
                    throw new DomainException('The safety disposition request key was already used for another decision.');
                }

                return $existingRequest;
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
            $existingEncounterDisposition = OutpatientSafetyDisposition::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingEncounterDisposition) {
                throw new DomainException('This escalated encounter already has a recorded safety disposition.');
            }

            $this->assertActorAndSession($activeActor, $lockedEncounter, $session);

            if ($lockedEncounter->status !== EncounterStatus::Escalated) {
                throw new DomainException('A safety disposition can only be recorded while the routine flow is escalated.');
            }

            $source = $this->approvedEscalationSource($lockedEncounter);
            $occurredAt = now();
            $disposition = OutpatientSafetyDisposition::query()->create([
                'request_key' => $requestKey,
                'encounter_id' => $lockedEncounter->getKey(),
                'source_clinical_entry_version_id' => $source->getKey(),
                'source_content_hash' => $source->content_hash,
                'actor_user_id' => $activeActor->user_id,
                'actor_assignment_id' => $activeActor->getKey(),
                'outcome' => $outcome,
                'rationale' => $normalizedRationale,
                'occurred_at' => $occurredAt,
            ]);

            $target = $outcome === OutpatientSafetyDispositionOutcome::ResumeRoutineFlow
                ? EncounterStatus::WaitingClinician
                : EncounterStatus::TransferredSimulation;
            $transitioned = $this->encounterTransitionService->transition(
                $lockedEncounter,
                $target,
                $activeActor,
                $outcome === OutpatientSafetyDispositionOutcome::ResumeRoutineFlow
                    ? 'human_safety_disposition_resumed_routine_flow'
                    : 'human_safety_disposition_simulated_transfer',
            );
            $taskCounts = $this->reconcileTasks($transitioned, $activeActor, $outcome, $occurredAt);

            $this->auditRecorder->record(
                action: 'clinical.outpatient_safety_disposition_recorded',
                resourceType: 'outpatient_safety_disposition',
                resourceId: $disposition->public_id,
                actor: $activeActor->user,
                assignment: $activeActor,
                session: $session,
                encounter: $transitioned,
                reason: $normalizedRationale,
                metadata: [
                    'outcome' => $outcome->value,
                    'source_nursing_version_public_id' => $source->public_id,
                    'source_nursing_content_hash' => $source->content_hash,
                    ...$taskCounts,
                ],
            );

            return $disposition->refresh()->load(['encounter', 'sourceVersion', 'actor', 'actorAssignment']);
        });
    }

    private function assertActorAndSession(
        ?Assignment $actor,
        Encounter $encounter,
        SimulationSession $session,
    ): void {
        if (! $actor
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $actor->session_id !== $session->getKey()
            || ! $actor->hasCapability(Capability::SafetyDispositionRecord)) {
            throw new DomainException('The active assignment does not permit a safety disposition in this simulation session.');
        }

        $matchesCase = $actor->patient_id === $encounter->patient_id
            && $actor->encounter_id === $encounter->getKey()
            && $actor->hasCapability(Capability::SupervisionReview);
        $sessionWideFacilitator = $actor->patient_id === null
            && $actor->encounter_id === null
            && $actor->hasCapability(Capability::SessionFacilitate);

        if (! $matchesCase && ! $sessionWideFacilitator) {
            throw new DomainException('The acting assignment is outside the escalated encounter disposition context.');
        }
    }

    private function approvedEscalationSource(Encounter $encounter): ClinicalEntryVersion
    {
        $source = ClinicalEntryVersion::query()
            ->where('status', ClinicalEntryStatus::Approved)
            ->whereHas('clinicalEntry', fn ($query) => $query
                ->where('encounter_id', $encounter->getKey())
                ->where('document_type', ClinicalDocumentType::NursingIntake))
            ->orderByDesc('version_number')
            ->lockForUpdate()
            ->first();

        if (! $source
            || data_get($source->content, 'safetyDecision') !== IntakeSafetyDecision::EscalateToSupervisor->value) {
            throw new DomainException('The escalated encounter has no approved human-authored nursing escalation source.');
        }

        return $source;
    }

    /**
     * @return array{
     *   disposition_tasks_completed: int,
     *   disposition_tasks_cancelled: int,
     *   medical_tasks_readied: int,
     *   medical_tasks_cancelled: int
     * }
     */
    private function reconcileTasks(
        Encounter $encounter,
        Assignment $actor,
        OutpatientSafetyDispositionOutcome $outcome,
        mixed $occurredAt,
    ): array {
        $dispositionTasks = WorkTask::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::SafetyDisposition)
            ->lockForUpdate()
            ->get();

        if (! $dispositionTasks->contains(fn (WorkTask $task): bool => $task->assignment_id === $actor->getKey())) {
            throw new DomainException('The acting assignment has no safety disposition task for this encounter.');
        }

        $completed = 0;
        $cancelled = 0;

        foreach ($dispositionTasks as $task) {
            if ($task->assignment_id === $actor->getKey()) {
                $task->status = WorkTaskStatus::Complete;
                $completed++;
            } else {
                $task->status = WorkTaskStatus::Cancelled;
                $cancelled++;
            }

            $task->completed_at = $occurredAt;
            $task->save();
        }

        $medicalTasks = WorkTask::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::MedicalAssessment)
            ->lockForUpdate()
            ->get();
        $medicalReadied = 0;
        $medicalCancelled = 0;

        foreach ($medicalTasks as $task) {
            if ($outcome === OutpatientSafetyDispositionOutcome::ResumeRoutineFlow) {
                $task->status = WorkTaskStatus::Ready;
                $task->description = 'Keputusan manusia telah dicatat. Tinjau asesmen awal yang disetujui dan lanjutkan asesmen medis simulasi.';
                $task->completed_at = null;
                $medicalReadied++;
            } else {
                $task->status = WorkTaskStatus::Cancelled;
                $task->description = 'Alur asesmen medis rutin dibatalkan karena pengalihan yang dicatat manusia untuk skenario simulasi.';
                $task->completed_at = $occurredAt;
                $medicalCancelled++;
            }

            $task->save();
        }

        return [
            'disposition_tasks_completed' => $completed,
            'disposition_tasks_cancelled' => $cancelled,
            'medical_tasks_readied' => $medicalReadied,
            'medical_tasks_cancelled' => $medicalCancelled,
        ];
    }
}
