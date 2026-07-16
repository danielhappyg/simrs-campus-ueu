<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\ObservationStatus;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalObservation;
use App\Modules\Clinical\Models\ClinicalReviewActionModel;
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

class NursingIntakeWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_authorized_learner_saves_an_idempotent_immutable_draft_with_typed_observations(): void
    {
        [$encounter, $nurse] = $this->prepareArrivedCase();
        $requestKey = (string) Str::ulid();
        $payload = $this->nursingPayload(ClinicalSaveIntent::SaveDraft, requestKey: $requestKey);

        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $payload)
            ->assertRedirect(route('encounters.nursing-intake.show', $encounter));
        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $payload)
            ->assertRedirect(route('encounters.nursing-intake.show', $encounter));

        $version = ClinicalEntryVersion::query()->with(['observations', 'allergyAssessment'])->sole();

        $this->assertSame(1, $version->version_number);
        $this->assertSame(ClinicalEntryStatus::Draft, $version->status);
        $this->assertSame(hash('sha256', CanonicalJson::encode($version->content)), $version->content_hash);
        $this->assertCount(6, $version->observations);
        $this->assertSame(
            ['2708-6', '8310-5', '8462-4', '8480-6', '8867-4', '9279-1'],
            $version->observations->pluck('code')->sort()->values()->all(),
        );
        $this->assertTrue($version->observations->every(
            fn (ClinicalObservation $observation): bool => $observation->status === ObservationStatus::Preliminary
                && $observation->code_system === 'http://loinc.org'
                && $observation->unit_system === 'http://unitsofmeasure.org'
                && $observation->mapping_version === 'OPD-NURSING-VITALS-v1',
        ));
        $this->assertSame(AllergyAssessmentState::NoKnownAllergyReported, $version->allergyAssessment?->assessment_state);
        $this->assertSame(EncounterStatus::InIntake, $encounter->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'encounter_id' => $encounter->getKey(),
            'task_type' => WorkTaskType::NursingIntake->value,
            'status' => WorkTaskStatus::InProgress->value,
            'clinical_entry_version_id' => $version->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.nursing_intake_version_created',
            'encounter_id' => $encounter->getKey(),
        ]);
    }

    public function test_submission_requires_complete_explicit_clinical_fields(): void
    {
        [$encounter, $nurse] = $this->prepareArrivedCase();

        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), [
                'request_key' => (string) Str::ulid(),
                'intent' => ClinicalSaveIntent::Submit->value,
                'clinical_occurrence_at' => now()->toIso8601String(),
            ])
            ->assertSessionHasErrors([
                'chief_complaint',
                'onset_duration',
                'consciousness',
                'allergy_state',
                'current_medication_state',
                'vitals',
                'safety_decision',
                'handoff_summary',
            ]);

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertDatabaseCount('clinical_entry_versions', 0);
        $this->assertSame(EncounterStatus::Arrived, $encounter->refresh()->status);
    }

    public function test_nursing_workspace_exposes_case_context_explicit_states_and_standardized_vitals(): void
    {
        [$encounter, $nurse] = $this->prepareArrivedCase();

        $this->actingAs($nurse)
            ->get(route('encounters.nursing-intake.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/nursing-intake')
                ->where('patient.synthetic', true)
                ->where('patient.fullName', 'Pasien Sintetis Arunika')
                ->where('encounter.number', 'ENC-SIM-000001')
                ->where('encounter.environmentMode', 'SIMULATION')
                ->where('document.schemaVersion', 'nursing-intake.v1')
                ->where('document.canEdit', true)
                ->where('formOptions.vitals.temperature.code', '8310-5')
                ->where('formOptions.vitals.temperature.unitCode', 'Cel')
                ->has('formOptions.allergyStates', 3)
                ->has('formOptions.medicationStates', 3)
                ->has('formOptions.safetyDecisions', 3));
    }

    public function test_submission_is_bound_to_its_author_supervisor_and_exact_content_hash(): void
    {
        [$encounter, $nurse, $supervisor] = $this->prepareArrivedCase();

        $this->actingAs($nurse)
            ->post(
                route('encounters.nursing-intake.versions.store', $encounter),
                $this->nursingPayload(ClinicalSaveIntent::Submit),
            )
            ->assertRedirect(route('encounters.nursing-intake.show', $encounter));

        $version = ClinicalEntryVersion::query()->with('reviewActions')->sole();
        $supervisorAssignment = Assignment::query()->where('user_id', $supervisor->getKey())->sole();

        $this->assertSame(ClinicalEntryStatus::Submitted, $version->status);
        $this->assertNotNull($version->submitted_at);
        $this->assertCount(1, $version->reviewActions);
        $this->assertSame(ClinicalReviewAction::Submit, $version->reviewActions->first()?->action);
        $this->assertSame($version->content_hash, $version->reviewActions->first()?->reviewed_content_hash);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $supervisorAssignment->getKey(),
            'clinical_entry_version_id' => $version->getKey(),
            'task_type' => WorkTaskType::SupervisorReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertSame(EncounterStatus::InIntake, $encounter->refresh()->status);
        $this->assertTrue($version->observations()->get()->every(
            fn (ClinicalObservation $observation): bool => $observation->status === ObservationStatus::Final,
        ));
    }

    public function test_only_the_linked_supervisor_sees_the_exact_submitted_version_review_workspace(): void
    {
        [$encounter, $nurse, $supervisor] = $this->prepareArrivedCase();
        $this->submitNursingIntake($encounter, $nurse);
        $version = ClinicalEntryVersion::query()->sole();
        $otherSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();

        $this->actingAs($supervisor)
            ->get(route('clinical-versions.review.show', $version))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/review')
                ->where('document.versionPublicId', $version->public_id)
                ->where('document.versionNumber', 1)
                ->where('document.contentHash', $version->content_hash)
                ->where('document.status.code', ClinicalEntryStatus::Submitted->value)
                ->where('document.canReview', true)
                ->has('document.observations', 6)
                ->has('document.reviews', 1));

        $this->actingAs($otherSupervisor)
            ->get(route('clinical-versions.review.show', $version))
            ->assertForbidden();
    }

    public function test_unassigned_or_revoked_users_cannot_author_clinical_content(): void
    {
        [$encounter, $nurse] = $this->prepareArrivedCase();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();

        $this->actingAs($registrar)
            ->post(
                route('encounters.nursing-intake.versions.store', $encounter),
                $this->nursingPayload(ClinicalSaveIntent::SaveDraft),
            )
            ->assertForbidden();

        Assignment::query()->where('user_id', $nurse->getKey())->update([
            'revoked_at' => now(),
            'revocation_reason' => 'Test revocation',
        ]);

        $this->actingAs($nurse)
            ->post(
                route('encounters.nursing-intake.versions.store', $encounter),
                $this->nursingPayload(ClinicalSaveIntent::SaveDraft),
            )
            ->assertForbidden();

        $this->assertDatabaseCount('clinical_entry_versions', 0);
    }

    public function test_request_changes_preserves_the_submitted_version_and_requires_a_reason_for_its_successor(): void
    {
        [$encounter, $nurse, $supervisor] = $this->prepareArrivedCase();
        $this->submitNursingIntake($encounter, $nurse);
        $submitted = ClinicalEntryVersion::query()->sole();
        $submittedHash = $submitted->content_hash;

        $this->actingAs($supervisor)
            ->post(route('clinical-versions.review-decisions.store', $submitted), [
                'request_key' => (string) Str::ulid(),
                'action' => ClinicalReviewAction::RequestChanges->value,
                'comment' => 'Perjelas ringkasan handoff dan sumber riwayat.',
                'findings' => [[
                    'code' => 'HANDOFF_CLARITY',
                    'severity' => 'BLOCKING',
                    'message' => 'Ringkasan handoff belum cukup spesifik.',
                ]],
            ])
            ->assertRedirect(route('clinical-versions.review.show', $submitted));

        $this->assertSame(ClinicalEntryStatus::ChangesRequested, $submitted->refresh()->status);
        $this->assertSame($submittedHash, $submitted->content_hash);
        $this->assertSame(ClinicalEntryStatus::ChangesRequested, $submitted->clinicalEntry->refresh()->lifecycle_status);
        $this->assertDatabaseHas('work_tasks', [
            'task_type' => WorkTaskType::NursingIntake->value,
            'status' => WorkTaskStatus::ChangesRequested->value,
            'clinical_entry_version_id' => $submitted->getKey(),
        ]);

        $withoutReason = $this->nursingPayload(ClinicalSaveIntent::Submit);
        $withoutReason['handoff_summary'] = 'Keluhan dan tanda vital diteruskan untuk penilaian medis.';
        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $withoutReason)
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('clinical_entry_versions', 1);

        $corrected = $this->nursingPayload(ClinicalSaveIntent::Submit);
        $corrected['change_reason'] = 'Memperjelas sumber riwayat dan ringkasan handoff sesuai tinjauan supervisor.';
        $corrected['handoff_summary'] = 'Keluhan pusing dua hari, tanda vital tercatat, tanpa alergi atau obat yang dilaporkan.';
        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $corrected)
            ->assertRedirect(route('encounters.nursing-intake.show', $encounter));

        $successor = ClinicalEntryVersion::query()->where('version_number', 2)->sole();
        $this->assertSame($submitted->getKey(), $successor->supersedes_version_id);
        $this->assertSame(ClinicalEntryStatus::Submitted, $successor->status);
        $this->assertNotSame($submittedHash, $successor->content_hash);
        $this->assertSame($submittedHash, $submitted->refresh()->content_hash);
    }

    public function test_supervisor_approval_releases_the_medical_task_and_advances_routine_flow(): void
    {
        [$encounter, $nurse, $supervisor] = $this->prepareArrivedCase();
        $this->submitNursingIntake($encounter, $nurse);
        $version = ClinicalEntryVersion::query()->sole();
        $requestKey = (string) Str::ulid();
        $decision = [
            'request_key' => $requestKey,
            'action' => ClinicalReviewAction::ApproveSimulation->value,
            'comment' => 'Dokumentasi lengkap untuk tujuan simulasi.',
            'findings' => [],
        ];

        $this->actingAs($supervisor)
            ->post(route('clinical-versions.review-decisions.store', $version), $decision)
            ->assertRedirect(route('clinical-versions.review.show', $version));
        $this->actingAs($supervisor)
            ->post(route('clinical-versions.review-decisions.store', $version), $decision)
            ->assertRedirect(route('clinical-versions.review.show', $version));

        $this->assertSame(ClinicalEntryStatus::Approved, $version->refresh()->status);
        $this->assertSame(EncounterStatus::WaitingClinician, $encounter->refresh()->status);
        $this->assertSame(2, ClinicalReviewActionModel::query()->count());
        $this->assertDatabaseHas('work_tasks', [
            'task_type' => WorkTaskType::NursingIntake->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'task_type' => WorkTaskType::SupervisorReview->value,
            'clinical_entry_version_id' => $version->getKey(),
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'task_type' => WorkTaskType::MedicalAssessment->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.version_approved_for_simulation',
            'encounter_id' => $encounter->getKey(),
        ]);
    }

    public function test_approved_human_escalation_blocks_routine_medical_flow(): void
    {
        [$encounter, $nurse, $supervisor] = $this->prepareArrivedCase();
        $this->submitNursingIntake($encounter, $nurse, IntakeSafetyDecision::EscalateToSupervisor);
        $version = ClinicalEntryVersion::query()->sole();

        $this->actingAs($supervisor)
            ->post(route('clinical-versions.review-decisions.store', $version), [
                'request_key' => (string) Str::ulid(),
                'action' => ClinicalReviewAction::ApproveSimulation->value,
                'comment' => 'Keputusan eskalasi manusia dikonfirmasi untuk simulasi.',
                'findings' => [],
            ])
            ->assertRedirect(route('clinical-versions.review.show', $version));

        $this->assertSame(EncounterStatus::Escalated, $encounter->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'task_type' => WorkTaskType::MedicalAssessment->value,
            'status' => WorkTaskStatus::Blocked->value,
        ]);
    }

    public function test_clinical_content_observations_and_reviews_cannot_be_overwritten_or_deleted(): void
    {
        [$encounter, $nurse] = $this->prepareArrivedCase();
        $this->submitNursingIntake($encounter, $nurse);
        $version = ClinicalEntryVersion::query()->with(['observations', 'reviewActions'])->sole();
        $originalHash = $version->content_hash;

        try {
            $version->update(['content' => ['tampered' => true]]);
            $this->fail('Clinical content mutation should throw.');
        } catch (DomainException) {
            $this->assertSame($originalHash, $version->refresh()->content_hash);
        }

        $observation = $version->observations->firstOrFail();

        try {
            $observation->update(['display' => 'Tampered display']);
            $this->fail('Observation mutation should throw.');
        } catch (DomainException) {
            $this->assertNotSame('Tampered display', $observation->refresh()->display);
        }

        try {
            $version->reviewActions->firstOrFail()->delete();
            $this->fail('Review deletion should throw.');
        } catch (DomainException) {
            $this->assertDatabaseCount('clinical_review_actions', 1);
        }

        try {
            $version->delete();
            $this->fail('Clinical version deletion should throw.');
        } catch (DomainException) {
            $this->assertDatabaseCount('clinical_entry_versions', 1);
        }
    }

    /** @return array{Encounter, User, User} */
    private function prepareArrivedCase(): array
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $supervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)
            ->post(route('appointments.check-in', $encounter->appointment))
            ->assertRedirect(route('encounters.show', $encounter));

        return [$encounter->refresh(), $nurse, $supervisor];
    }

    private function submitNursingIntake(
        Encounter $encounter,
        User $nurse,
        IntakeSafetyDecision $decision = IntakeSafetyDecision::RoutineFlow,
    ): void {
        $this->actingAs($nurse)
            ->post(
                route('encounters.nursing-intake.versions.store', $encounter),
                $this->nursingPayload(ClinicalSaveIntent::Submit, $decision),
            )
            ->assertRedirect(route('encounters.nursing-intake.show', $encounter));
    }

    /** @return array<string, mixed> */
    private function nursingPayload(
        ClinicalSaveIntent $intent,
        IntakeSafetyDecision $decision = IntakeSafetyDecision::RoutineFlow,
        ?string $requestKey = null,
    ): array {
        return [
            'request_key' => $requestKey ?? (string) Str::ulid(),
            'intent' => $intent->value,
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
                'response' => $decision === IntakeSafetyDecision::RoutineFlow ? 'NO' : 'YES',
                'note' => $decision === IntakeSafetyDecision::RoutineFlow
                    ? null
                    : 'Mahasiswa memilih eskalasi manusia untuk skenario ini.',
            ]],
            'safety_decision' => $decision->value,
            'note' => 'Catatan dibuat hanya untuk latihan dengan data sintetis.',
            'handoff_summary' => 'Keluhan pusing, tanda vital tercatat, lanjutkan asesmen medis sesuai alur simulasi.',
        ];
    }
}
