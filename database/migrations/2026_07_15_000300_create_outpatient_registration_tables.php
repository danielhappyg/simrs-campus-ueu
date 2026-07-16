<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_locations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->default('OUTPATIENT_CLINIC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('synthetic_patients', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->rawColumn('synthetic_flag', 'BOOLEAN NOT NULL DEFAULT TRUE CHECK (synthetic_flag = TRUE)');
            $table->string('fixture_source');
            $table->string('full_name');
            $table->date('birth_date');
            $table->string('administrative_sex');
            $table->json('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('religion')->nullable();
            $table->string('occupation')->nullable();
            $table->string('education')->nullable();
            $table->string('marital_status')->nullable();
            $table->boolean('deceased_flag')->default(false);
            $table->string('record_status')->default('ACTIVE')->index();
            $table->timestamps();

            $table->index(['session_id', 'full_name', 'birth_date'], 'synthetic_patient_search');
        });

        Schema::create('patient_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->string('type');
            $table->string('system');
            $table->string('value');
            $table->rawColumn('synthetic_flag', 'BOOLEAN NOT NULL DEFAULT TRUE CHECK (synthetic_flag = TRUE)');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status')->default('ACTIVE')->index();
            $table->timestamps();

            $table->unique(['system', 'value']);
            $table->index(['patient_id', 'type', 'status'], 'patient_identifier_lookup');
        });

        Schema::create('appointment_registrations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('request_key');
            $table->foreignId('session_id')->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('service_locations')->restrictOnDelete();
            $table->foreignId('registered_by_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('appointment_code');
            $table->timestamp('scheduled_at');
            $table->string('visit_reason');
            $table->string('visit_source')->default('SCHEDULED');
            $table->string('coverage_status')->default('SIMULATION_SELF_PAY');
            $table->string('identity_verification_method');
            $table->string('consent_version');
            $table->timestamp('consent_acknowledged_at');
            $table->string('duplicate_decision')->nullable();
            $table->string('duplicate_reason')->nullable();
            $table->string('status')->default('BOOKED')->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'request_key'], 'appointment_request_unique');
            $table->unique(['session_id', 'appointment_code'], 'appointment_code_unique');
            $table->index(['session_id', 'status', 'scheduled_at'], 'appointment_queue_lookup');
        });

        Schema::create('encounters', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('session_id')->unique()->constrained('simulation_sessions')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('appointment_registration_id')->unique()->constrained('appointment_registrations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('service_locations')->restrictOnDelete();
            $table->string('encounter_number')->unique();
            $table->string('class')->default('AMBULATORY');
            $table->string('service_type_code');
            $table->string('service_type_display');
            $table->string('status')->default('PLANNED')->index();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('disposition')->nullable();
            $table->timestamp('closure_requested_at')->nullable();
            $table->timestamp('clinically_closed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->rawColumn('environment_mode', "VARCHAR(32) NOT NULL DEFAULT 'SIMULATION' CHECK (environment_mode = 'SIMULATION')");
            $table->timestamps();

            $table->index(['session_id', 'patient_id', 'status'], 'encounter_context_lookup');
        });

        Schema::create('queue_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('service_locations')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('ticket_number');
            $table->string('status')->index();
            $table->string('reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['encounter_id', 'ticket_number']);
            $table->index(['location_id', 'status', 'started_at'], 'public_queue_lookup');
        });

        Schema::create('encounter_transitions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $table->foreignId('actor_assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at', precision: 6);

            $table->index(['encounter_id', 'occurred_at'], 'encounter_transition_timeline');
        });

        Schema::table('assignments', function (Blueprint $table): void {
            $table->foreignId('patient_id')->nullable()->after('capabilities')->constrained('synthetic_patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->after('patient_id')->constrained('encounters')->restrictOnDelete();
            $table->index(['session_id', 'patient_id', 'encounter_id'], 'assignment_case_scope');
        });

        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->foreignId('encounter_id')->nullable()->after('assignment_id')->constrained('encounters')->restrictOnDelete();
            $table->index(['assignment_id', 'encounter_id', 'task_type'], 'work_task_case_lookup');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->foreignId('patient_id')->nullable()->after('session_id')->constrained('synthetic_patients')->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->after('patient_id')->constrained('encounters')->nullOnDelete();
            $table->index(['session_id', 'patient_id', 'encounter_id'], 'audit_case_context');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_case_context');
            $table->dropConstrainedForeignId('encounter_id');
            $table->dropConstrainedForeignId('patient_id');
        });

        Schema::table('work_tasks', function (Blueprint $table): void {
            $table->dropIndex('work_task_case_lookup');
            $table->dropConstrainedForeignId('encounter_id');
        });

        Schema::table('assignments', function (Blueprint $table): void {
            $table->dropIndex('assignment_case_scope');
            $table->dropConstrainedForeignId('encounter_id');
            $table->dropConstrainedForeignId('patient_id');
        });

        Schema::dropIfExists('encounter_transitions');
        Schema::dropIfExists('queue_events');
        Schema::dropIfExists('encounters');
        Schema::dropIfExists('appointment_registrations');
        Schema::dropIfExists('patient_identifiers');
        Schema::dropIfExists('synthetic_patients');
        Schema::dropIfExists('service_locations');
    }
};
