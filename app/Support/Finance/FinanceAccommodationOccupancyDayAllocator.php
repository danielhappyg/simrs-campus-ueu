<?php

namespace App\Support\Finance;

use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientDischarge;
use App\Models\InpatientLocationEvent;
use Carbon\CarbonInterface;

final class FinanceAccommodationOccupancyDayAllocator
{
    public const OPEN_INTERVAL = 'INTERVAL_MASIH_TERBUKA';

    public const INCOMPLETE_HISTORY = 'RIWAYAT_LOKASI_TIDAK_LENGKAP';

    public const CORRUPT_EVIDENCE = 'BUKTI_TIDAK_KONSISTEN';

    public function allocate(Encounter $encounter): FinanceAccommodationOccupancyPlan
    {
        if ($encounter->care_setting !== Encounter::CARE_SETTING_INPATIENT) {
            return new FinanceAccommodationOccupancyPlan([], self::CORRUPT_EVIDENCE);
        }
        $hasCancellation = EncounterCancellation::query()->where('encounter_id', $encounter->id)->exists();
        if ($hasCancellation !== ($encounter->status === Encounter::STATUS_CANCELLED)) {
            return new FinanceAccommodationOccupancyPlan([], self::CORRUPT_EVIDENCE);
        }
        if ($hasCancellation) {
            return new FinanceAccommodationOccupancyPlan([]);
        }
        $events = InpatientLocationEvent::query()->where('encounter_id', $encounter->id)
            ->orderBy('sequence')->orderBy('id')->get();
        if ($events->isEmpty()) {
            return new FinanceAccommodationOccupancyPlan([], self::INCOMPLETE_HISTORY);
        }
        if ($events->contains(fn (InpatientLocationEvent $event): bool => ! $event->hasRequiredBedVersionProvenance())) {
            return new FinanceAccommodationOccupancyPlan([], self::INCOMPLETE_HISTORY);
        }
        $dischargeQuery = InpatientDischarge::query()->where('encounter_id', $encounter->id);
        if ((clone $dischargeQuery)->count() > 1) {
            return new FinanceAccommodationOccupancyPlan([], self::CORRUPT_EVIDENCE);
        }
        $discharge = (clone $dischargeQuery)->exists()
            ? $dischargeQuery->orderBy('id')->firstOrFail()
            : null;

        $intervals = [];
        foreach ($events->values() as $index => $event) {
            if (! $this->validEvent($event, $index, $events->get($index - 1), $encounter)) {
                return new FinanceAccommodationOccupancyPlan([], self::CORRUPT_EVIDENCE);
            }
            $next = $events->get($index + 1);
            $closing = $next instanceof InpatientLocationEvent ? $next : $discharge;
            $end = $next instanceof InpatientLocationEvent ? $next->occurred_at : $discharge?->discharged_at;
            if ($end !== null && ! $end->greaterThan($event->occurred_at)) {
                return new FinanceAccommodationOccupancyPlan([], self::CORRUPT_EVIDENCE);
            }
            $intervals[] = new FinanceAccommodationOccupancyInterval(
                $event,
                $closing,
                $event->occurred_at->copy(),
                $end?->copy(),
            );
        }

        $last = $events->last();
        if ($discharge !== null && ! $this->validDischarge($discharge, $last)) {
            return new FinanceAccommodationOccupancyPlan([], self::CORRUPT_EVIDENCE);
        }

        $horizon = $discharge === null ? $last->occurred_at : $discharge->discharged_at;
        $days = $this->allocateClosedIntervals($intervals, $horizon);

        return new FinanceAccommodationOccupancyPlan($days, $discharge === null ? self::OPEN_INTERVAL : null);
    }

    /**
     * Pure deterministic seam used by retained-evidence verification and boundary tests.
     *
     * @param  list<FinanceAccommodationOccupancyInterval>  $intervals
     * @return list<FinanceAccommodationOccupancyDay>
     */
    public function allocateClosedIntervals(array $intervals, CarbonInterface $horizon): array
    {
        if ($intervals === []) {
            return [];
        }
        $start = $intervals[0]->startsAt;
        $days = [];
        for ($date = $start->copy()->startOfDay(); $date->lessThanOrEqualTo($horizon->copy()->startOfDay()); $date = $date->addDay()) {
            $anchor = $date->isSameDay($start) ? $start->copy() : $date->copy();
            if (! $anchor->lessThan($horizon)) {
                continue;
            }
            foreach ($intervals as $interval) {
                if ($interval->endsAt !== null && ! $anchor->lessThan($interval->startsAt) && $anchor->lessThan($interval->endsAt)) {
                    $days[] = new FinanceAccommodationOccupancyDay($date->format('Y-m-d'), $anchor, $interval);
                    break;
                }
            }
        }

        return $days;
    }

    private function validEvent(InpatientLocationEvent $event, int $index, ?InpatientLocationEvent $previous, Encounter $encounter): bool
    {
        if ($event->sequence !== $index + 1
            || ($index === 0 && ($event->event_type !== InpatientLocationEvent::TYPE_ADMISSION
                || ! $event->occurred_at->equalTo($encounter->registered_at)))
            || ($index > 0 && $event->event_type !== InpatientLocationEvent::TYPE_TRANSFER)) {
            return false;
        }
        if ($index > 0 && (! $previous instanceof InpatientLocationEvent
            || $event->from_ward_public_id !== $previous->to_ward_public_id
            || $event->from_ward_code !== $previous->to_ward_code
            || $event->from_bed_public_id !== $previous->to_bed_public_id
            || $event->from_bed_code !== $previous->to_bed_code
            || $event->from_inpatient_bed_version_id !== $previous->to_inpatient_bed_version_id
            || $event->from_inpatient_bed_version_public_id !== $previous->to_inpatient_bed_version_public_id
            || $event->from_inpatient_bed_version !== $previous->to_inpatient_bed_version
            || $event->from_inpatient_bed_after_digest !== $previous->to_inpatient_bed_after_digest)) {
            return false;
        }
        $version = InpatientBedVersion::query()->whereKey($event->to_inpatient_bed_version_id)->first();
        $bed = $version instanceof InpatientBedVersion ? InpatientBed::query()->with('ward')->whereKey($version->bed_id)->first() : null;

        return $version instanceof InpatientBedVersion && $bed instanceof InpatientBed
            && $version->public_id === $event->to_inpatient_bed_version_public_id
            && $version->version === $event->to_inpatient_bed_version
            && hash_equals($version->after_digest, $event->to_inpatient_bed_after_digest)
            && $bed->public_id === $event->to_bed_public_id
            && $bed->code === $event->to_bed_code
            && $bed->ward->public_id === $event->to_ward_public_id
            && $bed->ward->code === $event->to_ward_code
            && $version->display_name === $event->to_bed_display_name
            && $version->room_label === $event->to_room_label
            && $version->service_class === $event->to_service_class;
    }

    private function validDischarge(InpatientDischarge $discharge, InpatientLocationEvent $last): bool
    {
        return $this->isRoutineDischarge($discharge)
            && $discharge->location_sequence === $last->sequence
            && $discharge->source_ward_public_id === $last->to_ward_public_id
            && $discharge->source_ward_code === $last->to_ward_code
            && $discharge->source_bed_public_id === $last->to_bed_public_id
            && $discharge->source_bed_code === $last->to_bed_code;
    }

    public function isRoutineDischarge(InpatientDischarge $discharge): bool
    {
        return $discharge->disposition_code === InpatientDischarge::DISPOSITION_ROUTINE_HOME
            && $discharge->disposition_label === InpatientDischarge::DISPOSITION_ROUTINE_HOME_LABEL;
    }
}
