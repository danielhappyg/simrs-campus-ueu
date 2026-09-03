<?php

namespace Tests\Feature\Wilayah;

use App\Models\DailyQueueCounter;
use App\Models\Encounter;
use App\Models\InpatientLocationEvent;
use App\Models\Patient;
use App\Models\User;
use App\Support\Inpatient\InpatientLocationMutationScope;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\InpatientMastersSeeder;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TeachingCensusSeeder;
use Database\Seeders\WilayahMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ExactEngineTestFixture;
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
        $this->seed(InpatientMastersSeeder::class);
        $this->seed(WilayahMinimalSeeder::class);
        $this->seed(TeachingCensusSeeder::class);

        $digitMrns = Patient::query()
            ->pluck('medical_record_number')
            ->filter(fn (string $mrn): bool => preg_match('/^[0-9]{6}$/', $mrn) === 1);
        $this->assertGreaterThanOrEqual(30, $digitMrns->count());
        $this->assertSame(0, Patient::query()->where('medical_record_number', 'like', 'SYNTH-CENSUS-%')->count());
        $this->assertTrue(
            Patient::query()
                ->whereIn('medical_record_number', $digitMrns->all())
                ->whereNotNull('province_code')
                ->exists(),
        );
        $this->assertTrue(Encounter::query()->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)->exists());
        $this->assertTrue(Encounter::query()->where('care_setting', Encounter::CARE_SETTING_EMERGENCY)->exists());
        $this->assertTrue(Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->exists());
        $this->assertFalse(Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->whereNull('inpatient_bed_id')->exists());
        $this->assertSame(
            Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->count(),
            InpatientLocationEvent::query()->where('event_type', InpatientLocationEvent::TYPE_ADMISSION)->count(),
        );
        $this->assertFalse(InpatientLocationEvent::query()->where('sequence', '!=', 1)->exists());
        $this->assertTrue(User::query()->where('email', 'registrar.demo@example.invalid')->exists());

        $firstAssignments = Encounter::query()
            ->where('booking_code', 'like', 'SYNTH-ENC-%')
            ->orderBy('booking_code')
            ->get(['booking_code', 'queue_date', 'queue_number']);
        $firstCounters = DailyQueueCounter::query()->orderBy('queue_date')->pluck('last_number', 'queue_date');
        $legacyInpatient = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->orderBy('id')
            ->firstOrFail();
        ExactEngineTestFixture::runSyntheticResetDelete(
            fn () => InpatientLocationMutationScope::run(
                fn () => DB::table('inpatient_location_events')
                    ->where('encounter_id', $legacyInpatient->id)
                    ->delete(),
            ),
        );

        $firstAssignments
            ->groupBy(fn (Encounter $encounter): string => $encounter->queue_date)
            ->each(function ($dailyEncounters, string $queueDate): void {
                $numbers = $dailyEncounters->pluck('queue_number');
                $this->assertCount($numbers->count(), $numbers->unique());
                $this->assertSame(
                    $numbers->max(),
                    DailyQueueCounter::query()->where('queue_date', $queueDate)->value('last_number'),
                );
            });

        $this->seed(TeachingCensusSeeder::class);

        $this->assertSame(0, Patient::query()->where('medical_record_number', 'like', 'SYNTH-CENSUS-%')->count());
        $this->assertEquals(
            $firstAssignments->toArray(),
            Encounter::query()
                ->where('booking_code', 'like', 'SYNTH-ENC-%')
                ->orderBy('booking_code')
                ->get(['booking_code', 'queue_date', 'queue_number'])
                ->toArray(),
        );
        $this->assertEquals(
            $firstCounters->toArray(),
            DailyQueueCounter::query()->orderBy('queue_date')->pluck('last_number', 'queue_date')->toArray(),
        );
        $this->assertFalse(InpatientLocationEvent::query()->where('encounter_id', $legacyInpatient->id)->exists());
        $this->assertSame(
            Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->count() - 1,
            InpatientLocationEvent::query()->where('event_type', InpatientLocationEvent::TYPE_ADMISSION)->count(),
        );
    }

    public function test_teaching_census_refuses_unsafe_application_mode_before_writing(): void
    {
        config([
            'simulation.mode' => 'PRODUCTION',
            'simulation.synthetic_only' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Teaching census requires SIMULATION mode with synthetic-only data enforced.');

        $this->seed(TeachingCensusSeeder::class);
    }

    public function test_teaching_census_refuses_to_overwrite_a_non_synthetic_mrn_collision(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $user = User::factory()->create();
        $patient = Patient::factory()->create([
            'medical_record_number' => '000001',
            'full_name' => 'Record yang harus dipertahankan',
            'is_synthetic' => false,
            'created_by_user_id' => $user->id,
        ]);

        try {
            $this->seed(TeachingCensusSeeder::class);
            $this->fail('Seeder should reject a non-synthetic MRN collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('belongs to a non-synthetic patient', $exception->getMessage());
        }

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'full_name' => 'Record yang harus dipertahankan',
            'is_synthetic' => false,
        ]);
        $this->assertDatabaseCount('clinics', 0);
        $this->assertDatabaseCount('wilayah_provinces', 0);
    }

    public function test_teaching_census_refuses_to_repoint_a_non_synthetic_encounter_marker(): void
    {
        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);

        $user = User::factory()->create();
        $patient = Patient::factory()->create([
            'medical_record_number' => 'NON-SYNTH-COLLISION',
            'is_synthetic' => false,
            'created_by_user_id' => $user->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $user->id,
            'booking_code' => 'SYNTH-ENC-RJ-001',
        ]);

        try {
            $this->seed(TeachingCensusSeeder::class);
            $this->fail('Seeder should reject a non-synthetic booking marker collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('belongs to a non-synthetic patient encounter', $exception->getMessage());
        }

        $this->assertDatabaseHas('encounters', [
            'id' => $encounter->id,
            'patient_id' => $patient->id,
            'booking_code' => 'SYNTH-ENC-RJ-001',
        ]);
        $this->assertDatabaseCount('clinics', 0);
        $this->assertDatabaseCount('wilayah_provinces', 0);
    }
}
