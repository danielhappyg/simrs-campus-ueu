<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\PharmacyInterventionStatus;
use App\Modules\Clinical\Enums\PharmacyResponseAction;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Clinical\Models\MedicationStockMovement;
use App\Modules\Clinical\Models\PharmacyIntervention;
use App\Modules\Clinical\Models\PharmacyInterventionMessage;
use App\Modules\Clinical\Models\PharmacyReview;
use App\Modules\Clinical\Support\PharmacyReviewDefinition;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PharmacyWorkflowService
{
    public function __construct(
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /** @param array<string, mixed> $payload */
    public function submitReview(
        MedicationRequest $medicationRequest,
        Assignment $reviewerAssignment,
        array $payload,
    ): PharmacyReview {
        return DB::transaction(function () use ($medicationRequest, $reviewerAssignment, $payload): PharmacyReview {
            $requestKey = (string) $payload['request_key'];
            $existing = PharmacyReview::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->medication_request_id !== $medicationRequest->getKey()
                    || $existing->reviewer_assignment_id !== $reviewerAssignment->getKey()) {
                    throw new DomainException('The pharmacy-review request key was already used in another context.');
                }

                return $existing;
            }

            $request = MedicationRequest::query()
                ->with(['encounter.session', 'sourceEntryVersion', 'sourceCondition', 'requesterAssignment'])
                ->whereKey($medicationRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($request->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($request->session_id)->lockForUpdate()->firstOrFail();
            $reviewer = Assignment::query()->active()->whereKey($reviewerAssignment->getKey())->lockForUpdate()->first();

            $this->assertExactAssignment($reviewer, $request, $session, Capability::PharmacyReview);

            if ($encounter->status !== EncounterStatus::AwaitingPharmacy
                || $request->status !== MedicationRequestStatus::Active) {
                throw new DomainException('This medication request is not ready for a pharmacy review.');
            }

            if (PharmacyIntervention::query()
                ->where('medication_request_id', $request->getKey())
                ->where('status', PharmacyInterventionStatus::Open)
                ->exists()) {
                throw new DomainException('The open pharmacy intervention requires a prescriber response before re-review.');
            }

            $outcome = PharmacyReviewOutcome::from((string) $payload['overall_outcome']);
            $domainResults = PharmacyReviewDefinition::normalizeAndValidate($payload, $outcome);
            $latest = PharmacyReview::query()
                ->where('medication_request_id', $request->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();
            $content = [
                'overallOutcome' => $outcome->value,
                'domainResults' => $domainResults,
            ];
            $reviewedAt = CarbonImmutable::now();
            $review = PharmacyReview::query()->create([
                'request_key' => $requestKey,
                'session_id' => $request->session_id,
                'patient_id' => $request->patient_id,
                'encounter_id' => $request->encounter_id,
                'medication_request_id' => $request->getKey(),
                'reviewer_user_id' => $reviewer->user_id,
                'reviewer_assignment_id' => $reviewer->getKey(),
                'version_number' => $latest === null ? 1 : $latest->version_number + 1,
                'overall_outcome' => $outcome,
                'domain_results' => $domainResults,
                'content_hash' => hash('sha256', CanonicalJson::encode($content)),
                'supersedes_review_id' => $latest?->getKey(),
                'reviewed_at' => $reviewedAt,
            ]);

            if ($outcome === PharmacyReviewOutcome::Accept) {
                $request->persistStatus(MedicationRequestStatus::Accepted);
                $this->resolveRespondedInterventions($request, $reviewer);
                $this->setTaskStatus(
                    assignment: $reviewer,
                    encounter: $encounter,
                    taskType: WorkTaskType::PharmacyReview,
                    status: WorkTaskStatus::Complete,
                    description: 'Telaah tiga domain telah diterima oleh penelaah manusia.',
                );
                WorkTask::query()->updateOrCreate(
                    [
                        'assignment_id' => $reviewer->getKey(),
                        'encounter_id' => $encounter->getKey(),
                        'task_type' => WorkTaskType::Dispensing,
                    ],
                    [
                        'session_id' => $session->getKey(),
                        'title' => 'Siapkan dan Serahkan Obat Simulasi',
                        'description' => 'Catat penyiapan, pemeriksaan akhir, jumlah, penyerahan, konseling, dan lot sintetis.',
                        'status' => WorkTaskStatus::Ready,
                        'priority' => 1,
                        'source_program' => Program::Pharmacy,
                        'context' => [
                            'caseLabel' => $encounter->encounter_number,
                            'synthetic' => true,
                            'medicationRequestPublicId' => $request->public_id,
                            'pharmacyReviewPublicId' => $review->public_id,
                            'pharmacyReviewContentHash' => $review->content_hash,
                        ],
                        'available_at' => now(),
                        'completed_at' => null,
                    ],
                );
            } else {
                $interventionPayload = $payload['intervention'] ?? null;

                if (! is_array($interventionPayload)) {
                    throw new DomainException('A non-accepted review requires an attributed intervention.');
                }

                $intervention = $this->openIntervention(
                    request: $request,
                    review: $review,
                    reviewer: $reviewer,
                    outcome: $outcome,
                    payload: $interventionPayload,
                );
                $request->persistStatus($outcome === PharmacyReviewOutcome::RecommendCancel
                    ? MedicationRequestStatus::CancellationRecommended
                    : MedicationRequestStatus::OnHold);
                $this->setTaskStatus(
                    assignment: $reviewer,
                    encounter: $encounter,
                    taskType: WorkTaskType::PharmacyReview,
                    status: WorkTaskStatus::Blocked,
                    description: 'Menunggu tanggapan prescriber terhadap intervensi farmasi yang tidak dapat dihapus.',
                );
                WorkTask::query()->updateOrCreate(
                    [
                        'assignment_id' => $request->requester_assignment_id,
                        'encounter_id' => $encounter->getKey(),
                        'task_type' => WorkTaskType::PrescriptionInterventionResponse,
                    ],
                    [
                        'session_id' => $session->getKey(),
                        'title' => 'Tanggapi Intervensi Farmasi',
                        'description' => 'Berikan penjelasan, buat pengganti, atau batalkan permintaan tanpa mengubah riwayat lama.',
                        'status' => WorkTaskStatus::Ready,
                        'priority' => 1,
                        'source_program' => Program::Pharmacy,
                        'context' => [
                            'caseLabel' => $encounter->encounter_number,
                            'synthetic' => true,
                            'interventionPublicId' => $intervention->public_id,
                            'medicationRequestPublicId' => $request->public_id,
                        ],
                        'available_at' => now(),
                        'completed_at' => null,
                    ],
                );
            }

            $this->auditRecorder->record(
                action: 'clinical.pharmacy_review_recorded',
                resourceType: 'pharmacy_review',
                resourceId: $review->public_id,
                actor: $reviewer->user,
                assignment: $reviewer,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'medication_request_public_id' => $request->public_id,
                    'review_version' => $review->version_number,
                    'overall_outcome' => $outcome->value,
                    'content_hash' => $review->content_hash,
                    'human_authored' => true,
                ],
            );

            return $review->load(['medicationRequest', 'reviewer', 'interventions.messages']);
        });
    }

    /** @param array<string, mixed> $payload */
    public function respondToIntervention(
        PharmacyIntervention $intervention,
        Assignment $prescriberAssignment,
        array $payload,
    ): PharmacyInterventionMessage {
        return DB::transaction(function () use ($intervention, $prescriberAssignment, $payload): PharmacyInterventionMessage {
            $requestKey = (string) $payload['request_key'];
            $existing = PharmacyInterventionMessage::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->pharmacy_intervention_id !== $intervention->getKey()
                    || $existing->author_assignment_id !== $prescriberAssignment->getKey()) {
                    throw new DomainException('The intervention-response request key was already used in another context.');
                }

                return $existing;
            }

            $lockedIntervention = PharmacyIntervention::query()
                ->with(['medicationRequest.encounter.session', 'sourceReview'])
                ->whereKey($intervention->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $request = MedicationRequest::query()
                ->whereKey($lockedIntervention->medication_request_id)
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($request->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($request->session_id)->lockForUpdate()->firstOrFail();
            $prescriber = Assignment::query()->active()->whereKey($prescriberAssignment->getKey())->lockForUpdate()->first();

            $this->assertExactAssignment($prescriber, $request, $session, Capability::PrescriptionWrite);

            if ($prescriber->getKey() !== $request->requester_assignment_id
                || $encounter->status !== EncounterStatus::AwaitingPharmacy
                || $lockedIntervention->status !== PharmacyInterventionStatus::Open) {
                throw new DomainException('Only the exact requesting prescriber can respond to this open intervention.');
            }

            $action = PharmacyResponseAction::from((string) $payload['response_action']);
            $messageText = trim((string) $payload['message_text']);
            $replacement = null;

            if ($action === PharmacyResponseAction::Replace) {
                $replacementPayload = $payload['replacement'] ?? null;

                if (! is_array($replacementPayload)) {
                    throw new DomainException('A replacement response requires a complete successor medication request.');
                }

                $replacement = $this->createReplacementRequest($request, $replacementPayload, $messageText);
                $request->persistStatus(MedicationRequestStatus::Cancelled, $messageText);
                $replacement->persistStatus(MedicationRequestStatus::Active);
                $lockedIntervention->persistStatus(PharmacyInterventionStatus::Responded);
            } elseif ($action === PharmacyResponseAction::Cancel) {
                $request->persistStatus(MedicationRequestStatus::Cancelled, $messageText);
                $lockedIntervention->persistStatus(PharmacyInterventionStatus::Resolved);
            } else {
                $request->persistStatus(MedicationRequestStatus::Active);
                $lockedIntervention->persistStatus(PharmacyInterventionStatus::Responded);
            }

            $message = PharmacyInterventionMessage::query()->create([
                'request_key' => $requestKey,
                'pharmacy_intervention_id' => $lockedIntervention->getKey(),
                'author_user_id' => $prescriber->user_id,
                'author_assignment_id' => $prescriber->getKey(),
                'message_type' => 'PRESCRIBER_RESPONSE',
                'response_action' => $action->value,
                'message_text' => $messageText,
                'replacement_medication_request_id' => $replacement?->getKey(),
                'authored_at' => CarbonImmutable::now(),
            ]);

            $this->setTaskStatus(
                assignment: $prescriber,
                encounter: $encounter,
                taskType: WorkTaskType::PrescriptionInterventionResponse,
                status: WorkTaskStatus::Complete,
                description: 'Tanggapan prescriber tersimpan dengan tindakan dan provenance.',
            );

            $pharmacyTask = WorkTask::query()
                ->where('encounter_id', $encounter->getKey())
                ->where('task_type', WorkTaskType::PharmacyReview)
                ->lockForUpdate()
                ->first();

            if ($pharmacyTask) {
                $hasReviewableRequest = MedicationRequest::query()
                    ->where('encounter_id', $encounter->getKey())
                    ->where('status', MedicationRequestStatus::Active)
                    ->exists();
                $pharmacyTask->fill([
                    'status' => $hasReviewableRequest ? WorkTaskStatus::Ready : WorkTaskStatus::Complete,
                    'description' => $hasReviewableRequest
                        ? 'Tanggapan prescriber tersedia. Telaah ulang permintaan obat saat ini; hasil lama tidak disalin.'
                        : 'Permintaan obat dibatalkan dengan alasan dan tidak memerlukan dispensing.',
                    'available_at' => now(),
                    'completed_at' => $hasReviewableRequest ? null : now(),
                ])->save();
            }

            if ($action === PharmacyResponseAction::Cancel) {
                $this->advanceWhenMedicationWorkComplete($encounter, $request, $prescriber);
            }

            $this->auditRecorder->record(
                action: 'clinical.pharmacy_intervention_responded',
                resourceType: 'pharmacy_intervention',
                resourceId: $lockedIntervention->public_id,
                actor: $prescriber->user,
                assignment: $prescriber,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'response_action' => $action->value,
                    'replacement_medication_request_public_id' => $replacement?->public_id,
                    'prior_medication_request_public_id' => $request->public_id,
                ],
            );

            return $message->load(['intervention', 'author', 'replacementMedicationRequest']);
        });
    }

    /** @param array<string, mixed> $payload */
    public function dispense(
        MedicationRequest $medicationRequest,
        Assignment $pharmacyAssignment,
        array $payload,
    ): MedicationDispense {
        return DB::transaction(function () use ($medicationRequest, $pharmacyAssignment, $payload): MedicationDispense {
            $requestKey = (string) $payload['request_key'];
            $existing = MedicationDispense::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->medication_request_id !== $medicationRequest->getKey()
                    || $existing->preparer_assignment_id !== $pharmacyAssignment->getKey()) {
                    throw new DomainException('The dispense request key was already used in another context.');
                }

                return $existing;
            }

            $request = MedicationRequest::query()
                ->with(['encounter.session'])
                ->whereKey($medicationRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($request->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($request->session_id)->lockForUpdate()->firstOrFail();
            $actor = Assignment::query()->active()->whereKey($pharmacyAssignment->getKey())->lockForUpdate()->first();

            $this->assertExactAssignment($actor, $request, $session, Capability::Dispense);

            if ($encounter->status !== EncounterStatus::AwaitingPharmacy
                || $request->status !== MedicationRequestStatus::Accepted) {
                throw new DomainException('Only a currently accepted medication request can be dispensed.');
            }

            $review = PharmacyReview::query()
                ->where('medication_request_id', $request->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if (! $review || $review->overall_outcome !== PharmacyReviewOutcome::Accept) {
                throw new DomainException('Dispensing requires the current human-authored pharmacy review to be accepted.');
            }

            if ($this->unresolvedInterventions($request)->isNotEmpty()) {
                throw new DomainException('Dispensing is blocked while a pharmacy intervention remains unresolved.');
            }

            $outcome = MedicationDispenseOutcome::from((string) $payload['outcome']);
            $quantity = number_format((float) $payload['quantity'], 3, '.', '');
            $requestedQuantity = (int) round(((float) $request->quantity_value) * 1000);
            $dispensedQuantity = (int) round(((float) $quantity) * 1000);
            $reason = $this->nullableText($payload['outcome_reason'] ?? null);
            $stock = null;

            if ($outcome === MedicationDispenseOutcome::Complete
                && ($dispensedQuantity <= 0 || $dispensedQuantity !== $requestedQuantity)) {
                throw new DomainException('A complete dispense must equal the current requested quantity.');
            }

            if ($outcome === MedicationDispenseOutcome::Partial
                && ($dispensedQuantity <= 0 || $dispensedQuantity >= $requestedQuantity || $reason === null)) {
                throw new DomainException('A partial dispense requires a positive smaller quantity and a reason.');
            }

            if ($outcome === MedicationDispenseOutcome::NotDispensed
                && ($dispensedQuantity !== 0 || $reason === null)) {
                throw new DomainException('A not-dispensed outcome requires zero quantity and a reason.');
            }

            if ($outcome !== MedicationDispenseOutcome::NotDispensed) {
                $stockId = $payload['medication_stock_id'] ?? null;
                $stock = MedicationStock::query()
                    ->whereKey($stockId)
                    ->lockForUpdate()
                    ->first();

                if (! $stock
                    || $stock->session_id !== $request->session_id
                    || $stock->authored_medication !== $request->authored_medication
                    || $stock->unit !== $request->quantity_unit
                    || $stock->expires_on->isPast()) {
                    throw new DomainException('A matching, unexpired synthetic stock lot is required for this dispense.');
                }
            }

            $counselingTopics = $this->normalizeTextList($payload['counseling_topics'] ?? []);

            if ($outcome !== MedicationDispenseOutcome::NotDispensed
                && ($counselingTopics === [] || ! (bool) ($payload['counseling_acknowledged'] ?? false))) {
                throw new DomainException('A completed or partial handoff requires counseling topics and acknowledgement.');
            }

            $now = CarbonImmutable::now();
            $content = [
                'synthetic' => true,
                'preparationNotes' => $this->nullableText($payload['preparation_notes'] ?? null),
                'finalCheckConfirmed' => (bool) ($payload['final_check_confirmed'] ?? false),
                'finalCheckNotes' => $this->nullableText($payload['final_check_notes'] ?? null),
                'sameActorCheckPermittedByScenario' => true,
                'handoffRecipient' => $this->nullableText($payload['handoff_recipient'] ?? null),
                'counselingTopics' => $counselingTopics,
                'counselingAcknowledged' => (bool) ($payload['counseling_acknowledged'] ?? false),
                'lotNumber' => $stock?->lot_number,
                'expiresOn' => $stock?->expires_on->toDateString(),
            ];

            if (! $content['finalCheckConfirmed']) {
                throw new DomainException('The pharmacy actor must record an explicit final check before saving the outcome.');
            }

            $dispense = MedicationDispense::query()->create([
                'request_key' => $requestKey,
                'session_id' => $request->session_id,
                'patient_id' => $request->patient_id,
                'encounter_id' => $request->encounter_id,
                'medication_request_id' => $request->getKey(),
                'pharmacy_review_id' => $review->getKey(),
                'medication_stock_id' => $stock?->getKey(),
                'outcome' => $outcome,
                'quantity' => $quantity,
                'unit' => $request->quantity_unit,
                'outcome_reason' => $reason,
                'content' => $content,
                'content_hash' => hash('sha256', CanonicalJson::encode($content)),
                'preparer_user_id' => $actor->user_id,
                'preparer_assignment_id' => $actor->getKey(),
                'checker_user_id' => $actor->user_id,
                'checker_assignment_id' => $actor->getKey(),
                'prepared_at' => $now,
                'checked_at' => $now,
                'handed_over_at' => $outcome === MedicationDispenseOutcome::NotDispensed ? null : $now,
            ]);

            if ($stock) {
                $balances = $stock->decrementForDispense($quantity);
                MedicationStockMovement::query()->create([
                    'session_id' => $session->getKey(),
                    'medication_stock_id' => $stock->getKey(),
                    'medication_dispense_id' => $dispense->getKey(),
                    'direction' => 'OUT',
                    'quantity' => $quantity,
                    'balance_before' => $balances['before'],
                    'balance_after' => $balances['after'],
                    'actor_user_id' => $actor->user_id,
                    'actor_assignment_id' => $actor->getKey(),
                    'occurred_at' => $now,
                ]);
            }

            $request->persistStatus(match ($outcome) {
                MedicationDispenseOutcome::Complete => MedicationRequestStatus::Completed,
                MedicationDispenseOutcome::Partial => MedicationRequestStatus::Partial,
                MedicationDispenseOutcome::NotDispensed => MedicationRequestStatus::NotDispensed,
            });
            $this->setTaskStatus(
                assignment: $actor,
                encounter: $encounter,
                taskType: WorkTaskType::Dispensing,
                status: WorkTaskStatus::Complete,
                description: 'Outcome dispensing dan pergerakan stok sintetis tersimpan dalam satu transaksi.',
            );
            $this->advanceWhenMedicationWorkComplete($encounter, $request, $actor);

            $this->auditRecorder->record(
                action: 'clinical.medication_dispense_recorded',
                resourceType: 'medication_dispense',
                resourceId: $dispense->public_id,
                actor: $actor->user,
                assignment: $actor,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'medication_request_public_id' => $request->public_id,
                    'outcome' => $outcome->value,
                    'quantity' => $quantity,
                    'unit' => $request->quantity_unit,
                    'stock_movement_recorded' => $stock !== null,
                    'content_hash' => $dispense->content_hash,
                ],
            );

            return $dispense->load(['medicationRequest', 'pharmacyReview', 'medicationStock', 'stockMovement']);
        });
    }

    /** @param array<string, mixed> $payload */
    private function openIntervention(
        MedicationRequest $request,
        PharmacyReview $review,
        Assignment $reviewer,
        PharmacyReviewOutcome $outcome,
        array $payload,
    ): PharmacyIntervention {
        $requestKey = (string) ($payload['request_key'] ?? '');
        $category = trim((string) ($payload['issue_category'] ?? ''));
        $urgency = trim((string) ($payload['urgency'] ?? ''));
        $question = trim((string) ($payload['question'] ?? ''));
        $recommendation = $this->nullableText($payload['recommendation'] ?? null);

        if (! Str::isUlid($requestKey)
            || $category === ''
            || ! in_array($urgency, ['ROUTINE', 'PRIORITY_SIMULATION'], true)
            || $question === '') {
            throw new DomainException('The pharmacy intervention requires an idempotency key, category, urgency, and question.');
        }

        $intervention = PharmacyIntervention::query()->create([
            'request_key' => $requestKey,
            'session_id' => $request->session_id,
            'patient_id' => $request->patient_id,
            'encounter_id' => $request->encounter_id,
            'medication_request_id' => $request->getKey(),
            'source_pharmacy_review_id' => $review->getKey(),
            'opened_by_user_id' => $reviewer->user_id,
            'opened_by_assignment_id' => $reviewer->getKey(),
            'status' => PharmacyInterventionStatus::Open,
            'issue_category' => $category,
            'urgency' => $urgency,
            'question' => $question,
            'recommendation' => $recommendation,
            'opened_at' => CarbonImmutable::now(),
        ]);

        PharmacyInterventionMessage::query()->create([
            'request_key' => (string) Str::ulid(),
            'pharmacy_intervention_id' => $intervention->getKey(),
            'author_user_id' => $reviewer->user_id,
            'author_assignment_id' => $reviewer->getKey(),
            'message_type' => 'PHARMACY_QUERY',
            'response_action' => null,
            'message_text' => $question.($recommendation ? "\nRekomendasi: {$recommendation}" : ''),
            'replacement_medication_request_id' => null,
            'authored_at' => CarbonImmutable::now(),
        ]);

        return $intervention;
    }

    /** @param array<string, mixed> $payload */
    private function createReplacementRequest(
        MedicationRequest $request,
        array $payload,
        string $reason,
    ): MedicationRequest {
        $requiredText = [
            'authored_medication',
            'dose_unit',
            'route',
            'frequency',
            'duration',
            'quantity_unit',
            'directions',
        ];

        foreach ($requiredText as $field) {
            if (blank($payload[$field] ?? null)) {
                throw new DomainException('A replacement medication request is incomplete.');
            }
        }

        if ((float) ($payload['dose_value'] ?? 0) <= 0 || (float) ($payload['quantity_value'] ?? 0) <= 0) {
            throw new DomainException('A replacement medication request requires positive dose and quantity values.');
        }

        $nextSequence = (int) MedicationRequest::query()
            ->where('source_entry_version_id', $request->source_entry_version_id)
            ->max('sequence_number') + 1;

        return MedicationRequest::query()->create([
            'session_id' => $request->session_id,
            'patient_id' => $request->patient_id,
            'encounter_id' => $request->encounter_id,
            'source_entry_version_id' => $request->source_entry_version_id,
            'source_condition_id' => $request->source_condition_id,
            'replaces_medication_request_id' => $request->getKey(),
            'requester_user_id' => $request->requester_user_id,
            'requester_assignment_id' => $request->requester_assignment_id,
            'sequence_number' => $nextSequence,
            'revision_number' => $request->revision_number + 1,
            'authored_medication' => trim((string) $payload['authored_medication']),
            'form' => $this->nullableText($payload['form'] ?? null),
            'strength' => $this->nullableText($payload['strength'] ?? null),
            'dose_value' => $payload['dose_value'],
            'dose_unit' => trim((string) $payload['dose_unit']),
            'route' => trim((string) $payload['route']),
            'frequency' => trim((string) $payload['frequency']),
            'duration' => trim((string) $payload['duration']),
            'quantity_value' => $payload['quantity_value'],
            'quantity_unit' => trim((string) $payload['quantity_unit']),
            'directions' => trim((string) $payload['directions']),
            'indication_text' => $this->nullableText($payload['indication_text'] ?? null),
            'replacement_reason' => $reason,
            'cancellation_reason' => null,
            'status' => MedicationRequestStatus::Draft,
            'authored_at' => CarbonImmutable::now(),
        ]);
    }

    private function resolveRespondedInterventions(MedicationRequest $request, Assignment $reviewer): void
    {
        foreach ($this->unresolvedInterventions($request) as $intervention) {
            if ($intervention->status !== PharmacyInterventionStatus::Responded) {
                throw new DomainException('An open intervention cannot be resolved by accepting a pharmacy review.');
            }

            PharmacyInterventionMessage::query()->create([
                'request_key' => (string) Str::ulid(),
                'pharmacy_intervention_id' => $intervention->getKey(),
                'author_user_id' => $reviewer->user_id,
                'author_assignment_id' => $reviewer->getKey(),
                'message_type' => 'PHARMACY_RESOLUTION',
                'response_action' => null,
                'message_text' => 'Intervensi ditutup setelah telaah ulang eksplisit terhadap permintaan obat saat ini.',
                'replacement_medication_request_id' => $request->replaces_medication_request_id === null
                    ? null
                    : $request->getKey(),
                'authored_at' => CarbonImmutable::now(),
            ]);
            $intervention->persistStatus(PharmacyInterventionStatus::Resolved);
        }
    }

    /** @return Collection<int, PharmacyIntervention> */
    private function unresolvedInterventions(MedicationRequest $request): Collection
    {
        $requestIds = [];
        $cursor = $request;

        while (true) {
            $requestIds[] = $cursor->getKey();

            if ($cursor->replaces_medication_request_id === null) {
                break;
            }

            $parent = MedicationRequest::query()->find($cursor->replaces_medication_request_id);

            if (! $parent) {
                break;
            }

            $cursor = $parent;
        }

        return PharmacyIntervention::query()
            ->whereIn('medication_request_id', $requestIds)
            ->whereIn('status', [PharmacyInterventionStatus::Open, PharmacyInterventionStatus::Responded])
            ->lockForUpdate()
            ->get();
    }

    private function assertExactAssignment(
        ?Assignment $assignment,
        MedicationRequest $request,
        SimulationSession $session,
        Capability $capability,
    ): void {
        if (! $assignment
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $assignment->session_id !== $session->getKey()
            || $assignment->patient_id !== $request->patient_id
            || $assignment->encounter_id !== $request->encounter_id
            || ! $assignment->hasCapability($capability)) {
            throw new DomainException('The active assignment cannot perform this pharmacy action in the exact case context.');
        }
    }

    private function setTaskStatus(
        Assignment $assignment,
        Encounter $encounter,
        WorkTaskType $taskType,
        WorkTaskStatus $status,
        string $description,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $assignment->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', $taskType)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException("The {$taskType->value} workflow task is missing.");
        }

        $task->fill([
            'status' => $status,
            'description' => $description,
            'available_at' => now(),
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
        ])->save();
    }

    private function advanceWhenMedicationWorkComplete(
        Encounter $encounter,
        MedicationRequest $request,
        Assignment $actor,
    ): void {
        $hasOpenMedicationWork = MedicationRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('status', [
                MedicationRequestStatus::Draft,
                MedicationRequestStatus::Active,
                MedicationRequestStatus::OnHold,
                MedicationRequestStatus::Accepted,
                MedicationRequestStatus::CancellationRecommended,
            ])
            ->exists();

        if ($hasOpenMedicationWork || $encounter->status !== EncounterStatus::AwaitingPharmacy) {
            return;
        }

        $encounter = $this->encounterTransitionService->transition(
            $encounter,
            EncounterStatus::InConsultation,
            $actor,
            'pharmacy_work_completed',
        );
        WorkTask::query()->updateOrCreate(
            [
                'assignment_id' => $request->requester_assignment_id,
                'encounter_id' => $encounter->getKey(),
                'task_type' => WorkTaskType::EncounterClosure,
            ],
            [
                'session_id' => $encounter->session_id,
                'title' => 'Ajukan Penutupan Encounter',
                'description' => 'Tinjau hasil, outcome obat, follow-up, edukasi, disposisi, dan ringkasan sebelum penutupan.',
                'status' => WorkTaskStatus::Ready,
                'priority' => 1,
                'source_program' => Program::Pharmacy,
                'context' => [
                    'caseLabel' => $encounter->encounter_number,
                    'synthetic' => true,
                ],
                'available_at' => now(),
                'completed_at' => null,
            ],
        );
    }

    /** @return list<string> */
    private function normalizeTextList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();

        return array_values($normalized);
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
