<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $sequenceTables = [
        'security_ledger_entries',
        'security_ledger_outboxes',
    ];

    /** @var list<string> */
    private array $protectedTables = [
        // audit_events remains outside this guard until its closed pre-insert registry is delivered.
        'security_ledger_entries',
        'break_glass_requests',
        'break_glass_decisions',
        'break_glass_activations',
        'break_glass_revocations',
        'break_glass_session_bindings',
    ];

    public function up(): void
    {
        $this->assertSupportedDriver();

        Schema::create('security_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->timestamp('recorded_at', precision: 6);
            $table->foreignId('actor_user_id')->nullable()->constrained('users', indexName: 'sle_actor_user_fk')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->string('actor_reference', 255);
            $table->string('event_type', 128);
            $table->string('resource_type', 64);
            $table->string('resource_public_id', 255);
            $table->string('outcome', 16);
            $table->text('reason');
            $table->string('environment', 32);
            $table->string('release_sha', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->unsignedSmallInteger('schema_version');
            $table->json('payload');
            $table->string('payload_digest', 64);
            $table->string('semantic_key', 64);
            $table->string('integrity_digest', 64);
            $table->timestamp('created_at', precision: 6);

            $table->unique('public_id', 'sle_public_id_uq');
            $table->unique('semantic_key', 'sle_semantic_key_uq');
            $table->unique('integrity_digest', 'sle_integrity_digest_uq');
            $table->index(['event_type', 'recorded_at'], 'sle_event_recorded_idx');
            $table->index(['resource_type', 'resource_public_id'], 'sle_resource_idx');
            $table->index(['actor_user_id', 'recorded_at'], 'sle_actor_recorded_idx');
            $table->index('request_correlation_id', 'sle_request_correlation_idx');
        });

        Schema::create('security_ledger_outboxes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('security_ledger_entry_id')
                ->constrained('security_ledger_entries', indexName: 'slo_entry_fk')
                ->restrictOnDelete();
            $table->string('destination', 64);
            $table->string('delivery_state', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at', precision: 6);
            $table->timestamp('last_attempted_at', precision: 6)->nullable();
            $table->timestamp('delivered_at', precision: 6)->nullable();
            $table->string('last_error_digest', 64)->nullable();
            $table->timestamps(precision: 6);

            $table->unique('public_id', 'slo_public_id_uq');
            $table->unique('security_ledger_entry_id', 'slo_entry_uq');
            $table->index(['delivery_state', 'available_at'], 'slo_pending_idx');
        });

        $this->qualifyPostgresSequences();
        $this->createMutationGuards();
    }

    public function down(): void
    {
        $this->assertNoEvidenceBeforeRollback();
        $this->dropMutationGuards();

        Schema::dropIfExists('security_ledger_outboxes');
        Schema::dropIfExists('security_ledger_entries');
    }

    private function createMutationGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresMutationGuards(),
            'mysql' => $this->createMysqlMutationGuards(),
            'sqlite' => $this->createSqliteMutationGuards(),
            default => throw new RuntimeException('Unsupported database driver for protected security facts.'),
        };
    }

    private function dropMutationGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->dropPostgresMutationGuards(),
            'mysql' => $this->dropMysqlMutationGuards(),
            'sqlite' => $this->dropSqliteMutationGuards(),
            default => null,
        };
    }

    private function createPostgresMutationGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $function = $this->qualifiedPostgresName($schema, 'reject_protected_fact_mutation');

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                RAISE EXCEPTION 'protected security facts are append-only'
                    USING ERRCODE = '55000';
            END;
            \$function\$
            SQL);

        foreach ($this->protectedTables as $table) {
            $qualifiedTable = $this->qualifiedPostgresName($schema, $table);

            foreach (['update', 'delete'] as $operation) {
                $triggerName = $this->triggerName($table, $operation);
                $trigger = $this->quotePostgresIdentifier($triggerName);

                DB::statement(sprintf(
                    'CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
                    $trigger,
                    strtoupper($operation),
                    $qualifiedTable,
                    $function,
                ));
            }
        }

        $outboxTable = $this->qualifiedPostgresName($schema, 'security_ledger_outboxes');
        $outboxFunction = $this->qualifiedPostgresName($schema, 'protect_security_ledger_outbox_mutation');

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$outboxFunction}()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.delivery_state <> 'PENDING'
                        OR NEW.attempts <> 0
                        OR NEW.available_at IS NULL
                        OR NEW.created_at IS NULL
                        OR NEW.updated_at IS NULL
                        OR NEW.last_attempted_at IS NOT NULL
                        OR NEW.delivered_at IS NOT NULL
                        OR NEW.last_error_digest IS NOT NULL THEN
                        RAISE EXCEPTION 'security ledger outbox initial state is invalid'
                            USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'security ledger outbox rows cannot be deleted'
                        USING ERRCODE = '55000';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.security_ledger_entry_id IS DISTINCT FROM OLD.security_ledger_entry_id
                    OR NEW.destination IS DISTINCT FROM OLD.destination
                    OR NEW.available_at IS DISTINCT FROM OLD.available_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'security ledger outbox identity is immutable'
                        USING ERRCODE = '55000';
                END IF;

                IF NEW.delivery_state NOT IN ('PENDING', 'DELIVERED', 'FAILED')
                    OR NEW.attempts < OLD.attempts
                    OR (NEW.attempts > OLD.attempts AND NEW.last_attempted_at IS NULL)
                    OR (OLD.last_attempted_at IS NOT NULL AND (NEW.last_attempted_at IS NULL OR NEW.last_attempted_at < OLD.last_attempted_at))
                    OR (NEW.last_attempted_at IS DISTINCT FROM OLD.last_attempted_at AND NEW.attempts <= OLD.attempts)
                    OR (NEW.last_error_digest IS DISTINCT FROM OLD.last_error_digest AND NEW.attempts <= OLD.attempts)
                    OR (OLD.delivered_at IS NOT NULL AND NEW.delivered_at IS DISTINCT FROM OLD.delivered_at)
                    OR (NEW.delivery_state = 'DELIVERED' AND (NEW.delivered_at IS NULL OR NEW.last_attempted_at IS NULL OR NEW.attempts < 1))
                    OR (NEW.delivery_state = 'FAILED' AND (
                        NEW.attempts < 1
                        OR NEW.last_attempted_at IS NULL
                        OR NEW.last_error_digest IS NULL
                        OR NEW.last_error_digest !~ '^[0-9A-Fa-f]{64}$'
                    ))
                    OR (NEW.delivery_state <> 'DELIVERED' AND NEW.delivered_at IS NOT NULL)
                    OR NEW.updated_at IS NULL
                    OR OLD.updated_at IS NULL
                    OR NEW.updated_at < OLD.updated_at
                    OR (OLD.delivery_state IN ('DELIVERED', 'FAILED') AND (
                        NEW.delivery_state IS DISTINCT FROM OLD.delivery_state
                        OR NEW.attempts IS DISTINCT FROM OLD.attempts
                        OR NEW.last_attempted_at IS DISTINCT FROM OLD.last_attempted_at
                        OR NEW.delivered_at IS DISTINCT FROM OLD.delivered_at
                        OR NEW.last_error_digest IS DISTINCT FROM OLD.last_error_digest
                    )) THEN
                    RAISE EXCEPTION 'security ledger outbox delivery transition is invalid'
                        USING ERRCODE = '55000';
                END IF;

                RETURN NEW;
            END;
            \$function\$
            SQL);

        DB::statement(sprintf(
            'CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
            $this->quotePostgresIdentifier('security_ledger_outboxes_validate_insert'),
            $outboxTable,
            $outboxFunction,
        ));
        DB::statement(sprintf(
            'CREATE TRIGGER %s BEFORE UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
            $this->quotePostgresIdentifier('security_ledger_outboxes_protect_update'),
            $outboxTable,
            $outboxFunction,
        ));
        DB::statement(sprintf(
            'CREATE TRIGGER %s BEFORE DELETE ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
            $this->quotePostgresIdentifier('security_ledger_outboxes_no_delete'),
            $outboxTable,
            $outboxFunction,
        ));
    }

    private function dropPostgresMutationGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';

        foreach ($this->protectedTables as $table) {
            $qualifiedTable = $this->qualifiedPostgresName($schema, $table);

            foreach (['update', 'delete'] as $operation) {
                $trigger = $this->quotePostgresIdentifier($this->triggerName($table, $operation));
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$qualifiedTable}");
            }
        }

        $outboxTable = $this->qualifiedPostgresName($schema, 'security_ledger_outboxes');
        DB::statement(sprintf(
            'DROP TRIGGER IF EXISTS %s ON %s',
            $this->quotePostgresIdentifier('security_ledger_outboxes_validate_insert'),
            $outboxTable,
        ));
        DB::statement(sprintf(
            'DROP TRIGGER IF EXISTS %s ON %s',
            $this->quotePostgresIdentifier('security_ledger_outboxes_protect_update'),
            $outboxTable,
        ));
        DB::statement(sprintf(
            'DROP TRIGGER IF EXISTS %s ON %s',
            $this->quotePostgresIdentifier('security_ledger_outboxes_no_delete'),
            $outboxTable,
        ));

        $outboxFunction = $this->qualifiedPostgresName($schema, 'protect_security_ledger_outbox_mutation');
        DB::statement("DROP FUNCTION IF EXISTS {$outboxFunction}()");
        $factFunction = $this->qualifiedPostgresName($schema, 'reject_protected_fact_mutation');
        DB::statement("DROP FUNCTION IF EXISTS {$factFunction}()");
    }

    private function createMysqlMutationGuards(): void
    {
        $database = DB::connection()->getDatabaseName();

        foreach ($this->protectedTables as $table) {
            foreach (['update', 'delete'] as $operation) {
                $triggerName = $this->triggerName($table, $operation);
                // MySQL 8.4: trigger name is unqualified; ON table is database-qualified.
                $trigger = $this->quoteMysqlIdentifier($triggerName);
                $qualifiedTable = $this->qualifiedMysqlName($database, $table);

                DB::statement(sprintf(
                    "CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'protected security facts are append-only'",
                    $trigger,
                    strtoupper($operation),
                    $qualifiedTable,
                ));
            }
        }

        $outboxTable = $this->qualifiedMysqlName($database, 'security_ledger_outboxes');
        $insertTrigger = $this->quoteMysqlIdentifier('security_ledger_outboxes_validate_insert');
        $updateTrigger = $this->quoteMysqlIdentifier('security_ledger_outboxes_protect_update');
        $deleteTrigger = $this->quoteMysqlIdentifier('security_ledger_outboxes_no_delete');

        DB::statement(<<<SQL
            CREATE TRIGGER {$insertTrigger}
            BEFORE INSERT ON {$outboxTable}
            FOR EACH ROW
            BEGIN
                IF NEW.delivery_state <> 'PENDING'
                    OR NEW.attempts <> 0
                    OR NEW.available_at IS NULL
                    OR NEW.created_at IS NULL
                    OR NEW.updated_at IS NULL
                    OR NEW.last_attempted_at IS NOT NULL
                    OR NEW.delivered_at IS NOT NULL
                    OR NEW.last_error_digest IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security ledger outbox initial state is invalid';
                END IF;
            END
            SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER {$updateTrigger}
            BEFORE UPDATE ON {$outboxTable}
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id)
                    OR NOT (NEW.public_id <=> OLD.public_id)
                    OR NOT (NEW.security_ledger_entry_id <=> OLD.security_ledger_entry_id)
                    OR NOT (NEW.destination <=> OLD.destination)
                    OR NOT (NEW.available_at <=> OLD.available_at)
                    OR NOT (NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security ledger outbox identity is immutable';
                END IF;

                IF NEW.delivery_state NOT IN ('PENDING', 'DELIVERED', 'FAILED')
                    OR NEW.attempts < OLD.attempts
                    OR (NEW.attempts > OLD.attempts AND NEW.last_attempted_at IS NULL)
                    OR (OLD.last_attempted_at IS NOT NULL AND (NEW.last_attempted_at IS NULL OR NEW.last_attempted_at < OLD.last_attempted_at))
                    OR (NOT (NEW.last_attempted_at <=> OLD.last_attempted_at) AND NEW.attempts <= OLD.attempts)
                    OR (NOT (NEW.last_error_digest <=> OLD.last_error_digest) AND NEW.attempts <= OLD.attempts)
                    OR (OLD.delivered_at IS NOT NULL AND NOT (NEW.delivered_at <=> OLD.delivered_at))
                    OR (NEW.delivery_state = 'DELIVERED' AND (NEW.delivered_at IS NULL OR NEW.last_attempted_at IS NULL OR NEW.attempts < 1))
                    OR (NEW.delivery_state = 'FAILED' AND (
                        NEW.attempts < 1
                        OR NEW.last_attempted_at IS NULL
                        OR NEW.last_error_digest IS NULL
                        OR NOT (NEW.last_error_digest REGEXP '^[0-9A-Fa-f]{64}$')
                    ))
                    OR (NEW.delivery_state <> 'DELIVERED' AND NEW.delivered_at IS NOT NULL)
                    OR NEW.updated_at IS NULL
                    OR OLD.updated_at IS NULL
                    OR NEW.updated_at < OLD.updated_at
                    OR (OLD.delivery_state IN ('DELIVERED', 'FAILED') AND (
                        NOT (NEW.delivery_state <=> OLD.delivery_state)
                        OR NOT (NEW.attempts <=> OLD.attempts)
                        OR NOT (NEW.last_attempted_at <=> OLD.last_attempted_at)
                        OR NOT (NEW.delivered_at <=> OLD.delivered_at)
                        OR NOT (NEW.last_error_digest <=> OLD.last_error_digest)
                    )) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security ledger outbox delivery transition is invalid';
                END IF;
            END
            SQL);

        DB::statement(
            "CREATE TRIGGER {$deleteTrigger} BEFORE DELETE ON {$outboxTable} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security ledger outbox rows cannot be deleted'",
        );
    }

    private function dropMysqlMutationGuards(): void
    {
        $database = DB::connection()->getDatabaseName();

        foreach ($this->protectedTables as $table) {
            foreach (['update', 'delete'] as $operation) {
                $trigger = $this->qualifiedMysqlName($database, $this->triggerName($table, $operation));
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }

        DB::statement('DROP TRIGGER IF EXISTS '.$this->qualifiedMysqlName(
            $database,
            'security_ledger_outboxes_validate_insert',
        ));
        DB::statement('DROP TRIGGER IF EXISTS '.$this->qualifiedMysqlName(
            $database,
            'security_ledger_outboxes_protect_update',
        ));
        DB::statement('DROP TRIGGER IF EXISTS '.$this->qualifiedMysqlName(
            $database,
            'security_ledger_outboxes_no_delete',
        ));
    }

    private function createSqliteMutationGuards(): void
    {
        foreach ($this->protectedTables as $table) {
            foreach (['update', 'delete'] as $operation) {
                $triggerName = $this->triggerName($table, $operation);
                $trigger = $this->quoteSqliteIdentifier($triggerName);
                $quotedTable = $this->quoteSqliteIdentifier($table);

                DB::statement(sprintf(
                    "CREATE TRIGGER %s BEFORE %s ON %s BEGIN SELECT RAISE(ABORT, 'protected security facts are append-only'); END",
                    $trigger,
                    strtoupper($operation),
                    $quotedTable,
                ));
            }
        }

        $outboxTable = $this->quoteSqliteIdentifier('security_ledger_outboxes');
        $insertTrigger = $this->quoteSqliteIdentifier('security_ledger_outboxes_validate_insert');
        $updateTrigger = $this->quoteSqliteIdentifier('security_ledger_outboxes_protect_update');
        $deleteTrigger = $this->quoteSqliteIdentifier('security_ledger_outboxes_no_delete');

        DB::statement(<<<SQL
            CREATE TRIGGER {$insertTrigger}
            BEFORE INSERT ON {$outboxTable}
            WHEN NEW.delivery_state <> 'PENDING'
                OR NEW.attempts <> 0
                OR NEW.available_at IS NULL
                OR NEW.created_at IS NULL
                OR NEW.updated_at IS NULL
                OR NEW.last_attempted_at IS NOT NULL
                OR NEW.delivered_at IS NOT NULL
                OR NEW.last_error_digest IS NOT NULL
            BEGIN
                SELECT RAISE(ABORT, 'security ledger outbox initial state is invalid');
            END
            SQL);
        DB::statement(<<<SQL
            CREATE TRIGGER {$updateTrigger}
            BEFORE UPDATE ON {$outboxTable}
            WHEN NEW.id IS NOT OLD.id
                OR NEW.public_id IS NOT OLD.public_id
                OR NEW.security_ledger_entry_id IS NOT OLD.security_ledger_entry_id
                OR NEW.destination IS NOT OLD.destination
                OR NEW.available_at IS NOT OLD.available_at
                OR NEW.created_at IS NOT OLD.created_at
                OR NEW.delivery_state NOT IN ('PENDING', 'DELIVERED', 'FAILED')
                OR NEW.attempts < OLD.attempts
                OR (NEW.attempts > OLD.attempts AND NEW.last_attempted_at IS NULL)
                OR (OLD.last_attempted_at IS NOT NULL AND (NEW.last_attempted_at IS NULL OR NEW.last_attempted_at < OLD.last_attempted_at))
                OR (NEW.last_attempted_at IS NOT OLD.last_attempted_at AND NEW.attempts <= OLD.attempts)
                OR (NEW.last_error_digest IS NOT OLD.last_error_digest AND NEW.attempts <= OLD.attempts)
                OR (OLD.delivered_at IS NOT NULL AND NEW.delivered_at IS NOT OLD.delivered_at)
                OR (NEW.delivery_state = 'DELIVERED' AND (NEW.delivered_at IS NULL OR NEW.last_attempted_at IS NULL OR NEW.attempts < 1))
                OR (NEW.delivery_state = 'FAILED' AND (
                    NEW.attempts < 1
                    OR NEW.last_attempted_at IS NULL
                    OR NEW.last_error_digest IS NULL
                    OR length(NEW.last_error_digest) <> 64
                    OR lower(NEW.last_error_digest) GLOB '*[^0-9a-f]*'
                ))
                OR (NEW.delivery_state <> 'DELIVERED' AND NEW.delivered_at IS NOT NULL)
                OR NEW.updated_at IS NULL
                OR OLD.updated_at IS NULL
                OR NEW.updated_at < OLD.updated_at
                OR (OLD.delivery_state IN ('DELIVERED', 'FAILED') AND (
                    NEW.delivery_state IS NOT OLD.delivery_state
                    OR NEW.attempts IS NOT OLD.attempts
                    OR NEW.last_attempted_at IS NOT OLD.last_attempted_at
                    OR NEW.delivered_at IS NOT OLD.delivered_at
                    OR NEW.last_error_digest IS NOT OLD.last_error_digest
                ))
            BEGIN
                SELECT RAISE(ABORT, 'security ledger outbox mutation is invalid');
            END
            SQL);
        DB::statement(
            "CREATE TRIGGER {$deleteTrigger} BEFORE DELETE ON {$outboxTable} BEGIN SELECT RAISE(ABORT, 'security ledger outbox rows cannot be deleted'); END",
        );
    }

    private function dropSqliteMutationGuards(): void
    {
        foreach ($this->protectedTables as $table) {
            foreach (['update', 'delete'] as $operation) {
                $trigger = $this->quoteSqliteIdentifier($this->triggerName($table, $operation));
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }

        DB::statement('DROP TRIGGER IF EXISTS '.$this->quoteSqliteIdentifier(
            'security_ledger_outboxes_validate_insert',
        ));
        DB::statement('DROP TRIGGER IF EXISTS '.$this->quoteSqliteIdentifier(
            'security_ledger_outboxes_protect_update',
        ));
        DB::statement('DROP TRIGGER IF EXISTS '.$this->quoteSqliteIdentifier(
            'security_ledger_outboxes_no_delete',
        ));
    }

    private function assertSupportedDriver(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver for protected security facts.');
        }
    }

    private function assertNoEvidenceBeforeRollback(): void
    {
        foreach (array_merge(['security_ledger_outboxes'], $this->protectedTables) as $table) {
            if (
                Schema::hasTable($table)
                && DB::table(SchemaQualifier::table($table))->exists()
            ) {
                throw new RuntimeException(
                    'Refusing BG-02 rollback because protected security evidence exists.',
                );
            }
        }
    }

    private function triggerName(string $table, string $operation): string
    {
        return $table.'_no_'.$operation;
    }

    private function qualifyPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = SchemaQualifier::primarySchema() ?? 'public';

        foreach ($this->sequenceTables as $table) {
            $qualifiedTable = $this->qualifiedPostgresName($schema, $table);
            $qualifiedSequence = $this->qualifiedPostgresName($schema, $table.'_id_seq');

            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
                $qualifiedTable,
                $this->quoteLiteral($qualifiedSequence),
            ));
        }
    }

    private function qualifiedPostgresName(string $schema, string $name): string
    {
        return $this->quotePostgresIdentifier($schema).'.'.$this->quotePostgresIdentifier($name);
    }

    private function quotePostgresIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteMysqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function qualifiedMysqlName(string $database, string $name): string
    {
        return $this->quoteMysqlIdentifier($database).'.'.$this->quoteMysqlIdentifier($name);
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
