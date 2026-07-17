<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outpatient_safety_dispositions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('encounter_id')->unique()->constrained('encounters')->restrictOnDelete();
            $table->foreignId('source_clinical_entry_version_id')
                ->constrained('clinical_entry_versions')
                ->restrictOnDelete();
            $table->char('source_content_hash', 64);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('outcome');
            $table->text('rationale');
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamps();

            $table->index(
                ['source_clinical_entry_version_id', 'source_content_hash'],
                'safety_disposition_source_lookup',
            );
            $table->index(
                ['actor_assignment_id', 'occurred_at'],
                'safety_disposition_actor_timeline',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outpatient_safety_dispositions');
    }
};
