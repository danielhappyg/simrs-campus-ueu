<?php

namespace Tests\Feature\Outpatient;

use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Registration\ClinicBookingSurface;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClinicBookingSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_rawat_jalan_clinics_exclude_emergency_and_supporting_units(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->has('clinics')
                ->where('clinics', function ($clinics): bool {
                    $codes = collect($clinics)->pluck('code')->all();

                    foreach (['UMUM', 'GIGI', 'PD', 'BEDAH', 'REHAB', 'BMM'] as $expected) {
                        if (! in_array($expected, $codes, true)) {
                            return false;
                        }
                    }

                    foreach (['IGD', 'ANESTESI', 'RAD', 'PK'] as $forbidden) {
                        if (in_array($forbidden, $codes, true)) {
                            return false;
                        }
                    }

                    return true;
                }));
    }

    public function test_rawat_jalan_rejects_igd_clinic_booking(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $igd = Clinic::query()->where('code', 'IGD')->firstOrFail();
        $doctor = Doctor::query()->where('clinic_id', $igd->id)->orderBy('id')->firstOrFail();
        $schedule = ClinicSchedule::query()
            ->where('clinic_id', $igd->id)
            ->where('doctor_id', $doctor->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-jalan.store'), [
                'full_name' => 'Pasien IGD Lewat RJ',
                'date_of_birth' => '1991-01-01',
                'sex' => Patient::SEX_LAKI_LAKI,
                'clinic_public_id' => $igd->public_id,
                'doctor_public_id' => $doctor->public_id,
                'schedule_public_id' => $schedule->public_id,
                'visit_date' => now()->toDateString(),
                'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
                'payer_type' => Encounter::PAYER_UMUM,
                'is_synthetic' => true,
            ])
            ->assertStatus(422);
    }

    public function test_igd_desk_still_exposes_only_igd_clinic(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clinics', 1)
                ->where('clinics.0.code', 'IGD')
                ->where('clinics.0.name', 'Instalasi Gawat Darurat'));
    }

    public function test_type_b_clinics_carry_booking_surface(): void
    {
        $this->assertSame(
            ClinicBookingSurface::OUTPATIENT,
            Clinic::query()->where('code', 'PD')->value('booking_surface'),
        );
        $this->assertSame(
            ClinicBookingSurface::EMERGENCY,
            Clinic::query()->where('code', 'IGD')->value('booking_surface'),
        );
        $this->assertSame(
            ClinicBookingSurface::SUPPORTING,
            Clinic::query()->where('code', 'RAD')->value('booking_surface'),
        );
        $this->assertSame(
            ClinicBookingSurface::OUTPATIENT,
            Clinic::query()->where('code', 'REHAB')->value('booking_surface'),
        );
    }

    public function test_ensure_masters_seeded_appends_type_b_on_existing_clinics(): void
    {
        Clinic::query()->whereNotIn('code', ['UMUM', 'GIGI', 'ANAK', 'JANTUNG', 'IGD'])->delete();
        $this->assertNull(Clinic::query()->where('code', 'PD')->first());

        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk();

        $this->assertNotNull(Clinic::query()->where('code', 'PD')->first());
        $this->assertSame(
            ClinicBookingSurface::SUPPORTING,
            Clinic::query()->where('code', 'ANESTESI')->value('booking_surface'),
        );
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
