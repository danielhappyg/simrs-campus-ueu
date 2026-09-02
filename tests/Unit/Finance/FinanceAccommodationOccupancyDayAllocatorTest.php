<?php

namespace Tests\Unit\Finance;

use App\Models\Encounter;
use App\Models\FinanceChargeEvent;
use App\Models\InpatientDischarge;
use App\Models\InpatientLocationEvent;
use App\Models\User;
use App\Support\Finance\FinanceAccommodationOccupancyDay;
use App\Support\Finance\FinanceAccommodationOccupancyDayAllocator;
use App\Support\Finance\FinanceAccommodationOccupancyInterval;
use App\Support\Finance\FinanceAccommodationSourceAdapter;
use App\Support\Finance\FinanceAccommodationTariffActorPolicy;
use App\Support\Finance\FinanceAccommodationTariffContentDigest;
use App\Support\Finance\FinanceAccommodationTariffProjection;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceTariffContentDigest;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class FinanceAccommodationOccupancyDayAllocatorTest extends TestCase
{
    public function test_same_day_stay_is_one_day_and_midnight_discharge_adds_no_day(): void
    {
        $allocator = new FinanceAccommodationOccupancyDayAllocator;
        $sameDay = $this->interval('2026-09-02 10:00:00', '2026-09-02 15:00:00');
        $this->assertSame(['2026-09-02'], $this->dates($allocator->allocateClosedIntervals([$sameDay], Carbon::parse('2026-09-02 15:00:00'))));

        $midnight = $this->interval('2026-09-02 10:00:00', '2026-09-03 00:00:00');
        $this->assertSame(['2026-09-02'], $this->dates($allocator->allocateClosedIntervals([$midnight], Carbon::parse('2026-09-03 00:00:00'))));
    }

    public function test_midnight_transfer_assigns_new_date_to_target_without_duplicate(): void
    {
        $allocator = new FinanceAccommodationOccupancyDayAllocator;
        $first = $this->interval('2026-09-02 10:00:00', '2026-09-03 00:00:00');
        $second = $this->interval('2026-09-03 00:00:00', '2026-09-04 08:00:00');
        $days = $allocator->allocateClosedIntervals([$first, $second], Carbon::parse('2026-09-04 08:00:00'));

        $this->assertSame(['2026-09-02', '2026-09-03', '2026-09-04'], $this->dates($days));
        $this->assertSame($first, $days[0]->interval);
        $this->assertSame($second, $days[1]->interval);
        $this->assertSame($second, $days[2]->interval);
    }

    public function test_multiple_same_day_and_multi_day_transfers_keep_one_encounter_date_anchor(): void
    {
        $allocator = new FinanceAccommodationOccupancyDayAllocator;
        $first = $this->interval('2026-09-02 08:00:00', '2026-09-02 12:00:00');
        $second = $this->interval('2026-09-02 12:00:00', '2026-09-02 18:00:00');
        $third = $this->interval('2026-09-02 18:00:00', '2026-09-04 00:00:00');
        $fourth = $this->interval('2026-09-04 00:00:00', '2026-09-05 09:00:00');
        $days = $allocator->allocateClosedIntervals([$first, $second, $third, $fourth], Carbon::parse('2026-09-05 09:00:00'));

        $this->assertSame(['2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05'], $this->dates($days));
        $this->assertSame($first, $days[0]->interval);
        $this->assertSame($third, $days[1]->interval);
        $this->assertSame($fourth, $days[2]->interval);
        $this->assertSame($fourth, $days[3]->interval);
    }

    public function test_non_inpatient_encounters_are_no_op_unless_an_accommodation_event_is_supplied(): void
    {
        $adapter = new FinanceAccommodationSourceAdapter(
            new FinanceAccommodationOccupancyDayAllocator,
            new FinanceAccommodationTariffProjection(
                new FinanceAccommodationTariffActorPolicy,
                new FinanceAccommodationTariffContentDigest,
                new FinanceTariffContentDigest,
            ),
        );
        $encounter = new Encounter(['care_setting' => Encounter::CARE_SETTING_OUTPATIENT]);
        $this->assertSame([], $adapter->readiness($encounter));
        $this->assertTrue($adapter->synchronize($encounter, new User)->isEmpty());
        $adapter->verifyRetained($encounter, collect());
        $this->addToAssertionCount(1);

        $this->expectException(FinanceDenied::class);
        $adapter->verifyRetained($encounter, collect([new FinanceChargeEvent]));
    }

    public function test_only_retained_routine_discharge_contract_is_an_eligible_close(): void
    {
        $allocator = new FinanceAccommodationOccupancyDayAllocator;
        $routine = new InpatientDischarge([
            'disposition_code' => InpatientDischarge::DISPOSITION_ROUTINE_HOME,
            'disposition_label' => InpatientDischarge::DISPOSITION_ROUTINE_HOME_LABEL,
        ]);
        $nonRoutine = new InpatientDischarge([
            'disposition_code' => 'RUJUK',
            'disposition_label' => 'Rujuk',
        ]);

        $this->assertTrue($allocator->isRoutineDischarge($routine));
        $this->assertFalse($allocator->isRoutineDischarge($nonRoutine));
    }

    private function interval(string $start, string $end): FinanceAccommodationOccupancyInterval
    {
        return new FinanceAccommodationOccupancyInterval(
            new InpatientLocationEvent,
            new InpatientDischarge,
            Carbon::parse($start),
            Carbon::parse($end),
        );
    }

    /** @param list<FinanceAccommodationOccupancyDay> $days */
    private function dates(array $days): array
    {
        return array_map(static fn ($day): string => $day->serviceDate, $days);
    }
}
