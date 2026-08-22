<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutpatientPrintAndRecapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_print_or_open_rekap(): void
    {
        $encounter = Encounter::factory()->create();

        $this->get(route('pendaftaran.kunjungan.cetak', $encounter))
            ->assertRedirect();

        $this->get(route('pendaftaran.rekap'))
            ->assertRedirect();
    }

    public function test_registrar_can_print_teaching_bukti_and_sep(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'registered_by_user_id' => $registrar->id,
            'payer_type' => Encounter::PAYER_BPJS,
            'insurance_number' => 'SYNTH-0001',
            'queue_number' => 7,
        ]);

        $response = $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter).'?docs=bukti,sep,antrian');

        $response->assertOk();
        $response->assertSee('Dokumen pengajaran', false);
        $response->assertSee('Bukti pendaftaran', false);
        $response->assertSee('SEP pengajaran', false);
        $response->assertSee('Tidak dikirim ke VClaim', false);
        $response->assertSee('SIM-SEP-', false);
        $response->assertDontSee('Authorization: Bearer', false);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.print',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_print_hides_non_synthetic_patients(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'is_synthetic' => false,
            'created_by_user_id' => $registrar->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter))
            ->assertNotFound();
    }

    public function test_rekap_filters_online_booking_and_exports_csv(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        Encounter::factory()->create([
            'registered_by_user_id' => $registrar->id,
            'clinic_name' => 'Poli Dalam',
            'booking_code' => 'RGN-ONLINE-1',
            'registered_at' => now(),
        ]);
        Encounter::factory()->create([
            'registered_by_user_id' => $registrar->id,
            'clinic_name' => 'Poli Dalam',
            'booking_code' => null,
            'registered_at' => now(),
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'origin' => 'ONLINE',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rekap')
                ->where('totals.all', 1)
                ->where('totals.online', 1)
                ->where('rows.0.booking_code', 'RGN-ONLINE-1'));

        $csv = $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'origin' => 'WALK_IN',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]));

        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv->assertSee('WALK_IN', false);
        $csv->assertDontSee('RGN-ONLINE-1', false);
    }

    public function test_user_without_encounter_list_cannot_open_rekap(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('pendaftaran.rekap'))
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
