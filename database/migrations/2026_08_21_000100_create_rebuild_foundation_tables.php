<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->timestamp('recorded_at', precision: 6)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
            $table->string('resource_version')->nullable();
            $table->string('outcome')->default('SUCCESS')->index();
            $table->string('reason')->nullable();
            $table->ulid('request_correlation_id')->nullable()->index();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->index(['resource_type', 'resource_id'], 'audit_resource_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
