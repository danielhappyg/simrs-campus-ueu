<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MedicalAssessmentWorkspaceController extends Controller
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
        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::MedicalAssessmentWrite);
        $entry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('document_type', ClinicalDocumentType::MedicalAssessment)
            ->with([
                'versions' => fn ($query) => $query
                    ->with(['author', 'authorAssignment', 'conditions', 'reviewActions.actorAssignment.user'])
                    ->orderByDesc('version_number')
                    ->limit(20),
            ])
            ->first();
        /** @var ClinicalEntryVersion|null $latest */
        $latest = $entry?->versions->first();
        $nursingVersion = ClinicalEntryVersion::query()
            ->whereRelation('clinicalEntry', 'encounter_id', $encounter->getKey())
            ->whereRelation('clinicalEntry', 'document_type', ClinicalDocumentType::NursingIntake->value)
            ->where('status', ClinicalEntryStatus::Approved)
            ->with(['clinicalEntry', 'observations', 'allergyAssessment', 'author'])
            ->orderByDesc('version_number')
            ->first();
        $codingCorrection = CodingDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('responsible_assignment_id', $assignment->getKey())
            ->where('status', '!=', CodingDocumentationCorrectionStatus::Resolved->value)
            ->with(['sourceCondition', 'sourceEntryVersion', 'requestedBy'])
            ->orderByDesc('requested_at')
            ->first();
        $task = $encounter->workTasks()
            ->where('assignment_id', $assignment->getKey())
            ->whereIn('task_type', [WorkTaskType::MedicalAssessment, WorkTaskType::CodingSourceCorrection])
            ->orderByRaw('case when task_type = ? then 0 else 1 end', [WorkTaskType::CodingSourceCorrection->value])
            ->first();
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $amendmentMode = $encounter->status === EncounterStatus::AmendmentPending && $codingCorrection !== null;
        $workflowEnabled = in_array($encounter->status, [EncounterStatus::WaitingClinician, EncounterStatus::InConsultation], true)
            || ($amendmentMode && in_array($codingCorrection->status, [
                CodingDocumentationCorrectionStatus::Open,
                CodingDocumentationCorrectionStatus::MedicalResponseSubmitted,
            ], true));

        $this->auditRecorder->record(
            action: 'clinical.medical_assessment_workspace_viewed',
            resourceType: 'clinical_entry',
            resourceId: $entry?->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            request: $request,
        );

        return Inertia::render('clinical/medical-assessment', [
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
                'allergyStatus' => $nursingVersion?->allergyAssessment?->assessment_state->label() ?? 'Belum dinilai',
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
                'type' => $task->task_type->value,
                'status' => [
                    'code' => $task->status->value,
                    'label' => $task->status->label(),
                ],
            ] : null,
            'codingCorrection' => $codingCorrection ? [
                'publicId' => $codingCorrection->public_id,
                'status' => [
                    'code' => $codingCorrection->status->value,
                    'label' => $codingCorrection->status->label(),
                ],
                'reason' => $codingCorrection->reason,
                'requestedBy' => $codingCorrection->requestedBy->name,
                'requestedAt' => $codingCorrection->requested_at->toIso8601String(),
                'sourceConditionPublicId' => $codingCorrection->sourceCondition->public_id,
                'sourceStatement' => $codingCorrection->sourceCondition->authored_text,
                'sourceMedicalVersionPublicId' => $codingCorrection->sourceEntryVersion->public_id,
                'sourceMedicalVersionNumber' => $codingCorrection->sourceEntryVersion->version_number,
                'requestedSourceHash' => $codingCorrection->requested_source_hash,
            ] : null,
            'nursingSource' => $nursingVersion ? [
                'versionPublicId' => $nursingVersion->public_id,
                'versionNumber' => $nursingVersion->version_number,
                'contentHash' => $nursingVersion->content_hash,
                'status' => [
                    'code' => $nursingVersion->status->value,
                    'label' => $nursingVersion->status->label(),
                ],
                'author' => $nursingVersion->author->name,
                'content' => $nursingVersion->content,
                'observations' => $nursingVersion->observations->map(fn ($observation): array => [
                    'code' => $observation->code,
                    'display' => $observation->display,
                    'value' => $observation->value_numeric,
                    'unitDisplay' => $observation->unit_display,
                    'mappingVersion' => $observation->mapping_version,
                ])->values()->all(),
            ] : null,
            'document' => [
                'publicId' => $entry?->public_id,
                'type' => ClinicalDocumentType::MedicalAssessment->value,
                'label' => ClinicalDocumentType::MedicalAssessment->label(),
                'schemaVersion' => ClinicalDocumentType::MedicalAssessment->schemaVersion(),
                'lifecycleStatus' => $entry ? [
                    'code' => $entry->lifecycle_status->value,
                    'label' => $entry->lifecycle_status->label(),
                ] : null,
                'latestVersion' => $latest ? $this->versionPayload($latest) : null,
                'history' => $entry?->versions
                    ->map(fn (ClinicalEntryVersion $version): array => $this->versionPayload($version))
                    ->values()
                    ->all() ?? [],
                'canEdit' => $workflowEnabled
                    && (! $latest || in_array($latest->status, [
                        ClinicalEntryStatus::Draft,
                        ClinicalEntryStatus::ChangesRequested,
                        ...($amendmentMode ? [ClinicalEntryStatus::Approved] : []),
                    ], true)),
                'requiresChangeReason' => $amendmentMode || $latest?->status === ClinicalEntryStatus::ChangesRequested,
                'amendmentMode' => $amendmentMode,
                'ordersLocked' => $amendmentMode,
                'workflowEnabled' => $workflowEnabled,
            ],
            'formOptions' => [
                'requestKey' => (string) Str::ulid(),
                'defaultOccurrenceAt' => now('Asia/Jakarta')->format('Y-m-d\TH:i'),
                'intents' => collect(ClinicalSaveIntent::cases())->map(fn (ClinicalSaveIntent $intent): string => $intent->value)->all(),
                'diagnosisCertainties' => collect(DiagnosisCertainty::cases())->map(fn (DiagnosisCertainty $certainty): array => [
                    'code' => $certainty->value,
                    'label' => $certainty->label(),
                ])->all(),
                'diagnosisRoles' => collect(DiagnosisRole::cases())->map(fn (DiagnosisRole $role): array => [
                    'code' => $role->value,
                    'label' => $role->label(),
                ])->all(),
            ],
            'urls' => [
                'store' => route('encounters.medical-assessment.versions.store', $encounter),
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
