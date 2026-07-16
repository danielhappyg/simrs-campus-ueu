<?php

namespace App\Http\Controllers\Coding;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\CodingReviewAction;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\CodingSuggestionCandidate;
use App\Modules\Coding\Models\CodingSuggestionDecision;
use App\Modules\Coding\Models\CodingSuggestionRun;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Services\TerminologySearchService;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Services\RecordCompletenessService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CodingWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly TerminologySearchService $searchService,
        private readonly RecordCompletenessService $recordCompletenessService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['patient.identifiers', 'session.scenario', 'location']);
        $assignment = $this->assignmentResolver->forEncounterAny($user, $encounter, [
            Capability::CodingWrite,
            Capability::SupervisionReview,
        ]);

        if ($assignment->program !== Program::Rmik) {
            abort(403);
        }
        $medicalEntry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('document_type', ClinicalDocumentType::MedicalAssessment)
            ->first();
        $currentMedicalVersion = $medicalEntry?->versions()
            ->with(['conditions.authorAssignment.user'])
            ->orderByDesc('version_number')
            ->first();
        $conditions = $currentMedicalVersion?->conditions->sortBy('id')->values() ?? collect();
        $currentClosure = EncounterClosure::query()
            ->with(['procedures.recorderAssignment.user', 'procedures.basedOnServiceRequest'])
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();
        $procedures = $currentClosure?->procedures->sortBy('sequence_number')->values() ?? collect();
        $latestQuality = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();
        $releases = collect(TerminologySystem::cases())->mapWithKeys(
            fn (TerminologySystem $system): array => [$system->value => $this->searchService->activeRelease($system)],
        );
        $runs = CodingSuggestionRun::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['release', 'sourceCondition', 'sourceEntryVersion', 'sourceProcedure.closure', 'requestedBy', 'candidates.concept', 'decisions.candidate.concept', 'decisions.resultingAssignment.concept'])
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get();
        $codingAssignments = CodingAssignment::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['concept', 'release', 'coder', 'coderAssignment', 'sourceCondition', 'sourceProcedure.closure', 'reviewActions.reviewer'])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get();
        $documentationCorrection = CodingDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('requested_by_assignment_id', $assignment->getKey())
            ->where('status', '!=', CodingDocumentationCorrectionStatus::Resolved)
            ->with(['responsibleUser', 'sourceCondition', 'responseMedicalVersion', 'responseClosure'])
            ->orderByDesc('requested_at')
            ->first();
        $procedureDocumentationCorrection = ProcedureDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('requested_by_assignment_id', $assignment->getKey())
            ->where('status', '!=', ProcedureDocumentationCorrectionStatus::Resolved)
            ->with(['responsibleUser', 'sourceProcedure', 'sourceClosure', 'responseClosure'])
            ->orderByDesc('requested_at')
            ->first();
        $runsByCondition = $runs->groupBy('source_condition_id');
        $runsByProcedure = $runs->groupBy('source_procedure_id');
        $assignmentsByCondition = $codingAssignments->groupBy('source_condition_id');
        $assignmentsByProcedure = $codingAssignments->groupBy('source_procedure_id');
        $icd10Release = $releases->get(TerminologySystem::Icd10->value);
        $icd9Release = $releases->get(TerminologySystem::Icd9Cm->value);
        $qualityApproved = $this->recordCompletenessService->approvedReviewIsCurrent($latestQuality, $encounter);
        $codingContextReady = $assignment->hasCapability(Capability::CodingWrite)
            && $encounter->status === EncounterStatus::RecordReview
            && $qualityApproved
            && $currentMedicalVersion?->status === ClinicalEntryStatus::Approved;
        $canCodeDiagnosis = $codingContextReady && $icd10Release instanceof TerminologyRelease;
        $canCodeProcedure = $codingContextReady
            && $currentClosure?->status === EncounterClosureStatus::Approved
            && $icd9Release instanceof TerminologyRelease;
        $selectedSourceId = trim((string) $request->query('source', ''));
        $correctionSourceId = $procedureDocumentationCorrection?->sourceProcedure->public_id
            ?? $documentationCorrection?->sourceCondition->public_id;

        if ($selectedSourceId === ''
            || (! $conditions->contains('public_id', $selectedSourceId)
                && ! $procedures->contains('public_id', $selectedSourceId))) {
            $firstDiagnosis = $conditions->first();
            $firstProcedure = $procedures->first();
            $selectedSourceId = is_string($correctionSourceId)
                && ($conditions->contains('public_id', $correctionSourceId)
                    || $procedures->contains('public_id', $correctionSourceId))
                    ? $correctionSourceId
                    : ($firstDiagnosis instanceof ClinicalCondition
                        ? $firstDiagnosis->public_id
                        : ($firstProcedure instanceof ClinicalProcedure ? $firstProcedure->public_id : ''));
        }

        $manualQuery = Str::limit(trim((string) $request->query('q', '')), 120, '');
        $selectedSourceSystem = $procedures->contains('public_id', $selectedSourceId)
            ? TerminologySystem::Icd9Cm
            : TerminologySystem::Icd10;
        $searchSystem = $selectedSourceSystem;
        $manualResults = [];
        $manualSearchError = null;

        if (mb_strlen($manualQuery) >= 2) {
            try {
                $manualResults = $this->searchService->searchActive($searchSystem, $manualQuery, 12);
            } catch (DomainException $exception) {
                $manualSearchError = $exception->getMessage();
            }

            $this->auditRecorder->record(
                action: 'coding.terminology_searched',
                resourceType: 'encounter',
                resourceId: $encounter->public_id,
                actor: $user,
                assignment: $assignment,
                session: $encounter->session,
                encounter: $encounter,
                metadata: [
                    'classification_system' => $searchSystem->value,
                    'query_hash' => hash('sha256', mb_strtolower($manualQuery)),
                    'result_count' => count($manualResults),
                    'source_type' => $selectedSourceSystem->sourceType(),
                    'source_public_id' => $selectedSourceId,
                ],
            );
        }

        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $task = $encounter->workTasks()
            ->where('assignment_id', $assignment->getKey())
            ->whereIn('task_type', [WorkTaskType::Coding, WorkTaskType::CodingReview])
            ->orderByDesc('id')
            ->first();

        $this->auditRecorder->record(
            action: 'coding.workspace_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'quality_approved' => $qualityApproved,
                'diagnosis_count' => $conditions->count(),
                'performed_procedure_count' => $procedures->count(),
                'suggestion_run_count' => $runs->count(),
                'coding_assignment_count' => $codingAssignments->count(),
                'active_icd10_release' => $icd10Release?->public_id,
                'active_icd9cm_release' => $icd9Release?->public_id,
                'documentation_correction_public_id' => $documentationCorrection?->public_id,
                'procedure_documentation_correction_public_id' => $procedureDocumentationCorrection?->public_id,
            ],
        );

        return Inertia::render('coding/workspace', [
            'encounter' => [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'status' => ['code' => $encounter->status->value, 'label' => $encounter->status->label()],
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
                'allergyStatus' => 'Lihat asesmen awal yang disetujui',
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
                'canCode' => $canCodeDiagnosis || $canCodeProcedure,
                'canReview' => $assignment->hasCapability(Capability::SupervisionReview),
            ],
            'task' => $task ? [
                'publicId' => $task->public_id,
                'type' => $task->task_type->value,
                'status' => ['code' => $task->status->value, 'label' => $task->status->label()],
            ] : null,
            'documentationCorrection' => $documentationCorrection ? [
                'publicId' => $documentationCorrection->public_id,
                'sourceType' => CodingSourceType::Diagnosis->value,
                'status' => [
                    'code' => $documentationCorrection->status->value,
                    'label' => $documentationCorrection->status->label(),
                ],
                'reason' => $documentationCorrection->reason,
                'responsibleAuthor' => $documentationCorrection->responsibleUser->name,
                'requestedAt' => $documentationCorrection->requested_at->toIso8601String(),
                'requestedSourceHash' => $documentationCorrection->requested_source_hash,
                'requestedClosureHash' => null,
                'responseMedicalVersionPublicId' => $documentationCorrection->responseMedicalVersion?->public_id,
                'responseClosurePublicId' => $documentationCorrection->responseClosure?->public_id,
            ] : ($procedureDocumentationCorrection ? [
                'publicId' => $procedureDocumentationCorrection->public_id,
                'sourceType' => CodingSourceType::Procedure->value,
                'status' => [
                    'code' => $procedureDocumentationCorrection->status->value,
                    'label' => $procedureDocumentationCorrection->status->label(),
                ],
                'reason' => $procedureDocumentationCorrection->reason,
                'responsibleAuthor' => $procedureDocumentationCorrection->responsibleUser->name,
                'requestedAt' => $procedureDocumentationCorrection->requested_at->toIso8601String(),
                'requestedSourceHash' => $procedureDocumentationCorrection->requested_source_hash,
                'requestedClosureHash' => $procedureDocumentationCorrection->requested_closure_hash,
                'responseMedicalVersionPublicId' => null,
                'responseClosurePublicId' => $procedureDocumentationCorrection->responseClosure?->public_id,
            ] : null),
            'prerequisites' => [
                'qualityApproved' => $qualityApproved,
                'qualityReviewPublicId' => $latestQuality?->public_id,
                'qualityReviewVersion' => $latestQuality?->version_number,
                'medicalSourceApproved' => $currentMedicalVersion?->status === ClinicalEntryStatus::Approved,
                'medicalSourcePublicId' => $currentMedicalVersion?->public_id,
                'medicalSourceVersion' => $currentMedicalVersion?->version_number,
                'medicalSourceHash' => $currentMedicalVersion?->content_hash,
                'closureSourceApproved' => $currentClosure?->status === EncounterClosureStatus::Approved,
                'closureSourcePublicId' => $currentClosure?->public_id,
                'closureSourceVersion' => $currentClosure?->version_number,
                'closureSourceHash' => $currentClosure?->content_hash,
            ],
            'releases' => collect(TerminologySystem::cases())->map(fn (TerminologySystem $system): array => $this->releasePayload(
                system: $system,
                release: $releases->get($system->value),
            ))->values()->all(),
            'sources' => $conditions->map(fn (ClinicalCondition $condition): array => $this->diagnosisSourcePayload(
                encounter: $encounter,
                condition: $condition,
                currentMedicalVersion: $currentMedicalVersion,
                runs: $runsByCondition->get($condition->getKey(), collect()),
                assignments: $assignmentsByCondition->get($condition->getKey(), collect()),
                canCode: $canCodeDiagnosis,
                actingAssignmentId: $assignment->getKey(),
            ))->concat($procedures->map(fn (ClinicalProcedure $procedure): array => $this->procedureSourcePayload(
                encounter: $encounter,
                procedure: $procedure,
                closure: $currentClosure,
                runs: $runsByProcedure->get($procedure->getKey(), collect()),
                assignments: $assignmentsByProcedure->get($procedure->getKey(), collect()),
                canCode: $canCodeProcedure,
                actingAssignmentId: $assignment->getKey(),
            )))->values()->all(),
            'selectedSourcePublicId' => $selectedSourceId,
            'manualSearch' => [
                'query' => $manualQuery,
                'system' => $searchSystem->value,
                'error' => $manualSearchError,
                'results' => collect($manualResults)->map(fn (array $result): array => [
                    'concept' => $this->conceptPayload($result['concept']),
                    'confidence' => ['code' => $result['confidence']->value, 'label' => $result['confidence']->label()],
                    'score' => $result['score'],
                    'evidence' => $result['evidence'],
                    'specificityWarning' => $result['specificityWarning'],
                ])->values()->all(),
            ],
            'formOptions' => [
                'suggestionRequestKey' => (string) Str::ulid(),
                'decisionRequestKey' => (string) Str::ulid(),
                'reviewRequestKey' => (string) Str::ulid(),
                'decisionTypes' => collect(CodingDecisionType::cases())->map(fn (CodingDecisionType $decision): array => [
                    'code' => $decision->value,
                    'label' => $decision->label(),
                ])->all(),
                'reviewActions' => collect(CodingReviewAction::cases())->map(fn (CodingReviewAction $action): array => [
                    'code' => $action->value,
                    'label' => $action->label(),
                ])->all(),
            ],
            'urls' => [
                'self' => route('encounters.coding.show', $encounter),
                'recordQuality' => route('encounters.record-quality.show', $encounter),
                'workQueue' => route('work'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function releasePayload(TerminologySystem $system, ?TerminologyRelease $release): array
    {
        return [
            'system' => ['code' => $system->value, 'label' => $system->label()],
            'sourceType' => $system->sourceType(),
            'active' => $release !== null,
            'release' => $release ? [
                'publicId' => $release->public_id,
                'logicalVersion' => $release->logical_version,
                'status' => ['code' => $release->status->value, 'label' => $release->status->label()],
                'sourceFilename' => $release->source_filename,
                'sourceSha256' => $release->source_sha256,
                'rowCount' => $release->row_count,
                'ignoredBlankRows' => $release->ignored_blank_rows,
                'provenance' => $release->source_provenance_status->label(),
                'activatedAt' => $release->activated_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, CodingSuggestionRun>  $runs
     * @param  Collection<int, CodingAssignment>  $assignments
     * @return array<string, mixed>
     */
    private function diagnosisSourcePayload(
        Encounter $encounter,
        ClinicalCondition $condition,
        ?ClinicalEntryVersion $currentMedicalVersion,
        Collection $runs,
        Collection $assignments,
        bool $canCode,
        int $actingAssignmentId,
    ): array {
        /** @var CodingSuggestionRun|null $latestRun */
        $latestRun = $runs->first();
        /** @var CodingAssignment|null $latestAssignment */
        $latestAssignment = $assignments->first();
        $canStartAssignment = $canCode
            && (! $latestAssignment || $latestAssignment->status === CodingAssignmentStatus::ChangesRequested);

        return [
            'publicId' => $condition->public_id,
            'sourceType' => [
                'code' => CodingSourceType::Diagnosis->value,
                'label' => CodingSourceType::Diagnosis->label(),
            ],
            'terminologySystem' => TerminologySystem::Icd10->value,
            'correctionSupported' => true,
            'authoredText' => $condition->authored_text,
            'certainty' => ['code' => $condition->certainty->value, 'label' => $condition->certainty->label()],
            'role' => ['code' => $condition->role->value, 'label' => $condition->role->label()],
            'clinicalStatus' => $condition->clinical_status,
            'clinicianCode' => $condition->code ? [
                'system' => $condition->code_system,
                'code' => $condition->code,
                'display' => $condition->display,
                'version' => $condition->code_version,
            ] : null,
            'sourceVersion' => [
                'publicId' => $currentMedicalVersion?->public_id,
                'versionNumber' => $currentMedicalVersion?->version_number,
                'schemaVersion' => $currentMedicalVersion?->schema_version,
                'status' => $currentMedicalVersion ? [
                    'code' => $currentMedicalVersion->status->value,
                    'label' => $currentMedicalVersion->status->label(),
                ] : null,
                'contentHash' => $currentMedicalVersion?->content_hash,
                'documentContentHash' => $currentMedicalVersion?->content_hash,
                'author' => $condition->authorAssignment->user->name,
            ],
            'procedureDetails' => null,
            'canGenerate' => $canStartAssignment,
            'suggestionUrl' => route('encounters.conditions.coding-suggestions.store', [
                'encounter' => $encounter,
                'condition' => $condition,
            ]),
            'latestRun' => $latestRun ? $this->runPayload($latestRun, $canStartAssignment) : null,
            'assignmentHistory' => $assignments->map(fn (CodingAssignment $coding): array => $this->assignmentPayload(
                coding: $coding,
                actingAssignmentId: $actingAssignmentId,
            ))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, CodingSuggestionRun>  $runs
     * @param  Collection<int, CodingAssignment>  $assignments
     * @return array<string, mixed>
     */
    private function procedureSourcePayload(
        Encounter $encounter,
        ClinicalProcedure $procedure,
        ?EncounterClosure $closure,
        Collection $runs,
        Collection $assignments,
        bool $canCode,
        int $actingAssignmentId,
    ): array {
        /** @var CodingSuggestionRun|null $latestRun */
        $latestRun = $runs->first();
        /** @var CodingAssignment|null $latestAssignment */
        $latestAssignment = $assignments->first();
        $canStartAssignment = $canCode
            && $closure?->getKey() === $procedure->encounter_closure_id
            && (! $latestAssignment || $latestAssignment->status === CodingAssignmentStatus::ChangesRequested);

        return [
            'publicId' => $procedure->public_id,
            'sourceType' => [
                'code' => CodingSourceType::Procedure->value,
                'label' => CodingSourceType::Procedure->label(),
            ],
            'terminologySystem' => TerminologySystem::Icd9Cm->value,
            'correctionSupported' => true,
            'authoredText' => $procedure->authored_text,
            'certainty' => null,
            'role' => null,
            'clinicalStatus' => $procedure->status->value,
            'clinicianCode' => null,
            'sourceVersion' => [
                'publicId' => $closure?->public_id,
                'versionNumber' => $closure?->version_number,
                'schemaVersion' => $closure?->schema_version,
                'status' => $closure ? [
                    'code' => $closure->status->value,
                    'label' => $closure->status->label(),
                ] : null,
                'contentHash' => $procedure->content_hash,
                'documentContentHash' => $closure?->content_hash,
                'author' => $procedure->recorderAssignment->user->name,
            ],
            'procedureDetails' => [
                'performedStartAt' => $procedure->performed_start_at->toIso8601String(),
                'performedEndAt' => $procedure->performed_end_at?->toIso8601String(),
                'performerText' => $procedure->performer_text,
                'bodySiteText' => $procedure->body_site_text,
                'outcomeText' => $procedure->outcome_text,
                'basedOnServiceRequestPublicId' => $procedure->basedOnServiceRequest?->public_id,
            ],
            'canGenerate' => $canStartAssignment,
            'suggestionUrl' => route('encounters.procedures.coding-suggestions.store', [
                'encounter' => $encounter,
                'procedure' => $procedure,
            ]),
            'latestRun' => $latestRun ? $this->runPayload($latestRun, $canStartAssignment) : null,
            'assignmentHistory' => $assignments->map(fn (CodingAssignment $coding): array => $this->assignmentPayload(
                coding: $coding,
                actingAssignmentId: $actingAssignmentId,
            ))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function runPayload(CodingSuggestionRun $run, bool $canDecide): array
    {
        return [
            'publicId' => $run->public_id,
            'sourceType' => [
                'code' => $run->source_type->value,
                'label' => $run->source_type->label(),
            ],
            'outcome' => ['code' => $run->outcome->value, 'label' => $run->outcome->label()],
            'engineType' => $run->engine_type,
            'engineVersion' => $run->engine_version,
            'configurationHash' => $run->configuration_hash,
            'normalizedInputHash' => $run->normalized_input_hash,
            'generatedAt' => $run->generated_at->toIso8601String(),
            'release' => [
                'publicId' => $run->release->public_id,
                'system' => $run->release->classification_system->label(),
                'logicalVersion' => $run->release->logical_version,
                'sourceSha256' => $run->release->source_sha256,
            ],
            'canDecide' => $canDecide,
            'decisionUrl' => route('coding-suggestion-runs.decisions.store', $run),
            'candidates' => $run->candidates->map(fn (CodingSuggestionCandidate $candidate): array => [
                'publicId' => $candidate->public_id,
                'rank' => $candidate->rank,
                'concept' => $this->conceptPayload($candidate->concept),
                'confidence' => ['code' => $candidate->confidence_band->value, 'label' => $candidate->confidence_band->label()],
                'score' => $candidate->score,
                'evidence' => $candidate->evidence,
                'specificityWarning' => $candidate->specificity_warning,
            ])->values()->all(),
            'decisions' => $run->decisions->map(fn (CodingSuggestionDecision $decision): array => [
                'publicId' => $decision->public_id,
                'decision' => ['code' => $decision->decision->value, 'label' => $decision->decision->label()],
                'candidatePublicId' => $decision->candidate?->public_id,
                'reason' => $decision->reason,
                'resultingAssignmentPublicId' => $decision->resultingAssignment?->public_id,
                'decidedAt' => $decision->decided_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(CodingAssignment $coding, int $actingAssignmentId): array
    {
        return [
            'publicId' => $coding->public_id,
            'sourceType' => [
                'code' => $coding->source_type->value,
                'label' => $coding->source_type->label(),
            ],
            'status' => ['code' => $coding->status->value, 'label' => $coding->status->label()],
            'selectionMethod' => ['code' => $coding->selection_method->value, 'label' => $coding->selection_method->label()],
            'concept' => $this->conceptPayload($coding->concept),
            'sourceClinicalContentHash' => $coding->source_clinical_content_hash,
            'sourceStatementHash' => $coding->source_statement_hash,
            'terminologySourceHash' => $coding->terminology_source_hash,
            'contentHash' => $coding->content_hash,
            'rationale' => $coding->rationale,
            'changeReason' => $coding->change_reason,
            'coder' => $coding->coder->name,
            'recordedAt' => $coding->recorded_at->toIso8601String(),
            'submittedAt' => $coding->submitted_at?->toIso8601String(),
            'reviewedAt' => $coding->reviewed_at?->toIso8601String(),
            'canSubmit' => $coding->status === CodingAssignmentStatus::Draft
                && $coding->coder_assignment_id === $actingAssignmentId,
            'canReview' => $coding->status === CodingAssignmentStatus::Submitted
                && $coding->coderAssignment->supervisor_assignment_id === $actingAssignmentId,
            'submitUrl' => route('coding-assignments.submit', $coding),
            'reviewUrl' => route('coding-assignments.reviews.store', $coding),
            'reviews' => $coding->reviewActions->map(fn ($review): array => [
                'publicId' => $review->public_id,
                'action' => ['code' => $review->action->value, 'label' => $review->action->label()],
                'reviewer' => $review->reviewer->name,
                'comment' => $review->comment,
                'findings' => $review->findings,
                'reviewedContentHash' => $review->reviewed_content_hash,
                'reviewedAt' => $review->reviewed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function conceptPayload(TerminologyConcept $concept): array
    {
        return [
            'publicId' => $concept->public_id,
            'code' => $concept->code,
            'display' => $concept->display,
        ];
    }
}
