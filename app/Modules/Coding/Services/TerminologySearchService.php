<?php

namespace App\Modules\Coding\Services;

use App\Modules\Coding\Enums\CodingConfidenceBand;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyAlias;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TerminologySearchService
{
    public function __construct(private readonly TerminologyNormalizer $normalizer) {}

    public function activeRelease(TerminologySystem $system): ?TerminologyRelease
    {
        return TerminologyRelease::query()
            ->where('classification_system', $system)
            ->where('status', TerminologyReleaseStatus::Active)
            ->orderByDesc('activated_at')
            ->first();
    }

    /**
     * @return list<array{
     *   concept: TerminologyConcept,
     *   confidence: CodingConfidenceBand,
     *   score: int,
     *   evidence: array<string, mixed>,
     *   specificityWarning: string|null
     * }>
     */
    public function searchActive(TerminologySystem $system, string $query, int $limit = 10): array
    {
        $release = $this->activeRelease($system);

        if (! $release) {
            throw new DomainException("No active {$system->label()} terminology release is available.");
        }

        return $this->search($release, $query, $limit);
    }

    /**
     * @return list<array{
     *   concept: TerminologyConcept,
     *   confidence: CodingConfidenceBand,
     *   score: int,
     *   evidence: array<string, mixed>,
     *   specificityWarning: string|null
     * }>
     */
    public function search(TerminologyRelease $release, string $query, int $limit = 10): array
    {
        $normalizedText = $this->normalizer->text($query);
        $normalizedCode = $this->normalizer->code($query);
        $tokens = $this->normalizer->tokens($query);
        $boundedLimit = max(1, min($limit, 20));

        if ($normalizedText === '' || mb_strlen($normalizedText) < 2) {
            return [];
        }

        $aliases = TerminologyAlias::query()
            ->where('terminology_release_id', $release->getKey())
            ->where('active', true)
            ->where(function (Builder $builder) use ($normalizedText): void {
                $builder
                    ->where('normalized_phrase', $normalizedText)
                    ->orWhere('normalized_phrase', 'like', $this->escapeLike($normalizedText).'%');
            })
            ->limit(100)
            ->get()
            ->groupBy('terminology_concept_id');
        $aliasConceptIds = $aliases->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $exactConcepts = TerminologyConcept::query()
            ->where('terminology_release_id', $release->getKey())
            ->where('active', true)
            ->where(function (Builder $builder) use ($aliasConceptIds, $normalizedCode, $normalizedText): void {
                $builder
                    ->where('normalized_code', $normalizedCode)
                    ->orWhere('normalized_display', $normalizedText);

                if ($aliasConceptIds !== []) {
                    $builder->orWhereIn('id', $aliasConceptIds);
                }
            })
            ->orderBy('code')
            ->limit(200)
            ->get();
        $prefixConcepts = TerminologyConcept::query()
            ->where('terminology_release_id', $release->getKey())
            ->where('active', true)
            ->where(function (Builder $builder) use ($normalizedCode, $normalizedText): void {
                $builder
                    ->where('normalized_code', 'like', $this->escapeLike($normalizedCode).'%')
                    ->orWhere('normalized_display', 'like', $this->escapeLike($normalizedText).'%');
            })
            ->orderBy('code')
            ->limit(300)
            ->get();

        /** @var Collection<int, TerminologyConcept> $tokenConcepts */
        $tokenConcepts = collect();

        foreach ($this->candidateTokens($tokens) as $token) {
            $tokenConcepts = $tokenConcepts->concat(
                TerminologyConcept::query()
                    ->where('terminology_release_id', $release->getKey())
                    ->where('active', true)
                    ->where('search_tokens', 'like', '%'.$this->escapeLike($token).'%')
                    ->orderBy('code')
                    ->limit(150)
                    ->get(),
            );
        }

        $concepts = $exactConcepts
            ->concat($prefixConcepts)
            ->concat($tokenConcepts)
            ->unique(fn (TerminologyConcept $concept): int => $concept->getKey())
            ->values();
        $queryTokenCount = max(1, count($tokens));
        $ranked = $concepts->map(function (TerminologyConcept $concept) use (
            $aliases,
            $normalizedCode,
            $normalizedText,
            $queryTokenCount,
            $tokens,
        ): ?array {
            $features = [];
            $score = 0;
            $matchedAlias = $aliases->get($concept->getKey())
                ?->sortBy(fn (TerminologyAlias $alias): int => $alias->normalized_phrase === $normalizedText ? 0 : 1)
                ->first();

            if ($concept->normalized_code === $normalizedCode) {
                $score = 10000;
                $features[] = 'EXACT_CODE';
            } elseif ($concept->normalized_display === $normalizedText) {
                $score = 9800;
                $features[] = 'EXACT_DISPLAY';
            } elseif ($matchedAlias && $matchedAlias->normalized_phrase === $normalizedText) {
                $score = 9300;
                $features[] = 'EXACT_VERSIONED_ALIAS';
            } elseif (str_starts_with($concept->normalized_code, $normalizedCode)) {
                $score = 9000;
                $features[] = 'CODE_PREFIX';
            } elseif (str_starts_with($concept->normalized_display, $normalizedText)) {
                $score = 8500;
                $features[] = 'DISPLAY_PREFIX';
            }

            $conceptTokens = $this->normalizer->tokens($concept->normalized_display);
            $matchedTokens = array_values(array_intersect($tokens, $conceptTokens));
            $coverage = count($matchedTokens) / $queryTokenCount;

            if ($coverage === 1.0 && $score < 8000) {
                $score = 8000;
                $features[] = 'ALL_QUERY_TOKENS';
            } elseif ($coverage >= 0.6 && $score < 8000) {
                $score = 5000 + (int) round($coverage * 2000);
                $features[] = 'PARTIAL_QUERY_TOKENS';
            }

            if ($score < 5000) {
                return null;
            }

            return [
                'concept' => $concept,
                'score' => $score,
                'evidence' => [
                    'features' => $features,
                    'normalizedQuery' => $normalizedText,
                    'matchedTokens' => $matchedTokens,
                    'queryTokenCoverage' => round($coverage, 4),
                    'aliasRuleId' => $matchedAlias?->rule_id,
                    'aliasSource' => $matchedAlias?->source,
                ],
            ];
        })->filter()->sort(function (array $left, array $right): int {
            $scoreComparison = $right['score'] <=> $left['score'];

            return $scoreComparison !== 0
                ? $scoreComparison
                : strcmp($left['concept']->code, $right['concept']->code);
        })->take($boundedLimit)->values();
        $topScore = $ranked->first()['score'] ?? null;
        $topScoreTieCount = $topScore === null
            ? 0
            : $ranked->where('score', $topScore)->count();
        $ranked = $ranked->map(function (array $result) use ($topScore, $topScoreTieCount): array {
            $ambiguousTop = $topScoreTieCount > 1 && $result['score'] === $topScore;
            $confidence = match (true) {
                $result['score'] >= 9500 => CodingConfidenceBand::Exact,
                $result['score'] >= 8000 && ! $ambiguousTop => CodingConfidenceBand::StrongMatch,
                default => CodingConfidenceBand::ReviewRequired,
            };

            return [
                ...$result,
                'confidence' => $confidence,
                'evidence' => [
                    ...$result['evidence'],
                    'topScoreTieCount' => $topScoreTieCount,
                ],
                'specificityWarning' => $confidence === CodingConfidenceBand::ReviewRequired
                    ? ($ambiguousTop
                        ? 'Beberapa kandidat memiliki skor teratas yang sama. Tinjau indeks, konteks, dan detail dokumentasi sebelum memilih.'
                        : 'Kecocokan leksikal terbatas. Tinjau indeks, konteks, dan detail dokumentasi sebelum memilih.')
                    : null,
            ];
        })->all();

        /** @var list<array{concept: TerminologyConcept, confidence: CodingConfidenceBand, score: int, evidence: array<string, mixed>, specificityWarning: string|null}> $ranked */
        return $ranked;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function candidateTokens(array $tokens): array
    {
        $stopWords = [
            'and', 'the', 'with', 'without', 'of', 'in', 'on', 'for', 'to',
            'other', 'unspecified', 'dan', 'dengan', 'tanpa', 'dari', 'pada',
            'untuk', 'yang',
        ];
        $ranked = collect($tokens)
            ->reject(fn (string $token): bool => in_array($token, $stopWords, true))
            ->sortByDesc(fn (string $token): int => mb_strlen($token))
            ->take(8)
            ->values();

        if ($ranked->isEmpty()) {
            $ranked = collect($tokens)
                ->sortByDesc(fn (string $token): int => mb_strlen($token))
                ->take(2)
                ->values();
        }

        /** @var list<string> $candidateTokens */
        $candidateTokens = $ranked->all();

        return $candidateTokens;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
