<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminology_releases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->string('classification_system')->index();
            $table->string('logical_version');
            $table->string('status')->index();
            $table->string('source_filename');
            $table->char('source_sha256', 64);
            $table->string('sheet_name');
            $table->string('source_provenance_status');
            $table->foreignId('imported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('imported_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('ignored_blank_rows')->default(0);
            $table->json('validation_report');
            $table->timestamp('imported_at');
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by_assignment_id')->nullable()->constrained('assignments')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('supersedes_release_id')->nullable()->constrained('terminology_releases')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['classification_system', 'logical_version', 'source_sha256'],
                'terminology_release_source_unique',
            );
            $table->index(
                ['classification_system', 'status', 'activated_at'],
                'terminology_release_active_lookup',
            );
        });

        Schema::create('terminology_concepts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('terminology_release_id')->constrained('terminology_releases')->restrictOnDelete();
            $table->string('code');
            $table->text('display');
            $table->string('normalized_code');
            $table->text('normalized_display');
            $table->text('search_tokens');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['terminology_release_id', 'code'], 'terminology_concept_release_code_unique');
            $table->index(['terminology_release_id', 'normalized_code'], 'terminology_concept_code_search');
            $table->index(['terminology_release_id', 'active'], 'terminology_concept_active_lookup');
        });

        Schema::create('terminology_aliases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('terminology_release_id')->constrained('terminology_releases')->restrictOnDelete();
            $table->foreignId('terminology_concept_id')->constrained('terminology_concepts')->restrictOnDelete();
            $table->string('rule_id');
            $table->text('phrase');
            $table->text('normalized_phrase');
            $table->string('source');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['terminology_release_id', 'rule_id'], 'terminology_alias_release_rule_unique');
            $table->index(['terminology_release_id', 'active'], 'terminology_alias_active_lookup');
        });

        Schema::create('coding_suggestion_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('source_condition_id')->constrained('clinical_conditions')->restrictOnDelete();
            $table->foreignId('source_entry_version_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('terminology_release_id')->constrained('terminology_releases')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('engine_type');
            $table->string('engine_version');
            $table->char('configuration_hash', 64);
            $table->text('normalized_input');
            $table->char('normalized_input_hash', 64);
            $table->string('outcome');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['encounter_id', 'source_condition_id', 'generated_at'], 'coding_run_source_timeline');
        });

        Schema::create('coding_suggestion_candidates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('coding_suggestion_run_id')->constrained('coding_suggestion_runs')->restrictOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->foreignId('terminology_concept_id')->constrained('terminology_concepts')->restrictOnDelete();
            $table->string('confidence_band');
            $table->unsignedInteger('score');
            $table->json('evidence');
            $table->text('specificity_warning')->nullable();
            $table->timestamps();

            $table->unique(['coding_suggestion_run_id', 'rank'], 'coding_candidate_run_rank_unique');
            $table->unique(['coding_suggestion_run_id', 'terminology_concept_id'], 'coding_candidate_run_concept_unique');
        });

        Schema::create('coding_assignments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('source_condition_id')->constrained('clinical_conditions')->restrictOnDelete();
            $table->foreignId('source_entry_version_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('terminology_release_id')->constrained('terminology_releases')->restrictOnDelete();
            $table->foreignId('terminology_concept_id')->constrained('terminology_concepts')->restrictOnDelete();
            $table->foreignId('coder_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('coder_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('status')->index();
            $table->string('selection_method');
            $table->foreignId('source_suggestion_run_id')->nullable()->constrained('coding_suggestion_runs')->restrictOnDelete();
            $table->foreignId('source_candidate_id')->nullable()->constrained('coding_suggestion_candidates')->restrictOnDelete();
            $table->char('source_clinical_content_hash', 64);
            $table->char('source_statement_hash', 64);
            $table->char('terminology_source_hash', 64);
            $table->char('content_hash', 64);
            $table->text('rationale')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('supersedes_assignment_id')->nullable()->constrained('coding_assignments')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['encounter_id', 'source_condition_id', 'recorded_at'], 'coding_assignment_source_timeline');
        });

        Schema::create('coding_suggestion_decisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('coding_suggestion_run_id')->constrained('coding_suggestion_runs')->restrictOnDelete();
            $table->foreignId('coding_suggestion_candidate_id')
                ->nullable()
                ->constrained(
                    'coding_suggestion_candidates',
                    'id',
                    'coding_decision_candidate_fk',
                )
                ->restrictOnDelete();
            $table->string('decision');
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('resulting_assignment_id')->nullable()->constrained('coding_assignments')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('coding_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('coding_assignment_id')->constrained('coding_assignments')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('action');
            $table->json('findings')->nullable();
            $table->text('comment')->nullable();
            $table->char('reviewed_content_hash', 64);
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_review_actions');
        Schema::dropIfExists('coding_suggestion_decisions');
        Schema::dropIfExists('coding_assignments');
        Schema::dropIfExists('coding_suggestion_candidates');
        Schema::dropIfExists('coding_suggestion_runs');
        Schema::dropIfExists('terminology_aliases');
        Schema::dropIfExists('terminology_concepts');
        Schema::dropIfExists('terminology_releases');
    }
};
