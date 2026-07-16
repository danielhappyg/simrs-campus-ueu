<?php

namespace App\Modules\Coding\Services;

use App\Modules\Coding\Enums\CodingConfidenceBand;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyRelease;
use DomainException;
use JsonException;

final class CodingGoldSetEvaluator
{
    public const SCHEMA = 'simrs-coding-gold-set.v1';

    public const MAX_CANDIDATES = 5;

    private const EXPECTATION_TARGET = 'TARGET_RANKED';

    private const EXPECTATION_NO_CANDIDATE = 'NO_RELIABLE_CANDIDATE';

    private const EXPECTATION_REVIEW = 'REVIEW_REQUIRED';

    private const STATUS_REFERENCE = 'REFERENCE_ASSERTION';

    private const STATUS_PENDING = 'PENDING_EXPERT_REVIEW';

    public function __construct(private readonly TerminologySearchService $searchService) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluateFile(string $path): array
    {
        [$definition, $sourceHash] = $this->loadDefinition($path);
        $releases = $this->resolveReleases($definition);
        $cases = $this->evaluateCases($definition, $releases);

        return [
            'schema' => 'simrs-coding-gold-set-report.v1',
            'goldSet' => [
                'id' => $this->requiredString($definition, 'id', 'gold set'),
                'version' => $this->requiredString($definition, 'version', 'gold set'),
                'status' => $this->requiredString($definition, 'status', 'gold set'),
                'mode' => $this->requiredString($definition, 'mode', 'gold set'),
                'expertReviewStatus' => $this->requiredString($definition, 'expertReviewStatus', 'gold set'),
                'sourceFile' => basename($path),
                'sourceSha256' => $sourceHash,
            ],
            'engine' => [
                'type' => CodingSuggestionService::ENGINE_TYPE,
                'version' => CodingSuggestionService::ENGINE_VERSION,
                'maxCandidates' => self::MAX_CANDIDATES,
                'automaticFinalization' => false,
                'accuracyThreshold' => null,
            ],
            'releases' => collect($releases)->map(fn (TerminologyRelease $release): array => [
                'publicId' => $release->public_id,
                'classificationSystem' => $release->classification_system->value,
                'logicalVersion' => $release->logical_version,
                'sourceSha256' => $release->source_sha256,
                'rowCount' => $release->row_count,
            ])->all(),
            'summary' => $this->summarize($cases),
            'cases' => $cases,
        ];
    }

    /**
     * @return array{array<string, mixed>, string}
     */
    private function loadDefinition(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException('The coding gold-set file is unavailable or unreadable.');
        }

        $contents = file_get_contents($path);
        $sourceHash = hash_file('sha256', $path);

        if (! is_string($contents) || ! is_string($sourceHash)) {
            throw new DomainException('The coding gold-set file could not be read or checksummed.');
        }

        try {
            $definition = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('The coding gold-set file is not valid JSON.', previous: $exception);
        }

        if (! is_array($definition) || array_is_list($definition)) {
            throw new DomainException('The coding gold-set root must be an object.');
        }

        if ($this->requiredString($definition, 'schema', 'gold set') !== self::SCHEMA
            || $this->requiredString($definition, 'mode', 'gold set') !== 'SYNTHETIC_ONLY') {
            throw new DomainException('The coding gold set must use the supported schema and synthetic-only mode.');
        }

        $status = $this->requiredString($definition, 'status', 'gold set');
        $reviewStatus = $this->requiredString($definition, 'expertReviewStatus', 'gold set');

        if (! in_array($status, ['DRAFT_EXPERT_VALIDATION_REQUIRED', 'EXPERT_VALIDATED'], true)
            || ! in_array($reviewStatus, ['PENDING', 'APPROVED'], true)
            || ($status === 'EXPERT_VALIDATED' && $reviewStatus !== 'APPROVED')) {
            throw new DomainException('The gold-set validation and expert-review statuses are inconsistent.');
        }

        if (! array_key_exists('accuracyThreshold', $definition) || $definition['accuracyThreshold'] !== null) {
            throw new DomainException('The reference gold set cannot invent an accuracy threshold before owner approval.');
        }

        return [$definition, $sourceHash];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, TerminologyRelease>
     */
    private function resolveReleases(array $definition): array
    {
        $requirements = $definition['releaseRequirements'] ?? null;

        if (! is_array($requirements) || array_is_list($requirements)) {
            throw new DomainException('The coding gold set requires releaseRequirements keyed by classification system.');
        }

        $releases = [];

        foreach (TerminologySystem::cases() as $system) {
            $requirement = $requirements[$system->value] ?? null;

            if (! is_array($requirement) || array_is_list($requirement)) {
                throw new DomainException("The coding gold set has no {$system->value} release requirement.");
            }

            $logicalVersion = $this->requiredString($requirement, 'logicalVersion', "{$system->value} release requirement");
            $sourceHash = strtolower($this->requiredString($requirement, 'sourceSha256', "{$system->value} release requirement"));
            $rowCount = $requirement['rowCount'] ?? null;

            if ($logicalVersion !== $system->logicalVersion()
                || preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1
                || ! is_int($rowCount)
                || $rowCount < 1) {
                throw new DomainException("The {$system->value} release requirement is invalid.");
            }

            $release = $this->searchService->activeRelease($system);

            if (! $release
                || $release->logical_version !== $logicalVersion
                || ! hash_equals($release->source_sha256, $sourceHash)
                || $release->row_count !== $rowCount) {
                throw new DomainException("The active {$system->value} release does not match the gold-set version, row count, and SHA-256.");
            }

            $releases[$system->value] = $release;
        }

        return $releases;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, TerminologyRelease>  $releases
     * @return list<array<string, mixed>>
     */
    private function evaluateCases(array $definition, array $releases): array
    {
        $rawCases = $definition['cases'] ?? null;

        if (! is_array($rawCases) || ! array_is_list($rawCases) || $rawCases === []) {
            throw new DomainException('The coding gold set must contain at least one case.');
        }

        $ids = [];
        $results = [];

        foreach ($rawCases as $index => $rawCase) {
            $context = 'gold-set case '.($index + 1);

            if (! is_array($rawCase) || array_is_list($rawCase)) {
                throw new DomainException("The {$context} must be an object.");
            }

            $id = $this->requiredString($rawCase, 'id', $context);

            if (isset($ids[$id]) || preg_match('/^[A-Z0-9][A-Z0-9_-]{2,63}$/', $id) !== 1) {
                throw new DomainException("The {$context} has a duplicate or invalid ID.");
            }

            $ids[$id] = true;
            $sourceType = CodingSourceType::tryFrom($this->requiredString($rawCase, 'sourceType', $context));
            $system = TerminologySystem::tryFrom($this->requiredString($rawCase, 'system', $context));
            $statement = $this->requiredString($rawCase, 'statement', $context);
            $cohort = $this->requiredString($rawCase, 'cohort', $context);
            $language = $this->requiredString($rawCase, 'language', $context);
            $validationStatus = $this->requiredString($rawCase, 'validationStatus', $context);
            $expectation = $this->requiredString($rawCase, 'expectation', $context);
            $metricEligible = $this->requiredBool($rawCase, 'metricEligible', $context);
            $synthetic = $this->requiredBool($rawCase, 'synthetic', $context);
            $acceptableCodes = $this->stringList($rawCase['acceptableCodes'] ?? null, "{$context} acceptableCodes");

            if (! $sourceType
                || ! $system
                || $sourceType->terminologySystem() !== $system
                || ! in_array($language, ['en', 'id', 'mixed'], true)
                || ! in_array($validationStatus, [self::STATUS_REFERENCE, self::STATUS_PENDING], true)
                || ! in_array($expectation, [self::EXPECTATION_TARGET, self::EXPECTATION_NO_CANDIDATE, self::EXPECTATION_REVIEW], true)
                || ! $synthetic
                || mb_strlen($statement) < 2
                || mb_strlen($statement) > 500
                || ($validationStatus === self::STATUS_PENDING && $metricEligible)
                || ($expectation === self::EXPECTATION_TARGET && $acceptableCodes === [])
                || ($expectation !== self::EXPECTATION_TARGET && $acceptableCodes !== [])) {
                throw new DomainException("The {$context} has an unsafe or inconsistent evaluation contract.");
            }

            $release = $releases[$system->value];
            $existingCodes = $release->concepts()
                ->where('active', true)
                ->whereIn('code', $acceptableCodes)
                ->pluck('code')
                ->map(fn (mixed $code): string => (string) $code)
                ->all();

            if (array_diff($acceptableCodes, $existingCodes) !== []) {
                throw new DomainException("The {$context} references a code outside its checksummed release.");
            }

            $candidates = $this->searchService->search($release, $statement, self::MAX_CANDIDATES);
            $returnedCodes = array_map(
                fn (array $candidate): string => $candidate['concept']->code,
                $candidates,
            );
            $firstAcceptableRank = null;

            foreach ($returnedCodes as $candidateIndex => $code) {
                if (in_array($code, $acceptableCodes, true)) {
                    $firstAcceptableRank = $candidateIndex + 1;
                    break;
                }
            }

            $topConfidence = $candidates[0]['confidence'] ?? null;
            $expectationMet = match ($expectation) {
                self::EXPECTATION_TARGET => $firstAcceptableRank !== null && $firstAcceptableRank <= self::MAX_CANDIDATES,
                self::EXPECTATION_NO_CANDIDATE => $candidates === [],
                self::EXPECTATION_REVIEW => $candidates === [] || $topConfidence === CodingConfidenceBand::ReviewRequired,
            };

            $results[] = [
                'id' => $id,
                'sourceType' => $sourceType->value,
                'system' => $system->value,
                'cohort' => $cohort,
                'language' => $language,
                'validationStatus' => $validationStatus,
                'metricEligible' => $metricEligible,
                'synthetic' => true,
                'statement' => $statement,
                'expectation' => $expectation,
                'acceptableCodes' => $acceptableCodes,
                'returnedCodes' => $returnedCodes,
                'firstAcceptableRank' => $firstAcceptableRank,
                'top1Hit' => $expectation === self::EXPECTATION_TARGET ? $firstAcceptableRank === 1 : null,
                'top5Hit' => $expectation === self::EXPECTATION_TARGET
                    ? $firstAcceptableRank !== null && $firstAcceptableRank <= self::MAX_CANDIDATES
                    : null,
                'expectationMet' => $expectationMet,
                'topConfidence' => $topConfidence?->value,
                'topScore' => $candidates[0]['score'] ?? null,
                'candidateCount' => count($candidates),
            ];
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function summarize(array $cases): array
    {
        $bySourceType = [
            CodingSourceType::Diagnosis->value => $this->emptySourceSummary(),
            CodingSourceType::Procedure->value => $this->emptySourceSummary(),
        ];

        $pendingExpertReviewCases = 0;
        $expectationMet = 0;
        $expectationMissed = 0;
        $referenceExpectationMisses = 0;

        foreach ($cases as $case) {
            $sourceType = CodingSourceType::from((string) $case['sourceType']);
            $sourceSummary = $bySourceType[$sourceType->value];
            $sourceSummary['allCases']++;
            $caseExpectationMet = $case['expectationMet'] === true;

            if ($caseExpectationMet) {
                $expectationMet++;
            } else {
                $expectationMissed++;
            }

            if ($case['validationStatus'] === self::STATUS_PENDING) {
                $pendingExpertReviewCases++;
            }

            if ($case['metricEligible'] !== true) {
                $bySourceType[$sourceType->value] = $sourceSummary;

                continue;
            }

            $sourceSummary['metricEligibleCases']++;

            if (! $caseExpectationMet) {
                $sourceSummary['referenceExpectationMisses']++;
                $referenceExpectationMisses++;
            }

            if ($case['expectation'] === self::EXPECTATION_TARGET) {
                $sourceSummary['targetCases']++;

                if ($case['top1Hit'] === true) {
                    $sourceSummary['top1Hits']++;
                }

                if ($case['top5Hit'] === true) {
                    $sourceSummary['top5Hits']++;
                }
            } elseif ($case['expectation'] === self::EXPECTATION_NO_CANDIDATE) {
                $sourceSummary['noCandidateCases']++;

                if ($caseExpectationMet) {
                    $sourceSummary['noCandidatePasses']++;
                }
            } else {
                $sourceSummary['reviewSafetyCases']++;

                if ($caseExpectationMet) {
                    $sourceSummary['reviewSafetyPasses']++;
                }
            }

            $bySourceType[$sourceType->value] = $sourceSummary;
        }

        foreach (CodingSourceType::cases() as $sourceType) {
            $sourceSummary = $bySourceType[$sourceType->value];
            $sourceSummary['top1Rate'] = $this->rate($sourceSummary['top1Hits'], $sourceSummary['targetCases']);
            $sourceSummary['top5Rate'] = $this->rate($sourceSummary['top5Hits'], $sourceSummary['targetCases']);
            $bySourceType[$sourceType->value] = $sourceSummary;
        }

        return [
            'totalCases' => count($cases),
            'metricEligibleCases' => collect($cases)->where('metricEligible', true)->count(),
            'pendingExpertReviewCases' => $pendingExpertReviewCases,
            'expectationMet' => $expectationMet,
            'expectationMissed' => $expectationMissed,
            'referenceExpectationMisses' => $referenceExpectationMisses,
            'accuracyThreshold' => null,
            'bySourceType' => $bySourceType,
        ];
    }

    /**
     * @return array{
     *   allCases: int,
     *   metricEligibleCases: int,
     *   targetCases: int,
     *   top1Hits: int,
     *   top5Hits: int,
     *   top1Rate: float|null,
     *   top5Rate: float|null,
     *   noCandidateCases: int,
     *   noCandidatePasses: int,
     *   reviewSafetyCases: int,
     *   reviewSafetyPasses: int,
     *   referenceExpectationMisses: int
     * }
     */
    private function emptySourceSummary(): array
    {
        return [
            'allCases' => 0,
            'metricEligibleCases' => 0,
            'targetCases' => 0,
            'top1Hits' => 0,
            'top5Hits' => 0,
            'top1Rate' => null,
            'top5Rate' => null,
            'noCandidateCases' => 0,
            'noCandidatePasses' => 0,
            'reviewSafetyCases' => 0,
            'reviewSafetyPasses' => 0,
            'referenceExpectationMisses' => 0,
        ];
    }

    private function rate(int $hits, int $total): ?float
    {
        return $total === 0 ? null : round($hits / $total, 4);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function requiredString(array $source, string $key, string $context): string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("The {$context} requires a non-empty {$key} string.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function requiredBool(array $source, string $key, string $context): bool
    {
        $value = $source[$key] ?? null;

        if (! is_bool($value)) {
            throw new DomainException("The {$context} requires a boolean {$key} value.");
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException("The {$context} must be a list of strings.");
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new DomainException("The {$context} must contain only non-empty strings.");
            }

            $strings[] = trim($item);
        }

        if (count($strings) !== count(array_unique($strings))) {
            throw new DomainException("The {$context} cannot contain duplicates.");
        }

        return $strings;
    }
}
