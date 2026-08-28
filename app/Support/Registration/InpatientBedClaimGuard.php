<?php

namespace App\Support\Registration;

use App\Models\DailyQueueCounter;
use App\Models\Encounter;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class InpatientBedClaimGuard
{
    private const GLOBAL_MUTEX_DATE = '1000-01-01';

    public function assertAvailable(string $bedCode): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inpatient bed claims require an active database transaction.');
        }

        $now = now((string) config('app.timezone', 'Asia/Jakarta'));

        // A stable row provides one database-portable mutex across dates, including claims
        // that overlap midnight. The subsequent occupancy rows remain locked until commit.
        DailyQueueCounter::query()->insertOrIgnore([
            'queue_date' => self::GLOBAL_MUTEX_DATE,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mutex = DailyQueueCounter::query()
            ->where('queue_date', self::GLOBAL_MUTEX_DATE)
            ->lockForUpdate()
            ->first();

        if (! $mutex instanceof DailyQueueCounter) {
            throw new RuntimeException('Inpatient bed claim mutex could not be locked.');
        }

        $occupied = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('bed_code', $bedCode)
            ->where('status', '!=', Encounter::STATUS_CLOSED)
            ->lockForUpdate()
            ->first(['id']) !== null;

        if ($occupied) {
            throw new InpatientBedUnavailable('Tempat tidur sudah dipakai kunjungan rawat inap aktif.');
        }
    }
}
