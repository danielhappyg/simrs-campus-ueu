<?php

namespace App\Modules\Coding\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
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

class CodingSuggestionService
{
    public const ENGINE_TYPE = 'DETERMINISTIC_LEXICAL';

    public const ENGINE_VERSION = 'coding-reference.v1';

    private const MAX_CANDIDATES = 5;

    public function __construct(
        private readonly TerminologySearchService $searchService,
        private readonly TerminologyNormalizer $normalizer,
        private readonly RecordCompletenessService $recordCompletenessService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function generate(
        Encounter $encounter,
        ClinicalCondition $condition,
        Assignment $coderAssignment,
        string $requestKey,
    ): CodingSuggestionRun {
        return DB::transaction(function () use ($coderAssignment, $condition, $encounter, $requestKey): CodingSuggestionRun {
            $existing = CodingSuggestionRun::query()->where('request_key', $requestKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->encounter_id !== $encounter->getKey()
                    || $existing->source_condition_id !== $condition->getKey()
                    || $existing->source_type !== CodingSourceType::Diagnosis
                    || $existing->requested_by_assignment_id !== $coderAssignment->getKey()) {
                    throw new DomainException('The coding-suggestion request key was already used in another context.');
                }

                return $existing->load(['release', 'sourceCondition', 'candidates.concept', 'decisions']);
            }

            $lockedEncounter = Encounter::query()->whereKey($encounter->getKey())->lockForUpdate()->firstOrFail();
            $session = SimulationSession::query()->whereKey($lockedEncounter->session_id)->lockForUpdate()->firstOrFail();
            $coder = Assignment::query()->active()->whereKey($coderAssignment->getKey())->lockForUpdate()->first();
            $source = ClinicalCondition::query()->whereKey($condition->getKey())->lockForUpdate()->firstOrFail();
            $version = ClinicalEntryVersion::query()
                ->with('clinicalEntry')
                ->whereKey($source->source_entry_version_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCodingContext($lockedEncounter, $session, $coder, $source, $version);
            $release = $this->searchService->activeRelease(TerminologySystem::Icd10);

            if (! $release) {
                throw new DomainException('No active ICD-10 release is available. Import and activate a validated release first.');
            }

            $normalizedInput = $this->normalizer->text($source->authored_text);
            $configuration = [
                'engineType' => self::ENGINE_TYPE,
                'engineVersion' => self::ENGINE_VERSION,
                'maxCandidates' => self::MAX_CANDIDATES,
                'minimumPersistedScore' => 5000,
                'automaticFinalization' => false,
            ];
            $results = $this->searchService->search($release, $source->authored_text, self::MAX_CANDIDATES);
            $generatedAt = CarbonImmutable::now();
            $run = CodingSuggestionRun::query()->create([
                'request_key' => $requestKey,
                'session_id' => $lockedEncounter->session_id,
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->getKey(),
                'source_type' => CodingSourceType::Diagnosis,
                'source_condition_id' => $source->getKey(),
                'source_entry_version_id' => $version->getKey(),
                'source_procedure_id' => null,
                'terminology_release_id' => $release->getKey(),
                'requested_by_user_id' => $coder->user_id,
                'requested_by_assignment_id' => $coder->getKey(),
                'engine_type' => self::ENGINE_TYPE,
                'engine_version' => self::ENGINE_VERSION,
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
                        'sourceStatement' => $source->authored_text,
                        'sourceConditionPublicId' => $source->public_id,
                        'sourceEntryVersionPublicId' => $version->public_id,
                        'sourceEntryVersionNumber' => $version->version_number,
                        'sourceClinicalContentHash' => $version->content_hash,
                        'terminologyReleasePublicId' => $release->public_id,
                        'terminologySourceHash' => $release->source_sha256,
                        'engineVersion' => self::ENGINE_VERSION,
                    ],
                    'specificity_warning' => $result['specificityWarning'],
                ]);
            }

            $this->auditRecorder->record(
                action: $results === []
                    ? 'coding.suggestion_no_reliable_candidate'
                    : 'coding.suggestions_generated',
                resourceType: 'coding_suggestion_run',
                resourceId: $run->public_id,
                actor: $coder->user,
                assignment: $coder,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'source_condition_public_id' => $source->public_id,
                    'source_entry_version_public_id' => $version->public_id,
                    'source_clinical_content_hash' => $version->content_hash,
                    'terminology_release_public_id' => $release->public_id,
                    'terminology_source_hash' => $release->source_sha256,
                    'engine_version' => self::ENGINE_VERSION,
                    'candidate_count' => count($results),
                    'automatic_finalization' => false,
                ],
            );

            return $run->refresh()->load(['release', 'sourceCondition', 'candidates.concept', 'decisions']);
        });
    }

    private function assertCodingContext(
        Encounter $encounter,
        SimulationSession $session,
        ?Assignment $coder,
        ClinicalCondition $source,
        ClinicalEntryVersion $version,
    ): void {
        $latestVersionId = $version->clinicalEntry->versions()->max('id');
        $latestQuality = RecordQualityReview::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('version_number')
            ->first();
        $qualityApproved = $this->recordCompletenessService->approvedReviewIsCurrent($latestQuality, $encounter);

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
            || $source->source_entry_version_id !== $version->getKey()
            || $version->status !== ClinicalEntryStatus::Approved
            || $latestVersionId !== $version->getKey()
            || ! $qualityApproved) {
            throw new DomainException('Coding suggestions require the exact current approved diagnosis, approved RMIK review, and authorized coder in simulation context.');
        }
    }
}
