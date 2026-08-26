<?php

namespace Tests\Feature\Inpatient;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\InpatientMastersSeeder;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class InpatientFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];

        return array_merge([
            'full_name' => 'Pasien RI Sintetis',
            'date_of_birth' => '1990-05-15',
            'sex' => Patient::SEX_PEREMPUAN,
            'nik' => '3174011555900002',
            'ward_name' => $ward['name'],
            'ward_class' => $ward['class'],
            'bed_code' => $ward['beds'][0],
            'payer_type' => Encounter::PAYER_UMUM,
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'chief_complaint' => 'Demam dan mual',
            'is_synthetic' => true,
        ], $overrides);
    }

    public function test_registrar_can_admit_synthetic_inpatient_with_bed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];

        $response = $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload());

        $response->assertRedirect(route('pendaftaran.rawat-inap.index'));

        $this->assertDatabaseHas('patients', [
            'full_name' => 'Pasien RI Sintetis',
            'nik' => '3174011555900002',
            'is_synthetic' => true,
        ]);

        $this->assertDatabaseHas('encounters', [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'ward_name' => $ward['name'],
            'ward_class' => $ward['class'],
            'bed_code' => $ward['beds'][0],
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'status' => Encounter::STATUS_REGISTERED,
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => now()->toDateString(),
            'queue_number' => 1,
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
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->has('todaysEncounters', 1)
                ->has('wards', 3)
                ->where('canOpen', true)
                ->where('todaysEncounters.0.patient.full_name', 'Pasien RI Sintetis')
                ->where('todaysEncounters.0.bed_code', $ward['beds'][0]));
    }

    public function test_inpatient_registration_hides_examination_handoff_without_open_capability(): void
    {
        $listOnlyRole = Role::query()->create([
            'slug' => 'inpatient-list-only',
            'name' => 'Inpatient list only',
            'description' => 'Synthetic test role without encounter open',
        ]);
        $listOnlyRole->permissions()->sync(Permission::query()
            ->whereIn('name', [Capability::PATIENT_SEARCH, Capability::ENCOUNTER_LIST])
            ->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->sync([$listOnlyRole->id]);

        $this->actingAs($user)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->where('canRegister', false)
                ->where('canOpen', false));
    }

    public function test_inpatient_registration_rolls_back_when_audit_write_fails(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload())
            ->assertStatus(503);

        $this->assertDatabaseMissing('patients', ['full_name' => 'Pasien RI Sintetis']);
        $this->assertDatabaseCount('encounters', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('daily_queue_counters', 0);
    }

    public function test_second_admit_same_bed_blocked_while_first_open(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];
        $bed = $ward['beds'][0];

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'full_name' => 'Pasien RI Pertama',
                'nik' => '3174011555900003',
            ]));

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'full_name' => 'Pasien RI Kedua',
                'nik' => '3174011555900004',
                'bed_code' => $bed,
            ]))
            ->assertRedirect(route('pendaftaran.rawat-inap.index'))
            ->assertSessionHasErrors('bed_code');

        $this->assertSame(1, Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('bed_code', $bed)
            ->where('status', '!=', Encounter::STATUS_CLOSED)
            ->count());
    }

    public function test_clinician_can_list_open_and_write_note(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload());

        $encounter = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->firstOrFail();

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/index')
                ->where('variant', 'rawat-inap')
                ->has('encounters', 1));

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/show')
                ->where('variant', 'rawat-inap'));

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Observasi awal rawat inap',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));

        $encounter->refresh();
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->status);

        $this->assertDatabaseHas('clinical_entries', [
            'encounter_id' => $encounter->id,
            'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
            'body' => 'Observasi awal rawat inap',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.note.write',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_inpatient_clinical_note_rolls_back_when_audit_write_fails(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload())
            ->assertRedirect(route('pendaftaran.rawat-inap.index'));

        $encounter = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->firstOrFail();

        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Catatan yang wajib dibatalkan',
            ])
            ->assertStatus(503);

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertDatabaseMissing('audit_events', ['action' => 'clinical.note.write']);
    }

    public function test_unauthorized_actor_is_denied_before_closed_ri_state_is_disclosed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_CLOSED,
        ]);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.rawat-inap.entries.store', $encounter), [
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
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.rawat-inap.entries.store', $encounter), [
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

    public function test_non_registrar_forbidden_on_inpatient_store(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($nurse)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'full_name' => 'Tidak Diizinkan RI',
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
