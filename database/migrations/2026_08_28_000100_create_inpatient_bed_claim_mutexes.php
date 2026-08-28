<?php

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENCOUNTER_BED_STATUS_INDEX = 'encounters_care_bed_status_idx';

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        Schema::create(SchemaQualifier::table('inpatient_bed_claim_mutexes'), function (Blueprint $table): void {
            $table->string('bed_code', 64)->primary();
            $table->timestamps();
        });

        Schema::table(SchemaQualifier::table('encounters'), function (Blueprint $table): void {
            $table->index(
                ['care_setting', 'bed_code', 'status'],
                self::ENCOUNTER_BED_STATUS_INDEX,
            );
        });
    }

    public function down(): void
    {
        $encountersTable = SchemaQualifier::table('encounters');

        if (Schema::hasTable($encountersTable)
            && Schema::hasIndex($encountersTable, self::ENCOUNTER_BED_STATUS_INDEX)) {
            Schema::table($encountersTable, function (Blueprint $table): void {
                $table->dropIndex(self::ENCOUNTER_BED_STATUS_INDEX);
            });
        }

        Schema::dropIfExists(SchemaQualifier::table('inpatient_bed_claim_mutexes'));
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException(
                'Inpatient bed claim mutex migration requires SIMULATION mode with synthetic-only data enforced.',
            );
        }

        $patientsTable = SchemaQualifier::table('patients');
        if (Schema::hasTable($patientsTable) && DB::table($patientsTable)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException(
                'Inpatient bed claim mutex migration refused: non-synthetic patient data requires a separately reviewed migration plan.',
            );
        }
    }
};
