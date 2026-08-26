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
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class EmergencyFlowTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        ['clinic' => $clinic, 'doctor' => $doctor, 'schedule' => $schedule] = $this->igdMasterChain();

        return array_merge([
            'full_name' => 'Pasien IGD Sintetis',
            'date_of_birth' => '1985-12-31',
            'sex' => Patient::SEX_LAKI_LAKI,
            'nik' => '3174013112850001',
            'clinic_public_id' => $clinic->public_id,
            'doctor_public_id' => $doctor->public_id,
            'schedule_public_id' => $schedule->public_id,
            'visit_date' => now()->toDateString(),
            'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
            'payer_type' => Encounter::PAYER_UMUM,
            'case_type' => Encounter::CASE_NON_BEDAH,
            'accident_type' => Encounter::ACCIDENT_NONE,
            'is_synthetic' => true,
        ], $overrides);
    }

    public function test_registrar_can_register_synthetic_ed_patient(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        ['clinic' => $clinic, 'doctor' => $doctor, 'schedule' => $schedule] = $this->igdMasterChain();

        $response = $this->actingAs($registrar)
            ->post(route('pendaftaran.igd.store'), $this->registrationPayload());

        $response->assertRedirect(route('pendaftaran.igd.index'));

        $this->assertDatabaseHas('patients', [
            'full_name' => 'Pasien IGD Sintetis',
            'nik' => '3174013112850001',
            'is_synthetic' => true,
        ]);

        $this->assertDatabaseHas('encounters', [
            'clinic_name' => $clinic->name,
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'clinic_schedule_id' => $schedule->id,
            'doctor_name' => $doctor->name,
            'schedule_label' => $schedule->label,
            'queue_date' => now()->toDateString(),
            'queue_number' => 1,
            'status' => Encounter::STATUS_REGISTERED,
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'case_type' => Encounter::CASE_NON_BEDAH,
            'accident_type' => Encounter::ACCIDENT_NONE,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'patient.register',
            'resource_type' => 'encounter',
            'outcome' => 'SUCCESS',
        ]);
        $this->assertDatabaseHas('daily_queue_counters', [
            'queue_date' => now()->toDateString(),
            'last_number' => 1,
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->where('variant', 'igd')
                ->has('todaysEncounters', 1)
                ->has('clinics', 1)
                ->where('todaysEncounters.0.patient.full_name', 'Pasien IGD Sintetis')
                ->where('todaysEncounters.0.queue_number', 1));
    }

    public function test_emergency_registration_rolls_back_when_audit_write_fails(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.igd.store'), $this->registrationPayload())
            ->assertStatus(503);

        $this->assertDatabaseMissing('patients', ['full_name' => 'Pasien IGD Sintetis']);
        $this->assertDatabaseCount('encounters', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('daily_queue_counters', 0);
    }

    public function test_clinical_staff_can_open_igd_worklist_and_write_note(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.igd.store'), $this->registrationPayload());

        $encounter = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_EMERGENCY)
            ->firstOrFail();

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/index')
                ->where('variant', 'igd')
                ->has('encounters', 1));

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Triage awal pasien IGD',
            ])
            ->assertRedirect(route('pemeriksaan.igd.show', $encounter));

        $encounter->refresh();
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->status);

        $this->assertDatabaseHas('clinical_entries', [
            'encounter_id' => $encounter->id,
            'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
            'body' => 'Triage awal pasien IGD',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.note.write',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_emergency_clinical_note_rolls_back_when_audit_write_fails(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.igd.store'), $this->registrationPayload())
            ->assertRedirect(route('pendaftaran.igd.index'));

        $encounter = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_EMERGENCY)
            ->firstOrFail();

        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Catatan yang wajib dibatalkan',
            ])
            ->assertStatus(503);

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertDatabaseMissing('audit_events', ['action' => 'clinical.note.write']);
    }

    public function test_unauthorized_actor_is_denied_before_closed_igd_state_is_disclosed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_CLOSED,
        ]);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Permintaan tidak berwenang.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('clinical_entries', 0);
    }

    public function test_unauthorized_actor_is_denied_before_opposite_care_setting_is_disclosed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Permintaan lintas layanan tidak berwenang.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'authorization.denied',
            'resource_type' => 'http_route',
            'outcome' => 'DENIED',
            'reason' => 'authorization_check_failed',
        ]);
    }

    public function test_user_without_patient_register_gets_forbidden_on_igd_store(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($nurse)
            ->post(route('pendaftaran.igd.store'), $this->registrationPayload([
                'full_name' => 'Tidak Diizinkan IGD',
            ]))
            ->assertForbidden();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
