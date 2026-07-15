<?php

namespace Database\Seeders;

use App\Models\User;
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

        $facilitator = User::query()->updateOrCreate(
            ['email' => 'fasilitator.simulasi@example.invalid'],
            [
                'name' => 'Fasilitator Simulasi UEU',
                'password' => Hash::make($password),
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ],
        );

        $learner = User::query()->updateOrCreate(
            ['email' => 'mahasiswa.keperawatan@example.invalid'],
            [
                'name' => 'Mahasiswa Keperawatan Demo',
                'password' => Hash::make($password),
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ],
        );

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
                'fixture_spec' => [
                    'synthetic_only' => true,
                    'fixture_set' => 'OPD-REF-001-v1',
                ],
                'ruleset_version' => 'FOUNDATION-2026.07',
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

        $assignment = Assignment::query()->updateOrCreate(
            [
                'session_id' => $session->getKey(),
                'user_id' => $learner->getKey(),
                'program' => Program::Nursing,
                'application_role' => ApplicationRole::Learner,
            ],
            [
                'capabilities' => [
                    Capability::SessionView->value,
                    Capability::PatientSearch->value,
                    Capability::IntakeWrite->value,
                ],
                'active_from' => now()->subDay(),
                'active_until' => now()->addMonths(6),
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revocation_reason' => null,
            ],
        );

        $this->seedTask(
            session: $session,
            assignment: $assignment,
            title: 'Orientasi sesi simulasi',
            description: 'Tinjau batas keselamatan, peran, dan tujuan pembelajaran sesi.',
            type: WorkTaskType::SessionOrientation,
            status: WorkTaskStatus::Complete,
            priority: 1,
            caseLabel: 'ORIENTASI',
        );

        $this->seedTask(
            session: $session,
            assignment: $assignment,
            title: 'Asesmen Awal dan Skrining Keselamatan',
            description: 'Dokumentasikan keluhan utama, tanda vital, alergi, risiko jatuh, nyeri, dan tanda bahaya pada pasien sintetis.',
            type: WorkTaskType::NursingIntake,
            status: WorkTaskStatus::Ready,
            priority: 1,
            caseLabel: 'KASUS-SYN-001',
        );

        $this->seedTask(
            session: $session,
            assignment: $assignment,
            title: 'Perbaiki asesmen awal kasus latihan',
            description: 'Supervisor meminta verifikasi ulang status alergi dan dokumentasi eskalasi tanda bahaya.',
            type: WorkTaskType::NursingIntake,
            status: WorkTaskStatus::ChangesRequested,
            priority: 2,
            caseLabel: 'KASUS-SYN-002',
        );
    }

    private function seedTask(
        SimulationSession $session,
        Assignment $assignment,
        string $title,
        string $description,
        WorkTaskType $type,
        WorkTaskStatus $status,
        int $priority,
        string $caseLabel,
    ): void {
        WorkTask::query()->updateOrCreate(
            [
                'assignment_id' => $assignment->getKey(),
                'task_type' => $type,
                'title' => $title,
            ],
            [
                'session_id' => $session->getKey(),
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'source_program' => Program::Nursing,
                'context' => [
                    'caseLabel' => $caseLabel,
                    'synthetic' => true,
                ],
                'available_at' => now()->subMinute(),
                'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
            ],
        );
    }
}
