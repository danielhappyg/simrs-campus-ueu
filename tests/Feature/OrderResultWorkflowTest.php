<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Enums\DiagnosticResultStatus;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\ServiceRequestStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\ResultAcknowledgement;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class OrderResultWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_approved_requests_keep_exact_medical_provenance_and_gate_downstream_work(): void
    {
        $case = $this->prepareOrderedCase();
        $serviceRequest = ServiceRequest::query()->sole();
        $medicationRequest = MedicationRequest::query()->sole();
        $condition = ClinicalCondition::query()
            ->where('source_entry_version_id', $case['medicalVersion']->getKey())
            ->sole();

        $this->assertSame(ServiceRequestStatus::Active, $serviceRequest->status);
        $this->assertSame(MedicationRequestStatus::Active, $medicationRequest->status);
        $this->assertSame($case['medicalVersion']->getKey(), $serviceRequest->source_entry_version_id);
        $this->assertSame($case['medicalVersion']->getKey(), $medicationRequest->source_entry_version_id);
        $this->assertSame($condition->getKey(), $serviceRequest->source_condition_id);
        $this->assertSame($condition->getKey(), $medicationRequest->source_condition_id);
        $this->assertSame($case['medicalAssignment']->getKey(), $serviceRequest->requester_assignment_id);
        $this->assertSame($case['medicalAssignment']->getKey(), $medicationRequest->requester_assignment_id);
        $this->assertSame(EncounterStatus::AwaitingResult, $case['encounter']->refresh()->status);

        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['facilitatorAssignment']->getKey(),
            'encounter_id' => $case['encounter']->getKey(),
            'task_type' => WorkTaskType::SyntheticResultRelease->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'encounter_id' => $case['encounter']->getKey(),
            'task_type' => WorkTaskType::ResultAcknowledgement->value,
            'status' => WorkTaskStatus::Waiting->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'encounter_id' => $case['encounter']->getKey(),
            'task_type' => WorkTaskType::PharmacyReview->value,
            'status' => WorkTaskStatus::Waiting->value,
        ]);

        $this->actingAs($case['medicalSupervisor'])
            ->get(route('clinical-versions.review.show', $case['medicalVersion']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/review')
                ->has('document.conditions', 1)
                ->has('document.serviceRequests', 1)
                ->has('document.medicationRequests', 1)
                ->where('document.serviceRequests.0.sourceDiagnosis', $condition->authored_text)
                ->where('document.medicationRequests.0.sourceDiagnosis', $condition->authored_text));

        $this->actingAs($case['facilitator'])
            ->get(route('encounters.order-results.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/order-results')
                ->where('patient.allergyStatus', AllergyAssessmentState::NoKnownAllergyReported->label())
                ->where('assignment.canRelease', true)
                ->where('assignment.canAcknowledge', false)
                ->has('serviceRequests', 1)
                ->where('serviceRequests.0.source.medicalVersionPublicId', $case['medicalVersion']->public_id)
                ->where('serviceRequests.0.source.medicalContentHash', $case['medicalVersion']->content_hash)
                ->where('serviceRequests.0.currentResultPublicId', null));
    }

    public function test_only_an_authorized_simulation_actor_can_release_an_idempotent_final_result(): void
    {
        $case = $this->prepareOrderedCase();
        $serviceRequest = ServiceRequest::query()->sole();
        $payload = $this->resultPayload();

        $this->actingAs($case['nurse'])
            ->post(route('service-requests.results.store', $serviceRequest), $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('diagnostic_results', 0);

        $this->actingAs($case['facilitator'])
            ->post(route('service-requests.results.store', $serviceRequest), $payload)
            ->assertRedirect(route('encounters.order-results.show', $case['encounter']));
        $this->actingAs($case['facilitator'])
            ->post(route('service-requests.results.store', $serviceRequest), $payload)
            ->assertRedirect(route('encounters.order-results.show', $case['encounter']));

        $result = DiagnosticResult::query()->sole();
        $expectedContent = [
            'synthetic' => true,
            'narrativeConclusion' => $payload['narrative_conclusion'],
            'components' => [[
                'code' => 'SIM-HGB',
                'display' => 'Hemoglobin sintetis',
                'value' => '13.4',
                'unit' => 'g/dL',
            ]],
        ];

        $this->assertSame(1, $result->version_number);
        $this->assertSame(DiagnosticResultStatus::Final, $result->status);
        // JSON object key order is not significant and MySQL normalizes it on storage.
        $this->assertEquals($expectedContent, $result->content);
        $this->assertSame(hash('sha256', CanonicalJson::encode($expectedContent)), $result->content_hash);
        $this->assertNull($result->supersedes_result_id);
        $this->assertSame(ServiceRequestStatus::Completed, $serviceRequest->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['facilitatorAssignment']->getKey(),
            'task_type' => WorkTaskType::SyntheticResultRelease->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::ResultAcknowledgement->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.synthetic_result_released',
            'resource_id' => $result->public_id,
        ]);
    }

    public function test_only_the_requesting_medical_assignment_can_acknowledge_and_open_pharmacy_work(): void
    {
        $case = $this->prepareOrderedCase();
        $result = $this->releaseCurrentResult($case);
        $payload = $this->acknowledgementPayload();

        $this->actingAs($case['pharmacyLearner'])
            ->post(route('diagnostic-results.acknowledgements.store', $result), $payload)
            ->assertForbidden();

        $this->actingAs($case['medicalLearner'])
            ->post(route('diagnostic-results.acknowledgements.store', $result), $payload)
            ->assertRedirect(route('encounters.order-results.show', $case['encounter']));
        $this->actingAs($case['medicalLearner'])
            ->post(route('diagnostic-results.acknowledgements.store', $result), $payload)
            ->assertRedirect(route('encounters.order-results.show', $case['encounter']));

        $acknowledgement = ResultAcknowledgement::query()->sole();

        $this->assertSame($result->getKey(), $acknowledgement->diagnostic_result_id);
        $this->assertSame($case['medicalAssignment']->getKey(), $acknowledgement->actor_assignment_id);
        $this->assertSame(EncounterStatus::AwaitingPharmacy, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::ResultAcknowledgement->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::PharmacyReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
    }

    public function test_a_corrected_result_reopens_review_and_requires_acknowledgement_of_the_exact_new_version(): void
    {
        $case = $this->prepareOrderedCase();
        $serviceRequest = ServiceRequest::query()->sole();
        $firstResult = $this->releaseCurrentResult($case);

        $this->actingAs($case['medicalLearner'])->post(
            route('diagnostic-results.acknowledgements.store', $firstResult),
            $this->acknowledgementPayload(),
        );
        $this->assertSame(EncounterStatus::AwaitingPharmacy, $case['encounter']->refresh()->status);

        $correctionPayload = $this->resultPayload();
        $correctionPayload['narrative_conclusion'] = 'Hasil sintetis dikoreksi setelah verifikasi fixture skenario.';
        $correctionPayload['components'][0]['value'] = '13.1';
        $this->actingAs($case['facilitator'])
            ->post(route('service-requests.results.store', $serviceRequest), $correctionPayload)
            ->assertRedirect(route('encounters.order-results.show', $case['encounter']));

        $corrected = DiagnosticResult::query()->orderByDesc('version_number')->firstOrFail();

        $this->assertSame(2, $corrected->version_number);
        $this->assertSame(DiagnosticResultStatus::Corrected, $corrected->status);
        $this->assertSame($firstResult->getKey(), $corrected->supersedes_result_id);
        $this->assertSame(EncounterStatus::AwaitingResult, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::PharmacyReview->value,
            'status' => WorkTaskStatus::Waiting->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::ResultAcknowledgement->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);

        $this->actingAs($case['medicalLearner'])
            ->post(
                route('diagnostic-results.acknowledgements.store', $firstResult),
                $this->acknowledgementPayload(),
            )
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('result_acknowledgements', 1);
        $this->assertSame(EncounterStatus::AwaitingResult, $case['encounter']->refresh()->status);

        $this->actingAs($case['medicalLearner'])
            ->post(
                route('diagnostic-results.acknowledgements.store', $corrected),
                $this->acknowledgementPayload(),
            )
            ->assertRedirect(route('encounters.order-results.show', $case['encounter']));

        $this->assertDatabaseCount('result_acknowledgements', 2);
        $this->assertSame(EncounterStatus::AwaitingPharmacy, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::PharmacyReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);

        $this->actingAs($case['medicalLearner'])
            ->post(
                route('diagnostic-results.acknowledgements.store', $corrected),
                $this->acknowledgementPayload(),
            )
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('result_acknowledgements', 2);
    }

    public function test_request_result_and_acknowledgement_records_reject_direct_mutation_or_deletion(): void
    {
        $case = $this->prepareOrderedCase();
        $serviceRequest = ServiceRequest::query()->sole();
        $medicationRequest = MedicationRequest::query()->sole();
        $result = $this->releaseCurrentResult($case);
        $this->actingAs($case['medicalLearner'])->post(
            route('diagnostic-results.acknowledgements.store', $result),
            $this->acknowledgementPayload(),
        );
        $acknowledgement = ResultAcknowledgement::query()->sole();

        $this->assertDomainFailure(fn () => $serviceRequest->update(['authored_service' => 'Mutasi tidak sah']));
        $serviceRequest->refresh();
        $this->assertDomainFailure(fn () => $serviceRequest->update(['completed_at' => now()->subDay()]));
        $serviceRequest->refresh();
        $this->assertDomainFailure(fn () => $serviceRequest->delete());

        $this->assertDomainFailure(fn () => $medicationRequest->update(['directions' => 'Mutasi tidak sah']));
        $medicationRequest->refresh();
        $this->assertDomainFailure(fn () => $medicationRequest->update(['activated_at' => now()->subDay()]));
        $medicationRequest->refresh();
        $this->assertDomainFailure(fn () => $medicationRequest->delete());

        $this->assertDomainFailure(fn () => $result->update(['report_display' => 'Mutasi tidak sah']));
        $result->refresh();
        $this->assertDomainFailure(fn () => $result->delete());

        $this->assertDomainFailure(fn () => $acknowledgement->update(['comment' => 'Mutasi tidak sah']));
        $acknowledgement->refresh();
        $this->assertDomainFailure(fn () => $acknowledgement->delete());

        $this->assertDatabaseHas('service_requests', [
            'id' => $serviceRequest->getKey(),
            'authored_service' => 'Pemeriksaan darah sintetis skenario',
        ]);
        $this->assertDatabaseHas('medication_requests', [
            'id' => $medicationRequest->getKey(),
            'directions' => 'Gunakan sesuai instruksi skenario.',
        ]);
        $this->assertDatabaseHas('diagnostic_results', [
            'id' => $result->getKey(),
            'report_display' => 'Panel darah sintetis',
        ]);
        $this->assertDatabaseHas('result_acknowledgements', [
            'id' => $acknowledgement->getKey(),
        ]);
    }

    /**
     * @return array{
     *   encounter: Encounter,
     *   nurse: User,
     *   medicalLearner: User,
     *   medicalSupervisor: User,
     *   pharmacyLearner: User,
     *   facilitator: User,
     *   medicalAssignment: Assignment,
     *   pharmacyAssignment: Assignment,
     *   facilitatorAssignment: Assignment,
     *   medicalVersion: ClinicalEntryVersion
     * }
     */
    private function prepareOrderedCase(): array
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $nursingSupervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();
        $medicalLearner = User::query()->where('email', 'mahasiswa.kedokteran@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();
        $pharmacyLearner = User::query()->where('email', 'mahasiswa.farmasi@example.invalid')->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)
            ->post(route('appointments.check-in', $encounter->appointment))
            ->assertRedirect(route('encounters.show', $encounter));
        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $this->nursingPayload())
            ->assertRedirect(route('encounters.nursing-intake.show', $encounter));
        $nursingVersion = ClinicalEntryVersion::query()->where('schema_version', 'nursing-intake.v1')->sole();
        $this->actingAs($nursingSupervisor)
            ->post(
                route('clinical-versions.review-decisions.store', $nursingVersion),
                $this->reviewPayload('Handoff disetujui untuk skenario.'),
            )
            ->assertRedirect(route('clinical-versions.review.show', $nursingVersion));
        $this->actingAs($medicalLearner)
            ->post(
                route('encounters.medical-assessment.versions.store', $encounter),
                $this->medicalPayloadWithRequests(),
            )
            ->assertRedirect(route('encounters.medical-assessment.show', $encounter));
        $medicalVersion = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->sole();
        $this->actingAs($medicalSupervisor)
            ->post(
                route('clinical-versions.review-decisions.store', $medicalVersion),
                $this->reviewPayload('Asesmen, order, dan resep simulasi disetujui.'),
            )
            ->assertRedirect(route('clinical-versions.review.show', $medicalVersion));

        return [
            'encounter' => $encounter->refresh(),
            'nurse' => $nurse,
            'medicalLearner' => $medicalLearner,
            'medicalSupervisor' => $medicalSupervisor,
            'pharmacyLearner' => $pharmacyLearner,
            'facilitator' => $facilitator,
            'medicalAssignment' => Assignment::query()->where('user_id', $medicalLearner->getKey())->sole(),
            'pharmacyAssignment' => Assignment::query()->where('user_id', $pharmacyLearner->getKey())->sole(),
            'facilitatorAssignment' => Assignment::query()->where('user_id', $facilitator->getKey())->sole(),
            'medicalVersion' => $medicalVersion->refresh(),
        ];
    }

    /** @param array<string, mixed> $case */
    private function releaseCurrentResult(array $case): DiagnosticResult
    {
        $serviceRequest = ServiceRequest::query()->sole();
        $this->actingAs($case['facilitator'])
            ->post(route('service-requests.results.store', $serviceRequest), $this->resultPayload())
            ->assertRedirect(route('encounters.order-results.show', $case['encounter']));

        return DiagnosticResult::query()->orderByDesc('version_number')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function nursingPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'history_source' => 'Pasien sintetis',
            'chief_complaint' => 'Pusing sejak dua hari sebelum kunjungan simulasi.',
            'onset_duration' => 'Dua hari',
            'consciousness' => 'Sadar penuh dan dapat menjawab pertanyaan.',
            'allergy_state' => AllergyAssessmentState::NoKnownAllergyReported->value,
            'current_medication_state' => CurrentMedicationState::NoneReported->value,
            'vitals' => [
                'temperature' => 36.8,
                'heart_rate' => 82,
                'respiratory_rate' => 18,
                'systolic_blood_pressure' => 118,
                'diastolic_blood_pressure' => 76,
                'oxygen_saturation' => 98,
            ],
            'safety_responses' => [[
                'question_code' => 'SUPERVISOR_CONCERN',
                'response' => 'NO',
                'note' => null,
            ]],
            'safety_decision' => IntakeSafetyDecision::RoutineFlow->value,
            'note' => 'Catatan keperawatan untuk skenario.',
            'handoff_summary' => 'Keluhan dan tanda vital diteruskan untuk asesmen medis.',
        ];
    }

    /** @return array<string, mixed> */
    private function medicalPayloadWithRequests(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'history_source' => 'Pasien sintetis dan handoff keperawatan',
            'present_illness' => 'Pusing episodik selama dua hari pada konteks kasus sintetis, tanpa data pasien nyata.',
            'past_medical_history' => 'Tidak ada riwayat tambahan dalam brief skenario.',
            'family_history' => 'Tidak ada riwayat relevan dalam brief skenario.',
            'social_history' => 'Konteks sosial disediakan hanya sebagai fixture pembelajaran.',
            'general_examination' => 'Keadaan umum tampak stabil pada skenario dan pasien mampu berkomunikasi.',
            'focused_examination' => 'Pemeriksaan terfokus dicatat sesuai brief skenario tanpa rekomendasi otomatis.',
            'assessment_summary' => 'Temuan disintesis oleh mahasiswa untuk latihan supervisi.',
            'diagnoses' => [[
                'authored_text' => 'Sindrom pusing dalam evaluasi pada skenario simulasi.',
                'certainty' => DiagnosisCertainty::Working->value,
                'role' => DiagnosisRole::Primary->value,
                'onset_at' => now()->subDays(2)->toDateString(),
            ]],
            'service_requests' => [[
                'request_type' => 'LABORATORY',
                'authored_service' => 'Pemeriksaan darah sintetis skenario',
                'clinical_question' => 'Dokumentasikan hasil fixture untuk mendukung penilaian skenario.',
                'priority' => 'ROUTINE',
                'source_diagnosis_index' => 0,
            ]],
            'medication_requests' => [[
                'authored_medication' => 'Obat Simulasi A',
                'form' => 'Tablet',
                'strength' => '500 mg',
                'dose_value' => 1,
                'dose_unit' => 'tablet',
                'route' => 'Oral',
                'frequency' => 'Dua kali sehari',
                'duration' => 'Tiga hari',
                'quantity_value' => 6,
                'quantity_unit' => 'tablet',
                'directions' => 'Gunakan sesuai instruksi skenario.',
                'indication_text' => 'Sindrom pusing dalam evaluasi.',
                'source_diagnosis_index' => 0,
            ]],
            'care_plan' => 'Rencana simulasi mencakup hasil sintetis dan telaah resep manusia.',
            'education' => 'Edukasi skenario dan tanda untuk kembali dinyatakan oleh mahasiswa.',
            'follow_up_plan' => 'Kontrol simulasi sesuai jadwal skenario.',
            'intended_disposition' => 'Rawat jalan dalam skenario, menunggu penyelesaian tahap berikutnya.',
        ];
    }

    /** @return array<string, mixed> */
    private function resultPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'report_code' => 'LAB-SIM-001',
            'report_display' => 'Panel darah sintetis',
            'effective_at' => now()->toIso8601String(),
            'narrative_conclusion' => 'Hasil fixture tidak menunjukkan temuan kritis dalam skenario simulasi.',
            'components' => [[
                'code' => 'SIM-HGB',
                'display' => 'Hemoglobin sintetis',
                'value' => '13.4',
                'unit' => 'g/dL',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function acknowledgementPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'outcome' => 'ACKNOWLEDGED',
            'comment' => 'Versi hasil sintetis telah ditinjau dalam konteks skenario.',
        ];
    }

    /** @return array<string, mixed> */
    private function reviewPayload(string $comment): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'action' => ClinicalReviewAction::ApproveSimulation->value,
            'comment' => $comment,
            'findings' => [],
        ];
    }

    private function assertDomainFailure(callable $action): void
    {
        try {
            $action();
            $this->fail('The protected record accepted a forbidden direct mutation.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
