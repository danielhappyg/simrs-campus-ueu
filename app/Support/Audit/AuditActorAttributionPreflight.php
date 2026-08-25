<?php

namespace App\Support\Audit;

use App\Support\CanonicalJson;
use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type PreflightReport array{schema_version: int, command: string, mode: string, result: string, rows_scanned: int, counts: array<string, int>, blocking_reason_counts: array<string, int>, contract_ready: bool, backfill_ready: bool, root_digest_algorithm: string, root_digest: string}
 * @phpstan-type ManifestEntry array{locator_hmac: string, source_leaf_hmac: string, target_reference_hmac: string, classification: string, derivation_rule: string, target_type: string}
 * @phpstan-type DatabaseBinding array{driver: string, engine_version: string, database_target_hmac: string, schema_contract: string, schema_fingerprint: string, migration_count: int, migration_fingerprint: string}
 * @phpstan-type InternalSnapshot array{report: PreflightReport, entries: list<ManifestEntry>, expected_after_root: string, key_id: string, database_binding: DatabaseBinding|null}
 * @phpstan-type ManifestSnapshot array{report: PreflightReport, entries: list<ManifestEntry>, expected_after_root: string, key_id: string, database_binding: DatabaseBinding}
 */
final class AuditActorAttributionPreflight
{
    public const MAX_MANIFEST_ENTRIES = 10000;

    public const CURRENT = 'CURRENT';

    public const BACKFILLABLE_USER = 'BACKFILLABLE_USER';

    public const BACKFILLABLE_SERVICE = 'BACKFILLABLE_SERVICE';

    public const BLOCKING = 'BLOCKING';

    public const INVALID_AUDIT_ID = 'INVALID_AUDIT_ID';

    public const USER_NOT_FOUND = 'USER_NOT_FOUND';

    public const INVALID_USER_PUBLIC_ID = 'INVALID_USER_PUBLIC_ID';

    public const PARTIAL_ATTRIBUTION = 'PARTIAL_ATTRIBUTION';

    public const USER_ATTRIBUTION_MISMATCH = 'USER_ATTRIBUTION_MISMATCH';

    public const SERVICE_ATTRIBUTION_MISMATCH = 'SERVICE_ATTRIBUTION_MISMATCH';

    public const ACTOR_REQUIRED_BUT_MISSING = 'ACTOR_REQUIRED_BUT_MISSING';

    /** @var list<string> */
    public const OPERATIONAL_CODES = [
        'UNSAFE_RUNTIME',
        'UNSUPPORTED_DRIVER',
        'SCHEMA_NOT_EXPANDED',
        'DIGEST_KEY_UNAVAILABLE',
        'EXISTING_TRANSACTION_UNSAFE',
        'MANIFEST_ENTRY_LIMIT',
        'DATABASE_SCAN_FAILED',
    ];

    /** @var list<string> */
    private const CLASSIFICATIONS = [
        self::CURRENT,
        self::BACKFILLABLE_USER,
        self::BACKFILLABLE_SERVICE,
        self::BLOCKING,
    ];

    /** @var list<string> */
    private const BLOCKING_CODES = [
        self::INVALID_AUDIT_ID,
        self::USER_NOT_FOUND,
        self::INVALID_USER_PUBLIC_ID,
        self::PARTIAL_ATTRIBUTION,
        self::USER_ATTRIBUTION_MISMATCH,
        self::SERVICE_ATTRIBUTION_MISMATCH,
        self::ACTOR_REQUIRED_BUT_MISSING,
    ];

    /** @return PreflightReport */
    public function scan(): array
    {
        return $this->snapshot(false)['report'];
    }

    /**
     * Produce the preflight report and reviewed recovery candidates from the
     * same command-owned repeatable snapshot. Candidate values are keyed
     * digests only; callers never receive database identifiers or references.
     *
     * @return ManifestSnapshot
     */
    public function manifestSnapshot(): array
    {
        $snapshot = $this->snapshot(true);
        if ($snapshot['database_binding'] === null) {
            throw new RuntimeException('DATABASE_SCAN_FAILED');
        }

        return $snapshot;
    }

    /** @return InternalSnapshot */
    private function snapshot(bool $includeManifest): array
    {
        $this->assertPreconditions();

        $key = (string) config('app.key');
        $connection = DB::connection();

        try {
            if ($connection->transactionLevel() > 0) {
                throw new RuntimeException('EXISTING_TRANSACTION_UNSAFE');
            }

            if ($connection->getDriverName() === 'mysql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $connection->transaction(function () use ($connection, $key, $includeManifest): array {
                if ($connection->getDriverName() === 'pgsql') {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                return $this->scanWithinSnapshot($connection, $key, $includeManifest);
            }, 1);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), self::OPERATIONAL_CODES, true)) {
                throw $exception;
            }

            throw new RuntimeException('DATABASE_SCAN_FAILED', previous: $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('DATABASE_SCAN_FAILED', previous: $exception);
        }
    }

    private function assertPreconditions(): void
    {
        if (strtoupper((string) config('simulation.mode')) !== 'SIMULATION'
            || filter_var(config('simulation.synthetic_only'), FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new RuntimeException('UNSAFE_RUNTIME');
        }

        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('UNSUPPORTED_DRIVER');
        }

        $auditTable = SchemaQualifier::table('audit_events');
        if (! Schema::hasColumns($auditTable, [
            'id',
            'action',
            'actor_user_id',
            'actor_type',
            'actor_reference',
        ])) {
            throw new RuntimeException('SCHEMA_NOT_EXPANDED');
        }

        if (trim((string) config('app.key')) === '') {
            throw new RuntimeException('DIGEST_KEY_UNAVAILABLE');
        }
    }

    /** @return InternalSnapshot */
    private function scanWithinSnapshot(Connection $connection, string $key, bool $includeManifest): array
    {
        $counts = array_fill_keys(self::CLASSIFICATIONS, 0);
        $blockingReasonCounts = array_fill_keys(self::BLOCKING_CODES, 0);
        $digest = hash_init('sha256', HASH_HMAC, $key);
        hash_update($digest, "SIMRS-AUDIT-ACTOR-ATTRIBUTION-PREFLIGHT\0V1\0");
        $expectedAfterDigest = hash_init('sha256', HASH_HMAC, $key);
        hash_update($expectedAfterDigest, "SIMRS-AUDIT-ACTOR-ATTRIBUTION-PREFLIGHT\0V1\0");
        $entries = [];
        $rowsScanned = 0;
        $auditTable = SchemaQualifier::table('audit_events');
        $usersTable = SchemaQualifier::table('users');

        $rows = $connection->table($auditTable.' as ae')
            ->leftJoin($usersTable.' as u', 'u.id', '=', 'ae.actor_user_id')
            ->select([
                'ae.id as audit_id',
                'ae.action',
                'ae.actor_user_id',
                'ae.actor_type',
                'ae.actor_reference',
                'u.id as resolved_user_id',
                'u.public_id as resolved_user_public_id',
            ])
            ->lazyById(500, 'ae.id', 'audit_id');

        foreach ($rows as $row) {
            $auditId = $row->audit_id ?? null;
            $action = $row->action ?? null;
            $actorUserId = $row->actor_user_id ?? null;
            $actorType = $row->actor_type ?? null;
            $actorReference = $row->actor_reference ?? null;
            $resolvedUserId = $row->resolved_user_id ?? null;
            $resolvedUserPublicId = $row->resolved_user_public_id ?? null;
            $actorUserId = $this->normalizeInteger($actorUserId);
            $resolvedUserId = $this->normalizeInteger($resolvedUserId);
            [$classification, $blockingCode] = $this->classify(
                auditId: $auditId,
                action: $action,
                actorUserId: $actorUserId,
                actorType: $actorType,
                actorReference: $actorReference,
                resolvedUserId: $resolvedUserId,
                resolvedUserPublicId: $resolvedUserPublicId,
            );

            $counts[$classification]++;
            if ($blockingCode !== null) {
                $blockingReasonCounts[$blockingCode]++;
            }
            $rowsScanned++;

            $leaf = $this->frame([
                $auditId,
                $action,
                $actorUserId,
                $actorType,
                $actorReference,
                $resolvedUserId,
                $resolvedUserPublicId,
                $classification,
                $blockingCode,
            ]);
            hash_update($digest, pack('N', strlen($leaf)).$leaf);

            $afterActorType = $actorType;
            $afterActorReference = $actorReference;
            $afterClassification = $classification;
            $afterBlockingCode = $blockingCode;

            if ($classification === self::BACKFILLABLE_USER) {
                $afterActorType = AuditActorAttribution::TYPE_USER;
                $afterActorReference = $resolvedUserPublicId;
                $afterClassification = self::CURRENT;
                $afterBlockingCode = null;
            } elseif ($classification === self::BACKFILLABLE_SERVICE) {
                $afterActorType = AuditActorAttribution::TYPE_SERVICE;
                $afterActorReference = $this->legacyBackfillableServiceReference($action);
                $afterClassification = self::CURRENT;
                $afterBlockingCode = null;
            }

            $afterLeaf = $this->frame([
                $auditId,
                $action,
                $actorUserId,
                $afterActorType,
                $afterActorReference,
                $resolvedUserId,
                $resolvedUserPublicId,
                $afterClassification,
                $afterBlockingCode,
            ]);
            hash_update($expectedAfterDigest, pack('N', strlen($afterLeaf)).$afterLeaf);

            if ($includeManifest && in_array($classification, [self::BACKFILLABLE_USER, self::BACKFILLABLE_SERVICE], true)) {
                if (count($entries) >= self::MAX_MANIFEST_ENTRIES) {
                    throw new RuntimeException('MANIFEST_ENTRY_LIMIT');
                }
                $targetType = $classification === self::BACKFILLABLE_USER
                    ? AuditActorAttribution::TYPE_USER
                    : AuditActorAttribution::TYPE_SERVICE;
                $derivationRule = $classification === self::BACKFILLABLE_USER
                    ? 'USER_FROM_RESTRICTED_FK_RECOVERY_V1'
                    : 'SERVICE_REBUILD_ADMIN_V1';

                $entries[] = [
                    'locator_hmac' => $this->keyedValue($key, 'LOCATOR', $this->frame([$auditId])),
                    'source_leaf_hmac' => $this->keyedValue($key, 'SOURCE_LEAF', $leaf),
                    'target_reference_hmac' => $this->keyedValue($key, 'TARGET_REFERENCE', $this->frame([$afterActorReference])),
                    'classification' => $classification,
                    'derivation_rule' => $derivationRule,
                    'target_type' => $targetType,
                ];
            }
        }

        $result = $counts[self::BLOCKING] > 0
            ? self::BLOCKING
            : (($counts[self::BACKFILLABLE_USER] + $counts[self::BACKFILLABLE_SERVICE]) > 0
                ? 'BACKFILL_REQUIRED'
                : self::CURRENT);

        $report = [
            'schema_version' => 1,
            'command' => 'audit:attribution:preflight',
            'mode' => 'READ_ONLY',
            'result' => $result,
            'rows_scanned' => $rowsScanned,
            'counts' => $counts,
            'blocking_reason_counts' => $blockingReasonCounts,
            'contract_ready' => $result === self::CURRENT,
            'backfill_ready' => $result !== self::BLOCKING,
            'root_digest_algorithm' => 'hmac-sha256-length-prefixed-v1',
            'root_digest' => hash_final($digest),
        ];

        return [
            'report' => $report,
            'entries' => $entries,
            'expected_after_root' => hash_final($expectedAfterDigest),
            'key_id' => substr($this->keyedValue($key, 'KEY_ID', 'BG-02C4A'), 0, 24),
            'database_binding' => $includeManifest ? $this->databaseBinding($connection, $key) : null,
        ];
    }

    /** @return DatabaseBinding */
    private function databaseBinding(Connection $connection, string $key): array
    {
        $driver = $connection->getDriverName();
        $version = match ($driver) {
            'sqlite' => (string) ($connection->selectOne('select sqlite_version() as version')->version ?? ''),
            'pgsql' => (string) ($connection->selectOne("select current_setting('server_version') as version")->version ?? ''),
            'mysql' => (string) ($connection->selectOne('select version() as version')->version ?? ''),
            default => throw new RuntimeException('UNSUPPORTED_DRIVER'),
        };

        $schema = $connection->getSchemaBuilder();
        $schemaEvidence = [];
        foreach (['audit_events', 'users'] as $table) {
            $qualified = SchemaQualifier::table($table);
            $schemaEvidence[$table] = [
                'columns' => $schema->getColumns($qualified),
                'indexes' => $schema->getIndexes($qualified),
                'foreign_keys' => $schema->getForeignKeys($qualified),
            ];
        }
        $this->assertAttributionSchemaContract($connection, $schemaEvidence);

        $migrations = $connection->table(SchemaQualifier::table('migrations'))
            ->orderBy('migration')
            ->get(['migration', 'batch'])
            ->map(fn (object $row): array => [(string) $row->migration, (int) $row->batch])
            ->all();
        $connectionConfig = $connection->getConfig();
        $targetMaterial = CanonicalJson::encode([
            'driver' => $driver,
            'host' => $connectionConfig['host'] ?? null,
            'port' => $connectionConfig['port'] ?? null,
            'database' => $connectionConfig['database'] ?? null,
            'schema' => SchemaQualifier::primarySchema(),
        ]);

        return [
            'driver' => $driver,
            'engine_version' => $version,
            'database_target_hmac' => hash_hmac('sha256', "SIMRS-DATABASE-TARGET\0V1\0".$targetMaterial, $key),
            'schema_contract' => 'audit-attribution-expanded-v1',
            'schema_fingerprint' => hash('sha256', CanonicalJson::encode($schemaEvidence)),
            'migration_count' => count($migrations),
            'migration_fingerprint' => hash('sha256', CanonicalJson::encode(['migrations' => $migrations])),
        ];
    }

    /** @param array<string, array<string, mixed>> $schemaEvidence */
    private function assertAttributionSchemaContract(Connection $connection, array $schemaEvidence): void
    {
        $audit = $schemaEvidence['audit_events'];
        $users = $schemaEvidence['users'];
        $driver = $connection->getDriverName();
        $actorUser = $this->columnDefinition($audit['columns'] ?? null, 'actor_user_id');
        $userId = $this->columnDefinition($users['columns'] ?? null, 'id');
        $actorType = $this->columnDefinition($audit['columns'] ?? null, 'actor_type');
        $actorReference = $this->columnDefinition($audit['columns'] ?? null, 'actor_reference');
        $publicId = $this->columnDefinition($users['columns'] ?? null, 'public_id');
        $idTypesMatch = $actorUser !== null && $userId !== null
            && ($actorUser['nullable'] ?? null) === true
            && $this->normalizedColumnType($actorUser) !== null
            && $this->normalizedColumnType($actorUser) === $this->normalizedColumnType($userId);
        $expandedStringColumnsMatch = $this->stringColumnMatches($actorType, true, 16, $driver)
            && $this->stringColumnMatches($actorReference, true, 255, $driver);
        $publicIdMatches = $this->stringColumnMatches($publicId, false, 26, $driver);
        $attributionIndexExists = $this->hasIndex(
            $audit['indexes'] ?? null,
            ['actor_type', 'actor_reference'],
        );
        $publicIdUnique = $this->hasGloballyEnforcedPublicIdUniqueness($connection);
        $restrictiveActorForeignKey = $this->hasRestrictiveActorForeignKey(
            $audit['foreign_keys'] ?? null,
            $this->expectedForeignSchema($connection),
        );

        if (! $idTypesMatch || ! $expandedStringColumnsMatch || ! $publicIdMatches || ! $attributionIndexExists
            || ! $publicIdUnique || ! $restrictiveActorForeignKey) {
            throw new RuntimeException('SCHEMA_NOT_EXPANDED');
        }
    }

    /** @return array<string, mixed>|null */
    private function columnDefinition(mixed $columns, string $name): ?array
    {
        if (! is_array($columns)) {
            return null;
        }
        foreach ($columns as $column) {
            if (is_array($column) && ($column['name'] ?? null) === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $column */
    private function stringColumnMatches(?array $column, bool $nullable, int $length, string $driver): bool
    {
        if ($column === null || ($column['nullable'] ?? null) !== $nullable) {
            return false;
        }
        $typeName = is_string($column['type_name'] ?? null)
            ? strtolower($column['type_name'])
            : null;
        $type = $this->normalizedColumnType($column);
        if (! in_array($typeName, ['bpchar', 'char', 'varchar'], true) || $type === null) {
            return false;
        }
        if ($driver === 'sqlite') {
            return in_array($type, ['char', 'varchar'], true);
        }

        return preg_match('/\A(?:char|character|varchar|character varying)\('.$length.'\)\z/', $type) === 1;
    }

    /** @param array<string, mixed> $column */
    private function normalizedColumnType(array $column): ?string
    {
        if (! is_string($column['type'] ?? null)) {
            return null;
        }

        return strtolower(trim(preg_replace('/\s+/', ' ', $column['type']) ?? ''));
    }

    /** @param list<string> $columns */
    private function hasIndex(mixed $indexes, array $columns, ?bool $unique = null): bool
    {
        if (! is_array($indexes)) {
            return false;
        }
        foreach ($indexes as $index) {
            if (is_array($index) && ($index['columns'] ?? null) === $columns
                && ($unique === null || ($index['unique'] ?? null) === $unique)) {
                return true;
            }
        }

        return false;
    }

    private function hasRestrictiveActorForeignKey(mixed $foreignKeys, string $expectedSchema): bool
    {
        if (! is_array($foreignKeys)) {
            return false;
        }
        $qualifiedUsers = SchemaQualifier::table('users');
        foreach ($foreignKeys as $foreign) {
            if (! is_array($foreign)) {
                continue;
            }
            $onDelete = strtolower(trim(is_string($foreign['on_delete'] ?? null) ? $foreign['on_delete'] : ''));
            if (($foreign['columns'] ?? null) === ['actor_user_id']
                && in_array($foreign['foreign_table'] ?? null, ['users', $qualifiedUsers], true)
                && ($foreign['foreign_schema'] ?? null) === $expectedSchema
                && ($foreign['foreign_columns'] ?? null) === ['id']
                && in_array($onDelete, ['restrict', 'no action'], true)) {
                return true;
            }
        }

        return false;
    }

    private function expectedForeignSchema(Connection $connection): string
    {
        return match ($connection->getDriverName()) {
            'pgsql' => SchemaQualifier::primarySchema() ?? 'public',
            'mysql' => $connection->getDatabaseName(),
            'sqlite' => 'main',
            default => throw new RuntimeException('UNSUPPORTED_DRIVER'),
        };
    }

    private function hasGloballyEnforcedPublicIdUniqueness(Connection $connection): bool
    {
        return match ($connection->getDriverName()) {
            'pgsql' => $this->postgresPublicIdUniqueness($connection),
            'mysql' => $this->mysqlPublicIdUniqueness($connection),
            'sqlite' => $this->sqlitePublicIdUniqueness($connection),
            default => throw new RuntimeException('UNSUPPORTED_DRIVER'),
        };
    }

    private function postgresPublicIdUniqueness(Connection $connection): bool
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $match = $connection->selectOne(<<<'SQL'
            select exists (
                select 1
                from pg_constraint c
                join pg_class t on t.oid = c.conrelid
                join pg_namespace n on n.oid = t.relnamespace
                join pg_index i on i.indexrelid = c.conindid
                where n.nspname = ?
                  and t.relname = 'users'
                  and c.conname = 'users_public_id_unique'
                  and c.contype = 'u'
                  and c.convalidated
                  and i.indisunique
                  and i.indisvalid
                  and i.indisready
                  and i.indpred is null
                  and i.indexprs is null
                  and array_length(c.conkey, 1) = 1
                  and c.conkey[1] = (
                      select a.attnum
                      from pg_attribute a
                      where a.attrelid = t.oid and a.attname = 'public_id' and not a.attisdropped
                  )
            ) as matches
            SQL, [$schema]);

        return filter_var($match->matches ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function mysqlPublicIdUniqueness(Connection $connection): bool
    {
        $match = $connection->selectOne(<<<'SQL'
            select count(*) as matches
            from (
                select index_name
                from information_schema.statistics
                where table_schema = ?
                  and table_name = 'users'
                  and index_name = 'users_public_id_unique'
                group by index_name
                having max(non_unique) = 0
                   and count(*) = 1
                   and max(case when column_name = 'public_id' and seq_in_index = 1 then 1 else 0 end) = 1
            ) as enforced_unique
            SQL, [$connection->getDatabaseName()]);

        return (int) ($match->matches ?? 0) === 1;
    }

    private function sqlitePublicIdUniqueness(Connection $connection): bool
    {
        $index = $connection->selectOne(<<<'SQL'
            select "unique" as is_unique, partial
            from pragma_index_list('users')
            where name = 'users_public_id_unique'
            SQL);
        if ((int) ($index->is_unique ?? 0) !== 1 || (int) ($index->partial ?? 1) !== 0) {
            return false;
        }
        $columns = $connection->select("select name from pragma_index_info('users_public_id_unique') order by seqno");

        return array_map(fn (object $column): mixed => $column->name ?? null, $columns) === ['public_id'];
    }

    /** @return array{string, string|null} */
    private function classify(
        mixed $auditId,
        mixed $action,
        mixed $actorUserId,
        mixed $actorType,
        mixed $actorReference,
        mixed $resolvedUserId,
        mixed $resolvedUserPublicId,
    ): array {
        if (! $this->isUppercaseUlid($auditId)) {
            return [self::BLOCKING, self::INVALID_AUDIT_ID];
        }

        $hasType = $actorType !== null;
        $hasReference = $actorReference !== null;
        if ($hasType !== $hasReference) {
            return [self::BLOCKING, self::PARTIAL_ATTRIBUTION];
        }

        if ($actorUserId !== null) {
            if ($resolvedUserId === null || (string) $resolvedUserId !== (string) $actorUserId) {
                return [self::BLOCKING, self::USER_NOT_FOUND];
            }

            if (! $this->isUppercaseUlid($resolvedUserPublicId)) {
                return [self::BLOCKING, self::INVALID_USER_PUBLIC_ID];
            }

            if (! $hasType) {
                return [self::BACKFILLABLE_USER, null];
            }

            if ($actorType === AuditActorAttribution::TYPE_USER
                && $actorReference === $resolvedUserPublicId) {
                return [self::CURRENT, null];
            }

            return [self::BLOCKING, self::USER_ATTRIBUTION_MISMATCH];
        }

        $serviceReference = is_string($action)
            ? $this->registeredServiceReference($action)
            : null;

        if (! $hasType) {
            return $this->legacyBackfillableServiceReference($action) === null
                ? [self::BLOCKING, self::ACTOR_REQUIRED_BUT_MISSING]
                : [self::BACKFILLABLE_SERVICE, null];
        }

        if ($serviceReference !== null
            && $actorType === AuditActorAttribution::TYPE_SERVICE
            && $actorReference === $serviceReference) {
            return [self::CURRENT, null];
        }

        return [self::BLOCKING, self::SERVICE_ATTRIBUTION_MISMATCH];
    }

    private function registeredServiceReference(string $action): ?string
    {
        try {
            $attribution = app(AuditActorAttribution::class)->forRecording($action, null);

            return $attribution['actor_reference'];
        } catch (InvalidAuditEvent) {
            return null;
        }
    }

    private function legacyBackfillableServiceReference(mixed $action): ?string
    {
        // Rebuild-admin reconciliation has always been service-only. Teaching
        // resets historically accepted an optional user, whose old SET NULL FK
        // could have erased identity evidence, so null reset actors must block.
        return $action === 'authorization.rebuild_admin.reconciled'
            ? AuditActorAttribution::REBUILD_ADMIN_SERVICE
            : null;
    }

    private function isUppercaseUlid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) === 1;
    }

    private function normalizeInteger(mixed $value): mixed
    {
        if (is_string($value) && ctype_digit($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_INT);

            return $normalized === false ? $value : $normalized;
        }

        return $value;
    }

    /** @param list<mixed> $values */
    private function frame(array $values): string
    {
        $framed = '';

        foreach ($values as $value) {
            if ($value === null) {
                $type = 'N';
                $bytes = '';
            } elseif (is_int($value)) {
                $type = 'I';
                $bytes = (string) $value;
            } elseif (is_bool($value)) {
                $type = 'B';
                $bytes = $value ? '1' : '0';
            } elseif (is_float($value)) {
                $type = 'F';
                $bytes = serialize($value);
            } elseif (is_string($value)) {
                $type = 'S';
                $bytes = $value;
            } else {
                $type = 'X';
                $bytes = get_debug_type($value);
            }

            $framed .= $type.pack('N', strlen($bytes)).$bytes;
        }

        return $framed;
    }

    private function keyedValue(string $key, string $purpose, string $value): string
    {
        return hash_hmac('sha256', "SIMRS-AUDIT-ACTOR-ATTRIBUTION-MANIFEST\0V1\0{$purpose}\0".$value, $key);
    }
}
