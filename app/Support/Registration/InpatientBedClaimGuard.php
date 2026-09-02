<?php

namespace App\Support\Registration;

use App\Models\Encounter;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InpatientBedClaimGuard
{
    public function __construct(private readonly CanonicalInpatientBedOperationLockCoordinator $locks) {}

    public function assertAvailable(string $bedCode, ?int $bedId = null): void
    {
        $this->locks->lockMutexes([$bedCode]);
        $this->assertAvailableAfterCanonicalLocks($bedCode, $bedId);
    }

    public function assertAvailableAfterCanonicalLocks(string $bedCode, ?int $bedId = null): void
    {
        if ($this->lockClaimsAfterCanonicalMutexes($bedCode, $bedId)->isNotEmpty()) {
            throw new InpatientBedUnavailable('Tempat tidur sudah dipakai kunjungan rawat inap aktif.');
        }
    }

    /** @return Collection<int, Encounter> */
    public function lockClaimsAfterCanonicalMutexes(string $bedCode, ?int $bedId = null): Collection
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inpatient bed claims require an active database transaction.');
        }

        return Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where(function ($query) use ($bedCode, $bedId): void {
                $query->where('bed_code', $bedCode);
                if ($bedId !== null) {
                    $query->orWhere('inpatient_bed_id', $bedId);
                }
            })
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'inpatient_bed_id', 'bed_code']);
    }
}
