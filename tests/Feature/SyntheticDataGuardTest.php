<?php

namespace Tests\Feature;

use App\Modules\Patient\Enums\AdministrativeSex;
use App\Modules\Patient\Enums\IdentifierStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Patient\Enums\PatientRecordStatus;
use App\Modules\Patient\Models\PatientIdentifier;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Models\SimulationSession;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class SyntheticDataGuardTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_patient_model_rejects_a_non_synthetic_record(): void
    {
        $this->seedReferenceOutpatient();
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();

        $this->expectException(DomainException::class);

        SyntheticPatient::query()->create([
            'session_id' => $session->getKey(),
            'synthetic_flag' => false,
            'fixture_source' => 'forbidden-test',
            'full_name' => 'Pasien Sintetis Ditolak',
            'birth_date' => '1990-01-01',
            'administrative_sex' => AdministrativeSex::Unknown,
            'record_status' => PatientRecordStatus::Active,
        ]);
    }

    public function test_database_constraint_rejects_non_synthetic_patient_even_without_model(): void
    {
        $this->seedReferenceOutpatient();
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('synthetic_patients')->insert([
            'public_id' => '01J00000000000000000000999',
            'session_id' => $session->getKey(),
            'synthetic_flag' => false,
            'fixture_source' => 'bypass-attempt',
            'full_name' => 'Pasien Sintetis Bypass',
            'birth_date' => '1990-01-01',
            'administrative_sex' => AdministrativeSex::Unknown->value,
            'deceased_flag' => false,
            'record_status' => PatientRecordStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_identifier_model_rejects_a_production_or_unclassified_namespace(): void
    {
        $this->seedReferenceOutpatient();
        $patient = SyntheticPatient::query()->firstOrFail();

        $this->expectException(DomainException::class);

        PatientIdentifier::query()->create([
            'patient_id' => $patient->getKey(),
            'type' => IdentifierType::SyntheticNationalId,
            'system' => 'https://production.example/nik',
            'value' => 'forbidden-production-value',
            'synthetic_flag' => true,
            'status' => IdentifierStatus::Active,
        ]);
    }
}
