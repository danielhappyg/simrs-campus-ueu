<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code');
            $table->string('title');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('DRAFT')->index();
            $table->json('learning_outcomes')->nullable();
            $table->json('fixture_spec')->nullable();
            $table->string('ruleset_version')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        Schema::create('simulation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('scenario_id')->constrained('simulation_scenarios')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('course_code');
            $table->string('cohort_code');
            $table->string('environment_mode')->default('SIMULATION');
            $table->string('status')->default('SCHEDULED')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('facilitator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_session_id')->nullable()->constrained('simulation_sessions')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('program')->index();
            $table->string('application_role')->index();
            $table->json('capabilities');
            $table->foreignId('supervisor_assignment_id')->nullable()->constrained('assignments')->nullOnDelete();
            $table->timestamp('active_from');
            $table->timestamp('active_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'user_id', 'program', 'application_role'], 'assignment_context_unique');
            $table->index(['user_id', 'active_from', 'active_until', 'revoked_at'], 'assignment_active_lookup');
        });

        Schema::create('work_tasks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->string('task_type')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('READY')->index();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->string('source_program')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assignment_id', 'status', 'available_at'], 'work_queue_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_tasks');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('simulation_sessions');
        Schema::dropIfExists('simulation_scenarios');
    }
};
