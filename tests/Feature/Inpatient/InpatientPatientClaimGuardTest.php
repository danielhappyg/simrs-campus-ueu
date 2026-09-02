<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InpatientPatientClaimGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_refuses_two_active_inpatient_claims_for_one_patient(): void
    {
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $first = $this->activeInpatient($patient);

        $this->assertSame($patient->id, $first->active_inpatient_patient_id);

        $this->expectException(QueryException::class);
        $this->activeInpatient($patient);
    }

    public function test_database_refuses_an_active_inpatient_without_the_patient_claim_key(): void
    {
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = $this->activeInpatient($patient);

        $this->expectException(QueryException::class);
        DB::table($encounter->getTable())->where('id', $encounter->id)->update([
            'active_inpatient_patient_id' => null,
        ]);
    }

    public function test_database_refuses_a_patient_claim_key_on_an_inactive_encounter(): void
    {
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        $this->expectException(QueryException::class);
        DB::table($encounter->getTable())->where('id', $encounter->id)->update([
            'active_inpatient_patient_id' => $patient->id,
        ]);
    }

    private function activeInpatient(Patient $patient): Encounter
    {
        return Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
        ]);
    }
}
