<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Emergency\EmergencySchemaMutationScope;
use App\Support\Inpatient\InpatientLocationSchemaMutationScope;
use App\Support\Laboratory\LaboratorySchemaMutationScope;
use App\Support\Radiology\RadiologySchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'emergency_operation_receipts',
        'emergency_handoff_compensations',
        'emergency_disposition_correction_intent_events',
        'emergency_disposition_correction_intents',
        'emergency_inpatient_handoffs',
        'emergency_dispositions',
        'emergency_result_follow_up_acceptances',
        'emergency_result_follow_up_proposals',
        'emergency_clinical_document_versions',
        'emergency_clinical_documents',
        'emergency_triage_assessments',
        'emergency_triage_vocabulary_versions',
        'emergency_triage_vocabularies',
        'emergency_triage_code_reservations',
    ];

    public function up(): void
    {
        EmergencySchemaMutationScope::run(
            fn () => InpatientLocationSchemaMutationScope::run(
                fn () => LaboratorySchemaMutationScope::run(
                    fn () => RadiologySchemaMutationScope::run(fn () => $this->migrateUp()),
                ),
            ),
        );
    }

    private function migrateUp(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Emergency teaching migration requires synthetic-only SIMULATION mode.');
        }

        Schema::create(SchemaQualifier::table('emergency_triage_code_reservations'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'etcr_actor_fk')->restrictOnDelete();
            $table->string('normalized_code', 64)->unique();
            $table->timestamp('created_at');
        });

        Schema::create(SchemaQualifier::table('emergency_triage_vocabularies'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('vocabulary_code', 64)->unique();
            $table->string('display_name', 160);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->string('current_content_digest', 64);
            $table->timestamps();
        });

        Schema::create(SchemaQualifier::table('emergency_triage_vocabulary_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('emergency_triage_vocabulary_id')->constrained(SchemaQualifier::table('emergency_triage_vocabularies'), indexName: 'etvv_vocabulary_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'etvv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('display_name', 160);
            $table->json('categories');
            $table->string('state', 16);
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['emergency_triage_vocabulary_id', 'version'], 'etvv_vocabulary_version_uq');
        });

        Schema::create(SchemaQualifier::table('emergency_triage_assessments'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'eta_encounter_fk')->restrictOnDelete();
            $table->foreignId('vocabulary_version_id')->constrained(SchemaQualifier::table('emergency_triage_vocabulary_versions'), indexName: 'eta_vocabulary_version_fk')->restrictOnDelete();
            $table->foreignId('assessor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'eta_assessor_fk')->restrictOnDelete();
            $table->foreignId('prior_assessment_id')->nullable()->constrained(SchemaQualifier::table('emergency_triage_assessments'), indexName: 'eta_prior_fk')->restrictOnDelete();
            $table->unsignedInteger('assessment_number');
            $table->string('assessment_type', 16);
            $table->string('category_code', 16);
            $table->string('category_label_snapshot', 120);
            $table->unsignedSmallInteger('category_rank_snapshot');
            $table->string('category_cue_snapshot', 160);
            $table->string('category_colour_token_snapshot', 32);
            $table->timestamp('observed_at');
            $table->timestamp('recorded_at');
            $table->text('late_entry_reason')->nullable();
            $table->text('presenting_concern');
            $table->text('clinical_basis');
            $table->text('arrival_condition');
            $table->json('abcde_observations');
            $table->string('consciousness', 16);
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedSmallInteger('oxygen_saturation')->nullable();
            $table->decimal('temperature_celsius', 4, 1)->nullable();
            $table->unsignedSmallInteger('pain_score')->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->json('unobtainable_fields');
            $table->json('unobtainable_reasons');
            $table->boolean('trauma_flag');
            $table->text('trauma_note')->nullable();
            $table->boolean('isolation_precaution_flag');
            $table->text('isolation_precaution_note')->nullable();
            $table->text('handoff_note')->nullable();
            $table->text('reassessment_reason')->nullable();
            $table->string('prior_assessment_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->unique(['encounter_id', 'assessment_number'], 'eta_encounter_number_uq');
            $table->index(['encounter_id', 'observed_at'], 'eta_encounter_observed_idx');
        });

        Schema::create(SchemaQualifier::table('emergency_clinical_documents'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'ecd_encounter_fk')->restrictOnDelete();
            $table->string('document_type', 16);
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->string('current_content_digest', 64);
            $table->timestamps();
            $table->unique(['encounter_id', 'document_type'], 'ecd_encounter_type_uq');
        });

        Schema::create(SchemaQualifier::table('emergency_clinical_document_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('emergency_clinical_document_id')->constrained(SchemaQualifier::table('emergency_clinical_documents'), indexName: 'ecdv_document_fk')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ecdv_author_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 16);
            $table->json('fields');
            $table->string('content_digest', 64);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['emergency_clinical_document_id', 'version'], 'ecdv_document_version_uq');
        });

        Schema::create(SchemaQualifier::table('emergency_result_follow_up_proposals'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'erfup_encounter_fk')->restrictOnDelete();
            $table->foreignId('proposed_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'erfup_proposer_fk')->restrictOnDelete();
            $table->foreignId('proposed_to_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'erfup_assignee_fk')->restrictOnDelete();
            $table->foreignId('prior_proposal_id')->nullable()->constrained(SchemaQualifier::table('emergency_result_follow_up_proposals'), indexName: 'erfup_prior_fk')->restrictOnDelete();
            $table->string('order_type', 16);
            $table->string('order_public_id', 26);
            $table->string('result_fingerprint', 64);
            $table->text('assignment_reason');
            $table->text('handoff_note');
            $table->timestamp('effective_at');
            $table->string('prior_proposal_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('created_at');
            $table->index(['encounter_id', 'order_type', 'order_public_id'], 'erfup_order_idx');
        });

        Schema::create(SchemaQualifier::table('emergency_result_follow_up_acceptances'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('proposal_id')->unique('erfua_proposal_uq')->constrained(SchemaQualifier::table('emergency_result_follow_up_proposals'), indexName: 'erfua_proposal_fk')->restrictOnDelete();
            $table->foreignId('accepted_by_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'erfua_actor_fk')->restrictOnDelete();
            $table->string('proposal_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('accepted_at');
            $table->timestamp('created_at');
        });

        Schema::table(SchemaQualifier::table('laboratory_result_acknowledgements'), function (Blueprint $table): void {
            $table->foreignId('emergency_follow_up_acceptance_id')->nullable()->constrained(SchemaQualifier::table('emergency_result_follow_up_acceptances'), indexName: 'lraa_emergency_acceptance_fk')->restrictOnDelete();
        });
        Schema::table(SchemaQualifier::table('radiology_report_acknowledgements'), function (Blueprint $table): void {
            $table->foreignId('emergency_follow_up_acceptance_id')->nullable()->constrained(SchemaQualifier::table('emergency_result_follow_up_acceptances'), indexName: 'rraa_emergency_acceptance_fk')->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('emergency_dispositions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'ed_encounter_fk')->restrictOnDelete();
            $table->foreignId('physician_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ed_physician_fk')->restrictOnDelete();
            $table->foreignId('prior_disposition_id')->nullable()->constrained(SchemaQualifier::table('emergency_dispositions'), indexName: 'ed_prior_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('disposition_type', 32);
            $table->json('payload');
            $table->text('correction_reason')->nullable();
            $table->string('prior_disposition_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('signed_at');
            $table->timestamp('created_at');
            $table->unique(['encounter_id', 'version'], 'ed_encounter_version_uq');
        });

        Schema::create(SchemaQualifier::table('emergency_disposition_correction_intents'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'edci_encounter_fk')->restrictOnDelete();
            $table->foreignId('current_disposition_id')->constrained(SchemaQualifier::table('emergency_dispositions'), indexName: 'edci_disposition_fk')->restrictOnDelete();
            $table->unsignedBigInteger('handoff_id')->nullable()->index('edci_handoff_idx');
            $table->foreignId('physician_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'edci_physician_fk')->restrictOnDelete();
            $table->string('replacement_type', 32);
            $table->json('replacement_payload');
            $table->text('reason');
            $table->string('source_encounter_fingerprint', 64);
            $table->string('disposition_fingerprint', 64);
            $table->string('handoff_fingerprint', 64)->nullable();
            $table->string('target_encounter_fingerprint', 64)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->index(['encounter_id', 'expires_at'], 'edci_encounter_expiry_idx');
        });

        Schema::create(SchemaQualifier::table('emergency_disposition_correction_intent_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('correction_intent_id')->constrained(SchemaQualifier::table('emergency_disposition_correction_intents'), indexName: 'edcie_intent_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'edcie_actor_fk')->restrictOnDelete();
            $table->string('event_type', 16);
            $table->text('reason')->nullable();
            $table->string('intent_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->unique(['correction_intent_id', 'event_type'], 'edcie_intent_type_uq');
        });

        Schema::create(SchemaQualifier::table('emergency_inpatient_handoffs'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('source_encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'eih_source_fk')->restrictOnDelete();
            $table->foreignId('disposition_id')->unique('eih_disposition_uq')->constrained(SchemaQualifier::table('emergency_dispositions'), indexName: 'eih_disposition_fk')->restrictOnDelete();
            $table->foreignId('target_encounter_id')->unique('eih_target_uq')->constrained(SchemaQualifier::table('encounters'), indexName: 'eih_target_fk')->restrictOnDelete();
            $table->foreignId('inpatient_location_event_id')->constrained(SchemaQualifier::table('inpatient_location_events'), indexName: 'eih_location_fk')->restrictOnDelete();
            $table->foreignId('inpatient_bed_id')->constrained(SchemaQualifier::table('inpatient_beds'), indexName: 'eih_bed_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'eih_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('inpatient_bed_version');
            $table->json('bed_snapshot');
            $table->string('source_encounter_fingerprint', 64);
            $table->string('disposition_fingerprint', 64);
            $table->string('target_encounter_fingerprint', 64);
            $table->string('location_event_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('handed_off_at');
            $table->timestamp('created_at');
            $table->unique('source_encounter_id', 'eih_source_uq');
        });

        Schema::table(SchemaQualifier::table('emergency_disposition_correction_intents'), function (Blueprint $table): void {
            $table->foreign('handoff_id', 'edci_handoff_fk')->references('id')->on(SchemaQualifier::table('emergency_inpatient_handoffs'))->restrictOnDelete();
        });

        Schema::create(SchemaQualifier::table('emergency_handoff_compensations'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('handoff_id')->unique('ehc_handoff_uq')->constrained(SchemaQualifier::table('emergency_inpatient_handoffs'), indexName: 'ehc_handoff_fk')->restrictOnDelete();
            $table->foreignId('correction_intent_id')->unique('ehc_intent_uq')->constrained(SchemaQualifier::table('emergency_disposition_correction_intents'), indexName: 'ehc_intent_fk')->restrictOnDelete();
            $table->foreignId('replacement_disposition_id')->unique('ehc_replacement_uq')->constrained(SchemaQualifier::table('emergency_dispositions'), indexName: 'ehc_replacement_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'ehc_actor_fk')->restrictOnDelete();
            $table->string('target_cancellation_fingerprint', 64);
            $table->string('bed_reconciliation_fingerprint', 64);
            $table->string('location_reconciliation_fingerprint', 64);
            $table->string('content_digest', 64);
            $table->timestamp('compensated_at');
            $table->timestamp('created_at');
        });

        Schema::create(SchemaQualifier::table('emergency_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'eor_actor_fk')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('result_type', 32);
            $table->string('result_public_id', 26);
            $table->unsignedInteger('result_version');
            $table->string('result_state', 32);
            $table->string('result_digest', 64);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'eor_actor_operation_key_uq');
        });

        $this->addDatabaseGuards();
    }

    public function down(): void
    {
        EmergencySchemaMutationScope::run(
            fn () => InpatientLocationSchemaMutationScope::run(
                fn () => LaboratorySchemaMutationScope::run(
                    fn () => RadiologySchemaMutationScope::run(fn () => $this->migrateDown()),
                ),
            ),
        );
    }

    private function migrateDown(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit) && DB::table($audit)->where('action', 'emergency.workflow.mutate')->exists()) {
            throw new RuntimeException('Refusing to discard emergency tables while correlated audit evidence exists.');
        }
        foreach (self::TABLES as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to discard populated emergency evidence.');
            }
        }
        foreach (['laboratory_result_acknowledgements' => 'lraa_emergency_acceptance_fk', 'radiology_report_acknowledgements' => 'rraa_emergency_acceptance_fk'] as $acknowledgementTable => $foreignKey) {
            $qualified = SchemaQualifier::table($acknowledgementTable);
            if (Schema::hasTable($qualified) && Schema::hasColumn($qualified, 'emergency_follow_up_acceptance_id')) {
                $driver = DB::connection()->getDriverName();
                Schema::table($qualified, function (Blueprint $table) use ($driver, $foreignKey): void {
                    if ($driver === 'sqlite') {
                        $table->dropConstrainedForeignId('emergency_follow_up_acceptance_id');
                    } else {
                        $table->dropForeign($foreignKey);
                        $table->dropColumn('emergency_follow_up_acceptance_id');
                    }
                });
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['emergency_append_only_guard', 'emergency_truncate_guard', 'emergency_vocabulary_transition_guard', 'emergency_document_transition_guard', 'emergency_handoff_graph_guard'] as $function) {
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

        $vocabularies = SchemaQualifier::table('emergency_triage_vocabularies');
        $assessments = SchemaQualifier::table('emergency_triage_assessments');
        $documents = SchemaQualifier::table('emergency_clinical_documents');
        $documentVersions = SchemaQualifier::table('emergency_clinical_document_versions');
        $proposals = SchemaQualifier::table('emergency_result_follow_up_proposals');
        $acceptances = SchemaQualifier::table('emergency_result_follow_up_acceptances');
        $dispositions = SchemaQualifier::table('emergency_dispositions');
        $intents = SchemaQualifier::table('emergency_disposition_correction_intents');
        $intentEvents = SchemaQualifier::table('emergency_disposition_correction_intent_events');
        $handoffs = SchemaQualifier::table('emergency_inpatient_handoffs');
        $compensations = SchemaQualifier::table('emergency_handoff_compensations');
        $receipts = SchemaQualifier::table('emergency_operation_receipts');

        DB::statement("ALTER TABLE {$vocabularies} ADD CONSTRAINT etv_state_ck CHECK (state IN ('ACTIVE','RETIRED') AND version>=1 AND CHAR_LENGTH(current_content_digest)=64)");
        DB::statement('ALTER TABLE '.SchemaQualifier::table('emergency_triage_vocabulary_versions')." ADD CONSTRAINT etvv_state_ck CHECK (state IN ('ACTIVE','RETIRED') AND version>=1 AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$assessments} ADD CONSTRAINT eta_type_ck CHECK (assessment_type IN ('INITIAL','REASSESSMENT') AND category_code IN ('MERAH','KUNING','HIJAU','HITAM') AND consciousness IN ('ALERT','VOICE','PAIN','UNRESPONSIVE') AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$assessments} ADD CONSTRAINT eta_number_ck CHECK ((assessment_type='INITIAL' AND assessment_number=1 AND prior_assessment_id IS NULL AND reassessment_reason IS NULL AND prior_assessment_digest IS NULL) OR (assessment_type='REASSESSMENT' AND assessment_number>1 AND prior_assessment_id IS NOT NULL AND reassessment_reason IS NOT NULL AND CHAR_LENGTH(prior_assessment_digest)=64))");
        DB::statement("ALTER TABLE {$assessments} ADD CONSTRAINT eta_vitals_ck CHECK ((respiratory_rate IS NULL OR respiratory_rate<=100) AND (pulse IS NULL OR pulse<=300) AND ((systolic_bp IS NULL AND diastolic_bp IS NULL) OR (systolic_bp IS NOT NULL AND diastolic_bp IS NOT NULL AND systolic_bp<=300 AND diastolic_bp<=300)) AND (oxygen_saturation IS NULL OR oxygen_saturation<=100) AND (temperature_celsius IS NULL OR (temperature_celsius>=20.0 AND temperature_celsius<=45.0)) AND (pain_score IS NULL OR pain_score<=10) AND (weight_kg IS NULL OR (weight_kg>=0.1 AND weight_kg<=500.0)))");
        DB::statement("ALTER TABLE {$documents} ADD CONSTRAINT ecd_state_ck CHECK (document_type IN ('NURSING','MEDICAL') AND state IN ('DRAFT','FINAL') AND version>=1 AND CHAR_LENGTH(current_content_digest)=64)");
        DB::statement("ALTER TABLE {$documentVersions} ADD CONSTRAINT ecdv_state_ck CHECK (state IN ('DRAFT','FINAL') AND version>=1 AND CHAR_LENGTH(content_digest)=64 AND ((state='DRAFT' AND finalized_at IS NULL) OR (state='FINAL' AND finalized_at IS NOT NULL)))");
        DB::statement("ALTER TABLE {$proposals} ADD CONSTRAINT erfup_values_ck CHECK (order_type IN ('LABORATORY','RADIOLOGY') AND CHAR_LENGTH(result_fingerprint)=64 AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$acceptances} ADD CONSTRAINT erfua_digest_ck CHECK (CHAR_LENGTH(proposal_fingerprint)=64 AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$dispositions} ADD CONSTRAINT ed_values_ck CHECK (disposition_type IN ('PULANG','DIRUJUK','RAWAT_INAP','MENINGGAL_DI_IGD','DOA') AND version>=1 AND CHAR_LENGTH(content_digest)=64 AND ((version=1 AND prior_disposition_id IS NULL AND correction_reason IS NULL AND prior_disposition_digest IS NULL) OR (version>1 AND prior_disposition_id IS NOT NULL AND correction_reason IS NOT NULL AND CHAR_LENGTH(prior_disposition_digest)=64)))");
        DB::statement("ALTER TABLE {$intents} ADD CONSTRAINT edci_values_ck CHECK (replacement_type IN ('PULANG','DIRUJUK','RAWAT_INAP','MENINGGAL_DI_IGD','DOA') AND CHAR_LENGTH(source_encounter_fingerprint)=64 AND CHAR_LENGTH(disposition_fingerprint)=64 AND CHAR_LENGTH(content_digest)=64 AND ((handoff_id IS NULL AND handoff_fingerprint IS NULL AND target_encounter_fingerprint IS NULL) OR (handoff_id IS NOT NULL AND CHAR_LENGTH(handoff_fingerprint)=64 AND CHAR_LENGTH(target_encounter_fingerprint)=64)))");
        DB::statement("ALTER TABLE {$intentEvents} ADD CONSTRAINT edcie_values_ck CHECK (event_type IN ('REVOKED','EXECUTED','EXPIRED') AND CHAR_LENGTH(intent_fingerprint)=64 AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$handoffs} ADD CONSTRAINT eih_digest_ck CHECK (inpatient_bed_version>=1 AND CHAR_LENGTH(source_encounter_fingerprint)=64 AND CHAR_LENGTH(disposition_fingerprint)=64 AND CHAR_LENGTH(target_encounter_fingerprint)=64 AND CHAR_LENGTH(location_event_fingerprint)=64 AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$compensations} ADD CONSTRAINT ehc_digest_ck CHECK (CHAR_LENGTH(target_cancellation_fingerprint)=64 AND CHAR_LENGTH(bed_reconciliation_fingerprint)=64 AND CHAR_LENGTH(location_reconciliation_fingerprint)=64 AND CHAR_LENGTH(content_digest)=64)");
        DB::statement("ALTER TABLE {$receipts} ADD CONSTRAINT eor_values_ck CHECK (result_version>=1 AND CHAR_LENGTH(payload_digest)=64 AND CHAR_LENGTH(result_digest)=64)");

        $this->addServerHandoffGraphGuard($driver);

        $appendOnly = [
            'emergency_triage_code_reservations', 'emergency_triage_vocabulary_versions',
            'emergency_triage_assessments', 'emergency_clinical_document_versions',
            'emergency_result_follow_up_proposals', 'emergency_result_follow_up_acceptances',
            'emergency_dispositions', 'emergency_disposition_correction_intents',
            'emergency_disposition_correction_intent_events', 'emergency_inpatient_handoffs',
            'emergency_handoff_compensations', 'emergency_operation_receipts',
        ];

        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION emergency_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF current_setting('simrs.synthetic_reset', true)='1' AND TG_OP='DELETE' THEN RETURN OLD; END IF; RAISE EXCEPTION 'emergency evidence is append-only'; END $$");
            DB::unprepared("CREATE OR REPLACE FUNCTION emergency_truncate_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'emergency evidence cannot be truncated'; END $$");
            foreach ($appendOnly as $table) {
                $qualified = SchemaQualifier::table($table);
                DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$qualified} FOR EACH ROW EXECUTE FUNCTION emergency_append_only_guard()");
                DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$qualified} FOR EACH STATEMENT EXECUTE FUNCTION emergency_truncate_guard()");
            }
            DB::unprepared("CREATE OR REPLACE FUNCTION emergency_vocabulary_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.public_id<>OLD.public_id OR NEW.vocabulary_code<>OLD.vocabulary_code OR NEW.version<>OLD.version+1 OR OLD.state='RETIRED' OR NOT(OLD.state='ACTIVE' AND NEW.state IN ('ACTIVE','RETIRED')) THEN RAISE EXCEPTION 'invalid emergency vocabulary transition'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER emergency_vocabulary_transition BEFORE UPDATE ON {$vocabularies} FOR EACH ROW EXECUTE FUNCTION emergency_vocabulary_transition_guard()");
            DB::statement("CREATE TRIGGER emergency_vocabulary_no_delete BEFORE DELETE ON {$vocabularies} FOR EACH ROW EXECUTE FUNCTION emergency_append_only_guard()");
            DB::unprepared("CREATE OR REPLACE FUNCTION emergency_document_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.public_id<>OLD.public_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.document_type<>OLD.document_type OR NEW.version<>OLD.version+1 OR OLD.state='FINAL' OR NOT(OLD.state='DRAFT' AND NEW.state IN ('DRAFT','FINAL')) THEN RAISE EXCEPTION 'invalid emergency document transition'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER emergency_document_transition BEFORE UPDATE ON {$documents} FOR EACH ROW EXECUTE FUNCTION emergency_document_transition_guard()");
            DB::statement("CREATE TRIGGER emergency_document_no_delete BEFORE DELETE ON {$documents} FOR EACH ROW EXECUTE FUNCTION emergency_append_only_guard()");
        } else {
            foreach ($appendOnly as $table) {
                $qualified = SchemaQualifier::table($table);
                DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE ON {$qualified} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='emergency evidence is append-only'");
                DB::statement("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$qualified} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='emergency evidence is append-only'; END IF; END");
            }
            DB::statement("CREATE TRIGGER emergency_vocabulary_transition BEFORE UPDATE ON {$vocabularies} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.vocabulary_code<>OLD.vocabulary_code OR NEW.version<>OLD.version+1 OR OLD.state='RETIRED' OR NOT(OLD.state='ACTIVE' AND NEW.state IN ('ACTIVE','RETIRED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid emergency vocabulary transition'; END IF; END");
            DB::statement("CREATE TRIGGER emergency_vocabulary_no_delete BEFORE DELETE ON {$vocabularies} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='emergency vocabulary cannot be deleted'; END IF; END");
            DB::statement("CREATE TRIGGER emergency_document_transition BEFORE UPDATE ON {$documents} FOR EACH ROW BEGIN IF NEW.public_id<>OLD.public_id OR NEW.encounter_id<>OLD.encounter_id OR NEW.document_type<>OLD.document_type OR NEW.version<>OLD.version+1 OR OLD.state='FINAL' OR NOT(OLD.state='DRAFT' AND NEW.state IN ('DRAFT','FINAL')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid emergency document transition'; END IF; END");
            DB::statement("CREATE TRIGGER emergency_document_no_delete BEFORE DELETE ON {$documents} FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='emergency document cannot be deleted'; END IF; END");
        }
    }

    private function addServerHandoffGraphGuard(string $driver): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $handoffs = $grammar->wrapTable(SchemaQualifier::table('emergency_inpatient_handoffs'));
        $encounters = $grammar->wrapTable(SchemaQualifier::table('encounters'));
        $dispositions = $grammar->wrapTable(SchemaQualifier::table('emergency_dispositions'));
        $locations = $grammar->wrapTable(SchemaQualifier::table('inpatient_location_events'));
        $beds = $grammar->wrapTable(SchemaQualifier::table('inpatient_beds'));
        $validGraph = "EXISTS (
            SELECT 1 FROM {$encounters} source
            JOIN {$dispositions} disposition ON disposition.id=NEW.disposition_id
            JOIN {$encounters} target ON target.id=NEW.target_encounter_id
            JOIN {$locations} location ON location.id=NEW.inpatient_location_event_id
            JOIN {$beds} bed ON bed.id=NEW.inpatient_bed_id
            WHERE source.id=NEW.source_encounter_id
              AND source.care_setting='EMERGENCY'
              AND disposition.encounter_id=source.id
              AND disposition.disposition_type='RAWAT_INAP'
              AND target.care_setting='INPATIENT'
              AND target.patient_id=source.patient_id
              AND target.inpatient_bed_id=bed.id
              AND location.encounter_id=target.id
              AND location.event_type='ADMISSION_LOCATION'
              AND location.to_bed_public_id=bed.public_id
              AND bed.state='ACTIVE'
              AND NEW.inpatient_bed_version=bed.version
        )";

        if ($driver === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION emergency_handoff_graph_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NOT ({$validGraph}) THEN RAISE EXCEPTION 'invalid emergency inpatient handoff graph'; END IF; RETURN NEW; END $$");
            DB::statement("CREATE TRIGGER emergency_handoff_graph_insert BEFORE INSERT ON {$handoffs} FOR EACH ROW EXECUTE FUNCTION emergency_handoff_graph_guard()");

            return;
        }

        DB::statement("CREATE TRIGGER emergency_handoff_graph_insert BEFORE INSERT ON {$handoffs} FOR EACH ROW BEGIN IF NOT ({$validGraph}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='invalid emergency inpatient handoff graph'; END IF; END");
    }
};
