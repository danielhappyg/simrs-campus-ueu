<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\OutpatientSafetyDispositionOutcome;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\OutpatientSafetyDisposition;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Models\Assignment;
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
