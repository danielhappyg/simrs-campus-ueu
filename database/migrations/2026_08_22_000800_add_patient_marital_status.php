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
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('Unsupported database driver for patient marital-status migration.');
        }

        $patientsTable = SchemaQualifier::table('patients');
        if (! Schema::hasTable($patientsTable)) {
            throw new RuntimeException('Cannot add patient marital status because the patients table is missing.');
        }

        $column = $this->columnDefinition(Schema::getColumns($patientsTable), 'marital_status');
        if ($column !== null) {
            $this->assertCompatibleExistingColumn($column, $driver, allowHostedPostgresType: true);

            return;
        }

        Schema::table($patientsTable, function (Blueprint $table): void {
            $table->string('marital_status')->nullable()->after('sex');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('Unsupported database driver for patient marital-status migration.');
        }

        $patientsTable = SchemaQualifier::table('patients');
        if (! Schema::hasTable($patientsTable)) {
            throw new RuntimeException('Cannot roll back patient marital status because the patients table is missing.');
        }

        $sqliteTransactionOwned = false;
        $mysqlTableLocked = false;
        try {
            if ($driver === 'pgsql') {
                $this->lockPostgresPatientsTable();
            } elseif ($driver === 'sqlite') {
                if (DB::connection()->transactionLevel() < 1) {
                    DB::beginTransaction();
                    $sqliteTransactionOwned = true;
                }
                $this->lockSqlitePatientsTable();
            }

            if ($driver === 'mysql') {
                $this->lockMysqlPatientsTable();
                $mysqlTableLocked = true;
            }

            if (DB::table($patientsTable)->exists()) {
                throw new RuntimeException(
                    'Refusing to roll back patient marital status because retained patient records exist.',
                );
            }

            $column = $this->columnDefinition(Schema::getColumns($patientsTable), 'marital_status');
            if ($column === null) {
                throw new RuntimeException('Cannot roll back patient marital status because its column is missing.');
            }
            $this->assertCompatibleExistingColumn($column, $driver, allowHostedPostgresType: false);

            Schema::table($patientsTable, function (Blueprint $table): void {
                $table->dropColumn('marital_status');
            });
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

    /**
     * @param  array<array-key, array<string, mixed>>  $columns
     * @return array<string, mixed>|null
     */
    private function columnDefinition(array $columns, string $name): ?array
    {
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $column */
    private function assertCompatibleExistingColumn(
        array $column,
        string $driver,
        bool $allowHostedPostgresType,
    ): void {
        $typeName = is_string($column['type_name'] ?? null)
            ? strtolower(trim($column['type_name']))
            : null;
        $type = is_string($column['type'] ?? null)
            ? strtolower(trim(preg_replace('/\s+/', ' ', $column['type']) ?? ''))
            : null;

        $typeMatches = match ($driver) {
            'pgsql' => $typeName === 'varchar'
                && ($allowHostedPostgresType
                    ? in_array($type, ['character varying', 'character varying(255)', 'varchar', 'varchar(255)'], true)
                    : in_array($type, ['character varying(255)', 'varchar(255)'], true)),
            'mysql' => $typeName === 'varchar' && $type === 'varchar(255)',
            'sqlite' => $typeName === 'varchar' && $type === 'varchar',
            default => false,
        };

        if (! $typeMatches
            || ($column['nullable'] ?? null) !== true
            || ($column['default'] ?? null) !== null
            || ($column['auto_increment'] ?? null) !== false
            || ($column['generation'] ?? null) !== null) {
            throw new RuntimeException(
                'Refusing to adopt incompatible patients.marital_status schema drift.',
            );
        }
    }

    private function lockPostgresPatientsTable(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('PostgreSQL patient marital-status rollback requires a migration transaction.');
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        DB::statement(sprintf(
            'LOCK TABLE %s.%s IN ACCESS EXCLUSIVE MODE',
            $this->quotePostgresIdentifier($schema),
            $this->quotePostgresIdentifier('patients'),
        ));
    }

    private function lockSqlitePatientsTable(): void
    {
        DB::statement('UPDATE "patients" SET "id" = "id" WHERE 0');
    }

    private function lockMysqlPatientsTable(): void
    {
        $database = DB::connection()->getDatabaseName();
        DB::statement(sprintf(
            'LOCK TABLES %s.%s WRITE',
            $this->quoteMysqlIdentifier($database),
            $this->quoteMysqlIdentifier('patients'),
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
