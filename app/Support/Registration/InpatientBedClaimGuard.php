<?php

namespace App\Support\Registration;

use App\Models\Encounter;
use App\Models\InpatientBedClaimMutex;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class InpatientBedClaimGuard
{
    public function assertAvailable(string $bedCode): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inpatient bed claims require an active database transaction.');
        }

        $now = now((string) config('app.timezone', 'Asia/Jakarta'));

        // Each bed has its own stable mutex row. Concurrent claims for the same bed
        // serialize until commit, while unrelated beds can be admitted independently.
        InpatientBedClaimMutex::query()->insertOrIgnore([
            'bed_code' => $bedCode,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mutex = InpatientBedClaimMutex::query()
            ->whereKey($bedCode)
            ->lockForUpdate()
            ->first();

        if (! $mutex instanceof InpatientBedClaimMutex) {
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
