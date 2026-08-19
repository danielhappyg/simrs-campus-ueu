<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_claim_cases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->unique()->constrained('encounters')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('status')->index();
            $table->string('compatibility_profile');
            $table->string('synthetic_sep')->unique();
            $table->json('source_snapshot');
            $table->char('source_snapshot_hash', 64);
            $table->string('grouper_code')->nullable();
            $table->string('grouper_description')->nullable();
            $table->unsignedBigInteger('simulated_tariff')->nullable();
            $table->timestamp('data_staged_at')->nullable();
            $table->timestamp('grouped_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('submission_simulated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('e_claim_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('e_claim_case_id')->constrained('e_claim_cases')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence_number');
            $table->string('action');
            $table->string('method');
            $table->json('request_payload');
            $table->json('response_payload');
            $table->char('request_hash', 64);
            $table->char('response_hash', 64);
            $table->unsignedSmallInteger('response_code');
            $table->string('transport_state');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['e_claim_case_id', 'sequence_number'], 'e_claim_event_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_claim_events');
        Schema::dropIfExists('e_claim_cases');
    }
};
