<?php

use App\Support\Database\SchemaQualifier;
use App\Support\Emergency\SqliteEmergencyHandoffGraphGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENCOUNTER_QUEUE_UNIQUE = 'encounters_queue_date_number_unique';

    public function up(): void
    {
        $this->assertSyntheticMigrationBoundary();

        $encountersTable = SchemaQualifier::table('encounters');
        $countersTable = SchemaQualifier::table('daily_queue_counters');
        $assignments = $this->plannedAssignments($encountersTable);

        Schema::create($countersTable, function (Blueprint $table): void {
            $table->date('queue_date')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        SqliteEmergencyHandoffGraphGuard::aroundEncounterTableRebuild(
            fn () => Schema::table($encountersTable, function (Blueprint $table): void {
                $table->date('queue_date')->nullable()->after('queue_number');
            }),
        );

        DB::transaction(function () use ($assignments, $encountersTable, $countersTable): void {
            /** @var array<string, int> $highWatermarks */
            $highWatermarks = [];

            foreach ($assignments as $assignment) {
                DB::table($encountersTable)
                    ->where('id', $assignment['id'])
                    ->update([
                        'queue_date' => $assignment['queue_date'],
                        'queue_number' => $assignment['queue_number'],
                    ]);

                $highWatermarks[$assignment['queue_date']] = $assignment['queue_number'];
            }

            $timestamp = now((string) config('app.timezone', 'Asia/Jakarta'));
            foreach ($highWatermarks as $queueDate => $lastNumber) {
                DB::table($countersTable)->insert([
                    'queue_date' => $queueDate,
                    'last_number' => $lastNumber,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        });

        SqliteEmergencyHandoffGraphGuard::aroundEncounterTableRebuild(
            fn () => Schema::table($encountersTable, function (Blueprint $table): void {
                $table->date('queue_date')->nullable(false)->change();
                $table->unique(['queue_date', 'queue_number'], self::ENCOUNTER_QUEUE_UNIQUE);
            }),
        );
    }

    public function down(): void
    {
        $encountersTable = SchemaQualifier::table('encounters');

        SqliteEmergencyHandoffGraphGuard::aroundEncounterTableRebuild(
            fn () => Schema::table($encountersTable, function (Blueprint $table): void {
                $table->dropUnique(self::ENCOUNTER_QUEUE_UNIQUE);
                $table->dropColumn('queue_date');
            }),
        );

        Schema::dropIfExists(SchemaQualifier::table('daily_queue_counters'));
    }

    private function assertSyntheticMigrationBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException(
                'Daily queue migration requires SIMULATION mode with synthetic-only data enforced.',
            );
        }

        $patientsTable = SchemaQualifier::table('patients');
        if (Schema::hasTable($patientsTable) && DB::table($patientsTable)->where('is_synthetic', false)->exists()) {
            throw new RuntimeException(
                'Daily queue migration refused: non-synthetic patient data requires a separately reviewed migration plan.',
            );
        }
    }

    /**
     * @return list<array{id: int, queue_date: string, queue_number: int}>
     */
    private function plannedAssignments(string $encountersTable): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Jakarta');
        $nextByDate = [];
        $assignments = [];

        $rows = DB::table($encountersTable)
            ->select(['id', 'registered_at'])
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($row->registered_at === null) {
                throw new RuntimeException('Daily queue migration refused: encounter has no registration timestamp.');
            }

            $queueDate = CarbonImmutable::parse((string) $row->registered_at, $timezone)
                ->setTimezone($timezone)
                ->toDateString();
            $queueNumber = ($nextByDate[$queueDate] ?? 0) + 1;
            $nextByDate[$queueDate] = $queueNumber;

            $assignments[] = [
                'id' => (int) $row->id,
                'queue_date' => $queueDate,
                'queue_number' => $queueNumber,
            ];
        }

        return $assignments;
    }
};
