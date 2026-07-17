<?php

namespace App\Modules\Teaching\Services;

use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalReviewAction;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\DiagnosisCertainty;
use App\Modules\Clinical\Enums\DiagnosisRole;
use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Clinical\Enums\MedicationDispenseOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewItemOutcome;
use App\Modules\Clinical\Enums\PharmacyReviewOutcome;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\MedicationStock;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Clinical\Services\ClinicalDocumentationService;
use App\Modules\Clinical\Services\EncounterClosureService;
use App\Modules\Clinical\Services\OrderResultService;
use App\Modules\Clinical\Services\PharmacyWorkflowService;
use App\Modules\Coding\Enums\CodingDecisionType;
use App\Modules\Coding\Enums\CodingDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\CodingReviewAction;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\ProcedureDocumentationCorrectionStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Coding\Models\CodingDocumentationCorrection;
use App\Modules\Coding\Models\CodingSuggestionRun;
use App\Modules\Coding\Models\ProcedureDocumentationCorrection;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Services\CodingSuggestionService;
use App\Modules\Coding\Services\CodingWorkflowService;
use App\Modules\Coding\Services\ProcedureCodingSuggestionService;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\AppointmentStatus;
use App\Modules\Patient\Services\AppointmentCheckInService;
use App\Modules\RecordQuality\Enums\RecordQualityReviewAction;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Services\RecordQualityWorkflowService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Models\WorkTask;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReferenceOutpatientJourneyBuilder
{
    public const SESSION_CODE = 'SIM-RJ-UEU-001';

    public function __construct(
        private readonly AppointmentCheckInService $checkInService,
        private readonly ClinicalDocumentationService $documentationService,
        private readonly OrderResultService $orderResultService,
        private readonly PharmacyWorkflowService $pharmacyWorkflowService,
        private readonly EncounterClosureService $closureService,
        private readonly RecordQualityWorkflowService $recordQualityWorkflowService,
        private readonly CodingSuggestionService $diagnosisSuggestionService,
        private readonly ProcedureCodingSuggestionService $procedureSuggestionService,
        private readonly CodingWorkflowService $codingWorkflowService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function complete(string $sessionCode = self::SESSION_CODE): array
    {
        $this->assertEnvironmentBoundary();

        return DB::transaction(function () use ($sessionCode): array {
            [$session, $encounter] = $this->lockedReferenceContext($sessionCode);

            if ($encounter->status === EncounterStatus::Finalized) {
                return $this->summary($encounter, 'ALREADY_FINALIZED');
            }

            $this->assertCleanStartingState($encounter);
            [$icd10Concept, $icd9Concept] = $this->requiredTerminologyConcepts();
            $assignments = $this->assignments($session, $encounter);
            [$condition, $procedure] = $this->progressToApprovedRecordQuality(
                $session,
                $encounter,
                $assignments,
            );

            $this->manuallyCodeDiagnosis($encounter, $condition, $icd10Concept, $assignments);
            $this->manuallyCodeProcedure($encounter, $procedure, $icd9Concept, $assignments);

            $finalized = Encounter::query()->whereKey($encounter->getKey())->lockForUpdate()->firstOrFail();

            if ($finalized->status !== EncounterStatus::Finalized) {
                throw new DomainException('The reference journey did not reach FINALIZED; the transaction was rolled back.');
            }

            return $this->summary($finalized, 'COMPLETED');
        });
    }

    /**
     * Prepare an active, source-attributed coding correction for staged UI validation.
     *
     * @return array<string, mixed>
     */
    public function openCorrection(
        CodingSourceType $sourceType,
        string $sessionCode = self::SESSION_CODE,
    ): array {
        $this->assertEnvironmentBoundary();

        return DB::transaction(function () use ($sessionCode, $sourceType): array {
            [$session, $encounter] = $this->lockedReferenceContext($sessionCode);
            $existing = $this->activeCorrection($encounter, $sourceType);

            if ($existing) {
                return $this->correctionSummary(
                    $encounter,
                    $sourceType,
                    $existing,
                    'ALREADY_PREPARED',
                );
            }

            $this->assertCleanStartingState($encounter);
            $this->requiredTerminologyConcepts();
            $assignments = $this->assignments($session, $encounter);
            [$condition, $procedure] = $this->progressToApprovedRecordQuality(
                $session,
                $encounter,
                $assignments,
            );

            $run = match ($sourceType) {
                CodingSourceType::Diagnosis => $this->diagnosisSuggestionService->generate(
                    $encounter,
                    $condition,
                    $assignments['rmikCoder'],
                    (string) Str::ulid(),
                ),
                CodingSourceType::Procedure => $this->procedureSuggestionService->generate(
                    $encounter,
                    $procedure,
                    $assignments['rmikCoder'],
                    (string) Str::ulid(),
                ),
            };
            $reason = match ($sourceType) {
                CodingSourceType::Diagnosis => 'Perjelas karakter pusing pada diagnosis kerja sebelum pemilihan kode ICD-10 simulasi.',
                CodingSourceType::Procedure => 'Perjelas lokasi pengambilan sampel pada prosedur sebelum pemilihan kode ICD-9-CM simulasi.',
            };
            $decision = $this->codingWorkflowService->decide(
                run: $run,
                coderAssignment: $assignments['rmikCoder'],
                decision: CodingDecisionType::CorrectionRequested,
                requestKey: (string) Str::ulid(),
                reason: $reason,
            );
            $correction = match ($sourceType) {
                CodingSourceType::Diagnosis => CodingDocumentationCorrection::query()
                    ->where('coding_suggestion_decision_id', $decision->getKey())
                    ->sole(),
                CodingSourceType::Procedure => ProcedureDocumentationCorrection::query()
                    ->where('coding_suggestion_decision_id', $decision->getKey())
                    ->sole(),
            };
            $prepared = Encounter::query()
                ->whereKey($encounter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($prepared->status !== EncounterStatus::AmendmentPending) {
                throw new DomainException('The reference correction did not reach AMENDMENT_PENDING; the transaction was rolled back.');
            }

            return $this->correctionSummary(
                $prepared,
                $sourceType,
                $correction,
                'CORRECTION_OPEN',
            );
        });
    }

    /** @return array{SimulationSession, Encounter} */
    private function lockedReferenceContext(string $sessionCode): array
    {
        $session = SimulationSession::query()
            ->where('code', $sessionCode)
            ->lockForUpdate()
            ->first();

        if (! $session) {
            throw new DomainException("Reference session {$sessionCode} was not found. Run the opt-in demo seeder first.");
        }

        $encounter = Encounter::query()
            ->with(['patient', 'appointment'])
            ->where('session_id', $session->getKey())
            ->lockForUpdate()
            ->sole();

        $this->assertSyntheticReferenceContext($session, $encounter);

        return [$session, $encounter];
    }

    private function assertEnvironmentBoundary(): void
    {
        if (app()->environment('production')
            || config('simulation.mode') !== EnvironmentMode::Simulation->value
            || config('simulation.synthetic_only') !== true
            || config('simulation.demo_seed_enabled') !== true) {
            throw new DomainException('Reference-journey completion is allowed only in an explicitly opted-in, synthetic-only, non-production SIMULATION environment.');
        }
    }

    private function assertSyntheticReferenceContext(SimulationSession $session, Encounter $encounter): void
    {
        if ($session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active
            || $encounter->environment_mode !== EnvironmentMode::Simulation
            || ! $encounter->patient->synthetic_flag
            || $encounter->patient->fixture_source !== 'OPD-REF-001-v1') {
            throw new DomainException('The requested session is not the approved OPD-REF-001-v1 synthetic reference fixture.');
        }
    }

    private function assertCleanStartingState(Encounter $encounter): void
    {
        if ($encounter->status !== EncounterStatus::Planned
            || $encounter->appointment->status !== AppointmentStatus::Booked
            || ClinicalEntryVersion::query()->whereHas('clinicalEntry', fn ($query) => $query->where('encounter_id', $encounter->getKey()))->exists()
            || EncounterClosure::query()->where('encounter_id', $encounter->getKey())->exists()
            || RecordQualityReview::query()->where('encounter_id', $encounter->getKey())->exists()
            || CodingSuggestionRun::query()->where('encounter_id', $encounter->getKey())->exists()
            || CodingAssignment::query()->where('encounter_id', $encounter->getKey())->exists()) {
            throw new DomainException('The reference journey is partially progressed. Use a fresh isolated fixture or finish it through the user workflow; this command will not overwrite or guess the next step.');
        }
    }

    private function activeCorrection(
        Encounter $encounter,
        CodingSourceType $requestedSourceType,
    ): CodingDocumentationCorrection|ProcedureDocumentationCorrection|null {
        $diagnosis = CodingDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('status', '!=', CodingDocumentationCorrectionStatus::Resolved->value)
            ->lockForUpdate()
            ->first();
        $procedure = ProcedureDocumentationCorrection::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('status', '!=', ProcedureDocumentationCorrectionStatus::Resolved->value)
            ->lockForUpdate()
            ->first();

        if ($diagnosis && $procedure) {
            throw new DomainException('The reference fixture has more than one active coding correction. Rebuild the isolated fixture.');
        }

        if (($diagnosis && $requestedSourceType !== CodingSourceType::Diagnosis)
            || ($procedure && $requestedSourceType !== CodingSourceType::Procedure)) {
            throw new DomainException('The reference fixture already contains a different active coding correction. Rebuild the isolated fixture.');
        }

        return $diagnosis ?? $procedure;
    }

    /**
     * @param  array<string, Assignment>  $assignments
     * @return array{ClinicalCondition, ClinicalProcedure}
     */
    private function progressToApprovedRecordQuality(
        SimulationSession $session,
        Encounter $encounter,
        array $assignments,
    ): array {
        $this->checkInService->checkIn($encounter->appointment, $assignments['registrar']);

        $nursingVersion = $this->documentationService->saveNursingIntake(
            $encounter,
            $assignments['nursingLearner'],
            $this->nursingPayload(),
        );
        $this->documentationService->review(
            version: $nursingVersion,
            reviewerAssignment: $assignments['nursingSupervisor'],
            decision: ClinicalReviewAction::ApproveSimulation,
            requestKey: (string) Str::ulid(),
            comment: 'Handoff asesmen awal disetujui untuk fixture referensi simulasi.',
        );

        $medicalVersion = $this->documentationService->saveMedicalAssessment(
            $encounter,
            $assignments['medicalLearner'],
            $this->medicalPayload(),
        );
        $this->documentationService->review(
            version: $medicalVersion,
            reviewerAssignment: $assignments['medicalSupervisor'],
            decision: ClinicalReviewAction::ApproveSimulation,
            requestKey: (string) Str::ulid(),
            comment: 'Asesmen, order, dan resep sintetis disetujui untuk fixture referensi.',
        );

        $serviceRequest = ServiceRequest::query()->where('encounter_id', $encounter->getKey())->sole();
        $result = $this->orderResultService->releaseResult(
            $serviceRequest,
            $assignments['facilitator'],
            $this->resultPayload(),
        );
        $this->orderResultService->acknowledgeResult(
            $result,
            $assignments['medicalLearner'],
            $this->acknowledgementPayload(),
        );

        $medicationRequest = MedicationRequest::query()->where('encounter_id', $encounter->getKey())->sole();
        $this->pharmacyWorkflowService->submitReview(
            $medicationRequest,
            $assignments['pharmacyLearner'],
            $this->pharmacyReviewPayload(),
        );
        $stock = MedicationStock::query()
            ->where('session_id', $session->getKey())
            ->where('lot_number', 'LOT-SIM-A-001')
            ->sole();
        $this->pharmacyWorkflowService->dispense(
            $medicationRequest,
            $assignments['pharmacyLearner'],
            $this->dispensePayload($stock),
        );

        $closure = $this->closureService->save(
            $encounter,
            $assignments['medicalLearner'],
            $this->closurePayload($serviceRequest),
        );
        $this->closureService->review(
            closure: $closure,
            reviewerAssignment: $assignments['medicalSupervisor'],
            action: EncounterClosureReviewAction::ApproveSimulation,
            requestKey: (string) Str::ulid(),
            comment: 'Closure dan prosedur sintetis ditinjau terhadap versi serta hash sumber.',
        );

        $qualityReview = $this->recordQualityWorkflowService->save(
            $encounter,
            $assignments['rmikCoder'],
            $this->recordQualityPayload(),
        );
        $this->recordQualityWorkflowService->review(
            review: $qualityReview,
            supervisorAssignment: $assignments['rmikSupervisor'],
            action: RecordQualityReviewAction::ApproveSimulation,
            requestKey: (string) Str::ulid(),
            comment: 'Checklist, sumber, versi, dan hash disetujui untuk simulasi.',
        );

        return [
            ClinicalCondition::query()->where('encounter_id', $encounter->getKey())->sole(),
            ClinicalProcedure::query()->where('encounter_id', $encounter->getKey())->sole(),
        ];
    }

    /** @return array{TerminologyConcept, TerminologyConcept} */
    private function requiredTerminologyConcepts(): array
    {
        $icd10 = $this->requiredConcept(TerminologySystem::Icd10, 'R42');
        $icd9 = $this->requiredConcept(TerminologySystem::Icd9Cm, '38.99');

        return [$icd10, $icd9];
    }

    private function requiredConcept(TerminologySystem $system, string $code): TerminologyConcept
    {
        $releases = TerminologyRelease::query()
            ->where('classification_system', $system)
            ->where('status', TerminologyReleaseStatus::Active)
            ->get();

        if ($releases->count() !== 1) {
            throw new DomainException("Exactly one active {$system->label()} release is required before the reference journey can be completed.");
        }

        $release = $releases->firstOrFail();
        $concept = TerminologyConcept::query()
            ->where('terminology_release_id', $release->getKey())
            ->where('code', $code)
            ->where('active', true)
            ->first();

        if (! $concept) {
            throw new DomainException("The active {$system->label()} release does not contain required fixture code {$code}.");
        }

        return $concept;
    }

    /**
     * @return array{
     *   registrar: Assignment,
     *   facilitator: Assignment,
     *   nursingLearner: Assignment,
     *   nursingSupervisor: Assignment,
     *   medicalLearner: Assignment,
     *   medicalSupervisor: Assignment,
     *   pharmacyLearner: Assignment,
     *   rmikCoder: Assignment,
     *   rmikSupervisor: Assignment
     * }
     */
    private function assignments(SimulationSession $session, Encounter $encounter): array
    {
        $assignments = [
            'registrar' => $this->assignment($session, $encounter, 'mahasiswa.rmik@example.invalid', Capability::PatientRegister, false),
            'facilitator' => $this->assignment($session, $encounter, 'fasilitator.simulasi@example.invalid', Capability::SessionFacilitate, false),
            'nursingLearner' => $this->assignment($session, $encounter, 'mahasiswa.keperawatan@example.invalid', Capability::IntakeWrite),
            'nursingSupervisor' => $this->assignment($session, $encounter, 'supervisor.keperawatan@example.invalid', Capability::SupervisionReview),
            'medicalLearner' => $this->assignment($session, $encounter, 'mahasiswa.kedokteran@example.invalid', Capability::MedicalAssessmentWrite),
            'medicalSupervisor' => $this->assignment($session, $encounter, 'supervisor.kedokteran@example.invalid', Capability::SupervisionReview),
            'pharmacyLearner' => $this->assignment($session, $encounter, 'mahasiswa.farmasi@example.invalid', Capability::PharmacyReview),
            'rmikCoder' => $this->assignment($session, $encounter, 'koder.rmik@example.invalid', Capability::CodingWrite),
            'rmikSupervisor' => $this->assignment($session, $encounter, 'supervisor.rmik@example.invalid', Capability::SupervisionReview),
        ];

        $this->assertCapabilities($assignments['medicalLearner'], [Capability::MedicalAssessmentWrite, Capability::PrescriptionWrite]);
        $this->assertCapabilities($assignments['pharmacyLearner'], [Capability::PharmacyReview, Capability::Dispense]);
        $this->assertCapabilities($assignments['rmikCoder'], [Capability::RecordReview, Capability::CodingWrite]);
        $this->assertCapabilities($assignments['facilitator'], [Capability::SessionFacilitate, Capability::SafetyDispositionRecord]);
        $this->assertCapabilities($assignments['nursingSupervisor'], [Capability::SupervisionReview, Capability::SafetyDispositionRecord]);

        return $assignments;
    }

    /** @param list<Capability> $capabilities */
    private function assertCapabilities(Assignment $assignment, array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            if (! $assignment->hasCapability($capability)) {
                throw new DomainException("Reference assignment {$assignment->user->email} is missing {$capability->value}.");
            }
        }
    }

    private function assignment(
        SimulationSession $session,
        Encounter $encounter,
        string $email,
        Capability $capability,
        bool $exactCase = true,
    ): Assignment {
        $assignment = Assignment::query()
            ->active()
            ->where('session_id', $session->getKey())
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->sole();

        if (! $assignment->hasCapability($capability)
            || ($exactCase && ($assignment->patient_id !== $encounter->patient_id || $assignment->encounter_id !== $encounter->getKey()))) {
            throw new DomainException("Reference assignment {$email} is missing {$capability->value} or exact case scope.");
        }

        return $assignment;
    }

    /** @param array<string, Assignment> $assignments */
    private function manuallyCodeDiagnosis(
        Encounter $encounter,
        ClinicalCondition $condition,
        TerminologyConcept $concept,
        array $assignments,
    ): void {
        $run = $this->diagnosisSuggestionService->generate(
            $encounter,
            $condition,
            $assignments['rmikCoder'],
            (string) Str::ulid(),
        );
        $decision = $this->codingWorkflowService->decide(
            run: $run,
            coderAssignment: $assignments['rmikCoder'],
            decision: CodingDecisionType::ManualAlternative,
            requestKey: (string) Str::ulid(),
            manualConcept: $concept,
            rationale: 'Koder memilih kode fixture secara manual setelah meninjau pernyataan klinisi dan release aktif; kandidat tidak difinalkan otomatis.',
        );
        $assignment = $decision->resultingAssignment()->firstOrFail();
        $this->codingWorkflowService->submit($assignment, $assignments['rmikCoder']);
        $this->codingWorkflowService->review(
            codingAssignment: $assignment,
            supervisorAssignment: $assignments['rmikSupervisor'],
            action: CodingReviewAction::ApproveSimulation,
            requestKey: (string) Str::ulid(),
            comment: 'Diagnosis, sumber klinis, release ICD-10, dan pilihan manusia ditinjau terpisah.',
        );
    }

    /** @param array<string, Assignment> $assignments */
    private function manuallyCodeProcedure(
        Encounter $encounter,
        ClinicalProcedure $procedure,
        TerminologyConcept $concept,
        array $assignments,
    ): void {
        $run = $this->procedureSuggestionService->generate(
            $encounter,
            $procedure,
            $assignments['rmikCoder'],
            (string) Str::ulid(),
        );
        $decision = $this->codingWorkflowService->decide(
            run: $run,
            coderAssignment: $assignments['rmikCoder'],
            decision: CodingDecisionType::ManualAlternative,
            requestKey: (string) Str::ulid(),
            manualConcept: $concept,
            rationale: 'Koder memilih kode fixture secara manual setelah meninjau prosedur selesai, pelaksana, waktu, dan release aktif.',
        );
        $assignment = $decision->resultingAssignment()->firstOrFail();
        $this->codingWorkflowService->submit($assignment, $assignments['rmikCoder']);
        $this->codingWorkflowService->review(
            codingAssignment: $assignment,
            supervisorAssignment: $assignments['rmikSupervisor'],
            action: CodingReviewAction::ApproveSimulation,
            requestKey: (string) Str::ulid(),
            comment: 'Prosedur, sumber closure, release ICD-9-CM, dan pilihan manusia ditinjau terpisah.',
        );
    }

    /** @return array<string, mixed> */
    private function correctionSummary(
        Encounter $encounter,
        CodingSourceType $sourceType,
        CodingDocumentationCorrection|ProcedureDocumentationCorrection $correction,
        string $state,
    ): array {
        return [
            ...$this->summary($encounter, $state),
            'correction' => [
                'sourceType' => $sourceType->value,
                'publicId' => $correction->public_id,
                'status' => $correction->status->value,
                'responsibleAssignmentPublicId' => Assignment::query()
                    ->whereKey($correction->responsible_assignment_id)
                    ->value('public_id'),
                'requestingAssignmentPublicId' => Assignment::query()
                    ->whereKey($correction->requested_by_assignment_id)
                    ->value('public_id'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Encounter $encounter, string $state): array
    {
        return [
            'state' => $state,
            'sessionCode' => $encounter->session()->value('code'),
            'encounterPublicId' => $encounter->public_id,
            'encounterNumber' => $encounter->encounter_number,
            'status' => $encounter->status->value,
            'synthetic' => (bool) $encounter->patient()->value('synthetic_flag'),
            'counts' => [
                'clinicalVersions' => ClinicalEntryVersion::query()->whereHas('clinicalEntry', fn ($query) => $query->where('encounter_id', $encounter->getKey()))->count(),
                'results' => DiagnosticResult::query()->whereHas('serviceRequest', fn ($query) => $query->where('encounter_id', $encounter->getKey()))->count(),
                'closures' => EncounterClosure::query()->where('encounter_id', $encounter->getKey())->count(),
                'procedures' => ClinicalProcedure::query()->where('encounter_id', $encounter->getKey())->count(),
                'recordQualityReviews' => RecordQualityReview::query()->where('encounter_id', $encounter->getKey())->count(),
                'codingAssignments' => CodingAssignment::query()->where('encounter_id', $encounter->getKey())->count(),
                'diagnosisCorrections' => CodingDocumentationCorrection::query()->where('encounter_id', $encounter->getKey())->count(),
                'procedureCorrections' => ProcedureDocumentationCorrection::query()->where('encounter_id', $encounter->getKey())->count(),
                'workTasks' => WorkTask::query()->where('encounter_id', $encounter->getKey())->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function nursingPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'history_source' => 'Pasien sintetis',
            'chief_complaint' => 'Pusing sejak dua hari sebelum kunjungan simulasi.',
            'onset_duration' => 'Dua hari',
            'consciousness' => 'Sadar penuh.',
            'allergy_state' => AllergyAssessmentState::NoKnownAllergyReported->value,
            'current_medication_state' => CurrentMedicationState::NoneReported->value,
            'vitals' => [
                'temperature' => 36.8,
                'heart_rate' => 82,
                'respiratory_rate' => 18,
                'systolic_blood_pressure' => 118,
                'diastolic_blood_pressure' => 76,
                'oxygen_saturation' => 98,
            ],
            'safety_responses' => [[
                'question_code' => 'SUPERVISOR_CONCERN',
                'response' => 'NO',
                'note' => null,
            ]],
            'safety_decision' => IntakeSafetyDecision::RoutineFlow->value,
            'note' => 'Catatan fixture pembelajaran.',
            'handoff_summary' => 'Diteruskan untuk asesmen medis dalam skenario.',
            'change_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function medicalPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'history_source' => 'Pasien sintetis dan handoff keperawatan',
            'present_illness' => 'Pusing episodik selama dua hari dalam skenario simulasi.',
            'past_medical_history' => 'Tidak ada riwayat tambahan pada fixture.',
            'family_history' => 'Tidak ada riwayat relevan pada fixture.',
            'social_history' => 'Data sintetis untuk pembelajaran.',
            'general_examination' => 'Keadaan umum stabil dalam skenario.',
            'focused_examination' => 'Pemeriksaan terfokus didokumentasikan sesuai skenario.',
            'assessment_summary' => 'Temuan disintesis oleh mahasiswa untuk pembelajaran.',
            'diagnoses' => [[
                'authored_text' => 'Sindrom pusing dalam evaluasi pada skenario simulasi.',
                'certainty' => DiagnosisCertainty::Working->value,
                'role' => DiagnosisRole::Primary->value,
                'onset_at' => now()->subDays(2)->toDateString(),
            ]],
            'service_requests' => [[
                'request_type' => 'LABORATORY',
                'authored_service' => 'Pemeriksaan darah sintetis skenario',
                'clinical_question' => 'Dokumentasikan hasil fixture.',
                'priority' => 'ROUTINE',
                'source_diagnosis_index' => 0,
            ]],
            'medication_requests' => [[
                'authored_medication' => 'Obat Simulasi A',
                'form' => 'Tablet',
                'strength' => '500 mg',
                'dose_value' => 1,
                'dose_unit' => 'tablet',
                'route' => 'Oral',
                'frequency' => 'Dua kali sehari',
                'duration' => 'Tiga hari',
                'quantity_value' => 6,
                'quantity_unit' => 'tablet',
                'directions' => 'Gunakan sesuai instruksi skenario.',
                'indication_text' => 'Sindrom pusing.',
                'source_diagnosis_index' => 0,
            ]],
            'care_plan' => 'Rencana simulasi mencakup hasil dan farmasi.',
            'education' => 'Edukasi hanya untuk skenario pembelajaran.',
            'follow_up_plan' => 'Kontrol simulasi sesuai skenario.',
            'intended_disposition' => 'Rawat jalan simulasi.',
            'change_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function resultPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'report_code' => 'LAB-SIM-001',
            'report_display' => 'Panel darah sintetis',
            'effective_at' => now()->toIso8601String(),
            'narrative_conclusion' => 'Tidak ada temuan kritis dalam skenario.',
            'components' => [[
                'code' => 'SIM-HGB',
                'display' => 'Hemoglobin sintetis',
                'value' => '13.4',
                'unit' => 'g/dL',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function acknowledgementPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'outcome' => 'ACKNOWLEDGED',
            'comment' => 'Hasil sintetis ditinjau oleh peminta.',
        ];
    }

    /** @return array<string, mixed> */
    private function pharmacyReviewPayload(): array
    {
        $clear = PharmacyReviewItemOutcome::Clear->value;

        return [
            'request_key' => (string) Str::ulid(),
            'overall_outcome' => PharmacyReviewOutcome::Accept->value,
            'domain_results' => [
                'administrative' => [
                    ['criterion_code' => 'PATIENT_IDENTITY', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'PRESCRIBER_AND_DATE', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'CLINIC_CONTEXT', 'outcome' => $clear, 'comment' => null],
                ],
                'pharmaceutical' => [
                    ['criterion_code' => 'MEDICINE_FORM_STRENGTH', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'DOSE_DIRECTIONS_QUANTITY', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'PREPARATION_STABILITY', 'outcome' => $clear, 'comment' => null],
                ],
                'clinical' => [
                    ['criterion_code' => 'INDICATION_AND_DOSE', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'DUPLICATION', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'ALLERGY_ADVERSE_REACTION', 'outcome' => $clear, 'comment' => null],
                    ['criterion_code' => 'CONTRAINDICATION_INTERACTION', 'outcome' => $clear, 'comment' => null],
                ],
            ],
            'intervention' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function dispensePayload(MedicationStock $stock): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'outcome' => MedicationDispenseOutcome::Complete->value,
            'quantity' => 6,
            'medication_stock_id' => $stock->getKey(),
            'outcome_reason' => null,
            'preparation_notes' => 'Obat sintetis disiapkan.',
            'final_check_confirmed' => true,
            'final_check_notes' => 'Pemeriksaan akhir fixture dicatat.',
            'handoff_recipient' => 'Pasien sintetis',
            'counseling_topics' => ['Cara penggunaan'],
            'counseling_acknowledged' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function closurePayload(ServiceRequest $serviceRequest): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->toIso8601String(),
            'leaving_condition' => 'Kondisi stabil untuk menyelesaikan encounter rawat jalan simulasi.',
            'disposition' => 'Pulang dari poliklinik simulasi.',
            'follow_up_plan' => 'Kontrol simulasi sesuai jadwal skenario.',
            'referral_plan' => null,
            'education_instructions' => 'Instruksi penggunaan obat sintetis dan tanda kembali dijelaskan.',
            'outpatient_summary' => 'Asesmen, hasil, farmasi, dan rencana tindak lanjut telah ditinjau.',
            'procedure_documentation_state' => ProcedureDocumentationState::ProceduresRecorded->value,
            'procedures' => [[
                'authored_text' => 'Pengambilan sampel darah vena untuk pemeriksaan sintetis.',
                'performed_start_at' => now()->subMinutes(15)->toIso8601String(),
                'performed_end_at' => now()->subMinutes(5)->toIso8601String(),
                'performer_text' => 'Petugas laboratorium simulasi',
                'body_site_text' => 'Vena lengan kanan',
                'outcome_text' => 'Sampel sintetis berhasil diperoleh',
                'note' => 'Tidak ada komplikasi pada skenario.',
                'reason_condition_public_id' => null,
                'based_on_service_request_public_id' => $serviceRequest->public_id,
            ]],
            'change_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function recordQualityPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'findings' => [],
            'resolved_correction_public_ids' => [],
            'change_reason' => null,
        ];
    }
}
