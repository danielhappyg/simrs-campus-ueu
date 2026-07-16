<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulation_scenarios', function (Blueprint $table): void {
            $table->json('rubric_references')->nullable()->after('learning_outcomes');
        });

        Schema::create('debrief_notes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('created_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->ulid('request_key');
            $table->string('note_type');
            $table->timestamps();

            $table->unique(['encounter_id', 'request_key'], 'debrief_note_request_unique');
            $table->index(['encounter_id', 'note_type', 'created_at'], 'debrief_note_encounter_lookup');
        });

        Schema::create('debrief_note_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('debrief_note_id')->constrained('debrief_notes')->restrictOnDelete();
            $table->foreignId('authored_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->ulid('request_key');
            $table->unsignedInteger('version_number');
            $table->text('body');
            $table->string('content_hash', 64);
            $table->string('change_reason', 1000)->nullable();
            $table->timestamp('authored_at', precision: 6);

            $table->unique(['debrief_note_id', 'request_key'], 'debrief_note_version_request_unique');
            $table->unique(['debrief_note_id', 'version_number'], 'debrief_note_version_lineage_unique');
            $table->index(['debrief_note_id', 'authored_at'], 'debrief_note_version_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debrief_note_versions');
        Schema::dropIfExists('debrief_notes');

        Schema::table('simulation_scenarios', function (Blueprint $table): void {
            $table->dropColumn('rubric_references');
        });
    }
};
