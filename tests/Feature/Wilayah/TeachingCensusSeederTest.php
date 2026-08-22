<?php

namespace Tests\Feature\Wilayah;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TeachingCensusSeeder;
use Database\Seeders\WilayahMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeachingCensusSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_teaching_census_seeds_synthetic_patients_across_care_settings(): void
    {
        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => 'demo-password-12',
        ]);

        $this->seed(RbacSeeder::class);
        $this->seed(DemoActorsSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
        $this->seed(WilayahMinimalSeeder::class);
        $this->seed(TeachingCensusSeeder::class);

        $this->assertGreaterThanOrEqual(30, Patient::query()->where('medical_record_number', 'like', 'SYNTH-CENSUS-%')->count());
        $this->assertTrue(Patient::query()->where('medical_record_number', 'like', 'SYNTH-CENSUS-%')->whereNotNull('province_code')->exists());
        $this->assertTrue(Encounter::query()->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)->exists());
        $this->assertTrue(Encounter::query()->where('care_setting', Encounter::CARE_SETTING_EMERGENCY)->exists());
        $this->assertTrue(Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->exists());
        $this->assertTrue(User::query()->where('email', 'registrar.demo@example.invalid')->exists());
    }
}
