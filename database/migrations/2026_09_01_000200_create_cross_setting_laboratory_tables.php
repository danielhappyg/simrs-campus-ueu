<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Laboratory\LaboratorySchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'laboratory_operation_receipts', 'laboratory_result_acknowledgements',
        'laboratory_critical_communications', 'laboratory_result_versions',
        'laboratory_specimen_events', 'laboratory_specimen_attempts',
        'laboratory_order_cancellations', 'laboratory_orders',
        'laboratory_examination_master_versions', 'laboratory_examination_masters',
        'laboratory_master_code_reservations',
    ];

    public function up(): void
    {
        LaboratorySchemaMutationScope::run(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Laboratory teaching migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('laboratory_master_code_reservations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lmcr_actor_fk')->restrictOnDelete();
            $t->string('normalized_code', 64)->unique();
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('laboratory_examination_masters'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->string('examination_code', 64)->unique();
            $t->string('display_name', 160);
            $t->string('specimen_type', 120);
            $t->text('collection_instruction')->nullable();
            $t->json('components');
            $t->string('state', 16);
            $t->unsignedInteger('version');
            $t->timestamps();
            $t->index(['state', 'display_name'], 'lem_state_name_idx');
        });
        Schema::create(SchemaQualifier::table('laboratory_examination_master_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('laboratory_examination_master_id')->constrained(SchemaQualifier::table('laboratory_examination_masters'), indexName: 'lemv_master_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lemv_actor_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('display_name', 160);
            $t->string('specimen_type', 120);
            $t->text('collection_instruction')->nullable();
            $t->json('components');
            $t->string('state', 16);
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
            $t->unique(['laboratory_examination_master_id', 'version'], 'lemv_master_version_uq');
        });
        Schema::create(SchemaQualifier::table('laboratory_orders'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'lo_encounter_fk')->restrictOnDelete();
            $t->foreignId('master_id')->constrained(SchemaQualifier::table('laboratory_examination_masters'), indexName: 'lo_master_fk')->restrictOnDelete();
            $t->foreignId('ordered_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lo_orderer_fk')->restrictOnDelete();
            $t->unsignedInteger('master_version');
            $t->string('master_version_public_id', 26);
            $t->string('master_content_digest', 64);
            $t->string('master_code', 64);
            $t->string('master_display_name', 160);
            $t->string('specimen_type_snapshot', 120);
            $t->text('collection_instruction_snapshot')->nullable();
            $t->json('components_snapshot');
            $t->string('care_setting', 16);
            $t->string('encounter_status_snapshot', 24);
            $t->string('encounter_number_snapshot', 26);
            $t->string('care_location_label_snapshot', 160);
            $t->string('priority', 16);
            $t->text('clinical_question');
            $t->string('status', 32);
            $t->unsignedInteger('version');
            $t->timestamp('ordered_at');
            $t->timestamps();
            $t->index(['encounter_id', 'status'], 'lo_encounter_status_idx');
        });
        Schema::create(SchemaQualifier::table('laboratory_order_cancellations'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('laboratory_order_id')->unique('loc_order_uq')->constrained(SchemaQualifier::table('laboratory_orders'), indexName: 'loc_order_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'loc_actor_fk')->restrictOnDelete();
            $t->string('reason_code', 64);
            $t->text('note')->nullable();
            $t->timestamp('cancelled_at');
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('laboratory_specimen_attempts'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('laboratory_order_id')->constrained(SchemaQualifier::table('laboratory_orders'), indexName: 'lsa_order_fk')->restrictOnDelete();
            $t->unsignedInteger('attempt_number');
            $t->string('label_identifier', 40)->unique();
            $t->foreignId('collector_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lsa_collector_fk')->restrictOnDelete();
            $t->timestamp('collected_at');
            $t->text('collection_note')->nullable();
            $t->string('state', 16);
            $t->unsignedInteger('version');
            $t->timestamp('created_at');
            $t->unique(['laboratory_order_id', 'attempt_number'], 'lsa_order_attempt_uq');
        });
        Schema::create(SchemaQualifier::table('laboratory_specimen_events'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('laboratory_specimen_attempt_id')->constrained(SchemaQualifier::table('laboratory_specimen_attempts'), indexName: 'lse_attempt_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lse_actor_fk')->restrictOnDelete();
            $t->string('event_type', 16);
            $t->string('reason_code', 64)->nullable();
            $t->text('note')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamp('created_at');
            $t->unique(['laboratory_specimen_attempt_id', 'event_type'], 'lse_attempt_type_uq');
        });
        Schema::create(SchemaQualifier::table('laboratory_result_versions'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('laboratory_order_id')->constrained(SchemaQualifier::table('laboratory_orders'), indexName: 'lrv_order_fk')->restrictOnDelete();
            $t->foreignId('laboratory_specimen_attempt_id')->constrained(SchemaQualifier::table('laboratory_specimen_attempts'), indexName: 'lrv_specimen_fk')->restrictOnDelete();
            $t->foreignId('author_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lrv_author_fk')->restrictOnDelete();
            $t->foreignId('base_verified_version_id')->nullable()->constrained(SchemaQualifier::table('laboratory_result_versions'), indexName: 'lrv_base_fk')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('state', 24);
            $t->json('results');
            $t->string('correction_reason', 64)->nullable();
            $t->string('base_verified_digest', 64)->nullable();
            $t->string('prior_amendment_digest', 64)->nullable();
            $t->string('content_digest', 64);
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['laboratory_order_id', 'version'], 'lrv_order_version_uq');
            $t->index(['laboratory_order_id', 'state'], 'lrv_order_state_idx');
        });
        Schema::create(SchemaQualifier::table('laboratory_critical_communications'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('laboratory_result_version_id')->unique('lcc_result_uq')->constrained(SchemaQualifier::table('laboratory_result_versions'), indexName: 'lcc_result_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lcc_actor_fk')->restrictOnDelete();
            $t->foreignId('recipient_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lcc_recipient_fk')->restrictOnDelete();
            $t->string('communication_method', 32);
            $t->string('outcome', 16);
            $t->text('note')->nullable();
            $t->timestamp('communicated_at');
            $t->string('content_digest', 64);
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('laboratory_result_acknowledgements'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('laboratory_result_version_id')->unique('lra_result_uq')->constrained(SchemaQualifier::table('laboratory_result_versions'), indexName: 'lra_result_fk')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lra_actor_fk')->restrictOnDelete();
            $t->string('result_fingerprint', 64);
            $t->timestamp('acknowledged_at');
            $t->timestamp('created_at');
        });
        Schema::create(SchemaQualifier::table('laboratory_operation_receipts'), function (Blueprint $t): void {
            $t->id();
            $t->string('public_id', 26)->unique();
            $t->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'lor_actor_fk')->restrictOnDelete();
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
            $t->unique(['actor_user_id', 'operation', 'idempotency_key'], 'lor_actor_operation_key_uq');
        });

        $this->addDatabaseGuards();
    }

    public function down(): void
    {
        LaboratorySchemaMutationScope::run(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'laboratory.workflow.mutate')->exists()) {
            throw new RuntimeException('Refusing to discard laboratory tables while correlated audit evidence exists.');
        }
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated laboratory evidence.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['laboratory_order_transition_guard', 'laboratory_specimen_transition_guard', 'laboratory_master_transition_guard', 'laboratory_truncate_guard', 'laboratory_append_only_guard'] as $function) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
            }
        }
    }

    private function addDatabaseGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            return;
        }
        $masters = SchemaQualifier::table('laboratory_examination_masters');
        $orders = SchemaQualifier::table('laboratory_orders');
        $attempts = SchemaQualifier::table('laboratory_specimen_attempts');
        $versions = SchemaQualifier::table('laboratory_examination_master_versions');
        $results = SchemaQualifier::table('laboratory_result_versions');
        $communications = SchemaQualifier::table('laboratory_critical_communications');
        $acks = SchemaQualifier::table('laboratory_result_acknowledgements');
        $receipts = SchemaQualifier::table('laboratory_operation_receipts');

        DB::statement("ALTER TABLE {$masters} ADD CONSTRAINT lem_state_ck CHECK (state IN ('ACTIVE','RETIRED'))");
        DB::statement("ALTER TABLE {$orders} ADD CONSTRAINT lo_state_ck CHECK (status IN ('ORDERED','SPECIMEN_ACCEPTED','REPORTED_VERIFIED','CANCELLED'))");
        DB::statement("ALTER TABLE {$orders} ADD CONSTRAINT lo_snapshot_ck CHECK (care_setting IN ('OUTPATIENT','EMERGENCY','INPATIENT') AND encounter_status_snapshot='IN_EXAMINATION' AND priority IN ('ROUTINE','URGENT') AND CHAR_LENGTH(master_content_digest)=64)");
        DB::statement("ALTER TABLE {$attempts} ADD CONSTRAINT lsa_state_ck CHECK (state IN ('COLLECTED','RECEIVED','ACCEPTED','REJECTED') AND version>=1)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('laboratory_specimen_events')." ADD CONSTRAINT lse_event_ck CHECK (event_type IN ('RECEIVED','ACCEPTED','REJECTED') AND ((event_type='REJECTED' AND reason_code IS NOT NULL) OR (event_type<>'REJECTED' AND reason_code IS NULL)))");
        DB::statement("ALTER TABLE {$results} ADD CONSTRAINT lrv_state_ck CHECK (state IN ('DRAFT','VERIFIED','AMENDED_VERIFIED'))");
        DB::statement("ALTER TABLE {$results} ADD CONSTRAINT lrv_coherence_ck CHECK ((state='DRAFT' AND verified_at IS NULL AND correction_reason IS NULL AND base_verified_version_id IS NULL AND base_verified_digest IS NULL AND prior_amendment_digest IS NULL) OR (state='VERIFIED' AND verified_at IS NOT NULL AND correction_reason IS NULL AND base_verified_version_id IS NULL AND base_verified_digest IS NULL AND prior_amendment_digest IS NULL) OR (state='AMENDED_VERIFIED' AND verified_at IS NOT NULL AND correction_reason IS NOT NULL AND base_verified_version_id IS NOT NULL AND CHAR_LENGTH(base_verified_digest)=64 AND (prior_amendment_digest IS NULL OR CHAR_LENGTH(prior_amendment_digest)=64)))");
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT lemv_digest_ck CHECK (CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$communications} ADD CONSTRAINT lcc_values_ck CHECK (outcome IN ('COMMUNICATED','ESCALATED') AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$acks} ADD CONSTRAINT lra_fingerprint_ck CHECK (CHAR_LENGTH(result_fingerprint)=64)");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT lor_result_ck CHECK (result_version>=1 AND CHAR_LENGTH(result_digest)=64 AND ((operation IN ('LABORATORY_MASTER_CREATE','LABORATORY_MASTER_REVISE') AND result_type='MASTER' AND result_state IN ('ACTIVE','RETIRED')) OR (operation='LABORATORY_ORDER_CREATE' AND result_type='ORDER' AND result_state='ORDERED') OR (operation='LABORATORY_ORDER_CANCEL' AND result_type='CANCELLATION' AND result_state='CANCELLED') OR (operation='LABORATORY_SPECIMEN_COLLECT' AND result_type='SPECIMEN_ATTEMPT' AND result_state='COLLECTED') OR (operation IN ('LABORATORY_SPECIMEN_RECEIVE','LABORATORY_SPECIMEN_ACCEPT','LABORATORY_SPECIMEN_REJECT') AND result_type='SPECIMEN_EVENT' AND result_state IN ('RECEIVED','ACCEPTED','REJECTED')) OR (operation IN ('LABORATORY_RESULT_DRAFT_SAVE','LABORATORY_RESULT_VERIFY','LABORATORY_RESULT_AMEND_VERIFIED') AND result_type='RESULT_VERSION' AND result_state IN ('DRAFT','VERIFIED','AMENDED_VERIFIED')) OR (operation='LABORATORY_RESULT_ACKNOWLEDGE' AND result_type='ACKNOWLEDGEMENT' AND result_state='ACKNOWLEDGED')))");

        $appendOnly = ['laboratory_master_code_reservations', 'laboratory_examination_master_versions', 'laboratory_order_cancellations', 'laboratory_specimen_events', 'laboratory_result_versions', 'laboratory_critical_communications', 'laboratory_result_acknowledgements', 'laboratory_operation_receipts'];
        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION laboratory_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF current_setting('simrs.synthetic_reset', true)='1' AND TG_OP='DELETE' THEN RETURN OLD; END IF; RAISE EXCEPTION 'laboratory evidence is append-only'; END $$");
            DB::unprepared("CREATE OR REPLACE FUNCTION laboratory_truncate_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'laboratory evidence cannot be truncated'; END $$");
            foreach ($appendOnly as $table) {
                $q = SchemaQualifier::table($table);
                DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$q} FOR EACH ROW EXECUTE FUNCTION laboratory_append_only_guard()");
                DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$q} FOR EACH STATEMENT EXECUTE FUNCTION laboratory_truncate_guard()");
            }
            DB::unprepared("CREATE OR REPLACE FUNCTION laboratory_master_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.public_id<>OLD.public_id OR NEW.created_at<>OLD.created_at OR NEW.examination_code<>OLD.examination_code OR NEW.version<>OLD.version+1 OR OLD.state='RETIRED' OR NOT(OLD.state='ACTIVE' AND NEW.state IN ('ACTIVE','RETIRED')) THEN RAISE EXCEPTION 'invalid laboratory master transition'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER laboratory_master_transition BEFORE UPDATE ON {$masters} FOR EACH ROW EXECUTE FUNCTION laboratory_master_transition_guard()");
            DB::statement("CREATE TRIGGER laboratory_master_no_delete BEFORE DELETE ON {$masters} FOR EACH ROW EXECUTE FUNCTION laboratory_append_only_guard()");
            DB::statement("CREATE TRIGGER laboratory_master_no_truncate BEFORE TRUNCATE ON {$masters} FOR EACH STATEMENT EXECUTE FUNCTION laboratory_truncate_guard()");
            DB::unprepared("CREATE OR REPLACE FUNCTION laboratory_order_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN IF current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'laboratory order cannot be deleted'; END IF; IF NEW.public_id<>OLD.public_id OR NEW.version<>OLD.version+1 OR NEW.encounter_id<>OLD.encounter_id OR NEW.master_id<>OLD.master_id OR NEW.ordered_by_user_id<>OLD.ordered_by_user_id OR NEW.master_version<>OLD.master_version OR NEW.master_version_public_id<>OLD.master_version_public_id OR NEW.master_content_digest<>OLD.master_content_digest OR NEW.master_code<>OLD.master_code OR NEW.master_display_name<>OLD.master_display_name OR NEW.specimen_type_snapshot<>OLD.specimen_type_snapshot OR NEW.collection_instruction_snapshot IS DISTINCT FROM OLD.collection_instruction_snapshot OR NEW.components_snapshot::text<>OLD.components_snapshot::text OR NEW.care_setting<>OLD.care_setting OR NEW.encounter_status_snapshot<>OLD.encounter_status_snapshot OR NEW.encounter_number_snapshot<>OLD.encounter_number_snapshot OR NEW.care_location_label_snapshot<>OLD.care_location_label_snapshot OR NEW.priority<>OLD.priority OR NEW.clinical_question<>OLD.clinical_question OR NEW.ordered_at<>OLD.ordered_at OR NEW.created_at<>OLD.created_at OR NOT((OLD.status='ORDERED' AND NEW.status IN ('SPECIMEN_ACCEPTED','CANCELLED')) OR (OLD.status='SPECIMEN_ACCEPTED' AND NEW.status='REPORTED_VERIFIED')) THEN RAISE EXCEPTION 'invalid laboratory order transition'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER laboratory_order_transition BEFORE UPDATE OR DELETE ON {$orders} FOR EACH ROW EXECUTE FUNCTION laboratory_order_transition_guard()");
            DB::statement("CREATE TRIGGER laboratory_order_no_truncate BEFORE TRUNCATE ON {$orders} FOR EACH STATEMENT EXECUTE FUNCTION laboratory_truncate_guard()");
            DB::unprepared("CREATE OR REPLACE FUNCTION laboratory_specimen_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN IF current_setting('simrs.synthetic_reset',true)='1' THEN RETURN OLD; END IF; RAISE EXCEPTION 'laboratory specimen cannot be deleted'; END IF; IF NEW.public_id<>OLD.public_id OR NEW.laboratory_order_id<>OLD.laboratory_order_id OR NEW.attempt_number<>OLD.attempt_number OR NEW.label_identifier<>OLD.label_identifier OR NEW.collector_user_id<>OLD.collector_user_id OR NEW.collected_at<>OLD.collected_at OR NEW.collection_note IS DISTINCT FROM OLD.collection_note OR NEW.created_at<>OLD.created_at OR NEW.version<>OLD.version+1 OR NOT((OLD.state='COLLECTED' AND NEW.state='RECEIVED') OR (OLD.state='RECEIVED' AND NEW.state IN ('ACCEPTED','REJECTED'))) THEN RAISE EXCEPTION 'invalid laboratory specimen transition'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER laboratory_specimen_transition BEFORE UPDATE OR DELETE ON {$attempts} FOR EACH ROW EXECUTE FUNCTION laboratory_specimen_transition_guard()");
            DB::statement("CREATE TRIGGER laboratory_specimen_no_truncate BEFORE TRUNCATE ON {$attempts} FOR EACH STATEMENT EXECUTE FUNCTION laboratory_truncate_guard()");
        } else {
            foreach ($appendOnly as $table) {
                $q = SchemaQualifier::table($table);
                DB::statement("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$q} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='laboratory evidence is append-only'");
                DB::statement("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$q} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='laboratory evidence is append-only'; END IF; END");
            }
            DB::statement("CREATE TRIGGER laboratory_master_transition BEFORE UPDATE ON {$masters} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.created_at<>OLD.created_at OR NEW.examination_code<>OLD.examination_code OR NEW.version<>OLD.version+1 OR OLD.state='RETIRED' OR NOT(OLD.state='ACTIVE' AND NEW.state IN ('ACTIVE','RETIRED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid laboratory master transition'; END IF; END");
            DB::statement("CREATE TRIGGER laboratory_master_no_delete BEFORE DELETE ON {$masters} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='laboratory master cannot be deleted'");
            DB::statement("CREATE TRIGGER laboratory_order_transition BEFORE UPDATE ON {$orders} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.version<>OLD.version+1 OR NEW.encounter_id<>OLD.encounter_id OR NEW.master_id<>OLD.master_id OR NEW.ordered_by_user_id<>OLD.ordered_by_user_id OR NEW.master_version<>OLD.master_version OR NEW.master_version_public_id<>OLD.master_version_public_id OR NEW.master_content_digest<>OLD.master_content_digest OR NEW.master_code<>OLD.master_code OR NEW.master_display_name<>OLD.master_display_name OR NOT(NEW.specimen_type_snapshot<=>OLD.specimen_type_snapshot) OR NOT(NEW.collection_instruction_snapshot<=>OLD.collection_instruction_snapshot) OR NOT(NEW.components_snapshot<=>OLD.components_snapshot) OR NEW.care_setting<>OLD.care_setting OR NEW.encounter_status_snapshot<>OLD.encounter_status_snapshot OR NEW.encounter_number_snapshot<>OLD.encounter_number_snapshot OR NEW.care_location_label_snapshot<>OLD.care_location_label_snapshot OR NEW.priority<>OLD.priority OR NEW.clinical_question<>OLD.clinical_question OR NEW.ordered_at<>OLD.ordered_at OR NEW.created_at<>OLD.created_at OR NOT((OLD.status='ORDERED' AND NEW.status IN ('SPECIMEN_ACCEPTED','CANCELLED')) OR (OLD.status='SPECIMEN_ACCEPTED' AND NEW.status='REPORTED_VERIFIED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid laboratory order transition'; END IF; END");
            DB::statement("CREATE TRIGGER laboratory_order_no_delete BEFORE DELETE ON {$orders} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='laboratory order cannot be deleted'; END IF; END");
            DB::statement("CREATE TRIGGER laboratory_specimen_transition BEFORE UPDATE ON {$attempts} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.laboratory_order_id<>OLD.laboratory_order_id OR NEW.attempt_number<>OLD.attempt_number OR NEW.label_identifier<>OLD.label_identifier OR NEW.collector_user_id<>OLD.collector_user_id OR NEW.collected_at<>OLD.collected_at OR NOT(NEW.collection_note<=>OLD.collection_note) OR NEW.created_at<>OLD.created_at OR NEW.version<>OLD.version+1 OR NOT((OLD.state='COLLECTED' AND NEW.state='RECEIVED') OR (OLD.state='RECEIVED' AND NEW.state IN ('ACCEPTED','REJECTED'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid laboratory specimen transition'; END IF; END");
            DB::statement("CREATE TRIGGER laboratory_specimen_no_delete BEFORE DELETE ON {$attempts} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='laboratory specimen cannot be deleted'; END IF; END");
        }
    }
};
