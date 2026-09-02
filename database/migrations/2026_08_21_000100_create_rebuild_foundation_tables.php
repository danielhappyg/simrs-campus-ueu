<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = $this->supportedDriver();
        $auditTable = SchemaQualifier::table('audit_events');

        if (Schema::hasTable($auditTable)) {
            $this->assertCompatibleExistingTable($auditTable, $driver, allowKnownEvolution: true);

            return;
        }

        $usersTable = SchemaQualifier::table('users');
        Schema::create($auditTable, function (Blueprint $table) use ($usersTable): void {
            $table->ulid('id')->primary();
            $table->timestamp('recorded_at', precision: 6);
            $table->foreignId('actor_user_id')->nullable();
            $table->string('action');
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
            $table->string('resource_version')->nullable();
            $table->string('outcome')->default('SUCCESS');
            $table->string('reason')->nullable();
            $table->ulid('request_correlation_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->foreign('actor_user_id', 'audit_events_actor_user_id_foreign')
                ->references('id')
                ->on($usersTable)
                ->nullOnDelete();
            $table->index('recorded_at', 'audit_events_recorded_at_index');
            $table->index('action', 'audit_events_action_index');
            $table->index('outcome', 'audit_events_outcome_index');
            $table->index('request_correlation_id', 'audit_events_request_correlation_id_index');
            $table->index(['resource_type', 'resource_id'], 'audit_resource_lookup');
        });
    }

    public function down(): void
    {
        $driver = $this->supportedDriver();
        $auditTable = SchemaQualifier::table('audit_events');

        if (! Schema::hasTable($auditTable)) {
            return;
        }

        $sqliteTransactionOwned = false;
        $mysqlTableLocked = false;
        try {
            if ($driver === 'pgsql') {
                $this->lockPostgresTable();
            } elseif ($driver === 'sqlite') {
                if (DB::connection()->transactionLevel() < 1) {
                    DB::beginTransaction();
                    $sqliteTransactionOwned = true;
                }
                $this->lockSqliteTable();
            }

            if ($driver === 'mysql') {
                $this->lockMysqlTable();
                $mysqlTableLocked = true;
            }

            if (DB::table($auditTable)->exists()) {
                throw new RuntimeException(
                    'Refusing to roll back the rebuild foundation because retained audit evidence exists.',
                );
            }

            $this->assertCompatibleExistingTable($auditTable, $driver, allowKnownEvolution: false);
            Schema::drop($auditTable);
            if ($sqliteTransactionOwned) {
                DB::commit();
            }
        } catch (Throwable $exception) {
            if ($sqliteTransactionOwned && DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        } finally {
            if ($mysqlTableLocked) {
                DB::statement('UNLOCK TABLES');
            }
        }
    }

    private function supportedDriver(): string
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('Unsupported database driver for rebuild-foundation migration.');
        }

        return $driver;
    }

    private function assertCompatibleExistingTable(
        string $auditTable,
        string $driver,
        bool $allowKnownEvolution,
    ): void {
        $columns = collect(Schema::getColumns($auditTable))->keyBy('name');
        $indexes = Schema::getIndexes($auditTable);
        $foreignKeys = Schema::getForeignKeys($auditTable);
        $issues = [];

        $expectedColumns = [
            'id' => ['string', false, 26, null],
            'recorded_at' => ['timestamp', false, null, null],
            'actor_user_id' => ['bigint', true, null, null],
            'action' => ['string', false, 255, null],
            'resource_type' => ['string', false, 255, null],
            'resource_id' => ['string', true, 255, null],
            'resource_version' => ['string', true, 255, null],
            'outcome' => ['string', false, 255, 'SUCCESS'],
            'reason' => ['string', true, 255, null],
            'request_correlation_id' => ['string', true, 26, null],
            'ip_hash' => ['string', true, 64, null],
            'user_agent' => ['string', true, 255, null],
            'metadata' => ['json', true, null, null],
        ];

        foreach ($expectedColumns as $name => [$kind, $nullable, $length, $default]) {
            $column = $columns->get($name);
            if (! is_array($column)
                || ! $this->columnMatches($column, $driver, $kind, $nullable, $length, $default)) {
                $issues[] = "column:{$name}";
            }
        }

        $allowedExtraColumns = $allowKnownEvolution
            ? [
                'assignment_id' => ['bigint', true, null, null],
                'session_id' => ['bigint', true, null, null],
                'patient_id' => ['bigint', true, null, null],
                'encounter_id' => ['bigint', true, null, null],
                'actor_type' => ['string', true, 16, null],
                'actor_reference' => ['string', true, 255, null],
                'warehouse_operation_snapshot' => ['string', true, 64, null],
                'warehouse_result_version_snapshot' => ['unsigned_integer', true, null, null],
                'warehouse_result_digest_snapshot' => ['string', true, 64, null],
                'warehouse_control_total_snapshot' => ['unsigned_bigint', true, null, null],
            ]
            : [];
        $knownColumns = array_merge(array_keys($expectedColumns), array_keys($allowedExtraColumns));
        foreach ($columns as $name => $column) {
            if (! in_array($name, $knownColumns, true)) {
                $issues[] = "unexpected-column:{$name}";

                continue;
            }

            if (isset($allowedExtraColumns[$name])) {
                [$kind, $nullable, $length, $default] = $allowedExtraColumns[$name];
                if (! is_array($column)
                    || ! $this->columnMatches($column, $driver, $kind, $nullable, $length, $default)) {
                    $issues[] = "legacy-column:{$name}";
                }
            }
        }

        $actorTypePresent = $columns->has('actor_type');
        $actorReferencePresent = $columns->has('actor_reference');
        if ($actorTypePresent !== $actorReferencePresent) {
            $issues[] = 'partial-actor-attribution-columns';
        }

        $legacyColumnNames = ['assignment_id', 'session_id', 'patient_id', 'encounter_id'];
        $presentLegacyColumns = array_values(array_filter(
            $legacyColumnNames,
            fn (string $name): bool => $columns->has($name),
        ));
        $legacyShapePresent = count($presentLegacyColumns) === count($legacyColumnNames);
        if ($presentLegacyColumns !== [] && ! $legacyShapePresent) {
            $issues[] = 'partial-legacy-audit-context-columns';
        }

        $warehouseColumnNames = [
            'warehouse_operation_snapshot',
            'warehouse_result_version_snapshot',
            'warehouse_result_digest_snapshot',
            'warehouse_control_total_snapshot',
        ];
        $presentWarehouseColumns = array_values(array_filter(
            $warehouseColumnNames,
            fn (string $name): bool => $columns->has($name),
        ));
        $warehouseShapePresent = count($presentWarehouseColumns) === count($warehouseColumnNames);
        if ($presentWarehouseColumns !== [] && ! $warehouseShapePresent) {
            $issues[] = 'partial-warehouse-audit-columns';
        }

        $requiredIndexes = [
            [['id'], true, true],
            [['recorded_at'], false, false],
            [['action'], false, false],
            [['outcome'], false, false],
            [['request_correlation_id'], false, false],
            [['resource_type', 'resource_id'], false, false],
        ];
        foreach ($requiredIndexes as [$indexColumns, $unique, $primary]) {
            if (! $this->hasIndex($indexes, $indexColumns, $unique, $primary)) {
                $issues[] = 'index:'.implode(',', $indexColumns);
            }
        }

        $warehouseIndexes = [
            'ae_warehouse_actor_uq' => ['id', 'actor_user_id'],
            'ae_warehouse_action_resource_uq' => ['id', 'action', 'resource_type'],
            'ae_warehouse_result_identity_uq' => ['id', 'resource_id', 'warehouse_result_version_snapshot'],
            'ae_warehouse_control_uq' => ['id', 'warehouse_operation_snapshot', 'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot'],
        ];
        if ($allowKnownEvolution && $warehouseShapePresent) {
            foreach ($warehouseIndexes as $name => $indexColumns) {
                if (! $this->hasNamedIndex($indexes, $name, $indexColumns, true, false)) {
                    $issues[] = 'index:'.$name;
                }
            }
        }
        foreach ($indexes as $index) {
            $knownWarehouseIndex = $allowKnownEvolution
                && $warehouseShapePresent
                && isset($warehouseIndexes[$index['name'] ?? ''])
                && ($index['columns'] ?? null) === $warehouseIndexes[$index['name']]
                && ($index['unique'] ?? null) === true
                && ($index['primary'] ?? null) === false;
            if (($index['unique'] ?? false) === true
                && ! (($index['primary'] ?? false) === true && ($index['columns'] ?? null) === ['id'])
                && ! $knownWarehouseIndex) {
                $issues[] = 'unexpected-unique-index:'.($index['name'] ?? 'unnamed');
            }

            if (! $allowKnownEvolution
                && ! $this->isBaseMigrationIndex($index, $requiredIndexes)) {
                $issues[] = 'unexpected-index:'.($index['name'] ?? 'unnamed');
            }
        }

        if ($actorTypePresent && $actorReferencePresent
            && ! $this->hasNamedIndex(
                $indexes,
                'audit_actor_type_reference_idx',
                ['actor_type', 'actor_reference'],
                false,
                false,
            )) {
            $issues[] = 'index:actor_type,actor_reference';
        }
        $contextIndexPresent = $this->hasNamedIndex(
            $indexes,
            'audit_case_context',
            ['session_id', 'patient_id', 'encounter_id'],
            false,
            false,
        );
        if ($legacyShapePresent && ! $contextIndexPresent) {
            $issues[] = 'index:session_id,patient_id,encounter_id';
        } elseif (! $legacyShapePresent && $contextIndexPresent) {
            $issues[] = 'unexpected-index:audit_case_context';
        }

        if (count($foreignKeys) !== 1
            || ! $this->actorForeignKeyMatches(
                $foreignKeys[0] ?? null,
                $driver,
                $actorTypePresent && $actorReferencePresent,
            )) {
            $issues[] = 'foreign-key:actor_user_id';
        }

        if ($issues !== []) {
            $message = $allowKnownEvolution
                ? 'Refusing to adopt incompatible audit_events schema drift: '
                : 'Refusing to roll back non-owned or evolved audit_events schema: ';
            throw new RuntimeException(
                $message.implode(', ', $issues).'.',
            );
        }
    }

    /** @param array<string, mixed> $column */
    private function columnMatches(
        array $column,
        string $driver,
        string $kind,
        bool $nullable,
        ?int $length,
        mixed $default,
    ): bool {
        if (($column['nullable'] ?? null) !== $nullable
            || ($column['auto_increment'] ?? null) !== false
            || ($column['generation'] ?? null) !== null
            || ! $this->defaultMatches($column['default'] ?? null, $default)) {
            return false;
        }

        $typeName = is_string($column['type_name'] ?? null)
            ? strtolower(trim($column['type_name']))
            : null;
        $type = is_string($column['type'] ?? null)
            ? strtolower(trim(preg_replace('/\s+/', ' ', $column['type']) ?? ''))
            : null;

        return match ($kind) {
            'string' => $this->stringTypeMatches($driver, $typeName, $type, $length),
            'bigint' => match ($driver) {
                'pgsql' => $typeName === 'int8' && $type === 'bigint',
                'mysql' => $typeName === 'bigint' && in_array($type, ['bigint', 'bigint unsigned'], true),
                'sqlite' => $typeName === 'integer' && $type === 'integer',
                default => false,
            },
            'unsigned_integer' => match ($driver) {
                'pgsql' => $typeName === 'int4' && $type === 'integer',
                'mysql' => $typeName === 'int' && $type === 'int unsigned',
                'sqlite' => $typeName === 'integer' && $type === 'integer',
                default => false,
            },
            'unsigned_bigint' => match ($driver) {
                'pgsql' => $typeName === 'int8' && $type === 'bigint',
                'mysql' => $typeName === 'bigint' && $type === 'bigint unsigned',
                'sqlite' => $typeName === 'integer' && $type === 'integer',
                default => false,
            },
            'timestamp' => match ($driver) {
                'pgsql' => $typeName === 'timestamp'
                    && in_array($type, ['timestamp(6) without time zone', 'timestamp without time zone'], true),
                'mysql' => $typeName === 'timestamp' && in_array($type, ['timestamp', 'timestamp(6)'], true),
                'sqlite' => $typeName === 'datetime' && $type === 'datetime',
                default => false,
            },
            'json' => match ($driver) {
                'pgsql', 'mysql' => $typeName === 'json' && $type === 'json',
                'sqlite' => $typeName === 'text' && $type === 'text',
                default => false,
            },
            default => false,
        };
    }

    private function stringTypeMatches(string $driver, ?string $typeName, ?string $type, ?int $length): bool
    {
        if ($driver === 'sqlite') {
            return $typeName === 'varchar' && $type === 'varchar';
        }

        if ($length === 26 && $driver === 'pgsql') {
            return $typeName === 'bpchar' && $type === 'character(26)';
        }
        if ($length === 26 && $driver === 'mysql') {
            return $typeName === 'char' && $type === 'char(26)';
        }
        if ($driver === 'pgsql') {
            return $typeName === 'varchar' && $type === "character varying({$length})";
        }

        return $typeName === 'varchar' && $type === "varchar({$length})";
    }

    private function defaultMatches(mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if (! is_string($actual)) {
            return false;
        }

        $normalized = preg_replace('/::(?:character varying|text)\z/i', '', trim($actual));
        $normalized = trim($normalized ?? '', "'\"");

        return $normalized === $expected;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $indexes
     * @param  list<string>  $columns
     */
    private function hasIndex(array $indexes, array $columns, bool $unique, bool $primary): bool
    {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? null) === $columns
                && ($index['unique'] ?? null) === $unique
                && ($index['primary'] ?? null) === $primary) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $indexes
     * @param  list<string>  $columns
     */
    private function hasNamedIndex(
        array $indexes,
        string $name,
        array $columns,
        bool $unique,
        bool $primary,
    ): bool {
        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $name
                && ($index['columns'] ?? null) === $columns
                && ($index['unique'] ?? null) === $unique
                && ($index['primary'] ?? null) === $primary) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $index
     * @param  list<array{list<string>, bool, bool}>  $requiredIndexes
     */
    private function isBaseMigrationIndex(array $index, array $requiredIndexes): bool
    {
        foreach ($requiredIndexes as [$columns, $unique, $primary]) {
            if (($index['columns'] ?? null) === $columns
                && ($index['unique'] ?? null) === $unique
                && ($index['primary'] ?? null) === $primary) {
                return true;
            }
        }

        // MySQL automatically indexes the foreign-key column.
        return ($index['columns'] ?? null) === ['actor_user_id']
            && ($index['unique'] ?? null) === false
            && ($index['primary'] ?? null) === false;
    }

    /** @param array<string, mixed>|null $foreignKey */
    private function actorForeignKeyMatches(
        ?array $foreignKey,
        string $driver,
        bool $attributionExpanded,
    ): bool {
        if ($foreignKey === null) {
            return false;
        }

        $expectedSchema = match ($driver) {
            'pgsql' => SchemaQualifier::primarySchema() ?? 'public',
            'mysql' => DB::connection()->getDatabaseName(),
            'sqlite' => 'main',
            default => null,
        };
        $onUpdate = strtolower(trim(is_string($foreignKey['on_update'] ?? null) ? $foreignKey['on_update'] : ''));
        $onDelete = strtolower(trim(is_string($foreignKey['on_delete'] ?? null) ? $foreignKey['on_delete'] : ''));
        $deleteMatches = $attributionExpanded
            ? in_array($onDelete, ['restrict', 'no action'], true)
            : $onDelete === 'set null';
        $foreignKeyNameMatches = $driver === 'sqlite'
            || ($foreignKey['name'] ?? null) === ($attributionExpanded
                ? 'audit_events_actor_user_fk'
                : 'audit_events_actor_user_id_foreign');

        return ($foreignKey['columns'] ?? null) === ['actor_user_id']
            && $foreignKeyNameMatches
            && ($foreignKey['foreign_table'] ?? null) === 'users'
            && ($foreignKey['foreign_schema'] ?? null) === $expectedSchema
            && ($foreignKey['foreign_columns'] ?? null) === ['id']
            && in_array($onUpdate, ['restrict', 'no action'], true)
            && $deleteMatches;
    }

    private function lockPostgresTable(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('PostgreSQL rebuild-foundation rollback requires a migration transaction.');
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        DB::statement(sprintf(
            'LOCK TABLE %s.%s IN ACCESS EXCLUSIVE MODE',
            $this->quotePostgresIdentifier($schema),
            $this->quotePostgresIdentifier('audit_events'),
        ));
    }

    private function lockSqliteTable(): void
    {
        DB::statement('UPDATE "audit_events" SET "id" = "id" WHERE 0');
    }

    private function lockMysqlTable(): void
    {
        $database = DB::connection()->getDatabaseName();
        DB::statement(sprintf(
            'LOCK TABLES %s.%s WRITE',
            $this->quoteMysqlIdentifier($database),
            $this->quoteMysqlIdentifier('audit_events'),
        ));
    }

    private function quotePostgresIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteMysqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
};
