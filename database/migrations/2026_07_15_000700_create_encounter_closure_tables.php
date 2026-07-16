<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounter_closures', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('author_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('schema_version');
            $table->string('status')->index();
            $table->json('content');
            $table->char('content_hash', 64);
            $table->text('change_reason')->nullable();
            $table->foreignId('supersedes_closure_id')->nullable()->constrained('encounter_closures')->restrictOnDelete();
            $table->timestamp('clinical_occurrence_at');
            $table->timestamp('recorded_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['encounter_id', 'version_number'], 'encounter_closure_version_unique');
        });

        Schema::create('encounter_closure_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('encounter_closure_id')->constrained('encounter_closures')->restrictOnDelete();
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
        Schema::dropIfExists('encounter_closure_review_actions');
        Schema::dropIfExists('encounter_closures');
    }
};
