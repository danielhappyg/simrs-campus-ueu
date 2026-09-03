<?php

use App\Models\MedicalRecordNumberCounter;
use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        $countersTable = SchemaQualifier::table('medical_record_number_counters');

        Schema::create($countersTable, function (Blueprint $table): void {
            $table->string('scope')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        $patientsTable = SchemaQualifier::table('patients');
        if (! Schema::hasTable($patientsTable)) {
            return;
        }

        $highWatermark = 0;
        $rows = DB::table($patientsTable)->select(['medical_record_number'])->get();
        foreach ($rows as $row) {
            $mrn = (string) $row->medical_record_number;
            if (preg_match('/^[0-9]{6}$/', $mrn) !== 1) {
                continue;
            }

            $highWatermark = max($highWatermark, (int) $mrn);
        }

        if ($highWatermark < 1) {
            return;
        }

        $timestamp = now((string) config('app.timezone', 'Asia/Jakarta'));
        DB::table($countersTable)->insert([
            'scope' => MedicalRecordNumberCounter::GLOBAL_SCOPE,
            'last_number' => $highWatermark,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists(SchemaQualifier::table('medical_record_number_counters'));
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException(
                'Medical record number migration requires SIMULATION mode with synthetic-only data enforced.',
            );
        }

        $patientsTable = SchemaQualifier::table('patients');
        if (Schema::hasTable($patientsTable) && DB::table($patientsTable)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException(
                'Medical record number migration refused: non-synthetic patient data requires a separately reviewed migration plan.',
            );
        }
    }
};
