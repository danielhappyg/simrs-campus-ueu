<?php

namespace App\Http\Controllers\Work;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\WorkTask;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkQueueController extends Controller
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignments = Assignment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->with(['session.scenario'])
            ->orderBy('active_from')
            ->get();

        $tasks = WorkTask::query()
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->whereRelation('session', 'status', SessionStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->with(['assignment', 'session', 'encounter', 'clinicalEntryVersion'])
            ->orderBy('priority')
            ->orderBy('available_at')
            ->get();

        $this->auditRecorder->record(
            action: 'work_queue.viewed',
            resourceType: 'work_queue',
            actor: $user,
            metadata: [
                'assignment_count' => $assignments->count(),
                'task_count' => $tasks->count(),
            ],
            request: $request,
        );

        $assignmentPayloads = $assignments->map(fn (Assignment $assignment): array => [
            'publicId' => $assignment->public_id,
            'program' => [
                'code' => $assignment->program->value,
                'label' => $assignment->program->label(),
            ],
            'role' => [
                'code' => $assignment->application_role->value,
                'label' => $assignment->application_role->label(),
            ],
            'capabilities' => collect($assignment->capabilities)
                ->map(fn (string $capability): array => [
                    'code' => $capability,
                    'label' => Capability::tryFrom($capability)?->label() ?? $capability,
                ])
                ->values()
                ->all(),
            'session' => [
                'publicId' => $assignment->session->public_id,
                'code' => $assignment->session->code,
                'status' => [
                    'code' => $assignment->session->status->value,
                    'label' => $assignment->session->status->label(),
                ],
                'courseCode' => $assignment->session->course_code,
                'cohortCode' => $assignment->session->cohort_code,
                'scenarioTitle' => $assignment->session->scenario->title,
            ],
        ])->values()->all();

        $taskPayloads = [];

        foreach ($tasks as $task) {
            $taskPayloads[] = [
                'publicId' => $task->public_id,
                'type' => $task->task_type->value,
                'title' => $task->title,
                'description' => $task->description,
                'status' => [
                    'code' => $task->status->value,
                    'label' => $task->status->label(),
                ],
                'priority' => $task->priority,
                'sourceProgram' => $task->source_program?->label(),
                'availableAt' => $task->available_at?->toIso8601String(),
                'context' => $task->context,
                'assignmentPublicId' => $task->assignment->public_id,
                'sessionCode' => $task->session->code,
                'actionUrl' => match ($task->task_type) {
                    WorkTaskType::Registration => route('sessions.registration', $task->session),
                    WorkTaskType::NursingIntake => $task->encounter
                        ? route('encounters.nursing-intake.show', $task->encounter)
                        : null,
                    WorkTaskType::MedicalAssessment,
                    WorkTaskType::CodingSourceCorrection => $task->encounter
                        ? route('encounters.medical-assessment.show', $task->encounter)
                        : null,
                    WorkTaskType::SyntheticResultRelease,
                    WorkTaskType::ResultAcknowledgement => $task->encounter
                        ? route('encounters.order-results.show', $task->encounter)
                        : null,
                    WorkTaskType::PharmacyReview,
                    WorkTaskType::PrescriptionInterventionResponse,
                    WorkTaskType::Dispensing => $task->encounter
                        ? route('encounters.pharmacy.show', $task->encounter)
                        : null,
                    WorkTaskType::EncounterClosure,
                    WorkTaskType::ProcedureSourceCorrection,
                    WorkTaskType::EncounterClosureReview => $task->encounter
                        ? route('encounters.closure.show', $task->encounter)
                        : null,
                    WorkTaskType::RecordCorrection => $task->encounter
                        ? route('encounters.closure.show', $task->encounter)
                        : null,
                    WorkTaskType::RecordReview,
                    WorkTaskType::RecordQualityReview => $task->encounter
                        ? route('encounters.record-quality.show', $task->encounter)
                        : null,
                    WorkTaskType::Coding,
                    WorkTaskType::CodingReview => $task->encounter
                        ? $this->codingActionUrl($task)
                        : null,
                    WorkTaskType::SupervisorReview => $task->clinicalEntryVersion
                        ? route('clinical-versions.review.show', $task->clinicalEntryVersion)
                        : null,
                    WorkTaskType::SafetyDisposition => $task->encounter
                        ? route('encounters.safety-disposition.show', $task->encounter)
                        : null,
                    WorkTaskType::Debrief => $task->encounter
                        ? route('encounters.debrief.show', $task->encounter)
                        : null,
                    WorkTaskType::SessionOrientation => null,
                },
            ];
        }

        return Inertia::render('work/index', [
            'assignments' => $assignmentPayloads,
            'tasks' => $taskPayloads,
            'summary' => [
                'ready' => $tasks->where('status', WorkTaskStatus::Ready)->count(),
                'inProgress' => $tasks->where('status', WorkTaskStatus::InProgress)->count(),
                'waiting' => $tasks->where('status', WorkTaskStatus::Waiting)->count(),
                'blocked' => $tasks->where('status', WorkTaskStatus::Blocked)->count(),
                'changesRequested' => $tasks->where('status', WorkTaskStatus::ChangesRequested)->count(),
            ],
        ]);
    }

    private function codingActionUrl(WorkTask $task): string
    {
        $sourcePublicId = data_get($task->context, 'sourceConditionPublicId')
            ?? data_get($task->context, 'sourceProcedurePublicId');
        $parameters = ['encounter' => $task->encounter];

        if (is_string($sourcePublicId) && $sourcePublicId !== '') {
            $parameters['source'] = $sourcePublicId;
        }

        return route('encounters.coding.show', $parameters);
    }
}
