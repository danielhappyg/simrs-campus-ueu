<?php

namespace Tests\Feature\Emergency;

use App\Models\Clinic;
use App\Models\ClinicalEntry;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContinuousEmergencyTeachingJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_distinct_actors_complete_the_current_synthetic_emergency_scaffold_through_public_routes(): void
    {
        $registrar = $this->userWithRole('Registrar IGD Sintetis', RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole('Perawat IGD Sintetis', RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole('Dokter IGD Sintetis', RoleCapabilityMatrix::ROLE_PHYSICIAN);
        ['clinic' => $clinic, 'doctor' => $doctor, 'schedule' => $schedule] = $this->igdMasterChain();

        $this->actingAs($registrar)
            ->post(route('pendaftaran.igd.store'), $this->registrationPayload($clinic, $doctor, $schedule))
            ->assertRedirect(route('pendaftaran.igd.index'))
            ->assertSessionHas('last_encounter_public_id');

        $patient = Patient::query()->where('nik', '3174012708909001')->sole();
        $encounter = Encounter::query()->where('patient_id', $patient->id)->sole();

        $this->assertTrue($patient->is_synthetic);
        $this->assertSame($registrar->id, $patient->created_by_user_id);
        $this->assertSame($registrar->id, $encounter->registered_by_user_id);
        $this->assertSame(Encounter::CARE_SETTING_EMERGENCY, $encounter->care_setting);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->status);
        $this->assertSame($clinic->id, $encounter->clinic_id);
        $this->assertSame($doctor->id, $encounter->doctor_id);
        $this->assertSame($schedule->id, $encounter->clinic_schedule_id);
        $this->assertSame(1, $encounter->queue_number);
        $this->assertAttributedAudit('patient.register', $registrar, 'SUCCESS', null, $encounter->public_id);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->where('variant', 'igd')
                ->has('todaysEncounters', 1)
                ->where('todaysEncounters.0.public_id', $encounter->public_id)
                ->where('todaysEncounters.0.status', Encounter::STATUS_REGISTERED)
                ->where('todaysEncounters.0.patient.full_name', $patient->full_name)
                ->where('todaysEncounters.0.queue_number', 1));

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.triage.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/index')
                ->where('variant', 'triage')
                ->where('showPathPrefix', '/pemeriksaan/igd')
                ->has('encounters', 1)
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.status', Encounter::STATUS_REGISTERED));

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/index')
                ->where('variant', 'igd')
                ->has('encounters', 1)
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.status', Encounter::STATUS_REGISTERED));

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Asesmen keperawatan awal IGD untuk skenario sintetis.',
            ])
            ->assertRedirect(route('pemeriksaan.igd.show', $encounter));

        $nursingEntry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->id)
            ->where('entry_type', ClinicalEntry::TYPE_NURSING_INTAKE)
            ->sole();
        $this->assertSame($nurse->id, $nursingEntry->author_user_id);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertAttributedAudit('clinical.note.write', $nurse, 'SUCCESS', null, $encounter->public_id);

        $entryCountBeforeWrongRole = ClinicalEntry::query()->count();
        $this->actingAs($nurse)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Percobaan catatan medis IGD oleh peran yang tidak berwenang.',
            ])
            ->assertForbidden();

        $this->assertSame($entryCountBeforeWrongRole, ClinicalEntry::query()->count());
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertAttributedAudit(
            'authorization.denied',
            $nurse,
            'DENIED',
            'authorization_check_failed',
            'pemeriksaan.igd.entries.store',
        );

        $this->actingAs($physician)
            ->get(route('pemeriksaan.igd.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('variant', 'igd')
                ->where('encounter.public_id', $encounter->public_id)
                ->where('encounter.status', Encounter::STATUS_IN_EXAMINATION)
                ->has('encounter.entries', 1)
                ->where('encounter.entries.0.entry_type', ClinicalEntry::TYPE_NURSING_INTAKE)
                ->where('encounter.entries.0.author_name', $nurse->name));

        $this->actingAs($physician)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Asesmen medis awal IGD untuk skenario sintetis.',
            ])
            ->assertRedirect(route('pemeriksaan.igd.show', $encounter));

        $medicalEntry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->id)
            ->where('entry_type', ClinicalEntry::TYPE_MEDICAL_ASSESSMENT)
            ->sole();
        $this->assertSame($physician->id, $medicalEntry->author_user_id);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertAttributedAudit('clinical.note.write', $physician, 'SUCCESS', null, $encounter->public_id);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.igd.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('variant', 'igd')
                ->where('encounter.public_id', $encounter->public_id)
                ->where('encounter.status', Encounter::STATUS_READY_FOR_RM)
                ->has('encounter.entries', 2)
                ->where('encounter.entries.0.entry_type', ClinicalEntry::TYPE_NURSING_INTAKE)
                ->where('encounter.entries.0.author_name', $nurse->name)
                ->where('encounter.entries.1.entry_type', ClinicalEntry::TYPE_MEDICAL_ASSESSMENT)
                ->where('encounter.entries.1.author_name', $physician->name));

        $this->assertSame(3, AuditEvent::query()->where('outcome', 'SUCCESS')->count());
        $this->assertSame(1, AuditEvent::query()->where('outcome', 'DENIED')->count());
    }

    /**
     * @return array{clinic: Clinic, doctor: Doctor, schedule: ClinicSchedule}
     */
    private function igdMasterChain(): array
    {
        $clinic = Clinic::query()->where('code', 'IGD')->firstOrFail();
        $doctor = Doctor::query()->where('clinic_id', $clinic->id)->orderBy('id')->firstOrFail();
        $schedule = ClinicSchedule::query()
            ->where('clinic_id', $clinic->id)
            ->where('doctor_id', $doctor->id)
            ->orderBy('id')
            ->firstOrFail();

        return compact('clinic', 'doctor', 'schedule');
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(Clinic $clinic, Doctor $doctor, ClinicSchedule $schedule): array
    {
        return [
            'full_name' => 'Pasien Alur IGD Sintetis',
            'date_of_birth' => '1990-08-27',
            'sex' => Patient::SEX_PEREMPUAN,
            'nik' => '3174012708909001',
            'clinic_public_id' => $clinic->public_id,
            'doctor_public_id' => $doctor->public_id,
            'schedule_public_id' => $schedule->public_id,
            'visit_date' => now()->toDateString(),
            'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
            'payer_type' => Encounter::PAYER_UMUM,
            'case_type' => Encounter::CASE_NON_BEDAH,
            'accident_type' => Encounter::ACCIDENT_NONE,
            'chief_complaint' => 'Keluhan IGD pada skenario pengajaran sintetis.',
            'is_synthetic' => true,
        ];
    }

    private function userWithRole(string $name, string $roleSlug): User
    {
        $user = User::factory()->create(['name' => $name]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function assertAttributedAudit(
        string $action,
        User $actor,
        string $outcome,
        ?string $reason,
        string $resourceId,
    ): void {
        $this->assertDatabaseHas('audit_events', [
            'action' => $action,
            'resource_id' => $resourceId,
            'outcome' => $outcome,
            'reason' => $reason,
            'actor_user_id' => $actor->id,
            'actor_type' => AuditActorAttribution::TYPE_USER,
            'actor_reference' => $actor->public_id,
        ]);
    }
}
