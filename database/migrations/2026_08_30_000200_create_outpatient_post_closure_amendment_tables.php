<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'outpatient_post_closure_amendment_requests',
        'outpatient_clinical_document_addenda',
        'outpatient_clinical_document_addendum_versions',
        'outpatient_rm_amendment_reviews',
        'outpatient_rm_amendment_review_items',
        'outpatient_amendment_operation_receipts',
    ];

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        Schema::create(SchemaQualifier::table('outpatient_post_closure_amendment_requests'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('encounter_id')->constrained('encounters', indexName: 'opcar_encounter_fk')->cascadeOnDelete();
            $table->foreignId('original_document_id')->constrained('outpatient_clinical_documents', indexName: 'opcar_original_document_fk')->restrictOnDelete();
            $table->unsignedInteger('original_document_version');
            $table->foreignId('requested_by_user_id')->constrained('users', indexName: 'opcar_requester_fk')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users', indexName: 'opcar_decider_fk')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->string('note', 500)->nullable();
            $table->string('request_state', 32);
            $table->unsignedInteger('version');
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamps();

            $table->unique('public_id', 'opcar_public_id_uq');
            $table->index(['encounter_id', 'request_state'], 'opcar_encounter_state_idx');
            $table->index(['requested_by_user_id', 'created_at'], 'opcar_requester_time_idx');
        });

        Schema::create(SchemaQualifier::table('outpatient_clinical_document_addenda'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('amendment_request_id')->constrained('outpatient_post_closure_amendment_requests', indexName: 'ocda_request_fk')->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters', indexName: 'ocda_encounter_fk')->cascadeOnDelete();
            $table->foreignId('original_document_id')->constrained('outpatient_clinical_documents', indexName: 'ocda_original_document_fk')->restrictOnDelete();
            $table->unsignedInteger('original_document_version');
            $table->foreignId('author_user_id')->constrained('users', indexName: 'ocda_author_fk')->restrictOnDelete();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users', indexName: 'ocda_finalizer_fk')->restrictOnDelete();
            $table->string('addendum_state', 16);
            $table->string('definition_version', 64);
            $table->unsignedInteger('version');
            $table->json('fields');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('public_id', 'ocda_public_id_uq');
            $table->unique('amendment_request_id', 'ocda_request_uq');
            $table->index(['encounter_id', 'addendum_state'], 'ocda_encounter_state_idx');
        });

        Schema::create(SchemaQualifier::table('outpatient_clinical_document_addendum_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('addendum_id')->constrained('outpatient_clinical_document_addenda', indexName: 'ocdav_addendum_fk')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', indexName: 'ocdav_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('addendum_state', 16);
            $table->string('definition_version', 64);
            $table->json('fields');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('created_at');

            $table->unique('public_id', 'ocdav_public_id_uq');
            $table->unique(['addendum_id', 'version'], 'ocdav_addendum_version_uq');
        });

        Schema::create(SchemaQualifier::table('outpatient_rm_amendment_reviews'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('amendment_request_id')->constrained('outpatient_post_closure_amendment_requests', indexName: 'ormar_request_fk')->cascadeOnDelete();
            $table->foreignId('addendum_id')->constrained('outpatient_clinical_document_addenda', indexName: 'ormar_addendum_fk')->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters', indexName: 'ormar_encounter_fk')->cascadeOnDelete();
            $table->foreignId('baseline_review_id')->constrained('outpatient_rm_completeness_reviews', indexName: 'ormar_baseline_review_fk')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->constrained('users', indexName: 'ormar_reviewer_fk')->restrictOnDelete();
            $table->foreignId('signed_off_by_user_id')->nullable()->constrained('users', indexName: 'ormar_signoff_fk')->restrictOnDelete();
            $table->string('definition_version', 64);
            $table->unsignedInteger('version');
            $table->string('source_fingerprint', 64);
            $table->string('review_state', 16);
            $table->timestamp('reviewed_at');
            $table->timestamp('signed_off_at')->nullable();
            $table->timestamps();

            $table->unique('public_id', 'ormar_public_id_uq');
            $table->unique(['amendment_request_id', 'version'], 'ormar_request_version_uq');
            $table->index(['encounter_id', 'review_state'], 'ormar_encounter_state_idx');
        });

        Schema::create(SchemaQualifier::table('outpatient_rm_amendment_review_items'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('outpatient_rm_amendment_reviews', indexName: 'ormari_review_fk')->cascadeOnDelete();
            $table->string('item_code', 64);
            $table->string('label', 255);
            $table->boolean('is_blocking')->default(true);
            $table->boolean('is_complete');
            $table->string('source_reference', 255)->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'item_code'], 'ormari_review_item_uq');
        });

        Schema::create(SchemaQualifier::table('outpatient_amendment_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('encounter_id')->constrained('encounters', indexName: 'oaor_encounter_fk')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', indexName: 'oaor_actor_fk')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('result_type', 64);
            $table->string('result_public_id', 26);
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('completed_at')->useCurrent();
            $table->timestamps();

            $table->unique('public_id', 'oaor_public_id_uq');
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'oaor_actor_operation_key_uq');
            $table->index(['encounter_id', 'completed_at'], 'oaor_encounter_time_idx');
        });

        $this->addRequestChecks();
        $this->addRenewedReviewChecks();
        $this->qualifyPostgresSequences();
    }

    public function down(): void
    {
        $auditTable = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($auditTable)
            && DB::table($auditTable)->where('action', 'like', 'clinical.outpatient.amendment.%')->exists()) {
            throw new RuntimeException('Refusing to roll back outpatient amendment tables because correlated audit evidence remains.');
        }

        foreach ($this->tables as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back outpatient amendment tables because retained amendment evidence exists.');
            }
        }

        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists(SchemaQualifier::table($table));
        }
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Outpatient amendment migration requires SIMULATION mode with synthetic-only data enforced.');
        }

        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Outpatient amendment migration refused because non-synthetic patient data exists.');
        }
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
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
                $qualifiedTable,
                $this->quoteLiteral($qualifiedSequence),
            ));
        }
    }

    private function addRequestChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable(
            SchemaQualifier::table('outpatient_post_closure_amendment_requests'),
        );
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT opcar_state_ck CHECK (request_state IN ('SUBMITTED', 'APPROVED', 'DENIED', 'CONSUMED'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT opcar_reason_ck CHECK (reason_code IN ('CLINICAL_CORRECTION', 'MISSING_INFORMATION', 'WRONG_ENTRY', 'OTHER'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT opcar_version_ck CHECK (version >= 1 AND original_document_version >= 1)");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT opcar_decision_ck CHECK (
            (request_state = 'SUBMITTED' AND decided_by_user_id IS NULL AND decided_at IS NULL AND consumed_at IS NULL)
            OR (request_state IN ('APPROVED', 'DENIED') AND decided_by_user_id IS NOT NULL AND decided_by_user_id <> requested_by_user_id AND decided_at IS NOT NULL AND consumed_at IS NULL)
            OR (request_state = 'CONSUMED' AND decided_by_user_id IS NOT NULL AND decided_by_user_id <> requested_by_user_id AND decided_at IS NOT NULL AND consumed_at IS NOT NULL)
        )");
    }

    private function addRenewedReviewChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $reviews = DB::connection()->getQueryGrammar()->wrapTable(
            SchemaQualifier::table('outpatient_rm_amendment_reviews'),
        );
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT ormar_state_ck CHECK (review_state IN ('DRAFT', 'SIGNED_OFF'))");
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT ormar_definition_ck CHECK (definition_version = 'OUTPATIENT_RM_AMENDMENT_REVIEW_V1')");
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT ormar_version_fingerprint_ck CHECK (version >= 1 AND CHAR_LENGTH(source_fingerprint) = 64)");
        DB::statement("ALTER TABLE {$reviews} ADD CONSTRAINT ormar_attribution_ck CHECK (
            (review_state = 'DRAFT' AND signed_off_by_user_id IS NULL AND signed_off_at IS NULL)
            OR (review_state = 'SIGNED_OFF' AND signed_off_by_user_id = reviewed_by_user_id AND signed_off_at = reviewed_at)
        )");

        $items = DB::connection()->getQueryGrammar()->wrapTable(
            SchemaQualifier::table('outpatient_rm_amendment_review_items'),
        );
        DB::statement("ALTER TABLE {$items} ADD CONSTRAINT ormari_code_ck CHECK (item_code IN ('BASELINE_SIGNOFF', 'FINAL_ADDENDUM', 'CURRENT_SOURCE_REFERENCE', 'NO_ACTIVE_LAB_ORDERS'))");
        DB::statement("ALTER TABLE {$items} ADD CONSTRAINT ormari_evidence_ck CHECK (
            is_blocking = TRUE
            AND (
                (item_code IN ('BASELINE_SIGNOFF', 'FINAL_ADDENDUM', 'CURRENT_SOURCE_REFERENCE') AND is_complete = TRUE AND source_reference IS NOT NULL)
                OR (item_code = 'NO_ACTIVE_LAB_ORDERS' AND source_reference IS NULL)
            )
        )");
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
