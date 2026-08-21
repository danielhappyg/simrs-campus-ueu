<?php

namespace Tests\Feature\Outpatient;

use App\Models\ClinicalEntry;
use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutpatientFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    /**
     * @return array{clinic: Clinic, doctor: Doctor, schedule: ClinicSchedule}
     */
    private function firstMasterChain(): array
    {
        $clinic = Clinic::query()->orderBy('id')->firstOrFail();
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
    private function registrationPayload(array $overrides = []): array
    {
        ['clinic' => $clinic, 'doctor' => $doctor, 'schedule' => $schedule] = $this->firstMasterChain();

        return array_merge([
            'full_name' => 'Pasien Sintetis Satu',
            'date_of_birth' => '1990-05-15',
            'sex' => Patient::SEX_PEREMPUAN,
            'nik' => '3174010101900001',
            'clinic_public_id' => $clinic->public_id,
            'doctor_public_id' => $doctor->public_id,
            'schedule_public_id' => $schedule->public_id,
            'visit_date' => now()->toDateString(),
            'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
            'payer_type' => Encounter::PAYER_UMUM,
            'is_synthetic' => true,
        ], $overrides);
    }

    public function test_registrar_can_register_synthetic_outpatient_and_see_encounter(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        ['clinic' => $clinic, 'doctor' => $doctor, 'schedule' => $schedule] = $this->firstMasterChain();

        $response = $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-jalan.store'), $this->registrationPayload());

        $response->assertRedirect(route('pendaftaran.rawat-jalan.index'));

        $this->assertDatabaseHas('patients', [
            'full_name' => 'Pasien Sintetis Satu',
            'nik' => '3174010101900001',
            'is_synthetic' => true,
        ]);

        $this->assertDatabaseHas('encounters', [
            'clinic_name' => $clinic->name,
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'clinic_schedule_id' => $schedule->id,
            'doctor_name' => $doctor->name,
            'schedule_label' => $schedule->label,
            'queue_number' => 1,
            'status' => Encounter::STATUS_REGISTERED,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'patient.register',
            'resource_type' => 'encounter',
            'outcome' => 'SUCCESS',
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->has('todaysEncounters', 1)
                ->has('clinics')
                ->where('todaysEncounters.0.patient.full_name', 'Pasien Sintetis Satu')
                ->where('todaysEncounters.0.queue_number', 1));
    }

    public function test_user_without_patient_register_gets_forbidden(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($nurse)
            ->post(route('pendaftaran.rawat-jalan.store'), $this->registrationPayload([
                'full_name' => 'Tidak Diizinkan',
                'date_of_birth' => '1988-01-01',
                'sex' => Patient::SEX_LAKI_LAKI,
            ]))
            ->assertForbidden();
    }

    public function test_registrar_inertia_can_open_pendaftaran_without_access_flash(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->where('canRegister', true));
    }

    public function test_inertia_forbidden_pendaftaran_redirects_home_with_flash(): void
    {
        $user = User::factory()->create();
        $version = hash_file('xxh128', public_path('build/manifest.json'));

        $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
            ])
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'Anda tidak memiliki akses ke modul tersebut.');
    }

    public function test_clinical_entry_requires_capability(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);

        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.rawat-jalan.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Catatan tidak diizinkan',
            ])
            ->assertForbidden();

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-jalan.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Asesmen keperawatan awal',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-jalan.show', $encounter));

        $encounter->refresh();
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->status);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-jalan.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Asesmen medis tidak diizinkan untuk perawat',
            ])
            ->assertForbidden();

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Asesmen medis lengkap',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-jalan.show', $encounter));

        $encounter->refresh();
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->status);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.note.write',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_rm_complete_transitions_status_to_closed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);

        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_READY_FOR_RM,
        ]);

        $this->actingAs($rmik)
            ->get(route('rm.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rm/rawat-jalan')
                ->has('encounters', 1));

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.complete', $encounter))
            ->assertRedirect(route('rm.rawat-jalan.index'));

        $encounter->refresh();
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->status);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'rmik.review.complete',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_rejects_non_synthetic_patient_flag(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-jalan.store'), $this->registrationPayload([
                'full_name' => 'Pasien Non Sintetis',
                'date_of_birth' => '1992-02-02',
                'sex' => Patient::SEX_LAKI_LAKI,
                'is_synthetic' => false,
            ]))
            ->assertStatus(422);
    }

    public function test_poli_dokter_jadwal_must_belong_to_same_chain(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $clinic = Clinic::query()->where('code', 'UMUM')->firstOrFail();
        $otherClinic = Clinic::query()->where('code', 'GIGI')->firstOrFail();
        $foreignDoctor = Doctor::query()->where('clinic_id', $otherClinic->id)->firstOrFail();
        $schedule = ClinicSchedule::query()->where('clinic_id', $clinic->id)->firstOrFail();

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-jalan.store'), $this->registrationPayload([
                'clinic_public_id' => $clinic->public_id,
                'doctor_public_id' => $foreignDoctor->public_id,
                'schedule_public_id' => $schedule->public_id,
            ]))
            ->assertNotFound();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
