<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Services\TerminologyImportService;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportTerminologyReleaseCommand extends Command
{
    protected $signature = 'terminology:import
        {system : ICD_10 or ICD_9_CM}
        {path : Absolute path to the verified XLSX workbook}
        {--sha256= : Required approved SHA-256 checksum}
        {--actor=fasilitator.simulasi@example.invalid : Import actor email}
        {--inactive : Import without activating the release}';

    protected $description = 'Validate and atomically import a versioned ICD terminology release';

    public function handle(TerminologyImportService $importService): int
    {
        $system = TerminologySystem::tryFrom((string) $this->argument('system'));

        if (! $system) {
            $this->error('System must be ICD_10 or ICD_9_CM.');

            return self::INVALID;
        }

        $sha256 = $this->option('sha256');

        if (! is_string($sha256) || $sha256 === '') {
            $this->error('The --sha256 option is required.');

            return self::INVALID;
        }

        $user = User::query()->where('email', (string) $this->option('actor'))->first();

        if (! $user) {
            $this->error('The terminology import actor was not found.');

            return self::FAILURE;
        }

        $assignment = Assignment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->get()
            ->first(fn (Assignment $candidate): bool => $candidate->hasCapability(Capability::TerminologyManage));

        if (! $assignment) {
            $this->error('The import actor has no active terminology-management assignment.');

            return self::FAILURE;
        }

        try {
            $release = $importService->importXlsx(
                path: (string) $this->argument('path'),
                system: $system,
                expectedSha256: $sha256,
                actorAssignment: $assignment,
                requestKey: (string) Str::ulid(),
                activate: ! $this->option('inactive'),
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$release->classification_system->label()} {$release->logical_version} imported.");
        $this->line("Release: {$release->public_id}");
        $this->line("Status: {$release->status->value}");
        $this->line("Rows: {$release->row_count}");
        $this->line("SHA-256: {$release->source_sha256}");

        return self::SUCCESS;
    }
}
