<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_service_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('test_code');
            $table->string('test_label');
            $table->text('clinical_question')->nullable();
            $table->string('status');
            $table->timestamp('requested_at');
            $table->timestamps();

            $table->index('status');
            $table->index('encounter_id');
        });

        Schema::create('lab_diagnostic_results', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('lab_service_request_id')->constrained('lab_service_requests')->cascadeOnDelete();
            $table->foreignId('entered_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status');
            $table->text('result_text');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('lab_service_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_diagnostic_results');
        Schema::dropIfExists('lab_service_requests');
    }
};
