<?php

namespace Tests\Feature\Database;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DailyQueueAllocatorMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_populated_synthetic_encounters_are_deterministically_renumbered_per_jakarta_date(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create(['created_by_user_id' => $user->id]);
        $migration = $this->migration();
        $migration->down();

        foreach ([
            ['id' => 12, 'registered_at' => '2026-08-26 10:00:00', 'queue_number' => 9],
            ['id' => 10, 'registered_at' => '2026-08-26 08:00:00', 'queue_number' => 1],
            ['id' => 11, 'registered_at' => '2026-08-26 08:00:00', 'queue_number' => 1],
            ['id' => 13, 'registered_at' => '2026-08-27 00:00:00', 'queue_number' => null],
        ] as $row) {
            DB::table('encounters')->insert([
                'id' => $row['id'],
                'public_id' => str_pad((string) $row['id'], 26, '0', STR_PAD_LEFT),
                'patient_id' => $patient->id,
                'care_setting' => 'OUTPATIENT',
                'status' => 'REGISTERED',
                'clinic_name' => 'Poli Sintetis',
                'payer_type' => 'UMUM',
                'queue_number' => $row['queue_number'],
                'registered_at' => $row['registered_at'],
                'registered_by_user_id' => $user->id,
                'created_at' => $row['registered_at'],
                'updated_at' => $row['registered_at'],
            ]);
        }

        $migration->up();

        $this->assertSame([
            [10, '2026-08-26', 1],
            [11, '2026-08-26', 2],
            [12, '2026-08-26', 3],
            [13, '2026-08-27', 1],
        ], DB::table('encounters')
            ->orderBy('id')
            ->get(['id', 'queue_date', 'queue_number'])
            ->map(fn (object $row): array => [(int) $row->id, $row->queue_date, (int) $row->queue_number])
            ->all());
        $this->assertDatabaseHas('daily_queue_counters', ['queue_date' => '2026-08-26', 'last_number' => 3]);
        $this->assertDatabaseHas('daily_queue_counters', ['queue_date' => '2026-08-27', 'last_number' => 1]);
    }

    public function test_migration_refuses_non_synthetic_data_before_new_schema_is_created(): void
    {
        $user = User::factory()->create();
        $migration = $this->migration();
        $migration->down();
        Patient::factory()->create([
            'created_by_user_id' => $user->id,
            'is_synthetic' => false,
        ]);

        try {
            $migration->up();
            $this->fail('Migration must reject a database containing non-synthetic patient data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('non-synthetic patient data', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('daily_queue_counters'));
        $this->assertFalse(Schema::hasColumn('encounters', 'queue_date'));
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_26_000200_create_daily_queue_allocator.php');

        return $migration;
    }
}
