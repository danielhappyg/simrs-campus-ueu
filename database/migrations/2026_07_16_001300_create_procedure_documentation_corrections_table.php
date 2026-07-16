<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedure_documentation_corrections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('coding_suggestion_decision_id')->unique('pdc_decision_unique');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('encounter_id');
            $table->unsignedBigInteger('source_procedure_id');
            $table->unsignedBigInteger('source_closure_id');
            $table->unsignedBigInteger('superseded_record_quality_review_id');
            $table->unsignedBigInteger('record_quality_reviewer_assignment_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('requested_by_assignment_id');
            $table->unsignedBigInteger('responsible_user_id');
            $table->unsignedBigInteger('responsible_assignment_id');
            $table->string('status')->index();
            $table->text('reason');
            $table->char('requested_source_hash', 64);
            $table->char('requested_closure_hash', 64);
            $table->char('requested_statement_hash', 64);
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('response_closure_id')->nullable();
            $table->timestamp('closure_responded_at')->nullable();
            $table->unsignedBigInteger('replacement_record_quality_review_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('coding_suggestion_decision_id', 'pdc_decision_fk')->references('id')->on('coding_suggestion_decisions')->restrictOnDelete();
            $table->foreign('session_id', 'pdc_session_fk')->references('id')->on('simulation_sessions')->restrictOnDelete();
            $table->foreign('patient_id', 'pdc_patient_fk')->references('id')->on('synthetic_patients')->restrictOnDelete();
            $table->foreign('encounter_id', 'pdc_encounter_fk')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('source_procedure_id', 'pdc_source_procedure_fk')->references('id')->on('clinical_procedures')->restrictOnDelete();
            $table->foreign('source_closure_id', 'pdc_source_closure_fk')->references('id')->on('encounter_closures')->restrictOnDelete();
            $table->foreign('superseded_record_quality_review_id', 'pdc_prior_rq_fk')->references('id')->on('record_quality_reviews')->restrictOnDelete();
            $table->foreign('record_quality_reviewer_assignment_id', 'pdc_rq_author_fk')->references('id')->on('assignments')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'pdc_requester_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('requested_by_assignment_id', 'pdc_requester_assignment_fk')->references('id')->on('assignments')->restrictOnDelete();
            $table->foreign('responsible_user_id', 'pdc_responsible_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('responsible_assignment_id', 'pdc_responsible_assignment_fk')->references('id')->on('assignments')->restrictOnDelete();
            $table->foreign('response_closure_id', 'pdc_response_closure_fk')->references('id')->on('encounter_closures')->restrictOnDelete();
            $table->foreign('replacement_record_quality_review_id', 'pdc_replacement_rq_fk')->references('id')->on('record_quality_reviews')->restrictOnDelete();

            $table->index(['encounter_id', 'status'], 'pdc_encounter_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_documentation_corrections');
    }
};
