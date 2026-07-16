<?php

namespace App\Console\Commands;

use App\Modules\Coding\Services\CodingGoldSetEvaluator;
use DomainException;
use Illuminate\Console\Command;
use JsonException;

class EvaluateCodingGoldSetCommand extends Command
{
    protected $signature = 'coding:evaluate-gold-set
        {path? : JSON gold-set path; defaults to the versioned outpatient reference set}
        {--json : Emit the complete machine-readable report}
        {--fail-on-reference-miss : Return failure when a metric-eligible reference assertion misses}';

    protected $description = 'Evaluate deterministic ICD retrieval against a versioned synthetic coding gold set';

    public function handle(CodingGoldSetEvaluator $evaluator): int
    {
        $argument = $this->argument('path');
        $path = is_string($argument) && trim($argument) !== ''
            ? $argument
            : resource_path('coding/gold-sets/outpatient-reference-v1.json');

        try {
            $report = $evaluator->evaluateFile($path);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            try {
                $this->line(json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
            } catch (JsonException $exception) {
                $this->error('The coding gold-set report could not be encoded: '.$exception->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->renderHumanReport($report);
        }

        $referenceMisses = (int) data_get($report, 'summary.referenceExpectationMisses', 0);

        return $this->option('fail-on-reference-miss') && $referenceMisses > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function renderHumanReport(array $report): void
    {
        $this->info('Synthetic coding retrieval evaluation');
        $this->line('Gold set: '.data_get($report, 'goldSet.id').' '.data_get($report, 'goldSet.version'));
        $this->line('Status: '.data_get($report, 'goldSet.status').' / expert review '.data_get($report, 'goldSet.expertReviewStatus'));
        $this->line('Dataset SHA-256: '.data_get($report, 'goldSet.sourceSha256'));
        $this->line('Engine: '.data_get($report, 'engine.type').' '.data_get($report, 'engine.version'));

        /** @var array<string, array<string, int|float|null>> $bySourceType */
        $bySourceType = data_get($report, 'summary.bySourceType', []);
        $summaryRows = [];

        foreach ($bySourceType as $sourceType => $summary) {
            $summaryRows[] = [
                $sourceType,
                $summary['metricEligibleCases'],
                $this->ratio((int) $summary['top1Hits'], (int) $summary['targetCases']),
                $this->ratio((int) $summary['top5Hits'], (int) $summary['targetCases']),
                $this->ratio((int) $summary['noCandidatePasses'], (int) $summary['noCandidateCases']),
                $this->ratio((int) $summary['reviewSafetyPasses'], (int) $summary['reviewSafetyCases']),
                $summary['referenceExpectationMisses'],
            ];
        }

        $this->newLine();
        $this->table(
            ['Source', 'Eligible', 'Top-1', 'Top-5', 'No candidate', 'Review safety', 'Reference misses'],
            $summaryRows,
        );

        /** @var list<array<string, mixed>> $cases */
        $cases = $report['cases'];
        $this->table(
            ['Case', 'Cohort', 'Validation', 'Expectation', 'Rank', 'Returned codes', 'Result'],
            collect($cases)->map(fn (array $case): array => [
                $case['id'],
                $case['cohort'],
                $case['validationStatus'],
                $case['expectation'],
                $case['firstAcceptableRank'] ?? '—',
                $case['returnedCodes'] === [] ? '—' : implode(', ', $case['returnedCodes']),
                $case['expectationMet'] ? 'OBSERVED' : 'GAP',
            ])->all(),
        );

        $pending = (int) data_get($report, 'summary.pendingExpertReviewCases', 0);
        $missed = (int) data_get($report, 'summary.expectationMissed', 0);

        $this->warn("{$pending} proposed cases remain excluded from reference metrics pending expert review.");

        if ($missed > 0) {
            $this->warn("{$missed} case expectations are currently gaps; this command does not convert them into an invented accuracy threshold.");
        }

        $this->line('All output is retrieval evidence only. Human coder confirmation remains mandatory.');
    }

    private function ratio(int $hits, int $total): string
    {
        return $total === 0
            ? 'n/a'
            : sprintf('%d/%d (%.1f%%)', $hits, $total, ($hits / $total) * 100);
    }
}
