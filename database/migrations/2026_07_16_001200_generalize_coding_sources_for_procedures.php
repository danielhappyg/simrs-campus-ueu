<?php

use App\Modules\Coding\Enums\CodingSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coding_suggestion_runs', function (Blueprint $table): void {
            $table->string('source_type')
                ->default(CodingSourceType::Diagnosis->value)
                ->after('encounter_id')
                ->index();
            $table->foreignId('source_condition_id')->nullable()->change();
            $table->foreignId('source_entry_version_id')->nullable()->change();
            $table->foreignId('source_procedure_id')
                ->nullable()
                ->after('source_entry_version_id')
                ->constrained('clinical_procedures')
                ->restrictOnDelete();
            $table->index(
                ['encounter_id', 'source_procedure_id', 'generated_at'],
                'coding_run_procedure_timeline',
            );
        });

        Schema::table('coding_assignments', function (Blueprint $table): void {
            $table->string('source_type')
                ->default(CodingSourceType::Diagnosis->value)
                ->after('encounter_id')
                ->index();
            $table->foreignId('source_condition_id')->nullable()->change();
            $table->foreignId('source_entry_version_id')->nullable()->change();
            $table->foreignId('source_procedure_id')
                ->nullable()
                ->after('source_entry_version_id')
                ->constrained('clinical_procedures')
                ->restrictOnDelete();
            $table->index(
                ['encounter_id', 'source_procedure_id', 'recorded_at'],
                'coding_assignment_procedure_timeline',
            );
        });
    }

    public function down(): void
    {
        Schema::table('coding_assignments', function (Blueprint $table): void {
            $table->dropIndex('coding_assignment_procedure_timeline');
            $table->dropIndex('coding_assignments_source_type_index');
            $table->dropForeign(['source_procedure_id']);
            $table->dropColumn(['source_type', 'source_procedure_id']);
            $table->foreignId('source_condition_id')->nullable(false)->change();
            $table->foreignId('source_entry_version_id')->nullable(false)->change();
        });

        Schema::table('coding_suggestion_runs', function (Blueprint $table): void {
            $table->dropIndex('coding_run_procedure_timeline');
            $table->dropIndex('coding_suggestion_runs_source_type_index');
            $table->dropForeign(['source_procedure_id']);
            $table->dropColumn(['source_type', 'source_procedure_id']);
            $table->foreignId('source_condition_id')->nullable(false)->change();
            $table->foreignId('source_entry_version_id')->nullable(false)->change();
        });
    }
};
