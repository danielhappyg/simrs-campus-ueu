<?php

namespace App\Support\Registration;

use App\Models\DailyQueueCounter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use OverflowException;
use RuntimeException;

final class DailyQueueAllocator
{
    private const MAX_QUEUE_NUMBER = 2_147_483_647;

    public function allocate(CarbonInterface $registeredAt): DailyQueueAllocation
    {
        $this->assertActiveTransaction();
        $queueDate = $this->queueDate($registeredAt);
        $counter = $this->lockCounter($queueDate);

        if ($counter->last_number >= self::MAX_QUEUE_NUMBER) {
            throw new OverflowException('Daily registration queue number capacity has been reached.');
        }

        $counter->last_number++;
        $counter->save();

        return new DailyQueueAllocation($queueDate, $counter->last_number);
    }

    public function ensureHighWatermark(CarbonInterface $registeredAt, int $queueNumber): DailyQueueAllocation
    {
        $this->assertActiveTransaction();

        if ($queueNumber < 1 || $queueNumber > self::MAX_QUEUE_NUMBER) {
            throw new LogicException('Daily queue high-water mark is outside the supported range.');
        }

        $queueDate = $this->queueDate($registeredAt);
        $counter = $this->lockCounter($queueDate);

        if ($counter->last_number < $queueNumber) {
            $counter->last_number = $queueNumber;
            $counter->save();
        }

        return new DailyQueueAllocation($queueDate, $queueNumber);
    }

    private function assertActiveTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Daily queue allocation requires an active database transaction.');
        }
    }

    private function queueDate(CarbonInterface $registeredAt): string
    {
        return CarbonImmutable::instance($registeredAt)
            ->setTimezone((string) config('app.timezone', 'Asia/Jakarta'))
            ->toDateString();
    }

    private function lockCounter(string $queueDate): DailyQueueCounter
    {
        $now = now((string) config('app.timezone', 'Asia/Jakarta'));

        DailyQueueCounter::query()->insertOrIgnore([
            'queue_date' => $queueDate,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $counter = DailyQueueCounter::query()
            ->where('queue_date', $queueDate)
            ->lockForUpdate()
            ->first();

        if (! $counter instanceof DailyQueueCounter) {
            throw new RuntimeException('Daily queue counter could not be locked.');
        }

        return $counter;
    }
}
