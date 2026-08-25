<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ATTRIBUTION_INDEX = 'audit_actor_type_reference_idx';

    private const RESTRICTIVE_FOREIGN_KEY = 'audit_events_actor_user_fk';

    public function up(): void
    {
        $this->assertSupportedDriver();
        $auditTable = SchemaQualifier::table('audit_events');
        $usersTable = SchemaQualifier::table('users');

        Schema::table($auditTable, function (Blueprint $table): void {
            $table->string('actor_type', 16)->nullable();
            $table->string('actor_reference', 255)->nullable();
            $table->index(['actor_type', 'actor_reference'], self::ATTRIBUTION_INDEX);
        });

        $legacyForeign = DB::connection()->getDriverName() === 'sqlite'
            ? ['actor_user_id']
            : 'audit_events_actor_user_id_foreign';

        Schema::table($auditTable, function (Blueprint $table) use ($legacyForeign, $usersTable): void {
            $table->dropForeign($legacyForeign);
            $table->foreign('actor_user_id', self::RESTRICTIVE_FOREIGN_KEY)
                ->references('id')
                ->on($usersTable)
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->assertSupportedDriver();
        $auditTable = SchemaQualifier::table('audit_events');
        $usersTable = SchemaQualifier::table('users');
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->lockPostgresAuditTable();
        } elseif ($driver === 'sqlite') {
            DB::statement('UPDATE "audit_events" SET "id" = "id" WHERE 0');
        }

        $this->assertNoAuditEvidence($auditTable);

        $mysqlTablesLocked = false;
        try {
            if ($driver === 'mysql') {
                $this->lockMysqlTables();
                $mysqlTablesLocked = true;
            }

            // A writer may have committed between the initial check and the MySQL table lock.
            $this->assertNoAuditEvidence($auditTable);

            $restrictiveForeign = $driver === 'sqlite'
                ? ['actor_user_id']
                : self::RESTRICTIVE_FOREIGN_KEY;

            Schema::table($auditTable, function (Blueprint $table) use ($restrictiveForeign, $usersTable): void {
                $table->dropForeign($restrictiveForeign);
                $table->foreign('actor_user_id', 'audit_events_actor_user_id_foreign')
                    ->references('id')
                    ->on($usersTable)
                    ->nullOnDelete();
            });

            if ($driver === 'pgsql') {
                $schema = SchemaQualifier::primarySchema() ?? 'public';
                DB::statement(sprintf(
                    'DROP INDEX %s.%s',
                    $this->quotePostgresIdentifier($schema),
                    $this->quotePostgresIdentifier(self::ATTRIBUTION_INDEX),
                ));
                Schema::table($auditTable, function (Blueprint $table): void {
                    $table->dropColumn(['actor_type', 'actor_reference']);
                });
            } else {
                Schema::table($auditTable, function (Blueprint $table): void {
                    $table->dropIndex(self::ATTRIBUTION_INDEX);
                    $table->dropColumn(['actor_type', 'actor_reference']);
                });
            }
        } finally {
            if ($mysqlTablesLocked) {
                DB::statement('UNLOCK TABLES');
            }
        }
    }

    private function assertSupportedDriver(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('Unsupported database driver for audit actor attribution.');
        }
    }

    private function assertNoAuditEvidence(string $auditTable): void
    {
        if (DB::table($auditTable)->exists()) {
            throw new RuntimeException(
                'Refusing BG-02c2 rollback because ordinary audit evidence exists.',
            );
        }
    }

    private function lockPostgresAuditTable(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('PostgreSQL BG-02c2 rollback requires a migration transaction.');
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';
        DB::statement(sprintf(
            'LOCK TABLE %s.%s IN ACCESS EXCLUSIVE MODE',
            $this->quotePostgresIdentifier($schema),
            $this->quotePostgresIdentifier('audit_events'),
        ));
    }

    private function lockMysqlTables(): void
    {
        $database = DB::connection()->getDatabaseName();
        DB::statement(sprintf(
            'LOCK TABLES %s.%s WRITE, %s.%s WRITE',
            $this->quoteMysqlIdentifier($database),
            $this->quoteMysqlIdentifier('audit_events'),
            $this->quoteMysqlIdentifier($database),
            $this->quoteMysqlIdentifier('users'),
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
