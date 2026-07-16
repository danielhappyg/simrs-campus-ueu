<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_requests', function (Blueprint $table): void {
            $table->foreignId('replaces_medication_request_id')
                ->nullable()
                ->after('source_condition_id')
                ->constrained('medication_requests')
                ->restrictOnDelete();
            $table->unsignedInteger('revision_number')->default(1)->after('sequence_number');
            $table->text('replacement_reason')->nullable()->after('indication_text');
            $table->text('cancellation_reason')->nullable()->after('replacement_reason');
        });

        Schema::create('pharmacy_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('medication_request_id')->constrained('medication_requests')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('overall_outcome')->index();
            $table->json('domain_results');
            $table->char('content_hash', 64);
            $table->foreignId('supersedes_review_id')->nullable()->constrained('pharmacy_reviews')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique(['medication_request_id', 'version_number'], 'pharmacy_review_request_version_unique');
            $table->index(['encounter_id', 'overall_outcome'], 'pharmacy_review_workflow_lookup');
        });

        Schema::create('pharmacy_interventions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('medication_request_id')->constrained('medication_requests')->restrictOnDelete();
            $table->foreignId('source_pharmacy_review_id')->constrained('pharmacy_reviews')->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('status')->index();
            $table->string('issue_category');
            $table->string('urgency');
            $table->text('question');
            $table->text('recommendation')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['medication_request_id', 'status'], 'pharmacy_intervention_request_status');
        });

        Schema::create('pharmacy_intervention_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('pharmacy_intervention_id')->constrained('pharmacy_interventions')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('author_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('message_type');
            $table->string('response_action')->nullable();
            $table->text('message_text');
            $table->foreignId('replacement_medication_request_id')
                ->nullable()
                ->constrained(
                    'medication_requests',
                    'id',
                    'pharmacy_message_replacement_request_fk',
                )
                ->restrictOnDelete();
            $table->timestamp('authored_at');
            $table->timestamps();
        });

        Schema::create('medication_stocks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->string('authored_medication');
            $table->string('form')->nullable();
            $table->string('strength')->nullable();
            $table->string('lot_number');
            $table->date('expires_on');
            $table->decimal('quantity_on_hand', 14, 3);
            $table->string('unit');
            $table->boolean('synthetic_flag')->default(true);
            $table->timestamps();

            $table->unique(['session_id', 'lot_number']);
            $table->index(['session_id', 'authored_medication', 'expires_on'], 'medication_stock_fefo_lookup');
        });

        Schema::create('medication_dispenses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('medication_request_id')->constrained('medication_requests')->restrictOnDelete();
            $table->foreignId('pharmacy_review_id')->constrained('pharmacy_reviews')->restrictOnDelete();
            $table->foreignId('medication_stock_id')->nullable()->constrained('medication_stocks')->restrictOnDelete();
            $table->string('outcome')->index();
            $table->decimal('quantity', 14, 3);
            $table->string('unit');
            $table->text('outcome_reason')->nullable();
            $table->json('content');
            $table->char('content_hash', 64);
            $table->foreignId('preparer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('preparer_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->foreignId('checker_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('checker_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->timestamp('checked_at');
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamps();

            $table->unique('medication_request_id');
        });

        Schema::create('medication_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('medication_stock_id')->constrained('medication_stocks')->restrictOnDelete();
            $table->foreignId('medication_dispense_id')->constrained('medication_dispenses')->restrictOnDelete();
            $table->string('direction');
            $table->decimal('quantity', 14, 3);
            $table->decimal('balance_before', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique('medication_dispense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_stock_movements');
        Schema::dropIfExists('medication_dispenses');
        Schema::dropIfExists('medication_stocks');
        Schema::dropIfExists('pharmacy_intervention_messages');
        Schema::dropIfExists('pharmacy_interventions');
        Schema::dropIfExists('pharmacy_reviews');

        Schema::table('medication_requests', function (Blueprint $table): void {
            $table->dropForeign(['replaces_medication_request_id']);
            $table->dropColumn([
                'replaces_medication_request_id',
                'revision_number',
                'replacement_reason',
                'cancellation_reason',
            ]);
        });
    }
};
