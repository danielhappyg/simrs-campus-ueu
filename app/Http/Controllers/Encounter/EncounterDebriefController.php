<?php

namespace App\Http\Controllers\Encounter;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\DebriefNoteType;
use App\Modules\Teaching\Enums\RubricReferenceStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\DebriefNote;
use App\Modules\Teaching\Models\DebriefNoteVersion;
use App\Modules\Teaching\Models\WorkTask;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use App\Modules\Teaching\Services\EncounterDebriefTimeline;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EncounterDebriefController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly EncounterDebriefTimeline $timeline,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['session.scenario', 'patient.identifiers', 'location']);
        $assignment = $this->assignmentResolver->forDebrief($user, $encounter);

        if ($encounter->status !== EncounterStatus::Finalized) {
            abort(409, 'Debrief tersedia setelah encounter difinalisasi untuk simulasi.');
        }

        $timeline = $this->timeline->build($encounter);
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $writerAssignment = $this->assignmentResolver->writerForDebrief($user, $encounter);
        $debriefNotes = DebriefNote::query()
            ->with(['versions.authorAssignment.user'])
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (DebriefNote $note): array => [
                'publicId' => $note->public_id,
                'type' => [
                    'code' => $note->note_type->value,
                    'label' => $note->note_type->label(),
                ],
                'createdAt' => $note->created_at?->toIso8601String(),
                'versions' => $note->versions
                    ->sortBy('version_number')
                    ->values()
                    ->map(fn (DebriefNoteVersion $version): array => [
                        'publicId' => $version->public_id,
                        'versionNumber' => $version->version_number,
                        'body' => $version->body,
                        'changeReason' => $version->change_reason,
                        'authoredAt' => $version->authored_at->toIso8601String(),
                        'author' => [
                            'name' => $version->authorAssignment->user->name,
                            'program' => $version->authorAssignment->program->label(),
                            'role' => $version->authorAssignment->application_role->label(),
                            'assignmentPublicId' => $version->authorAssignment->public_id,
                        ],
                    ])->all(),
                'revisionUrl' => route('debrief-notes.versions.store', $note),
                'revisionRequestKey' => (string) Str::ulid(),
            ])->all();
        $learningOutcomes = $encounter->session->scenario->learning_outcomes ?? [];
        $rubricReferences = collect($encounter->session->scenario->rubric_references ?? [])
            ->filter(fn (array $reference): bool => is_string($reference['code'] ?? null)
                && is_string($reference['title'] ?? null)
                && is_string($reference['version'] ?? null)
                && is_string($reference['status'] ?? null)
                && is_string($reference['source_label'] ?? null))
            ->map(function (array $reference) use ($learningOutcomes): array {
                $status = RubricReferenceStatus::tryFrom($reference['status']);
                $rawNumbers = $reference['learning_outcome_numbers'] ?? [];

                if (! is_array($rawNumbers)) {
                    $rawNumbers = [];
                }

                $numbers = collect($rawNumbers)
                    ->filter(fn (mixed $number): bool => is_int($number) && $number > 0)
                    ->unique()
                    ->sort()
                    ->values();

                return [
                    'code' => $reference['code'],
                    'title' => $reference['title'],
                    'version' => $reference['version'],
                    'status' => [
                        'code' => $reference['status'],
                        'label' => $status?->label() ?? 'Status referensi tidak dikenali',
                    ],
                    'sourceLabel' => $reference['source_label'],
                    'learningOutcomes' => $numbers
                        ->map(fn (int $number): array => [
                            'number' => $number,
                            'label' => $learningOutcomes[$number - 1] ?? "Tujuan pembelajaran {$number}",
                        ])->all(),
                    'nonScoring' => true,
                ];
            })->values()->all();

        $task = WorkTask::query()
            ->where('assignment_id', $assignment->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::Debrief)
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->first();

        if ($task) {
            $task->fill([
                'status' => WorkTaskStatus::Complete,
                'completed_at' => now(),
            ])->save();
        }

        $this->auditRecorder->record(
            action: 'debrief.workspace_viewed',
            resourceType: 'encounter_debrief',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'displayed_event_count' => data_get($timeline, 'summary.displayedEventCount'),
                'truncated' => data_get($timeline, 'summary.truncated'),
            ],
            request: $request,
        );

        return Inertia::render('encounter/debrief', [
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
                'allergyStatus' => 'Lihat sumber asesmen pada linimasa',
                'synthetic' => true,
            ],
            'assignment' => [
                'publicId' => $assignment->public_id,
                'program' => $assignment->program->label(),
                'role' => $assignment->application_role->label(),
                'canViewReports' => $assignment->hasCapability(Capability::ReportView),
            ],
            'session' => [
                'publicId' => $encounter->session->public_id,
                'code' => $encounter->session->code,
                'status' => [
                    'code' => $encounter->session->status->value,
                    'label' => $encounter->session->status->label(),
                ],
                'scenarioTitle' => $encounter->session->scenario->title,
                'learningOutcomes' => $encounter->session->scenario->learning_outcomes ?? [],
            ],
            'release' => [
                'gate' => EncounterStatus::Finalized->value,
                'label' => 'Dirilis setelah finalisasi simulasi',
                'finalizedAt' => $encounter->finalized_at?->toIso8601String()
                    ?? $encounter->period_end?->toIso8601String(),
                'ordinaryEditsLocked' => true,
            ],
            'teachingEvidence' => [
                'notes' => $debriefNotes,
                'rubricReferences' => $rubricReferences,
                'authoring' => [
                    'canAuthorNotes' => $writerAssignment !== null,
                    'storeUrl' => route('encounters.debrief.notes.store', $encounter),
                    'requestKey' => (string) Str::ulid(),
                    'bodyMaxCharacters' => 4000,
                    'noteTypes' => collect(DebriefNoteType::cases())->map(fn (DebriefNoteType $type): array => [
                        'code' => $type->value,
                        'label' => $type->label(),
                    ])->all(),
                ],
            ],
            ...$timeline,
            'urls' => [
                'encounter' => route('encounters.show', $encounter),
                'timeline' => route('encounters.timeline.show', $encounter),
                'self' => route('encounters.debrief.show', $encounter),
                'workQueue' => route('work'),
                'outpatientSummaryReport' => route('encounters.reports.outpatient-summary', $encounter),
                'debriefEvidenceReport' => route('encounters.reports.debrief-evidence', $encounter),
                'interoperabilityPreview' => $assignment->hasCapability(Capability::ReportView)
                    ? route('encounters.interoperability-preview.show', $encounter)
                    : null,
            ],
        ]);
    }
}
