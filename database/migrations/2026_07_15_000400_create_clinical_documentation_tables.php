<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('created_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('document_type');
            $table->string('sensitivity_class')->default('STANDARD_CLINICAL');
            $table->string('lifecycle_status')->default('DRAFT')->index();
            $table->timestamps();

            $table->unique(['encounter_id', 'document_type'], 'clinical_entry_encounter_type_unique');
            $table->index(['session_id', 'patient_id', 'encounter_id'], 'clinical_entry_context_lookup');
        });

        Schema::create('clinical_entry_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('clinical_entry_id')->constrained('clinical_entries')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('creation_intent');
            $table->string('schema_version');
            $table->json('content');
            $table->char('content_hash', 64);
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('author_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->timestamp('clinical_occurrence_at');
            $table->timestamp('recorded_at');
            $table->string('status')->default('DRAFT')->index();
            $table->text('change_reason')->nullable();
            $table->foreignId('supersedes_version_id')->nullable()->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['clinical_entry_id', 'version_number'], 'clinical_entry_version_unique');
            $table->index(['clinical_entry_id', 'status', 'version_number'], 'clinical_version_current_lookup');
        });

        Schema::create('clinical_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('clinical_entry_version_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('action')->index();
            $table->json('findings')->nullable();
            $table->text('comment')->nullable();
            $table->char('reviewed_content_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['clinical_entry_version_id', 'occurred_at'], 'clinical_review_timeline');
        });

        Schema::create('clinical_observations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_entry_version_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('author_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('category');
            $table->string('code_system');
            $table->string('code');
            $table->string('display');
            $table->string('code_version')->nullable();
            $table->string('mapping_version');
            $table->string('value_type');
            $table->decimal('value_numeric', 12, 3)->nullable();
            $table->text('value_text')->nullable();
            $table->string('value_code')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->string('unit_system')->nullable();
            $table->string('unit_code')->nullable();
            $table->string('unit_display')->nullable();
            $table->timestamp('occurrence_at');
            $table->timestamp('recorded_at');
            $table->string('status')->default('PRELIMINARY');
            $table->json('data_quality_flags')->nullable();
            $table->timestamps();

            $table->unique(['source_entry_version_id', 'code_system', 'code'], 'clinical_observation_version_code_unique');
            $table->index(['encounter_id', 'category', 'code'], 'clinical_observation_lookup');
        });

        Schema::create('allergy_assessments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_entry_version_id')->unique()->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('author_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('assessment_state');
            $table->text('details')->nullable();
            $table->timestamp('assessed_at');
            $table->timestamps();

            $table->index(['patient_id', 'encounter_id', 'assessed_at'], 'allergy_assessment_timeline');
        });

        Schema::create('clinical_conditions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_entry_version_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('author_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->text('authored_text');
            $table->string('certainty');
            $table->string('role');
            $table->string('clinical_status')->default('ACTIVE');
            $table->string('code_system')->nullable();
            $table->string('code')->nullable();
            $table->string('display')->nullable();
            $table->string('code_version')->nullable();
            $table->timestamp('onset_at')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['encounter_id', 'role', 'clinical_status'], 'clinical_condition_lookup');
        });

        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->foreignId('clinical_entry_id')->nullable()->after('encounter_id')->constrained('clinical_entries')->restrictOnDelete();
            $table->foreignId('clinical_entry_version_id')->nullable()->after('clinical_entry_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->index(['clinical_entry_id', 'clinical_entry_version_id'], 'work_task_clinical_context');
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->dropForeign(['clinical_entry_version_id']);
            $table->dropForeign(['clinical_entry_id']);
            $table->dropIndex('work_task_clinical_context');
            $table->dropColumn(['clinical_entry_version_id', 'clinical_entry_id']);
        });

        Schema::dropIfExists('clinical_conditions');
        Schema::dropIfExists('allergy_assessments');
        Schema::dropIfExists('clinical_observations');
        Schema::dropIfExists('clinical_review_actions');
        Schema::dropIfExists('clinical_entry_versions');
        Schema::dropIfExists('clinical_entries');
    }
};
