<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\ServiceRequestStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\MedicationRequest;
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

class MedicalAssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_medical_authoring_is_blocked_until_the_nursing_handoff_is_approved(): void
    {
        $this->seedReferenceOutpatient();
        $medicalLearner = User::query()->where('email', 'mahasiswa.kedokteran@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($medicalLearner)
            ->post(
                route('encounters.medical-assessment.versions.store', $encounter),
                $this->medicalPayload(ClinicalSaveIntent::SaveDraft),
            )
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('clinical_entry_versions', 0);
        $this->assertSame(EncounterStatus::Planned, $encounter->refresh()->status);
    }

    public function test_medical_workspace_shows_the_exact_approved_nursing_source_without_editing_it(): void
    {
        [$encounter, $medicalLearner, $nursingVersion] = $this->prepareMedicalCase();

        $this->actingAs($medicalLearner)
            ->get(route('encounters.medical-assessment.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/medical-assessment')
                ->where('patient.synthetic', true)
                ->where('encounter.status.code', EncounterStatus::WaitingClinician->value)
                ->where('nursingSource.versionPublicId', $nursingVersion->public_id)
                ->where('nursingSource.contentHash', $nursingVersion->content_hash)
                ->where('nursingSource.status.code', ClinicalEntryStatus::Approved->value)
                ->has('nursingSource.observations', 6)
                ->where('document.schemaVersion', 'medical-assessment.v1')
                ->where('document.workflowEnabled', true)
                ->where('document.canEdit', true)
                ->has('formOptions.diagnosisCertainties', 3)
                ->has('formOptions.diagnosisRoles', 2));
    }

    public function test_medical_draft_is_idempotent_versioned_and_creates_uncoded_clinician_authored_conditions(): void
    {
        [$encounter, $medicalLearner] = $this->prepareMedicalCase();
        $requestKey = (string) Str::ulid();
        $payload = $this->medicalPayload(ClinicalSaveIntent::SaveDraft, $requestKey);

        $this->actingAs($medicalLearner)
            ->post(route('encounters.medical-assessment.versions.store', $encounter), $payload)
            ->assertRedirect(route('encounters.medical-assessment.show', $encounter));
        $this->actingAs($medicalLearner)
            ->post(route('encounters.medical-assessment.versions.store', $encounter), $payload)
            ->assertRedirect(route('encounters.medical-assessment.show', $encounter));

        $medicalVersion = ClinicalEntryVersion::query()
            ->where('schema_version', 'medical-assessment.v1')
            ->with('conditions')
            ->sole();
        $condition = $medicalVersion->conditions->sole();

        $this->assertSame(1, $medicalVersion->version_number);
        $this->assertSame(ClinicalEntryStatus::Draft, $medicalVersion->status);
        $this->assertSame(hash('sha256', CanonicalJson::encode($medicalVersion->content)), $medicalVersion->content_hash);
        $this->assertSame('Sindrom pusing dalam evaluasi pada skenario simulasi.', $condition->authored_text);
        $this->assertSame(DiagnosisCertainty::Working, $condition->certainty);
        $this->assertSame(DiagnosisRole::Primary, $condition->role);
        $this->assertNull($condition->code_system);
        $this->assertNull($condition->code);
        $this->assertSame(EncounterStatus::InConsultation, $encounter->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'task_type' => WorkTaskType::MedicalAssessment->value,
            'status' => WorkTaskStatus::InProgress->value,
            'clinical_entry_version_id' => $medicalVersion->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.medical_assessment_version_created',
            'encounter_id' => $encounter->getKey(),
        ]);
    }

    public function test_medical_submission_requires_complete_sections_and_exactly_one_primary_diagnosis(): void
    {
        [$encounter, $medicalLearner] = $this->prepareMedicalCase();
        $invalid = $this->medicalPayload(ClinicalSaveIntent::Submit);
        $invalid['present_illness'] = '';
        $invalid['diagnoses'][] = [
            'authored_text' => 'Diagnosis utama kedua yang tidak diperbolehkan.',
            'certainty' => DiagnosisCertainty::Differential->value,
            'role' => DiagnosisRole::Primary->value,
            'onset_at' => null,
        ];

        $this->actingAs($medicalLearner)
            ->post(route('encounters.medical-assessment.versions.store', $encounter), $invalid)
            ->assertSessionHasErrors(['present_illness', 'diagnoses']);

        $this->assertDatabaseCount('clinical_entries', 1);
        $this->assertDatabaseCount('clinical_entry_versions', 1);
        $this->assertSame(EncounterStatus::WaitingClinician, $encounter->refresh()->status);
    }

    public function test_submitted_medical_version_is_routed_only_to_the_linked_medical_supervisor(): void
    {
        [$encounter, $medicalLearner] = $this->prepareMedicalCase();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();
        $nursingSupervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();

        $this->actingAs($medicalLearner)
            ->post(
                route('encounters.medical-assessment.versions.store', $encounter),
                $this->medicalPayload(ClinicalSaveIntent::Submit),
            )
            ->assertRedirect(route('encounters.medical-assessment.show', $encounter));

        $version = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->sole();
        $supervisorAssignment = Assignment::query()->where('user_id', $medicalSupervisor->getKey())->sole();

        $this->assertSame(ClinicalEntryStatus::Submitted, $version->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $supervisorAssignment->getKey(),
            'task_type' => WorkTaskType::SupervisorReview->value,
            'clinical_entry_version_id' => $version->getKey(),
            'status' => WorkTaskStatus::Ready->value,
        ]);

        $this->actingAs($medicalSupervisor)
            ->get(route('clinical-versions.review.show', $version))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/review')
                ->where('document.type', 'MEDICAL_ASSESSMENT')
                ->where('patient.allergyStatus', AllergyAssessmentState::NoKnownAllergyReported->label())
                ->where('document.contentHash', $version->content_hash)
                ->has('document.conditions', 1)
                ->where('document.conditions.0.code', null)
                ->where('document.conditions.0.authoredText', 'Sindrom pusing dalam evaluasi pada skenario simulasi.'));

        $this->actingAs($nursingSupervisor)
            ->get(route('clinical-versions.review.show', $version))
            ->assertForbidden();
    }

    public function test_medical_supervisor_approval_completes_the_version_but_does_not_invent_downstream_orders(): void
    {
        [$encounter, $medicalLearner] = $this->prepareMedicalCase();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();
        $this->actingAs($medicalLearner)->post(
            route('encounters.medical-assessment.versions.store', $encounter),
            $this->medicalPayload(ClinicalSaveIntent::Submit),
        );
        $version = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->sole();

        $this->actingAs($medicalSupervisor)
            ->post(route('clinical-versions.review-decisions.store', $version), [
                'request_key' => (string) Str::ulid(),
                'action' => ClinicalReviewAction::ApproveSimulation->value,
                'comment' => 'Asesmen medis dapat digunakan untuk melanjutkan simulasi.',
                'findings' => [],
            ])
            ->assertRedirect(route('clinical-versions.review.show', $version));

        $this->assertSame(ClinicalEntryStatus::Approved, $version->refresh()->status);
        $this->assertSame(EncounterStatus::InConsultation, $encounter->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'task_type' => WorkTaskType::MedicalAssessment->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseMissing('work_tasks', [
            'task_type' => WorkTaskType::PharmacyReview->value,
        ]);
        $approvalAudit = AuditEvent::query()
            ->where('action', 'clinical.version_approved_for_simulation')
            ->where('resource_id', $version->public_id)
            ->sole();

        $this->assertSame(1, $approvalAudit->metadata['downstream_tasks_released']);
        $this->assertArrayNotHasKey('medical_tasks_released', $approvalAudit->metadata);
    }

    public function test_clinician_authored_condition_is_immutable_and_cannot_be_backfilled_with_a_code(): void
    {
        [$encounter, $medicalLearner] = $this->prepareMedicalCase();
        $this->actingAs($medicalLearner)->post(
            route('encounters.medical-assessment.versions.store', $encounter),
            $this->medicalPayload(ClinicalSaveIntent::SaveDraft),
        );
        $condition = ClinicalCondition::query()->sole();

        try {
            $condition->update([
                'code_system' => 'http://hl7.org/fhir/sid/icd-10',
                'code' => 'R42',
                'display' => 'Dizziness and giddiness',
            ]);
            $this->fail('A clinician-authored condition must not be overwritten by coding data.');
        } catch (DomainException) {
            $this->assertNull($condition->refresh()->code);
            $this->assertSame('Sindrom pusing dalam evaluasi pada skenario simulasi.', $condition->authored_text);
        }
    }

    public function test_successor_medical_version_cancels_orphaned_draft_orders_from_superseded_draft(): void
    {
        [$encounter, $medicalLearner, , $medicalSupervisor] = $this->prepareMedicalCase();

        $this->actingAs($medicalLearner)->post(
            route('encounters.medical-assessment.versions.store', $encounter),
            $this->medicalPayloadWithOrders(ClinicalSaveIntent::SaveDraft),
        );

        $draftVersion = ClinicalEntryVersion::query()
            ->where('schema_version', 'medical-assessment.v1')
            ->sole();
        $orphanedServiceRequest = ServiceRequest::query()
            ->where('source_entry_version_id', $draftVersion->getKey())
            ->sole();
        $orphanedMedicationRequest = MedicationRequest::query()
            ->where('source_entry_version_id', $draftVersion->getKey())
            ->sole();

        $this->assertSame(ServiceRequestStatus::Draft, $orphanedServiceRequest->status);
        $this->assertSame(MedicationRequestStatus::Draft, $orphanedMedicationRequest->status);

        $this->actingAs($medicalLearner)->post(
            route('encounters.medical-assessment.versions.store', $encounter),
            $this->medicalPayloadWithOrders(ClinicalSaveIntent::Submit),
        );

        $submittedVersion = ClinicalEntryVersion::query()
            ->where('schema_version', 'medical-assessment.v1')
            ->orderByDesc('version_number')
            ->firstOrFail();

        $this->assertSame(2, $submittedVersion->version_number);
        $this->assertSame(ClinicalEntryStatus::Submitted, $submittedVersion->status);
        $this->assertSame(ServiceRequestStatus::Cancelled, $orphanedServiceRequest->refresh()->status);
        $this->assertSame(MedicationRequestStatus::Cancelled, $orphanedMedicationRequest->refresh()->status);
        $this->assertStringContainsString(
            'Superseded by medical assessment version 2',
            (string) $orphanedMedicationRequest->cancellation_reason,
        );

        $currentServiceRequest = ServiceRequest::query()
            ->where('source_entry_version_id', $submittedVersion->getKey())
            ->sole();
        $currentMedicationRequest = MedicationRequest::query()
            ->where('source_entry_version_id', $submittedVersion->getKey())
            ->sole();

        $this->assertSame(ServiceRequestStatus::Draft, $currentServiceRequest->status);
        $this->assertSame(MedicationRequestStatus::Draft, $currentMedicationRequest->status);

        $this->actingAs($medicalSupervisor)->post(
            route('clinical-versions.review-decisions.store', $submittedVersion),
            [
                'request_key' => (string) Str::ulid(),
                'action' => ClinicalReviewAction::ApproveSimulation->value,
                'comment' => 'Asesmen medis disetujui untuk skenario.',
                'findings' => [],
            ],
        );

        $this->assertSame(ServiceRequestStatus::Active, $currentServiceRequest->refresh()->status);
        $this->assertSame(MedicationRequestStatus::Active, $currentMedicationRequest->refresh()->status);
        $this->assertSame(ServiceRequestStatus::Cancelled, $orphanedServiceRequest->refresh()->status);
        $this->assertSame(MedicationRequestStatus::Cancelled, $orphanedMedicationRequest->refresh()->status);
        $this->assertDatabaseCount('service_requests', 2);
        $this->assertDatabaseCount('medication_requests', 2);
    }

    /** @return array{Encounter, User, ClinicalEntryVersion, User} */
    private function prepareMedicalCase(): array
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $nursingSupervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();
        $medicalLearner = User::query()->where('email', 'mahasiswa.kedokteran@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $encounter->appointment));
        $this->actingAs($nurse)->post(
            route('encounters.nursing-intake.versions.store', $encounter),
            $this->nursingPayload(),
        );
        $nursingVersion = ClinicalEntryVersion::query()->where('schema_version', 'nursing-intake.v1')->sole();
        $this->actingAs($nursingSupervisor)->post(
            route('clinical-versions.review-decisions.store', $nursingVersion),
            [
                'request_key' => (string) Str::ulid(),
                'action' => ClinicalReviewAction::ApproveSimulation->value,
                'comment' => 'Handoff disetujui untuk skenario.',
                'findings' => [],
            ],
        );

        return [$encounter->refresh(), $medicalLearner, $nursingVersion->refresh(), $medicalSupervisor];
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
    private function medicalPayload(ClinicalSaveIntent $intent, ?string $requestKey = null): array
    {
        return [
            'request_key' => $requestKey ?? (string) Str::ulid(),
            'intent' => $intent->value,
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
                'code' => 'SHOULD_BE_IGNORED',
            ]],
            'care_plan' => 'Rencana simulasi dicatat untuk tahap order dan resep berikutnya.',
            'education' => 'Edukasi skenario dan tanda untuk kembali dinyatakan oleh mahasiswa.',
            'follow_up_plan' => 'Kontrol simulasi sesuai jadwal skenario.',
            'intended_disposition' => 'Rawat jalan dalam skenario, menunggu penyelesaian tahap berikutnya.',
        ];
    }

    /** @return array<string, mixed> */
    private function medicalPayloadWithOrders(ClinicalSaveIntent $intent): array
    {
        $payload = $this->medicalPayload($intent);
        $payload['service_requests'] = [[
            'request_type' => 'LABORATORY',
            'authored_service' => 'Pemeriksaan darah sintetis skenario',
            'clinical_question' => 'Dokumentasikan hasil fixture untuk mendukung penilaian skenario.',
            'priority' => 'ROUTINE',
            'source_diagnosis_index' => 0,
        ]];
        $payload['medication_requests'] = [[
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
        ]];

        return $payload;
    }
}
