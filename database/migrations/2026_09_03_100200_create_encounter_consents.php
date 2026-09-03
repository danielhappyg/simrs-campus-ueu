<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounter_consents', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->longText('explainer_signature_png');
            $table->longText('patient_signature_png');
            $table->string('explainer_name')->nullable();
            $table->string('patient_or_guardian_name')->nullable();
            $table->timestamp('signed_at');
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('encounter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_consents');
    }
};
