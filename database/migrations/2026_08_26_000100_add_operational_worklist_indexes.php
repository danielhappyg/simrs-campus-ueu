<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENCOUNTER_INDEX = 'encounters_care_registered_id_idx';

    private const LAB_REQUEST_INDEX = 'lab_requests_status_requested_id_idx';

    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->index(['care_setting', 'registered_at', 'id'], self::ENCOUNTER_INDEX);
        });

        Schema::table('lab_service_requests', function (Blueprint $table): void {
            $table->index(['status', 'requested_at', 'id'], self::LAB_REQUEST_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('lab_service_requests', function (Blueprint $table): void {
            $table->dropIndex(self::LAB_REQUEST_INDEX);
        });

        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropIndex(self::ENCOUNTER_INDEX);
        });
    }
};
