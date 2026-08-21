<?php

namespace App\Modules\Teaching\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\IdentifierStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Enums\PatientRecordStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\PatientIdentifier;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\ScenarioStatus;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReferenceSessionCloneService
{
    public const DEFAULT_SOURCE_CODE = ReferenceOutpatientJourneyBuilder::SESSION_CODE;

    public const MIN_DURATION_MINUTES = 30;

    public const MAX_DURATION_MINUTES = 10_080;

    public const MIN_APPOINTMENT_OFFSET_MINUTES = -1_440;

    private const EXPECTED_ASSIGNMENT_COUNT = 10;

    /** @var array<string, string> */
    private const EXPECTED_TASK_STATES = [
        WorkTaskType::Registration->value => WorkTaskStatus::Ready->value,
        WorkTaskType::SessionOrientation->value => WorkTaskStatus::Complete->value,
        WorkTaskType::NursingIntake->value => WorkTaskStatus::Waiting->value,
        WorkTaskType::MedicalAssessment->value => WorkTaskStatus::Waiting->value,
    ];

    /** @var list<string> */
    private const PROGRESSED_ENCOUNTER_TABLES = [
        'queue_events',
        'clinical_entries',
        'service_requests',
        'medication_requests',
        'pharmacy_reviews',
        'medication_dispense_preparations',
        'medication_dispenses',
        'encounter_closures',
        'record_quality_reviews',
        'coding_assignments',
        'outpatient_safety_dispositions',
        'outpatient_early_departures',
        'debrief_notes',
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @return array<string, mixed>
     */
    public function createClone(
        string $sourceCode,
        string $targetCode,
        int $durationMinutes,
        int $appointmentOffsetMinutes = 15,
    ): array {
        $this->assertEnvironmentBoundary();
        $sourceCode = trim($sourceCode);
        $targetCode = trim($targetCode);
        $this->assertArguments($sourceCode, $targetCode, $durationMinutes, $appointmentOffsetMinutes);

        return DB::transaction(function () use ($sourceCode, $targetCode, $durationMinutes, $appointmentOffsetMinutes): array {
            $source = SimulationSession::query()
                ->where('code', $sourceCode)
                ->lockForUpdate()
                ->first();

            if (! $source) {
                throw new DomainException("Reference source session {$sourceCode} was not found.");
            }

            if (SimulationSession::query()->where('code', $targetCode)->lockForUpdate()->exists()) {
                throw new DomainException("Target session {$targetCode} already exists; no records were changed.");
            }

            $sourceGraph = $this->pristineSourceGraph($source);
            $startsAt = CarbonImmutable::now();
            $endsAt = $startsAt->addMinutes($durationMinutes);
            $seed = strtoupper(substr((string) Str::ulid(), -12));

            $target = SimulationSession::query()->create([
                'scenario_id' => $source->scenario_id,
                'code' => $targetCode,
                'course_code' => $source->course_code,
                'cohort_code' => $source->cohort_code,
                'environment_mode' => EnvironmentMode::Simulation,
                'status' => SessionStatus::Active,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'facilitator_user_id' => $source->facilitator_user_id,
                'source_session_id' => $source->getKey(),
            ]);

            $targetPatient = $this->clonePatient($sourceGraph['patient'], $target, $seed);
            $this->clonePopulationPatients($sourceGraph['populationPatients'], $target, $seed);
            $assignmentMap = $this->cloneAssignments(
                $sourceGraph['assignments'],
                $target,
                $startsAt,
                $endsAt,
            );
            $targetAppointment = $this->cloneAppointment(
                $sourceGraph['appointment'],
                $target,
                $targetPatient,
                $assignmentMap,
                $startsAt,
                $appointmentOffsetMinutes,
                $seed,
            );
            $targetEncounter = $this->cloneEncounter(
                $sourceGraph['encounter'],
                $target,
                $targetPatient,
                $targetAppointment,
                $seed,
            );

            $this->scopeAssignments(
                $sourceGraph['assignments'],
                $assignmentMap,
                $sourceGraph['patient'],
                $sourceGraph['encounter'],
                $targetPatient,
                $targetEncounter,
            );
            $this->linkSupervisors($sourceGraph['assignments'], $assignmentMap);
            $this->cloneInitialTransition(
                $sourceGraph['transition'],
                $assignmentMap,
                $targetEncounter,
                $startsAt,
            );
            $this->cloneTasks(
                $sourceGraph['tasks'],
                $assignmentMap,
                $target,
                $targetEncounter,
                $startsAt,
            );
            $this->cloneStocks($sourceGraph['stocks'], $target, $endsAt, $seed);

            $this->auditRecorder->record(
                action: 'simulation_session.reference_cloned',
                resourceType: 'simulation_session',
                resourceId: $target->public_id,
                session: $target,
                patient: $targetPatient,
                encounter: $targetEncounter,
                metadata: [
                    'source_session_public_id' => $source->public_id,
                    'source_session_code' => $source->code,
                    'duration_minutes' => $durationMinutes,
                    'appointment_offset_minutes' => $appointmentOffsetMinutes,
                    'assignment_count' => $assignmentMap->count(),
                    'task_count' => $sourceGraph['tasks']->count(),
                    'stock_lot_count' => $sourceGraph['stocks']->count(),
                    'population_patient_count' => $sourceGraph['populationPatients']->count(),
                    'synthetic_only' => true,
                    'progressed_state_copied' => false,
                ],
                includeRequestFingerprint: false,
            );

            return [
                'state' => 'CREATED',
                'sourceSession' => [
                    'code' => $source->code,
                    'publicId' => $source->public_id,
                ],
                'session' => [
                    'code' => $target->code,
                    'publicId' => $target->public_id,
                    'status' => $target->status->value,
                    'startsAt' => $target->starts_at->toIso8601String(),
                    'endsAt' => $target->ends_at?->toIso8601String(),
                ],
                'appointment' => [
                    'code' => $targetAppointment->appointment_code,
                    'status' => $targetAppointment->status->value,
                    'scheduledAt' => $targetAppointment->scheduled_at->toIso8601String(),
                ],
                'encounter' => [
                    'number' => $targetEncounter->encounter_number,
                    'status' => $targetEncounter->status->value,
                ],
                'counts' => [
                    'assignments' => $assignmentMap->count(),
                    'tasks' => $sourceGraph['tasks']->count(),
                    'stockLots' => $sourceGraph['stocks']->count(),
                ],
                'synthetic' => true,
                'progressedStateCopied' => false,
            ];
        }, 3);
    }

    private function assertEnvironmentBoundary(): void
    {
        if (app()->environment('production')
            || config('simulation.mode') !== EnvironmentMode::Simulation->value
            || config('simulation.synthetic_only') !== true
            || config('simulation.demo_seed_enabled') !== true) {
            throw new DomainException('Reference-session cloning is allowed only in an explicitly opted-in, synthetic-only, non-production SIMULATION environment.');
        }
    }

    private function assertArguments(
        string $sourceCode,
        string $targetCode,
        int $durationMinutes,
        int $appointmentOffsetMinutes,
    ): void {
        foreach (['source' => $sourceCode, 'target' => $targetCode] as $label => $code) {
            if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,63}$/', $code) !== 1) {
                throw new DomainException("The {$label} session code must contain 3–64 uppercase letters, numbers, or hyphens.");
            }
        }

        if ($sourceCode === $targetCode) {
            throw new DomainException('The target session code must differ from its source.');
        }

        if ($durationMinutes < self::MIN_DURATION_MINUTES
            || $durationMinutes > self::MAX_DURATION_MINUTES) {
            throw new DomainException('Clone duration must be between 30 and 10,080 minutes.');
        }

        if ($appointmentOffsetMinutes < self::MIN_APPOINTMENT_OFFSET_MINUTES
            || $appointmentOffsetMinutes > $durationMinutes) {
            throw new DomainException('Appointment offset must be between -1,440 minutes and the clone duration.');
        }
    }

    /**
     * @return array{
     *   patient: SyntheticPatient,
     *   populationPatients: Collection<int, SyntheticPatient>,
     *   appointment: AppointmentRegistration,
     *   encounter: Encounter,
     *   transition: EncounterTransition,
     *   assignments: Collection<int, Assignment>,
     *   tasks: Collection<int, WorkTask>,
     *   stocks: Collection<int, MedicationStock>
     * }
     */
    private function pristineSourceGraph(SimulationSession $source): array
    {
        $source->load(['scenario', 'facilitator']);

        if ($source->environment_mode !== EnvironmentMode::Simulation
            || $source->status !== SessionStatus::Active
            || $source->scenario->code !== 'OPD-REF-001'
            || $source->scenario->version !== 1
            || $source->scenario->status !== ScenarioStatus::Published
            || data_get($source->scenario->fixture_spec, 'synthetic_only') !== true
            || data_get($source->scenario->fixture_spec, 'fixture_set') !== 'OPD-REF-001-v1') {
            throw new DomainException('The source is not the published OPD-REF-001-v1 synthetic reference session.');
        }

        $patients = SyntheticPatient::query()
            ->where('session_id', $source->getKey())
            ->with('identifiers')
            ->lockForUpdate()
            ->get();
        $appointments = AppointmentRegistration::query()
            ->where('session_id', $source->getKey())
            ->lockForUpdate()
            ->get();
        $encounters = Encounter::query()
            ->where('session_id', $source->getKey())
            ->with('location')
            ->lockForUpdate()
            ->get();

        $referencePatients = $patients->where('fixture_source', 'OPD-REF-001-v1')->values();
        $populationPatients = $patients
            ->filter(fn (SyntheticPatient $patient): bool => str_starts_with((string) $patient->fixture_source, 'OPD-POP-'))
            ->values();

        if ($referencePatients->count() !== 1
            || $appointments->count() !== 1
            || $encounters->count() !== 1
            || $patients->count() !== ($referencePatients->count() + $populationPatients->count())) {
            throw new DomainException('A clone source must contain exactly one reference patient, appointment, and encounter, plus only optional OPD-POP population patients.');
        }

        $patient = $referencePatients->sole();
        $appointment = $appointments->sole();
        $encounter = $encounters->sole();

        if (! $patient->synthetic_flag
            || $patient->fixture_source !== 'OPD-REF-001-v1'
            || $patient->record_status !== PatientRecordStatus::Active
            || $appointment->patient_id !== $patient->getKey()
            || $appointment->status !== AppointmentStatus::Booked
            || $appointment->checked_in_at !== null
            || $encounter->patient_id !== $patient->getKey()
            || $encounter->appointment_registration_id !== $appointment->getKey()
            || $encounter->status !== EncounterStatus::Planned
            || $encounter->environment_mode !== EnvironmentMode::Simulation
            || $encounter->period_start !== null
            || $encounter->period_end !== null
            || $encounter->closure_requested_at !== null
            || $encounter->clinically_closed_at !== null
            || $encounter->finalized_at !== null
            || ! $encounter->location->is_active) {
            throw new DomainException('The reference source has progressed or no longer matches the pristine planned fixture.');
        }

        foreach ($populationPatients as $populationPatient) {
            if (! $populationPatient->synthetic_flag
                || $populationPatient->record_status !== PatientRecordStatus::Active
                || $populationPatient->deceased_flag
                || $appointments->contains('patient_id', $populationPatient->getKey())
                || $encounters->contains('patient_id', $populationPatient->getKey())) {
                throw new DomainException('A population patient is progressed or unsafe and cannot be cloned with the pristine reference.');
            }
        }

        $this->assertPatientFixture($patient);
        $this->assertIdentifiers($patient);
        $assignments = $this->sourceAssignments($source, $patient, $encounter);
        $tasks = $this->sourceTasks($source, $encounter, $assignments);
        $stocks = $this->sourceStocks($source);
        $transition = EncounterTransition::query()
            ->where('encounter_id', $encounter->getKey())
            ->lockForUpdate()
            ->sole();

        if ($transition->from_status !== null
            || $transition->to_status !== EncounterStatus::Planned
            || ! $assignments->contains('id', $transition->actor_assignment_id)) {
            throw new DomainException('The source initial transition is incomplete or crosses assignment scope.');
        }

        foreach (self::PROGRESSED_ENCOUNTER_TABLES as $table) {
            if (DB::table($table)->where('encounter_id', $encounter->getKey())->exists()) {
                throw new DomainException("The source contains progressed state in {$table} and cannot be cloned.");
            }
        }

        if (DB::table('medication_stock_movements')->where('session_id', $source->getKey())->exists()) {
            throw new DomainException('The source stock ledger has progressed and cannot be cloned.');
        }

        return compact('patient', 'populationPatients', 'appointment', 'encounter', 'transition', 'assignments', 'tasks', 'stocks');
    }

    private function assertPatientFixture(SyntheticPatient $patient): void
    {
        if ($patient->full_name !== 'Pasien Sintetis Arunika'
            || $patient->birth_date->toDateString() !== '1992-04-18'
            || $patient->administrative_sex !== AdministrativeSex::Female
            || $patient->address !== null
            || $patient->phone !== null
            || $patient->email !== null
            || $patient->religion !== null
            || $patient->occupation !== null
            || $patient->education !== null
            || $patient->marital_status !== null
            || $patient->deceased_flag) {
            throw new DomainException('The source patient no longer matches the reserved OPD-REF-001-v1 synthetic identity fixture.');
        }
    }

    private function assertIdentifiers(SyntheticPatient $patient): void
    {
        $identifiers = $patient->identifiers;
        $types = $identifiers->pluck('type')->map(
            fn (IdentifierType $type): string => $type->value,
        )->sort()->values()->all();

        $mrn = $identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $syntheticNik = $identifiers->firstWhere('type', IdentifierType::SyntheticNationalId);

        if ($identifiers->count() !== 2
            || $types !== [IdentifierType::MedicalRecordNumber->value, IdentifierType::SyntheticNationalId->value]
            || ! $mrn instanceof PatientIdentifier
            || $mrn->system !== PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX.'mrn'
            || preg_match('/^MR-SIM-(?:000001|[A-Z0-9]{12})$/', $mrn->value) !== 1
            || ! $syntheticNik instanceof PatientIdentifier
            || $syntheticNik->system !== PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX.'nik'
            || preg_match('/^SYN-NIK-(?:000001|[A-Z0-9]{12})$/', $syntheticNik->value) !== 1
            || $identifiers->contains(fn (PatientIdentifier $identifier): bool => ! $identifier->synthetic_flag
                || $identifier->status !== IdentifierStatus::Active
                || ! Str::startsWith($identifier->system, PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX))) {
            throw new DomainException('The source must contain exactly one active synthetic MRN and one active synthetic NIK-like identifier.');
        }
    }

    /**
     * @return Collection<int, Assignment>
     */
    private function sourceAssignments(
        SimulationSession $source,
        SyntheticPatient $patient,
        Encounter $encounter,
    ): Collection {
        $assignments = Assignment::query()
            ->where('session_id', $source->getKey())
            ->with('user')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($assignments->count() !== self::EXPECTED_ASSIGNMENT_COUNT
            || $assignments->pluck('user_id')->unique()->count() !== self::EXPECTED_ASSIGNMENT_COUNT
            || $assignments->contains(fn (Assignment $assignment): bool => $assignment->revoked_at !== null
                || $assignment->active_from->isFuture()
                || ($assignment->active_until !== null && $assignment->active_until->isPast())
                || $assignment->user->status !== 'ACTIVE'
                || ! Str::endsWith($assignment->user->email, '@example.invalid')
                || $assignment->capabilities === [])) {
            throw new DomainException('The reference source must retain ten active reserved demo-account assignments.');
        }

        $facilitators = $assignments->filter(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::SessionFacilitate));
        $registrars = $assignments->filter(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PatientRegister));

        if ($facilitators->count() !== 1
            || $facilitators->sole()->user_id !== $source->facilitator_user_id
            || $registrars->count() !== 1) {
            throw new DomainException('The source facilitator or registrar assignment is ambiguous.');
        }

        foreach ($assignments as $assignment) {
            $unscoped = $assignment->patient_id === null && $assignment->encounter_id === null;
            $caseScoped = $assignment->patient_id === $patient->getKey()
                && $assignment->encounter_id === $encounter->getKey();

            if (! $unscoped && ! $caseScoped) {
                throw new DomainException('Every source assignment must be either session-wide or scoped to the exact reference case.');
            }

            if ($assignment->supervisor_assignment_id !== null
                && ! $assignments->contains('id', $assignment->supervisor_assignment_id)) {
                throw new DomainException('A source supervisor link leaves the reference session.');
            }
        }

        return $assignments;
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     * @return Collection<int, WorkTask>
     */
    private function sourceTasks(
        SimulationSession $source,
        Encounter $encounter,
        Collection $assignments,
    ): Collection {
        $tasks = WorkTask::query()
            ->where('session_id', $source->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $states = $tasks->mapWithKeys(
            fn (WorkTask $task): array => [$task->task_type->value => $task->status->value],
        )->sortKeys()->all();
        $expected = collect(self::EXPECTED_TASK_STATES)->sortKeys()->all();

        if ($tasks->count() !== count(self::EXPECTED_TASK_STATES)
            || $states !== $expected
            || $tasks->contains(fn (WorkTask $task): bool => $task->encounter_id !== $encounter->getKey()
                || $task->clinical_entry_id !== null
                || $task->clinical_entry_version_id !== null
                || ! $assignments->contains('id', $task->assignment_id)
                || data_get($task->context, 'synthetic') !== true
                || data_get($task->context, 'caseLabel') !== $encounter->encounter_number
                || count($task->context ?? []) !== 2)) {
            throw new DomainException('The source work queue does not match the four-task pristine reference contract.');
        }

        return $tasks;
    }

    /** @return Collection<int, MedicationStock> */
    private function sourceStocks(SimulationSession $source): Collection
    {
        $stocks = MedicationStock::query()
            ->where('session_id', $source->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($stocks->isEmpty()
            || $stocks->contains(fn (MedicationStock $stock): bool => ! $stock->synthetic_flag
                || (float) $stock->quantity_on_hand < 0)) {
            throw new DomainException('The source must contain untouched non-negative synthetic stock.');
        }

        return $stocks;
    }

    /**
     * @param  Collection<int, SyntheticPatient>  $populationPatients
     */
    private function clonePopulationPatients(
        Collection $populationPatients,
        SimulationSession $target,
        string $seed,
    ): void {
        foreach ($populationPatients->values() as $index => $source) {
            $this->clonePatient($source, $target, sprintf('%s-P%02d', $seed, $index + 1));
        }
    }

    private function clonePatient(
        SyntheticPatient $source,
        SimulationSession $target,
        string $seed,
    ): SyntheticPatient {
        $patient = SyntheticPatient::query()->create([
            'session_id' => $target->getKey(),
            'synthetic_flag' => true,
            'fixture_source' => $source->fixture_source,
            'full_name' => $source->full_name,
            'birth_date' => $source->birth_date,
            'administrative_sex' => $source->administrative_sex,
            'address' => $source->address,
            'phone' => $source->phone,
            'email' => $source->email,
            'religion' => $source->religion,
            'occupation' => $source->occupation,
            'education' => $source->education,
            'marital_status' => $source->marital_status,
            'deceased_flag' => $source->deceased_flag,
            'record_status' => PatientRecordStatus::Active,
        ]);

        foreach ($source->identifiers->sortBy('type')->values() as $identifier) {
            $value = match ($identifier->type) {
                IdentifierType::MedicalRecordNumber => 'MR-SIM-'.$seed,
                IdentifierType::SyntheticNationalId => 'SYN-NIK-'.$seed,
                default => throw new DomainException('The reference clone encountered an unsupported identifier type.'),
            };

            PatientIdentifier::query()->create([
                'patient_id' => $patient->getKey(),
                'type' => $identifier->type,
                'system' => $identifier->system,
                'value' => $value,
                'synthetic_flag' => true,
                'valid_from' => now(),
                'valid_until' => null,
                'status' => IdentifierStatus::Active,
            ]);
        }

        return $patient;
    }

    /**
     * @param  Collection<int, Assignment>  $sources
     * @return Collection<int, Assignment>
     */
    private function cloneAssignments(
        Collection $sources,
        SimulationSession $target,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): Collection {
        return $sources->mapWithKeys(function (Assignment $source) use ($target, $startsAt, $endsAt): array {
            $assignment = Assignment::query()->create([
                'session_id' => $target->getKey(),
                'user_id' => $source->user_id,
                'program' => $source->program,
                'application_role' => $source->application_role,
                'capabilities' => $source->capabilities,
                'patient_id' => null,
                'encounter_id' => null,
                'supervisor_assignment_id' => null,
                'active_from' => $startsAt,
                'active_until' => $endsAt,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revocation_reason' => null,
            ]);

            return [$source->getKey() => $assignment];
        });
    }

    /** @param Collection<int, Assignment> $assignmentMap */
    private function cloneAppointment(
        AppointmentRegistration $source,
        SimulationSession $target,
        SyntheticPatient $patient,
        Collection $assignmentMap,
        CarbonImmutable $startsAt,
        int $appointmentOffsetMinutes,
        string $seed,
    ): AppointmentRegistration {
        $registrar = $assignmentMap->get($source->registered_by_assignment_id);

        if (! $registrar instanceof Assignment || ! $registrar->hasCapability(Capability::PatientRegister)) {
            throw new DomainException('The source registration author cannot be remapped to the clone.');
        }

        return AppointmentRegistration::query()->create([
            'request_key' => (string) Str::ulid(),
            'session_id' => $target->getKey(),
            'patient_id' => $patient->getKey(),
            'location_id' => $source->location_id,
            'registered_by_assignment_id' => $registrar->getKey(),
            'appointment_code' => 'APT-SIM-'.$seed,
            'scheduled_at' => $startsAt->addMinutes($appointmentOffsetMinutes),
            'visit_reason' => $source->visit_reason,
            'visit_source' => $source->visit_source,
            'coverage_status' => $source->coverage_status,
            'identity_verification_method' => $source->identity_verification_method,
            'consent_version' => $source->consent_version,
            'consent_acknowledged_at' => $startsAt,
            'duplicate_decision' => $source->duplicate_decision,
            'duplicate_reason' => $source->duplicate_reason,
            'status' => AppointmentStatus::Booked,
            'checked_in_at' => null,
        ]);
    }

    private function cloneEncounter(
        Encounter $source,
        SimulationSession $target,
        SyntheticPatient $patient,
        AppointmentRegistration $appointment,
        string $seed,
    ): Encounter {
        return Encounter::query()->create([
            'session_id' => $target->getKey(),
            'patient_id' => $patient->getKey(),
            'appointment_registration_id' => $appointment->getKey(),
            'location_id' => $source->location_id,
            'encounter_number' => 'ENC-SIM-'.$seed,
            'class' => $source->class,
            'service_type_code' => $source->service_type_code,
            'service_type_display' => $source->service_type_display,
            'status' => EncounterStatus::Planned,
            'period_start' => null,
            'period_end' => null,
            'disposition' => null,
            'closure_requested_at' => null,
            'clinically_closed_at' => null,
            'finalized_at' => null,
            'environment_mode' => EnvironmentMode::Simulation,
        ]);
    }

    /**
     * @param  Collection<int, Assignment>  $sources
     * @param  Collection<int, Assignment>  $targets
     */
    private function scopeAssignments(
        Collection $sources,
        Collection $targets,
        SyntheticPatient $sourcePatient,
        Encounter $sourceEncounter,
        SyntheticPatient $targetPatient,
        Encounter $targetEncounter,
    ): void {
        foreach ($sources as $source) {
            $target = $targets->get($source->getKey());

            if (! $target instanceof Assignment) {
                throw new DomainException('A source assignment was not cloned.');
            }

            if ($source->patient_id === null && $source->encounter_id === null) {
                continue;
            }

            if ($source->patient_id !== $sourcePatient->getKey()
                || $source->encounter_id !== $sourceEncounter->getKey()) {
                throw new DomainException('A source assignment cannot be remapped outside the exact reference case.');
            }

            $target->update([
                'patient_id' => $targetPatient->getKey(),
                'encounter_id' => $targetEncounter->getKey(),
            ]);
        }
    }

    /**
     * @param  Collection<int, Assignment>  $sources
     * @param  Collection<int, Assignment>  $targets
     */
    private function linkSupervisors(Collection $sources, Collection $targets): void
    {
        foreach ($sources->whereNotNull('supervisor_assignment_id') as $source) {
            $target = $targets->get($source->getKey());
            $supervisor = $targets->get($source->supervisor_assignment_id);

            if (! $target instanceof Assignment || ! $supervisor instanceof Assignment) {
                throw new DomainException('A source supervision link could not be remapped.');
            }

            $target->update(['supervisor_assignment_id' => $supervisor->getKey()]);
        }
    }

    /** @param Collection<int, Assignment> $assignmentMap */
    private function cloneInitialTransition(
        EncounterTransition $source,
        Collection $assignmentMap,
        Encounter $targetEncounter,
        CarbonImmutable $occurredAt,
    ): void {
        $actor = $assignmentMap->get($source->actor_assignment_id);

        if (! $actor instanceof Assignment) {
            throw new DomainException('The initial transition actor could not be remapped.');
        }

        EncounterTransition::query()->create([
            'encounter_id' => $targetEncounter->getKey(),
            'actor_assignment_id' => $actor->getKey(),
            'from_status' => null,
            'to_status' => EncounterStatus::Planned,
            'reason' => 'reference_session_cloned',
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @param  Collection<int, WorkTask>  $sources
     * @param  Collection<int, Assignment>  $assignmentMap
     */
    private function cloneTasks(
        Collection $sources,
        Collection $assignmentMap,
        SimulationSession $target,
        Encounter $targetEncounter,
        CarbonImmutable $occurredAt,
    ): void {
        foreach ($sources as $source) {
            $assignment = $assignmentMap->get($source->assignment_id);

            if (! $assignment instanceof Assignment) {
                throw new DomainException('A source task assignment could not be remapped.');
            }

            WorkTask::query()->create([
                'session_id' => $target->getKey(),
                'assignment_id' => $assignment->getKey(),
                'encounter_id' => $targetEncounter->getKey(),
                'clinical_entry_id' => null,
                'clinical_entry_version_id' => null,
                'task_type' => $source->task_type,
                'title' => $source->title,
                'description' => $source->description,
                'status' => $source->status,
                'priority' => $source->priority,
                'source_program' => $source->source_program,
                'context' => [
                    'caseLabel' => $targetEncounter->encounter_number,
                    'synthetic' => true,
                ],
                'available_at' => $source->available_at === null ? null : $occurredAt,
                'completed_at' => $source->completed_at === null ? null : $occurredAt,
            ]);
        }
    }

    /** @param Collection<int, MedicationStock> $sources */
    private function cloneStocks(
        Collection $sources,
        SimulationSession $target,
        CarbonImmutable $targetEndsAt,
        string $seed,
    ): void {
        foreach ($sources->values() as $index => $source) {
            if ($source->expires_on->endOfDay()->lessThan($targetEndsAt)) {
                throw new DomainException('A source stock lot expires before the requested clone ends.');
            }

            MedicationStock::query()->create([
                'session_id' => $target->getKey(),
                'authored_medication' => $source->authored_medication,
                'form' => $source->form,
                'strength' => $source->strength,
                'lot_number' => 'LOT-SIM-'.$seed.'-'.($index + 1),
                'expires_on' => $source->expires_on,
                'quantity_on_hand' => $source->quantity_on_hand,
                'unit' => $source->unit,
                'synthetic_flag' => true,
            ]);
        }
    }
}
