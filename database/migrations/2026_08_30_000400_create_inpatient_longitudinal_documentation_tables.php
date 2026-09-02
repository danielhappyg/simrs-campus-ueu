<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\InpatientDocumentationSchemaMutationScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'inpatient_clinical_documents',
        'inpatient_clinical_document_versions',
        'inpatient_document_operation_receipts',
    ];

    public function up(): void
    {
        InpatientDocumentationSchemaMutationScope::run(function (): void {
            $this->applySchema();
        });
    }

    private function applySchema(): void
    {
        $this->assertSyntheticMigrationBoundary();

        Schema::create(SchemaQualifier::table('inpatient_clinical_documents'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'icd_encounter_fk')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'icd_author_fk')->restrictOnDelete();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained(SchemaQualifier::table('users'), indexName: 'icd_finalizer_fk')->restrictOnDelete();
            $table->string('document_type', 32);
            $table->date('service_date');
            $table->string('document_state', 16);
            $table->string('definition_version', 64);
            $table->unsignedInteger('version');
            $table->json('fields');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('public_id', 'icd_public_id_uq');
            $table->unique(['encounter_id', 'document_type', 'service_date', 'author_user_id'], 'icd_identity_uq');
            $table->index(['encounter_id', 'service_date', 'document_type'], 'icd_encounter_day_type_idx');
        });

        Schema::create(SchemaQualifier::table('inpatient_clinical_document_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('inpatient_clinical_document_id')->constrained(SchemaQualifier::table('inpatient_clinical_documents'), indexName: 'icdv_document_fk')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'icdv_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('document_type', 32);
            $table->string('document_state', 16);
            $table->string('definition_version', 64);
            $table->json('fields');
            $table->string('encounter_public_id', 26);
            $table->string('care_setting', 32);
            $table->date('service_date');
            $table->string('ward_public_id', 26);
            $table->string('ward_code', 64);
            $table->string('ward_display_name', 120);
            $table->string('bed_public_id', 26);
            $table->string('bed_code', 64);
            $table->string('bed_display_name', 120);
            $table->string('room_label', 120);
            $table->string('service_class', 120);
            $table->string('encounter_status', 32);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('created_at');

            $table->unique('public_id', 'icdv_public_id_uq');
            $table->unique(['inpatient_clinical_document_id', 'version'], 'icdv_document_version_uq');
            $table->index(['service_date', 'document_state', 'created_at'], 'icdv_day_state_time_idx');
        });

        Schema::create(SchemaQualifier::table('inpatient_document_operation_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26);
            $table->foreignId('encounter_id')->constrained(SchemaQualifier::table('encounters'), indexName: 'idor_encounter_fk')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained(SchemaQualifier::table('users'), indexName: 'idor_actor_fk')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('result_public_id', 26);
            $table->unsignedInteger('result_version');
            $table->string('request_correlation_id', 26)->nullable();
            $table->timestamp('completed_at')->useCurrent();
            $table->timestamps();

            $table->unique('public_id', 'idor_public_id_uq');
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'idor_actor_operation_key_uq');
            $table->index(['encounter_id', 'completed_at'], 'idor_encounter_time_idx');
        });

        $this->addChecks();
        $this->qualifyPostgresSequences();
    }

    public function down(): void
    {
        $audit = SchemaQualifier::table('audit_events');
        if (Schema::hasTable($audit)
            && DB::table($audit)->where('action', 'like', 'clinical.inpatient.%')->exists()) {
            throw new RuntimeException('Refusing to roll back inpatient documentation because correlated audit evidence remains.');
        }

        foreach ($this->tables as $table) {
            $qualified = SchemaQualifier::table($table);
            if (Schema::hasTable($qualified) && DB::table($qualified)->exists()) {
                throw new RuntimeException('Refusing to roll back inpatient documentation because retained document evidence exists.');
            }
        }

        InpatientDocumentationSchemaMutationScope::run(function (): void {
            foreach (array_reverse($this->tables) as $table) {
                Schema::dropIfExists(SchemaQualifier::table($table));
            }
        });
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Inpatient documentation migration requires SIMULATION mode with synthetic-only data enforced.');
        }

        $patients = SchemaQualifier::table('patients');
        if (Schema::hasTable($patients) && DB::table($patients)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Inpatient documentation migration refused because non-synthetic patient data exists.');
        }
    }

    private function addChecks(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        foreach (['inpatient_clinical_documents', 'inpatient_clinical_document_versions'] as $name) {
            $table = $grammar->wrapTable(SchemaQualifier::table($name));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_type_ck CHECK (document_type IN ('NURSING_DAILY', 'MEDICAL_DAILY'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_state_ck CHECK (document_state IN ('DRAFT', 'FINAL'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_definition_ck CHECK (definition_version = 'INPATIENT_LONGITUDINAL_DOCUMENTATION_V1')");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}_version_ck CHECK (version >= 1)");
        }

        $versions = $grammar->wrapTable(SchemaQualifier::table('inpatient_clinical_document_versions'));
        DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT icdv_care_setting_ck CHECK (care_setting = 'INPATIENT')");
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

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
