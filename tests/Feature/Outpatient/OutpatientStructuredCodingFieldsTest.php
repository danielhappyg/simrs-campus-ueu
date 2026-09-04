<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\OutpatientClinicalDocument;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OutpatientStructuredCodingFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_medical_document_preserves_human_selected_structured_codes_in_immutable_version(): void
    {
        $this->fakeTerminology();
        $physician = $this->physician();
        $patient = Patient::factory()->create(['is_synthetic' => true, 'created_by_user_id' => $physician->id]);
        $encounter = Encounter::factory()->create(['patient_id' => $patient->id, 'registered_by_user_id' => $physician->id, 'care_setting' => Encounter::CARE_SETTING_OUTPATIENT]);
        $fields = [
            'anamnesis' => 'Keluhan pusing.', 'objective_examination' => 'Tanda vital stabil.',
            'clinical_assessment' => 'Vertigo perifer.', 'care_plan' => 'Terapi simptomatik.', 'additional_notes' => '',
            'diagnosis_text' => 'Pusing dengan dugaan vertigo perifer.',
            'primary_icd10' => ['code' => 'R42', 'display' => 'Dizziness and giddiness'],
            'secondary_icd10' => [['code' => 'H81.1', 'display' => 'Benign paroxysmal vertigo']],
            'procedures_icd9cm' => [['code' => '89.01', 'display' => 'Interview and evaluation, described as brief']],
        ];

        $this->actingAs($physician)->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION, 'expected_version' => 0, 'fields' => $fields,
        ])->assertRedirect();
        $this->actingAs($physician)->post(route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), ['expected_version' => 1])->assertRedirect();

        $document = OutpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->sole();
        $this->assertSame($fields['primary_icd10'], $document->fields['primary_icd10']);
        $this->assertSame($fields['procedures_icd9cm'], $document->versions()->where('version', 2)->sole()->fields['procedures_icd9cm']);
    }

    public function test_lookup_proxies_authoritative_nlm_dataset_and_never_generates_diagnosis(): void
    {
        $this->fakeTerminology();
        $this->actingAs($this->physician())->getJson(route('pemeriksaan.rawat-jalan.terminology', ['system' => 'ICD-10', 'q' => 'R42']))
            ->assertOk()->assertJsonPath('options.0.code', 'R42')->assertJsonPath('options.0.system', 'ICD-10')
            ->assertJsonPath('source.authority', 'World Health Organization ICD-10 Browser');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/browse10/2010/en/ACSearch') && $request['q'] === 'R42');
    }

    public function test_lookup_returns_a_clear_service_unavailable_response_when_authority_is_down(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->actingAs($this->physician())
            ->getJson(route('pemeriksaan.rawat-jalan.terminology', ['system' => 'ICD-10', 'q' => 'cholera']))
            ->assertStatus(503)
            ->assertJsonPath('reason', 'terminology_unavailable');
    }

    private function physician(): User
    {
        $actor = User::factory()->create(['status' => 'ACTIVE', 'is_system_administrator' => false]);
        $actor->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PHYSICIAN)->sole()->id]);

        return $actor;
    }

    private function fakeTerminology(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/JsonGetConcept')) {
                $code = $request['ConceptId'];
                $display = $code === 'R42' ? 'Dizziness and giddiness' : 'Benign paroxysmal vertigo';

                return Http::response(['ID' => $code, 'label' => $code.' '.$display]);
            }
            if (str_contains($request->url(), '/browse10/2010/en/ACSearch')) {
                return Http::response('<div class="searchresults"><div class="oneentity"><span thecode class="thecode-3">R42</span><span class="titlelabel">Dizziness and giddiness</span></div><div class="oneentity"><span thecode class="thecode-5">H81.1</span><span class="titlelabel">Benign paroxysmal vertigo</span></div></div>');
            }

            return Http::response([1, ['89.01'], null, [['89.01', 'Interview and evaluation, described as brief']]]);
        });
    }
}
