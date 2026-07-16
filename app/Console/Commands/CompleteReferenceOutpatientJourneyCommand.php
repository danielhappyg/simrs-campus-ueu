<?php

namespace App\Console\Commands;

use App\Modules\Teaching\Services\ReferenceOutpatientJourneyBuilder;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class CompleteReferenceOutpatientJourneyCommand extends Command
{
    protected $signature = 'simulation:complete-reference-journey
        {--session=SIM-RJ-UEU-001 : Exact synthetic reference-session code}
        {--json : Emit the machine-readable completion summary}';

    protected $description = 'Complete the opt-in synthetic outpatient reference journey through guarded domain services';

    public function handle(ReferenceOutpatientJourneyBuilder $builder): int
    {
        try {
            $summary = $builder->complete((string) $this->option('session'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The reference journey could not be completed. No partial transaction was retained.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $this->info($summary['state'] === 'ALREADY_FINALIZED'
            ? 'The synthetic reference journey was already finalized; no records were changed.'
            : 'The synthetic outpatient reference journey reached FINALIZED.');
        $this->table(
            ['Session', 'Encounter', 'Status', 'Synthetic'],
            [[
                $summary['sessionCode'],
                $summary['encounterNumber'],
                $summary['status'],
                $summary['synthetic'] ? 'yes' : 'no',
            ]],
        );
        $this->table(
            ['Clinical versions', 'Results', 'Closures', 'Procedures', 'RMIK reviews', 'Code assignments', 'Tasks'],
            [[
                $summary['counts']['clinicalVersions'],
                $summary['counts']['results'],
                $summary['counts']['closures'],
                $summary['counts']['procedures'],
                $summary['counts']['recordQualityReviews'],
                $summary['counts']['codingAssignments'],
                $summary['counts']['workTasks'],
            ]],
        );
        $this->warn('Development fixture only — never use this command for real-patient or production clinical data.');

        return self::SUCCESS;
    }
}
