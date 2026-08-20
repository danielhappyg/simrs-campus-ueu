<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coding\Enums\CodingConfidenceBand;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Services\CodingGoldSetEvaluator;
use App\Modules\Coding\Services\TerminologySearchService;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Teaching\Models\Assignment;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class CodingGoldSetEvaluationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function test_search_guarantees_exact_matches_beyond_noisy_tokens_and_downgrades_tied_top_results(): void
    {
        $this->seedReferenceOutpatient();
        $diagnosisConcepts = [];

        for ($index = 1; $index <= 350; $index++) {
            $diagnosisConcepts[] = [
                'code' => sprintf('N%03d', $index),
                'display' => "Synthetic and distractor {$index}",
            ];
        }

        $diagnosisConcepts[] = ['code' => 'R42', 'display' => 'Dizziness and giddiness'];
        $this->activateRelease(TerminologySystem::Icd10, $diagnosisConcepts);
        $this->activateRelease(TerminologySystem::Icd9Cm, [
            ['code' => '00.11', 'display' => 'Infusion alpha'],
            ['code' => '00.17', 'display' => 'Infusion beta'],
        ]);
        $search = app(TerminologySearchService::class);

        $exact = $search->searchActive(TerminologySystem::Icd10, 'Dizziness and giddiness', 5);
        $this->assertSame('R42', $exact[0]['concept']->code);
        $this->assertSame(CodingConfidenceBand::Exact, $exact[0]['confidence']);
        $this->assertContains('EXACT_DISPLAY', $exact[0]['evidence']['features']);

        $ambiguous = $search->searchActive(TerminologySystem::Icd9Cm, 'infusion', 5);
        $this->assertCount(2, $ambiguous);
        $this->assertSame(['00.11', '00.17'], array_column(array_map(
            fn (array $candidate): array => ['code' => $candidate['concept']->code],
            $ambiguous,
        ), 'code'));
        $this->assertSame(
            [CodingConfidenceBand::ReviewRequired, CodingConfidenceBand::ReviewRequired],
            array_column($ambiguous, 'confidence'),
        );
        $this->assertSame(2, $ambiguous[0]['evidence']['topScoreTieCount']);
    }

    public function test_evaluator_reports_separate_metrics_excludes_pending_cases_and_writes_no_coding_records(): void
    {
        $this->seedReferenceOutpatient();
        $diagnosis = $this->activateRelease(TerminologySystem::Icd10, [
            ['code' => 'R42', 'display' => 'Dizziness and giddiness'],
        ]);
        $procedure = $this->activateRelease(TerminologySystem::Icd9Cm, [
            ['code' => '47.0', 'display' => 'Appendectomy'],
            ['code' => '00.11', 'display' => 'Infusion alpha'],
            ['code' => '00.17', 'display' => 'Infusion beta'],
        ]);
        $path = $this->definitionFile($diagnosis, $procedure, [
            $this->case('DX-REF-001', 'DIAGNOSIS', 'ICD_10', 'Dizziness and giddiness', 'TARGET_RANKED', ['R42'], true),
            $this->case('DX-SAFE-001', 'DIAGNOSIS', 'ICD_10', 'qzxv diagnosis', 'NO_RELIABLE_CANDIDATE', [], true),
            $this->case('DX-ID-001', 'DIAGNOSIS', 'ICD_10', 'Pusing', 'TARGET_RANKED', ['R42'], false),
            $this->case('PX-REF-001', 'PROCEDURE', 'ICD_9_CM', 'Appendectomy', 'TARGET_RANKED', ['47.0'], true),
            $this->case('PX-SAFE-001', 'PROCEDURE', 'ICD_9_CM', 'infusion', 'REVIEW_REQUIRED', [], true),
        ]);
        $before = [
            'runs' => DB::table('coding_suggestion_runs')->count(),
            'assignments' => DB::table('coding_assignments')->count(),
            'aliases' => DB::table('terminology_aliases')->count(),
            'audits' => DB::table('audit_events')->count(),
        ];

        $report = app(CodingGoldSetEvaluator::class)->evaluateFile($path);

        $this->assertSame('simrs-coding-gold-set-report.v1', $report['schema']);
        $this->assertSame(5, data_get($report, 'summary.totalCases'));
        $this->assertSame(4, data_get($report, 'summary.metricEligibleCases'));
        $this->assertSame(1, data_get($report, 'summary.pendingExpertReviewCases'));
        $this->assertSame(1, data_get($report, 'summary.expectationMissed'));
        $this->assertSame(0, data_get($report, 'summary.referenceExpectationMisses'));
        $this->assertSame(1.0, data_get($report, 'summary.bySourceType.DIAGNOSIS.top1Rate'));
        $this->assertSame(1.0, data_get($report, 'summary.bySourceType.DIAGNOSIS.top5Rate'));
        $this->assertSame(1, data_get($report, 'summary.bySourceType.DIAGNOSIS.noCandidatePasses'));
        $this->assertSame(1.0, data_get($report, 'summary.bySourceType.PROCEDURE.top1Rate'));
        $this->assertSame(1, data_get($report, 'summary.bySourceType.PROCEDURE.reviewSafetyPasses'));
        $this->assertNull(data_get($report, 'engine.accuracyThreshold'));
        $this->assertFalse((bool) data_get($report, 'cases.2.expectationMet'));
        $this->assertSame($before, [
            'runs' => DB::table('coding_suggestion_runs')->count(),
            'assignments' => DB::table('coding_assignments')->count(),
            'aliases' => DB::table('terminology_aliases')->count(),
            'audits' => DB::table('audit_events')->count(),
        ]);

        $exit = Artisan::call('coding:evaluate-gold-set', [
            'path' => $path,
            '--json' => true,
            '--fail-on-reference-miss' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $exit);
        $commandReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(5, data_get($commandReport, 'summary.totalCases'));
        $this->assertSame(0, data_get($commandReport, 'summary.referenceExpectationMisses'));
    }

    public function test_strict_command_fails_on_reference_miss_but_not_on_pending_expert_gap(): void
    {
        $this->seedReferenceOutpatient();
        $diagnosis = $this->activateRelease(TerminologySystem::Icd10, [
            ['code' => 'R42', 'display' => 'Dizziness and giddiness'],
        ]);
        $procedure = $this->activateRelease(TerminologySystem::Icd9Cm, [
            ['code' => '47.0', 'display' => 'Appendectomy'],
        ]);
        $referenceMiss = $this->definitionFile($diagnosis, $procedure, [
            $this->case('DX-REF-MISS', 'DIAGNOSIS', 'ICD_10', 'qzxv missing target', 'TARGET_RANKED', ['R42'], true),
            $this->case('PX-PENDING', 'PROCEDURE', 'ICD_9_CM', 'Appendektomi', 'TARGET_RANKED', ['47.0'], false),
        ]);

        $this->assertSame(Command::SUCCESS, Artisan::call('coding:evaluate-gold-set', [
            'path' => $referenceMiss,
            '--json' => true,
        ]));
        $this->assertSame(Command::FAILURE, Artisan::call('coding:evaluate-gold-set', [
            'path' => $referenceMiss,
            '--json' => true,
            '--fail-on-reference-miss' => true,
        ]));
    }

    public function test_release_mismatch_and_pending_case_in_reference_metrics_are_rejected(): void
    {
        $this->seedReferenceOutpatient();
        $diagnosis = $this->activateRelease(TerminologySystem::Icd10, [
            ['code' => 'R42', 'display' => 'Dizziness and giddiness'],
        ]);
        $procedure = $this->activateRelease(TerminologySystem::Icd9Cm, [
            ['code' => '47.0', 'display' => 'Appendectomy'],
        ]);
        $unsafePath = $this->definitionFile($diagnosis, $procedure, [[
            ...$this->case('DX-UNSAFE', 'DIAGNOSIS', 'ICD_10', 'Pusing', 'TARGET_RANKED', ['R42'], false),
            'metricEligible' => true,
        ]]);

        try {
            app(CodingGoldSetEvaluator::class)->evaluateFile($unsafePath);
            $this->fail('A pending expert-review case was allowed into reference metrics.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('unsafe or inconsistent', $exception->getMessage());
        }

        $mismatchPath = $this->definitionFile(
            $diagnosis,
            $procedure,
            [$this->case('DX-REF-001', 'DIAGNOSIS', 'ICD_10', 'Dizziness and giddiness', 'TARGET_RANKED', ['R42'], true)],
            diagnosisHash: str_repeat('0', 64),
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not match');
        app(CodingGoldSetEvaluator::class)->evaluateFile($mismatchPath);
    }

    public function test_versioned_reference_definition_is_synthetic_and_keeps_proposals_out_of_metrics(): void
    {
        $contents = file_get_contents(resource_path('coding/gold-sets/outpatient-reference-v1.json'));
        $this->assertIsString($contents);
        $definition = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(CodingGoldSetEvaluator::SCHEMA, $definition['schema']);
        $this->assertSame('SYNTHETIC_ONLY', $definition['mode']);
        $this->assertSame('PENDING', $definition['expertReviewStatus']);
        $this->assertNull($definition['accuracyThreshold']);
        $this->assertCount(28, $definition['cases']);

        foreach ($definition['cases'] as $case) {
            $this->assertTrue($case['synthetic']);

            if ($case['validationStatus'] === 'PENDING_EXPERT_REVIEW') {
                $this->assertFalse($case['metricEligible']);
            }
        }

        $honestyObservation = collect($definition['cases'])
            ->firstWhere('id', 'DX-ID-004');
        $this->assertIsArray($honestyObservation);
        $this->assertSame('NO_RELIABLE_CANDIDATE', $honestyObservation['expectation']);
        $this->assertSame('id', $honestyObservation['language']);
        $this->assertSame(
            'CHECKPOINT_2_UAT_20260820_HONESTY_OBSERVATION',
            $honestyObservation['provenance'],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  list<array{code: string, display: string}>  $concepts
     */
    private function activateRelease(TerminologySystem $system, array $concepts): TerminologyRelease
    {
        $facilitator = User::query()
            ->where('email', 'fasilitator.simulasi@example.invalid')
            ->firstOrFail();
        $actor = Assignment::query()->where('user_id', $facilitator->getKey())->sole();
        $sourceHash = hash('sha256', json_encode([$system->value, $concepts], JSON_THROW_ON_ERROR));
        $release = TerminologyRelease::query()->create([
            'request_key' => (string) Str::ulid(),
            'classification_system' => $system,
            'logical_version' => $system->logicalVersion(),
            'status' => TerminologyReleaseStatus::Imported,
            'source_filename' => 'synthetic-gold-set-fixture.xlsx',
            'source_sha256' => $sourceHash,
            'sheet_name' => $system->expectedSheetName(),
            'source_provenance_status' => TerminologyProvenanceStatus::SyntheticFixture,
            'imported_by_user_id' => $actor->user_id,
            'imported_by_assignment_id' => $actor->getKey(),
            'row_count' => count($concepts),
            'ignored_blank_rows' => 0,
            'validation_report' => ['schema' => 'synthetic-gold-set-fixture.v1'],
            'imported_at' => now(),
        ]);
        $normalizer = app(TerminologyNormalizer::class);
        $now = now();
        $rows = [];

        foreach ($concepts as $concept) {
            $normalizedDisplay = $normalizer->text($concept['display']);
            $rows[] = [
                'public_id' => (string) Str::ulid(),
                'terminology_release_id' => $release->getKey(),
                'code' => $concept['code'],
                'display' => $concept['display'],
                'normalized_code' => $normalizer->code($concept['code']),
                'normalized_display' => $normalizedDisplay,
                'search_tokens' => implode(' ', $normalizer->tokens($normalizedDisplay)),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('terminology_concepts')->insert($chunk);
        }

        $release->persistActivation($actor, null);

        return $release->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     */
    private function definitionFile(
        TerminologyRelease $diagnosis,
        TerminologyRelease $procedure,
        array $cases,
        ?string $diagnosisHash = null,
    ): string {
        $path = sys_get_temp_dir().'/simrs-coding-gold-set-'.Str::lower((string) Str::ulid()).'.json';
        $definition = [
            'schema' => CodingGoldSetEvaluator::SCHEMA,
            'id' => 'test-gold-set',
            'version' => '1.0.0-test',
            'status' => 'DRAFT_EXPERT_VALIDATION_REQUIRED',
            'mode' => 'SYNTHETIC_ONLY',
            'expertReviewStatus' => 'PENDING',
            'accuracyThreshold' => null,
            'releaseRequirements' => [
                TerminologySystem::Icd10->value => [
                    'logicalVersion' => $diagnosis->logical_version,
                    'sourceSha256' => $diagnosisHash ?? $diagnosis->source_sha256,
                    'rowCount' => $diagnosis->row_count,
                ],
                TerminologySystem::Icd9Cm->value => [
                    'logicalVersion' => $procedure->logical_version,
                    'sourceSha256' => $procedure->source_sha256,
                    'rowCount' => $procedure->row_count,
                ],
            ],
            'cases' => $cases,
        ];
        file_put_contents($path, json_encode($definition, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @param  list<string>  $acceptableCodes
     * @return array<string, mixed>
     */
    private function case(
        string $id,
        string $sourceType,
        string $system,
        string $statement,
        string $expectation,
        array $acceptableCodes,
        bool $metricEligible,
    ): array {
        return [
            'id' => $id,
            'sourceType' => $sourceType,
            'system' => $system,
            'cohort' => $metricEligible ? 'REFERENCE_RETRIEVAL' : 'INDONESIAN_PROPOSAL',
            'language' => $metricEligible ? 'en' : 'id',
            'validationStatus' => $metricEligible ? 'REFERENCE_ASSERTION' : 'PENDING_EXPERT_REVIEW',
            'metricEligible' => $metricEligible,
            'synthetic' => true,
            'statement' => $statement,
            'expectation' => $expectation,
            'acceptableCodes' => $acceptableCodes,
        ];
    }
}
