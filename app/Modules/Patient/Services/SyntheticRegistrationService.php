<?php

namespace App\Modules\Patient\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Models\ServiceLocation;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\DuplicateDecision;
use App\Modules\Patient\Enums\IdentifierStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Enums\PatientRecordStatus;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\PatientIdentifier;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyntheticRegistrationService
{
    public function __construct(
        private readonly PatientSearchService $patientSearch,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(
        SimulationSession $session,
        Assignment $assignment,
        ServiceLocation $location,
        array $data,
    ): AppointmentRegistration {
        $lockKey = "outpatient-registration:session:{$session->getKey()}";

        return Cache::lock($lockKey, 30)->block(5, fn (): AppointmentRegistration => DB::transaction(function () use ($session, $assignment, $location, $data): AppointmentRegistration {
            $session = SimulationSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $assignment = Assignment::query()
                ->active()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->first();
            $location = ServiceLocation::query()
                ->whereKey($location->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($session->environment_mode !== EnvironmentMode::Simulation
                || $session->status !== SessionStatus::Active
                || ! $assignment
                || $assignment->session_id !== $session->getKey()
                || ! $assignment->hasCapability(Capability::PatientRegister)
                || ! $location) {
                throw new DomainException('Registration requires an active simulation session, registrar assignment, and service location.');
            }

            $existing = AppointmentRegistration::query()
                ->where('session_id', $session->getKey())
                ->where('request_key', $data['request_key'])
                ->with(['patient.identifiers', 'encounter', 'location'])
                ->first();

            if ($existing) {
                return $existing;
            }

            if (Encounter::query()->where('session_id', $session->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'request_key' => 'Satu sesi simulasi referensi hanya dapat memiliki satu encounter bersama. Clone atau buat sesi baru untuk kasus berikutnya.',
                ]);
            }

            $existingPatientPublicId = $data['existing_patient_public_id'] ?? null;
            $patient = null;
            $duplicates = collect();
            $decision = DuplicateDecision::from((string) ($data['duplicate_decision'] ?? DuplicateDecision::NoCandidate->value));

            if (is_string($existingPatientPublicId) && $existingPatientPublicId !== '') {
                $patient = SyntheticPatient::query()
                    ->where('session_id', $session->getKey())
                    ->where('public_id', $existingPatientPublicId)
                    ->with('identifiers')
                    ->lockForUpdate()
                    ->firstOrFail();
                $decision = DuplicateDecision::UseExisting;
            } else {
                $duplicates = $this->patientSearch->possibleDuplicates(
                    $session,
                    (string) $data['full_name'],
                    (string) $data['birth_date'],
                );
            }

            if ($duplicates->isNotEmpty() && $decision !== DuplicateDecision::CreateNew) {
                throw ValidationException::withMessages([
                    'duplicate_decision' => 'Ditemukan kandidat pasien sintetis. Pilih rekam yang ada atau nyatakan alasan membuat rekam baru.',
                ]);
            }

            if ($duplicates->isNotEmpty() && blank($data['duplicate_reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'duplicate_reason' => 'Alasan membuat rekam baru wajib diisi ketika kandidat duplikat ditemukan.',
                ]);
            }

            $seed = strtoupper(substr((string) Str::ulid(), -10));
            $session->loadMissing('scenario');

            if (! $patient) {
                $patient = SyntheticPatient::query()->create([
                    'session_id' => $session->getKey(),
                    'synthetic_flag' => true,
                    'fixture_source' => "{$session->scenario->code}-v{$session->scenario->version}",
                    'full_name' => $data['full_name'],
                    'birth_date' => $data['birth_date'],
                    'administrative_sex' => $data['administrative_sex'],
                    'deceased_flag' => false,
                    'record_status' => $duplicates->isEmpty()
                        ? PatientRecordStatus::Active
                        : PatientRecordStatus::PotentialDuplicate,
                ]);

                $mrn = PatientIdentifier::query()->create([
                    'patient_id' => $patient->getKey(),
                    'type' => IdentifierType::MedicalRecordNumber,
                    'system' => PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX.'mrn',
                    'value' => 'MR-SIM-'.$seed,
                    'synthetic_flag' => true,
                    'valid_from' => now(),
                    'status' => IdentifierStatus::Active,
                ]);

                PatientIdentifier::query()->create([
                    'patient_id' => $patient->getKey(),
                    'type' => IdentifierType::SyntheticNationalId,
                    'system' => PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX.'nik',
                    'value' => 'SYN-NIK-'.$seed,
                    'synthetic_flag' => true,
                    'valid_from' => now(),
                    'status' => IdentifierStatus::Active,
                ]);
            } else {
                $mrn = $patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
            }

            $appointment = AppointmentRegistration::query()->create([
                'request_key' => $data['request_key'],
                'session_id' => $session->getKey(),
                'patient_id' => $patient->getKey(),
                'location_id' => $location->getKey(),
                'registered_by_assignment_id' => $assignment->getKey(),
                'appointment_code' => 'APT-SIM-'.$seed,
                'scheduled_at' => $data['scheduled_at'],
                'visit_reason' => $data['visit_reason'],
                'visit_source' => $data['visit_source'],
                'coverage_status' => 'SIMULATION_SELF_PAY',
                'identity_verification_method' => $data['identity_verification_method'],
                'consent_version' => 'TEACHING-SIM-1.0',
                'consent_acknowledged_at' => now(),
                'duplicate_decision' => $patient->wasRecentlyCreated && $duplicates->isEmpty()
                    ? DuplicateDecision::NoCandidate
                    : $decision,
                'duplicate_reason' => $data['duplicate_reason'] ?? null,
                'status' => AppointmentStatus::Booked,
            ]);

            $encounter = Encounter::query()->create([
                'session_id' => $session->getKey(),
                'patient_id' => $patient->getKey(),
                'appointment_registration_id' => $appointment->getKey(),
                'location_id' => $location->getKey(),
                'encounter_number' => 'ENC-SIM-'.$seed,
                'class' => 'AMBULATORY',
                'service_type_code' => $location->code,
                'service_type_display' => $location->name,
                'status' => EncounterStatus::Planned,
                'environment_mode' => EnvironmentMode::Simulation,
            ]);

            EncounterTransition::query()->create([
                'encounter_id' => $encounter->getKey(),
                'actor_assignment_id' => $assignment->getKey(),
                'from_status' => null,
                'to_status' => EncounterStatus::Planned,
                'reason' => 'synthetic_registration_created',
                'occurred_at' => now(),
            ]);

            $caseAssignments = Assignment::query()
                ->active()
                ->where('session_id', $session->getKey())
                ->whereNull('patient_id')
                ->whereNull('encounter_id')
                ->get()
                ->reject(fn (Assignment $candidate): bool => $candidate->hasCapability(Capability::PatientRegister)
                    || $candidate->hasCapability(Capability::SessionFacilitate));

            foreach ($caseAssignments as $caseAssignment) {
                $caseAssignment->forceFill([
                    'patient_id' => $patient->getKey(),
                    'encounter_id' => $encounter->getKey(),
                ])->save();
            }

            $this->auditRecorder->record(
                action: 'synthetic_registration.created',
                resourceType: 'appointment_registration',
                resourceId: $appointment->public_id,
                actor: $assignment->user,
                assignment: $assignment,
                session: $session,
                patient: $patient,
                encounter: $encounter,
                reason: $data['duplicate_reason'] ?? null,
                metadata: [
                    'appointment_code' => $appointment->appointment_code,
                    'mrn' => $mrn?->value,
                    'duplicate_candidate_count' => $duplicates->count(),
                    'duplicate_decision' => $appointment->duplicate_decision?->value,
                    'synthetic_only' => true,
                ],
            );

            return $appointment->load(['patient.identifiers', 'encounter', 'location']);
        }));
    }
}
