<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientDischargeSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['inpatient_discharges', 'inpatient_discharge_operation_receipts'];

    public function up(): void
    {
        $this->assertSyntheticBoundary();
        InpatientDischargeSchemaMutationScope::run(function (): void {
            Schema::create(SchemaQualifier::table('inpatient_discharges'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'idc_encounter_fk')->restrictOnDelete();
                $table->foreignId('inpatient_discharge_summary_id')->constrained(SchemaQualifier::table('inpatient_discharge_summaries'), indexName: 'idc_summary_fk')->restrictOnDelete();
                $table->foreignId('inpatient_discharge_summary_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_summary_versions'), indexName: 'idc_summary_version_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idc_actor_fk')->restrictOnDelete();
                $table->string('disposition_code', 64);
                $table->string('disposition_label', 120);
                $table->string('discharge_summary_public_id', 26);
                $table->unsignedInteger('discharge_summary_version');
                $table->string('discharge_summary_version_public_id', 26);
                $table->string('discharge_summary_content_digest', 64);
                $table->string('discharge_summary_provenance_digest', 64);
                $table->unsignedInteger('location_sequence');
                $table->string('source_ward_public_id', 26);
                $table->string('source_ward_code', 64);
                $table->string('source_bed_public_id', 26);
                $table->string('source_bed_code', 64);
                $table->string('encounter_status_before', 32);
                $table->string('encounter_status_after', 32);
                $table->string('payload_digest', 64);
                $table->string('request_correlation_id', 26)->nullable();
                $table->timestamp('discharged_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();

                $table->unique('public_id', 'idc_public_id_uq');
                $table->unique('encounter_id', 'idc_encounter_uq');
                $table->unique('inpatient_discharge_summary_id', 'idc_summary_uq');
                $table->unique('inpatient_discharge_summary_version_id', 'idc_summary_version_uq');
                $table->index(['source_bed_public_id', 'discharged_at'], 'idc_bed_time_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_discharge_operation_receipts'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'idcor_encounter_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idcor_actor_fk')->restrictOnDelete();
                $table->foreignId('inpatient_discharge_summary_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_summary_versions'), indexName: 'idcor_summary_version_fk')->restrictOnDelete();
                $table->string('operation', 64);
                $table->string('idempotency_key', 255);
                $table->string('payload_digest', 64);
                $table->string('result_discharge_public_id', 26);
                $table->string('discharge_summary_version_public_id', 26);
                $table->string('discharge_summary_content_digest', 64);
                $table->string('discharge_summary_provenance_digest', 64);
                $table->string('request_correlation_id', 26)->nullable();
                $table->timestamp('completed_at')->useCurrent();
                $table->timestamps();

                $table->unique('public_id', 'idcor_public_id_uq');
                $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'idcor_actor_operation_key_uq');
                $table->index(['encounter_id', 'completed_at'], 'idcor_encounter_time_idx');
            });

            $this->addChecks();
            $this->qualifyPostgresSequences();
            $this->createGuards();
        });
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'clinical.inpatient.discharge.execute')->exists()) {
            throw new RuntimeException('Refusing to roll back inpatient discharge because correlated audit evidence remains.');
        }
        foreach ($this->tables as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient discharge because retained evidence exists.');
            }
        }
        InpatientDischargeSchemaMutationScope::run(function (): void {
            $this->dropGuards();
            foreach (array_reverse($this->tables) as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
        });
    }

    private function assertSyntheticBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient discharge migration requires SIMULATION mode with synthetic-only data enforced.');
        }
        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Inpatient discharge migration refused because non-synthetic patient data exists.');
        }
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        $discharges = $grammar->wrapTable(SchemaQualifier::table('inpatient_discharges'));
        DB::statement("ALTER TABLE {$discharges} ADD CONSTRAINT idc_disposition_ck CHECK (disposition_code = 'PULANG_ATAS_IZIN_DOKTER' AND disposition_label = 'Pulang atas izin dokter')");
        DB::statement("ALTER TABLE {$discharges} ADD CONSTRAINT idc_summary_version_ck CHECK (discharge_summary_version >= 1)");
        DB::statement("ALTER TABLE {$discharges} ADD CONSTRAINT idc_status_ck CHECK (encounter_status_before IN ('REGISTERED', 'IN_EXAMINATION') AND encounter_status_after = 'READY_FOR_RM')");
        $receipts = $grammar->wrapTable(SchemaQualifier::table('inpatient_discharge_operation_receipts'));
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT idcor_operation_ck CHECK (operation = 'INPATIENT_DISCHARGE_EXECUTE')");
    }

    private function qualifyPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->tables as $table) {
            $qualified = $this->qi($schema).'.'.$this->qi($table);
            $sequence = $this->qi($schema).'.'.$this->qi($table.'_id_seq');
            DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)', $qualified, $this->ql($sequence)));
        }
    }

    private function createGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresGuards(),
            'mysql' => $this->createMysqlGuards(),
            'sqlite' => null,
            default => throw new RuntimeException('Unsupported database driver for inpatient discharge.'),
        };
    }

    private function dropGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->dropPostgresGuards(),
            'mysql' => $this->dropMysqlGuards(),
            default => null,
        };
    }

    private function createPostgresGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $function = $this->qi($schema).'.'.$this->qi('protect_inpatient_discharge_evidence');
        DB::statement("CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF TG_OP = 'DELETE' AND current_setting('simrs.synthetic_reset', true) = '1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'inpatient discharge evidence is append-only' USING ERRCODE = '55000'; END; \$f\$");
        foreach ($this->tables as $table) {
            $qualified = $this->qi($schema).'.'.$this->qi($table);
            foreach (['update', 'delete'] as $operation) {
                DB::statement(sprintf('CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $this->qi($this->trigger($table, $operation)), strtoupper($operation), $qualified, $function));
            }
            DB::statement(sprintf('CREATE TRIGGER %s BEFORE TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION %s()', $this->qi($this->trigger($table, 'truncate')), $qualified, $function));
        }
    }

    private function dropPostgresGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->tables as $table) {
            $qualified = $this->qi($schema).'.'.$this->qi($table);
            foreach (['update', 'delete', 'truncate'] as $operation) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qi($this->trigger($table, $operation))." ON {$qualified}");
            }
        }
        DB::statement('DROP FUNCTION IF EXISTS '.$this->qi($schema).'.'.$this->qi('protect_inpatient_discharge_evidence').'()');
    }

    private function createMysqlGuards(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($this->tables as $table) {
            $qualified = $grammar->wrapTable(SchemaQualifier::table($table));
            DB::statement('CREATE TRIGGER '.$this->qmi($this->trigger($table, 'update'))." BEFORE UPDATE ON {$qualified} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inpatient discharge evidence is append-only'");
            DB::statement('CREATE TRIGGER '.$this->qmi($this->trigger($table, 'delete'))." BEFORE DELETE ON {$qualified} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inpatient discharge evidence is append-only'; END IF; END");
        }
    }

    private function dropMysqlGuards(): void
    {
        foreach ($this->tables as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qmi($this->trigger($table, $operation)));
            }
        }
    }

    private function trigger(string $table, string $operation): string
    {
        return ($table === 'inpatient_discharges' ? 'idc' : 'idcor').'_immutable_'.$operation.'_trg';
    }

    private function qi(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function ql(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function qmi(string $value): string
    {
        return '`'.str_replace('`', '``', $value).'`';
    }
};
