<?php

namespace App\Console\Commands;

use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Teaching\Services\ReferenceOutpatientJourneyBuilder;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class PrepareReferenceOutpatientCorrectionCommand extends Command
{
    protected $signature = 'simulation:prepare-reference-correction
        {type : diagnosis or procedure}
        {--session=SIM-RJ-UEU-001 : Exact synthetic reference-session code}
        {--json : Emit the machine-readable preparation summary}';

    protected $description = 'Prepare an active synthetic diagnosis or procedure correction through guarded domain services';

    public function handle(ReferenceOutpatientJourneyBuilder $builder): int
    {
        try {
            $sourceType = match (strtolower(trim((string) $this->argument('type')))) {
                'diagnosis' => CodingSourceType::Diagnosis,
                'procedure' => CodingSourceType::Procedure,
                default => throw new DomainException('Correction type must be diagnosis or procedure.'),
            };
            $summary = $builder->openCorrection(
                $sourceType,
                (string) $this->option('session'),
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The reference correction could not be prepared. No partial transaction was retained.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $alreadyPrepared = $summary['state'] === 'ALREADY_PREPARED';
        $this->info($alreadyPrepared
            ? 'The requested synthetic correction was already prepared; no records were changed.'
            : 'The synthetic reference correction reached its active author-response state.');
        $this->table(
            ['Session', 'Encounter', 'Encounter status', 'Source', 'Correction status'],
            [[
                $summary['sessionCode'],
                $summary['encounterNumber'],
                $summary['status'],
                $summary['correction']['sourceType'],
                $summary['correction']['status'],
            ]],
        );
        $this->warn('Development fixture only — never use this command for real-patient or production clinical data.');

        return self::SUCCESS;
    }
}
