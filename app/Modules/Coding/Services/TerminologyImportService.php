<?php

namespace App\Modules\Coding\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TerminologyImportService
{
    public function __construct(
        private readonly XlsxTerminologyReader $reader,
        private readonly TerminologyNormalizer $normalizer,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function importXlsx(
        string $path,
        TerminologySystem $system,
        string $expectedSha256,
        Assignment $actorAssignment,
        string $requestKey,
        TerminologyProvenanceStatus $provenance = TerminologyProvenanceStatus::VerifiedUserSupplied,
        bool $activate = true,
    ): TerminologyRelease {
        $actor = Assignment::query()
            ->active()
            ->with(['user', 'session'])
            ->whereKey($actorAssignment->getKey())
            ->first();

        if (! $actor || ! $actor->hasCapability(Capability::TerminologyManage)) {
            throw new DomainException('An active terminology-management assignment is required for import.');
        }

        $expectedHash = strtolower(trim($expectedSha256));

        if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            throw new DomainException('A complete expected SHA-256 is required before terminology import.');
        }

        $actualHash = hash_file('sha256', $path);

        if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
            throw new DomainException('The terminology workbook checksum does not match the explicitly approved SHA-256.');
        }

        $workbook = $this->reader->read($path, $system);
        $validationReport = [
            'schema' => 'terminology-import-validation.v1',
            'headers' => $workbook['headers'],
            'expectedHeaders' => ['CODE', 'DISPLAY', 'VERSION'],
            'classificationSystem' => $system->value,
            'logicalVersion' => $system->logicalVersion(),
            'sourceHashVerified' => true,
            'populatedRows' => count($workbook['rows']),
            'ignoredBlankRows' => $workbook['ignoredBlankRows'],
            'partialBlankRows' => 0,
            'duplicateCodes' => 0,
            'formulaCells' => 0,
        ];

        return DB::transaction(function () use (
            $actor,
            $activate,
            $actualHash,
            $path,
            $provenance,
            $requestKey,
            $system,
            $validationReport,
            $workbook,
        ): TerminologyRelease {
            $byRequest = TerminologyRelease::query()->where('request_key', $requestKey)->lockForUpdate()->first();

            if ($byRequest) {
                if ($byRequest->classification_system !== $system
                    || ! hash_equals($byRequest->source_sha256, $actualHash)) {
                    throw new DomainException('The terminology import request key was already used in another context.');
                }

                return $byRequest;
            }

            $bySource = TerminologyRelease::query()
                ->where('classification_system', $system)
                ->where('logical_version', $system->logicalVersion())
                ->where('source_sha256', $actualHash)
                ->lockForUpdate()
                ->first();

            if ($bySource) {
                return $bySource;
            }

            $release = TerminologyRelease::query()->create([
                'request_key' => $requestKey,
                'classification_system' => $system,
                'logical_version' => $system->logicalVersion(),
                'status' => TerminologyReleaseStatus::Imported,
                'source_filename' => Str::limit(basename(str_replace('\\', '/', $path)), 255, ''),
                'source_sha256' => $actualHash,
                'sheet_name' => $workbook['sheetName'],
                'source_provenance_status' => $provenance,
                'imported_by_user_id' => $actor->user_id,
                'imported_by_assignment_id' => $actor->getKey(),
                'row_count' => count($workbook['rows']),
                'ignored_blank_rows' => $workbook['ignoredBlankRows'],
                'validation_report' => $validationReport,
                'imported_at' => now(),
                'activated_by_user_id' => null,
                'activated_by_assignment_id' => null,
                'activated_at' => null,
                'supersedes_release_id' => null,
            ]);

            $now = now();
            $conceptRows = [];

            foreach ($workbook['rows'] as $row) {
                $normalizedDisplay = $this->normalizer->text($row['display']);
                $conceptRows[] = [
                    'public_id' => (string) Str::ulid(),
                    'terminology_release_id' => $release->getKey(),
                    'code' => $row['code'],
                    'display' => $row['display'],
                    'normalized_code' => $this->normalizer->code($row['code']),
                    'normalized_display' => $normalizedDisplay,
                    'search_tokens' => implode(' ', $this->normalizer->tokens($normalizedDisplay)),
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($conceptRows) === 100) {
                    DB::table('terminology_concepts')->insert($conceptRows);
                    $conceptRows = [];
                }
            }

            if ($conceptRows !== []) {
                DB::table('terminology_concepts')->insert($conceptRows);
            }

            $superseded = null;

            if ($activate) {
                $superseded = TerminologyRelease::query()
                    ->where('classification_system', $system)
                    ->where('status', TerminologyReleaseStatus::Active)
                    ->lockForUpdate()
                    ->first();
                $superseded?->persistSuperseded();
                $release->persistActivation($actor, $superseded);
            }

            $this->auditRecorder->record(
                action: $activate ? 'terminology.release_imported_and_activated' : 'terminology.release_imported',
                resourceType: 'terminology_release',
                resourceId: $release->public_id,
                actor: $actor->user,
                assignment: $actor,
                session: $actor->session,
                metadata: [
                    'classification_system' => $system->value,
                    'logical_version' => $release->logical_version,
                    'source_sha256' => $release->source_sha256,
                    'row_count' => $release->row_count,
                    'ignored_blank_rows' => $release->ignored_blank_rows,
                    'superseded_release_public_id' => $superseded?->public_id,
                ],
            );

            return $release->refresh()->loadCount('concepts');
        });
    }
}
