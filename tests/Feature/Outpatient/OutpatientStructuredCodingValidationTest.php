<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OutpatientStructuredCodingValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->fakeTerminology();
    }

    public function test_registrar_cannot_write_medical_coding_or_search_terminology(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar);

        $this->actingAs($registrar)
            ->getJson(route('pemeriksaan.rawat-jalan.terminology', ['system' => 'ICD-10', 'q' => 'R42']))
            ->assertForbidden();
        $this->actingAs($registrar)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $this->codedFields(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
        Http::assertNothingSent();
    }

    public function test_malformed_and_unknown_coded_entries_are_rejected_without_writing_a_document(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter($physician);

        $malformed = $this->codedFields();
        $malformed['primary_icd10'] = ['code' => 'R42'];
        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $malformed,
            ])
            ->assertStatus(422);

        $unknown = $this->codedFields();
        $unknown['primary_icd10'] = ['code' => 'Z99', 'display' => 'Untrusted display'];
        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $unknown,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
        $this->assertDatabaseCount('outpatient_clinical_document_versions', 0);
    }

    public function test_primary_code_cannot_be_repeated_as_a_secondary_code_without_writing_a_document(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter($physician);
        $fields = $this->codedFields();
        $fields['secondary_icd10'] = [['code' => 'R42', 'display' => 'Dizziness and giddiness']];

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $fields,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
        $this->assertDatabaseCount('outpatient_clinical_document_versions', 0);
    }

    public function test_multiple_secondary_diagnoses_and_primary_reassignment_preserve_saved_and_final_versions(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter($physician);
        $fields = $this->codedFields();
        $fields['secondary_icd10'][] = ['code' => 'I10', 'display' => 'Essential (primary) hypertension'];
        $draftUrl = route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]);

        $this->actingAs($physician)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => $fields,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = OutpatientClinicalDocument::query()->sole();
        $this->assertEquals($fields, $document->fields);
        $this->assertSame(['H81.1', 'I10'], array_column($document->fields['secondary_icd10'], 'code'));

        $reassigned = $fields;
        $reassigned['primary_icd10'] = $fields['secondary_icd10'][0];
        $reassigned['secondary_icd10'] = [$fields['primary_icd10'], $fields['secondary_icd10'][1]];
        $this->actingAs($physician)->post($draftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 1,
            'fields' => $reassigned,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertEquals($reassigned, $document->fields);
        $this->assertSame(['R42', 'I10'], array_column($document->fields['secondary_icd10'], 'code'));
        $this->assertEquals($fields, OutpatientClinicalDocumentVersion::query()->where('version', 1)->sole()->fields);

        $this->actingAs($physician)->post(
            route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]),
            ['expected_version' => 2],
        )->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame(OutpatientClinicalDocument::STATE_FINAL, $document->document_state);
        $this->assertEquals($reassigned, $document->fields);
        $this->assertEquals($reassigned, OutpatientClinicalDocumentVersion::query()->where('version', 3)->sole()->fields);
        $this->assertDatabaseCount('outpatient_clinical_document_versions', 3);
    }

    public function test_duplicate_secondary_diagnoses_are_rejected_without_writing_a_document(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter($physician);
        $fields = $this->codedFields();
        $fields['secondary_icd10'][] = $fields['secondary_icd10'][0];

        $this->actingAs($physician)->post(
            route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]),
            [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $fields,
            ],
        )->assertStatus(422);

        $this->assertDatabaseCount('outpatient_clinical_documents', 0);
        $this->assertDatabaseCount('outpatient_clinical_document_versions', 0);
    }

    public function test_legacy_draft_can_finalize_without_new_codes_and_final_document_rejects_rewrite(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter($physician);
        $legacyFields = [
            'anamnesis' => 'Keluhan lama.',
            'objective_examination' => 'Pemeriksaan lama.',
            'clinical_assessment' => 'Asesmen lama.',
            'care_plan' => 'Rencana lama.',
            'additional_notes' => '',
        ];
        $expectedLegacyFields = $legacyFields;
        ksort($expectedLegacyFields);

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $legacyFields,
            ])
            ->assertRedirect();
        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), ['expected_version' => 1])
            ->assertRedirect();

        $document = OutpatientClinicalDocument::query()->sole();
        $this->assertSame(OutpatientClinicalDocument::STATE_FINAL, $document->document_state);
        $this->assertSame($expectedLegacyFields, $document->fields);

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]), [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 2,
                'fields' => $this->codedFields(),
            ])
            ->assertStatus(422);

        $document->refresh();
        $this->assertSame(2, $document->version);
        $this->assertSame($expectedLegacyFields, $document->fields);
        $this->assertDatabaseCount('outpatient_clinical_document_versions', 2);
    }

    /** @return array<string, mixed> */
    private function codedFields(): array
    {
        return [
            'anamnesis' => 'Keluhan pusing.',
            'objective_examination' => 'Tanda vital stabil.',
            'clinical_assessment' => 'Vertigo perifer.',
            'care_plan' => 'Terapi simptomatik.',
            'additional_notes' => '',
            'diagnosis_text' => 'Pusing dengan dugaan vertigo perifer.',
            'primary_icd10' => ['code' => 'R42', 'display' => 'Dizziness and giddiness'],
            'secondary_icd10' => [['code' => 'H81.1', 'display' => 'Benign paroxysmal vertigo']],
            'procedures_icd9cm' => [],
        ];
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create(['status' => 'ACTIVE', 'is_system_administrator' => false]);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor;
    }

    private function encounter(User $actor): Encounter
    {
        $patient = Patient::factory()->create(['is_synthetic' => true, 'created_by_user_id' => $actor->id]);

        return Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $actor->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
        ]);
    }

    private function fakeTerminology(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/JsonGetConcept')) {
                $code = $request['ConceptId'];
                $display = match ($code) {
                    'R42' => 'Dizziness and giddiness',
                    'H81.1' => 'Benign paroxysmal vertigo',
                    'I10' => 'Essential (primary) hypertension',
                    default => null,
                };

                return $display === null
                    ? Http::response(null)
                    : Http::response(['ID' => $code, 'label' => $code.' '.$display]);
            }

            return Http::response([0, [], null, []]);
        });
    }
}
