<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientLocationSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['inpatient_location_events', 'inpatient_location_operation_receipts'];

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();
        InpatientLocationSchemaMutationScope::run(function (): void {
            Schema::create(SchemaQualifier::table('inpatient_location_events'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'ile_encounter_fk')->restrictOnDelete();
                $table->string('encounter_public_id', 26);
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ile_actor_fk')->restrictOnDelete();
                $table->string('event_type', 32);
                $table->unsignedInteger('sequence');
                foreach (['ward_public_id' => 26, 'ward_code' => 64, 'ward_display_name' => 120, 'bed_public_id' => 26, 'bed_code' => 64, 'bed_display_name' => 120, 'room_label' => 120, 'service_class' => 120] as $name => $length) {
                    $table->string('from_'.$name, $length)->nullable();
                }
                foreach (['ward_public_id' => 26, 'ward_code' => 64, 'ward_display_name' => 120, 'bed_public_id' => 26, 'bed_code' => 64, 'bed_display_name' => 120, 'room_label' => 120, 'service_class' => 120] as $name => $length) {
                    $table->string('to_'.$name, $length);
                }
                $table->string('reason', 500)->nullable();
                $table->string('request_correlation_id', 26)->nullable();
                $table->string('payload_digest', 64);
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();

                $table->unique('public_id', 'ile_public_id_uq');
                $table->unique(['encounter_id', 'sequence'], 'ile_encounter_sequence_uq');
                $table->index(['encounter_id', 'occurred_at'], 'ile_encounter_time_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_location_operation_receipts'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'ilor_encounter_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ilor_actor_fk')->restrictOnDelete();
                $table->string('operation', 64);
                $table->string('idempotency_key', 255);
                $table->string('payload_digest', 64);
                $table->string('result_event_public_id', 26);
                $table->unsignedInteger('result_sequence');
                $table->string('request_correlation_id', 26)->nullable();
                $table->timestamp('completed_at')->useCurrent();
                $table->timestamps();

                $table->unique('public_id', 'ilor_public_id_uq');
                $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'ilor_actor_operation_key_uq');
                $table->index(['encounter_id', 'completed_at'], 'ilor_encounter_time_idx');
            });

            $this->addChecks();
            $this->qualifyPostgresSequences();
            $this->createMutationGuards();
        });
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'inpatient.bed.transfer')->exists()) {
            throw new RuntimeException('Refusing to roll back inpatient location history because correlated audit evidence remains.');
        }
        foreach ($this->tables as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient location history because retained evidence exists.');
            }
        }
        InpatientLocationSchemaMutationScope::run(function (): void {
            $this->dropMutationGuards();
            foreach (array_reverse($this->tables) as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
        });
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient location history migration requires SIMULATION mode with synthetic-only data enforced.');
        }
        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Inpatient location history migration refused because non-synthetic patient data exists.');
        }
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        $events = $grammar->wrapTable(SchemaQualifier::table('inpatient_location_events'));
        DB::statement("ALTER TABLE {$events} ADD CONSTRAINT ile_event_type_ck CHECK (event_type IN ('ADMISSION_LOCATION', 'BED_TRANSFER'))");
        DB::statement("ALTER TABLE {$events} ADD CONSTRAINT ile_sequence_ck CHECK (sequence >= 1)");
        $fromNull = implode(' AND ', array_map(static fn (string $column): string => $column.' IS NULL', ['from_ward_public_id', 'from_ward_code', 'from_ward_display_name', 'from_bed_public_id', 'from_bed_code', 'from_bed_display_name', 'from_room_label', 'from_service_class']));
        $fromPresent = implode(' AND ', array_map(static fn (string $column): string => $column.' IS NOT NULL', ['from_ward_public_id', 'from_ward_code', 'from_ward_display_name', 'from_bed_public_id', 'from_bed_code', 'from_bed_display_name', 'from_room_label', 'from_service_class']));
        DB::statement("ALTER TABLE {$events} ADD CONSTRAINT ile_snapshot_reason_ck CHECK ((event_type = 'ADMISSION_LOCATION' AND {$fromNull} AND reason IS NULL) OR (event_type = 'BED_TRANSFER' AND {$fromPresent} AND char_length(reason) BETWEEN 5 AND 500))");
        $receipts = $grammar->wrapTable(SchemaQualifier::table('inpatient_location_operation_receipts'));
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT ilor_operation_ck CHECK (operation = 'INPATIENT_BED_TRANSFER')");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT ilor_sequence_ck CHECK (result_sequence >= 1)");
    }

    private function qualifyPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->tables as $table) {
            $qualifiedTable = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
            $qualifiedSequence = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table.'_id_seq');
            DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)', $qualifiedTable, $this->quoteLiteral($qualifiedSequence)));
        }
    }

    private function createMutationGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresMutationGuards(),
            'mysql' => $this->createMysqlMutationGuards(),
            // SQLite remains protected by the model and SQL write guards. The
            // release evidence exercises database triggers on PostgreSQL/MySQL.
            'sqlite' => null,
            default => throw new RuntimeException('Unsupported database driver for inpatient location history.'),
        };
    }

    private function dropMutationGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->dropPostgresMutationGuards(),
            'mysql' => $this->dropMysqlMutationGuards(),
            default => null,
        };
    }

    private function createPostgresMutationGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $function = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier('protect_inpatient_location_history');

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND current_setting('simrs.synthetic_reset', true) = '1' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'inpatient location history is append-only'
                    USING ERRCODE = '55000';
            END;
            \$function\$
            SQL);

        foreach ($this->tables as $table) {
            $qualifiedTable = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
            foreach (['update', 'delete'] as $operation) {
                $trigger = $this->quoteIdentifier($this->mutationTriggerName($table, $operation));
                DB::statement(sprintf(
                    'CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
                    $trigger,
                    strtoupper($operation),
                    $qualifiedTable,
                    $function,
                ));
            }
            $truncateTrigger = $this->quoteIdentifier($this->mutationTriggerName($table, 'truncate'));
            DB::statement(sprintf(
                'CREATE TRIGGER %s BEFORE TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION %s()',
                $truncateTrigger,
                $qualifiedTable,
                $function,
            ));
        }
    }

    private function dropPostgresMutationGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->tables as $table) {
            $qualifiedTable = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
            foreach (['update', 'delete', 'truncate'] as $operation) {
                $trigger = $this->quoteIdentifier($this->mutationTriggerName($table, $operation));
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$qualifiedTable}");
            }
        }

        $function = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier('protect_inpatient_location_history');
        DB::statement("DROP FUNCTION IF EXISTS {$function}()");
    }

    private function createMysqlMutationGuards(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($this->tables as $table) {
            $qualifiedTable = $grammar->wrapTable(SchemaQualifier::table($table));
            $updateTrigger = $this->quoteMysqlIdentifier($this->mutationTriggerName($table, 'update'));
            $deleteTrigger = $this->quoteMysqlIdentifier($this->mutationTriggerName($table, 'delete'));

            DB::statement(<<<SQL
                CREATE TRIGGER {$updateTrigger}
                BEFORE UPDATE ON {$qualifiedTable}
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inpatient location history is append-only'
                SQL);
            DB::statement(<<<SQL
                CREATE TRIGGER {$deleteTrigger}
                BEFORE DELETE ON {$qualifiedTable}
                FOR EACH ROW
                BEGIN
                    IF COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inpatient location history is append-only';
                    END IF;
                END
                SQL);
        }
    }

    private function dropMysqlMutationGuards(): void
    {
        foreach ($this->tables as $table) {
            foreach (['update', 'delete'] as $operation) {
                $trigger = $this->quoteMysqlIdentifier($this->mutationTriggerName($table, $operation));
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    private function mutationTriggerName(string $table, string $operation): string
    {
        $prefix = $table === 'inpatient_location_events' ? 'ile' : 'ilor';

        return $prefix.'_immutable_'.$operation.'_trg';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function quoteMysqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
};
