<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_quality_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('checklist_version');
            $table->string('status')->index();
            $table->json('content');
            $table->char('content_hash', 64);
            $table->text('change_reason')->nullable();
            $table->foreignId('supersedes_review_id')->nullable()->constrained('record_quality_reviews')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['encounter_id', 'version_number'], 'record_quality_review_version_unique');
        });

        Schema::create('record_quality_findings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('record_quality_review_id')->constrained('record_quality_reviews')->restrictOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('code');
            $table->string('severity');
            $table->text('message');
            $table->string('affected_resource_type');
            $table->ulid('affected_resource_public_id');
            $table->unsignedInteger('affected_version_number');
            $table->char('affected_content_hash', 64);
            $table->foreignId('responsible_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->text('requested_action');
            $table->timestamps();

            $table->unique(['record_quality_review_id', 'sequence_number'], 'record_quality_finding_sequence_unique');
        });

        Schema::create('record_correction_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('record_quality_finding_id')->unique()->constrained('record_quality_findings')->restrictOnDelete();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->foreignId('responsible_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('status')->index();
            $table->text('reason');
            $table->char('requested_source_hash', 64);
            $table->timestamp('requested_at');
            $table->foreignId('response_closure_id')->nullable()->constrained('encounter_closures')->restrictOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('resolved_by_assignment_id')->nullable()->constrained('assignments')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('record_quality_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('record_quality_review_id')->constrained('record_quality_reviews')->restrictOnDelete();
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
        Schema::dropIfExists('record_quality_review_actions');
        Schema::dropIfExists('record_correction_requests');
        Schema::dropIfExists('record_quality_findings');
        Schema::dropIfExists('record_quality_reviews');
    }
};
