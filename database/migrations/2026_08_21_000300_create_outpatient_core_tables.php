<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('medical_record_number')->unique();
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->string('sex');
            $table->string('phone')->nullable();
            $table->boolean('is_synthetic')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('encounters', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('care_setting')->default('OUTPATIENT');
            $table->string('status');
            $table->string('clinic_name');
            $table->string('payer_type');
            $table->timestamp('registered_at');
            $table->foreignId('registered_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('chief_complaint')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('patient_id');
        });

        Schema::create('clinical_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entry_type');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_entries');
        Schema::dropIfExists('encounters');
        Schema::dropIfExists('patients');
    }
};
