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
            ->whereRelation('session', 'status', SessionStatus::Active->value)
            ->with(['session.scenario'])
            ->orderBy('active_from')
            ->get();

        $requestedSessionCode = $request->query('session');
        $hasSessionSelection = $request->query->has('session');
        $availableSessionCount = $assignments->unique('session_id')->count();
        $selectedAssignments = $assignments->filter(fn (): bool => false);

        if ($hasSessionSelection) {
            if (! is_string($requestedSessionCode)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $requestedSessionCode) !== 1) {
                $this->recordSelectionDenial($request, $user, $availableSessionCount, 'invalid_session_selector');
                abort(404);
            }

            $selectedAssignments = $assignments
                ->filter(fn (Assignment $assignment): bool => $assignment->session->code === $requestedSessionCode);

            if ($selectedAssignments->isEmpty()) {
                $this->recordSelectionDenial($request, $user, $availableSessionCount, 'unavailable_session_selector');
                abort(404);
            }
        } elseif ($availableSessionCount === 1) {
            $onlySessionId = $assignments->first()?->session_id;
            $selectedAssignments = $assignments
                ->filter(fn (Assignment $assignment): bool => $assignment->session_id === $onlySessionId);
        }

        $selectedSession = $selectedAssignments->first()?->session;
        $selectionRequired = $availableSessionCount > 1 && $selectedSession === null;

        $tasks = WorkTask::query()
            ->whereIn('assignment_id', $selectedAssignments->modelKeys())
            ->when(
                $selectedSession,
                fn ($query) => $query->where('session_id', $selectedSession->getKey()),
            )
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
                'available_session_count' => $availableSessionCount,
                'selected_assignment_count' => $selectedAssignments->count(),
                'selected_session_public_id' => $selectedSession?->public_id,
                'selection_required' => $selectionRequired,
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
            'selectedSessionCode' => $selectedSession?->code,
            'selectionRequired' => $selectionRequired,
            'summary' => [
                'ready' => $tasks->where('status', WorkTaskStatus::Ready)->count(),
                'inProgress' => $tasks->where('status', WorkTaskStatus::InProgress)->count(),
                'waiting' => $tasks->where('status', WorkTaskStatus::Waiting)->count(),
                'blocked' => $tasks->where('status', WorkTaskStatus::Blocked)->count(),
                'changesRequested' => $tasks->where('status', WorkTaskStatus::ChangesRequested)->count(),
            ],
        ]);
    }

    private function recordSelectionDenial(
        Request $request,
        User $user,
        int $availableSessionCount,
        string $reason,
    ): void {
        $this->auditRecorder->record(
            action: 'work_queue.selection_denied',
            resourceType: 'work_queue',
            actor: $user,
            outcome: 'DENIED',
            reason: $reason,
            metadata: [
                'available_session_count' => $availableSessionCount,
                'session_selector_present' => true,
            ],
            request: $request,
            includeRequestFingerprint: false,
        );
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
