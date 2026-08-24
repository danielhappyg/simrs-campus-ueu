<?php

namespace Tests\Feature\Simulation;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SyntheticDataBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_local_scopes_exclude_the_complete_non_synthetic_graph(): void
    {
        $user = User::factory()->create();
        $syntheticPatient = Patient::factory()->create([
            'created_by_user_id' => $user->id,
            'is_synthetic' => true,
        ]);
        $nonSyntheticPatient = Patient::factory()->create([
            'created_by_user_id' => $user->id,
            'is_synthetic' => false,
        ]);
        $syntheticEncounter = Encounter::factory()->create([
            'patient_id' => $syntheticPatient->id,
            'registered_by_user_id' => $user->id,
        ]);
        $nonSyntheticEncounter = Encounter::factory()->create([
            'patient_id' => $nonSyntheticPatient->id,
            'registered_by_user_id' => $user->id,
        ]);
        $syntheticOrder = LabServiceRequest::factory()->create([
            'encounter_id' => $syntheticEncounter->id,
            'requested_by_user_id' => $user->id,
        ]);
        $nonSyntheticOrder = LabServiceRequest::factory()->create([
            'encounter_id' => $nonSyntheticEncounter->id,
            'requested_by_user_id' => $user->id,
        ]);

        $this->assertSame([$syntheticPatient->id], Patient::query()->syntheticOnly()->pluck('id')->all());
        $this->assertSame([$syntheticEncounter->id], Encounter::query()->syntheticOnly()->pluck('id')->all());
        $this->assertSame([$syntheticOrder->id], LabServiceRequest::query()->syntheticOnly()->pluck('id')->all());
        $this->assertFalse(LabServiceRequest::query()->syntheticOnly()->whereKey($nonSyntheticOrder->id)->exists());
    }

    public function test_non_synthetic_records_are_absent_from_counts_and_worklists(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);

        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => false,
            'created_at' => now(),
        ]);

        $outpatient = $this->nonSyntheticEncounter($patient, $registrar, [
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_READY_FOR_RM,
        ]);
        $this->nonSyntheticEncounter($patient, $registrar, [
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_REGISTERED,
        ]);
        $this->nonSyntheticEncounter($patient, $registrar, [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'ward_name' => 'Bangsal Mawar',
            'ward_class' => 'Kelas 1',
            'bed_code' => 'MW-101-A',
        ]);
        LabServiceRequest::factory()->create([
            'encounter_id' => $outpatient->id,
            'requested_by_user_id' => $registrar->id,
            'status' => LabServiceRequest::STATUS_ACTIVE,
        ]);

        $this->actingAs($registrar)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.kunjungan_hari_ini', 0)
                ->where('counts.pasien_baru_hari_ini', 0)
                ->where('counts.in_examination', 0)
                ->where('counts.ready_for_rm', 0));

        foreach ([
            'pendaftaran.rawat-jalan.index',
            'pendaftaran.igd.index',
            'pendaftaran.rawat-inap.index',
        ] as $routeName) {
            $this->actingAs($registrar)
                ->get(route($routeName))
                ->assertInertia(fn (Assert $page) => $page->has('todaysEncounters', 0));
        }

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 0)
                ->where('totals.all', 0));

        foreach ([
            'pemeriksaan.rawat-jalan.index',
            'pemeriksaan.igd.index',
            'pemeriksaan.rawat-inap.index',
            'pemeriksaan.triage.index',
        ] as $routeName) {
            $this->actingAs($nurse)
                ->get(route($routeName))
                ->assertInertia(fn (Assert $page) => $page->has('encounters', 0));
        }

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.laboratorium.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 0));

        $this->actingAs($rmik)
            ->get(route('rm.rawat-jalan.index'))
            ->assertInertia(fn (Assert $page) => $page->has('encounters', 0));
    }

    public function test_route_binding_refuses_non_synthetic_encounters_and_lab_orders_before_mutation(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => false,
        ]);
        $encounter = $this->nonSyntheticEncounter($patient, $registrar, [
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_READY_FOR_RM,
        ]);
        $emergencyEncounter = $this->nonSyntheticEncounter($patient, $registrar, [
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);
        $inpatientEncounter = $this->nonSyntheticEncounter($patient, $registrar, [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);
        $order = LabServiceRequest::factory()->create([
            'encounter_id' => $encounter->id,
            'requested_by_user_id' => $physician->id,
            'status' => LabServiceRequest::STATUS_ACTIVE,
        ]);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertNotFound();
        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => ['anamnesis' => 'Must not be stored'],
            ])
            ->assertNotFound();
        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.lab-orders.store', $encounter), [
                'test_code' => 'HB',
            ])
            ->assertNotFound();
        $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter))
            ->assertNotFound();
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.signoff', $encounter), [
                'expected_version' => 1,
                'source_fingerprint' => str_repeat('a', 64),
            ])
            ->assertNotFound();
        $this->actingAs($physician)
            ->get(route('pemeriksaan.igd.show', $emergencyEncounter))
            ->assertNotFound();
        $this->actingAs($physician)
            ->post(route('pemeriksaan.igd.entries.store', $emergencyEncounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Must not be stored',
            ])
            ->assertNotFound();
        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-inap.show', $inpatientEncounter))
            ->assertNotFound();
        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-inap.entries.store', $inpatientEncounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Must not be stored',
            ])
            ->assertNotFound();
        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Must not be stored',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
        $this->assertDatabaseCount('lab_diagnostic_results', 0);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $emergencyEncounter->fresh()->status);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $inpatientEncounter->fresh()->status);
        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nonSyntheticEncounter(Patient $patient, User $registrar, array $attributes): Encounter
    {
        return Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'registered_at' => now(),
            ...$attributes,
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
