<?php

namespace Tests\Feature\Outpatient;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
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
    }

    public function test_registrar_can_register_synthetic_outpatient_and_see_encounter(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $response = $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-jalan.store'), [
                'full_name' => 'Pasien Sintetis Satu',
                'date_of_birth' => '1990-05-15',
                'sex' => Patient::SEX_PEREMPUAN,
                'clinic_name' => 'Poliklinik Umum',
                'payer_type' => Encounter::PAYER_UMUM,
                'is_synthetic' => true,
            ]);

        $response->assertRedirect(route('pendaftaran.rawat-jalan.index'));

        $this->assertDatabaseHas('patients', [
            'full_name' => 'Pasien Sintetis Satu',
            'is_synthetic' => true,
        ]);

        $this->assertDatabaseHas('encounters', [
            'clinic_name' => 'Poliklinik Umum',
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
                ->where('todaysEncounters.0.patient.full_name', 'Pasien Sintetis Satu'));
    }

    public function test_user_without_patient_register_gets_forbidden(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($nurse)
            ->post(route('pendaftaran.rawat-jalan.store'), [
                'full_name' => 'Tidak Diizinkan',
                'date_of_birth' => '1988-01-01',
                'sex' => Patient::SEX_LAKI_LAKI,
                'clinic_name' => 'Poliklinik Umum',
                'payer_type' => Encounter::PAYER_UMUM,
            ])
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
            ->post(route('pendaftaran.rawat-jalan.store'), [
                'full_name' => 'Pasien Non Sintetis',
                'date_of_birth' => '1992-02-02',
                'sex' => Patient::SEX_LAKI_LAKI,
                'clinic_name' => 'Poliklinik Umum',
                'payer_type' => Encounter::PAYER_UMUM,
                'is_synthetic' => false,
            ])
            ->assertStatus(422);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
