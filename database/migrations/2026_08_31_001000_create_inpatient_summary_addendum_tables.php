<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientSummaryAddendumSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['inpatient_summary_correction_requests', 'inpatient_summary_correction_request_versions', 'inpatient_summary_addenda', 'inpatient_summary_addendum_versions', 'inpatient_summary_addendum_reviews', 'inpatient_summary_addendum_review_items', 'inpatient_summary_addendum_operation_receipts'];

    /** @var array<string, list<string>> */
    private array $checks = [
        'inpatient_summary_correction_requests' => ['iscr_state_ck', 'iscr_active_ck', 'iscr_version_ck', 'iscr_reason_ck', 'iscr_other_note_ck', 'iscr_attribution_ck'],
        'inpatient_summary_correction_request_versions' => ['iscrv_state_ck', 'iscrv_active_ck', 'iscrv_version_ck'],
        'inpatient_summary_addenda' => ['isa_state_ck', 'isa_def_ck', 'isa_text_ck', 'isa_version_ck', 'isa_final_ck'],
        'inpatient_summary_addendum_versions' => ['isav_state_ck', 'isav_def_ck', 'isav_text_ck', 'isav_version_ck', 'isav_final_ck'],
        'inpatient_summary_addendum_reviews' => ['isar_state_ck', 'isar_def_ck', 'isar_version_ck', 'isar_blocker_ck', 'isar_sign_ck'],
        'inpatient_summary_addendum_operation_receipts' => ['isaor_operation_ck', 'isaor_result_ck', 'isaor_version_ck', 'isaor_binding_ck'],
    ];

    public function up(): void
    {
        $this->assertBoundary();
        InpatientSummaryAddendumSchemaMutationScope::run(function (): void {
            Schema::create(SchemaQualifier::table('inpatient_summary_correction_requests'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique('iscr_public_uq');
                $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'iscr_enc_fk')->restrictOnDelete();
                $t->foreignId('inpatient_discharge_id')->constrained(SchemaQualifier::table('inpatient_discharges'), indexName: 'iscr_dis_fk')->restrictOnDelete();
                $t->foreignId('inpatient_discharge_summary_id')->constrained(SchemaQualifier::table('inpatient_discharge_summaries'), indexName: 'iscr_sum_fk')->restrictOnDelete();
                $t->foreignId('inpatient_discharge_summary_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_summary_versions'), indexName: 'iscr_sumv_fk')->restrictOnDelete();
                $t->foreignId('inpatient_discharge_coding_source_id')->constrained(SchemaQualifier::table('inpatient_discharge_coding_sources'), indexName: 'iscr_src_fk')->restrictOnDelete();
                $t->foreignId('inpatient_discharge_coding_source_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_coding_source_versions'), indexName: 'iscr_srcv_fk')->restrictOnDelete();
                $t->foreignId('inpatient_rm_coding_id')->constrained(SchemaQualifier::table('inpatient_rm_codings'), indexName: 'iscr_cod_fk')->restrictOnDelete();
                $t->foreignId('inpatient_rm_coding_version_id')->constrained(SchemaQualifier::table('inpatient_rm_coding_versions'), indexName: 'iscr_codv_fk')->restrictOnDelete();
                $t->foreignId('baseline_review_id')->constrained(SchemaQualifier::table('inpatient_rm_completeness_reviews'), indexName: 'iscr_rev_fk')->restrictOnDelete();
                $t->foreignId('requested_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'iscr_req_fk')->restrictOnDelete();
                $t->foreignId('decided_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'iscr_dec_fk')->restrictOnDelete();
                $t->string('reason_code', 32);
                $t->string('note', 500)->nullable();
                $t->string('request_state', 16);
                $t->string('active_slot', 16)->nullable();
                $t->unsignedInteger('version');
                $t->string('summary_content_digest', 64);
                $t->string('summary_provenance_digest', 64);
                $t->string('source_content_digest', 64);
                $t->string('source_provenance_digest', 64);
                $t->string('coding_content_digest', 64);
                $t->string('baseline_fingerprint', 64);
                $t->string('decision_note', 500)->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamp('consumed_at')->nullable();
                $t->string('request_correlation_id', 26)->nullable();
                $t->timestamps();
                $t->unique(['encounter_id', 'active_slot'], 'iscr_active_uq');
            });
            Schema::create(SchemaQualifier::table('inpatient_summary_correction_request_versions'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique('iscrv_public_uq');
                $t->foreignId('correction_request_id')->constrained(SchemaQualifier::table('inpatient_summary_correction_requests'), indexName: 'iscrv_req_fk')->restrictOnDelete();
                $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'iscrv_actor_fk')->restrictOnDelete();
                $t->unsignedInteger('version');
                $t->string('request_state', 16);
                $t->string('active_slot', 16)->nullable();
                $t->foreignId('decided_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'iscrv_dec_fk')->restrictOnDelete();
                $t->string('decision_note', 500)->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamp('consumed_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['correction_request_id', 'version'], 'iscrv_req_ver_uq');
            });
            Schema::create(SchemaQualifier::table('inpatient_summary_addenda'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique('isa_public_uq');
                $t->foreignId('correction_request_id')->constrained(SchemaQualifier::table('inpatient_summary_correction_requests'), indexName: 'isa_req_fk')->restrictOnDelete();
                $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'isa_enc_fk')->restrictOnDelete();
                $t->foreignId('author_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'isa_author_fk')->restrictOnDelete();
                $t->foreignId('finalized_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'isa_final_fk')->restrictOnDelete();
                $t->string('definition_version', 64);
                $t->string('addendum_state', 16);
                $t->unsignedInteger('version');
                foreach (['admission_reason', 'significant_findings', 'care_and_treatment_summary', 'condition_at_discharge', 'follow_up_plan'] as $field) {
                    $t->text($field)->nullable();
                }
                $t->string('current_content_digest', 64);
                $t->timestamp('finalized_at')->nullable();
                $t->timestamps();
                $t->unique('correction_request_id', 'isa_req_uq');
            });
            Schema::create(SchemaQualifier::table('inpatient_summary_addendum_versions'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique('isav_public_uq');
                $t->foreignId('addendum_id')->constrained(SchemaQualifier::table('inpatient_summary_addenda'), indexName: 'isav_add_fk')->restrictOnDelete();
                $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'isav_actor_fk')->restrictOnDelete();
                $t->unsignedInteger('version');
                $t->string('addendum_state', 16);
                $t->string('definition_version', 64);
                foreach (['admission_reason', 'significant_findings', 'care_and_treatment_summary', 'condition_at_discharge', 'follow_up_plan'] as $field) {
                    $t->text($field)->nullable();
                }
                $t->string('content_digest', 64);
                $t->timestamp('finalized_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['addendum_id', 'version'], 'isav_add_ver_uq');
            });
            Schema::create(SchemaQualifier::table('inpatient_summary_addendum_reviews'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique('isar_public_uq');
                $t->foreignId('correction_request_id')->constrained(SchemaQualifier::table('inpatient_summary_correction_requests'), indexName: 'isar_req_fk')->restrictOnDelete();
                $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'isar_enc_fk')->restrictOnDelete();
                $t->foreignId('addendum_version_id')->constrained(SchemaQualifier::table('inpatient_summary_addendum_versions'), indexName: 'isar_addv_fk')->restrictOnDelete();
                $t->foreignId('baseline_review_id')->constrained(SchemaQualifier::table('inpatient_rm_completeness_reviews'), indexName: 'isar_base_fk')->restrictOnDelete();
                $t->foreignId('reviewed_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'isar_reviewer_fk')->restrictOnDelete();
                $t->foreignId('signed_off_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'isar_sign_fk')->restrictOnDelete();
                $t->string('definition_version', 64);
                $t->unsignedInteger('version');
                $t->string('source_fingerprint', 64);
                $t->string('addendum_content_digest', 64);
                $t->string('review_state', 16);
                $t->unsignedInteger('blocker_count');
                $t->timestamp('reviewed_at');
                $t->timestamp('signed_off_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['correction_request_id', 'version'], 'isar_req_ver_uq');
            });
            Schema::create(SchemaQualifier::table('inpatient_summary_addendum_review_items'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique('isari_public_uq');
                $t->foreignId('review_id')->constrained(SchemaQualifier::table('inpatient_summary_addendum_reviews'), indexName: 'isari_rev_fk')->restrictOnDelete();
                $t->string('item_code', 64);
                $t->string('label', 255);
                $t->boolean('is_blocking')->default(true);
                $t->boolean('is_complete');
                $t->string('source_reference', 255)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['review_id', 'item_code'], 'isari_rev_item_uq');
            });
            Schema::create(SchemaQualifier::table('inpatient_summary_addendum_operation_receipts'), function (Blueprint $t): void {
                $t->id();
                $t->string('public_id', 26)->unique('isaor_public_uq');
                $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'isaor_enc_fk')->restrictOnDelete();
                $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'isaor_actor_fk')->restrictOnDelete();
                $t->foreignId('correction_request_id')->constrained(SchemaQualifier::table('inpatient_summary_correction_requests'), indexName: 'isaor_req_fk')->restrictOnDelete();
                $t->foreignId('request_version_id')->constrained(SchemaQualifier::table('inpatient_summary_correction_request_versions'), indexName: 'isaor_reqv_fk')->restrictOnDelete();
                $t->foreignId('addendum_id')->nullable()->constrained(SchemaQualifier::table('inpatient_summary_addenda'), indexName: 'isaor_add_fk')->restrictOnDelete();
                $t->foreignId('addendum_version_id')->nullable()->constrained(SchemaQualifier::table('inpatient_summary_addendum_versions'), indexName: 'isaor_addv_fk')->restrictOnDelete();
                $t->foreignId('review_id')->nullable()->constrained(SchemaQualifier::table('inpatient_summary_addendum_reviews'), indexName: 'isaor_rev_fk')->restrictOnDelete();
                $t->foreignId('baseline_review_id')->constrained(SchemaQualifier::table('inpatient_rm_completeness_reviews'), indexName: 'isaor_base_fk')->restrictOnDelete();
                $t->foreignId('baseline_summary_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_summary_versions'), indexName: 'isaor_sumv_fk')->restrictOnDelete();
                $t->foreignId('baseline_source_version_id')->constrained(SchemaQualifier::table('inpatient_discharge_coding_source_versions'), indexName: 'isaor_srcv_fk')->restrictOnDelete();
                $t->foreignId('baseline_coding_version_id')->constrained(SchemaQualifier::table('inpatient_rm_coding_versions'), indexName: 'isaor_codv_fk')->restrictOnDelete();
                $t->string('operation', 32);
                $t->string('idempotency_key', 255);
                $t->string('payload_digest', 64);
                $t->string('baseline_fingerprint', 64);
                $t->string('addendum_content_digest', 64)->nullable();
                $t->string('result_type', 32);
                $t->string('result_public_id', 26);
                $t->unsignedInteger('result_version');
                $t->string('request_correlation_id', 26)->nullable();
                $t->timestamp('completed_at')->useCurrent();
                $t->unique(['actor_user_id', 'operation', 'idempotency_key'], 'isaor_actor_op_key_uq');
            });
            $this->checks();
            $this->qualifyPostgresSequences();
            $this->guards();
        });
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where(fn ($q) => $q->where('action', 'like', 'clinical.inpatient.summary-addendum.%')->orWhere('action', 'like', 'rmik.inpatient.summary-addendum.%'))->exists()) {
            throw new RuntimeException('Refusing summary addendum rollback because correlated audit remains.');
        }
        foreach ($this->tables as $table) {
            if (Schema::hasTable(SchemaQualifier::table($table)) && DB::table(SchemaQualifier::table($table))->exists()) {
                throw new RuntimeException('Refusing summary addendum rollback because retained business evidence exists.');
            }
        }
        InpatientSummaryAddendumSchemaMutationScope::run(function (): void {
            $this->dropGuards();
            $this->dropChecks();
            foreach (array_reverse($this->tables) as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
        });
    }

    private function assertBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Summary addendum migration requires the internal synthetic boundary.');
        }
    }

    private function checks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        $g = DB::connection()->getQueryGrammar();
        $r = $g->wrapTable(SchemaQualifier::table('inpatient_summary_correction_requests'));
        $a = $g->wrapTable(SchemaQualifier::table('inpatient_summary_addenda'));
        $v = $g->wrapTable(SchemaQualifier::table('inpatient_summary_addendum_versions'));
        $rv = $g->wrapTable(SchemaQualifier::table('inpatient_summary_correction_request_versions'));
        $review = $g->wrapTable(SchemaQualifier::table('inpatient_summary_addendum_reviews'));
        $receipt = $g->wrapTable(SchemaQualifier::table('inpatient_summary_addendum_operation_receipts'));
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT iscr_state_ck CHECK (request_state IN ('SUBMITTED','APPROVED','DENIED','CONSUMED'))");
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT iscr_active_ck CHECK ((request_state IN ('SUBMITTED','APPROVED') AND active_slot='ACTIVE') OR (request_state IN ('DENIED','CONSUMED') AND active_slot IS NULL))");
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT iscr_version_ck CHECK (version>=1)");
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT iscr_reason_ck CHECK (reason_code IN ('CLINICAL_CORRECTION','MISSING_INFORMATION','WRONG_ENTRY','OTHER'))");
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT iscr_other_note_ck CHECK (reason_code<>'OTHER' OR NULLIF(TRIM(note),'') IS NOT NULL)");
        DB::statement("ALTER TABLE {$r} ADD CONSTRAINT iscr_attribution_ck CHECK ((request_state='SUBMITTED' AND decided_by_user_id IS NULL AND decision_note IS NULL AND decided_at IS NULL AND consumed_at IS NULL) OR (request_state='APPROVED' AND decided_by_user_id IS NOT NULL AND decided_by_user_id<>requested_by_user_id AND decided_at IS NOT NULL AND consumed_at IS NULL) OR (request_state='DENIED' AND decided_by_user_id IS NOT NULL AND decided_by_user_id<>requested_by_user_id AND NULLIF(TRIM(decision_note),'') IS NOT NULL AND decided_at IS NOT NULL AND consumed_at IS NULL) OR (request_state='CONSUMED' AND decided_by_user_id IS NOT NULL AND decided_by_user_id<>requested_by_user_id AND decided_at IS NOT NULL AND consumed_at IS NOT NULL))");
        DB::statement("ALTER TABLE {$rv} ADD CONSTRAINT iscrv_state_ck CHECK (request_state IN ('SUBMITTED','APPROVED','DENIED','CONSUMED'))");
        DB::statement("ALTER TABLE {$rv} ADD CONSTRAINT iscrv_active_ck CHECK ((request_state IN ('SUBMITTED','APPROVED') AND active_slot='ACTIVE') OR (request_state IN ('DENIED','CONSUMED') AND active_slot IS NULL))");
        DB::statement("ALTER TABLE {$rv} ADD CONSTRAINT iscrv_version_ck CHECK (version>=1)");
        DB::statement("ALTER TABLE {$a} ADD CONSTRAINT isa_state_ck CHECK (addendum_state IN ('DRAFT','FINAL'))");
        DB::statement("ALTER TABLE {$a} ADD CONSTRAINT isa_def_ck CHECK (definition_version='INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1')");
        DB::statement("ALTER TABLE {$a} ADD CONSTRAINT isa_text_ck CHECK (COALESCE(NULLIF(TRIM(admission_reason),''),NULLIF(TRIM(significant_findings),''),NULLIF(TRIM(care_and_treatment_summary),''),NULLIF(TRIM(condition_at_discharge),''),NULLIF(TRIM(follow_up_plan),'')) IS NOT NULL)");
        DB::statement("ALTER TABLE {$a} ADD CONSTRAINT isa_version_ck CHECK (version>=1)");
        DB::statement("ALTER TABLE {$a} ADD CONSTRAINT isa_final_ck CHECK ((addendum_state='DRAFT' AND finalized_by_user_id IS NULL AND finalized_at IS NULL) OR (addendum_state='FINAL' AND finalized_by_user_id IS NOT NULL AND finalized_by_user_id=author_user_id AND finalized_at IS NOT NULL))");
        DB::statement("ALTER TABLE {$v} ADD CONSTRAINT isav_state_ck CHECK (addendum_state IN ('DRAFT','FINAL'))");
        DB::statement("ALTER TABLE {$v} ADD CONSTRAINT isav_def_ck CHECK (definition_version='INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1')");
        DB::statement("ALTER TABLE {$v} ADD CONSTRAINT isav_text_ck CHECK (COALESCE(NULLIF(TRIM(admission_reason),''),NULLIF(TRIM(significant_findings),''),NULLIF(TRIM(care_and_treatment_summary),''),NULLIF(TRIM(condition_at_discharge),''),NULLIF(TRIM(follow_up_plan),'')) IS NOT NULL)");
        DB::statement("ALTER TABLE {$v} ADD CONSTRAINT isav_version_ck CHECK (version>=1)");
        DB::statement("ALTER TABLE {$v} ADD CONSTRAINT isav_final_ck CHECK ((addendum_state='DRAFT' AND finalized_at IS NULL) OR (addendum_state='FINAL' AND finalized_at IS NOT NULL))");
        DB::statement("ALTER TABLE {$review} ADD CONSTRAINT isar_state_ck CHECK (review_state IN ('DRAFT','SIGNED_OFF'))");
        DB::statement("ALTER TABLE {$review} ADD CONSTRAINT isar_def_ck CHECK (definition_version='INPATIENT_SUMMARY_ADDENDUM_REVIEW_V1')");
        DB::statement("ALTER TABLE {$review} ADD CONSTRAINT isar_version_ck CHECK (version>=1)");
        DB::statement("ALTER TABLE {$review} ADD CONSTRAINT isar_blocker_ck CHECK (blocker_count>=0)");
        DB::statement("ALTER TABLE {$review} ADD CONSTRAINT isar_sign_ck CHECK ((review_state='DRAFT' AND signed_off_by_user_id IS NULL AND signed_off_at IS NULL) OR (review_state='SIGNED_OFF' AND signed_off_by_user_id IS NOT NULL AND signed_off_at IS NOT NULL))");
        DB::statement("ALTER TABLE {$receipt} ADD CONSTRAINT isaor_operation_ck CHECK (operation IN ('REQUEST_SUBMIT','REQUEST_DECIDE','ADDENDUM_DRAFT_SAVE','ADDENDUM_FINALIZE','REVIEW_SAVE','REVIEW_SIGNOFF'))");
        DB::statement("ALTER TABLE {$receipt} ADD CONSTRAINT isaor_result_ck CHECK (result_type IN ('REQUEST','ADDENDUM','REVIEW'))");
        DB::statement("ALTER TABLE {$receipt} ADD CONSTRAINT isaor_version_ck CHECK (result_version>=1)");
        DB::statement("ALTER TABLE {$receipt} ADD CONSTRAINT isaor_binding_ck CHECK ((operation IN ('REQUEST_SUBMIT','REQUEST_DECIDE') AND result_type='REQUEST' AND addendum_id IS NULL AND addendum_version_id IS NULL AND review_id IS NULL AND addendum_content_digest IS NULL) OR (operation IN ('ADDENDUM_DRAFT_SAVE','ADDENDUM_FINALIZE') AND result_type='ADDENDUM' AND addendum_id IS NOT NULL AND addendum_version_id IS NOT NULL AND review_id IS NULL AND addendum_content_digest IS NOT NULL) OR (operation IN ('REVIEW_SAVE','REVIEW_SIGNOFF') AND result_type='REVIEW' AND addendum_id IS NOT NULL AND addendum_version_id IS NOT NULL AND review_id IS NOT NULL AND addendum_content_digest IS NOT NULL))");
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
    private function historyTables(): array
    {
        return ['inpatient_summary_correction_request_versions', 'inpatient_summary_addendum_versions', 'inpatient_summary_addendum_reviews', 'inpatient_summary_addendum_review_items', 'inpatient_summary_addendum_operation_receipts'];
    }

    private function guards(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->createPostgresGuards(),
            'mysql' => $this->createMysqlGuards(),
            'sqlite' => null,
            default => throw new RuntimeException('Unsupported database driver for inpatient summary addendum.'),
        };
    }

    private function createPostgresGuards(): void
    {
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        $function = $this->qi($schema).'.'.$this->qi('protect_inpatient_summary_addendum_evidence');
        DB::statement("CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF TG_OP='DELETE' AND current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'summary addendum evidence is append-only' USING ERRCODE='55000'; END; \$f\$");
        foreach ($this->historyTables() as $table) {
            $qualified = $this->qi($schema).'.'.$this->qi($table);
            foreach (['update', 'delete'] as $operation) {
                DB::statement('CREATE TRIGGER '.$this->qi($this->trigger($table, $operation)).' BEFORE '.strtoupper($operation)." ON {$qualified} FOR EACH ROW EXECUTE FUNCTION {$function}()");
            }
            DB::statement('CREATE TRIGGER '.$this->qi($this->trigger($table, 'truncate'))." BEFORE TRUNCATE ON {$qualified} FOR EACH STATEMENT EXECUTE FUNCTION {$function}()");
        }

        $requestFunction = $this->qi($schema).'.'.$this->qi('protect_inpatient_summary_correction_request_head');
        DB::statement("CREATE OR REPLACE FUNCTION {$requestFunction}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF TG_OP='DELETE' THEN IF current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'summary correction request head cannot be deleted' USING ERRCODE='55000'; END IF; IF NEW.public_id<>OLD.public_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.inpatient_discharge_id<>OLD.inpatient_discharge_id OR NEW.inpatient_discharge_summary_id<>OLD.inpatient_discharge_summary_id OR NEW.inpatient_discharge_summary_version_id<>OLD.inpatient_discharge_summary_version_id OR NEW.inpatient_discharge_coding_source_id<>OLD.inpatient_discharge_coding_source_id OR NEW.inpatient_discharge_coding_source_version_id<>OLD.inpatient_discharge_coding_source_version_id OR NEW.inpatient_rm_coding_id<>OLD.inpatient_rm_coding_id OR NEW.inpatient_rm_coding_version_id<>OLD.inpatient_rm_coding_version_id OR NEW.baseline_review_id<>OLD.baseline_review_id OR NEW.requested_by_user_id<>OLD.requested_by_user_id OR NEW.reason_code<>OLD.reason_code OR NEW.note IS DISTINCT FROM OLD.note OR NEW.summary_content_digest<>OLD.summary_content_digest OR NEW.summary_provenance_digest<>OLD.summary_provenance_digest OR NEW.source_content_digest<>OLD.source_content_digest OR NEW.source_provenance_digest<>OLD.source_provenance_digest OR NEW.coding_content_digest<>OLD.coding_content_digest OR NEW.baseline_fingerprint<>OLD.baseline_fingerprint OR NEW.request_correlation_id IS DISTINCT FROM OLD.request_correlation_id OR NEW.created_at<>OLD.created_at OR NEW.version<>OLD.version+1 OR NOT ((OLD.request_state='SUBMITTED' AND NEW.request_state IN ('APPROVED','DENIED')) OR (OLD.request_state='APPROVED' AND NEW.request_state='CONSUMED' AND NEW.decided_by_user_id=OLD.decided_by_user_id AND NEW.decision_note IS NOT DISTINCT FROM OLD.decision_note AND NEW.decided_at=OLD.decided_at)) THEN RAISE EXCEPTION 'invalid summary correction request head transition' USING ERRCODE='55000'; END IF; RETURN NEW; END; \$f\$");
        $requestHead = $this->qi($schema).'.'.$this->qi('inpatient_summary_correction_requests');
        DB::statement('CREATE TRIGGER '.$this->qi('iscr_guard_update_trg')." BEFORE UPDATE ON {$requestHead} FOR EACH ROW EXECUTE FUNCTION {$requestFunction}()");
        DB::statement('CREATE TRIGGER '.$this->qi('iscr_guard_delete_trg')." BEFORE DELETE ON {$requestHead} FOR EACH ROW EXECUTE FUNCTION {$requestFunction}()");

        $addendumFunction = $this->qi($schema).'.'.$this->qi('protect_inpatient_summary_addendum_head');
        DB::statement("CREATE OR REPLACE FUNCTION {$addendumFunction}() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF TG_OP='DELETE' THEN IF current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'summary addendum head cannot be deleted' USING ERRCODE='55000'; END IF; IF OLD.addendum_state<>'DRAFT' OR NEW.version<>OLD.version+1 OR NEW.public_id<>OLD.public_id OR NEW.correction_request_id<>OLD.correction_request_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.author_user_id<>OLD.author_user_id OR NEW.definition_version<>OLD.definition_version OR NEW.created_at<>OLD.created_at OR NEW.addendum_state NOT IN ('DRAFT','FINAL') OR (NEW.addendum_state='DRAFT' AND (NEW.finalized_by_user_id IS NOT NULL OR NEW.finalized_at IS NOT NULL)) OR (NEW.addendum_state='FINAL' AND (NEW.current_content_digest<>OLD.current_content_digest OR NEW.admission_reason IS DISTINCT FROM OLD.admission_reason OR NEW.significant_findings IS DISTINCT FROM OLD.significant_findings OR NEW.care_and_treatment_summary IS DISTINCT FROM OLD.care_and_treatment_summary OR NEW.condition_at_discharge IS DISTINCT FROM OLD.condition_at_discharge OR NEW.follow_up_plan IS DISTINCT FROM OLD.follow_up_plan)) THEN RAISE EXCEPTION 'invalid summary addendum head transition' USING ERRCODE='55000'; END IF; RETURN NEW; END; \$f\$");
        $addendumHead = $this->qi($schema).'.'.$this->qi('inpatient_summary_addenda');
        DB::statement('CREATE TRIGGER '.$this->qi('isa_guard_update_trg')." BEFORE UPDATE ON {$addendumHead} FOR EACH ROW EXECUTE FUNCTION {$addendumFunction}()");
        DB::statement('CREATE TRIGGER '.$this->qi('isa_guard_delete_trg')." BEFORE DELETE ON {$addendumHead} FOR EACH ROW EXECUTE FUNCTION {$addendumFunction}()");
    }

    private function createMysqlGuards(): void
    {
        $g = DB::connection()->getQueryGrammar();
        foreach ($this->historyTables() as $table) {
            $q = $g->wrapTable(SchemaQualifier::table($table));
            DB::statement('CREATE TRIGGER '.$this->qmi($this->trigger($table, 'update'))." BEFORE UPDATE ON {$q} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='summary addendum evidence is append-only'");
            DB::statement('CREATE TRIGGER '.$this->qmi($this->trigger($table, 'delete'))." BEFORE DELETE ON {$q} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='summary addendum evidence is append-only'; END IF; END");
        }
        $r = $g->wrapTable(SchemaQualifier::table('inpatient_summary_correction_requests'));
        DB::statement('CREATE TRIGGER '.$this->qmi('iscr_guard_update_trg')." BEFORE UPDATE ON {$r} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.inpatient_discharge_id<>OLD.inpatient_discharge_id OR NEW.inpatient_discharge_summary_id<>OLD.inpatient_discharge_summary_id OR NEW.inpatient_discharge_summary_version_id<>OLD.inpatient_discharge_summary_version_id OR NEW.inpatient_discharge_coding_source_id<>OLD.inpatient_discharge_coding_source_id OR NEW.inpatient_discharge_coding_source_version_id<>OLD.inpatient_discharge_coding_source_version_id OR NEW.inpatient_rm_coding_id<>OLD.inpatient_rm_coding_id OR NEW.inpatient_rm_coding_version_id<>OLD.inpatient_rm_coding_version_id OR NEW.baseline_review_id<>OLD.baseline_review_id OR NEW.requested_by_user_id<>OLD.requested_by_user_id OR NEW.reason_code<>OLD.reason_code OR NOT (NEW.note <=> OLD.note) OR NEW.summary_content_digest<>OLD.summary_content_digest OR NEW.summary_provenance_digest<>OLD.summary_provenance_digest OR NEW.source_content_digest<>OLD.source_content_digest OR NEW.source_provenance_digest<>OLD.source_provenance_digest OR NEW.coding_content_digest<>OLD.coding_content_digest OR NEW.baseline_fingerprint<>OLD.baseline_fingerprint OR NOT (NEW.request_correlation_id <=> OLD.request_correlation_id) OR NEW.created_at<>OLD.created_at OR NEW.version<>OLD.version+1 OR NOT ((OLD.request_state='SUBMITTED' AND NEW.request_state IN ('APPROVED','DENIED')) OR (OLD.request_state='APPROVED' AND NEW.request_state='CONSUMED' AND NEW.decided_by_user_id=OLD.decided_by_user_id AND (NEW.decision_note <=> OLD.decision_note) AND NEW.decided_at=OLD.decided_at)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid summary correction request head transition'; END IF; END");
        DB::statement('CREATE TRIGGER '.$this->qmi('iscr_guard_delete_trg')." BEFORE DELETE ON {$r} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='summary correction request head cannot be deleted'; END IF; END");
        $a = $g->wrapTable(SchemaQualifier::table('inpatient_summary_addenda'));
        DB::statement('CREATE TRIGGER '.$this->qmi('isa_guard_update_trg')." BEFORE UPDATE ON {$a} FOR EACH ROW BEGIN IF OLD.addendum_state<>'DRAFT' OR NEW.version<>OLD.version+1 OR NEW.public_id<>OLD.public_id OR NEW.correction_request_id<>OLD.correction_request_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.author_user_id<>OLD.author_user_id OR NEW.definition_version<>OLD.definition_version OR NEW.created_at<>OLD.created_at OR NEW.addendum_state NOT IN ('DRAFT','FINAL') OR (NEW.addendum_state='DRAFT' AND (NEW.finalized_by_user_id IS NOT NULL OR NEW.finalized_at IS NOT NULL)) OR (NEW.addendum_state='FINAL' AND (NEW.current_content_digest<>OLD.current_content_digest OR NOT (NEW.admission_reason <=> OLD.admission_reason) OR NOT (NEW.significant_findings <=> OLD.significant_findings) OR NOT (NEW.care_and_treatment_summary <=> OLD.care_and_treatment_summary) OR NOT (NEW.condition_at_discharge <=> OLD.condition_at_discharge) OR NOT (NEW.follow_up_plan <=> OLD.follow_up_plan))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid summary addendum head transition'; END IF; END");
        DB::statement('CREATE TRIGGER '.$this->qmi('isa_guard_delete_trg')." BEFORE DELETE ON {$a} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='summary addendum head cannot be deleted'; END IF; END");
    }

    private function dropGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $schema = SchemaQualifier::primarySchema() ?? 'public';
            foreach ($this->historyTables() as $table) {
                $qualified = $this->qi($schema).'.'.$this->qi($table);
                foreach (['update', 'delete', 'truncate'] as $operation) {
                    DB::statement('DROP TRIGGER IF EXISTS '.$this->qi($this->trigger($table, $operation))." ON {$qualified}");
                }
            }
            foreach ([['inpatient_summary_correction_requests', 'iscr_guard_update_trg'], ['inpatient_summary_correction_requests', 'iscr_guard_delete_trg'], ['inpatient_summary_addenda', 'isa_guard_update_trg'], ['inpatient_summary_addenda', 'isa_guard_delete_trg']] as [$table, $trigger]) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qi($trigger).' ON '.$this->qi($schema).'.'.$this->qi($table));
            }
            DB::statement('DROP FUNCTION IF EXISTS '.$this->qi($schema).'.'.$this->qi('protect_inpatient_summary_correction_request_head').'()');
            DB::statement('DROP FUNCTION IF EXISTS '.$this->qi($schema).'.'.$this->qi('protect_inpatient_summary_addendum_head').'()');
            DB::statement('DROP FUNCTION IF EXISTS '.$this->qi($schema).'.'.$this->qi('protect_inpatient_summary_addendum_evidence').'()');
        } elseif ($driver === 'mysql') {
            foreach ($this->historyTables() as $table) {
                foreach (['update', 'delete'] as $operation) {
                    DB::statement('DROP TRIGGER IF EXISTS '.$this->qmi($this->trigger($table, $operation)));
                }
            }
            foreach (['iscr_guard_update_trg', 'iscr_guard_delete_trg', 'isa_guard_update_trg', 'isa_guard_delete_trg'] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->qmi($trigger));
            }
        }
    }

    private function trigger(string $table, string $operation): string
    {
        $prefix = match ($table) {
            'inpatient_summary_correction_request_versions' => 'iscrv',
            'inpatient_summary_addendum_versions' => 'isav',
            'inpatient_summary_addendum_reviews' => 'isar',
            'inpatient_summary_addendum_review_items' => 'isari',
            default => 'isaor',
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
