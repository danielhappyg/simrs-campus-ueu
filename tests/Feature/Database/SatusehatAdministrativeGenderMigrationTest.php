<?php

namespace Tests\Feature\Database;

use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SatusehatAdministrativeGenderMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_legacy_patient_sex_values_without_changing_their_records(): void
    {
        $male = Patient::factory()->create(['sex' => 'LAKI_LAKI']);
        $female = Patient::factory()->create(['sex' => 'PEREMPUAN']);
        $unknown = Patient::factory()->create(['sex' => 'TIDAK_DIKETAHUI']);
        $other = Patient::factory()->create(['sex' => Patient::SEX_LAINNYA]);

        $this->migration()->up();

        $this->assertDatabaseHas('patients', ['id' => $male->id, 'sex' => 'male']);
        $this->assertDatabaseHas('patients', ['id' => $female->id, 'sex' => 'female']);
        $this->assertDatabaseHas('patients', ['id' => $unknown->id, 'sex' => 'unknown']);
        $this->assertDatabaseHas('patients', ['id' => $other->id, 'sex' => 'other']);
    }

    public function test_the_canonical_other_code_is_a_supported_patient_value(): void
    {
        $this->assertContains(Patient::SEX_LAINNYA, Patient::SEX_VALUES);
        $this->assertSame('other', Patient::SEX_LAINNYA);
    }

    public function test_it_refuses_unknown_legacy_data_without_partially_converting_known_records(): void
    {
        $known = Patient::factory()->create(['sex' => 'LAKI_LAKI']);
        Patient::factory()->create(['sex' => 'UNMAPPED_VALUE']);

        try {
            $this->migration()->up();
            $this->fail('Unexpected legacy gender values must stop the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('null or unrecognised values', $exception->getMessage());
        }

        $this->assertDatabaseHas('patients', ['id' => $known->id, 'sex' => 'LAKI_LAKI']);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_05_000100_migrate_patient_sex_to_satusehat_administrative_gender.php');
    }
}
