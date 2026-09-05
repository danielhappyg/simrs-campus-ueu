<?php

namespace Tests\Feature\Outpatient;

use App\Models\ClinicSchedule;
use App\Models\Encounter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdministrativeGenderRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_exposes_and_persists_all_four_satusehat_codes_and_rejects_legacy_input(): void
    {
        $this->seed([RbacSeeder::class, OutpatientMastersSeeder::class]);
        $registrar = User::factory()->create();
        $registrar->roles()->sync([Role::query()->where('slug', 'registrar')->sole()->id]);
        $this->actingAs($registrar);
        $options = [
            ['value' => 'male', 'label' => 'Male'],
            ['value' => 'female', 'label' => 'Female'],
            ['value' => 'other', 'label' => 'Other'],
            ['value' => 'unknown', 'label' => 'Unknown'],
        ];
        $this->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sexOptions', $options));

        $schedule = ClinicSchedule::query()->with(['clinic', 'doctor'])->firstOrFail();
        $payload = [
            'full_name' => 'Pasien Sintetis Metadata',
            'date_of_birth' => '1990-05-15',
            'clinic_public_id' => $schedule->clinic->public_id,
            'doctor_public_id' => $schedule->doctor->public_id,
            'schedule_public_id' => $schedule->public_id,
            'visit_date' => now()->toDateString(),
            'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
            'payer_type' => Encounter::PAYER_UMUM,
            'is_synthetic' => true,
        ];
        foreach ($options as $index => $option) {
            $nik = '317401010190000'.($index + 1);
            $this->post(route('pendaftaran.rawat-jalan.store'), [
                ...$payload, 'sex' => $option['value'], 'nik' => $nik,
            ])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertDatabaseHas('patients', ['nik' => $nik, 'sex' => $option['value']]);
        }

        $this->postJson(route('pendaftaran.rawat-jalan.store'), [
            ...$payload, 'sex' => 'LAKI_LAKI', 'nik' => '3174010101900005',
        ])->assertUnprocessable()->assertJsonValidationErrors('sex');
        $this->assertDatabaseCount('patients', 4);
    }
}
