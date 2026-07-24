<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\ServiceRequestStatus;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Clinical\Models\MedicationStockMovement;
use App\Modules\Clinical\Models\PharmacyReview;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\CodingReviewActionModel;
use App\Modules\Coding\Models\CodingSuggestionDecision;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\RecordQuality\Enums\RecordQualityReviewStatus;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class CompleteReferenceOutpatientJourneyCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_command_completes_the_full_synthetic_journey_and_is_idempotent_after_finalization(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();

        $this->referenceJourneyCommand()
            ->expectsOutputToContain('reached FINALIZED')
            ->assertSuccessful();

        $encounter = Encounter::query()->sole();

        $this->assertSame(EncounterStatus::Finalized, $encounter->status);
        $this->assertNotNull($encounter->finalized_at);
        $this->assertTrue($encounter->patient()->sole()->synthetic_flag);
        $this->assertSame(2, ClinicalEntryVersion::query()->count());
        $this->assertSame(2, ClinicalEntryVersion::query()->where('status', ClinicalEntryStatus::Approved)->count());
        $this->assertSame(ServiceRequestStatus::Completed, ServiceRequest::query()->sole()->status);
        $this->assertDatabaseCount('diagnostic_results', 1);
        $this->assertSame(MedicationRequestStatus::Completed, MedicationRequest::query()->sole()->status);
        $this->assertDatabaseCount('pharmacy_reviews', 1);
        $this->assertDatabaseCount('medication_dispenses', 1);
        $this->assertDatabaseCount('medication_stock_movements', 1);
        $this->assertSame(EncounterClosureStatus::Approved, EncounterClosure::query()->sole()->status);
        $this->assertSame(ClinicalProcedureStatus::Completed, ClinicalProcedure::query()->sole()->status);
        $this->assertSame(RecordQualityReviewStatus::Approved, RecordQualityReview::query()->sole()->status);
        $this->assertSame(2, CodingAssignment::query()->where('status', CodingAssignmentStatus::Approved)->count());
        $this->assertSame(2, CodingSuggestionDecision::query()->where('decision', CodingDecisionType::ManualAlternative)->count());
        $this->assertDatabaseCount('coding_review_actions', 2);
        $this->assertSame(10, WorkTask::query()
            ->where('task_type', WorkTaskType::Debrief)
            ->where('status', WorkTaskStatus::Ready)
            ->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.transitioned',
            'resource_id' => $encounter->public_id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.manual_alternative_selected',
        ]);

        $before = $this->materialCounts();

        $this->referenceJourneyCommand()
            ->expectsOutputToContain('already finalized')
            ->assertSuccessful();

        $this->assertSame($before, $this->materialCounts());
    }

    public function test_command_completes_a_disposable_clone_without_progressing_the_pristine_source(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();
        $source = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->sole();
        $sourceEncounter = Encounter::query()->where('session_id', $source->getKey())->sole();
        $sourceStock = MedicationStock::query()->where('session_id', $source->getKey())->sole();

        $cloneCommand = $this->artisan('simulation:clone-reference-session', [
            'code' => 'LAB-REHEARSAL-001',
            '--duration' => '480',
        ]);

        if (! $cloneCommand instanceof PendingCommand) {
            $this->fail('Console output mocking must remain enabled for the reference-clone command test.');
        }

        $cloneCommand->assertSuccessful()->run();

        $target = SimulationSession::query()->where('code', 'LAB-REHEARSAL-001')->sole();
        $targetEncounter = Encounter::query()->where('session_id', $target->getKey())->sole();
        $targetStock = MedicationStock::query()->where('session_id', $target->getKey())->sole();

        $this->assertNotSame($sourceStock->lot_number, $targetStock->lot_number);

        $this->referenceJourneyCommand('LAB-REHEARSAL-001')
            ->expectsOutputToContain('reached FINALIZED')
            ->assertSuccessful();

        $this->assertSame(EncounterStatus::Finalized, $targetEncounter->fresh()->status);
        $this->assertSame(EncounterStatus::Planned, $sourceEncounter->fresh()->status);
        $this->assertSame($sourceStock->quantity_on_hand, $sourceStock->fresh()->quantity_on_hand);
        $this->assertSame('94.000', $targetStock->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('medication_stock_movements', [
            'session_id' => $target->getKey(),
            'medication_stock_id' => $targetStock->getKey(),
        ]);
        $this->assertDatabaseMissing('medication_stock_movements', [
            'session_id' => $source->getKey(),
        ]);
    }

    public function test_command_refuses_missing_terminology_before_any_workflow_mutation(): void
    {
        $this->seedReferenceOutpatient();

        $this->referenceJourneyCommand()
            ->expectsOutputToContain('Exactly one active ICD-10 release is required')
            ->assertFailed();

        $this->assertSame(EncounterStatus::Planned, Encounter::query()->sole()->status);
        $this->assertDatabaseCount('clinical_entry_versions', 0);
        $this->assertDatabaseCount('diagnostic_results', 0);
        $this->assertDatabaseCount('coding_assignments', 0);
    }

    public function test_command_prepares_an_idempotent_diagnosis_correction_fixture(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();

        $this->referenceCorrectionCommand(CodingSourceType::Diagnosis)
            ->expectsOutputToContain('active author-response state')
            ->assertSuccessful();

        $encounter = Encounter::query()->sole();
        $correction = CodingDocumentationCorrection::query()->sole();
        $medicalAuthor = Assignment::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'mahasiswa.kedokteran@example.invalid'))
            ->sole();
        $coder = Assignment::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'koder.rmik@example.invalid'))
            ->sole();

        $this->assertSame(EncounterStatus::AmendmentPending, $encounter->status);
        $this->assertSame(CodingDocumentationCorrectionStatus::Open, $correction->status);
        $this->assertSame($medicalAuthor->getKey(), $correction->responsible_assignment_id);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $medicalAuthor->getKey(),
            'task_type' => WorkTaskType::CodingSourceCorrection->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $coder->getKey(),
            'task_type' => WorkTaskType::Coding->value,
            'status' => WorkTaskStatus::Blocked->value,
        ]);
        $this->assertDatabaseCount('procedure_documentation_corrections', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.documentation_correction_requested',
        ]);

        $before = $this->materialCounts();
        $this->referenceCorrectionCommand(CodingSourceType::Diagnosis)
            ->expectsOutputToContain('already prepared')
            ->assertSuccessful();
        $this->assertSame($before, $this->materialCounts());
    }

    public function test_command_prepares_an_idempotent_procedure_correction_fixture(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();

        $this->referenceCorrectionCommand(CodingSourceType::Procedure)
            ->expectsOutputToContain('active author-response state')
            ->assertSuccessful();

        $encounter = Encounter::query()->sole();
        $correction = ProcedureDocumentationCorrection::query()->sole();
        $closureAuthor = Assignment::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'mahasiswa.kedokteran@example.invalid'))
            ->sole();
        $coder = Assignment::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'koder.rmik@example.invalid'))
            ->sole();

        $this->assertSame(EncounterStatus::AmendmentPending, $encounter->status);
        $this->assertSame(ProcedureDocumentationCorrectionStatus::Open, $correction->status);
        $this->assertSame($closureAuthor->getKey(), $correction->responsible_assignment_id);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $closureAuthor->getKey(),
            'task_type' => WorkTaskType::ProcedureSourceCorrection->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $coder->getKey(),
            'task_type' => WorkTaskType::Coding->value,
            'status' => WorkTaskStatus::Blocked->value,
        ]);
        $this->assertDatabaseCount('coding_documentation_corrections', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'coding.procedure_documentation_correction_requested',
        ]);

        $before = $this->materialCounts();
        $this->referenceCorrectionCommand(CodingSourceType::Procedure)
            ->expectsOutputToContain('already prepared')
            ->assertSuccessful();
        $this->assertSame($before, $this->materialCounts());
    }

    public function test_command_refuses_a_partially_progressed_case_without_overwriting_it(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->sole();
        $encounter = Encounter::query()->sole();

        $this->actingAs($registrar)
            ->post(route('appointments.check-in', $encounter->appointment))
            ->assertRedirect(route('encounters.show', $encounter));

        $this->referenceJourneyCommand()
            ->expectsOutputToContain('partially progressed')
            ->assertFailed();

        $this->assertSame(EncounterStatus::Arrived, $encounter->refresh()->status);
        $this->assertDatabaseCount('clinical_entry_versions', 0);
        $this->assertDatabaseCount('coding_assignments', 0);
    }

    public function test_command_refuses_when_the_explicit_synthetic_only_opt_in_is_removed(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();
        config(['simulation.synthetic_only' => false]);

        $this->referenceJourneyCommand()
            ->expectsOutputToContain('synthetic-only, non-production SIMULATION environment')
            ->assertFailed();

        $this->assertSame(EncounterStatus::Planned, Encounter::query()->sole()->status);
        $this->assertDatabaseCount('clinical_entry_versions', 0);
    }

    public function test_command_preflights_every_required_capability_before_check_in(): void
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();
        $pharmacyLearner = Assignment::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'mahasiswa.farmasi@example.invalid'))
            ->sole();
        $pharmacyLearner->update([
            'capabilities' => collect($pharmacyLearner->capabilities)
                ->reject(fn (string $capability): bool => $capability === Capability::Dispense->value)
                ->values()
                ->all(),
        ]);

        $this->referenceJourneyCommand()
            ->expectsOutputToContain('missing pharmacy.dispense')
            ->assertFailed();

        $this->assertSame(EncounterStatus::Planned, Encounter::query()->sole()->status);
        $this->assertDatabaseCount('clinical_entry_versions', 0);
    }

    private function activateReferenceTerminology(): void
    {
        $this->activateRelease(TerminologySystem::Icd10, 'R42', 'Dizziness and giddiness');
        $this->activateRelease(TerminologySystem::Icd9Cm, '38.99', 'Other puncture of vein');
    }

    private function referenceJourneyCommand(?string $sessionCode = null): PendingCommand
    {
        $command = $this->artisan(
            'simulation:complete-reference-journey',
            $sessionCode === null ? [] : ['--session' => $sessionCode],
        );

        if (! $command instanceof PendingCommand) {
            $this->fail('Console output mocking must remain enabled for the reference-journey command tests.');
        }

        return $command;
    }

    private function referenceCorrectionCommand(CodingSourceType $sourceType): PendingCommand
    {
        $command = $this->artisan('simulation:prepare-reference-correction', [
            'type' => strtolower($sourceType->value),
        ]);

        if (! $command instanceof PendingCommand) {
            $this->fail('Console output mocking must remain enabled for the reference-correction command tests.');
        }

        return $command;
    }

    private function activateRelease(TerminologySystem $system, string $code, string $display): void
    {
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->sole();
        $actor = Assignment::query()
            ->where('user_id', $facilitator->getKey())
            ->where('application_role', ApplicationRole::Facilitator)
            ->sole();
        $release = TerminologyRelease::query()->create([
            'request_key' => (string) Str::ulid(),
            'classification_system' => $system,
            'logical_version' => $system->logicalVersion(),
            'status' => TerminologyReleaseStatus::Imported,
            'source_filename' => 'synthetic-reference-'.$system->value.'.xlsx',
            'source_sha256' => hash('sha256', "{$system->value}|{$code}|{$display}"),
            'sheet_name' => $system->expectedSheetName(),
            'source_provenance_status' => TerminologyProvenanceStatus::SyntheticFixture,
            'imported_by_user_id' => $actor->user_id,
            'imported_by_assignment_id' => $actor->getKey(),
            'row_count' => 1,
            'ignored_blank_rows' => 0,
            'validation_report' => [
                'schema' => 'terminology-import-validation.v1',
                'classificationSystem' => $system->value,
                'sourceHashVerified' => true,
                'syntheticFixture' => true,
            ],
            'imported_at' => now(),
        ]);
        $normalizer = app(TerminologyNormalizer::class);
        $normalizedDisplay = $normalizer->text($display);
        TerminologyConcept::query()->create([
            'terminology_release_id' => $release->getKey(),
            'code' => $code,
            'display' => $display,
            'normalized_code' => $normalizer->code($code),
            'normalized_display' => $normalizedDisplay,
            'search_tokens' => implode(' ', $normalizer->tokens($normalizedDisplay)),
            'active' => true,
        ]);
        $release->persistActivation($actor, null);
    }

    /** @return array<string, int> */
    private function materialCounts(): array
    {
        return [
            'clinicalVersions' => ClinicalEntryVersion::query()->count(),
            'results' => DiagnosticResult::query()->count(),
            'pharmacyReviews' => PharmacyReview::query()->count(),
            'dispenses' => MedicationDispense::query()->count(),
            'stockMovements' => MedicationStockMovement::query()->count(),
            'closures' => EncounterClosure::query()->count(),
            'procedures' => ClinicalProcedure::query()->count(),
            'qualityReviews' => RecordQualityReview::query()->count(),
            'codingAssignments' => CodingAssignment::query()->count(),
            'codingDecisions' => CodingSuggestionDecision::query()->count(),
            'codingReviews' => CodingReviewActionModel::query()->count(),
            'diagnosisCorrections' => CodingDocumentationCorrection::query()->count(),
            'procedureCorrections' => ProcedureDocumentationCorrection::query()->count(),
            'tasks' => WorkTask::query()->count(),
            'auditEvents' => AuditEvent::query()->count(),
        ];
    }
}
