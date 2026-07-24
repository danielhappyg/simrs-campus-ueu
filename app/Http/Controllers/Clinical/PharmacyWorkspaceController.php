<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\DispensePreparationReviewAction;
use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use App\Modules\Clinical\Enums\PharmacyResponseAction;
use App\Modules\Clinical\Enums\PharmacyReviewItemOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationDispensePreparation;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Clinical\Models\PharmacyIntervention;
use App\Modules\Clinical\Models\PharmacyInterventionMessage;
use App\Modules\Clinical\Models\PharmacyReview;
use App\Modules\Clinical\Services\ApprovedAllergyAssessmentResolver;
use App\Modules\Clinical\Support\PharmacyReviewDefinition;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PharmacyWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly AuditRecorder $auditRecorder,
        private readonly ApprovedAllergyAssessmentResolver $allergyResolver,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['patient.identifiers', 'session.scenario', 'location']);
        $assignment = $this->assignmentResolver->forEncounterAny($user, $encounter, [
            Capability::PharmacyReview,
            Capability::Dispense,
            Capability::PrescriptionWrite,
            Capability::SupervisionReview,
        ]);
        $medicationRequests = MedicationRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with([
                'sourceEntryVersion',
                'sourceCondition',
                'requester',
                'replacesRequest',
                'replacementRequests',
                'pharmacyReviews' => fn ($query) => $query
                    ->with(['reviewer'])
                    ->orderByDesc('version_number'),
                'pharmacyInterventions' => fn ($query) => $query
                    ->with([
                        'openedBy',
                        'messages' => fn ($messageQuery) => $messageQuery
                            ->with(['author', 'replacementMedicationRequest'])
                            ->orderBy('authored_at'),
                    ])
                    ->orderBy('opened_at'),
                'dispensePreparations' => fn ($query) => $query
                    ->with(['preparer', 'preparerAssignment', 'reviewAction.checker'])
                    ->orderByDesc('version_number'),
                'dispenses' => fn ($query) => $query
                    ->with(['preparation', 'preparer', 'checker', 'medicationStock', 'stockMovement'])
                    ->orderByDesc('created_at'),
            ])
            ->orderBy('revision_number')
            ->orderBy('sequence_number')
            ->get();
        $stocks = MedicationStock::query()
            ->where('session_id', $encounter->session_id)
            ->where('synthetic_flag', true)
            ->where('quantity_on_hand', '>', 0)
            ->orderBy('expires_on')
            ->get();
        $allergy = $this->allergyResolver->forEncounter($encounter);
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $canReview = $assignment->hasCapability(Capability::PharmacyReview);
        $canRespond = $assignment->hasCapability(Capability::PrescriptionWrite);
        $canDispense = $assignment->hasCapability(Capability::Dispense);
        $canFinalCheck = $assignment->hasCapability(Capability::SupervisionReview);

        $this->auditRecorder->record(
            action: 'clinical.pharmacy_workspace_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            request: $request,
        );

        return Inertia::render('clinical/pharmacy', [
            'encounter' => [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'status' => [
                    'code' => $encounter->status->value,
                    'label' => $encounter->status->label(),
                ],
                'serviceType' => $encounter->service_type_display,
                'location' => $encounter->location->name,
                'periodStart' => $encounter->period_start?->toIso8601String(),
                'environmentMode' => $encounter->environment_mode->value,
            ],
            'patient' => [
                'publicId' => $encounter->patient->public_id,
                'fullName' => $encounter->patient->full_name,
                'mrn' => $mrn?->value,
                'birthDate' => $encounter->patient->birth_date->toDateString(),
                'administrativeSex' => $encounter->patient->administrative_sex->label(),
                'allergyStatus' => $this->allergyResolver->label(
                    $allergy,
                    'Belum ada asesmen alergi yang disetujui',
                ),
                'synthetic' => true,
            ],
            'session' => [
                'code' => $encounter->session->code,
                'scenarioTitle' => $encounter->session->scenario->title,
            ],
            'assignment' => [
                'publicId' => $assignment->public_id,
                'program' => $assignment->program->label(),
                'role' => $assignment->application_role->label(),
                'canReview' => $canReview,
                'canRespond' => $canRespond,
                'canDispense' => $canDispense,
                'canFinalCheck' => $canFinalCheck,
            ],
            'allergySource' => $allergy ? [
                'state' => $allergy->assessment_state->value,
                'label' => $allergy->assessment_state->label(),
                'details' => $allergy->details,
                'sourceVersionPublicId' => $allergy->sourceEntryVersion->public_id,
                'sourceContentHash' => $allergy->sourceEntryVersion->content_hash,
                'assessedAt' => $allergy->assessed_at->toIso8601String(),
            ] : null,
            'reviewDefinition' => [
                'criteriaVersion' => 'pharmacy-review.v1',
                'humanAuthored' => true,
                'criteria' => PharmacyReviewDefinition::criteria(),
                'itemOutcomes' => array_map(fn (PharmacyReviewItemOutcome $outcome): array => [
                    'code' => $outcome->value,
                    'label' => $outcome->label(),
                ], PharmacyReviewItemOutcome::cases()),
                'overallOutcomes' => array_map(fn (PharmacyReviewOutcome $outcome): array => [
                    'code' => $outcome->value,
                    'label' => $outcome->label(),
                ], PharmacyReviewOutcome::cases()),
            ],
            'medicationRequests' => $medicationRequests->map(function (MedicationRequest $medicationRequest) use ($assignment, $canReview, $canRespond, $canDispense, $canFinalCheck): array {
                /** @var PharmacyReview|null $latestReview */
                $latestReview = $medicationRequest->pharmacyReviews->first();
                /** @var MedicationDispensePreparation|null $latestPreparation */
                $latestPreparation = $medicationRequest->dispensePreparations->first();
                /** @var MedicationDispense|null $dispense */
                $dispense = $medicationRequest->dispenses->first();
                $latestPreparationDecision = $latestPreparation?->reviewAction?->action;

                return [
                    'publicId' => $medicationRequest->public_id,
                    'sequenceNumber' => $medicationRequest->sequence_number,
                    'revisionNumber' => $medicationRequest->revision_number,
                    'replacesPublicId' => $medicationRequest->replacesRequest?->public_id,
                    'replacementPublicId' => $medicationRequest->replacementRequests->first()?->public_id,
                    'replacementReason' => $medicationRequest->replacement_reason,
                    'cancellationReason' => $medicationRequest->cancellation_reason,
                    'authoredMedication' => $medicationRequest->authored_medication,
                    'form' => $medicationRequest->form,
                    'strength' => $medicationRequest->strength,
                    'doseValue' => $medicationRequest->dose_value,
                    'doseUnit' => $medicationRequest->dose_unit,
                    'route' => $medicationRequest->route,
                    'frequency' => $medicationRequest->frequency,
                    'duration' => $medicationRequest->duration,
                    'quantityValue' => $medicationRequest->quantity_value,
                    'quantityUnit' => $medicationRequest->quantity_unit,
                    'directions' => $medicationRequest->directions,
                    'indicationText' => $medicationRequest->indication_text,
                    'status' => $medicationRequest->status->value,
                    'authoredAt' => $medicationRequest->authored_at->toIso8601String(),
                    'requester' => $medicationRequest->requester->name,
                    'source' => [
                        'medicalVersionPublicId' => $medicationRequest->sourceEntryVersion->public_id,
                        'medicalVersionNumber' => $medicationRequest->sourceEntryVersion->version_number,
                        'medicalContentHash' => $medicationRequest->sourceEntryVersion->content_hash,
                        'diagnosis' => $medicationRequest->sourceCondition?->authored_text,
                    ],
                    'reviews' => $medicationRequest->pharmacyReviews->map(fn (PharmacyReview $review): array => [
                        'publicId' => $review->public_id,
                        'versionNumber' => $review->version_number,
                        'overallOutcome' => [
                            'code' => $review->overall_outcome->value,
                            'label' => $review->overall_outcome->label(),
                        ],
                        'domainResults' => $review->domain_results,
                        'contentHash' => $review->content_hash,
                        'reviewer' => $review->reviewer->name,
                        'reviewedAt' => $review->reviewed_at->toIso8601String(),
                    ])->values()->all(),
                    'interventions' => $medicationRequest->pharmacyInterventions->map(fn (PharmacyIntervention $intervention): array => [
                        'publicId' => $intervention->public_id,
                        'status' => $intervention->status->value,
                        'issueCategory' => $intervention->issue_category,
                        'urgency' => $intervention->urgency,
                        'question' => $intervention->question,
                        'recommendation' => $intervention->recommendation,
                        'openedBy' => $intervention->openedBy->name,
                        'openedAt' => $intervention->opened_at->toIso8601String(),
                        'messages' => $intervention->messages->map(fn (PharmacyInterventionMessage $message): array => [
                            'publicId' => $message->public_id,
                            'messageType' => $message->message_type,
                            'responseAction' => $message->response_action,
                            'messageText' => $message->message_text,
                            'author' => $message->author->name,
                            'replacementPublicId' => $message->replacementMedicationRequest?->public_id,
                            'authoredAt' => $message->authored_at->toIso8601String(),
                        ])->values()->all(),
                        'response' => [
                            'allowed' => $canRespond
                                && $assignment->getKey() === $medicationRequest->requester_assignment_id
                                && $intervention->status->value === 'OPEN',
                            'requestKey' => (string) Str::ulid(),
                            'url' => route('pharmacy-interventions.responses.store', $intervention),
                        ],
                    ])->values()->all(),
                    'reviewAction' => [
                        'allowed' => $canReview && $medicationRequest->status->value === 'ACTIVE',
                        'requestKey' => (string) Str::ulid(),
                        'interventionRequestKey' => (string) Str::ulid(),
                        'url' => route('medication-requests.pharmacy-reviews.store', $medicationRequest),
                    ],
                    'dispenseAction' => [
                        'allowed' => $canDispense
                            && $medicationRequest->status->value === 'ACCEPTED'
                            && $latestReview?->overall_outcome === PharmacyReviewOutcome::Accept
                            && $dispense === null
                            && ($latestPreparation === null
                                || $latestPreparationDecision === DispensePreparationReviewAction::RequestChanges),
                        'requestKey' => (string) Str::ulid(),
                        'url' => route('medication-requests.dispenses.store', $medicationRequest),
                    ],
                    'dispensePreparations' => $medicationRequest->dispensePreparations
                        ->map(fn (MedicationDispensePreparation $preparation): array => [
                            'publicId' => $preparation->public_id,
                            'versionNumber' => $preparation->version_number,
                            'outcome' => [
                                'code' => $preparation->outcome->value,
                                'label' => $preparation->outcome->label(),
                            ],
                            'quantity' => $preparation->quantity,
                            'unit' => $preparation->unit,
                            'outcomeReason' => $preparation->outcome_reason,
                            'content' => $preparation->content,
                            'contentHash' => $preparation->content_hash,
                            'changeReason' => $preparation->change_reason,
                            'preparer' => $preparation->preparer->name,
                            'preparedAt' => $preparation->prepared_at->toIso8601String(),
                            'review' => $preparation->reviewAction ? [
                                'action' => [
                                    'code' => $preparation->reviewAction->action->value,
                                    'label' => $preparation->reviewAction->action->label(),
                                ],
                                'comment' => $preparation->reviewAction->comment,
                                'checker' => $preparation->reviewAction->checker->name,
                                'sourceContentHash' => $preparation->reviewAction->source_content_hash,
                                'reviewedAt' => $preparation->reviewAction->reviewed_at->toIso8601String(),
                            ] : null,
                        ])
                        ->values()
                        ->all(),
                    'finalCheckAction' => [
                        'allowed' => $canFinalCheck
                            && $latestPreparation !== null
                            && $latestPreparation->reviewAction === null
                            && $latestPreparation->preparerAssignment?->supervisor_assignment_id === $assignment->getKey()
                            && $latestPreparation->preparer_user_id !== $assignment->user_id,
                        'requestKey' => (string) Str::ulid(),
                        'preparationPublicId' => $latestPreparation?->public_id,
                        'url' => route('medication-requests.dispenses.store', $medicationRequest),
                    ],
                    'dispense' => $dispense ? [
                        'publicId' => $dispense->public_id,
                        'outcome' => [
                            'code' => $dispense->outcome->value,
                            'label' => $dispense->outcome->label(),
                        ],
                        'quantity' => $dispense->quantity,
                        'unit' => $dispense->unit,
                        'outcomeReason' => $dispense->outcome_reason,
                        'content' => $dispense->content,
                        'contentHash' => $dispense->content_hash,
                        'preparer' => $dispense->preparer->name,
                        'checker' => $dispense->checker->name,
                        'preparedAt' => $dispense->prepared_at->toIso8601String(),
                        'checkedAt' => $dispense->checked_at->toIso8601String(),
                        'preparationPublicId' => $dispense->preparation?->public_id,
                        'stockMovement' => $dispense->stockMovement ? [
                            'publicId' => $dispense->stockMovement->public_id,
                            'quantity' => $dispense->stockMovement->quantity,
                            'balanceBefore' => $dispense->stockMovement->balance_before,
                            'balanceAfter' => $dispense->stockMovement->balance_after,
                        ] : null,
                    ] : null,
                ];
            })->values()->all(),
            'stocks' => $stocks->map(fn (MedicationStock $stock): array => [
                'id' => $stock->getKey(),
                'publicId' => $stock->public_id,
                'authoredMedication' => $stock->authored_medication,
                'form' => $stock->form,
                'strength' => $stock->strength,
                'lotNumber' => $stock->lot_number,
                'expiresOn' => $stock->expires_on->toDateString(),
                'quantityOnHand' => $stock->quantity_on_hand,
                'unit' => $stock->unit,
                'synthetic' => true,
            ])->values()->all(),
            'formOptions' => [
                'responseActions' => array_map(fn (PharmacyResponseAction $action): array => [
                    'code' => $action->value,
                    'label' => $action->label(),
                ], PharmacyResponseAction::cases()),
                'dispenseOutcomes' => array_map(fn (MedicationDispenseOutcome $outcome): array => [
                    'code' => $outcome->value,
                    'label' => $outcome->label(),
                ], MedicationDispenseOutcome::cases()),
            ],
            'urls' => [
                'encounter' => route('encounters.show', $encounter),
                'workQueue' => route('work'),
            ],
        ]);
    }
}
