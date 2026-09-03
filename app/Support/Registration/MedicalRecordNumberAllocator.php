<?php

namespace App\Support\Registration;

use App\Models\MedicalRecordNumberCounter;
use Illuminate\Support\Facades\DB;
use LogicException;
use OverflowException;

final class MedicalRecordNumberAllocator
{
    public function allocate(): MedicalRecordNumber
    {
        $this->assertActiveTransaction();
        $counter = $this->lockCounter();

        if ($counter->last_number >= MedicalRecordNumber::MAX) {
            throw new OverflowException('Medical record number capacity has been reached.');
        }

        $counter->last_number++;
        $counter->save();

        return MedicalRecordNumber::fromSequence($counter->last_number);
    }

    public function ensureHighWatermark(int $number): MedicalRecordNumber
    {
        $this->assertActiveTransaction();

        if ($number < 1 || $number > MedicalRecordNumber::MAX) {
            throw new LogicException('Medical record number high-water mark is outside the supported range.');
        }

        $mrn = MedicalRecordNumber::fromSequence($number);
        $counter = $this->lockCounter();

        if ($counter->last_number < $number) {
            $counter->last_number = $number;
            $counter->save();
        }

        return $mrn;
    }

    private function assertActiveTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Medical record number allocation requires an active database transaction.');
        }
    }

    private function lockCounter(): MedicalRecordNumberCounter
    {
        $now = now((string) config('app.timezone', 'Asia/Jakarta'));

        MedicalRecordNumberCounter::query()->insertOrIgnore([
            'scope' => MedicalRecordNumberCounter::GLOBAL_SCOPE,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $counter = MedicalRecordNumberCounter::query()
            ->where('scope', MedicalRecordNumberCounter::GLOBAL_SCOPE)
            ->lockForUpdate()
            ->first();

        if (! $counter instanceof MedicalRecordNumberCounter) {
            throw new RuntimeException('Medical record number counter could not be locked.');
        }

        return $counter;
    }
}
