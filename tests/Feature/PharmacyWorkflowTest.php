<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Enums\DispensePreparationReviewAction;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\PharmacyInterventionStatus;
use App\Modules\Clinical\Enums\PharmacyResponseAction;
use App\Modules\Clinical\Enums\PharmacyReviewItemOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationDispensePreparation;
use App\Modules\Clinical\Models\MedicationDispensePreparationReview;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Clinical\Models\MedicationStockMovement;
use App\Modules\Clinical\Models\PharmacyIntervention;
use App\Modules\Clinical\Models\PharmacyInterventionMessage;
use App\Modules\Clinical\Models\PharmacyReview;
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

class PharmacyWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_three_domain_review_is_human_authored_complete_and_cannot_accept_a_finding(): void
    {
        $case = $this->preparePharmacyCase();
        $medicationRequest = MedicationRequest::query()->sole();
        $invalid = $this->reviewPayload(PharmacyReviewOutcome::Accept, finding: true);

        $this->actingAs($case['medicalLearner'])
            ->post(route('medication-requests.pharmacy-reviews.store', $medicationRequest), $invalid)
            ->assertForbidden();
        $this->actingAs($case['pharmacyLearner'])
            ->post(route('medication-requests.pharmacy-reviews.store', $medicationRequest), $invalid)
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('pharmacy_reviews', 0);
        $this->assertSame(MedicationRequestStatus::Active, $medicationRequest->refresh()->status);

        $payload = $this->reviewPayload(PharmacyReviewOutcome::Accept);
        $this->actingAs($case['pharmacyLearner'])
            ->post(route('medication-requests.pharmacy-reviews.store', $medicationRequest), $payload)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));
        $this->actingAs($case['pharmacyLearner'])
            ->post(route('medication-requests.pharmacy-reviews.store', $medicationRequest), $payload)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $review = PharmacyReview::query()->sole();
        $expectedContent = [
            'overallOutcome' => PharmacyReviewOutcome::Accept->value,
            'domainResults' => $review->domain_results,
        ];

        $this->assertSame(1, $review->version_number);
        $this->assertSame(PharmacyReviewOutcome::Accept, $review->overall_outcome);
        $this->assertSame(hash('sha256', CanonicalJson::encode($expectedContent)), $review->content_hash);
        $this->assertSame($case['pharmacyAssignment']->getKey(), $review->reviewer_assignment_id);
        $this->assertSame(MedicationRequestStatus::Accepted, $medicationRequest->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::PharmacyReview->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::Dispensing->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.pharmacy_review_recorded',
            'resource_id' => $review->public_id,
        ]);

        $this->actingAs($case['pharmacyLearner'])
            ->get(route('encounters.pharmacy.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clinical/pharmacy')
                ->where('assignment.canReview', true)
                ->where('assignment.canRespond', false)
                ->where('assignment.canDispense', true)
                ->where('reviewDefinition.humanAuthored', true)
                ->has('reviewDefinition.criteria.administrative', 3)
                ->has('reviewDefinition.criteria.pharmaceutical', 3)
                ->has('reviewDefinition.criteria.clinical', 4)
                ->where('allergySource.state', AllergyAssessmentState::NoKnownAllergyReported->value)
                ->has('medicationRequests', 1)
                ->where('medicationRequests.0.reviews.0.contentHash', $review->content_hash));
    }

    public function test_clarification_holds_the_request_and_requires_prescriber_response_then_explicit_rereview(): void
    {
        $case = $this->preparePharmacyCase();
        $medicationRequest = MedicationRequest::query()->sole();

        $this->actingAs($case['pharmacyLearner'])
            ->post(
                route('medication-requests.pharmacy-reviews.store', $medicationRequest),
                $this->reviewPayload(PharmacyReviewOutcome::ClarificationRequired, finding: true),
            )
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $intervention = PharmacyIntervention::query()->sole();
        $firstReview = PharmacyReview::query()->sole();

        $this->assertSame(PharmacyInterventionStatus::Open, $intervention->status);
        $this->assertSame($firstReview->getKey(), $intervention->source_pharmacy_review_id);
        $this->assertSame(MedicationRequestStatus::OnHold, $medicationRequest->refresh()->status);
        $this->assertDatabaseCount('pharmacy_intervention_messages', 1);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::PharmacyReview->value,
            'status' => WorkTaskStatus::Blocked->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::PrescriptionInterventionResponse->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);

        $response = $this->responsePayload(PharmacyResponseAction::Explanation);
        $this->actingAs($case['nurse'])
            ->post(route('pharmacy-interventions.responses.store', $intervention), $response)
            ->assertForbidden();
        $this->actingAs($case['medicalLearner'])
            ->post(route('pharmacy-interventions.responses.store', $intervention), $response)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));
        $this->actingAs($case['medicalLearner'])
            ->post(route('pharmacy-interventions.responses.store', $intervention), $response)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $this->assertSame(PharmacyInterventionStatus::Responded, $intervention->refresh()->status);
        $this->assertSame(MedicationRequestStatus::Active, $medicationRequest->refresh()->status);
        $this->assertDatabaseCount('pharmacy_intervention_messages', 2);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::PharmacyReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);

        $this->actingAs($case['pharmacyLearner'])
            ->post(
                route('medication-requests.pharmacy-reviews.store', $medicationRequest),
                $this->reviewPayload(PharmacyReviewOutcome::Accept),
            )
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $reviews = PharmacyReview::query()->orderBy('version_number')->get();
        $secondReview = $reviews->last();

        $this->assertCount(2, $reviews);
        $this->assertNotNull($secondReview);
        $this->assertSame(2, $secondReview->version_number);
        $this->assertSame($firstReview->getKey(), $secondReview->supersedes_review_id);
        $this->assertSame(PharmacyInterventionStatus::Resolved, $intervention->refresh()->status);
        $this->assertDatabaseCount('pharmacy_intervention_messages', 3);
        $this->assertSame(MedicationRequestStatus::Accepted, $medicationRequest->refresh()->status);
    }

    public function test_replacement_preserves_the_old_request_and_routes_the_successor_back_to_pharmacy(): void
    {
        $case = $this->preparePharmacyCase();
        $original = MedicationRequest::query()->sole();
        $originalDirections = $original->directions;

        $this->actingAs($case['pharmacyLearner'])->post(
            route('medication-requests.pharmacy-reviews.store', $original),
            $this->reviewPayload(PharmacyReviewOutcome::ClarificationRequired, finding: true),
        );
        $intervention = PharmacyIntervention::query()->sole();
        $response = $this->responsePayload(PharmacyResponseAction::Replace);
        $response['replacement'] = [
            'authored_medication' => 'Obat Simulasi A',
            'form' => 'Tablet',
            'strength' => '500 mg',
            'dose_value' => 1,
            'dose_unit' => 'tablet',
            'route' => 'Oral',
            'frequency' => 'Satu kali sehari',
            'duration' => 'Tiga hari',
            'quantity_value' => 3,
            'quantity_unit' => 'tablet',
            'directions' => 'Gunakan satu tablet sehari sesuai koreksi skenario.',
            'indication_text' => 'Sindrom pusing dalam evaluasi.',
        ];

        $this->actingAs($case['medicalLearner'])
            ->post(route('pharmacy-interventions.responses.store', $intervention), $response)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $requests = MedicationRequest::query()->orderBy('revision_number')->get();
        $replacement = $requests->last();

        $this->assertCount(2, $requests);
        $this->assertNotNull($replacement);
        $this->assertSame(MedicationRequestStatus::Cancelled, $original->refresh()->status);
        $this->assertSame($originalDirections, $original->directions);
        $this->assertSame(1, $original->revision_number);
        $this->assertSame(MedicationRequestStatus::Active, $replacement->status);
        $this->assertSame(2, $replacement->revision_number);
        $this->assertSame($original->getKey(), $replacement->replaces_medication_request_id);
        $this->assertSame($original->source_entry_version_id, $replacement->source_entry_version_id);
        $this->assertSame($original->source_condition_id, $replacement->source_condition_id);
        $this->assertSame($case['medicalAssignment']->getKey(), $replacement->requester_assignment_id);
        $this->assertSame(PharmacyInterventionStatus::Responded, $intervention->refresh()->status);
        $this->assertDatabaseHas('pharmacy_intervention_messages', [
            'pharmacy_intervention_id' => $intervention->getKey(),
            'response_action' => PharmacyResponseAction::Replace->value,
            'replacement_medication_request_id' => $replacement->getKey(),
        ]);

        $this->actingAs($case['pharmacyLearner'])
            ->post(
                route('medication-requests.pharmacy-reviews.store', $replacement),
                $this->reviewPayload(PharmacyReviewOutcome::Accept),
            )
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $this->assertSame(MedicationRequestStatus::Accepted, $replacement->refresh()->status);
        $this->assertSame(PharmacyInterventionStatus::Resolved, $intervention->refresh()->status);
        $this->assertDatabaseCount('pharmacy_reviews', 2);
    }

    public function test_linked_supervisor_final_check_is_required_before_stock_and_closure_release(): void
    {
        $case = $this->preparePharmacyCase();
        $medicationRequest = MedicationRequest::query()->sole();
        $this->acceptCurrentRequest($case, $medicationRequest);
        $stock = MedicationStock::query()->where('lot_number', 'LOT-SIM-A-001')->sole();
        $preparationPayload = $this->dispensePayload($stock);

        $this->actingAs($case['nurse'])
            ->post(route('medication-requests.dispenses.store', $medicationRequest), $preparationPayload)
            ->assertForbidden();
        $this->actingAs($case['pharmacyLearner'])
            ->post(route('medication-requests.dispenses.store', $medicationRequest), $preparationPayload)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));
        $this->actingAs($case['pharmacyLearner'])
            ->post(route('medication-requests.dispenses.store', $medicationRequest), $preparationPayload)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $preparation = MedicationDispensePreparation::query()->sole();
        $finalCheckPayload = $this->finalCheckPayload($preparation);

        $this->assertDatabaseCount('medication_dispenses', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('100.000', $stock->refresh()->quantity_on_hand);
        $this->assertSame(MedicationRequestStatus::Accepted, $medicationRequest->refresh()->status);
        $this->assertSame(EncounterStatus::AwaitingPharmacy, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::Dispensing->value,
            'status' => WorkTaskStatus::Submitted->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacySupervisorAssignment']->getKey(),
            'task_type' => WorkTaskType::SupervisorReview->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->actingAs($case['pharmacySupervisor'])
            ->get(route('work'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.type', WorkTaskType::SupervisorReview->value)
                ->where('tasks.0.actionUrl', route('encounters.pharmacy.show', $case['encounter'])));

        $this->actingAs($case['pharmacyLearner'])
            ->post(route('medication-requests.dispenses.store', $medicationRequest), $finalCheckPayload)
            ->assertSessionHasErrors('workflow');
        $this->actingAs($case['pharmacySupervisor'])
            ->post(route('medication-requests.dispenses.store', $medicationRequest), $finalCheckPayload)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));
        $this->actingAs($case['pharmacySupervisor'])
            ->post(route('medication-requests.dispenses.store', $medicationRequest), $finalCheckPayload)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $dispense = MedicationDispense::query()->sole();
        $reviewAction = MedicationDispensePreparationReview::query()->sole();
        $movement = MedicationStockMovement::query()->sole();

        $this->assertSame(DispensePreparationReviewAction::ApproveSimulation, $reviewAction->action);
        $this->assertSame($preparation->content_hash, $reviewAction->source_content_hash);
        $this->assertSame($preparation->getKey(), $dispense->medication_dispense_preparation_id);
        $this->assertNotSame($dispense->preparer_user_id, $dispense->checker_user_id);
        $this->assertSame(MedicationDispenseOutcome::Complete, $dispense->outcome);
        $this->assertSame('6.000', $dispense->quantity);
        $this->assertSame($dispense->getKey(), $movement->medication_dispense_id);
        $this->assertSame('100.000', $movement->balance_before);
        $this->assertSame('94.000', $movement->balance_after);
        $this->assertSame('94.000', $stock->refresh()->quantity_on_hand);
        $this->assertSame(MedicationRequestStatus::Completed, $medicationRequest->refresh()->status);
        $this->assertSame(EncounterStatus::InConsultation, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::Dispensing->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacySupervisorAssignment']->getKey(),
            'task_type' => WorkTaskType::SupervisorReview->value,
            'status' => WorkTaskStatus::Complete->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['medicalAssignment']->getKey(),
            'task_type' => WorkTaskType::EncounterClosure->value,
            'status' => WorkTaskStatus::Ready->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.medication_dispense_recorded',
            'resource_id' => $dispense->public_id,
        ]);
    }

    public function test_insufficient_stock_rolls_back_dispense_request_status_and_movement_together(): void
    {
        $case = $this->preparePharmacyCase();
        $medicationRequest = MedicationRequest::query()->sole();
        $this->acceptCurrentRequest($case, $medicationRequest);
        $limitedStock = MedicationStock::query()->create([
            'session_id' => $case['encounter']->session_id,
            'authored_medication' => 'Obat Simulasi A',
            'form' => 'Tablet',
            'strength' => '500 mg',
            'lot_number' => 'LOT-SIM-LIMITED',
            'expires_on' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 5,
            'unit' => 'tablet',
            'synthetic_flag' => true,
        ]);

        $this->actingAs($case['pharmacyLearner'])
            ->post(
                route('medication-requests.dispenses.store', $medicationRequest),
                $this->dispensePayload($limitedStock),
            )
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));
        $preparation = MedicationDispensePreparation::query()->sole();

        $this->actingAs($case['pharmacySupervisor'])
            ->post(
                route('medication-requests.dispenses.store', $medicationRequest),
                $this->finalCheckPayload($preparation),
            )
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('medication_dispense_preparation_reviews', 0);
        $this->assertDatabaseCount('medication_dispenses', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame('5.000', $limitedStock->refresh()->quantity_on_hand);
        $this->assertSame(MedicationRequestStatus::Accepted, $medicationRequest->refresh()->status);
        $this->assertSame(EncounterStatus::AwaitingPharmacy, $case['encounter']->refresh()->status);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::Dispensing->value,
            'status' => WorkTaskStatus::Submitted->value,
        ]);
    }

    public function test_change_request_preserves_old_preparation_and_requires_an_attributed_successor(): void
    {
        $case = $this->preparePharmacyCase();
        $medicationRequest = MedicationRequest::query()->sole();
        $this->acceptCurrentRequest($case, $medicationRequest);
        $stock = MedicationStock::query()->where('lot_number', 'LOT-SIM-A-001')->sole();

        $this->actingAs($case['pharmacyLearner'])->post(
            route('medication-requests.dispenses.store', $medicationRequest),
            $this->dispensePayload($stock),
        );
        $first = MedicationDispensePreparation::query()->sole();

        $this->actingAs($case['pharmacySupervisor'])
            ->post(
                route('medication-requests.dispenses.store', $medicationRequest),
                $this->finalCheckPayload($first, DispensePreparationReviewAction::RequestChanges),
            )
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $this->assertDatabaseCount('medication_dispenses', 0);
        $this->assertSame('100.000', $stock->refresh()->quantity_on_hand);
        $this->assertDatabaseHas('work_tasks', [
            'assignment_id' => $case['pharmacyAssignment']->getKey(),
            'task_type' => WorkTaskType::Dispensing->value,
            'status' => WorkTaskStatus::ChangesRequested->value,
        ]);

        $successorPayload = $this->dispensePayload($stock);
        $successorPayload['change_reason'] = 'Catatan etiket dan konseling diperjelas sesuai komentar supervisor.';
        $this->actingAs($case['pharmacyLearner'])
            ->post(route('medication-requests.dispenses.store', $medicationRequest), $successorPayload)
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $second = MedicationDispensePreparation::query()->orderByDesc('version_number')->firstOrFail();
        $this->assertSame(2, $second->version_number);
        $this->assertSame($first->getKey(), $second->supersedes_preparation_id);
        $this->assertSame(
            'Catatan etiket dan konseling diperjelas sesuai komentar supervisor.',
            $second->change_reason,
        );
        $this->assertSame(
            DispensePreparationReviewAction::RequestChanges,
            $first->reviewAction()->sole()->action,
        );

        $this->actingAs($case['pharmacySupervisor'])
            ->post(
                route('medication-requests.dispenses.store', $medicationRequest),
                $this->finalCheckPayload($second),
            )
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        $this->assertDatabaseCount('medication_dispense_preparations', 2);
        $this->assertDatabaseCount('medication_dispense_preparation_reviews', 2);
        $this->assertDatabaseCount('medication_dispenses', 1);
        $this->assertSame('94.000', $stock->refresh()->quantity_on_hand);
    }

    public function test_pharmacy_records_and_stock_ledger_reject_direct_mutation_and_deletion(): void
    {
        $case = $this->preparePharmacyCase();
        $medicationRequest = MedicationRequest::query()->sole();
        $this->actingAs($case['pharmacyLearner'])->post(
            route('medication-requests.pharmacy-reviews.store', $medicationRequest),
            $this->reviewPayload(PharmacyReviewOutcome::ClarificationRequired, finding: true),
        );
        $review = PharmacyReview::query()->sole();
        $intervention = PharmacyIntervention::query()->sole();
        $message = PharmacyInterventionMessage::query()->sole();

        $this->assertDomainFailure(fn () => $review->update(['overall_outcome' => PharmacyReviewOutcome::Accept]), 'review update');
        $review->refresh();
        $this->assertDomainFailure(fn () => $review->delete(), 'review delete');
        $this->assertDomainFailure(fn () => $intervention->update(['question' => 'Mutasi tidak sah']), 'intervention update');
        $intervention->refresh();
        $this->assertDomainFailure(fn () => $intervention->delete(), 'intervention delete');
        $this->assertDomainFailure(fn () => $message->update(['message_text' => 'Mutasi tidak sah']), 'message update');
        $message->refresh();
        $this->assertDomainFailure(fn () => $message->delete(), 'message delete');

        $this->actingAs($case['medicalLearner'])->post(
            route('pharmacy-interventions.responses.store', $intervention),
            $this->responsePayload(PharmacyResponseAction::Explanation),
        );
        $this->actingAs($case['pharmacyLearner'])->post(
            route('medication-requests.pharmacy-reviews.store', $medicationRequest),
            $this->reviewPayload(PharmacyReviewOutcome::Accept),
        );
        $stock = MedicationStock::query()->where('lot_number', 'LOT-SIM-A-001')->sole();
        $this->actingAs($case['pharmacyLearner'])->post(
            route('medication-requests.dispenses.store', $medicationRequest),
            $this->dispensePayload($stock),
        );
        $preparation = MedicationDispensePreparation::query()->sole();
        $this->actingAs($case['pharmacySupervisor'])->post(
            route('medication-requests.dispenses.store', $medicationRequest),
            $this->finalCheckPayload($preparation),
        );
        $preparationReview = MedicationDispensePreparationReview::query()->sole();
        $dispense = MedicationDispense::query()->sole();
        $movement = MedicationStockMovement::query()->sole();

        $this->assertDomainFailure(fn () => $preparation->update(['outcome_reason' => 'Mutasi tidak sah']), 'preparation update');
        $preparation->refresh();
        $this->assertDomainFailure(fn () => $preparation->delete(), 'preparation delete');
        $this->assertDomainFailure(fn () => $preparationReview->update(['comment' => 'Mutasi tidak sah']), 'preparation review update');
        $preparationReview->refresh();
        $this->assertDomainFailure(fn () => $preparationReview->delete(), 'preparation review delete');
        $this->assertDomainFailure(fn () => $dispense->update(['outcome_reason' => 'Mutasi tidak sah']), 'dispense update');
        $dispense->refresh();
        $this->assertDomainFailure(fn () => $dispense->delete(), 'dispense delete');
        $this->assertDomainFailure(fn () => $movement->update(['balance_after' => '99.000']), 'movement update');
        $movement->refresh();
        $this->assertDomainFailure(fn () => $movement->delete(), 'movement delete');
        $stock->refresh();
        $this->assertDomainFailure(fn () => $stock->update(['quantity_on_hand' => 100]), 'stock update');
        $stock->refresh();
        $this->assertDomainFailure(fn () => $stock->delete(), 'stock delete');
    }

    /**
     * @return array{
     *   encounter: Encounter,
     *   nurse: User,
     *   medicalLearner: User,
     *   pharmacyLearner: User,
     *   pharmacySupervisor: User,
     *   medicalAssignment: Assignment,
     *   pharmacyAssignment: Assignment,
     *   pharmacySupervisorAssignment: Assignment
     * }
     */
    private function preparePharmacyCase(): array
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $nursingSupervisor = User::query()->where('email', 'supervisor.keperawatan@example.invalid')->firstOrFail();
        $medicalLearner = User::query()->where('email', 'mahasiswa.kedokteran@example.invalid')->firstOrFail();
        $medicalSupervisor = User::query()->where('email', 'supervisor.kedokteran@example.invalid')->firstOrFail();
        $pharmacyLearner = User::query()->where('email', 'mahasiswa.farmasi@example.invalid')->firstOrFail();
        $pharmacySupervisor = User::query()->where('email', 'supervisor.farmasi@example.invalid')->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $encounter = Encounter::query()->firstOrFail();

        $this->actingAs($registrar)->post(route('appointments.check-in', $encounter->appointment));
        $this->actingAs($nurse)->post(
            route('encounters.nursing-intake.versions.store', $encounter),
            $this->nursingPayload(),
        );
        $nursingVersion = ClinicalEntryVersion::query()->where('schema_version', 'nursing-intake.v1')->sole();
        $this->actingAs($nursingSupervisor)->post(
            route('clinical-versions.review-decisions.store', $nursingVersion),
            $this->reviewDecisionPayload('Handoff disetujui untuk skenario.'),
        );
        $this->actingAs($medicalLearner)->post(
            route('encounters.medical-assessment.versions.store', $encounter),
            $this->medicalPayloadWithRequests(),
        );
        $medicalVersion = ClinicalEntryVersion::query()->where('schema_version', 'medical-assessment.v1')->sole();
        $this->actingAs($medicalSupervisor)->post(
            route('clinical-versions.review-decisions.store', $medicalVersion),
            $this->reviewDecisionPayload('Asesmen, order, dan resep simulasi disetujui.'),
        );
        $serviceRequest = ServiceRequest::query()->sole();
        $this->actingAs($facilitator)->post(
            route('service-requests.results.store', $serviceRequest),
            $this->resultPayload(),
        );
        $result = DiagnosticResult::query()->sole();
        $this->actingAs($medicalLearner)->post(
            route('diagnostic-results.acknowledgements.store', $result),
            $this->acknowledgementPayload(),
        );

        return [
            'encounter' => $encounter->refresh(),
            'nurse' => $nurse,
            'medicalLearner' => $medicalLearner,
            'pharmacyLearner' => $pharmacyLearner,
            'pharmacySupervisor' => $pharmacySupervisor,
            'medicalAssignment' => Assignment::query()->where('user_id', $medicalLearner->getKey())->sole(),
            'pharmacyAssignment' => Assignment::query()->where('user_id', $pharmacyLearner->getKey())->sole(),
            'pharmacySupervisorAssignment' => Assignment::query()->where('user_id', $pharmacySupervisor->getKey())->sole(),
        ];
    }

    /** @param array<string, mixed> $case */
    private function acceptCurrentRequest(array $case, MedicationRequest $request): PharmacyReview
    {
        $this->actingAs($case['pharmacyLearner'])
            ->post(
                route('medication-requests.pharmacy-reviews.store', $request),
                $this->reviewPayload(PharmacyReviewOutcome::Accept),
            )
            ->assertRedirect(route('encounters.pharmacy.show', $case['encounter']));

        return PharmacyReview::query()->where('medication_request_id', $request->getKey())->sole();
    }

    /** @return array<string, mixed> */
    private function reviewPayload(PharmacyReviewOutcome $outcome, bool $finding = false): array
    {
        $clear = PharmacyReviewItemOutcome::Clear->value;
        $findingOutcome = PharmacyReviewItemOutcome::Finding->value;

        return [
            'request_key' => (string) Str::ulid(),
            'overall_outcome' => $outcome->value,
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
                    [
                        'criterion_code' => 'ALLERGY_ADVERSE_REACTION',
                        'outcome' => $finding ? $findingOutcome : $clear,
                        'comment' => $finding ? 'Mohon konfirmasi kembali konteks alergi pada resep simulasi.' : null,
                    ],
                    ['criterion_code' => 'CONTRAINDICATION_INTERACTION', 'outcome' => $clear, 'comment' => null],
                ],
            ],
            'intervention' => $outcome === PharmacyReviewOutcome::Accept ? null : [
                'request_key' => (string) Str::ulid(),
                'issue_category' => 'ALLERGY_CONTEXT',
                'urgency' => 'ROUTINE',
                'question' => 'Mohon jelaskan dasar penggunaan obat dalam konteks alergi yang didokumentasikan.',
                'recommendation' => 'Tinjau sumber alergi dan berikan keputusan prescriber.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function responsePayload(PharmacyResponseAction $action): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'response_action' => $action->value,
            'message_text' => 'Prescriber meninjau sumber alergi dan mencatat keputusan dalam konteks skenario.',
            'replacement' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function dispensePayload(MedicationStock $stock): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'action' => 'PREPARE',
            'outcome' => MedicationDispenseOutcome::Complete->value,
            'quantity' => 6,
            'medication_stock_id' => $stock->getKey(),
            'outcome_reason' => null,
            'preparation_notes' => 'Obat sintetis disiapkan sesuai permintaan yang telah ditelaah.',
            'handoff_recipient' => 'Pasien sintetis',
            'counseling_topics' => ['Cara penggunaan', 'Penyimpanan', 'Kapan kembali dalam skenario'],
            'counseling_acknowledged' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function finalCheckPayload(
        MedicationDispensePreparation $preparation,
        DispensePreparationReviewAction $action = DispensePreparationReviewAction::ApproveSimulation,
    ): array {
        return [
            'request_key' => (string) Str::ulid(),
            'action' => 'FINAL_CHECK',
            'preparation_public_id' => $preparation->public_id,
            'review_action' => $action->value,
            'comment' => $action === DispensePreparationReviewAction::RequestChanges
                ? 'Perjelas catatan etiket dan konseling sebelum pemeriksaan ulang.'
                : 'Penyiapan disetujui terhadap versi dan hash yang tepat.',
            'final_check_confirmed' => $action === DispensePreparationReviewAction::ApproveSimulation,
            'final_check_notes' => 'Identitas, obat, jumlah, etiket, lot, dan versi penyiapan diperiksa.',
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
    private function medicalPayloadWithRequests(): array
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
    private function reviewDecisionPayload(string $comment): array
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
            $this->fail("The protected pharmacy record accepted a forbidden direct mutation: {$label}.");
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
