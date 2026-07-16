<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewItemOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Models\EncounterClosureReviewActionModel;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class EncounterClosureWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_closure_submission_is_blocked_before_results_and_pharmacy_are_complete(): void
    {
        $case = $this->prepareCaseThroughMedicalApproval();

        $this->assertSame(EncounterStatus::AwaitingResult, $case['encounter']->refresh()->status);
        $this->actingAs($case['medicalLearner'])
            ->get(route('encounters.closure.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/closure')
                ->where('readiness.ready', false)
                ->where('assignment.canAuthor', false)
                ->has('readiness.checks', 6)
            );

        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $this->closurePayload(ClinicalSaveIntent::Submit))
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('encounter_closures', 0);
    }

    public function test_draft_is_idempotent_and_preserves_derived_source_provenance(): void
    {
        $case = $this->prepareCompletedCase();
        $payload = $this->closurePayload(ClinicalSaveIntent::SaveDraft);

        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $payload)
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $payload)
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));

        $closure = EncounterClosure::query()->sole();
        $content = $closure->content;

        $this->assertSame(EncounterClosureStatus::Draft, $closure->status);
        $this->assertSame(1, $closure->version_number);
        $this->assertSame(hash('sha256', CanonicalJson::encode($content)), $closure->content_hash);
        $this->assertTrue((bool) data_get($content, 'readinessAtAuthoring.ready'));
        $this->assertNotNull(data_get($content, 'sourceSnapshot.medical.contentHash'));
        $this->assertNotNull(data_get($content, 'sourceSnapshot.results.0.resultContentHash'));
        $this->assertNotNull(data_get($content, 'sourceSnapshot.results.0.acknowledgementPublicId'));
        $this->assertSame('COMPLETED', data_get($content, 'sourceSnapshot.medications.0.status'));
        $this->assertDatabaseCount('encounter_closures', 1);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::EncounterClosure->value,
            'status' => WorkTaskStatus::InProgress->value,
        ]);
    }

    public function test_closure_requires_an_explicit_procedure_attestation_before_submission(): void
    {
        $case = $this->prepareCompletedCase();
        $payload = $this->closurePayload(ClinicalSaveIntent::Submit);
        unset($payload['procedure_documentation_state'], $payload['procedures']);

        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $payload)
            ->assertSessionHasErrors('procedure_documentation_state');

        $this->assertDatabaseCount('encounter_closures', 0);
        $this->assertDatabaseCount('clinical_procedures', 0);
    }

    public function test_clinician_records_an_immutable_completed_procedure_source_separate_from_its_order_and_icd_code(): void
    {
        $case = $this->prepareCompletedCase();
        $condition = ClinicalCondition::query()
            ->where('encounter_id', $case['encounter']->getKey())
            ->sole();
        $serviceRequest = ServiceRequest::query()->sole();
        $performedStart = now()->subMinutes(15);
        $performedEnd = now()->subMinutes(5);
        $payload = $this->closurePayload(ClinicalSaveIntent::Submit);
        $payload['procedure_documentation_state'] = ProcedureDocumentationState::ProceduresRecorded->value;
        $payload['procedures'] = [[
            'authored_text' => 'Pengambilan sampel darah vena untuk pemeriksaan sintetis.',
            'performed_start_at' => $performedStart->toIso8601String(),
            'performed_end_at' => $performedEnd->toIso8601String(),
            'performer_text' => 'Petugas laboratorium simulasi',
            'body_site_text' => 'Vena lengan kanan',
            'outcome_text' => 'Sampel sintetis berhasil diperoleh',
            'note' => 'Tidak ada komplikasi pada skenario.',
            'reason_condition_public_id' => $condition->public_id,
            'based_on_service_request_public_id' => $serviceRequest->public_id,
        ]];

        $this->assertDatabaseCount('clinical_procedures', 0);
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $payload)
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));

        $closure = EncounterClosure::query()->sole();
        $procedure = ClinicalProcedure::query()->sole();
        $documented = data_get($closure->content, 'procedureDocumentation.procedures.0');

        $this->assertSame('encounter-closure.v2', $closure->schema_version);
        $this->assertSame(ProcedureDocumentationState::ProceduresRecorded->value, data_get($closure->content, 'procedureDocumentation.state'));
        $this->assertSame($closure->getKey(), $procedure->encounter_closure_id);
        $this->assertSame($condition->getKey(), $procedure->reason_condition_id);
        $this->assertSame($serviceRequest->getKey(), $procedure->based_on_service_request_id);
        $this->assertSame('COMPLETED', $procedure->status->value);
        $this->assertSame('Pengambilan sampel darah vena untuk pemeriksaan sintetis.', $procedure->authored_text);
        $this->assertSame($procedure->public_id, data_get($documented, 'publicId'));
        $this->assertSame($procedure->content_hash, data_get($documented, 'contentHash'));
        $this->assertSame(hash('sha256', CanonicalJson::encode($procedure->integrityPayload())), $procedure->content_hash);

        $this->actingAs($case['medicalLearner'])
            ->get(route('encounters.closure.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.schemaVersion', 'encounter-closure.v2')
                ->where('document.latestVersion.content.procedureDocumentation.state', ProcedureDocumentationState::ProceduresRecorded->value)
                ->where('document.latestVersion.content.procedureDocumentation.procedures.0.publicId', $procedure->public_id)
                ->where('formOptions.diagnosisOptions.0.publicId', $condition->public_id)
                ->where('formOptions.serviceRequestOptions.0.publicId', $serviceRequest->public_id));

        $this->assertDomainFailure(
            fn () => $procedure->update(['authored_text' => 'Perubahan terlarang']),
            'performed procedure update',
        );
        $procedure->refresh();
        $this->assertDomainFailure(fn () => $procedure->delete(), 'performed procedure deletion');
    }

    public function test_submit_moves_to_closure_pending_and_only_the_linked_supervisor_can_review(): void
    {
        $case = $this->prepareCompletedCase();
        $this->submitClosure($case);
        $closure = EncounterClosure::query()->sole();

        $this->assertSame(EncounterClosureStatus::Submitted, $closure->status);
        $this->assertSame(EncounterStatus::ClosurePending, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalSupervisorAssignment']->getKey(),
            'task_type' => WorkTaskType::EncounterClosureReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);

        $this->actingAs($case['nursingSupervisor'])
            ->post(
                route('encounter-closures.review-decisions.store', $closure),
                $this->closureReviewPayload(EncounterClosureReviewAction::ApproveSimulation),
            )
            ->assertForbidden();

        $this->assertSame(EncounterClosureStatus::Submitted, $closure->refresh()->status);
        $this->assertDatabaseCount('encounter_closure_review_actions', 0);
    }

    public function test_changes_requested_create_an_attributed_successor_without_overwriting_history(): void
    {
        $case = $this->prepareCompletedCase();
        $this->submitClosure($case);
        $first = EncounterClosure::query()->sole();
        $firstHash = $first->content_hash;

        $this->actingAs($case['medicalSupervisor'])
            ->post(
                route('encounter-closures.review-decisions.store', $first),
                $this->closureReviewPayload(EncounterClosureReviewAction::RequestChanges),
            )
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));

        $this->assertSame(EncounterClosureStatus::ChangesRequested, $first->refresh()->status);
        $this->assertSame(EncounterStatus::InConsultation, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::EncounterClosure->value,
            'status' => WorkTaskStatus::ChangesRequested->value,
        ]);

        $withoutReason = $this->closurePayload(ClinicalSaveIntent::Submit);
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $withoutReason)
            ->assertSessionHasErrors('workflow');

        $corrected = $this->closurePayload(ClinicalSaveIntent::Submit);
        $corrected['change_reason'] = 'Ringkasan dan instruksi tindak lanjut diperjelas sesuai temuan supervisor.';
        $corrected['outpatient_summary'] = 'Ringkasan rawat jalan simulasi diperjelas pada versi penerus.';
        $this->actingAs($case['medicalLearner'])
            ->post(route('encounters.closure.versions.store', $case['encounter']), $corrected)
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));

        $second = EncounterClosure::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(2, $second->version_number);
        $this->assertSame($first->getKey(), $second->supersedes_closure_id);
        $this->assertSame(EncounterClosureStatus::Submitted, $second->status);
        $this->assertNotSame($firstHash, $second->content_hash);
        $this->assertSame($firstHash, $first->refresh()->content_hash);
        $this->assertDatabaseCount('encounter_closures', 2);
    }

    public function test_approval_clinically_closes_the_encounter_and_releases_exact_rmik_review(): void
    {
        $case = $this->prepareCompletedCase();
        $this->submitClosure($case);
        $closure = EncounterClosure::query()->sole();
        $payload = $this->closureReviewPayload(EncounterClosureReviewAction::ApproveSimulation);

        $this->actingAs($case['medicalSupervisor'])
            ->post(route('encounter-closures.review-decisions.store', $closure), $payload)
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));
        $this->actingAs($case['medicalSupervisor'])
            ->post(route('encounter-closures.review-decisions.store', $closure), $payload)
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));

        $this->assertSame(EncounterClosureStatus::Approved, $closure->refresh()->status);
        $this->assertSame(EncounterStatus::ClinicallyClosed, $case['encounter']->refresh()->status);
        $this->assertDatabaseCount('encounter_closure_review_actions', 1);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['rmikAssignment']->getKey(),
            'task_type' => WorkTaskType::RecordReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.encounter_closure_approved_for_simulation',
            'resource_id' => $closure->public_id,
        ]);
    }

    public function test_approval_rechecks_readiness_and_closure_records_are_immutable(): void
    {
        $case = $this->prepareCompletedCase();
        $this->submitClosure($case);
        $closure = EncounterClosure::query()->sole();
        $blocker = WorkTask::query()->create([
            'session_id' => $case['encounter']->session_id,
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'encounter_id' => $case['encounter']->getKey(),
            'task_type' => WorkTaskType::ResultAcknowledgement,
            'title' => 'Blocker readiness sintetis',
            'description' => 'Fixture untuk memastikan readiness diperiksa ulang.',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
            'source_program' => Program::Medicine,
            'context' => ['synthetic' => true],
            'available_at' => now(),
        ]);

        $this->actingAs($case['medicalSupervisor'])
            ->post(
                route('encounter-closures.review-decisions.store', $closure),
                $this->closureReviewPayload(EncounterClosureReviewAction::ApproveSimulation),
            )
            ->assertSessionHasErrors('workflow');
        $this->assertSame(EncounterClosureStatus::Submitted, $closure->refresh()->status);

        $blocker->update(['status' => WorkTaskStatus::Complete, 'completed_at' => now()]);
        $this->actingAs($case['medicalSupervisor'])
            ->post(
                route('encounter-closures.review-decisions.store', $closure),
                $this->closureReviewPayload(EncounterClosureReviewAction::ApproveSimulation),
            )
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));
        $review = EncounterClosureReviewActionModel::query()->sole();

        $this->assertDomainFailure(
            fn () => $closure->update(['content' => ['forbidden' => true]]),
            'closure content update',
        );
        $closure->refresh();
        $this->assertDomainFailure(fn () => $closure->delete(), 'closure deletion');
        $this->assertDomainFailure(fn () => $review->update(['comment' => 'forbidden']), 'review update');
        $review->refresh();
        $this->assertDomainFailure(fn () => $review->delete(), 'review deletion');
    }

    /**
     * @return array{
     *   encounter: Encounter,
     *   medicalLearner: User,
     *   medicalSupervisor: User,
     *   nursingSupervisor: User,
     *   pharmacyLearner: User,
     *   medicalAssignment: Assignment,
     *   medicalSupervisorAssignment: Assignment,
     *   pharmacyAssignment: Assignment,
     *   rmikAssignment: Assignment
     * }
     */
    private function prepareCaseThroughMedicalApproval(): array
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $nursingSupervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();
        $medicalLearner = User::query()->where('email', 'mahasiswa.kedokteran@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();
        $pharmacyLearner = User::query()->where('email', 'mahasiswa.farmasi@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $encounter->appointment));
        $this->actingAs($nurse)->post(
            route('encounters.nursing-intake.versions.store', $encounter),
            $this->nursingPayload(),
        );
        $nursingVersion = ClinicalEntryVersion::query()->where('schema_version', 'nursing-intake.v1')->sole();
        $this->actingAs($nursingSupervisor)->post(
            route('clinical-versions.review-decisions.store', $nursingVersion),
            $this->clinicalReviewPayload('Handoff disetujui untuk skenario.'),
        );
        $this->actingAs($medicalLearner)->post(
            route('encounters.medical-assessment.versions.store', $encounter),
            $this->medicalPayload(),
        );
        $medicalVersion = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->sole();
        $this->actingAs($medicalSupervisor)->post(
            route('clinical-versions.review-decisions.store', $medicalVersion),
            $this->clinicalReviewPayload('Asesmen, order, dan resep simulasi disetujui.'),
        );

        return [
            'encounter' => $encounter->refresh(),
            'medicalLearner' => $medicalLearner,
            'medicalSupervisor' => $medicalSupervisor,
            'nursingSupervisor' => $nursingSupervisor,
            'pharmacyLearner' => $pharmacyLearner,
            'medicalAssignment' => Assignment::query()->where('user_id', $medicalLearner->getKey())->sole(),
            'medicalSupervisorAssignment' => Assignment::query()->where('user_id', $medicalSupervisor->getKey())->sole(),
            'pharmacyAssignment' => Assignment::query()->where('user_id', $pharmacyLearner->getKey())->sole(),
            'rmikAssignment' => Assignment::query()
                ->where('program', Program::Rmik)
                ->where('application_role', ApplicationRole::Coder)
                ->whereNotNull('encounter_id')
                ->sole(),
        ];
    }

    /**
     * @return array{
     *   encounter: Encounter,
     *   medicalLearner: User,
     *   medicalSupervisor: User,
     *   nursingSupervisor: User,
     *   pharmacyLearner: User,
     *   medicalAssignment: Assignment,
     *   medicalSupervisorAssignment: Assignment,
     *   pharmacyAssignment: Assignment,
     *   rmikAssignment: Assignment
     * }
     */
    private function prepareCompletedCase(): array
    {
        $case = $this->prepareCaseThroughMedicalApproval();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $serviceRequest = ServiceRequest::query()->sole();
        $this->actingAs($facilitator)->post(
            route('service-requests.results.store', $serviceRequest),
            $this->resultPayload(),
        );
        $result = DiagnosticResult::query()->sole();
        $this->actingAs($case['medicalLearner'])->post(
            route('diagnostic-results.acknowledgements.store', $result),
            $this->acknowledgementPayload(),
        );
        $medicationRequest = MedicationRequest::query()->sole();
        $this->actingAs($case['pharmacyLearner'])->post(
            route('medication-requests.pharmacy-reviews.store', $medicationRequest),
            $this->pharmacyReviewPayload(),
        );
        $stock = MedicationStock::query()->where('lot_number', 'LOT-SIM-A-001')->sole();
        $this->actingAs($case['pharmacyLearner'])->post(
            route('medication-requests.dispenses.store', $medicationRequest),
            $this->dispensePayload($stock),
        );

        $case['encounter'] = $case['encounter']->refresh();

        return $case;
    }

    /** @param array<string, mixed> $case */
    private function submitClosure(array $case): void
    {
        $this->actingAs($case['medicalLearner'])
            ->post(
                route('encounters.closure.versions.store', $case['encounter']),
                $this->closurePayload(ClinicalSaveIntent::Submit),
            )
            ->assertRedirect(route('encounters.closure.show', $case['encounter']));
    }

    /** @return array<string, mixed> */
    private function closurePayload(ClinicalSaveIntent $intent): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => $intent->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'leaving_condition' => 'Kondisi stabil untuk menyelesaikan encounter rawat jalan simulasi.',
            'disposition' => 'Pulang dari poliklinik simulasi.',
            'follow_up_plan' => 'Kontrol simulasi sesuai jadwal skenario dan kembali bila alarm skenario muncul.',
            'referral_plan' => null,
            'education_instructions' => 'Instruksi penggunaan obat sintetis dan tanda kembali telah dijelaskan.',
            'outpatient_summary' => 'Asesmen, hasil sintetis, telaah farmasi manusia, dan rencana tindak lanjut telah ditinjau.',
            'procedure_documentation_state' => ProcedureDocumentationState::NonePerformed->value,
            'procedures' => [],
            'change_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function closureReviewPayload(EncounterClosureReviewAction $action): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'action' => $action->value,
            'comment' => $action === EncounterClosureReviewAction::ApproveSimulation
                ? 'Versi dan readiness ditinjau untuk persetujuan simulasi.'
                : 'Ringkasan tindak lanjut perlu diperjelas pada versi penerus.',
            'findings' => $action === EncounterClosureReviewAction::RequestChanges ? [[
                'code' => 'FOLLOW_UP_CLARITY',
                'severity' => 'BLOCKING',
                'message' => 'Perjelas hubungan ringkasan dengan instruksi tindak lanjut.',
            ]] : [],
        ];
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
    private function medicalPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'history_source' => 'Pasien sintetis dan handoff keperawatan',
            'present_illness' => 'Pusing episodik selama dua hari pada konteks kasus sintetis.',
            'past_medical_history' => 'Tidak ada riwayat tambahan dalam brief skenario.',
            'family_history' => 'Tidak ada riwayat relevan dalam brief skenario.',
            'social_history' => 'Konteks sosial disediakan hanya sebagai fixture pembelajaran.',
            'general_examination' => 'Keadaan umum tampak stabil pada skenario.',
            'focused_examination' => 'Pemeriksaan terfokus dicatat sesuai brief skenario.',
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
            'education' => 'Edukasi skenario dinyatakan oleh mahasiswa.',
            'follow_up_plan' => 'Kontrol simulasi sesuai jadwal skenario.',
            'intended_disposition' => 'Rawat jalan dalam skenario.',
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
            'narrative_conclusion' => 'Hasil fixture tidak menunjukkan temuan kritis dalam skenario.',
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
            'comment' => 'Versi hasil sintetis telah ditinjau.',
        ];
    }

    /** @return array<string, mixed> */
    private function pharmacyReviewPayload(): array
    {
        $clear = PharmacyReviewItemOutcome::Clear->value;

        return [
            'request_key' => (string) Str::ulid(),
            'overall_outcome' => PharmacyReviewOutcome::Accept->value,
            'domain_results' => [
                'administrative' => [
                    ['criterion_code' => 'PATIENT_IDENTITY', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'PRESCRIBER_AND_DATE', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'CLINIC_CONTEXT', 'outcome' => $clear, 'comment' => null],
                ],
                'pharmaceutical' => [
                    ['criterion_code' => 'MEDICINE_FORM_STRENGTH', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'DOSE_DIRECTIONS_QUANTITY', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'PREPARATION_STABILITY', 'outcome' => $clear, 'comment' => null],
                ],
                'clinical' => [
                    ['criterion_code' => 'INDICATION_AND_DOSE', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'DUPLICATION', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'ALLERGY_ADVERSE_REACTION', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'CONTRAINDICATION_INTERACTION', 'outcome' => $clear, 'comment' => null],
                ],
            ],
            'intervention' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function dispensePayload(MedicationStock $stock): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'outcome' => MedicationDispenseOutcome::Complete->value,
            'quantity' => 6,
            'medication_stock_id' => $stock->getKey(),
            'outcome_reason' => null,
            'preparation_notes' => 'Obat sintetis disiapkan sesuai permintaan yang telah ditelaah.',
            'final_check_confirmed' => true,
            'final_check_notes' => 'Identitas, obat, jumlah, etiket, dan lot sintetis diperiksa.',
            'handoff_recipient' => 'Pasien sintetis',
            'counseling_topics' => ['Cara penggunaan', 'Penyimpanan', 'Kapan kembali dalam skenario'],
            'counseling_acknowledged' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function clinicalReviewPayload(string $comment): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'action' => ClinicalReviewAction::ApproveSimulation->value,
            'comment' => $comment,
            'findings' => [],
        ];
    }

    private function assertDomainFailure(callable $action, string $label): void
    {
        try {
            $action();
            $this->fail("The protected closure record accepted a forbidden direct mutation: {$label}.");
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
