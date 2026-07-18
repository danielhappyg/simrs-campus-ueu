<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outpatient_early_departures', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->unique()->constrained('encounters')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('outcome');
            $table->string('source_encounter_status');
            $table->json('source_snapshot');
            $table->char('source_snapshot_hash', 64);
            $table->text('stated_reason');
            $table->text('communication_summary');
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamps();

            $table->index(
                ['actor_assignment_id', 'occurred_at'],
                'early_departure_actor_timeline',
            );
            $table->index(
                ['session_id', 'source_encounter_status'],
                'early_departure_session_source',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outpatient_early_departures');
    }
};
