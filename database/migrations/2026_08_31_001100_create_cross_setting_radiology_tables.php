<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Radiology\RadiologySchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'radiology_operation_receipts', 'radiology_report_acknowledgements', 'radiology_report_versions',
        'radiology_performances', 'radiology_order_cancellations', 'radiology_orders',
        'radiology_examination_master_versions', 'radiology_examination_masters', 'radiology_master_code_reservations',
    ];

    public function up(): void
    {
        RadiologySchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Radiology teaching migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('radiology_master_code_reservations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'rmcr_actor_fk')->restrictOnDelete();
            $t->string('normalized_code', 64)->unique();
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('radiology_examination_masters'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->string('examination_code', 64)->unique();
            $t->string('display_name', 160);
            $t->text('preparation_instruction')->nullable();
            $t->string('state', 16);
            $t->unsignedInteger('version');
            $t->timestamps();
            $t->index(['state', 'display_name'], 'radiology_master_state_name_idx');
        });
        Schema::create(SchemaQualifier::table('radiology_examination_master_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('radiology_examination_master_id')->constrained(SchemaQualifier::table('radiology_examination_masters'), indexName: 'remv_master_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'remv_actor_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('display_name', 160);
            $t->text('preparation_instruction')->nullable();
            $t->string('state', 16);
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['radiology_examination_master_id', 'version'], 'remv_master_version_uq');
        });
        Schema::create(SchemaQualifier::table('radiology_orders'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'ro_encounter_fk')->restrictOnDelete();
            $t->foreignId('master_id')->constrained(SchemaQualifier::table('radiology_examination_masters'), indexName: 'ro_master_fk')->restrictOnDelete();
            $t->foreignId('ordered_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ro_orderer_fk')->restrictOnDelete();
            $t->unsignedInteger('master_version');
            $t->string('master_version_public_id', 26);
            $t->string('master_content_digest', 64);
            $t->string('master_code', 64);
            $t->string('master_display_name', 160);
            $t->text('master_preparation_instruction')->nullable();
            $t->string('care_setting', 16);
            $t->string('encounter_status_snapshot', 24);
            $t->string('encounter_number_snapshot', 26);
            $t->string('care_location_label_snapshot', 160);
            $t->text('clinical_indication');
            $t->string('status', 24);
            $t->unsignedInteger('version');
            $t->timestamp('ordered_at');
            $t->timestamps();
            $t->index(['encounter_id', 'status'], 'ro_encounter_status_idx');
        });
        Schema::create(SchemaQualifier::table('radiology_order_cancellations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('radiology_order_id')->unique()->constrained(SchemaQualifier::table('radiology_orders'), indexName: 'roc_order_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'roc_actor_fk')->restrictOnDelete();
            $t->string('reason_code', 64);
            $t->text('note')->nullable();
            $t->timestamp('cancelled_at');
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('radiology_performances'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('radiology_order_id')->unique()->constrained(SchemaQualifier::table('radiology_orders'), indexName: 'rp_order_fk')->restrictOnDelete();
            $t->foreignId('performed_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'rp_actor_fk')->restrictOnDelete();
            $t->timestamp('performed_at');
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('radiology_report_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('radiology_order_id')->constrained(SchemaQualifier::table('radiology_orders'), indexName: 'rrv_order_fk')->restrictOnDelete();
            $t->foreignId('author_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'rrv_author_fk')->restrictOnDelete();
            $t->foreignId('base_verified_version_id')->nullable()->constrained(SchemaQualifier::table('radiology_report_versions'), indexName: 'rrv_base_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('state', 24);
            $t->text('findings')->nullable();
            $t->text('impression')->nullable();
            $t->text('recommendation')->nullable();
            $t->text('amendment_reason')->nullable();
            $t->text('amended_statement')->nullable();
            $t->string('base_verified_digest', 64)->nullable();
            $t->string('prior_amendment_digest', 64)->nullable();
            $t->string('content_digest', 64);
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['radiology_order_id', 'version'], 'rrv_order_version_uq');
            $t->index(['radiology_order_id', 'state'], 'rrv_order_state_idx');
        });
        Schema::create(SchemaQualifier::table('radiology_report_acknowledgements'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('radiology_report_version_id')->unique('rra_report_version_uq')->constrained(SchemaQualifier::table('radiology_report_versions'), indexName: 'rra_report_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'rra_actor_fk')->restrictOnDelete();
            $t->string('report_fingerprint', 64);
            $t->timestamp('acknowledged_at');
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('radiology_operation_receipts'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ror_actor_fk')->restrictOnDelete();
            $t->string('operation', 64);
            $t->string('idempotency_key', 255);
            $t->string('payload_digest', 64);
            $t->string('result_type', 24);
            $t->string('result_public_id', 26);
            $t->unsignedInteger('result_version');
            $t->string('result_state', 24);
            $t->string('result_digest', 64);
            $t->string('request_correlation_id', 26)->nullable();
            $t->timestamp('completed_at');
            $t->timestamps();
            $t->unique(['actor_user_id', 'operation', 'idempotency_key'], 'ror_actor_operation_key_uq');
        });
        $this->addDatabaseGuards();
    }

    public function down(): void
    {
        RadiologySchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'radiology.workflow.mutate')->exists()) {
            throw new RuntimeException('Refusing to discard radiology tables while correlated audit evidence exists.');
        }
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated radiology evidence.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS radiology_order_transition_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS radiology_master_transition_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS radiology_truncate_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS radiology_append_only_guard()');
        }
    }

    private function addDatabaseGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            return;
        }
        $masters = SchemaQualifier::table('radiology_examination_masters');
        $orders = SchemaQualifier::table('radiology_orders');
        $reports = SchemaQualifier::table('radiology_report_versions');
        $acks = SchemaQualifier::table('radiology_report_acknowledgements');
        $masterVersions = SchemaQualifier::table('radiology_examination_master_versions');
        $receipts = SchemaQualifier::table('radiology_operation_receipts');
        DB::statement("ALTER TABLE {$masters} ADD CONSTRAINT rem_state_ck CHECK (state IN ('ACTIVE','RETIRED'))");
        DB::statement("ALTER TABLE {$orders} ADD CONSTRAINT ro_state_ck CHECK (status IN ('ORDERED','PERFORMED','REPORTED_VERIFIED','CANCELLED'))");
        DB::statement("ALTER TABLE {$reports} ADD CONSTRAINT rrv_state_ck CHECK (state IN ('DRAFT','VERIFIED','AMENDED_VERIFIED'))");
        DB::statement("ALTER TABLE {$orders} ADD CONSTRAINT ro_snapshot_ck CHECK (care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND encounter_status_snapshot='IN_EXAMINATION' AND CHAR_LENGTH(master_content_digest)=64)");
        DB::statement("ALTER TABLE {$masterVersions} ADD CONSTRAINT remv_digest_ck CHECK (CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$acks} ADD CONSTRAINT rra_fingerprint_ck CHECK (CHAR_LENGTH(report_fingerprint)=64)");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT ror_result_ck CHECK (result_version>=1 AND CHAR_LENGTH(result_digest)=64 AND ((operation IN ('RADIOLOGY_MASTER_CREATE','RADIOLOGY_MASTER_REVISE') AND result_type='MASTER' AND result_state IN ('ACTIVE','RETIRED')) OR (operation='RADIOLOGY_ORDER_CREATE' AND result_type='ORDER' AND result_state='ORDERED') OR (operation='RADIOLOGY_ORDER_CANCEL' AND result_type='CANCELLATION' AND result_state='CANCELLED') OR (operation='RADIOLOGY_ORDER_PERFORM' AND result_type='PERFORMANCE' AND result_state='PERFORMED') OR (operation IN ('RADIOLOGY_REPORT_DRAFT_SAVE','RADIOLOGY_REPORT_VERIFY','RADIOLOGY_REPORT_AMEND_VERIFIED') AND result_type='REPORT_VERSION' AND result_state IN ('DRAFT','VERIFIED','AMENDED_VERIFIED')) OR (operation='RADIOLOGY_REPORT_ACKNOWLEDGE' AND result_type='ACKNOWLEDGEMENT' AND result_state='ACKNOWLEDGED')))");
        DB::statement("ALTER TABLE {$reports} ADD CONSTRAINT rrv_coherence_ck CHECK ((state='DRAFT' AND verified_at IS NULL AND amendment_reason IS NULL AND amended_statement IS NULL AND base_verified_version_id IS NULL AND base_verified_digest IS NULL AND prior_amendment_digest IS NULL) OR (state='VERIFIED' AND findings IS NOT NULL AND impression IS NOT NULL AND verified_at IS NOT NULL AND amendment_reason IS NULL AND amended_statement IS NULL AND base_verified_version_id IS NULL AND base_verified_digest IS NULL AND prior_amendment_digest IS NULL) OR (state='AMENDED_VERIFIED' AND findings IS NOT NULL AND impression IS NOT NULL AND verified_at IS NOT NULL AND amendment_reason IS NOT NULL AND amended_statement IS NOT NULL AND base_verified_version_id IS NOT NULL AND CHAR_LENGTH(base_verified_digest)=64 AND (prior_amendment_digest IS NULL OR CHAR_LENGTH(prior_amendment_digest)=64)))");
        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION radiology_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF current_setting('simrs.synthetic_reset', true) = '1' AND TG_OP = 'DELETE' THEN RETURN OLD; END IF; RAISE EXCEPTION 'radiology evidence is append-only'; END $$");
            DB::unprepared("CREATE OR REPLACE FUNCTION radiology_truncate_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'radiology evidence cannot be truncated'; END $$");
            foreach (['radiology_examination_master_versions', 'radiology_order_cancellations', 'radiology_performances', 'radiology_report_versions', 'radiology_report_acknowledgements', 'radiology_operation_receipts', 'radiology_master_code_reservations'] as $table) {
                $q = SchemaQualifier::table($table);
                DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$q} FOR EACH ROW EXECUTE FUNCTION radiology_append_only_guard()");
                DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$q} FOR EACH STATEMENT EXECUTE FUNCTION radiology_truncate_guard()");
            }
            DB::unprepared("CREATE OR REPLACE FUNCTION radiology_master_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.public_id<>OLD.public_id OR NEW.created_at<>OLD.created_at OR NEW.examination_code <> OLD.examination_code OR NEW.version <> OLD.version + 1 OR OLD.state = 'RETIRED' OR NOT (OLD.state = 'ACTIVE' AND NEW.state IN ('ACTIVE','RETIRED')) THEN RAISE EXCEPTION 'invalid radiology master transition'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER radiology_master_transition BEFORE UPDATE ON {$masters} FOR EACH ROW EXECUTE FUNCTION radiology_master_transition_guard()");
            DB::statement("CREATE TRIGGER radiology_master_no_delete BEFORE DELETE ON {$masters} FOR EACH ROW EXECUTE FUNCTION radiology_append_only_guard()");
            DB::statement("CREATE TRIGGER radiology_master_no_truncate BEFORE TRUNCATE ON {$masters} FOR EACH STATEMENT EXECUTE FUNCTION radiology_truncate_guard()");
            DB::unprepared("CREATE OR REPLACE FUNCTION radiology_order_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN IF current_setting('simrs.synthetic_reset', true) = '1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'radiology order cannot be deleted'; END IF; IF NEW.public_id<>OLD.public_id OR NEW.version <> OLD.version + 1 OR NEW.encounter_id<>OLD.encounter_id OR NEW.master_id<>OLD.master_id OR NEW.ordered_by_user_id<>OLD.ordered_by_user_id OR NEW.master_version<>OLD.master_version OR NEW.master_version_public_id<>OLD.master_version_public_id OR NEW.master_content_digest<>OLD.master_content_digest OR NEW.master_code<>OLD.master_code OR NEW.master_display_name<>OLD.master_display_name OR NEW.master_preparation_instruction IS DISTINCT FROM OLD.master_preparation_instruction OR NEW.care_setting<>OLD.care_setting OR NEW.encounter_status_snapshot<>OLD.encounter_status_snapshot OR NEW.encounter_number_snapshot<>OLD.encounter_number_snapshot OR NEW.care_location_label_snapshot<>OLD.care_location_label_snapshot OR NEW.clinical_indication<>OLD.clinical_indication OR NEW.ordered_at<>OLD.ordered_at OR NEW.created_at<>OLD.created_at OR NOT ((OLD.status='ORDERED' AND NEW.status IN ('PERFORMED','CANCELLED')) OR (OLD.status='PERFORMED' AND NEW.status='REPORTED_VERIFIED')) THEN RAISE EXCEPTION 'invalid radiology order transition'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER radiology_order_transition BEFORE UPDATE OR DELETE ON {$orders} FOR EACH ROW EXECUTE FUNCTION radiology_order_transition_guard()");
            DB::statement("CREATE TRIGGER radiology_order_no_truncate BEFORE TRUNCATE ON {$orders} FOR EACH STATEMENT EXECUTE FUNCTION radiology_truncate_guard()");
        } else {
            foreach (['radiology_examination_master_versions', 'radiology_order_cancellations', 'radiology_performances', 'radiology_report_versions', 'radiology_report_acknowledgements', 'radiology_operation_receipts', 'radiology_master_code_reservations'] as $table) {
                $q = SchemaQualifier::table($table);
                DB::statement("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$q} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='radiology evidence is append-only'");
                DB::statement("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$q} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='radiology evidence is append-only'; END IF; END");
            }
            DB::statement("CREATE TRIGGER radiology_master_transition BEFORE UPDATE ON {$masters} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.created_at<>OLD.created_at OR NEW.examination_code <> OLD.examination_code OR NEW.version <> OLD.version + 1 OR OLD.state='RETIRED' OR NOT(OLD.state='ACTIVE' AND NEW.state IN ('ACTIVE','RETIRED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid radiology master transition'; END IF; END");
            DB::statement("CREATE TRIGGER radiology_master_no_delete BEFORE DELETE ON {$masters} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='radiology master cannot be deleted'");
            DB::statement("CREATE TRIGGER radiology_order_transition BEFORE UPDATE ON {$orders} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.version <> OLD.version + 1 OR NEW.encounter_id<>OLD.encounter_id OR NEW.master_id<>OLD.master_id OR NEW.ordered_by_user_id<>OLD.ordered_by_user_id OR NEW.master_version<>OLD.master_version OR NEW.master_version_public_id<>OLD.master_version_public_id OR NEW.master_content_digest<>OLD.master_content_digest OR NEW.master_code<>OLD.master_code OR NEW.master_display_name<>OLD.master_display_name OR NOT(NEW.master_preparation_instruction <=> OLD.master_preparation_instruction) OR NEW.care_setting<>OLD.care_setting OR NEW.encounter_status_snapshot<>OLD.encounter_status_snapshot OR NEW.encounter_number_snapshot<>OLD.encounter_number_snapshot OR NEW.care_location_label_snapshot<>OLD.care_location_label_snapshot OR NEW.clinical_indication<>OLD.clinical_indication OR NEW.ordered_at<>OLD.ordered_at OR NEW.created_at<>OLD.created_at OR NOT((OLD.status='ORDERED' AND NEW.status IN ('PERFORMED','CANCELLED')) OR (OLD.status='PERFORMED' AND NEW.status='REPORTED_VERIFIED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid radiology order transition'; END IF; END");
            DB::statement("CREATE TRIGGER radiology_order_no_delete BEFORE DELETE ON {$orders} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='radiology order cannot be deleted'; END IF; END");
        }
    }
};
