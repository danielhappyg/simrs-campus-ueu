<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\ObservationStatus;
use App\Modules\Clinical\Enums\ObservationValueType;
use App\Modules\Clinical\Enums\ServiceRequestStatus;
use App\Modules\Clinical\Models\AllergyAssessment;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalObservation;
use App\Modules\Clinical\Models\ClinicalReviewActionModel;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Clinical\Support\MedicalAssessmentDefinition;
use App\Modules\Clinical\Support\NursingIntakeDefinition;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Services\CodingInvalidationService;
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

class ClinicalDocumentationService
{
    public function __construct(
        private readonly EncounterTransitionService $encounterTransitionService,
        private readonly CodingInvalidationService $codingInvalidationService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveNursingIntake(
        Encounter $encounter,
        Assignment $authorAssignment,
        array $payload,
    ): ClinicalEntryVersion {
        return DB::transaction(function () use ($encounter, $authorAssignment, $payload): ClinicalEntryVersion {
            $intent = ClinicalSaveIntent::from((string) $payload['intent']);
            $requestKey = (string) $payload['request_key'];
            $lockedEncounter = Encounter::query()
                ->with(['patient', 'session'])
                ->whereKey($encounter->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $session = SimulationSession::query()
                ->whereKey($lockedEncounter->session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $activeAuthor = Assignment::query()
                ->active()
                ->whereKey($authorAssignment->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertClinicalAssignment(
                assignment: $activeAuthor,
                encounter: $lockedEncounter,
                session: $session,
                capability: Capability::IntakeWrite,
            );

            if (! in_array($lockedEncounter->status, [EncounterStatus::Arrived, EncounterStatus::InIntake], true)) {
                throw new DomainException('Nursing intake can only be documented after arrival and before clinician handoff.');
            }

            $existing = ClinicalEntryVersion::query()
                ->with(['clinicalEntry', 'observations', 'allergyAssessment', 'reviewActions'])
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->clinicalEntry->encounter_id !== $lockedEncounter->getKey()
                    || $existing->author_assignment_id !== $activeAuthor->getKey()
                    || $existing->creation_intent !== $intent) {
                    throw new DomainException('The clinical request key was already used for a different context or intent.');
                }

                return $existing;
            }

            $entry = ClinicalEntry::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->where('document_type', ClinicalDocumentType::NursingIntake)
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                $entry = ClinicalEntry::query()->create([
                    'session_id' => $lockedEncounter->session_id,
                    'patient_id' => $lockedEncounter->patient_id,
                    'encounter_id' => $lockedEncounter->getKey(),
                    'created_by_assignment_id' => $activeAuthor->getKey(),
                    'document_type' => ClinicalDocumentType::NursingIntake,
                    'sensitivity_class' => 'STANDARD_CLINICAL',
                    'lifecycle_status' => ClinicalEntryStatus::Draft,
                ]);
            }

            if ($entry->created_by_assignment_id !== $activeAuthor->getKey()) {
                throw new DomainException('Only the assigned clinical-entry author can create its successor versions.');
            }

            $latest = ClinicalEntryVersion::query()
                ->where('clinical_entry_id', $entry->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if ($latest && in_array($latest->status, [ClinicalEntryStatus::Submitted, ClinicalEntryStatus::Approved], true)) {
                throw new DomainException('Submitted or approved content cannot be overwritten; use its review or amendment path.');
            }

            $changeReason = $this->nullableText($payload['change_reason'] ?? null);

            if ($latest?->status === ClinicalEntryStatus::ChangesRequested && $changeReason === null) {
                throw new DomainException('A correction version requires a change reason.');
            }

            if ($entry->lifecycle_status === ClinicalEntryStatus::ChangesRequested) {
                $entry->persistLifecycleStatus(ClinicalEntryStatus::Draft);
            }

            $content = NursingIntakeDefinition::buildContent($payload);
            $recordedAt = CarbonImmutable::now();
            $nextVersionNumber = $latest === null ? 1 : $latest->version_number + 1;
            $version = ClinicalEntryVersion::query()->create([
                'request_key' => $requestKey,
                'clinical_entry_id' => $entry->getKey(),
                'version_number' => $nextVersionNumber,
                'creation_intent' => $intent,
                'schema_version' => ClinicalDocumentType::NursingIntake->schemaVersion(),
                'content' => $content,
                'content_hash' => hash('sha256', CanonicalJson::encode($content)),
                'author_user_id' => $activeAuthor->user_id,
                'author_assignment_id' => $activeAuthor->getKey(),
                'clinical_occurrence_at' => CarbonImmutable::parse((string) $payload['clinical_occurrence_at']),
                'recorded_at' => $recordedAt,
                'status' => ClinicalEntryStatus::Draft,
                'change_reason' => $changeReason,
                'supersedes_version_id' => $latest?->getKey(),
            ]);

            $observationStatus = $intent === ClinicalSaveIntent::Submit
                ? ObservationStatus::Final
                : ObservationStatus::Preliminary;
            $this->persistNursingObservations($version, $activeAuthor, $content, $observationStatus, $recordedAt);
            $this->persistAllergyAssessment($version, $activeAuthor, $content, $recordedAt);

            if ($lockedEncounter->status === EncounterStatus::Arrived) {
                $lockedEncounter = $this->encounterTransitionService->transition(
                    $lockedEncounter,
                    EncounterStatus::InIntake,
                    $activeAuthor,
                    'nursing_intake_started',
                );
            }

            $this->updateAuthorTask(
                assignment: $activeAuthor,
                encounter: $lockedEncounter,
                documentType: ClinicalDocumentType::NursingIntake,
                status: WorkTaskStatus::InProgress,
                entry: $entry,
                version: $version,
            );

            $this->auditRecorder->record(
                action: 'clinical.nursing_intake_version_created',
                resourceType: 'clinical_entry_version',
                resourceId: $version->public_id,
                actor: $activeAuthor->user,
                assignment: $activeAuthor,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'document_type' => ClinicalDocumentType::NursingIntake->value,
                    'version_number' => $version->version_number,
                    'schema_version' => $version->schema_version,
                    'content_hash' => $version->content_hash,
                    'intent' => $intent->value,
                ],
            );

            if ($intent === ClinicalSaveIntent::Submit) {
                $this->submitLocked($version, $entry, $activeAuthor, $session, $lockedEncounter, $requestKey);
            }

            return $version->refresh()->load(['clinicalEntry', 'observations', 'allergyAssessment', 'reviewActions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveMedicalAssessment(
        Encounter $encounter,
        Assignment $authorAssignment,
        array $payload,
    ): ClinicalEntryVersion {
        return DB::transaction(function () use ($encounter, $authorAssignment, $payload): ClinicalEntryVersion {
            $intent = ClinicalSaveIntent::from((string) $payload['intent']);
            $requestKey = (string) $payload['request_key'];
            $lockedEncounter = Encounter::query()
                ->with(['patient', 'session'])
                ->whereKey($encounter->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $session = SimulationSession::query()
                ->whereKey($lockedEncounter->session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $activeAuthor = Assignment::query()
                ->active()
                ->whereKey($authorAssignment->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertClinicalAssignment(
                assignment: $activeAuthor,
                encounter: $lockedEncounter,
                session: $session,
                capability: Capability::MedicalAssessmentWrite,
            );

            $amendmentMode = $lockedEncounter->status === EncounterStatus::AmendmentPending;
            $codingCorrection = $amendmentMode
                ? CodingDocumentationCorrection::query()
                    ->where('encounter_id', $lockedEncounter->getKey())
                    ->where('responsible_assignment_id', $activeAuthor->getKey())
                    ->whereIn('status', [
                        CodingDocumentationCorrectionStatus::Open->value,
                        CodingDocumentationCorrectionStatus::MedicalResponseSubmitted->value,
                    ])
                    ->with(['sourceEntryVersion', 'sourceCondition'])
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! in_array($lockedEncounter->status, [
                EncounterStatus::WaitingClinician,
                EncounterStatus::InConsultation,
                EncounterStatus::AmendmentPending,
            ], true)) {
                throw new DomainException('Medical assessment can begin only after the approved nursing handoff.');
            }

            if ($amendmentMode && ! $codingCorrection) {
                throw new DomainException('A post-coding medical amendment requires an open correction assigned to this exact author.');
            }

            $existing = ClinicalEntryVersion::query()
                ->with(['clinicalEntry', 'conditions', 'serviceRequests', 'medicationRequests', 'reviewActions'])
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->clinicalEntry->encounter_id !== $lockedEncounter->getKey()
                    || $existing->author_assignment_id !== $activeAuthor->getKey()
                    || $existing->creation_intent !== $intent
                    || $existing->clinicalEntry->document_type !== ClinicalDocumentType::MedicalAssessment) {
                    throw new DomainException('The clinical request key was already used for a different context or intent.');
                }

                return $existing;
            }

            $entry = ClinicalEntry::query()
                ->where('encounter_id', $lockedEncounter->getKey())
                ->where('document_type', ClinicalDocumentType::MedicalAssessment)
                ->lockForUpdate()
                ->first();

            if (! $entry && $amendmentMode) {
                throw new DomainException('A coding correction cannot create a missing medical assessment.');
            }

            if (! $entry) {
                $entry = ClinicalEntry::query()->create([
                    'session_id' => $lockedEncounter->session_id,
                    'patient_id' => $lockedEncounter->patient_id,
                    'encounter_id' => $lockedEncounter->getKey(),
                    'created_by_assignment_id' => $activeAuthor->getKey(),
                    'document_type' => ClinicalDocumentType::MedicalAssessment,
                    'sensitivity_class' => 'STANDARD_CLINICAL',
                    'lifecycle_status' => ClinicalEntryStatus::Draft,
                ]);
            }

            if ($entry->created_by_assignment_id !== $activeAuthor->getKey()) {
                throw new DomainException('Only the assigned clinical-entry author can create its successor versions.');
            }

            $latest = ClinicalEntryVersion::query()
                ->where('clinical_entry_id', $entry->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if ($latest?->status === ClinicalEntryStatus::Submitted) {
                throw new DomainException('Submitted or approved content cannot be overwritten; use its review or amendment path.');
            }

            if ($latest?->status === ClinicalEntryStatus::Approved && ! $amendmentMode) {
                throw new DomainException('Submitted or approved content cannot be overwritten; use its review or amendment path.');
            }

            if ($amendmentMode
                && $codingCorrection->status === CodingDocumentationCorrectionStatus::Open
                && (($latest->status === ClinicalEntryStatus::Approved
                    && $latest->getKey() !== $codingCorrection->source_entry_version_id)
                    || ! in_array($latest->status, [ClinicalEntryStatus::Approved, ClinicalEntryStatus::Draft], true))) {
                throw new DomainException('The coding correction no longer points to the current approved medical version.');
            }

            $changeReason = $this->nullableText($payload['change_reason'] ?? null);

            if (($latest?->status === ClinicalEntryStatus::ChangesRequested || $amendmentMode) && $changeReason === null) {
                throw new DomainException('A correction version requires a change reason.');
            }

            if ($entry->lifecycle_status === ClinicalEntryStatus::ChangesRequested) {
                $entry->persistLifecycleStatus(ClinicalEntryStatus::Draft);
            }

            if ($amendmentMode && $latest?->status === ClinicalEntryStatus::Approved) {
                $latest->persistStatus(ClinicalEntryStatus::Amended, reviewedAt: CarbonImmutable::now());
                $entry->persistLifecycleStatus(ClinicalEntryStatus::Amended);
                $entry->persistLifecycleStatus(ClinicalEntryStatus::Draft);
            }

            $content = MedicalAssessmentDefinition::buildContent($payload);
            $occurrenceAt = CarbonImmutable::parse((string) $payload['clinical_occurrence_at']);

            if ($amendmentMode) {
                $sourceContent = $codingCorrection->sourceEntryVersion->content;

                if (CanonicalJson::encode($content['serviceRequests']) !== CanonicalJson::encode($sourceContent['serviceRequests'] ?? [])
                    || CanonicalJson::encode($content['medicationRequests']) !== CanonicalJson::encode($sourceContent['medicationRequests'] ?? [])) {
                    throw new DomainException('A coding documentation amendment cannot add, remove, or alter operational orders or prescriptions.');
                }

                if (! $occurrenceAt->equalTo($codingCorrection->sourceEntryVersion->clinical_occurrence_at)) {
                    throw new DomainException('A documentation amendment must preserve the original clinical occurrence time.');
                }
            }

            $recordedAt = CarbonImmutable::now();
            $nextVersionNumber = $latest === null ? 1 : $latest->version_number + 1;
            $version = ClinicalEntryVersion::query()->create([
                'request_key' => $requestKey,
                'clinical_entry_id' => $entry->getKey(),
                'version_number' => $nextVersionNumber,
                'creation_intent' => $intent,
                'schema_version' => ClinicalDocumentType::MedicalAssessment->schemaVersion(),
                'content' => $content,
                'content_hash' => hash('sha256', CanonicalJson::encode($content)),
                'author_user_id' => $activeAuthor->user_id,
                'author_assignment_id' => $activeAuthor->getKey(),
                'clinical_occurrence_at' => $occurrenceAt,
                'recorded_at' => $recordedAt,
                'status' => ClinicalEntryStatus::Draft,
                'change_reason' => $changeReason,
                'supersedes_version_id' => $latest?->getKey(),
            ]);

            $this->persistMedicalConditions($version, $activeAuthor, $content, $recordedAt);

            if (! $amendmentMode) {
                $this->persistMedicalRequests($version, $activeAuthor, $content, $recordedAt);
            }

            if ($lockedEncounter->status === EncounterStatus::WaitingClinician) {
                $lockedEncounter = $this->encounterTransitionService->transition(
                    $lockedEncounter,
                    EncounterStatus::InConsultation,
                    $activeAuthor,
                    'medical_assessment_started',
                );
            }

            $this->updateAuthorTask(
                assignment: $activeAuthor,
                encounter: $lockedEncounter,
                documentType: ClinicalDocumentType::MedicalAssessment,
                status: WorkTaskStatus::InProgress,
                entry: $entry,
                version: $version,
            );

            if ($codingCorrection) {
                $this->updateCodingCorrectionTask(
                    correction: $codingCorrection,
                    encounter: $lockedEncounter,
                    version: $version,
                    status: WorkTaskStatus::InProgress,
                );
            }

            $this->auditRecorder->record(
                action: 'clinical.medical_assessment_version_created',
                resourceType: 'clinical_entry_version',
                resourceId: $version->public_id,
                actor: $activeAuthor->user,
                assignment: $activeAuthor,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'document_type' => ClinicalDocumentType::MedicalAssessment->value,
                    'version_number' => $version->version_number,
                    'schema_version' => $version->schema_version,
                    'content_hash' => $version->content_hash,
                    'intent' => $intent->value,
                    'condition_count' => $version->conditions()->count(),
                    'service_request_count' => $version->serviceRequests()->count(),
                    'medication_request_count' => $version->medicationRequests()->count(),
                    'coding_documentation_correction_public_id' => $codingCorrection?->public_id,
                ],
            );

            if ($intent === ClinicalSaveIntent::Submit) {
                $this->submitLocked($version, $entry, $activeAuthor, $session, $lockedEncounter, $requestKey);
                $codingCorrection?->persistMedicalResponse($version->refresh());

                if ($codingCorrection) {
                    $this->updateCodingCorrectionTask(
                        correction: $codingCorrection,
                        encounter: $lockedEncounter,
                        version: $version,
                        status: WorkTaskStatus::Submitted,
                    );
                }
            }

            return $version->refresh()->load([
                'clinicalEntry',
                'conditions',
                'serviceRequests',
                'medicationRequests',
                'reviewActions',
            ]);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    public function review(
        ClinicalEntryVersion $version,
        Assignment $reviewerAssignment,
        ClinicalReviewAction $decision,
        string $requestKey,
        ?string $comment,
        array $findings = [],
    ): ClinicalEntryVersion {
        if (! in_array($decision, [ClinicalReviewAction::RequestChanges, ClinicalReviewAction::ApproveSimulation], true)) {
            throw new DomainException('The requested clinical review decision is not supported.');
        }

        return DB::transaction(function () use ($version, $reviewerAssignment, $decision, $requestKey, $comment, $findings): ClinicalEntryVersion {
            $existing = ClinicalReviewActionModel::query()
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->clinical_entry_version_id !== $version->getKey()
                    || $existing->actor_assignment_id !== $reviewerAssignment->getKey()
                    || $existing->action !== $decision) {
                    throw new DomainException('The clinical review request key was already used for another decision.');
                }

                return ClinicalEntryVersion::query()
                    ->with(['clinicalEntry', 'observations', 'conditions', 'allergyAssessment', 'reviewActions.actorAssignment.user'])
                    ->findOrFail($version->getKey());
            }

            $lockedVersion = ClinicalEntryVersion::query()
                ->with(['clinicalEntry.encounter.patient', 'clinicalEntry.encounter.session', 'authorAssignment'])
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $entry = ClinicalEntry::query()->whereKey($lockedVersion->clinical_entry_id)->lockForUpdate()->firstOrFail();
            $encounter = Encounter::query()->whereKey($entry->encounter_id)->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($entry->session_id)->lockForUpdate()->firstOrFail();
            $activeReviewer = Assignment::query()
                ->active()
                ->whereKey($reviewerAssignment->getKey())
                ->lockForUpdate()
                ->first();
            $codingCorrection = $encounter->status === EncounterStatus::AmendmentPending
                && $entry->document_type === ClinicalDocumentType::MedicalAssessment
                ? CodingDocumentationCorrection::query()
                    ->where('encounter_id', $encounter->getKey())
                    ->where('response_medical_version_id', $lockedVersion->getKey())
                    ->where('status', CodingDocumentationCorrectionStatus::MedicalResponseSubmitted)
                    ->lockForUpdate()
                    ->first()
                : null;

            $this->assertClinicalAssignment(
                assignment: $activeReviewer,
                encounter: $encounter,
                session: $session,
                capability: Capability::SupervisionReview,
            );

            if ($lockedVersion->authorAssignment->supervisor_assignment_id !== $activeReviewer->getKey()) {
                throw new DomainException('This supervisor assignment is not linked to the submitted author assignment.');
            }

            if ($encounter->status === EncounterStatus::AmendmentPending
                && $entry->document_type === ClinicalDocumentType::MedicalAssessment
                && ! $codingCorrection) {
                throw new DomainException('The submitted medical amendment is not linked to an active coding correction.');
            }

            if ($lockedVersion->status !== ClinicalEntryStatus::Submitted
                || $entry->lifecycle_status !== ClinicalEntryStatus::Submitted) {
                throw new DomainException('Only the currently submitted clinical version can be reviewed.');
            }

            $reviewedAt = CarbonImmutable::now();
            ClinicalReviewActionModel::query()->create([
                'request_key' => $requestKey,
                'clinical_entry_version_id' => $lockedVersion->getKey(),
                'actor_user_id' => $activeReviewer->user_id,
                'actor_assignment_id' => $activeReviewer->getKey(),
                'action' => $decision,
                'findings' => $findings === [] ? null : $findings,
                'comment' => $this->nullableText($comment),
                'reviewed_content_hash' => $lockedVersion->content_hash,
                'occurred_at' => $reviewedAt,
            ]);

            $targetStatus = $decision === ClinicalReviewAction::ApproveSimulation
                ? ClinicalEntryStatus::Approved
                : ClinicalEntryStatus::ChangesRequested;
            $lockedVersion->persistStatus($targetStatus, reviewedAt: $reviewedAt);
            $entry->persistLifecycleStatus($targetStatus);

            $this->updateAuthorTask(
                assignment: $lockedVersion->authorAssignment,
                encounter: $encounter,
                documentType: $entry->document_type,
                status: $decision === ClinicalReviewAction::ApproveSimulation
                    ? WorkTaskStatus::Complete
                    : WorkTaskStatus::ChangesRequested,
                entry: $entry,
                version: $lockedVersion,
            );
            $this->completeReviewTask($activeReviewer, $encounter, $lockedVersion);

            if ($codingCorrection) {
                $this->updateCodingCorrectionTask(
                    correction: $codingCorrection,
                    encounter: $encounter,
                    version: $lockedVersion,
                    status: $decision === ClinicalReviewAction::ApproveSimulation
                        ? WorkTaskStatus::Complete
                        : WorkTaskStatus::ChangesRequested,
                );
            }

            $releasedDownstreamTasks = 0;
            $invalidatedCodingAssignments = 0;

            if ($decision === ClinicalReviewAction::ApproveSimulation) {
                if ($codingCorrection) {
                    $invalidatedCodingAssignments = $this->codingInvalidationService
                        ->invalidateForMedicalAmendment($encounter, $lockedVersion);
                    $codingCorrection->persistMedicalApproved($lockedVersion);
                    $releasedDownstreamTasks = $this->advanceAfterCodingDocumentationApproval(
                        correction: $codingCorrection,
                        version: $lockedVersion,
                        encounter: $encounter,
                        session: $session,
                    );
                } else {
                    $releasedDownstreamTasks = match ($entry->document_type) {
                        ClinicalDocumentType::NursingIntake => $this->advanceAfterNursingApproval(
                            version: $lockedVersion,
                            reviewer: $activeReviewer,
                            encounter: $encounter,
                        ),
                        ClinicalDocumentType::MedicalAssessment => $this->advanceAfterMedicalApproval(
                            version: $lockedVersion,
                            reviewer: $activeReviewer,
                            encounter: $encounter,
                            session: $session,
                        ),
                    };
                }
            }

            $this->auditRecorder->record(
                action: $decision === ClinicalReviewAction::ApproveSimulation
                    ? 'clinical.version_approved_for_simulation'
                    : 'clinical.version_changes_requested',
                resourceType: 'clinical_entry_version',
                resourceId: $lockedVersion->public_id,
                actor: $activeReviewer->user,
                assignment: $activeReviewer,
                session: $session,
                encounter: $encounter,
                metadata: [
                    'document_type' => $entry->document_type->value,
                    'version_number' => $lockedVersion->version_number,
                    'content_hash' => $lockedVersion->content_hash,
                    'finding_count' => count($findings),
                    'downstream_tasks_released' => $releasedDownstreamTasks,
                    'coding_documentation_correction_public_id' => $codingCorrection?->public_id,
                    'stale_coding_assignments_invalidated' => $invalidatedCodingAssignments,
                ],
            );

            return $lockedVersion->refresh()->load([
                'clinicalEntry',
                'observations',
                'conditions',
                'allergyAssessment',
                'reviewActions.actorAssignment.user',
            ]);
        });
    }

    private function submitLocked(
        ClinicalEntryVersion $version,
        ClinicalEntry $entry,
        Assignment $author,
        SimulationSession $session,
        Encounter $encounter,
        string $requestKey,
    ): void {
        if ($version->status !== ClinicalEntryStatus::Draft || $entry->lifecycle_status !== ClinicalEntryStatus::Draft) {
            throw new DomainException('Only the current draft clinical version can be submitted.');
        }

        if ($author->supervisor_assignment_id === null) {
            throw new DomainException('A linked supervisor assignment is required before clinical submission.');
        }

        $supervisor = Assignment::query()
            ->active()
            ->whereKey($author->supervisor_assignment_id)
            ->lockForUpdate()
            ->first();
        $this->assertClinicalAssignment(
            assignment: $supervisor,
            encounter: $encounter,
            session: $session,
            capability: Capability::SupervisionReview,
        );

        $submittedAt = CarbonImmutable::now();
        $version->persistStatus(ClinicalEntryStatus::Submitted, submittedAt: $submittedAt);
        $entry->persistLifecycleStatus(ClinicalEntryStatus::Submitted);

        ClinicalReviewActionModel::query()->create([
            'request_key' => $requestKey,
            'clinical_entry_version_id' => $version->getKey(),
            'actor_user_id' => $author->user_id,
            'actor_assignment_id' => $author->getKey(),
            'action' => ClinicalReviewAction::Submit,
            'findings' => null,
            'comment' => null,
            'reviewed_content_hash' => $version->content_hash,
            'occurred_at' => $submittedAt,
        ]);

        $this->updateAuthorTask(
            assignment: $author,
            encounter: $encounter,
            documentType: $entry->document_type,
            status: WorkTaskStatus::Submitted,
            entry: $entry,
            version: $version,
        );

        WorkTask::query()->create([
            'session_id' => $session->getKey(),
            'assignment_id' => $supervisor->getKey(),
            'encounter_id' => $encounter->getKey(),
            'clinical_entry_id' => $entry->getKey(),
            'clinical_entry_version_id' => $version->getKey(),
            'task_type' => WorkTaskType::SupervisorReview,
            'title' => 'Tinjau '.$entry->document_type->label().' v'.$version->version_number,
            'description' => 'Tinjau versi dan hash yang diajukan. Persetujuan berlaku hanya untuk versi ini dan merupakan persetujuan simulasi.',
            'status' => WorkTaskStatus::Ready,
            'priority' => 1,
            'source_program' => $author->program,
            'context' => [
                'caseLabel' => $encounter->encounter_number,
                'synthetic' => true,
                'clinicalEntryPublicId' => $entry->public_id,
                'clinicalEntryVersionPublicId' => $version->public_id,
                'contentHash' => $version->content_hash,
            ],
            'available_at' => $submittedAt,
        ]);

        $this->auditRecorder->record(
            action: 'clinical.version_submitted',
            resourceType: 'clinical_entry_version',
            resourceId: $version->public_id,
            actor: $author->user,
            assignment: $author,
            session: $session,
            encounter: $encounter,
            metadata: [
                'document_type' => $entry->document_type->value,
                'version_number' => $version->version_number,
                'content_hash' => $version->content_hash,
                'supervisor_assignment_public_id' => $supervisor->public_id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function persistNursingObservations(
        ClinicalEntryVersion $version,
        Assignment $author,
        array $content,
        ObservationStatus $status,
        CarbonImmutable $recordedAt,
    ): void {
        /** @var array<string, array<string, mixed>> $vitals */
        $vitals = $content['vitalObservations'];

        foreach ($vitals as $vital) {
            if ($vital['value'] === null) {
                continue;
            }

            ClinicalObservation::query()->create([
                'source_entry_version_id' => $version->getKey(),
                'patient_id' => $version->clinicalEntry->patient_id,
                'encounter_id' => $version->clinicalEntry->encounter_id,
                'author_assignment_id' => $author->getKey(),
                'category' => 'VITAL_SIGNS',
                'code_system' => $vital['codeSystem'],
                'code' => $vital['code'],
                'display' => $vital['display'],
                'code_version' => $vital['codeVersion'],
                'mapping_version' => $vital['mappingVersion'],
                'value_type' => ObservationValueType::Quantity,
                'value_numeric' => $vital['value'],
                'unit_system' => $vital['unitSystem'],
                'unit_code' => $vital['unitCode'],
                'unit_display' => $vital['unitDisplay'],
                'occurrence_at' => $version->clinical_occurrence_at,
                'recorded_at' => $recordedAt,
                'status' => $status,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function persistAllergyAssessment(
        ClinicalEntryVersion $version,
        Assignment $author,
        array $content,
        CarbonImmutable $recordedAt,
    ): void {
        /** @var array{state: string|null, details: string|null} $allergy */
        $allergy = $content['allergyAssessment'];

        if ($allergy['state'] === null) {
            return;
        }

        $state = AllergyAssessmentState::from($allergy['state']);

        if ($state === AllergyAssessmentState::KnownAllergy && $allergy['details'] === null) {
            return;
        }

        AllergyAssessment::query()->create([
            'source_entry_version_id' => $version->getKey(),
            'patient_id' => $version->clinicalEntry->patient_id,
            'encounter_id' => $version->clinicalEntry->encounter_id,
            'author_assignment_id' => $author->getKey(),
            'assessment_state' => $state,
            'details' => $allergy['details'],
            'assessed_at' => $recordedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function persistMedicalConditions(
        ClinicalEntryVersion $version,
        Assignment $author,
        array $content,
        CarbonImmutable $recordedAt,
    ): void {
        /** @var array<int, array{authoredText: string, certainty: string|null, role: string|null, onsetAt: string|null}> $diagnoses */
        $diagnoses = $content['diagnoses'];

        foreach ($diagnoses as $diagnosis) {
            $certainty = is_string($diagnosis['certainty'])
                ? DiagnosisCertainty::tryFrom($diagnosis['certainty'])
                : null;
            $role = is_string($diagnosis['role'])
                ? DiagnosisRole::tryFrom($diagnosis['role'])
                : null;

            if (! $certainty || ! $role) {
                continue;
            }

            ClinicalCondition::query()->create([
                'source_entry_version_id' => $version->getKey(),
                'patient_id' => $version->clinicalEntry->patient_id,
                'encounter_id' => $version->clinicalEntry->encounter_id,
                'author_assignment_id' => $author->getKey(),
                'authored_text' => $diagnosis['authoredText'],
                'certainty' => $certainty,
                'role' => $role,
                'clinical_status' => 'ACTIVE',
                'code_system' => null,
                'code' => null,
                'display' => null,
                'code_version' => null,
                'onset_at' => $diagnosis['onsetAt'] === null
                    ? null
                    : CarbonImmutable::parse($diagnosis['onsetAt']),
                'recorded_at' => $recordedAt,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function persistMedicalRequests(
        ClinicalEntryVersion $version,
        Assignment $author,
        array $content,
        CarbonImmutable $recordedAt,
    ): void {
        $conditions = $version->conditions()->orderBy('id')->get();
        /** @var array<int, array{requestType: string|null, authoredService: string, clinicalQuestion: string|null, priority: string|null, sourceDiagnosisIndex: int|null}> $serviceRequests */
        $serviceRequests = $content['serviceRequests'];

        foreach ($serviceRequests as $index => $request) {
            if ($request['requestType'] === null
                || $request['clinicalQuestion'] === null
                || $request['priority'] === null) {
                throw new DomainException('A service request requires its type, authored service, question, and priority.');
            }

            $sourceCondition = $request['sourceDiagnosisIndex'] === null
                ? null
                : $conditions->get($request['sourceDiagnosisIndex']);

            if ($request['sourceDiagnosisIndex'] !== null && ! $sourceCondition) {
                throw new DomainException('A service request source diagnosis must exist in the same medical version.');
            }

            ServiceRequest::query()->create([
                'session_id' => $version->clinicalEntry->session_id,
                'patient_id' => $version->clinicalEntry->patient_id,
                'encounter_id' => $version->clinicalEntry->encounter_id,
                'source_entry_version_id' => $version->getKey(),
                'source_condition_id' => $sourceCondition?->getKey(),
                'requester_user_id' => $author->user_id,
                'requester_assignment_id' => $author->getKey(),
                'sequence_number' => $index + 1,
                'request_type' => $request['requestType'],
                'authored_service' => $request['authoredService'],
                'clinical_question' => $request['clinicalQuestion'],
                'priority' => $request['priority'],
                'status' => ServiceRequestStatus::Draft,
                'authored_at' => $recordedAt,
            ]);
        }

        /** @var array<int, array{authoredMedication: string, form: string|null, strength: string|null, doseValue: string|null, doseUnit: string|null, route: string|null, frequency: string|null, duration: string|null, quantityValue: string|null, quantityUnit: string|null, directions: string|null, indicationText: string|null, sourceDiagnosisIndex: int|null}> $medicationRequests */
        $medicationRequests = $content['medicationRequests'];

        foreach ($medicationRequests as $index => $request) {
            if ($request['doseValue'] === null
                || $request['doseUnit'] === null
                || $request['route'] === null
                || $request['frequency'] === null
                || $request['duration'] === null
                || $request['quantityValue'] === null
                || $request['quantityUnit'] === null
                || $request['directions'] === null) {
                throw new DomainException('A medication request requires dose, route, frequency, duration, quantity, and directions.');
            }

            $sourceCondition = $request['sourceDiagnosisIndex'] === null
                ? null
                : $conditions->get($request['sourceDiagnosisIndex']);

            if ($request['sourceDiagnosisIndex'] !== null && ! $sourceCondition) {
                throw new DomainException('A medication request source diagnosis must exist in the same medical version.');
            }

            MedicationRequest::query()->create([
                'session_id' => $version->clinicalEntry->session_id,
                'patient_id' => $version->clinicalEntry->patient_id,
                'encounter_id' => $version->clinicalEntry->encounter_id,
                'source_entry_version_id' => $version->getKey(),
                'source_condition_id' => $sourceCondition?->getKey(),
                'replaces_medication_request_id' => null,
                'requester_user_id' => $author->user_id,
                'requester_assignment_id' => $author->getKey(),
                'sequence_number' => $index + 1,
                'revision_number' => 1,
                'authored_medication' => $request['authoredMedication'],
                'form' => $request['form'],
                'strength' => $request['strength'],
                'dose_value' => $request['doseValue'],
                'dose_unit' => $request['doseUnit'],
                'route' => $request['route'],
                'frequency' => $request['frequency'],
                'duration' => $request['duration'],
                'quantity_value' => $request['quantityValue'],
                'quantity_unit' => $request['quantityUnit'],
                'directions' => $request['directions'],
                'indication_text' => $request['indicationText'],
                'replacement_reason' => null,
                'cancellation_reason' => null,
                'status' => MedicationRequestStatus::Draft,
                'authored_at' => $recordedAt,
            ]);
        }
    }

    private function updateAuthorTask(
        Assignment $assignment,
        Encounter $encounter,
        ClinicalDocumentType $documentType,
        WorkTaskStatus $status,
        ClinicalEntry $entry,
        ClinicalEntryVersion $version,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $assignment->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', $documentType->workTaskType())
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The assigned clinical author has no matching work task.');
        }

        $task->fill([
            'clinical_entry_id' => $entry->getKey(),
            'clinical_entry_version_id' => $version->getKey(),
            'status' => $status,
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
            'context' => array_merge($task->context ?? [], [
                'clinicalEntryPublicId' => $entry->public_id,
                'clinicalEntryVersionPublicId' => $version->public_id,
                'contentHash' => $version->content_hash,
            ]),
        ])->save();
    }

    private function completeReviewTask(
        Assignment $reviewer,
        Encounter $encounter,
        ClinicalEntryVersion $version,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $reviewer->getKey())
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::SupervisorReview)
            ->where('clinical_entry_version_id', $version->getKey())
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The linked supervisor review task is missing.');
        }

        $task->forceFill([
            'status' => WorkTaskStatus::Complete,
            'completed_at' => now(),
        ])->save();
    }

    private function updateCodingCorrectionTask(
        CodingDocumentationCorrection $correction,
        Encounter $encounter,
        ClinicalEntryVersion $version,
        WorkTaskStatus $status,
    ): void {
        $task = WorkTask::query()
            ->where('assignment_id', $correction->responsible_assignment_id)
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::CodingSourceCorrection)
            ->lockForUpdate()
            ->first();

        if (! $task) {
            throw new DomainException('The assigned coding-source correction task is missing.');
        }

        $task->fill([
            'clinical_entry_id' => $version->clinical_entry_id,
            'clinical_entry_version_id' => $version->getKey(),
            'description' => $status === WorkTaskStatus::ChangesRequested
                ? 'Supervisor meminta perbaikan pada amendemen medis. Buat versi penerus dengan alasan perubahan.'
                : 'Koreksi diagnosis harus tetap berupa versi penerus; koder tidak dapat mengubah teks klinis.',
            'status' => $status,
            'context' => array_merge($task->context ?? [], [
                'responseMedicalVersionPublicId' => $version->public_id,
                'responseMedicalVersionNumber' => $version->version_number,
                'responseMedicalContentHash' => $version->content_hash,
            ]),
            'available_at' => now(),
            'completed_at' => $status === WorkTaskStatus::Complete ? now() : null,
        ])->save();
    }

    private function advanceAfterCodingDocumentationApproval(
        CodingDocumentationCorrection $correction,
        ClinicalEntryVersion $version,
        Encounter $encounter,
        SimulationSession $session,
    ): int {
        WorkTask::query()->updateOrCreate(
            [
                'assignment_id' => $correction->responsible_assignment_id,
                'encounter_id' => $encounter->getKey(),
                'task_type' => WorkTaskType::EncounterClosure,
            ],
            [
                'session_id' => $session->getKey(),
                'title' => 'Perbarui Penutupan setelah Amendemen Medis',
                'description' => 'Buat versi penutupan penerus agar ringkasan dan provenance menunjuk asesmen medis yang telah diamendemen.',
                'status' => WorkTaskStatus::Ready,
                'priority' => 1,
                'source_program' => Program::Medicine,
                'context' => [
                    'caseLabel' => $encounter->encounter_number,
                    'synthetic' => true,
                    'codingDocumentationCorrectionPublicId' => $correction->public_id,
                    'sourceMedicalVersionPublicId' => $version->public_id,
                    'sourceMedicalContentHash' => $version->content_hash,
                ],
                'available_at' => now(),
                'completed_at' => null,
            ],
        );

        return 1;
    }

    private function advanceAfterNursingApproval(
        ClinicalEntryVersion $version,
        Assignment $reviewer,
        Encounter $encounter,
    ): int {
        $decisionValue = data_get($version->content, 'safetyDecision');
        $decision = is_string($decisionValue) ? IntakeSafetyDecision::tryFrom($decisionValue) : null;

        if (! $decision) {
            throw new DomainException('An approved nursing intake requires an explicit safety decision.');
        }

        $target = $decision === IntakeSafetyDecision::EscalateToSupervisor
            ? EncounterStatus::Escalated
            : EncounterStatus::WaitingClinician;
        $encounter = $this->encounterTransitionService->transition(
            $encounter,
            $target,
            $reviewer,
            $decision === IntakeSafetyDecision::EscalateToSupervisor
                ? 'human_authored_nursing_escalation_approved'
                : 'nursing_intake_approved',
        );
        $medicalStatus = $target === EncounterStatus::Escalated
            ? WorkTaskStatus::Blocked
            : WorkTaskStatus::Ready;
        $medicalAssignments = Assignment::query()
            ->active()
            ->where('session_id', $encounter->session_id)
            ->where('patient_id', $encounter->patient_id)
            ->where('encounter_id', $encounter->getKey())
            ->get()
            ->filter(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::MedicalAssessmentWrite));

        foreach ($medicalAssignments as $medicalAssignment) {
            $task = WorkTask::query()->firstOrNew([
                'assignment_id' => $medicalAssignment->getKey(),
                'encounter_id' => $encounter->getKey(),
                'task_type' => WorkTaskType::MedicalAssessment,
            ]);
            $task->fill([
                'session_id' => $encounter->session_id,
                'title' => 'Asesmen Medis Rawat Jalan',
                'description' => $medicalStatus === WorkTaskStatus::Blocked
                    ? 'Alur rutin dihentikan karena keputusan eskalasi manusia. Menunggu keputusan supervisor/fasilitator.'
                    : 'Tinjau asesmen awal yang telah disetujui dan catat asesmen medis pada encounter yang sama.',
                'status' => $medicalStatus,
                'priority' => 1,
                'source_program' => Program::Nursing,
                'context' => [
                    'caseLabel' => $encounter->encounter_number,
                    'synthetic' => true,
                    'sourceNursingVersionPublicId' => $version->public_id,
                    'sourceNursingContentHash' => $version->content_hash,
                ],
                'available_at' => now(),
                'completed_at' => null,
            ])->save();
        }

        if ($target === EncounterStatus::Escalated) {
            $this->createSafetyDispositionTasks($version, $reviewer, $encounter);
        }

        return $medicalAssignments->count();
    }

    private function createSafetyDispositionTasks(
        ClinicalEntryVersion $version,
        Assignment $reviewer,
        Encounter $encounter,
    ): void {
        $facilitator = Assignment::query()
            ->active()
            ->where('session_id', $encounter->session_id)
            ->whereHas('user', fn ($query) => $query->whereKey($encounter->session->facilitator_user_id))
            ->get()
            ->first(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::SafetyDispositionRecord));

        $actors = collect([$reviewer, $facilitator])
            ->filter()
            ->unique(fn (Assignment $assignment): int => $assignment->getKey());

        if ($actors->isEmpty()
            || ! $actors->contains(fn (Assignment $assignment): bool => $assignment->getKey() === $reviewer->getKey())
            || ! $reviewer->hasCapability(Capability::SafetyDispositionRecord)) {
            throw new DomainException('The approved escalation requires an authorized linked supervisor disposition task.');
        }

        foreach ($actors as $actor) {
            WorkTask::query()->updateOrCreate(
                [
                    'assignment_id' => $actor->getKey(),
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::SafetyDisposition,
                ],
                [
                    'session_id' => $encounter->session_id,
                    'title' => 'Putuskan Eskalasi Alur Simulasi',
                    'description' => 'Alur rutin dihentikan. Tinjau keputusan manusia dan catat satu disposisi simulasi; sistem tidak memberi rekomendasi klinis.',
                    'status' => WorkTaskStatus::Ready,
                    'priority' => 1,
                    'source_program' => Program::Nursing,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                        'sourceNursingVersionPublicId' => $version->public_id,
                        'sourceNursingContentHash' => $version->content_hash,
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ],
            );
        }
    }

    private function advanceAfterMedicalApproval(
        ClinicalEntryVersion $version,
        Assignment $reviewer,
        Encounter $encounter,
        SimulationSession $session,
    ): int {
        $serviceRequests = $version->serviceRequests()->lockForUpdate()->get();
        $medicationRequests = $version->medicationRequests()->lockForUpdate()->get();

        foreach ($serviceRequests as $request) {
            $request->persistStatus(ServiceRequestStatus::Active);
        }

        foreach ($medicationRequests as $request) {
            $request->persistStatus(MedicationRequestStatus::Active);
        }

        if ($serviceRequests->isEmpty() && $medicationRequests->isEmpty()) {
            WorkTask::query()->updateOrCreate(
                [
                    'assignment_id' => $version->author_assignment_id,
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::EncounterClosure,
                ],
                [
                    'session_id' => $session->getKey(),
                    'title' => 'Ajukan Penutupan Encounter',
                    'description' => 'Tidak ada order hilir. Tinjau follow-up, edukasi, disposisi, dan ringkasan sebelum penutupan.',
                    'status' => WorkTaskStatus::Ready,
                    'priority' => 1,
                    'source_program' => Program::Medicine,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                        'sourceMedicalVersionPublicId' => $version->public_id,
                        'sourceMedicalContentHash' => $version->content_hash,
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ],
            );

            return 1;
        }

        $taskCount = 0;

        if ($serviceRequests->isNotEmpty()) {
            $encounter = $this->encounterTransitionService->transition(
                $encounter,
                EncounterStatus::AwaitingResult,
                $reviewer,
                'approved_medical_service_requests_activated',
            );
            $facilitatorAssignment = Assignment::query()
                ->active()
                ->where('session_id', $session->getKey())
                ->where('user_id', $session->facilitator_user_id)
                ->get()
                ->first(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::SessionFacilitate));

            if (! $facilitatorAssignment) {
                throw new DomainException('An active facilitator assignment is required to release synthetic results.');
            }

            WorkTask::query()->updateOrCreate(
                [
                    'assignment_id' => $facilitatorAssignment->getKey(),
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::SyntheticResultRelease,
                ],
                [
                    'session_id' => $session->getKey(),
                    'title' => 'Rilis Hasil Diagnostik Sintetis',
                    'description' => 'Rilis hasil yang sudah disiapkan dalam skenario. Jangan memasukkan data atau endpoint laboratorium produksi.',
                    'status' => WorkTaskStatus::Ready,
                    'priority' => 1,
                    'source_program' => Program::Medicine,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                        'sourceMedicalVersionPublicId' => $version->public_id,
                        'sourceMedicalContentHash' => $version->content_hash,
                        'serviceRequestCount' => $serviceRequests->count(),
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ],
            );
            $taskCount++;

            WorkTask::query()->updateOrCreate(
                [
                    'assignment_id' => $version->author_assignment_id,
                    'encounter_id' => $encounter->getKey(),
                    'task_type' => WorkTaskType::ResultAcknowledgement,
                ],
                [
                    'session_id' => $session->getKey(),
                    'title' => 'Akui Hasil Diagnostik Sintetis',
                    'description' => 'Menunggu hasil final atau terkoreksi dilepas oleh fasilitator.',
                    'status' => WorkTaskStatus::Waiting,
                    'priority' => 1,
                    'source_program' => Program::Facilitation,
                    'context' => [
                        'caseLabel' => $encounter->encounter_number,
                        'synthetic' => true,
                        'serviceRequestCount' => $serviceRequests->count(),
                    ],
                    'available_at' => now(),
                    'completed_at' => null,
                ],
            );
            $taskCount++;
        } else {
            $encounter = $this->encounterTransitionService->transition(
                $encounter,
                EncounterStatus::AwaitingPharmacy,
                $reviewer,
                'approved_medication_requests_activated',
            );
        }

        if ($medicationRequests->isNotEmpty()) {
            $pharmacyStatus = $serviceRequests->isEmpty()
                ? WorkTaskStatus::Ready
                : WorkTaskStatus::Waiting;
            $pharmacyAssignments = Assignment::query()
                ->active()
                ->where('session_id', $encounter->session_id)
                ->where('patient_id', $encounter->patient_id)
                ->where('encounter_id', $encounter->getKey())
                ->get()
                ->filter(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PharmacyReview));

            foreach ($pharmacyAssignments as $pharmacyAssignment) {
                WorkTask::query()->updateOrCreate(
                    [
                        'assignment_id' => $pharmacyAssignment->getKey(),
                        'encounter_id' => $encounter->getKey(),
                        'task_type' => WorkTaskType::PharmacyReview,
                    ],
                    [
                        'session_id' => $session->getKey(),
                        'title' => 'Telaah Resep Simulasi',
                        'description' => $pharmacyStatus === WorkTaskStatus::Waiting
                            ? 'Menunggu hasil diagnostik diakui sebelum telaah resep dimulai.'
                            : 'Telaah domain administratif, farmasetik, dan klinis sebagai penilaian manusia.',
                        'status' => $pharmacyStatus,
                        'priority' => 1,
                        'source_program' => Program::Medicine,
                        'context' => [
                            'caseLabel' => $encounter->encounter_number,
                            'synthetic' => true,
                            'sourceMedicalVersionPublicId' => $version->public_id,
                            'sourceMedicalContentHash' => $version->content_hash,
                            'medicationRequestCount' => $medicationRequests->count(),
                        ],
                        'available_at' => now(),
                        'completed_at' => null,
                    ],
                );
                $taskCount++;
            }
        }

        return $taskCount;
    }

    private function assertClinicalAssignment(
        ?Assignment $assignment,
        Encounter $encounter,
        SimulationSession $session,
        Capability $capability,
    ): void {
        if (! $assignment
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $assignment->session_id !== $session->getKey()
            || $assignment->patient_id !== $encounter->patient_id
            || $assignment->encounter_id !== $encounter->getKey()
            || ! $assignment->hasCapability($capability)) {
            throw new DomainException('The active assignment does not permit this clinical action in the exact case context.');
        }
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
