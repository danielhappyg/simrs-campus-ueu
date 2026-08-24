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
        'outpatient_clinical_documents',
        'outpatient_clinical_document_versions',
        'outpatient_rm_completeness_reviews',
        'outpatient_rm_completeness_items',
    ];

    public function up(): void
    {
        Schema::create('outpatient_clinical_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id');
            $table->foreignId('encounter_id')->constrained('encounters', indexName: 'ocd_encounter_fk')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users', indexName: 'ocd_author_user_fk')->restrictOnDelete();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users', indexName: 'ocd_finalized_by_user_fk')->nullOnDelete();
            $table->string('document_type');
            $table->string('document_state');
            $table->string('definition_version');
            $table->unsignedInteger('version');
            $table->json('fields');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('public_id', 'ocd_public_id_uq');
            $table->unique(['encounter_id', 'document_type'], 'ocd_encounter_type_uq');
            $table->index(['encounter_id', 'document_state'], 'ocd_encounter_state_idx');
        });

        Schema::create('outpatient_clinical_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id');
            $table->foreignId('outpatient_clinical_document_id')
                ->constrained('outpatient_clinical_documents', indexName: 'ocdv_document_fk')
                ->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', indexName: 'ocdv_actor_user_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('document_state');
            $table->string('definition_version');
            $table->json('fields');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('created_at');

            $table->unique('public_id', 'ocdv_public_id_uq');
            $table->unique(['outpatient_clinical_document_id', 'version'], 'ocdv_document_version_uq');
        });

        Schema::create('outpatient_rm_completeness_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id');
            $table->foreignId('encounter_id')->constrained('encounters', indexName: 'ormcr_encounter_fk')->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users', indexName: 'ormcr_reviewed_by_user_fk')->nullOnDelete();
            $table->foreignId('signed_off_by_user_id')->nullable()->constrained('users', indexName: 'ormcr_signed_off_by_user_fk')->nullOnDelete();
            $table->string('definition_version');
            $table->unsignedInteger('version');
            $table->string('source_fingerprint', 64);
            $table->string('review_state');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('signed_off_at')->nullable();
            $table->timestamps();

            $table->unique('public_id', 'ormcr_public_id_uq');
            $table->unique(['encounter_id', 'version'], 'ormcr_encounter_version_uq');
            $table->index(['encounter_id', 'review_state'], 'ormcr_encounter_state_idx');
        });

        Schema::create('outpatient_rm_completeness_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outpatient_rm_completeness_review_id')
                ->constrained('outpatient_rm_completeness_reviews', indexName: 'ormci_review_fk')
                ->cascadeOnDelete();
            $table->string('item_code');
            $table->string('label');
            $table->boolean('is_blocking')->default(true);
            $table->boolean('is_complete');
            $table->string('source_reference')->nullable();
            $table->timestamps();

            $table->unique(['outpatient_rm_completeness_review_id', 'item_code'], 'ormci_review_item_uq');
        });

        $this->qualifyPostgresSequences();
    }

    public function down(): void
    {
        Schema::dropIfExists('outpatient_rm_completeness_items');
        Schema::dropIfExists('outpatient_rm_completeness_reviews');
        Schema::dropIfExists('outpatient_clinical_document_versions');
        Schema::dropIfExists('outpatient_clinical_documents');
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
