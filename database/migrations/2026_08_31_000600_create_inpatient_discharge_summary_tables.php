<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientDischargeSummarySchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'inpatient_discharge_summaries',
        'inpatient_discharge_summary_versions',
        'inpatient_discharge_summary_operation_receipts',
    ];

    /** @var list<string> */
    private array $narrativeFields = [
        'admission_reason',
        'significant_findings',
        'care_and_treatment_summary',
        'condition_at_discharge',
        'follow_up_plan',
    ];

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        InpatientDischargeSummarySchemaMutationScope::run(function (): void {
            Schema::create(SchemaQualifier::table('inpatient_discharge_summaries'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'ids_encounter_fk')->restrictOnDelete();
                $table->foreignId('assigned_physician_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ids_physician_fk')->restrictOnDelete();
                $table->foreignId('finalized_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'ids_finalizer_fk')->restrictOnDelete();
                $table->string('summary_state', 16);
                $table->string('definition_version', 64);
                $table->unsignedInteger('version');
                foreach ($this->narrativeFields as $field) {
                    $table->text($field)->nullable();
                }
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();

                $table->unique('public_id', 'ids_public_id_uq');
                $table->unique('encounter_id', 'ids_encounter_uq');
                $table->index(['summary_state', 'updated_at'], 'ids_state_time_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_discharge_summary_versions'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('inpatient_discharge_summary_id')->constrained(SchemaQualifier::table('inpatient_discharge_summaries'), indexName: 'idsv_summary_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idsv_actor_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('summary_state', 16);
                $table->string('definition_version', 64);
                foreach ($this->narrativeFields as $field) {
                    $table->text($field)->nullable();
                }
                $table->string('encounter_public_id', 26);
                $table->string('care_setting', 32);
                $table->string('encounter_status', 32);
                $table->unsignedInteger('location_sequence');
                $table->string('location_event_public_id', 26)->nullable();
                $table->string('location_event_type', 32)->nullable();
                $table->string('history_baseline', 32)->nullable();
                $table->boolean('history_complete');
                $table->string('ward_public_id', 26);
                $table->string('ward_code', 64);
                $table->string('ward_display_name', 120);
                $table->string('bed_public_id', 26);
                $table->string('bed_code', 64);
                $table->string('bed_display_name', 120);
                $table->string('room_label', 120);
                $table->string('service_class', 120);
                $table->timestamp('finalized_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique('public_id', 'idsv_public_id_uq');
                $table->unique(['inpatient_discharge_summary_id', 'version'], 'idsv_summary_version_uq');
                $table->index(['encounter_public_id', 'created_at'], 'idsv_encounter_time_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_discharge_summary_operation_receipts'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'idsor_encounter_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idsor_actor_fk')->restrictOnDelete();
                $table->string('operation', 64);
                $table->string('idempotency_key', 255);
                $table->string('payload_digest', 64);
                $table->string('result_summary_public_id', 26);
                $table->unsignedInteger('result_version');
                $table->string('request_correlation_id', 26)->nullable();
                $table->timestamp('completed_at')->useCurrent();
                $table->timestamps();

                $table->unique('public_id', 'idsor_public_id_uq');
                $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'idsor_actor_operation_key_uq');
                $table->index(['encounter_id', 'completed_at'], 'idsor_encounter_time_idx');
            });

            $this->addChecks();
            $this->qualifyPostgresSequences();
            $this->createImmutableEvidenceGuards();
        });
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit)
            && DB::table($audit)->where('action', 'like', 'clinical.inpatient.discharge-summary.%')->exists()) {
            throw new RuntimeException('Refusing to roll back inpatient discharge summaries because correlated audit evidence remains.');
        }
        foreach ($this->tables as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient discharge summaries because retained evidence exists.');
            }
        }

        InpatientDischargeSummarySchemaMutationScope::run(function (): void {
            $this->dropImmutableEvidenceGuards();
            foreach (array_reverse($this->tables) as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
        });
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient discharge summary migration requires SIMULATION mode with synthetic-only data enforced.');
        }
        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Inpatient discharge summary migration refused because non-synthetic patient data exists.');
        }
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        foreach (['inpatient_discharge_summaries', 'inpatient_discharge_summary_versions'] as $name) {
            $table = $grammar->wrapTable(SchemaQualifier::table($name));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_state_ck CHECK (summary_state IN ('DRAFT', 'FINAL'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_definition_ck CHECK (definition_version = 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1')");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_version_ck CHECK (version >= 1)");
        }
        $versions = $grammar->wrapTable(SchemaQualifier::table('inpatient_discharge_summary_versions'));
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT idsv_care_setting_ck CHECK (care_setting = 'INPATIENT')");
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT idsv_location_snapshot_ck CHECK ((location_sequence = 0 AND location_event_public_id IS NULL AND location_event_type IS NULL AND history_baseline = 'LEGACY_CURRENT_PLACEMENT' AND history_complete = false) OR (location_sequence >= 1 AND location_event_public_id IS NOT NULL AND location_event_type IN ('ADMISSION_LOCATION', 'BED_TRANSFER') AND history_baseline IS NULL))");
        $receipts = $grammar->wrapTable(SchemaQualifier::table('inpatient_discharge_summary_operation_receipts'));
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT idsor_operation_ck CHECK (operation IN ('DISCHARGE_SUMMARY_DRAFT_SAVE', 'DISCHARGE_SUMMARY_FINALIZE'))");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT idsor_result_version_ck CHECK (result_version >= 1)");
    }

    private function qualifyPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->tables as $table) {
            $qualifiedTable = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
            $sequence = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table.'_id_seq');
            DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)', $qualifiedTable, $this->quoteLiteral($sequence)));
        }
    }

    private function createImmutableEvidenceGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresGuards(),
            'mysql' => $this->createMysqlGuards(),
            'sqlite' => null,
            default => throw new RuntimeException('Unsupported database driver for inpatient discharge summaries.'),
        };
    }

    private function dropImmutableEvidenceGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->dropPostgresGuards(),
            'mysql' => $this->dropMysqlGuards(),
            default => null,
        };
    }

    /** @return list<string> */
    private function immutableTables(): array
    {
        return ['inpatient_discharge_summary_versions', 'inpatient_discharge_summary_operation_receipts'];
    }

    private function createPostgresGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $function = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier('protect_inpatient_discharge_summary_evidence');
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}()
            RETURNS trigger LANGUAGE plpgsql AS \$function\$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('simrs.synthetic_reset', true) = '1' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'inpatient discharge summary evidence is append-only' USING ERRCODE = '55000';
            END;
            \$function\$
            SQL);
        foreach ($this->immutableTables() as $table) {
            $qualified = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
            foreach (['update', 'delete'] as $operation) {
                $trigger = $this->quoteIdentifier($this->triggerName($table, $operation));
                DB::statement(sprintf('CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $trigger, strtoupper($operation), $qualified, $function));
            }
            $trigger = $this->quoteIdentifier($this->triggerName($table, 'truncate'));
            DB::statement(sprintf('CREATE TRIGGER %s BEFORE TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION %s()', $trigger, $qualified, $function));
        }
    }

    private function dropPostgresGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->immutableTables() as $table) {
            $qualified = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($table);
            foreach (['update', 'delete', 'truncate'] as $operation) {
                $trigger = $this->quoteIdentifier($this->triggerName($table, $operation));
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$qualified}");
            }
        }
        $function = $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier('protect_inpatient_discharge_summary_evidence');
        DB::statement("DROP FUNCTION IF EXISTS {$function}()");
    }

    private function createMysqlGuards(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($this->immutableTables() as $table) {
            $qualified = $grammar->wrapTable(SchemaQualifier::table($table));
            $update = $this->quoteMysqlIdentifier($this->triggerName($table, 'update'));
            $delete = $this->quoteMysqlIdentifier($this->triggerName($table, 'delete'));
            DB::statement("CREATE TRIGGER {$update} BEFORE UPDATE ON {$qualified} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inpatient discharge summary evidence is append-only'");
            DB::statement(<<<SQL
                CREATE TRIGGER {$delete} BEFORE DELETE ON {$qualified} FOR EACH ROW
                BEGIN
                    IF COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inpatient discharge summary evidence is append-only';
                    END IF;
                END
                SQL);
        }
    }

    private function dropMysqlGuards(): void
    {
        foreach ($this->immutableTables() as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->quoteMysqlIdentifier($this->triggerName($table, $operation)));
            }
        }
    }

    private function triggerName(string $table, string $operation): string
    {
        $prefix = $table === 'inpatient_discharge_summary_versions' ? 'idsv' : 'idsor';

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
