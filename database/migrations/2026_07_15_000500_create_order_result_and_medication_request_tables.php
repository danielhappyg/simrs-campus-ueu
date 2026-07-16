<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('source_entry_version_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('source_condition_id')->nullable()->constrained('clinical_conditions')->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requester_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('request_type');
            $table->string('authored_service');
            $table->text('clinical_question');
            $table->string('priority')->default('ROUTINE');
            $table->string('status')->default('DRAFT')->index();
            $table->timestamp('authored_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_entry_version_id', 'sequence_number'], 'service_request_source_sequence_unique');
            $table->index(['encounter_id', 'status', 'request_type'], 'service_request_workflow_lookup');
        });

        Schema::create('medication_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('source_entry_version_id')->constrained('clinical_entry_versions')->restrictOnDelete();
            $table->foreignId('source_condition_id')->nullable()->constrained('clinical_conditions')->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requester_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('authored_medication');
            $table->string('form')->nullable();
            $table->string('strength')->nullable();
            $table->decimal('dose_value', 12, 3);
            $table->string('dose_unit');
            $table->string('route');
            $table->string('frequency');
            $table->string('duration');
            $table->decimal('quantity_value', 12, 3);
            $table->string('quantity_unit');
            $table->text('directions');
            $table->text('indication_text')->nullable();
            $table->string('status')->default('DRAFT')->index();
            $table->timestamp('authored_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_entry_version_id', 'sequence_number'], 'medication_request_source_sequence_unique');
            $table->index(['encounter_id', 'status'], 'medication_request_workflow_lookup');
        });

        Schema::create('diagnostic_results', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('service_request_id')->constrained('service_requests')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->index();
            $table->string('report_code');
            $table->string('report_display');
            $table->json('content');
            $table->char('content_hash', 64);
            $table->foreignId('performer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('performer_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->timestamp('issued_at');
            $table->foreignId('supersedes_result_id')->nullable()->constrained('diagnostic_results')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['service_request_id', 'version_number'], 'diagnostic_result_request_version_unique');
            $table->index(['service_request_id', 'status', 'version_number'], 'diagnostic_result_current_lookup');
        });

        Schema::create('result_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('diagnostic_result_id')->constrained('diagnostic_results')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('outcome')->default('ACKNOWLEDGED');
            $table->text('comment')->nullable();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['diagnostic_result_id', 'actor_assignment_id'], 'result_ack_result_actor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_acknowledgements');
        Schema::dropIfExists('diagnostic_results');
        Schema::dropIfExists('medication_requests');
        Schema::dropIfExists('service_requests');
    }
};
