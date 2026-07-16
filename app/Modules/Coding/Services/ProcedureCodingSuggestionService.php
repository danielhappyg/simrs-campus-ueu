<?php

namespace App\Modules\Coding\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalProcedureStatus;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\CodingSuggestionOutcome;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\CodingSuggestionCandidate;
use App\Modules\Coding\Models\CodingSuggestionRun;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Modules\RecordQuality\Services\RecordCompletenessService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProcedureCodingSuggestionService
{
    private const MAX_CANDIDATES = 5;

    public function __construct(
        private readonly TerminologySearchService $searchService,
        private readonly TerminologyNormalizer $normalizer,
        private readonly RecordCompletenessService $recordCompletenessService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function generate(
        Encounter $encounter,
        ClinicalProcedure $procedure,
        Assignment $coderAssignment,
        string $requestKey,
    ): CodingSuggestionRun {
        return DB::transaction(function () use ($coderAssignment, $encounter, $procedure, $requestKey): CodingSuggestionRun {
            $existing = CodingSuggestionRun::query()->where('request_key', $requestKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->encounter_id !== $encounter->getKey()
                    || $existing->source_type !== CodingSourceType::Procedure
                    || $existing->source_procedure_id !== $procedure->getKey()
                    || $existing->requested_by_assignment_id !== $coderAssignment->getKey()) {
                    throw new DomainException('The procedure-coding request key was already used in another context.');
                }

                return $existing->load(['release', 'sourceProcedure.closure', 'candidates.concept', 'decisions']);
            }

            $lockedEncounter = Encounter::query()->whereKey($encounter->getKey())->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($lockedEncounter->session_id)->lockForUpdate()->firstOrFail();
            $coder = Assignment::query()->active()->whereKey($coderAssignment->getKey())->lockForUpdate()->first();
            $source = ClinicalProcedure::query()
                ->with('closure')
                ->whereKey($procedure->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCodingContext($lockedEncounter, $session, $coder, $source);
            $release = $this->searchService->activeRelease(TerminologySystem::Icd9Cm);

            if (! $release) {
                throw new DomainException('No active ICD-9-CM release is available. Import and activate a validated release first.');
            }

            $normalizedInput = $this->normalizer->text($source->authored_text);
            $configuration = [
                'engineType' => CodingSuggestionService::ENGINE_TYPE,
                'engineVersion' => CodingSuggestionService::ENGINE_VERSION,
                'maxCandidates' => self::MAX_CANDIDATES,
                'minimumPersistedScore' => 5000,
                'automaticFinalization' => false,
                'sourceType' => CodingSourceType::Procedure->value,
            ];
            $results = $this->searchService->search($release, $source->authored_text, self::MAX_CANDIDATES);
            $generatedAt = CarbonImmutable::now();
            $run = CodingSuggestionRun::query()->create([
                'request_key' => $requestKey,
                'session_id' => $lockedEncounter->session_id,
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->getKey(),
                'source_type' => CodingSourceType::Procedure,
                'source_condition_id' => null,
                'source_entry_version_id' => null,
                'source_procedure_id' => $source->getKey(),
                'terminology_release_id' => $release->getKey(),
                'requested_by_user_id' => $coder->user_id,
                'requested_by_assignment_id' => $coder->getKey(),
                'engine_type' => CodingSuggestionService::ENGINE_TYPE,
                'engine_version' => CodingSuggestionService::ENGINE_VERSION,
                'configuration_hash' => hash('sha256', CanonicalJson::encode($configuration)),
                'normalized_input' => $normalizedInput,
                'normalized_input_hash' => hash('sha256', $normalizedInput),
                'outcome' => $results === []
                    ? CodingSuggestionOutcome::NoReliableCandidate
                    : CodingSuggestionOutcome::Candidates,
                'generated_at' => $generatedAt,
            ]);

            foreach ($results as $index => $result) {
                CodingSuggestionCandidate::query()->create([
                    'coding_suggestion_run_id' => $run->getKey(),
                    'rank' => $index + 1,
                    'terminology_concept_id' => $result['concept']->getKey(),
                    'confidence_band' => $result['confidence'],
                    'score' => $result['score'],
                    'evidence' => [
                        ...$result['evidence'],
                        'sourceType' => CodingSourceType::Procedure->value,
                        'sourceStatement' => $source->authored_text,
                        'sourceProcedurePublicId' => $source->public_id,
                        'sourceProcedureContentHash' => $source->content_hash,
                        'sourceClosurePublicId' => $source->closure->public_id,
                        'sourceClosureVersionNumber' => $source->closure->version_number,
                        'sourceClosureContentHash' => $source->closure->content_hash,
                        'terminologyReleasePublicId' => $release->public_id,
                        'terminologySourceHash' => $release->source_sha256,
                        'engineVersion' => CodingSuggestionService::ENGINE_VERSION,
                    ],
                    'specificity_warning' => $result['specificityWarning'],
                ]);
            }

            $this->auditRecorder->record(
                action: $results === []
                    ? 'coding.procedure_suggestion_no_reliable_candidate'
                    : 'coding.procedure_suggestions_generated',
                resourceType: 'coding_suggestion_run',
                resourceId: $run->public_id,
                actor: $coder->user,
                assignment: $coder,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'source_type' => CodingSourceType::Procedure->value,
                    'source_procedure_public_id' => $source->public_id,
                    'source_procedure_content_hash' => $source->content_hash,
                    'source_closure_public_id' => $source->closure->public_id,
                    'source_closure_content_hash' => $source->closure->content_hash,
                    'terminology_release_public_id' => $release->public_id,
                    'terminology_source_hash' => $release->source_sha256,
                    'engine_version' => CodingSuggestionService::ENGINE_VERSION,
                    'candidate_count' => count($results),
                    'automatic_finalization' => false,
                ],
            );

            return $run->refresh()->load(['release', 'sourceProcedure.closure', 'candidates.concept', 'decisions']);
        });
    }

    private function assertCodingContext(
        Encounter $encounter,
        SimulationSession $session,
        ?Assignment $coder,
        ClinicalProcedure $source,
    ): void {
        $latestClosure = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();
        $latestQuality = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();

        if (! $coder
            || $session->status !== SessionStatus::Active
            || $session->environment_mode !== EnvironmentMode::Simulation
            || $encounter->status !== EncounterStatus::RecordReview
            || $coder->session_id !== $encounter->session_id
            || $coder->patient_id !== $encounter->patient_id
            || $coder->encounter_id !== $encounter->getKey()
            || ! $coder->hasCapability(Capability::CodingWrite)
            || $source->encounter_id !== $encounter->getKey()
            || $source->patient_id !== $encounter->patient_id
            || $source->getRawOriginal('status') !== ClinicalProcedureStatus::Completed->value
            || ! $latestClosure
            || $latestClosure->getKey() !== $source->encounter_closure_id
            || $latestClosure->status !== EncounterClosureStatus::Approved
            || ! $this->recordCompletenessService->approvedReviewIsCurrent($latestQuality, $encounter)) {
            throw new DomainException('Procedure coding suggestions require an exact current completed procedure, approved closure and RMIK review, and authorized coder in simulation context.');
        }
    }
}
