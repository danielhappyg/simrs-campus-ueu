<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coding_documentation_corrections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('coding_suggestion_decision_id')->unique('cdc_decision_unique');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('encounter_id');
            $table->unsignedBigInteger('source_condition_id');
            $table->unsignedBigInteger('source_entry_version_id');
            $table->unsignedBigInteger('superseded_record_quality_review_id');
            $table->unsignedBigInteger('record_quality_reviewer_assignment_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('requested_by_assignment_id');
            $table->unsignedBigInteger('responsible_user_id');
            $table->unsignedBigInteger('responsible_assignment_id');
            $table->string('status')->index();
            $table->text('reason');
            $table->char('requested_source_hash', 64);
            $table->char('requested_statement_hash', 64);
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('response_medical_version_id')->nullable();
            $table->timestamp('medical_responded_at')->nullable();
            $table->unsignedBigInteger('response_closure_id')->nullable();
            $table->timestamp('closure_responded_at')->nullable();
            $table->unsignedBigInteger('replacement_record_quality_review_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('coding_suggestion_decision_id', 'cdc_decision_fk')->references('id')->on('coding_suggestion_decisions')->restrictOnDelete();
            $table->foreign('session_id', 'cdc_session_fk')->references('id')->on('simulation_sessions')->restrictOnDelete();
            $table->foreign('patient_id', 'cdc_patient_fk')->references('id')->on('synthetic_patients')->restrictOnDelete();
            $table->foreign('encounter_id', 'cdc_encounter_fk')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('source_condition_id', 'cdc_condition_fk')->references('id')->on('clinical_conditions')->restrictOnDelete();
            $table->foreign('source_entry_version_id', 'cdc_source_version_fk')->references('id')->on('clinical_entry_versions')->restrictOnDelete();
            $table->foreign('superseded_record_quality_review_id', 'cdc_prior_rq_fk')->references('id')->on('record_quality_reviews')->restrictOnDelete();
            $table->foreign('record_quality_reviewer_assignment_id', 'cdc_rq_author_fk')->references('id')->on('assignments')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'cdc_requester_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('requested_by_assignment_id', 'cdc_requester_assignment_fk')->references('id')->on('assignments')->restrictOnDelete();
            $table->foreign('responsible_user_id', 'cdc_responsible_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('responsible_assignment_id', 'cdc_responsible_assignment_fk')->references('id')->on('assignments')->restrictOnDelete();
            $table->foreign('response_medical_version_id', 'cdc_response_medical_fk')->references('id')->on('clinical_entry_versions')->restrictOnDelete();
            $table->foreign('response_closure_id', 'cdc_response_closure_fk')->references('id')->on('encounter_closures')->restrictOnDelete();
            $table->foreign('replacement_record_quality_review_id', 'cdc_replacement_rq_fk')->references('id')->on('record_quality_reviews')->restrictOnDelete();

            $table->index(['encounter_id', 'status'], 'cdc_encounter_status_idx');
            $table->index(['responsible_assignment_id', 'status'], 'cdc_responsible_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_documentation_corrections');
    }
};
