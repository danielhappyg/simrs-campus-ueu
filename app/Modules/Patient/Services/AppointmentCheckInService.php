<?php

namespace App\Modules\Patient\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use DomainException;
use Illuminate\Support\Facades\DB;

class AppointmentCheckInService
{
    public function __construct(
        private readonly EncounterTransitionService $transitionService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function checkIn(AppointmentRegistration $appointment, Assignment $assignment): AppointmentRegistration
    {
        return DB::transaction(function () use ($appointment, $assignment): AppointmentRegistration {
            $locked = AppointmentRegistration::query()
                ->with(['encounter.session', 'encounter.patient', 'location'])
                ->whereKey($appointment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = $locked->encounter;

            if (! $encounter) {
                throw new DomainException('A planned encounter is required before check-in.');
            }

            $matchesCase = $assignment->patient_id === $locked->patient_id
                && $assignment->encounter_id === $encounter->getKey();
            $sessionWideRegistrar = $assignment->patient_id === null
                && $assignment->encounter_id === null
                && $assignment->hasCapability(Capability::PatientRegister);

            if ($assignment->session_id !== $locked->session_id || (! $matchesCase && ! $sessionWideRegistrar)) {
                throw new DomainException('The acting assignment is outside the appointment context.');
            }

            if ($locked->status === AppointmentStatus::CheckedIn) {
                return $locked;
            }

            if ($locked->status !== AppointmentStatus::Booked) {
                throw new DomainException("Appointment in {$locked->status->value} state cannot be checked in.");
            }

            $encounter = $this->transitionService->transition(
                $encounter,
                EncounterStatus::Arrived,
                $assignment,
                'appointment_check_in',
            );

            $locked->forceFill([
                'status' => AppointmentStatus::CheckedIn,
                'checked_in_at' => now(),
            ])->save();

            $ticket = 'RJ-'.strtoupper(substr($encounter->public_id, -6));

            QueueEvent::query()->firstOrCreate(
                [
                    'encounter_id' => $encounter->getKey(),
                    'ticket_number' => $ticket,
                ],
                [
                    'location_id' => $locked->location_id,
                    'actor_assignment_id' => $assignment->getKey(),
                    'status' => QueueEventStatus::Waiting,
                    'reason' => 'appointment_check_in',
                    'started_at' => now(),
                ],
            );

            WorkTask::query()
                ->where('assignment_id', $assignment->getKey())
                ->where('encounter_id', $encounter->getKey())
                ->where('task_type', WorkTaskType::Registration)
                ->whereNotIn('status', [WorkTaskStatus::Complete, WorkTaskStatus::Cancelled])
                ->update([
                    'status' => WorkTaskStatus::Complete,
                    'completed_at' => now(),
                ]);

            $nursingAssignments = Assignment::query()
                ->active()
                ->where('session_id', $locked->session_id)
                ->where('patient_id', $locked->patient_id)
                ->where('encounter_id', $encounter->getKey())
                ->get()
                ->filter(fn (Assignment $candidate): bool => $candidate->hasCapability(Capability::IntakeWrite));

            foreach ($nursingAssignments as $nursingAssignment) {
                $task = WorkTask::query()->firstOrNew([
                    'assignment_id' => $nursingAssignment->getKey(),
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::NursingIntake,
                ]);
                $task->fill([
                    'session_id' => $locked->session_id,
                    'title' => 'Asesmen Awal dan Skrining Keselamatan',
                    'description' => 'Catat asesmen awal rawat jalan tanpa membuat keputusan diagnosis atau terapi otomatis.',
                    'status' => WorkTaskStatus::Ready,
                    'priority' => 1,
                    'source_program' => Program::Rmik,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ]);
                $task->save();
            }

            $this->auditRecorder->record(
                action: 'appointment.checked_in',
                resourceType: 'appointment_registration',
                resourceId: $locked->public_id,
                actor: $assignment->user,
                assignment: $assignment,
                session: $encounter->session,
                encounter: $encounter,
                metadata: [
                    'ticket_number' => $ticket,
                    'appointment_status' => AppointmentStatus::CheckedIn->value,
                    'nursing_tasks_released' => $nursingAssignments->count(),
                ],
            );

            return $locked->refresh()->load(['encounter.patient.identifiers', 'encounter.location']);
        });
    }
}
