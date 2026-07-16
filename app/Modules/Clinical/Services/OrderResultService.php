<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\DiagnosticResultStatus;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\ServiceRequestStatus;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\ResultAcknowledgement;
use App\Modules\Clinical\Models\ServiceRequest;
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
use Illuminate\Support\Facades\DB;

class OrderResultService
{
    public function __construct(
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function releaseResult(
        ServiceRequest $serviceRequest,
        Assignment $performerAssignment,
        array $payload,
    ): DiagnosticResult {
        return DB::transaction(function () use ($serviceRequest, $performerAssignment, $payload): DiagnosticResult {
            $requestKey = (string) $payload['request_key'];
            $existing = DiagnosticResult::query()
                ->with(['serviceRequest', 'acknowledgements'])
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->service_request_id !== $serviceRequest->getKey()
                    || $existing->performer_assignment_id !== $performerAssignment->getKey()) {
                    throw new DomainException('The result request key was already used in another context.');
                }

                return $existing;
            }

            $lockedRequest = ServiceRequest::query()
                ->with(['encounter.session', 'sourceEntryVersion'])
                ->whereKey($serviceRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->whereKey($lockedRequest->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($lockedRequest->session_id)->lockForUpdate()->firstOrFail();
            $activePerformer = Assignment::query()
                ->active()
                ->whereKey($performerAssignment->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertReleaseActor($activePerformer, $lockedRequest, $session);

            if (! in_array($lockedRequest->status, [ServiceRequestStatus::Active, ServiceRequestStatus::Completed], true)
                || ! in_array($encounter->status, [
                    EncounterStatus::AwaitingResult,
                    EncounterStatus::InConsultation,
                    EncounterStatus::AwaitingPharmacy,
                ], true)) {
                throw new DomainException('This synthetic service request is not open for a result release.');
            }

            $latest = DiagnosticResult::query()
                ->where('service_request_id', $lockedRequest->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();
            $status = $latest ? DiagnosticResultStatus::Corrected : DiagnosticResultStatus::Final;

            if ($latest === null && $encounter->status !== EncounterStatus::AwaitingResult) {
                throw new DomainException('A first synthetic result can only be released while the encounter is awaiting results.');
            }

            $components = $this->normalizeComponents($payload['components'] ?? []);
            $content = [
                'synthetic' => true,
                'narrativeConclusion' => trim((string) $payload['narrative_conclusion']),
                'components' => $components,
            ];
            $issuedAt = CarbonImmutable::now();
            $result = DiagnosticResult::query()->create([
                'request_key' => $requestKey,
                'service_request_id' => $lockedRequest->getKey(),
                'version_number' => $latest === null ? 1 : $latest->version_number + 1,
                'status' => $status,
                'report_code' => trim((string) $payload['report_code']),
                'report_display' => trim((string) $payload['report_display']),
                'content' => $content,
                'content_hash' => hash('sha256', CanonicalJson::encode($content)),
                'performer_user_id' => $activePerformer->user_id,
                'performer_assignment_id' => $activePerformer->getKey(),
                'effective_at' => CarbonImmutable::parse((string) $payload['effective_at']),
                'issued_at' => $issuedAt,
                'supersedes_result_id' => $latest?->getKey(),
            ]);

            if ($lockedRequest->status === ServiceRequestStatus::Active) {
                $lockedRequest->persistStatus(ServiceRequestStatus::Completed);
            }

            if ($status === DiagnosticResultStatus::Corrected) {
                $encounter = $this->reopenForCorrectedResult($encounter, $activePerformer);
            }

            $this->markAcknowledgementTaskReady($lockedRequest, $result, $status);
            $this->completeReleaseTaskWhenReady($encounter, $activePerformer);

            $this->auditRecorder->record(
                action: $status === DiagnosticResultStatus::Corrected
                    ? 'clinical.synthetic_result_corrected'
                    : 'clinical.synthetic_result_released',
                resourceType: 'diagnostic_result',
                resourceId: $result->public_id,
                actor: $activePerformer->user,
                assignment: $activePerformer,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'service_request_public_id' => $lockedRequest->public_id,
                    'result_version' => $result->version_number,
                    'result_status' => $result->status->value,
                    'content_hash' => $result->content_hash,
                    'synthetic' => true,
                ],
            );

            return $result->load(['serviceRequest', 'acknowledgements']);
        });
    }

    /** @param  array<string, mixed>  $payload */
    public function acknowledgeResult(
        DiagnosticResult $result,
        Assignment $actorAssignment,
        array $payload,
    ): ResultAcknowledgement {
        return DB::transaction(function () use ($result, $actorAssignment, $payload): ResultAcknowledgement {
            $requestKey = (string) $payload['request_key'];
            $existing = ResultAcknowledgement::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->diagnostic_result_id !== $result->getKey()
                    || $existing->actor_assignment_id !== $actorAssignment->getKey()) {
                    throw new DomainException('The acknowledgement request key was already used in another context.');
                }

                return $existing;
            }

            $lockedResult = DiagnosticResult::query()
                ->with('serviceRequest')
                ->whereKey($result->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $request = ServiceRequest::query()->whereKey($lockedResult->service_request_id)->lockForUpdate()->firstOrFail();
            $encounter = Encounter::query()->whereKey($request->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($request->session_id)->lockForUpdate()->firstOrFail();
            $activeActor = Assignment::query()
                ->active()
                ->whereKey($actorAssignment->getKey())
                ->lockForUpdate()
                ->first();

            if (! $activeActor
                || $activeActor->session_id !== $request->session_id
                || $activeActor->patient_id !== $request->patient_id
                || $activeActor->encounter_id !== $request->encounter_id
                || $activeActor->getKey() !== $request->requester_assignment_id
                || ! $activeActor->hasCapability(Capability::MedicalAssessmentWrite)) {
                throw new DomainException('Only the requesting medical assignment can acknowledge this current result.');
            }

            $latestResult = DiagnosticResult::query()
                ->where('service_request_id', $request->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->firstOrFail();

            if ($latestResult->getKey() !== $lockedResult->getKey()) {
                throw new DomainException('A superseded result cannot satisfy acknowledgement of the current result.');
            }

            if (ResultAcknowledgement::query()
                ->where('diagnostic_result_id', $lockedResult->getKey())
                ->where('actor_assignment_id', $activeActor->getKey())
                ->exists()) {
                throw new DomainException('The requesting medical assignment already acknowledged this exact result version.');
            }

            $acknowledgement = ResultAcknowledgement::query()->create([
                'request_key' => $requestKey,
                'diagnostic_result_id' => $lockedResult->getKey(),
                'actor_user_id' => $activeActor->user_id,
                'actor_assignment_id' => $activeActor->getKey(),
                'outcome' => (string) $payload['outcome'],
                'comment' => $this->nullableText($payload['comment'] ?? null),
                'acknowledged_at' => CarbonImmutable::now(),
            ]);

            $allCurrentResultsAcknowledged = $this->allCurrentResultsAcknowledged($encounter, $activeActor);
            $task = WorkTask::query()
                ->where('assignment_id', $activeActor->getKey())
                ->where('encounter_id', $encounter->getKey())
                ->where('task_type', WorkTaskType::ResultAcknowledgement)
                ->lockForUpdate()
                ->first();

            if ($task) {
                $task->fill([
                    'status' => $allCurrentResultsAcknowledged
                        ? WorkTaskStatus::Complete
                        : WorkTaskStatus::InProgress,
                    'completed_at' => $allCurrentResultsAcknowledged ? now() : null,
                ])->save();
            }

            if ($allCurrentResultsAcknowledged && $encounter->status === EncounterStatus::AwaitingResult) {
                $encounter = $this->encounterTransitionService->transition(
                    $encounter,
                    EncounterStatus::InConsultation,
                    $activeActor,
                    'current_synthetic_results_acknowledged',
                );

                $hasMedicationRequests = MedicationRequest::query()
                    ->where('encounter_id', $encounter->getKey())
                    ->where('status', MedicationRequestStatus::Active)
                    ->exists();

                if ($hasMedicationRequests) {
                    $encounter = $this->encounterTransitionService->transition(
                        $encounter,
                        EncounterStatus::AwaitingPharmacy,
                        $activeActor,
                        'results_acknowledged_pharmacy_handoff',
                    );
                    WorkTask::query()
                        ->where('encounter_id', $encounter->getKey())
                        ->where('task_type', WorkTaskType::PharmacyReview)
                        ->where('status', WorkTaskStatus::Waiting)
                        ->get()
                        ->each(function (WorkTask $task): void {
                            $task->fill([
                                'status' => WorkTaskStatus::Ready,
                                'description' => 'Telaah domain administratif, farmasetik, dan klinis sebagai penilaian manusia.',
                                'available_at' => now(),
                            ])->save();
                        });
                } else {
                    WorkTask::query()->updateOrCreate(
                        [
                            'assignment_id' => $activeActor->getKey(),
                            'encounter_id' => $encounter->getKey(),
                            'task_type' => WorkTaskType::EncounterClosure,
                        ],
                        [
                            'session_id' => $encounter->session_id,
                            'title' => 'Ajukan Penutupan Encounter',
                            'description' => 'Semua hasil saat ini telah diakui. Tinjau follow-up, edukasi, disposisi, dan ringkasan sebelum penutupan.',
                            'status' => WorkTaskStatus::Ready,
                            'priority' => 1,
                            'source_program' => Program::Facilitation,
                            'context' => [
                                'caseLabel' => $encounter->encounter_number,
                                'synthetic' => true,
                            ],
                            'available_at' => now(),
                            'completed_at' => null,
                        ],
                    );
                }
            }

            $this->auditRecorder->record(
                action: 'clinical.synthetic_result_acknowledged',
                resourceType: 'diagnostic_result',
                resourceId: $lockedResult->public_id,
                actor: $activeActor->user,
                assignment: $activeActor,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'result_version' => $lockedResult->version_number,
                    'result_content_hash' => $lockedResult->content_hash,
                    'outcome' => $acknowledgement->outcome,
                    'all_current_results_acknowledged' => $allCurrentResultsAcknowledged,
                ],
            );

            return $acknowledgement->load('diagnosticResult');
        });
    }

    private function assertReleaseActor(
        ?Assignment $actor,
        ServiceRequest $request,
        SimulationSession $session,
    ): void {
        $exactSupervisor = $actor
            && $actor->patient_id === $request->patient_id
            && $actor->encounter_id === $request->encounter_id
            && $actor->hasCapability(Capability::SupervisionReview);
        $sessionFacilitator = $actor
            && $actor->patient_id === null
            && $actor->encounter_id === null
            && $actor->hasCapability(Capability::SessionFacilitate);

        if (! $actor
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $actor->session_id !== $session->getKey()
            || (! $exactSupervisor && ! $sessionFacilitator)) {
            throw new DomainException('The active assignment cannot release a synthetic result for this request.');
        }
    }

    /** @return array<int, array{code: string, display: string, value: string, unit: string|null}> */
    private function normalizeComponents(mixed $rawComponents): array
    {
        if (! is_array($rawComponents)) {
            return [];
        }

        $components = [];

        foreach ($rawComponents as $component) {
            if (! is_array($component)) {
                continue;
            }

            $components[] = [
                'code' => trim((string) ($component['code'] ?? '')),
                'display' => trim((string) ($component['display'] ?? '')),
                'value' => trim((string) ($component['value'] ?? '')),
                'unit' => $this->nullableText($component['unit'] ?? null),
            ];
        }

        return $components;
    }

    private function markAcknowledgementTaskReady(
        ServiceRequest $request,
        DiagnosticResult $result,
        DiagnosticResultStatus $status,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $request->requester_assignment_id)
            ->where('encounter_id', $request->encounter_id)
            ->where('task_type', WorkTaskType::ResultAcknowledgement)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The medical result-acknowledgement task is missing.');
        }

        $task->fill([
            'status' => WorkTaskStatus::Ready,
            'description' => $status === DiagnosticResultStatus::Corrected
                ? 'Hasil terkoreksi tersedia. Tinjau versi dan hash terbaru; acknowledgement lama tidak berlaku untuk koreksi ini.'
                : 'Hasil final sintetis tersedia untuk ditinjau dan diakui.',
            'context' => array_merge($task->context ?? [], [
                'currentResultPublicId' => $result->public_id,
                'currentResultVersion' => $result->version_number,
                'currentResultContentHash' => $result->content_hash,
            ]),
            'available_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    private function reopenForCorrectedResult(Encounter $encounter, Assignment $performer): Encounter
    {
        if ($encounter->status === EncounterStatus::AwaitingPharmacy) {
            WorkTask::query()
                ->where('encounter_id', $encounter->getKey())
                ->where('task_type', WorkTaskType::PharmacyReview)
                ->whereIn('status', [
                    WorkTaskStatus::Ready,
                    WorkTaskStatus::InProgress,
                    WorkTaskStatus::Complete,
                ])
                ->get()
                ->each(function (WorkTask $task): void {
                    $task->fill([
                        'status' => WorkTaskStatus::Waiting,
                        'description' => 'Menunggu hasil diagnostik terkoreksi diakui sebelum telaah resep dilanjutkan.',
                        'completed_at' => null,
                    ])->save();
                });

            $encounter = $this->encounterTransitionService->transition(
                $encounter,
                EncounterStatus::InConsultation,
                $performer,
                'corrected_result_reopens_clinical_review',
            );
        }

        if ($encounter->status === EncounterStatus::InConsultation) {
            $encounter = $this->encounterTransitionService->transition(
                $encounter,
                EncounterStatus::AwaitingResult,
                $performer,
                'corrected_result_requires_current_acknowledgement',
            );
        }

        return $encounter;
    }

    private function completeReleaseTaskWhenReady(Encounter $encounter, Assignment $performer): void
    {
        $allRequestsHaveResults = ! ServiceRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('status', [ServiceRequestStatus::Active, ServiceRequestStatus::Completed])
            ->whereDoesntHave('results')
            ->exists();

        if (! $allRequestsHaveResults) {
            return;
        }

        $task = WorkTask::query()
            ->where('assignment_id', $performer->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::SyntheticResultRelease)
            ->lockForUpdate()
            ->first();

        if ($task) {
            $task->fill([
                'status' => WorkTaskStatus::Complete,
                'completed_at' => now(),
            ])->save();
        }
    }

    private function allCurrentResultsAcknowledged(Encounter $encounter, Assignment $actor): bool
    {
        $requests = ServiceRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('status', [ServiceRequestStatus::Active, ServiceRequestStatus::Completed])
            ->get();

        if ($requests->isEmpty()) {
            return false;
        }

        foreach ($requests as $request) {
            $currentResult = DiagnosticResult::query()
                ->where('service_request_id', $request->getKey())
                ->orderByDesc('version_number')
                ->first();

            if (! $currentResult
                || ! ResultAcknowledgement::query()
                    ->where('diagnostic_result_id', $currentResult->getKey())
                    ->where('actor_assignment_id', $actor->getKey())
                    ->exists()) {
                return false;
            }
        }

        return true;
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
