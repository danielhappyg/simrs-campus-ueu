<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientRmSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'inpatient_rm_codings',
        'inpatient_rm_coding_versions',
        'inpatient_rm_coding_assignments',
        'inpatient_rm_completeness_reviews',
        'inpatient_rm_completeness_items',
        'inpatient_rm_operation_receipts',
    ];

    /** @var array<string, list<string>> */
    private array $checks = [
        'inpatient_rm_codings' => ['irmc_definition_ck', 'irmc_profile_ck', 'irmc_version_ck', 'irmc_state_ck'],
        'inpatient_rm_coding_versions' => ['irmcv_definition_ck', 'irmcv_profile_ck', 'irmcv_version_ck', 'irmcv_source_version_ck', 'irmcv_state_ck'],
        'inpatient_rm_coding_assignments' => ['irmca_kind_ck', 'irmca_system_ck', 'irmca_profile_ck', 'irmca_no_procedure_ck'],
        'inpatient_rm_completeness_reviews' => ['irmr_definition_ck', 'irmr_state_ck', 'irmr_version_ck', 'irmr_blockers_ck', 'irmr_signoff_ck'],
        'inpatient_rm_completeness_items' => [],
        'inpatient_rm_operation_receipts' => ['irmor_operation_ck', 'irmor_result_type_ck', 'irmor_version_ck', 'irmor_binding_ck'],
    ];

    public function up(): void
    {
        $this->assertSyntheticBoundary();
        InpatientRmSchemaMutationScope::run(function (): void {
            Schema::create(SchemaQualifier::table('inpatient_rm_codings'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'irmc_encounter_fk')->restrictOnDelete();
                $table->foreignId('created_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'irmc_creator_fk')->restrictOnDelete();
                $table->string('definition_version', 64);
                $table->string('profile', 64);
                $table->unsignedInteger('version');
                $table->string('coding_state', 16);
                $table->boolean('current_is_complete');
                $table->string('current_content_digest', 64);
                $table->timestamps();
                $table->unique('public_id', 'irmc_public_id_uq');
                $table->unique('encounter_id', 'irmc_encounter_uq');
                $table->index(['current_is_complete', 'updated_at'], 'irmc_complete_time_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_rm_coding_versions'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('inpatient_rm_coding_id')->constrained(SchemaQualifier::table('inpatient_rm_codings'), indexName: 'irmcv_coding_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'irmcv_actor_fk')->restrictOnDelete();
                $table->foreignId('inpatient_discharge_coding_source_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_coding_source_versions'), indexName: 'irmcv_source_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('coding_state', 16);
                $table->string('definition_version', 64);
                $table->string('profile', 64);
                $table->string('source_public_id', 26);
                $table->unsignedInteger('source_version');
                $table->string('source_version_public_id', 26);
                $table->string('source_content_digest', 64);
                $table->string('source_provenance_digest', 64);
                $table->boolean('is_complete');
                $table->string('content_digest', 64);
                $table->timestamp('created_at')->useCurrent();
                $table->unique('public_id', 'irmcv_public_id_uq');
                $table->unique(['inpatient_rm_coding_id', 'version'], 'irmcv_coding_version_uq');
                $table->index(['source_version_public_id', 'created_at'], 'irmcv_source_time_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_rm_coding_assignments'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('inpatient_rm_coding_version_id')->constrained(SchemaQualifier::table('inpatient_rm_coding_versions'), indexName: 'irmca_version_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'irmca_actor_fk')->restrictOnDelete();
                $table->string('source_statement_kind', 32);
                $table->unsignedSmallInteger('source_statement_index');
                $table->string('source_statement_text_hash', 64);
                $table->text('source_statement_reference');
                $table->string('code_system', 16);
                $table->string('profile', 64);
                $table->string('normalized_code', 64)->nullable();
                $table->string('display', 255);
                $table->unsignedSmallInteger('ordinal');
                $table->boolean('is_no_procedure_attestation');
                $table->timestamp('created_at')->useCurrent();
                $table->unique('public_id', 'irmca_public_id_uq');
                $table->unique(['inpatient_rm_coding_version_id', 'source_statement_kind', 'source_statement_index', 'normalized_code'], 'irmca_version_source_code_uq');
                $table->unique(['inpatient_rm_coding_version_id', 'ordinal'], 'irmca_version_ordinal_uq');
                $table->index(['source_statement_text_hash', 'code_system', 'normalized_code'], 'irmca_source_code_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_rm_completeness_reviews'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'irmr_encounter_fk')->restrictOnDelete();
                $table->foreignId('inpatient_rm_coding_version_id')->constrained(SchemaQualifier::table('inpatient_rm_coding_versions'), indexName: 'irmr_coding_version_fk')->restrictOnDelete();
                $table->foreignId('reviewed_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'irmr_reviewer_fk')->restrictOnDelete();
                $table->foreignId('signed_off_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'irmr_signer_fk')->restrictOnDelete();
                $table->string('definition_version', 64);
                $table->unsignedInteger('version');
                $table->string('source_fingerprint', 64);
                $table->unsignedInteger('coding_version');
                $table->string('coding_digest', 64);
                $table->string('source_version_public_id', 26);
                $table->string('source_content_digest', 64);
                $table->string('source_provenance_digest', 64);
                $table->string('review_state', 16);
                $table->unsignedSmallInteger('blocker_count');
                $table->timestamp('reviewed_at');
                $table->timestamp('signed_off_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique('public_id', 'irmr_public_id_uq');
                $table->unique(['encounter_id', 'version'], 'irmr_encounter_version_uq');
                $table->index(['review_state', 'created_at'], 'irmr_state_time_idx');
            });

            Schema::create(SchemaQualifier::table('inpatient_rm_completeness_items'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('inpatient_rm_completeness_review_id')->constrained(SchemaQualifier::table('inpatient_rm_completeness_reviews'), indexName: 'irmi_review_fk')->restrictOnDelete();
                $table->string('item_code', 64);
                $table->string('label', 180);
                $table->boolean('is_blocking');
                $table->boolean('is_complete');
                $table->string('source_reference', 128)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique('public_id', 'irmi_public_id_uq');
                $table->unique(['inpatient_rm_completeness_review_id', 'item_code'], 'irmi_review_item_uq');
            });

            Schema::create(SchemaQualifier::table('inpatient_rm_operation_receipts'), function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 26);
                $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'irmor_encounter_fk')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'irmor_actor_fk')->restrictOnDelete();
                $table->foreignId('inpatient_discharge_coding_source_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_coding_source_versions'), indexName: 'irmor_source_fk')->restrictOnDelete();
                $table->foreignId('inpatient_rm_coding_version_id')->constrained(SchemaQualifier::table('inpatient_rm_coding_versions'), indexName: 'irmor_coding_version_fk')->restrictOnDelete();
                $table->foreignId('inpatient_rm_completeness_review_id')->nullable()->constrained(SchemaQualifier::table('inpatient_rm_completeness_reviews'), indexName: 'irmor_review_fk')->restrictOnDelete();
                $table->string('operation', 64);
                $table->string('idempotency_key', 255);
                $table->string('payload_digest', 64);
                $table->string('source_version_public_id', 26);
                $table->string('source_content_digest', 64);
                $table->string('source_provenance_digest', 64);
                $table->string('coding_public_id', 26);
                $table->unsignedInteger('coding_version');
                $table->string('coding_digest', 64);
                $table->string('result_type', 16);
                $table->string('result_public_id', 26);
                $table->unsignedInteger('result_version');
                $table->string('request_correlation_id', 26)->nullable();
                $table->timestamp('completed_at')->useCurrent();
                $table->timestamps();
                $table->unique('public_id', 'irmor_public_id_uq');
                $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'irmor_actor_operation_key_uq');
                $table->index(['encounter_id', 'completed_at'], 'irmor_encounter_time_idx');
            });

            $this->addChecks();
            $this->qualifyPostgresSequences();
            $this->createGuards();
        });
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit)
            && DB::table($audit)->where('action', 'like', 'rmik.inpatient.%')->exists()) {
            throw new RuntimeException('Refusing to roll back inpatient RMIK closure because correlated audit evidence remains.');
        }
        foreach ($this->tables as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient RMIK closure because retained business evidence exists.');
            }
        }

        InpatientRmSchemaMutationScope::run(function (): void {
            $this->dropGuards();
            $this->dropChecks();
            foreach (array_reverse($this->tables) as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
        });
    }

    private function assertSyntheticBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient RMIK closure migration requires synthetic simulation mode.');
        }
        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Inpatient RMIK closure migration refused because non-synthetic patient data exists.');
        }
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        $coding = $grammar->wrapTable(SchemaQualifier::table('inpatient_rm_codings'));
        DB::statement("ALTER TABLE {$coding} ADD CONSTRAINT irmc_definition_ck CHECK (definition_version = 'INPATIENT_RM_MANUAL_CODING_V1')");
        DB::statement("ALTER TABLE {$coding} ADD CONSTRAINT irmc_profile_ck CHECK (profile = 'LOCAL_TEACHING_MANUAL_V1')");
        DB::statement("ALTER TABLE {$coding} ADD CONSTRAINT irmc_version_ck CHECK (version >= 1)");
        DB::statement("ALTER TABLE {$coding} ADD CONSTRAINT irmc_state_ck CHECK (coding_state IN ('DRAFT','FINAL'))");

        $versions = $grammar->wrapTable(SchemaQualifier::table('inpatient_rm_coding_versions'));
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT irmcv_definition_ck CHECK (definition_version = 'INPATIENT_RM_MANUAL_CODING_V1')");
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT irmcv_profile_ck CHECK (profile = 'LOCAL_TEACHING_MANUAL_V1')");
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT irmcv_version_ck CHECK (version >= 1)");
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT irmcv_source_version_ck CHECK (source_version >= 1)");
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT irmcv_state_ck CHECK (coding_state IN ('DRAFT','FINAL'))");

        $assignments = $grammar->wrapTable(SchemaQualifier::table('inpatient_rm_coding_assignments'));
        DB::statement("ALTER TABLE {$assignments} ADD CONSTRAINT irmca_kind_ck CHECK (source_statement_kind IN ('PRINCIPAL_DIAGNOSIS','SECONDARY_DIAGNOSIS','PROCEDURE','NO_PROCEDURE'))");
        DB::statement("ALTER TABLE {$assignments} ADD CONSTRAINT irmca_system_ck CHECK (((source_statement_kind IN ('PRINCIPAL_DIAGNOSIS','SECONDARY_DIAGNOSIS')) AND code_system = 'ICD-10') OR ((source_statement_kind IN ('PROCEDURE','NO_PROCEDURE')) AND code_system = 'ICD-9-CM'))");
        DB::statement("ALTER TABLE {$assignments} ADD CONSTRAINT irmca_profile_ck CHECK (profile = 'LOCAL_TEACHING_MANUAL_V1')");
        DB::statement("ALTER TABLE {$assignments} ADD CONSTRAINT irmca_no_procedure_ck CHECK ((source_statement_kind = 'NO_PROCEDURE' AND code_system = 'ICD-9-CM' AND normalized_code IS NULL AND is_no_procedure_attestation = TRUE) OR (source_statement_kind <> 'NO_PROCEDURE' AND normalized_code IS NOT NULL AND is_no_procedure_attestation = FALSE))");

        $reviews = $grammar->wrapTable(SchemaQualifier::table('inpatient_rm_completeness_reviews'));
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT irmr_definition_ck CHECK (definition_version = 'INPATIENT_RM_COMPLETENESS_V1')");
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT irmr_state_ck CHECK (review_state IN ('DRAFT','SIGNED_OFF'))");
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT irmr_version_ck CHECK (version >= 1)");
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT irmr_blockers_ck CHECK (blocker_count >= 0)");
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT irmr_signoff_ck CHECK ((review_state = 'DRAFT' AND signed_off_by_user_id IS NULL AND signed_off_at IS NULL) OR (review_state = 'SIGNED_OFF' AND signed_off_by_user_id IS NOT NULL AND signed_off_at IS NOT NULL))");

        $receipts = $grammar->wrapTable(SchemaQualifier::table('inpatient_rm_operation_receipts'));
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT irmor_operation_ck CHECK (operation IN ('INPATIENT_RM_CODING_DRAFT_SAVE','INPATIENT_RM_COMPLETENESS_REVIEW_SAVE','INPATIENT_RM_EPISODE_SIGNOFF'))");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT irmor_result_type_ck CHECK (result_type IN ('CODING','REVIEW'))");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT irmor_version_ck CHECK (result_version >= 1)");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT irmor_binding_ck CHECK ((operation = 'INPATIENT_RM_CODING_DRAFT_SAVE' AND result_type = 'CODING' AND inpatient_rm_completeness_review_id IS NULL) OR (operation IN ('INPATIENT_RM_COMPLETENESS_REVIEW_SAVE','INPATIENT_RM_EPISODE_SIGNOFF') AND result_type = 'REVIEW' AND inpatient_rm_completeness_review_id IS NOT NULL))");
    }

    private function dropChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($this->checks as $table => $constraints) {
            if (! Schema::hasTable(SchemaQualifier::table($table))) {
                continue;
            }
            $qualified = $grammar->wrapTable(SchemaQualifier::table($table));
            foreach ($constraints as $constraint) {
                $quoted = $driver === 'mysql' ? $this->qmi($constraint) : $this->qi($constraint);
                $operation = $driver === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT';
                DB::statement("ALTER TABLE {$qualified} {$operation} {$quoted}");
            }
        }
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

    /** @return list<string> */
    private function immutableTables(): array
    {
        return [
            'inpatient_rm_coding_versions',
            'inpatient_rm_coding_assignments',
            'inpatient_rm_completeness_reviews',
            'inpatient_rm_completeness_items',
            'inpatient_rm_operation_receipts',
        ];
    }

    private function createGuards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresGuards(),
            'mysql' => $this->createMysqlGuards(),
            'sqlite' => null,
            default => throw new RuntimeException('Unsupported database driver for inpatient RMIK closure.'),
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
        $function = $this->qi($schema).'.'.$this->qi('protect_inpatient_rm_evidence');
        DB::statement("CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF TG_OP='DELETE' AND current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'inpatient RMIK evidence is append-only' USING ERRCODE='55000'; END; \$f\$");
        foreach ($this->immutableTables() as $table) {
            $qualified = $this->qi($schema).'.'.$this->qi($table);
            foreach (['update', 'delete'] as $operation) {
                DB::statement('CREATE TRIGGER '.$this->qi($this->trigger($table, $operation)).' BEFORE '.strtoupper($operation)." ON {$qualified} FOR EACH ROW EXECUTE FUNCTION {$function}()");
            }
            DB::statement('CREATE TRIGGER '.$this->qi($this->trigger($table, 'truncate'))." BEFORE TRUNCATE ON {$qualified} FOR EACH STATEMENT EXECUTE FUNCTION {$function}()");
        }
        $headFunction = $this->qi($schema).'.'.$this->qi('protect_inpatient_rm_coding_head');
        DB::statement("CREATE OR REPLACE FUNCTION {$headFunction}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF TG_OP='DELETE' THEN IF current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'inpatient RMIK coding head cannot be deleted' USING ERRCODE='55000'; END IF; IF OLD.coding_state<>'DRAFT' OR NEW.version<>OLD.version+1 OR NEW.public_id<>OLD.public_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.created_by_user_id<>OLD.created_by_user_id OR NEW.definition_version<>OLD.definition_version OR NEW.profile<>OLD.profile OR NEW.coding_state NOT IN ('DRAFT','FINAL') THEN RAISE EXCEPTION 'invalid inpatient RMIK coding head transition' USING ERRCODE='55000'; END IF; RETURN NEW; END; \$f\$");
        $head = $this->qi($schema).'.'.$this->qi('inpatient_rm_codings');
        DB::statement('CREATE TRIGGER '.$this->qi('irmc_guard_update_trg')." BEFORE UPDATE ON {$head} FOR EACH ROW EXECUTE FUNCTION {$headFunction}()");
        DB::statement('CREATE TRIGGER '.$this->qi('irmc_guard_delete_trg')." BEFORE DELETE ON {$head} FOR EACH ROW EXECUTE FUNCTION {$headFunction}()");
    }

    private function dropPostgresGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        foreach ($this->immutableTables() as $table) {
            $qualified = $this->qi($schema).'.'.$this->qi($table);
            foreach (['update', 'delete', 'truncate'] as $operation) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qi($this->trigger($table, $operation))." ON {$qualified}");
            }
        }
        $head = $this->qi($schema).'.'.$this->qi('inpatient_rm_codings');
        DB::statement('DROP TRIGGER IF EXISTS '.$this->qi('irmc_guard_update_trg')." ON {$head}");
        DB::statement('DROP TRIGGER IF EXISTS '.$this->qi('irmc_guard_delete_trg')." ON {$head}");
        DB::statement('DROP FUNCTION IF EXISTS '.$this->qi($schema).'.'.$this->qi('protect_inpatient_rm_coding_head').'()');
        DB::statement('DROP FUNCTION IF EXISTS '.$this->qi($schema).'.'.$this->qi('protect_inpatient_rm_evidence').'()');
    }

    private function createMysqlGuards(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($this->immutableTables() as $table) {
            $qualified = $grammar->wrapTable(SchemaQualifier::table($table));
            DB::statement('CREATE TRIGGER '.$this->qmi($this->trigger($table, 'update'))." BEFORE UPDATE ON {$qualified} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inpatient RMIK evidence is append-only'");
            DB::statement('CREATE TRIGGER '.$this->qmi($this->trigger($table, 'delete'))." BEFORE DELETE ON {$qualified} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inpatient RMIK evidence is append-only'; END IF; END");
        }
        $head = $grammar->wrapTable(SchemaQualifier::table('inpatient_rm_codings'));
        DB::statement('CREATE TRIGGER '.$this->qmi('irmc_guard_update_trg')." BEFORE UPDATE ON {$head} FOR EACH ROW BEGIN IF OLD.coding_state<>'DRAFT' OR NEW.version<>OLD.version+1 OR NEW.public_id<>OLD.public_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.created_by_user_id<>OLD.created_by_user_id OR NEW.definition_version<>OLD.definition_version OR NEW.profile<>OLD.profile OR NEW.coding_state NOT IN ('DRAFT','FINAL') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid inpatient RMIK coding head transition'; END IF; END");
        DB::statement('CREATE TRIGGER '.$this->qmi('irmc_guard_delete_trg')." BEFORE DELETE ON {$head} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inpatient RMIK coding head cannot be deleted'; END IF; END");
    }

    private function dropMysqlGuards(): void
    {
        foreach ($this->immutableTables() as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qmi($this->trigger($table, $operation)));
            }
        }
        DB::statement('DROP TRIGGER IF EXISTS '.$this->qmi('irmc_guard_update_trg'));
        DB::statement('DROP TRIGGER IF EXISTS '.$this->qmi('irmc_guard_delete_trg'));
    }

    private function trigger(string $table, string $operation): string
    {
        $prefix = match ($table) {
            'inpatient_rm_coding_versions' => 'irmcv',
            'inpatient_rm_coding_assignments' => 'irmca',
            'inpatient_rm_completeness_reviews' => 'irmr',
            'inpatient_rm_completeness_items' => 'irmi',
            default => 'irmor',
        };

        return $prefix.'_immutable_'.$operation.'_trg';
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
