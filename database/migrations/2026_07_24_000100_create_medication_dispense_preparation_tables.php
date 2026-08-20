<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_dispense_preparations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('medication_request_id')->constrained('medication_requests')->restrictOnDelete();
            $table->foreignId('pharmacy_review_id')->constrained('pharmacy_reviews')->restrictOnDelete();
            $table->foreignId('medication_stock_id')->nullable()->constrained('medication_stocks')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('outcome');
            $table->decimal('quantity', 14, 3);
            $table->string('unit');
            $table->text('outcome_reason')->nullable();
            $table->json('content');
            $table->char('content_hash', 64);
            $table->text('change_reason')->nullable();
            $table->foreignId('supersedes_preparation_id')
                ->nullable()
                ->constrained('medication_dispense_preparations', 'id', 'mdp_supersedes_fk')
                ->restrictOnDelete();
            $table->foreignId('preparer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('preparer_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->timestamps();

            $table->unique(['medication_request_id', 'version_number'], 'dispense_preparation_version_unique');
            $table->index(['encounter_id', 'prepared_at'], 'dispense_preparation_timeline');
        });

        Schema::create('medication_dispense_preparation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('medication_dispense_preparation_id')
                ->constrained('medication_dispense_preparations', 'id', 'mdpr_preparation_fk')
                ->restrictOnDelete();
            $table->string('action');
            $table->char('source_content_hash', 64);
            $table->text('comment')->nullable();
            $table->foreignId('checker_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('checker_assignment_id')->constrained('assignments', 'id', 'mdpr_checker_assignment_fk')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique('medication_dispense_preparation_id', 'dispense_preparation_review_unique');
        });

        Schema::table('medication_dispenses', function (Blueprint $table): void {
            $table->foreignId('medication_dispense_preparation_id')
                ->nullable()
                ->after('medication_request_id')
                ->constrained('medication_dispense_preparations')
                ->restrictOnDelete();
            $table->unique('medication_dispense_preparation_id', 'dispense_preparation_final_unique');
        });
    }

    public function down(): void
    {
        Schema::table('medication_dispenses', function (Blueprint $table): void {
            $table->dropUnique('dispense_preparation_final_unique');
            $table->dropConstrainedForeignId('medication_dispense_preparation_id');
        });

        Schema::dropIfExists('medication_dispense_preparation_reviews');
        Schema::dropIfExists('medication_dispense_preparations');
    }
};
