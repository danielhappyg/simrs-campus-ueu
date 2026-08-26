<?php

namespace App\Support\Operations;

use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class SyntheticRecoverySnapshot
{
    public const DATABASE_NAME_PATTERN = '/\Asimrs_recovery_[0-9a-f]{12}_(source|restore)\z/';

    private const SENTINEL_ROUTE = 'recovery.rehearsal.synthetic';

    /**
     * Capture a canonical, value-minimized within-run fingerprint of a
     * disposable PostgreSQL 17 recovery database. No patient or account values
     * are emitted; generated fixture identifiers can differ between attempts.
     *
     * @return array<string, mixed>
     */
    public function capture(): array
    {
        $this->assertSafeBoundary();

        $snapshot = DB::transaction(function (): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            $tableNames = DB::table('information_schema.tables')
                ->where('table_schema', 'laravel')
                ->where('table_type', 'BASE TABLE')
                ->orderBy('table_name')
                ->pluck('table_name')
                ->map(fn (mixed $name): string => (string) $name)
                ->all();
            $migrationRows = DB::table(SchemaQualifier::table('migrations'))
                ->orderBy('id')
                ->get(['migration', 'batch'])
                ->map(fn (object $row): array => [
                    'migration' => (string) $row->migration,
                    'batch' => (int) $row->batch,
                ])
                ->all();
            $expectedMigrations = $this->migrationFileRows();

            if (array_column($migrationRows, 'migration') !== array_column($expectedMigrations, 'migration')) {
                throw new RuntimeException('The disposable recovery database migration ledger does not match this checkout.');
            }

            $counts = [
                'base_tables' => count($tableNames),
                'migrations' => count($migrationRows),
                'users' => DB::table(SchemaQualifier::table('users'))->count(),
                'patients' => DB::table(SchemaQualifier::table('patients'))->count(),
                'synthetic_patients' => DB::table(SchemaQualifier::table('patients'))->where('is_synthetic', true)->count(),
                'non_synthetic_patients' => DB::table(SchemaQualifier::table('patients'))->where('is_synthetic', false)->count(),
                'encounters' => DB::table(SchemaQualifier::table('encounters'))->count(),
                'clinical_entries' => DB::table(SchemaQualifier::table('clinical_entries'))->count(),
                'lab_service_requests' => DB::table(SchemaQualifier::table('lab_service_requests'))->count(),
                'lab_diagnostic_results' => DB::table(SchemaQualifier::table('lab_diagnostic_results'))->count(),
                'outpatient_documents' => DB::table(SchemaQualifier::table('outpatient_clinical_documents'))->count(),
                'outpatient_document_versions' => DB::table(SchemaQualifier::table('outpatient_clinical_document_versions'))->count(),
                'rm_completeness_reviews' => DB::table(SchemaQualifier::table('outpatient_rm_completeness_reviews'))->count(),
                'audit_events' => AuditEvent::query()->count(),
                'recovery_sentinel_events' => AuditEvent::query()
                    ->where('action', 'authorization.denied')
                    ->where('resource_type', 'http_route')
                    ->where('resource_id', self::SENTINEL_ROUTE)
                    ->where('outcome', 'DENIED')
                    ->count(),
            ];

            $orphans = [
                'encounter_without_patient' => $this->orphanCount('encounters', 'patients', 'patient_id'),
                'clinical_entry_without_encounter' => $this->orphanCount('clinical_entries', 'encounters', 'encounter_id'),
                'lab_request_without_encounter' => $this->orphanCount('lab_service_requests', 'encounters', 'encounter_id'),
                'lab_result_without_request' => $this->orphanCount('lab_diagnostic_results', 'lab_service_requests', 'lab_service_request_id'),
                'outpatient_document_without_encounter' => $this->orphanCount('outpatient_clinical_documents', 'encounters', 'encounter_id'),
                'outpatient_version_without_document' => $this->orphanCount('outpatient_clinical_document_versions', 'outpatient_clinical_documents', 'outpatient_clinical_document_id'),
                'rm_review_without_encounter' => $this->orphanCount('outpatient_rm_completeness_reviews', 'encounters', 'encounter_id'),
                'audit_user_reference_missing' => AuditEvent::query()
                    ->whereNotNull('actor_user_id')
                    ->whereDoesntHave('actor')
                    ->count(),
            ];

            if ($counts['patients'] < 1 || $counts['synthetic_patients'] !== $counts['patients']) {
                throw new RuntimeException('The recovery fixture must contain only synthetic patients.');
            }

            if ($counts['recovery_sentinel_events'] !== 1) {
                throw new RuntimeException('The recovery audit sentinel is missing or duplicated.');
            }

            if (array_sum($orphans) !== 0) {
                throw new RuntimeException('The recovery fixture contains orphaned application relationships.');
            }

            $payload = [
                'schema_version' => 1,
                'kind' => 'SIMRS_SYNTHETIC_RECOVERY_SNAPSHOT',
                'boundary' => [
                    'application_mode' => 'SIMULATION',
                    'synthetic_only' => true,
                    'database_engine' => 'postgresql',
                    'database_major' => 17,
                    'application_schema' => 'laravel',
                    'disposable_database' => true,
                    'hosted_readiness_claim' => false,
                ],
                'counts' => $counts,
                'orphans' => $orphans,
                'digests' => [
                    'table_names_sha256' => $this->digest($tableNames),
                    'migration_ledger_sha256' => $this->digest($migrationRows),
                    'migration_file_set_sha256' => $this->digest($expectedMigrations),
                    'users_sha256' => $this->tableDigest('users', ['id', 'public_id', 'status', 'is_system_administrator']),
                    'patients_sha256' => $this->tableDigest('patients', ['id', 'public_id', 'medical_record_number', 'is_synthetic', 'created_by_user_id']),
                    'encounters_sha256' => $this->tableDigest('encounters', ['id', 'public_id', 'patient_id', 'care_setting', 'status', 'registered_by_user_id']),
                    'clinical_entries_sha256' => $this->tableDigest('clinical_entries', ['id', 'public_id', 'encounter_id', 'author_user_id', 'entry_type']),
                    'lab_requests_sha256' => $this->tableDigest('lab_service_requests', ['id', 'public_id', 'encounter_id', 'requested_by_user_id', 'status']),
                    'lab_results_sha256' => $this->tableDigest('lab_diagnostic_results', ['id', 'public_id', 'lab_service_request_id', 'entered_by_user_id', 'status']),
                    'outpatient_documents_sha256' => $this->tableDigest('outpatient_clinical_documents', ['id', 'public_id', 'encounter_id', 'document_type', 'document_state', 'version']),
                    'outpatient_document_versions_sha256' => $this->tableDigest('outpatient_clinical_document_versions', ['id', 'public_id', 'outpatient_clinical_document_id', 'version', 'actor_user_id']),
                    'rm_reviews_sha256' => $this->tableDigest('outpatient_rm_completeness_reviews', ['id', 'public_id', 'encounter_id', 'version', 'review_state']),
                    'audit_events_sha256' => $this->tableDigest('audit_events', ['id', 'actor_user_id', 'actor_type', 'actor_reference', 'action', 'resource_type', 'resource_id', 'outcome', 'reason']),
                ],
            ];

            $payload['snapshot_sha256'] = hash('sha256', CanonicalJson::encode($payload));

            return $payload;
        }, 1);

        return $snapshot;
    }

    private function assertSafeBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Recovery verification requires SIMULATION mode with synthetic-only enforcement.');
        }

        if (config('database.default') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Recovery verification requires a PostgreSQL connection.');
        }

        $version = (int) DB::scalar('SHOW server_version_num');
        if (intdiv($version, 10_000) !== 17) {
            throw new RuntimeException('Recovery verification requires PostgreSQL major version 17.');
        }

        $database = (string) DB::scalar('SELECT current_database()');
        if (preg_match(self::DATABASE_NAME_PATTERN, $database) !== 1) {
            throw new RuntimeException('Recovery verification refuses a database outside the generated disposable namespace.');
        }

        if (SchemaQualifier::primarySchema() !== 'laravel') {
            throw new RuntimeException('Recovery verification requires the private laravel schema.');
        }

        $publicApplicationTables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->whereIn('table_name', ['users', 'patients', 'encounters', 'audit_events', 'migrations'])
            ->count();
        if ($publicApplicationTables !== 0) {
            throw new RuntimeException('Recovery verification found application tables in the public schema.');
        }
    }

    /** @return list<array{migration: string, sha256: string}> */
    private function migrationFileRows(): array
    {
        $files = collect(File::files(database_path('migrations')))
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename());
        $rows = [];

        foreach ($files as $file) {
            $sha256 = hash_file('sha256', $file->getPathname());
            if (! is_string($sha256)) {
                throw new RuntimeException('A migration file could not be hashed for recovery verification.');
            }

            $rows[] = [
                'migration' => $file->getBasename('.php'),
                'sha256' => $sha256,
            ];
        }

        return $rows;
    }

    /** @param list<string> $columns */
    private function tableDigest(string $table, array $columns): string
    {
        $rows = DB::table(SchemaQualifier::table($table))
            ->orderBy('id')
            ->get($columns)
            ->map(fn (object $row): array => collect((array) $row)
                ->map(fn (mixed $value): mixed => is_bool($value) ? $value : ($value === null ? null : (string) $value))
                ->all())
            ->all();

        return $this->digest($rows);
    }

    private function orphanCount(string $child, string $parent, string $foreignKey): int
    {
        return DB::table(SchemaQualifier::table($child).' as child')
            ->leftJoin(SchemaQualifier::table($parent).' as parent', 'parent.id', '=', 'child.'.$foreignKey)
            ->whereNotNull('child.'.$foreignKey)
            ->whereNull('parent.id')
            ->count();
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', CanonicalJson::encode(['value' => $value]));
    }
}
