<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_procedures', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('encounter_closure_id')->constrained('encounter_closures')->restrictOnDelete();
            $table->foreignId('reason_condition_id')->nullable()->constrained('clinical_conditions')->restrictOnDelete();
            $table->foreignId('based_on_service_request_id')->nullable()->constrained('service_requests')->restrictOnDelete();
            $table->foreignId('recorder_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorder_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence_number');
            $table->string('status');
            $table->text('authored_text');
            $table->timestamp('performed_start_at');
            $table->timestamp('performed_end_at')->nullable();
            $table->string('performer_text');
            $table->string('body_site_text')->nullable();
            $table->string('outcome_text')->nullable();
            $table->text('note')->nullable();
            $table->char('content_hash', 64);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['encounter_closure_id', 'sequence_number'], 'clinical_procedure_closure_sequence_unique');
            $table->index(['encounter_id', 'status', 'performed_start_at'], 'clinical_procedure_encounter_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_procedures');
    }
};
