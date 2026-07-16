<?php

namespace App\Modules\Patient\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\VisitTerminationOutcome;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use DomainException;
use Illuminate\Support\Facades\DB;

class AppointmentTerminationService
{
    public function __construct(
        private readonly EncounterTransitionService $transitionService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function terminate(
        AppointmentRegistration $appointment,
        Assignment $assignment,
        VisitTerminationOutcome $outcome,
        string $reason,
    ): AppointmentRegistration {
        return DB::transaction(function () use ($appointment, $assignment, $outcome, $reason): AppointmentRegistration {
            $locked = AppointmentRegistration::query()
                ->with(['encounter.session', 'encounter.patient', 'location'])
                ->whereKey($appointment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = $locked->encounter;

            if (! $encounter) {
                throw new DomainException('Encounter terencana tidak tersedia untuk terminasi.');
            }

            $activeAssignment = Assignment::query()
                ->active()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->first();

            if (! $activeAssignment) {
                throw new DomainException('The acting assignment is not active.');
            }

            $this->assertAssignmentContext($locked, $encounter, $activeAssignment);
            $targetAppointment = $outcome->appointmentStatus();
            $targetEncounter = $outcome->encounterStatus();

            if ($locked->status === $targetAppointment && $encounter->status === $targetEncounter) {
                return $locked;
            }

            if (in_array($locked->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
                throw new DomainException('Kunjungan sudah memiliki outcome terminal yang berbeda.');
            }

            $this->assertAllowedSource($locked, $encounter, $outcome);

            $encounter = $this->transitionService->transition(
                $encounter,
                $targetEncounter,
                $activeAssignment,
                $reason,
            );

            $locked->forceFill(['status' => $targetAppointment])->save();

            $queueEventsEnded = QueueEvent::query()
                ->where('encounter_id', $encounter->getKey())
                ->whereIn('status', [
                    QueueEventStatus::Waiting->value,
                    QueueEventStatus::Called->value,
                    QueueEventStatus::InService->value,
                    QueueEventStatus::Held->value,
                ])
                ->update([
                    'status' => QueueEventStatus::Cancelled->value,
                    'reason' => $outcome === VisitTerminationOutcome::NoShow
                        ? 'appointment_no_show'
                        : 'encounter_cancelled',
                    'ended_at' => now(),
                ]);

            $tasksCancelled = WorkTask::query()
                ->where('encounter_id', $encounter->getKey())
                ->whereNotIn('status', [
                    WorkTaskStatus::Complete->value,
                    WorkTaskStatus::Cancelled->value,
                ])
                ->update([
                    'status' => WorkTaskStatus::Cancelled->value,
                    'completed_at' => now(),
                ]);

            $this->auditRecorder->record(
                action: 'appointment.terminated',
                resourceType: 'appointment_registration',
                resourceId: $locked->public_id,
                actor: $activeAssignment->user,
                assignment: $activeAssignment,
                session: $encounter->session,
                encounter: $encounter,
                reason: $reason,
                metadata: [
                    'appointment_status' => $targetAppointment->value,
                    'encounter_status' => $targetEncounter->value,
                    'queue_events_ended' => $queueEventsEnded,
                    'tasks_cancelled' => $tasksCancelled,
                ],
            );

            return $locked->refresh()->load(['encounter.patient.identifiers', 'encounter.location']);
        });
    }

    private function assertAssignmentContext(
        AppointmentRegistration $appointment,
        Encounter $encounter,
        Assignment $assignment,
    ): void {
        $matchesCase = $assignment->patient_id === $appointment->patient_id
            && $assignment->encounter_id === $encounter->getKey();
        $sessionWideRegistrar = $assignment->patient_id === null
            && $assignment->encounter_id === null
            && $assignment->hasCapability(Capability::PatientRegister);

        if ($assignment->session_id !== $appointment->session_id
            || ! $assignment->hasCapability(Capability::PatientRegister)
            || (! $matchesCase && ! $sessionWideRegistrar)) {
            throw new DomainException('The acting assignment is outside the appointment context.');
        }
    }

    private function assertAllowedSource(
        AppointmentRegistration $appointment,
        Encounter $encounter,
        VisitTerminationOutcome $outcome,
    ): void {
        $planned = $appointment->status === AppointmentStatus::Booked
            && $encounter->status === EncounterStatus::Planned;
        $arrived = $appointment->status === AppointmentStatus::CheckedIn
            && $encounter->status === EncounterStatus::Arrived;

        if ($outcome === VisitTerminationOutcome::Cancelled && ($planned || $arrived)) {
            return;
        }

        if ($outcome === VisitTerminationOutcome::NoShow
            && $planned
            && $appointment->scheduled_at->lessThanOrEqualTo(now())) {
            return;
        }

        throw new DomainException('Kunjungan tidak dapat diterminasi dari status atau waktu saat ini.');
    }
}
