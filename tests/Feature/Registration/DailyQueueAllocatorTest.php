<?php

namespace Tests\Feature\Registration;

use App\Models\DailyQueueCounter;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Support\Registration\DailyQueueAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OverflowException;
use RuntimeException;
use Tests\TestCase;

class DailyQueueAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_daily_sequence_is_shared_across_outpatient_emergency_and_inpatient(): void
    {
        $registeredAt = CarbonImmutable::parse('2026-08-26 08:00:00', 'Asia/Jakarta');
        $user = User::factory()->create();
        $patient = Patient::factory()->create(['created_by_user_id' => $user->id]);

        DB::transaction(function () use ($registeredAt, $user, $patient): void {
            foreach ([
                Encounter::CARE_SETTING_OUTPATIENT,
                Encounter::CARE_SETTING_EMERGENCY,
                Encounter::CARE_SETTING_INPATIENT,
            ] as $index => $careSetting) {
                $allocation = $this->allocator()->allocate($registeredAt->addMinutes($index));

                Encounter::factory()->create([
                    'patient_id' => $patient->id,
                    'registered_by_user_id' => $user->id,
                    'care_setting' => $careSetting,
                    'queue_date' => $allocation->queueDate,
                    'queue_number' => $allocation->queueNumber,
                    'registered_at' => $registeredAt->addMinutes($index),
                ]);
            }
        });

        $this->assertSame([1, 2, 3], Encounter::query()->orderBy('queue_number')->pluck('queue_number')->all());
        $this->assertDatabaseHas('daily_queue_counters', [
            'queue_date' => '2026-08-26',
            'last_number' => 3,
        ]);
    }

    public function test_next_jakarta_date_starts_at_one_and_same_number_is_allowed_on_a_different_date(): void
    {
        $allocations = DB::transaction(fn (): array => [
            $this->allocator()->allocate(CarbonImmutable::parse('2026-08-26 23:59:59', 'Asia/Jakarta')),
            $this->allocator()->allocate(CarbonImmutable::parse('2026-08-27 00:00:00', 'Asia/Jakarta')),
        ]);

        $this->assertSame('2026-08-26', $allocations[0]->queueDate);
        $this->assertSame(1, $allocations[0]->queueNumber);
        $this->assertSame('2026-08-27', $allocations[1]->queueDate);
        $this->assertSame(1, $allocations[1]->queueNumber);
    }

    public function test_utc_instants_are_partitioned_by_the_jakarta_calendar_boundary(): void
    {
        $allocations = DB::transaction(fn (): array => [
            $this->allocator()->allocate(CarbonImmutable::parse('2026-08-26T16:59:59+00:00')),
            $this->allocator()->allocate(CarbonImmutable::parse('2026-08-26T17:00:00+00:00')),
        ]);

        $this->assertSame(['2026-08-26', '2026-08-27'], array_map(
            fn ($allocation): string => $allocation->queueDate,
            $allocations,
        ));
        $this->assertSame([1, 1], array_map(
            fn ($allocation): int => $allocation->queueNumber,
            $allocations,
        ));
    }

    public function test_rolled_back_allocation_is_reused_by_the_next_successful_transaction(): void
    {
        $registeredAt = CarbonImmutable::parse('2026-08-26 08:00:00', 'Asia/Jakarta');

        try {
            DB::transaction(function () use ($registeredAt): void {
                $allocation = $this->allocator()->allocate($registeredAt);
                $this->assertSame(1, $allocation->queueNumber);

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('daily_queue_counters', 0);

        $committed = DB::transaction(fn () => $this->allocator()->allocate($registeredAt));

        $this->assertSame(1, $committed->queueNumber);
        $this->assertDatabaseHas('daily_queue_counters', [
            'queue_date' => '2026-08-26',
            'last_number' => 1,
        ]);
    }

    public function test_database_rejects_duplicate_number_for_the_same_queue_date(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create(['created_by_user_id' => $user->id]);

        Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $user->id,
            'queue_date' => '2026-08-26',
            'queue_number' => 7,
        ]);

        $this->expectException(QueryException::class);

        Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $user->id,
            'queue_date' => '2026-08-26',
            'queue_number' => 7,
        ]);
    }

    public function test_database_allows_the_same_number_on_different_queue_dates(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create(['created_by_user_id' => $user->id]);

        foreach (['2026-08-26', '2026-08-27'] as $queueDate) {
            Encounter::factory()->create([
                'patient_id' => $patient->id,
                'registered_by_user_id' => $user->id,
                'queue_date' => $queueDate,
                'queue_number' => 7,
            ]);
        }

        $this->assertDatabaseCount('encounters', 2);
    }

    public function test_high_watermark_sync_never_decrements_a_counter(): void
    {
        $registeredAt = CarbonImmutable::parse('2026-08-26 08:00:00', 'Asia/Jakarta');

        DB::transaction(function () use ($registeredAt): void {
            $this->allocator()->ensureHighWatermark($registeredAt, 12);
            $this->allocator()->ensureHighWatermark($registeredAt, 4);
        });

        $this->assertDatabaseHas('daily_queue_counters', [
            'queue_date' => '2026-08-26',
            'last_number' => 12,
        ]);
    }

    public function test_allocator_fails_before_integer_capacity_is_exceeded(): void
    {
        DailyQueueCounter::query()->create([
            'queue_date' => '2026-08-26',
            'last_number' => 2_147_483_647,
        ]);
        $this->assertSame(
            2_147_483_647,
            DailyQueueCounter::query()->where('queue_date', '2026-08-26')->value('last_number'),
        );

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessage('capacity');

        DB::transaction(fn () => $this->allocator()->allocate(
            CarbonImmutable::parse('2026-08-26 08:00:00', 'Asia/Jakarta'),
        ));
    }

    private function allocator(): DailyQueueAllocator
    {
        return $this->app->make(DailyQueueAllocator::class);
    }
}
