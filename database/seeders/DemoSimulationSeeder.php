<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Models\ServiceLocation;
use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Enums\DuplicateDecision;
use App\Modules\Patient\Enums\IdentifierStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Enums\PatientRecordStatus;
use App\Modules\Patient\Enums\VisitSource;
use App\Modules\Patient\Models\AppointmentRegistration;
use App\Modules\Patient\Models\PatientIdentifier;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\ScenarioStatus;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationScenario;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoSimulationSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('simulation.demo_account_password');

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('DEMO_ACCOUNT_PASSWORD must contain at least 12 characters before demo fixtures can be seeded.');
        }

        $facilitator = $this->seedUser('fasilitator.simulasi@example.invalid', 'Fasilitator Simulasi UEU', $password);
        $registrar = $this->seedUser('mahasiswa.rmik@example.invalid', 'Mahasiswa RMIK Demo', $password);
        $rmikCoder = $this->seedUser('koder.rmik@example.invalid', 'Koder RMIK Demo', $password);
        $rmikSupervisor = $this->seedUser('supervisor.rmik@example.invalid', 'Supervisor RMIK Demo', $password);
        $nursingLearner = $this->seedUser('mahasiswa.keperawatan@example.invalid', 'Mahasiswa Keperawatan Demo', $password);
        $nursingSupervisor = $this->seedUser('supervisor.keperawatan@example.invalid', 'Supervisor Keperawatan Demo', $password);
        $medicalLearner = $this->seedUser('mahasiswa.kedokteran@example.invalid', 'Mahasiswa Kedokteran Demo', $password);
        $medicalSupervisor = $this->seedUser('supervisor.kedokteran@example.invalid', 'Supervisor Kedokteran Demo', $password);
        $pharmacyLearner = $this->seedUser('mahasiswa.farmasi@example.invalid', 'Mahasiswa Farmasi Demo', $password);
        $pharmacySupervisor = $this->seedUser('supervisor.farmasi@example.invalid', 'Supervisor Farmasi Demo', $password);

        $scenario = SimulationScenario::query()->updateOrCreate(
            ['code' => 'OPD-REF-001', 'version' => 1],
            [
                'title' => 'Kunjungan Rawat Jalan Terintegrasi — Data Sintetis',
                'status' => ScenarioStatus::Published,
                'learning_outcomes' => [
                    'Menelusuri satu alur encounter lintas program studi.',
                    'Menerapkan asesmen awal dan skrining keselamatan rawat jalan.',
                    'Membedakan dokumentasi, supervisi, dan penutupan rekam medis.',
                ],
                'rubric_references' => [[
                    'code' => 'UEU-OPD-IPE-DRAFT-001',
                    'title' => 'Referensi rubrik perjalanan rawat jalan lintas profesi',
                    'version' => 'DRAFT-0.1',
                    'status' => 'PENDING_PROGRAM_REVIEW',
                    'source_label' => 'Rancangan internal SIMRS Campus UEU',
                    'learning_outcome_numbers' => [1, 2, 3],
                ]],
                'fixture_spec' => [
                    'synthetic_only' => true,
                    'fixture_set' => 'OPD-REF-001-v1',
                ],
                'ruleset_version' => 'OPD-REF-2026.07',
                'published_at' => now(),
            ],
        );

        $session = SimulationSession::query()->updateOrCreate(
            ['code' => 'SIM-RJ-UEU-001'],
            [
                'scenario_id' => $scenario->getKey(),
                'course_code' => 'SIMRS-RJ',
                'cohort_code' => 'REFERENSI-2026',
                'environment_mode' => EnvironmentMode::Simulation,
                'status' => SessionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(6),
                'facilitator_user_id' => $facilitator->getKey(),
            ],
        );

        MedicationStock::query()->firstOrCreate(
            [
                'session_id' => $session->getKey(),
                'lot_number' => 'LOT-SIM-A-001',
            ],
            [
                'authored_medication' => 'Obat Simulasi A',
                'form' => 'Tablet',
                'strength' => '500 mg',
                'expires_on' => now()->addYear()->toDateString(),
                'quantity_on_hand' => 100,
                'unit' => 'tablet',
                'synthetic_flag' => true,
            ],
        );

        $location = ServiceLocation::query()->updateOrCreate(
            ['code' => 'POLI-UMUM-SIM'],
            [
                'name' => 'Poliklinik Umum Simulasi UEU',
                'type' => 'OUTPATIENT_CLINIC',
                'is_active' => true,
            ],
        );

        $facilitatorAssignment = $this->seedAssignment(
            session: $session,
            user: $facilitator,
            program: Program::Facilitation,
            role: ApplicationRole::Facilitator,
            capabilities: [
                Capability::SessionView,
                Capability::SessionFacilitate,
                Capability::SafetyDispositionRecord,
                Capability::EarlyDepartureRecord,
                Capability::TerminologyManage,
                Capability::ClaimReview,
                Capability::DebriefView,
                Capability::DebriefWrite,
                Capability::ReportView,
            ],
        );
        $registrarAssignment = $this->seedAssignment(
            session: $session,
            user: $registrar,
            program: Program::Rmik,
            role: ApplicationRole::Registrar,
            capabilities: [
                Capability::SessionView,
                Capability::PatientSearch,
                Capability::PatientRegister,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $rmikCoderAssignment = $this->seedAssignment(
            session: $session,
            user: $rmikCoder,
            program: Program::Rmik,
            role: ApplicationRole::Coder,
            capabilities: [
                Capability::SessionView,
                Capability::PatientSearch,
                Capability::RecordReview,
                Capability::CodingWrite,
                Capability::ClaimManage,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $rmikSupervisorAssignment = $this->seedAssignment(
            session: $session,
            user: $rmikSupervisor,
            program: Program::Rmik,
            role: ApplicationRole::Supervisor,
            capabilities: [
                Capability::SessionView,
                Capability::SupervisionReview,
                Capability::ClaimReview,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $nursingSupervisorAssignment = $this->seedAssignment(
            session: $session,
            user: $nursingSupervisor,
            program: Program::Nursing,
            role: ApplicationRole::Supervisor,
            capabilities: [
                Capability::SessionView,
                Capability::SupervisionReview,
                Capability::SafetyDispositionRecord,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $nursingAssignment = $this->seedAssignment(
            session: $session,
            user: $nursingLearner,
            program: Program::Nursing,
            role: ApplicationRole::Learner,
            capabilities: [
                Capability::SessionView,
                Capability::PatientSearch,
                Capability::IntakeWrite,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $medicalSupervisorAssignment = $this->seedAssignment(
            session: $session,
            user: $medicalSupervisor,
            program: Program::Medicine,
            role: ApplicationRole::Supervisor,
            capabilities: [
                Capability::SessionView,
                Capability::SupervisionReview,
                Capability::EarlyDepartureRecord,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $medicalAssignment = $this->seedAssignment(
            session: $session,
            user: $medicalLearner,
            program: Program::Medicine,
            role: ApplicationRole::Learner,
            capabilities: [
                Capability::SessionView,
                Capability::PatientSearch,
                Capability::MedicalAssessmentWrite,
                Capability::PrescriptionWrite,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $pharmacySupervisorAssignment = $this->seedAssignment(
            session: $session,
            user: $pharmacySupervisor,
            program: Program::Pharmacy,
            role: ApplicationRole::Supervisor,
            capabilities: [
                Capability::SessionView,
                Capability::SupervisionReview,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );
        $pharmacyAssignment = $this->seedAssignment(
            session: $session,
            user: $pharmacyLearner,
            program: Program::Pharmacy,
            role: ApplicationRole::Learner,
            capabilities: [
                Capability::SessionView,
                Capability::PatientSearch,
                Capability::PharmacyReview,
                Capability::Dispense,
                Capability::DebriefView,
                Capability::ReportView,
            ],
        );

        $patient = SyntheticPatient::query()->updateOrCreate(
            [
                'session_id' => $session->getKey(),
                'fixture_source' => 'OPD-REF-001-v1',
            ],
            [
                'synthetic_flag' => true,
                'full_name' => 'Pasien Sintetis Arunika',
                'birth_date' => '1992-04-18',
                'administrative_sex' => AdministrativeSex::Female,
                'deceased_flag' => false,
                'record_status' => PatientRecordStatus::Active,
            ],
        );

        $this->seedIdentifier(
            patient: $patient,
            type: IdentifierType::MedicalRecordNumber,
            system: PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX.'mrn',
            value: 'MR-SIM-000001',
        );
        $this->seedIdentifier(
            patient: $patient,
            type: IdentifierType::SyntheticNationalId,
            system: PatientIdentifier::SYNTHETIC_SYSTEM_PREFIX.'nik',
            value: 'SYN-NIK-000001',
        );

        $appointment = AppointmentRegistration::query()->updateOrCreate(
            [
                'session_id' => $session->getKey(),
                'appointment_code' => 'APT-SIM-000001',
            ],
            [
                'request_key' => '01J00000000000000000000001',
                'patient_id' => $patient->getKey(),
                'location_id' => $location->getKey(),
                'registered_by_assignment_id' => $registrarAssignment->getKey(),
                'scheduled_at' => now()->addHour(),
                'visit_reason' => 'Evaluasi keluhan rawat jalan dalam skenario pembelajaran.',
                'visit_source' => VisitSource::Scheduled,
                'coverage_status' => 'SIMULATION_SELF_PAY',
                'identity_verification_method' => 'TWO_SYNTHETIC_IDENTIFIERS',
                'consent_version' => 'TEACHING-SIM-1.0',
                'consent_acknowledged_at' => now(),
                'duplicate_decision' => DuplicateDecision::NoCandidate,
                'duplicate_reason' => null,
                'status' => AppointmentStatus::Booked,
                'checked_in_at' => null,
            ],
        );

        $encounter = Encounter::query()->updateOrCreate(
            ['encounter_number' => 'ENC-SIM-000001'],
            [
                'session_id' => $session->getKey(),
                'patient_id' => $patient->getKey(),
                'appointment_registration_id' => $appointment->getKey(),
                'location_id' => $location->getKey(),
                'class' => 'AMBULATORY',
                'service_type_code' => $location->code,
                'service_type_display' => $location->name,
                'status' => EncounterStatus::Planned,
                'period_start' => null,
                'period_end' => null,
                'environment_mode' => EnvironmentMode::Simulation,
            ],
        );

        $nursingSupervisorAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
        ])->save();
        $nursingAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
            'supervisor_assignment_id' => $nursingSupervisorAssignment->getKey(),
        ])->save();
        $medicalSupervisorAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
        ])->save();
        $medicalAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
            'supervisor_assignment_id' => $medicalSupervisorAssignment->getKey(),
        ])->save();
        $pharmacySupervisorAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
        ])->save();
        $pharmacyAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
            'supervisor_assignment_id' => $pharmacySupervisorAssignment->getKey(),
        ])->save();
        $rmikSupervisorAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
        ])->save();
        $rmikCoderAssignment->forceFill([
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
            'supervisor_assignment_id' => $rmikSupervisorAssignment->getKey(),
        ])->save();

        EncounterTransition::query()->firstOrCreate(
            [
                'encounter_id' => $encounter->getKey(),
                'to_status' => EncounterStatus::Planned,
            ],
            [
                'actor_assignment_id' => $registrarAssignment->getKey(),
                'from_status' => null,
                'reason' => 'reference_fixture_created',
                'occurred_at' => now(),
            ],
        );

        $this->seedTask(
            session: $session,
            assignment: $registrarAssignment,
            encounter: $encounter,
            title: 'Verifikasi registrasi dan check-in',
            description: 'Cari pasien sintetis, verifikasi dua identifier, lalu masukkan encounter ke antrean poliklinik.',
            type: WorkTaskType::Registration,
            status: WorkTaskStatus::Ready,
            priority: 1,
            sourceProgram: Program::Rmik,
        );
        $this->seedTask(
            session: $session,
            assignment: $nursingAssignment,
            encounter: $encounter,
            title: 'Orientasi sesi simulasi',
            description: 'Tinjau batas keselamatan, peran, dan tujuan pembelajaran sesi.',
            type: WorkTaskType::SessionOrientation,
            status: WorkTaskStatus::Complete,
            priority: 1,
            sourceProgram: Program::Facilitation,
        );
        $this->seedTask(
            session: $session,
            assignment: $nursingAssignment,
            encounter: $encounter,
            title: 'Asesmen Awal dan Skrining Keselamatan',
            description: 'Menunggu check-in registrasi sebelum asesmen awal dapat dimulai.',
            type: WorkTaskType::NursingIntake,
            status: WorkTaskStatus::Waiting,
            priority: 1,
            sourceProgram: Program::Rmik,
        );
        $this->seedTask(
            session: $session,
            assignment: $medicalAssignment,
            encounter: $encounter,
            title: 'Asesmen Medis Rawat Jalan',
            description: 'Menunggu asesmen awal disetujui supervisor keperawatan sebelum asesmen medis dapat dimulai.',
            type: WorkTaskType::MedicalAssessment,
            status: WorkTaskStatus::Waiting,
            priority: 1,
            sourceProgram: Program::Nursing,
        );
    }

    private function seedUser(string $email, string $name, string $password): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    private function seedAssignment(
        SimulationSession $session,
        User $user,
        Program $program,
        ApplicationRole $role,
        array $capabilities,
    ): Assignment {
        return Assignment::query()->updateOrCreate(
            [
                'session_id' => $session->getKey(),
                'user_id' => $user->getKey(),
                'program' => $program,
                'application_role' => $role,
            ],
            [
                'capabilities' => collect($capabilities)->map(fn (Capability $capability): string => $capability->value)->all(),
                'active_from' => now()->subDay(),
                'active_until' => now()->addMonths(6),
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revocation_reason' => null,
            ],
        );
    }

    private function seedIdentifier(
        SyntheticPatient $patient,
        IdentifierType $type,
        string $system,
        string $value,
    ): void {
        PatientIdentifier::query()->updateOrCreate(
            ['system' => $system, 'value' => $value],
            [
                'patient_id' => $patient->getKey(),
                'type' => $type,
                'synthetic_flag' => true,
                'valid_from' => now(),
                'status' => IdentifierStatus::Active,
            ],
        );
    }

    private function seedTask(
        SimulationSession $session,
        Assignment $assignment,
        Encounter $encounter,
        string $title,
        string $description,
        WorkTaskType $type,
        WorkTaskStatus $status,
        int $priority,
        Program $sourceProgram,
    ): void {
        WorkTask::query()->updateOrCreate(
            [
                'assignment_id' => $assignment->getKey(),
                'encounter_id' => $encounter->getKey(),
                'task_type' => $type,
            ],
            [
                'session_id' => $session->getKey(),
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'source_program' => $sourceProgram,
                'context' => [
                    'caseLabel' => $encounter->encounter_number,
                    'synthetic' => true,
                ],
                'available_at' => now()->subMinute(),
                'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
            ],
        );
    }
}
