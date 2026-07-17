<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\OutpatientSafetyDispositionOutcome;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\OutpatientSafetyDisposition;
use App\Modules\Clinical\Services\OutpatientSafetyDispositionService;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class OutpatientSafetyDispositionWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_append_only_disposition_preserves_exact_source_actor_outcome_and_time(): void
    {
        [$encounter, $source, $supervisorAssignment] = $this->prepareApprovedEscalation();
        $occurredAt = now();

        $disposition = OutpatientSafetyDisposition::query()->create([
            'request_key' => (string) Str::ulid(),
            'encounter_id' => $encounter->getKey(),
            'source_clinical_entry_version_id' => $source->getKey(),
            'source_content_hash' => $source->content_hash,
            'actor_user_id' => $supervisorAssignment->user_id,
            'actor_assignment_id' => $supervisorAssignment->getKey(),
            'outcome' => OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
            'rationale' => 'Supervisor memutuskan alur rutin simulasi dapat dilanjutkan setelah peninjauan manusia.',
            'occurred_at' => $occurredAt,
        ]);

        $this->assertTrue(Str::isUlid($disposition->public_id));
        $this->assertSame($encounter->getKey(), $disposition->encounter->getKey());
        $this->assertSame($source->getKey(), $disposition->sourceVersion->getKey());
        $this->assertSame($source->content_hash, $disposition->source_content_hash);
        $this->assertSame($supervisorAssignment->getKey(), $disposition->actorAssignment->getKey());
        $this->assertSame($supervisorAssignment->user_id, $disposition->actor->getKey());
        $this->assertSame(OutpatientSafetyDispositionOutcome::ResumeRoutineFlow, $disposition->outcome);
        $this->assertSame(
            $occurredAt->format('Y-m-d H:i:s'),
            $disposition->occurred_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_disposition_rejects_a_source_hash_that_does_not_match_the_approved_escalation(): void
    {
        [$encounter, $source, $supervisorAssignment] = $this->prepareApprovedEscalation();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('source version and integrity hash');

        OutpatientSafetyDisposition::query()->create([
            'request_key' => (string) Str::ulid(),
            'encounter_id' => $encounter->getKey(),
            'source_clinical_entry_version_id' => $source->getKey(),
            'source_content_hash' => str_repeat('0', 64),
            'actor_user_id' => $supervisorAssignment->user_id,
            'actor_assignment_id' => $supervisorAssignment->getKey(),
            'outcome' => OutpatientSafetyDispositionOutcome::SimulatedTransfer,
            'rationale' => 'Supervisor memilih transfer simulasi setelah meninjau konteks eskalasi.',
            'occurred_at' => now(),
        ]);
    }

    public function test_disposition_cannot_be_updated_or_deleted(): void
    {
        [$encounter, $source, $supervisorAssignment] = $this->prepareApprovedEscalation();
        $disposition = OutpatientSafetyDisposition::query()->create([
            'request_key' => (string) Str::ulid(),
            'encounter_id' => $encounter->getKey(),
            'source_clinical_entry_version_id' => $source->getKey(),
            'source_content_hash' => $source->content_hash,
            'actor_user_id' => $supervisorAssignment->user_id,
            'actor_assignment_id' => $supervisorAssignment->getKey(),
            'outcome' => OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
            'rationale' => 'Supervisor memutuskan alur rutin simulasi dapat dilanjutkan setelah peninjauan manusia.',
            'occurred_at' => now(),
        ]);

        try {
            $disposition->update(['rationale' => 'Rasional yang diubah tidak boleh tersimpan.']);
            $this->fail('Disposition updates must be rejected.');
        } catch (DomainException) {
            $this->assertStringContainsString('dilanjutkan', $disposition->refresh()->rationale);
        }

        try {
            $disposition->delete();
            $this->fail('Disposition deletion must be rejected.');
        } catch (DomainException) {
            $this->assertDatabaseCount('outpatient_safety_dispositions', 1);
        }
    }

    public function test_approved_escalation_creates_disposition_tasks_and_keeps_medical_work_blocked(): void
    {
        [$encounter, $source, $supervisorAssignment] = $this->prepareApprovedEscalation();
        $facilitatorAssignment = $this->facilitatorAssignment();

        foreach ([$supervisorAssignment, $facilitatorAssignment] as $assignment) {
            $task = WorkTask::query()
                ->where('assignment_id', $assignment->getKey())
                ->where('encounter_id', $encounter->getKey())
                ->where('task_type', WorkTaskType::SafetyDisposition)
                ->sole();

            $this->assertSame(WorkTaskStatus::Ready, $task->status);
            $this->assertSame($source->public_id, data_get($task->context, 'sourceNursingVersionPublicId'));
            $this->assertSame($source->content_hash, data_get($task->context, 'sourceNursingContentHash'));
            $this->assertStringNotContainsString('Mahasiswa memilih', json_encode($task->context, JSON_THROW_ON_ERROR));
        }

        $this->assertSame(
            WorkTaskStatus::Blocked,
            WorkTask::query()->where('task_type', WorkTaskType::MedicalAssessment)->sole()->status,
        );
    }

    public function test_linked_supervisor_can_resume_routine_flow_idempotently_with_minimized_audit(): void
    {
        [$encounter, $source, $supervisorAssignment] = $this->prepareApprovedEscalation();
        $requestKey = (string) Str::ulid();
        $rationale = 'Supervisor meninjau eskalasi dan memutuskan alur rutin simulasi dapat dilanjutkan.';
        $service = app(OutpatientSafetyDispositionService::class);

        $first = $service->record(
            encounter: $encounter,
            actorAssignment: $supervisorAssignment,
            outcome: OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
            requestKey: $requestKey,
            rationale: $rationale,
        );
        $second = $service->record(
            encounter: $encounter,
            actorAssignment: $supervisorAssignment,
            outcome: OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
            requestKey: $requestKey,
            rationale: $rationale,
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(EncounterStatus::WaitingClinician, $encounter->refresh()->status);
        $this->assertDatabaseCount('outpatient_safety_dispositions', 1);
        $this->assertSame(
            WorkTaskStatus::Ready,
            WorkTask::query()->where('task_type', WorkTaskType::MedicalAssessment)->sole()->status,
        );
        $this->assertSame(
            WorkTaskStatus::Complete,
            WorkTask::query()
                ->where('task_type', WorkTaskType::SafetyDisposition)
                ->where('assignment_id', $supervisorAssignment->getKey())
                ->sole()
                ->status,
        );
        $this->assertSame(
            WorkTaskStatus::Cancelled,
            WorkTask::query()
                ->where('task_type', WorkTaskType::SafetyDisposition)
                ->where('assignment_id', $this->facilitatorAssignment()->getKey())
                ->sole()
                ->status,
        );

        $audit = AuditEvent::query()
            ->where('action', 'clinical.outpatient_safety_disposition_recorded')
            ->sole();
        $this->assertSame($rationale, $audit->reason);
        $this->assertSame([
            'disposition_tasks_cancelled',
            'disposition_tasks_completed',
            'medical_tasks_cancelled',
            'medical_tasks_readied',
            'outcome',
            'source_nursing_content_hash',
            'source_nursing_version_public_id',
        ], array_keys(collect($audit->metadata)->sortKeys()->all()));
        $this->assertStringNotContainsString($rationale, json_encode($audit->metadata, JSON_THROW_ON_ERROR));
        $this->assertSame($source->public_id, $audit->metadata['source_nursing_version_public_id']);
    }

    public function test_session_facilitator_can_record_a_simulated_transfer_and_cancel_routine_medical_work(): void
    {
        [$encounter] = $this->prepareApprovedEscalation();
        $facilitatorAssignment = $this->facilitatorAssignment();

        $disposition = app(OutpatientSafetyDispositionService::class)->record(
            encounter: $encounter,
            actorAssignment: $facilitatorAssignment,
            outcome: OutpatientSafetyDispositionOutcome::SimulatedTransfer,
            requestKey: (string) Str::ulid(),
            rationale: 'Fasilitator mencatat pengalihan hanya sebagai hasil skenario simulasi yang ditinjau manusia.',
        );

        $this->assertSame(OutpatientSafetyDispositionOutcome::SimulatedTransfer, $disposition->outcome);
        $this->assertSame(EncounterStatus::TransferredSimulation, $encounter->refresh()->status);
        $this->assertSame(
            WorkTaskStatus::Cancelled,
            WorkTask::query()->where('task_type', WorkTaskType::MedicalAssessment)->sole()->status,
        );
    }

    public function test_conflicting_or_late_disposition_is_rejected_without_overwriting_the_first_decision(): void
    {
        [$encounter, , $supervisorAssignment] = $this->prepareApprovedEscalation();
        $requestKey = (string) Str::ulid();
        $service = app(OutpatientSafetyDispositionService::class);

        $service->record(
            encounter: $encounter,
            actorAssignment: $supervisorAssignment,
            outcome: OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
            requestKey: $requestKey,
            rationale: 'Supervisor meninjau eskalasi dan memutuskan alur rutin simulasi dapat dilanjutkan.',
        );

        foreach ([
            [$requestKey, OutpatientSafetyDispositionOutcome::SimulatedTransfer],
            [(string) Str::ulid(), OutpatientSafetyDispositionOutcome::SimulatedTransfer],
        ] as [$attemptKey, $attemptOutcome]) {
            try {
                $service->record(
                    encounter: $encounter,
                    actorAssignment: $supervisorAssignment,
                    outcome: $attemptOutcome,
                    requestKey: $attemptKey,
                    rationale: 'Keputusan kedua ini harus ditolak agar keputusan pertama tidak tertimpa.',
                );
                $this->fail('A conflicting or late disposition must be rejected.');
            } catch (DomainException) {
                $this->assertDatabaseCount('outpatient_safety_dispositions', 1);
            }
        }

        $this->assertSame(EncounterStatus::WaitingClinician, $encounter->refresh()->status);
        $this->assertSame(
            OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
            OutpatientSafetyDisposition::query()->sole()->outcome,
        );
    }

    public function test_revoked_assignment_cannot_record_a_disposition(): void
    {
        [$encounter, , $supervisorAssignment] = $this->prepareApprovedEscalation();
        $supervisorAssignment->update([
            'revoked_at' => now(),
            'revocation_reason' => 'Test revocation',
        ]);

        $this->expectException(DomainException::class);

        app(OutpatientSafetyDispositionService::class)->record(
            encounter: $encounter,
            actorAssignment: $supervisorAssignment,
            outcome: OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
            requestKey: (string) Str::ulid(),
            rationale: 'A revoked assignment must not be able to release the blocked simulation flow.',
        );
    }

    public function test_downstream_failure_rolls_back_disposition_transition_tasks_and_audit(): void
    {
        [$encounter, , $supervisorAssignment] = $this->prepareApprovedEscalation();

        $this->app->instance(
            EncounterTransitionService::class,
            new class(app(AuditRecorder::class)) extends EncounterTransitionService
            {
                public function transition(
                    Encounter $encounter,
                    EncounterStatus $target,
                    Assignment $actorAssignment,
                    ?string $reason = null,
                ): Encounter {
                    throw new DomainException('Forced downstream transition failure.');
                }
            },
        );

        try {
            app(OutpatientSafetyDispositionService::class)->record(
                encounter: $encounter,
                actorAssignment: $supervisorAssignment,
                outcome: OutpatientSafetyDispositionOutcome::ResumeRoutineFlow,
                requestKey: (string) Str::ulid(),
                rationale: 'This decision must roll back because a downstream transition failure is forced.',
            );
            $this->fail('The forced downstream failure must escape the service transaction.');
        } catch (DomainException $exception) {
            $this->assertSame('Forced downstream transition failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outpatient_safety_dispositions', 0);
        $this->assertSame(EncounterStatus::Escalated, $encounter->refresh()->status);
        $this->assertTrue(
            WorkTask::query()
                ->where('task_type', WorkTaskType::SafetyDisposition)
                ->get()
                ->every(fn (WorkTask $task): bool => $task->status === WorkTaskStatus::Ready),
        );
        $this->assertSame(
            WorkTaskStatus::Blocked,
            WorkTask::query()->where('task_type', WorkTaskType::MedicalAssessment)->sole()->status,
        );
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'clinical.outpatient_safety_disposition_recorded',
        ]);
    }

    /** @return array{Encounter, ClinicalEntryVersion, Assignment} */
    private function prepareApprovedEscalation(): array
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $supervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)
            ->post(route('appointments.check-in', $encounter->appointment))
            ->assertRedirect(route('encounters.show', $encounter));

        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $this->nursingPayload())
            ->assertRedirect(route('encounters.nursing-intake.show', $encounter));

        $source = ClinicalEntryVersion::query()->sole();

        $this->actingAs($supervisor)
            ->post(route('clinical-versions.review-decisions.store', $source), [
                'request_key' => (string) Str::ulid(),
                'action' => ClinicalReviewAction::ApproveSimulation->value,
                'comment' => 'Keputusan eskalasi manusia dikonfirmasi untuk simulasi.',
                'findings' => [],
            ])
            ->assertRedirect(route('clinical-versions.review.show', $source));

        $encounter->refresh();
        $this->assertSame(EncounterStatus::Escalated, $encounter->status);

        return [
            $encounter,
            $source->refresh(),
            Assignment::query()->where('user_id', $supervisor->getKey())->sole(),
        ];
    }

    private function facilitatorAssignment(): Assignment
    {
        return Assignment::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'fasilitator.simulasi@example.invalid'))
            ->sole();
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
                'response' => 'YES',
                'note' => 'Mahasiswa memilih eskalasi manusia untuk skenario ini.',
            ]],
            'safety_decision' => IntakeSafetyDecision::EscalateToSupervisor->value,
            'note' => 'Catatan dibuat hanya untuk latihan dengan data sintetis.',
            'handoff_summary' => 'Keluhan dan tanda vital dicatat; alur rutin dihentikan untuk keputusan manusia.',
        ];
    }
}
