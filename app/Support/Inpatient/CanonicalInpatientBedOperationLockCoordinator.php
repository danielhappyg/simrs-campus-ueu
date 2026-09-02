<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientBedClaimMutex;
use App\Models\InpatientPatientClaimMutex;
use App\Models\InpatientWard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class CanonicalInpatientBedOperationLockCoordinator
{
    /** @param list<int> $patientIds */
    public function lockPatientClaimMutexes(array $patientIds): void
    {
        $this->assertTransaction();
        $ids = array_values(array_unique(array_map(static fn (int $id): int => $id, $patientIds)));
        sort($ids, SORT_NUMERIC);
        $now = now((string) config('app.timezone', 'Asia/Jakarta'));
        foreach ($ids as $id) {
            if ($id < 1) {
                throw new LogicException('Canonical inpatient patient claim locking requires positive patient IDs.');
            }
            InpatientPatientClaimMutex::query()->insertOrIgnore(['patient_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($ids as $id) {
            if (! InpatientPatientClaimMutex::query()->whereKey($id)->lockForUpdate()->first()) {
                throw new RuntimeException('Inpatient patient claim mutex could not be locked.');
            }
        }
    }

    /** @param list<string> $bedCodes */
    public function lockMutexes(array $bedCodes): void
    {
        $this->assertTransaction();
        $codes = array_values(array_unique(array_map(static fn (string $code): string => trim($code), $bedCodes)));
        sort($codes, SORT_STRING);
        $now = now((string) config('app.timezone', 'Asia/Jakarta'));
        foreach ($codes as $code) {
            InpatientBedClaimMutex::query()->insertOrIgnore(['bed_code' => $code, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($codes as $code) {
            if (! InpatientBedClaimMutex::query()->whereKey($code)->lockForUpdate()->first()) {
                throw new RuntimeException('Inpatient bed operation mutex could not be locked.');
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Encounter>
     */
    public function lockEncounters(array $ids): Collection
    {
        $this->assertTransaction();
        sort($ids, SORT_NUMERIC);

        return Encounter::query()->whereKey(array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, InpatientWard>
     */
    public function lockWards(array $ids): Collection
    {
        $this->assertTransaction();
        sort($ids, SORT_NUMERIC);

        return InpatientWard::query()->whereKey(array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, InpatientBed>
     */
    public function lockBeds(array $ids): Collection
    {
        $this->assertTransaction();
        sort($ids, SORT_NUMERIC);

        return InpatientBed::query()->whereKey(array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function assertTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Canonical inpatient bed locking requires an active database transaction.');
        }
    }
}
