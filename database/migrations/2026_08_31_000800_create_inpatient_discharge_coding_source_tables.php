<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientDischargeCodingSourceSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['inpatient_discharge_coding_sources', 'inpatient_discharge_coding_source_versions', 'inpatient_discharge_coding_source_operation_receipts'];

    public function up(): void
    {
        $this->assertBoundary();
        InpatientDischargeCodingSourceSchemaMutationScope::run(function (): void {
            Schema::create(SchemaQualifier::table('inpatient_discharge_coding_sources'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26);
                $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'idcs_encounter_fk')->restrictOnDelete();
                $t->foreignId('assigned_physician_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idcs_physician_fk')->restrictOnDelete();
                $t->foreignId('finalized_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'idcs_finalizer_fk')->restrictOnDelete();
                $t->string('source_state', 16);
                $t->string('definition_version', 64);
                $t->unsignedInteger('version');
                $t->text('principal_diagnosis_statement')->nullable();
                $t->json('secondary_diagnosis_statements');
                $t->string('procedure_attestation', 32);
                $t->json('performed_procedure_statements');
                $t->timestamp('finalized_at')->nullable();
                $t->timestamps();
                $t->unique('public_id', 'idcs_public_id_uq');
                $t->unique('encounter_id', 'idcs_encounter_uq');
                $t->index(['source_state', 'updated_at'], 'idcs_state_time_idx');
            });
            Schema::create(SchemaQualifier::table('inpatient_discharge_coding_source_versions'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26);
                $t->foreignId('inpatient_discharge_coding_source_id')->constrained(SchemaQualifier::table('inpatient_discharge_coding_sources'), indexName: 'idcsv_source_fk')->restrictOnDelete();
                $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idcsv_actor_fk')->restrictOnDelete();
                $t->unsignedInteger('version');
                $t->string('source_state', 16);
                $t->string('definition_version', 64);
                $t->text('principal_diagnosis_statement')->nullable();
                $t->json('secondary_diagnosis_statements');
                $t->string('procedure_attestation', 32);
                $t->json('performed_procedure_statements');
                $t->string('encounter_public_id', 26);
                $t->string('care_setting', 32);
                $t->string('encounter_status', 32);
                $t->unsignedInteger('location_sequence');
                $t->string('location_event_public_id', 26)->nullable();
                $t->string('location_event_type', 32)->nullable();
                $t->string('history_baseline', 32)->nullable();
                $t->boolean('history_complete');
                $t->string('ward_public_id', 26);
                $t->string('ward_code', 64);
                $t->string('ward_display_name', 120);
                $t->string('bed_public_id', 26);
                $t->string('bed_code', 64);
                $t->string('bed_display_name', 120);
                $t->string('room_label', 120);
                $t->string('service_class', 120);
                $t->timestamp('finalized_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique('public_id', 'idcsv_public_id_uq');
                $t->unique(['inpatient_discharge_coding_source_id', 'version'], 'idcsv_source_version_uq');
                $t->index(['encounter_public_id', 'created_at'], 'idcsv_encounter_time_idx');
            });
            Schema::create(SchemaQualifier::table('inpatient_discharge_coding_source_operation_receipts'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26);
                $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'idcsor_encounter_fk')->restrictOnDelete();
                $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idcsor_actor_fk')->restrictOnDelete();
                $t->string('operation', 64);
                $t->string('idempotency_key', 255);
                $t->string('payload_digest', 64);
                $t->string('result_source_public_id', 26);
                $t->unsignedInteger('result_version');
                $t->string('request_correlation_id', 26)->nullable();
                $t->timestamp('completed_at')->useCurrent();
                $t->timestamps();
                $t->unique('public_id', 'idcsor_public_id_uq');
                $t->unique(['actor_user_id', 'operation', 'idempotency_key'], 'idcsor_actor_operation_key_uq');
                $t->index(['encounter_id', 'completed_at'], 'idcsor_encounter_time_idx');
            });
            $this->addDischargeBindings('inpatient_discharges', 'idc_coding_version_fk');
            $this->addDischargeBindings('inpatient_discharge_operation_receipts', 'idcor_coding_version_fk');
            $this->checks();
            $this->qualifySequences();
            $this->createGuards();
        });
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'like', 'clinical.inpatient.discharge-coding-source.%')->exists()) {
            throw new RuntimeException('Refusing to roll back inpatient discharge coding source because correlated audit evidence remains.');
        }
        foreach ($this->tables as $name) {
            if (Schema::hasTable(SchemaQualifier::table($name)) && DB::table(SchemaQualifier::table($name))->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient discharge coding source because retained evidence exists.');
            }
        }
        foreach (['inpatient_discharges', 'inpatient_discharge_operation_receipts'] as $name) {
            if (Schema::hasTable(SchemaQualifier::table($name)) && DB::table(SchemaQualifier::table($name))->whereNotNull('inpatient_discharge_coding_source_version_id')->exists()) {
                throw new RuntimeException('Refusing to roll back coding-source bindings retained by discharge evidence.');
            }
        }
        InpatientDischargeCodingSourceSchemaMutationScope::run(function (): void {
            $this->dropGuards();
            $this->dropDischargeBindingChecks();
            $this->dropDischargeBindings('inpatient_discharge_operation_receipts', 'idcor_coding_version_fk');
            $this->dropDischargeBindings('inpatient_discharges', 'idc_coding_version_fk');
            foreach (array_reverse($this->tables) as $name) {
                Schema::dropIfExists(SchemaQualifier::table($name));
            }
        });
    }

    private function assertBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient discharge coding source migration requires synthetic simulation mode.');
        }
        $p = SchemaQualifier::table('patients');
        if (Schema::hasTable($p) && DB::table($p)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Migration refused because non-synthetic patient data exists.');
        }
    }

    private function checks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        } $g = DB::connection()->getQueryGrammar();
        foreach (['inpatient_discharge_coding_sources', 'inpatient_discharge_coding_source_versions'] as $n) {
            $t = $g->wrapTable(SchemaQualifier::table($n));
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$n}_state_ck CHECK (source_state IN ('DRAFT','FINAL'))");
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$n}_definition_ck CHECK (definition_version = 'INPATIENT_DISCHARGE_CODING_SOURCE_V1')");
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$n}_version_ck CHECK (version >= 1)");
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$n}_attestation_ck CHECK (procedure_attestation IN ('NO_PROCEDURE_RECORDED','PROCEDURES_RECORDED'))");
        }
        $r = $g->wrapTable(SchemaQualifier::table('inpatient_discharge_coding_source_operation_receipts'));
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT idcsor_operation_ck CHECK (operation IN ('DISCHARGE_CODING_SOURCE_DRAFT_SAVE','DISCHARGE_CODING_SOURCE_FINALIZE'))");
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT idcsor_version_ck CHECK (result_version >= 1)");
        foreach (['inpatient_discharges' => 'idc_coding_binding_ck', 'inpatient_discharge_operation_receipts' => 'idcor_coding_binding_ck'] as $name => $constraint) {
            $t = $g->wrapTable(SchemaQualifier::table($name));
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$constraint} CHECK ((inpatient_discharge_coding_source_version_id IS NULL AND discharge_coding_source_public_id IS NULL AND discharge_coding_source_version IS NULL AND discharge_coding_source_version_public_id IS NULL AND discharge_coding_source_content_digest IS NULL AND discharge_coding_source_provenance_digest IS NULL) OR (inpatient_discharge_coding_source_version_id IS NOT NULL AND discharge_coding_source_public_id IS NOT NULL AND discharge_coding_source_version IS NOT NULL AND discharge_coding_source_version_public_id IS NOT NULL AND discharge_coding_source_content_digest IS NOT NULL AND discharge_coding_source_provenance_digest IS NOT NULL))");
        }
    }

    private function addDischargeBindings(string $name, string $fk): void
    {
        Schema::table(SchemaQualifier::table($name), function (Blueprint $t) use ($fk): void {
            $t->foreignId('inpatient_discharge_coding_source_version_id')->nullable()->constrained(SchemaQualifier::table('inpatient_discharge_coding_source_versions'), indexName: $fk)->restrictOnDelete();
            $t->string('discharge_coding_source_public_id', 26)->nullable();
            $t->unsignedInteger('discharge_coding_source_version')->nullable();
            $t->string('discharge_coding_source_version_public_id', 26)->nullable();
            $t->string('discharge_coding_source_content_digest', 64)->nullable();
            $t->string('discharge_coding_source_provenance_digest', 64)->nullable();
        });
    }

    private function dropDischargeBindings(string $name, string $fk): void
    {
        Schema::table(SchemaQualifier::table($name), function (Blueprint $t) use ($fk): void {
            $t->dropForeign($fk);
            $t->dropColumn(['inpatient_discharge_coding_source_version_id', 'discharge_coding_source_public_id', 'discharge_coding_source_version', 'discharge_coding_source_version_public_id', 'discharge_coding_source_content_digest', 'discharge_coding_source_provenance_digest']);
        });
    }

    private function dropDischargeBindingChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        foreach (['inpatient_discharges' => 'idc_coding_binding_ck', 'inpatient_discharge_operation_receipts' => 'idcor_coding_binding_ck'] as $name => $constraint) {
            $table = $grammar->wrapTable(SchemaQualifier::table($name));
            $quotedConstraint = $driver === 'mysql' ? $this->qmi($constraint) : $this->qi($constraint);
            $operation = $driver === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT';
            DB::statement("ALTER TABLE {$table} {$operation} {$quotedConstraint}");
        }
    }

    private function qualifySequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        } $s = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->tables as $n) {
            $qt = $this->qi($s).'.'.$this->qi($n);
            $seq = $this->qi($s).'.'.$this->qi($n.'_id_seq');
            DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)', $qt, $this->ql($seq)));
        }
    }

    /** @return list<string> */
    private function immutableTables(): array
    {
        return ['inpatient_discharge_coding_source_versions', 'inpatient_discharge_coding_source_operation_receipts'];
    }

    private function createGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPg(),'mysql' => $this->createMy(),'sqlite' => null,default => throw new RuntimeException('Unsupported database driver.')
        };
    }

    private function dropGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->dropPg(),'mysql' => $this->dropMy(),default => null
        };
    }

    private function createPg(): void
    {
        $s = SchemaQualifier::primarySchema() ?? 'public';
        $f = $this->qi($s).'.'.$this->qi('protect_inpatient_discharge_coding_source_evidence');
        DB::statement("CREATE OR REPLACE FUNCTION {$f}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF TG_OP='DELETE' AND current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'inpatient discharge coding source evidence is append-only' USING ERRCODE='55000'; END; \$f\$");
        foreach ($this->immutableTables() as $n) {
            $q = $this->qi($s).'.'.$this->qi($n);
            foreach (['update', 'delete'] as $op) {
                DB::statement('CREATE TRIGGER '.$this->qi($this->tr($n, $op)).' BEFORE '.strtoupper($op)." ON {$q} FOR EACH ROW EXECUTE FUNCTION {$f}()");
            }DB::statement('CREATE TRIGGER '.$this->qi($this->tr($n, 'truncate'))." BEFORE TRUNCATE ON {$q} FOR EACH STATEMENT EXECUTE FUNCTION {$f}()");
        }
    }

    private function dropPg(): void
    {
        $s = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->immutableTables() as $n) {
            $q = $this->qi($s).'.'.$this->qi($n);
            foreach (['update', 'delete', 'truncate'] as $op) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qi($this->tr($n, $op))." ON {$q}");
            }
        }DB::statement('DROP FUNCTION IF EXISTS '.$this->qi($s).'.'.$this->qi('protect_inpatient_discharge_coding_source_evidence').'()');
    }

    private function createMy(): void
    {
        $g = DB::connection()->getQueryGrammar();
        foreach ($this->immutableTables() as $n) {
            $q = $g->wrapTable(SchemaQualifier::table($n));
            DB::statement('CREATE TRIGGER '.$this->qmi($this->tr($n, 'update'))." BEFORE UPDATE ON {$q} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inpatient discharge coding source evidence is append-only'");
            DB::statement('CREATE TRIGGER '.$this->qmi($this->tr($n, 'delete'))." BEFORE DELETE ON {$q} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inpatient discharge coding source evidence is append-only'; END IF; END");
        }
    }

    private function dropMy(): void
    {
        foreach ($this->immutableTables() as $n) {
            foreach (['update', 'delete'] as $op) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qmi($this->tr($n, $op)));
            }
        }
    }

    private function tr(string $n, string $op): string
    {
        return ($n === 'inpatient_discharge_coding_source_versions' ? 'idcsv' : 'idcsor').'_immutable_'.$op.'_trg';
    }

    private function qi(string $v): string
    {
        return '"'.str_replace('"', '""', $v).'"';
    }

    private function ql(string $v): string
    {
        return "'".str_replace("'", "''", $v)."'";
    }

    private function qmi(string $v): string
    {
        return '`'.str_replace('`', '``', $v).'`';
    }
};
