<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Support\NursingIntakeDefinition;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class NursingIntakeWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['patient.identifiers', 'session.scenario', 'location']);
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::IntakeWrite);
        $entry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('document_type', ClinicalDocumentType::NursingIntake)
            ->with([
                'versions' => fn ($query) => $query
                    ->with(['author', 'authorAssignment', 'allergyAssessment', 'observations', 'reviewActions.actorAssignment.user'])
                    ->orderByDesc('version_number')
                    ->limit(20),
            ])
            ->first();
        /** @var ClinicalEntryVersion|null $latest */
        $latest = $entry?->versions->first();
        $task = $encounter->workTasks()
            ->where('assignment_id', $assignment->getKey())
            ->where('task_type', WorkTaskType::NursingIntake)
            ->first();
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $allergyStatus = $latest?->allergyAssessment?->assessment_state->label() ?? 'Belum dinilai';

        $this->auditRecorder->record(
            action: 'clinical.nursing_intake_workspace_viewed',
            resourceType: 'clinical_entry',
            resourceId: $entry?->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            request: $request,
        );

        return Inertia::render('clinical/nursing-intake', [
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
                'allergyStatus' => $allergyStatus,
                'synthetic' => true,
            ],
            'session' => [
                'publicId' => $encounter->session->public_id,
                'code' => $encounter->session->code,
                'scenarioTitle' => $encounter->session->scenario->title,
            ],
            'assignment' => [
                'publicId' => $assignment->public_id,
                'program' => $assignment->program->label(),
                'role' => $assignment->application_role->label(),
            ],
            'task' => $task ? [
                'publicId' => $task->public_id,
                'status' => [
                    'code' => $task->status->value,
                    'label' => $task->status->label(),
                ],
            ] : null,
            'document' => [
                'publicId' => $entry?->public_id,
                'type' => ClinicalDocumentType::NursingIntake->value,
                'label' => ClinicalDocumentType::NursingIntake->label(),
                'schemaVersion' => ClinicalDocumentType::NursingIntake->schemaVersion(),
                'lifecycleStatus' => $entry ? [
                    'code' => $entry->lifecycle_status->value,
                    'label' => $entry->lifecycle_status->label(),
                ] : null,
                'latestVersion' => $latest ? $this->versionPayload($latest) : null,
                'history' => $entry?->versions
                    ->map(fn (ClinicalEntryVersion $version): array => $this->versionPayload($version))
                    ->values()
                    ->all() ?? [],
                'canEdit' => ! $latest || in_array($latest->status, [ClinicalEntryStatus::Draft, ClinicalEntryStatus::ChangesRequested], true),
                'requiresChangeReason' => $latest?->status === ClinicalEntryStatus::ChangesRequested,
            ],
            'formOptions' => [
                'requestKey' => (string) Str::ulid(),
                'defaultOccurrenceAt' => now('Asia/Jakarta')->format('Y-m-d\TH:i'),
                'intents' => collect(ClinicalSaveIntent::cases())->map(fn (ClinicalSaveIntent $intent): string => $intent->value)->all(),
                'allergyStates' => collect(AllergyAssessmentState::cases())->map(fn (AllergyAssessmentState $state): array => [
                    'code' => $state->value,
                    'label' => $state->label(),
                ])->all(),
                'medicationStates' => collect(CurrentMedicationState::cases())->map(fn (CurrentMedicationState $state): array => [
                    'code' => $state->value,
                    'label' => $state->label(),
                ])->all(),
                'safetyDecisions' => collect(IntakeSafetyDecision::cases())->map(fn (IntakeSafetyDecision $decision): array => [
                    'code' => $decision->value,
                    'label' => $decision->label(),
                ])->all(),
                'vitals' => NursingIntakeDefinition::vitalDefinitions(),
                'safetyQuestions' => [
                    [
                        'code' => 'SUPERVISOR_CONCERN',
                        'label' => 'Apakah terdapat kekhawatiran keselamatan yang perlu ditinjau supervisor?',
                    ],
                ],
            ],
            'urls' => [
                'store' => route('encounters.nursing-intake.versions.store', $encounter),
                'encounter' => route('encounters.show', $encounter),
                'workQueue' => route('work'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function versionPayload(ClinicalEntryVersion $version): array
    {
        return [
            'publicId' => $version->public_id,
            'versionNumber' => $version->version_number,
            'schemaVersion' => $version->schema_version,
            'content' => $version->content,
            'contentHash' => $version->content_hash,
            'status' => [
                'code' => $version->status->value,
                'label' => $version->status->label(),
            ],
            'author' => $version->author->name,
            'authorRole' => $version->authorAssignment->application_role->label(),
            'clinicalOccurrenceAt' => $version->clinical_occurrence_at->toIso8601String(),
            'recordedAt' => $version->recorded_at->toIso8601String(),
            'changeReason' => $version->change_reason,
            'reviews' => $version->reviewActions->map(fn ($review): array => [
                'publicId' => $review->public_id,
                'action' => [
                    'code' => $review->action->value,
                    'label' => $review->action->label(),
                ],
                'actor' => $review->actorAssignment->user->name,
                'comment' => $review->comment,
                'findings' => $review->findings,
                'occurredAt' => $review->occurred_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
