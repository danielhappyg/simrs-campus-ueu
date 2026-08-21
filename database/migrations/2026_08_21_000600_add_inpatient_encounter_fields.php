<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->string('ward_name')->nullable()->after('accident_type');
            $table->string('ward_class')->nullable()->after('ward_name');
            $table->string('bed_code')->nullable()->after('ward_class');
            $table->string('continue_from')->nullable()->after('bed_code');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropColumn(['ward_name', 'ward_class', 'bed_code', 'continue_from']);
        });
    }
};
