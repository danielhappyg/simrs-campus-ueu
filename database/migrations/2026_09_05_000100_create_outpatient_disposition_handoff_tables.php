<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outpatient_dispositions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('physician_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('medical_document_version_id')->constrained('outpatient_clinical_document_versions')->restrictOnDelete();
            $table->foreignId('prior_disposition_id')->nullable()->constrained('outpatient_dispositions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('disposition_type', 32);
            $table->json('payload');
            $table->text('correction_reason')->nullable();
            $table->string('prior_disposition_digest', 64)->nullable();
            $table->string('content_digest', 64);
            $table->timestamp('signed_at');
            $table->timestamp('created_at');
            $table->unique(['encounter_id', 'version']);
        });
        Schema::create('outpatient_inpatient_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_encounter_id')->unique()->constrained('encounters')->restrictOnDelete();
            $table->foreignId('disposition_id')->unique()->constrained('outpatient_dispositions')->restrictOnDelete();
            $table->foreignId('target_encounter_id')->unique()->constrained('encounters')->restrictOnDelete();
            $table->foreignId('registrar_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('inpatient_location_event_id')->unique()->constrained('inpatient_location_events')->restrictOnDelete();
            $table->json('bed_snapshot');
            $table->string('content_digest', 64);
            $table->timestamp('completed_at');
            $table->timestamp('created_at');
        });
        Schema::create('outpatient_admission_operation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 255);
            $table->string('payload_digest', 64);
            $table->string('result_type', 32);
            $table->ulid('result_public_id');
            $table->string('result_digest', 64);
            $table->ulid('request_correlation_id')->nullable();
            $table->timestamp('completed_at');
            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'oadm_receipt_actor_op_key_uq');
        });
        Schema::table('encounters', function (Blueprint $table): void {
            $table->string('admission_authority_type', 32)->nullable()->after('continue_from');
            $table->string('admission_authority_reference', 255)->nullable()->after('admission_authority_type');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', fn (Blueprint $table) => $table->dropColumn(['admission_authority_type', 'admission_authority_reference']));
        Schema::dropIfExists('outpatient_admission_operation_receipts');
        Schema::dropIfExists('outpatient_inpatient_handoffs');
        Schema::dropIfExists('outpatient_dispositions');
    }
};
