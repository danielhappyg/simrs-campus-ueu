<?php

namespace Tests\Unit\Finance;

use App\Models\Encounter;
use App\Models\FinanceChargeEvent;
use App\Models\User;
use App\Support\Finance\FinanceAccommodationOccupancyDayAllocator;
use App\Support\Finance\FinanceAccommodationSourceAdapter;
use App\Support\Finance\FinanceAccommodationTariffActorPolicy;
use App\Support\Finance\FinanceAccommodationTariffContentDigest;
use App\Support\Finance\FinanceAccommodationTariffProjection;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceTariffContentDigest;
use PHPUnit\Framework\TestCase;

final class FinanceAccommodationSourceAdapterTest extends TestCase
{
    public function test_non_inpatient_domain_is_a_strict_no_op(): void
    {
        $adapter = new FinanceAccommodationSourceAdapter(
            new FinanceAccommodationOccupancyDayAllocator,
            new FinanceAccommodationTariffProjection(
                new FinanceAccommodationTariffActorPolicy,
                new FinanceAccommodationTariffContentDigest,
                new FinanceTariffContentDigest,
            ),
        );
        $encounter = new Encounter(['care_setting' => Encounter::CARE_SETTING_EMERGENCY]);

        $this->assertSame([], $adapter->readiness($encounter));
        $this->assertTrue($adapter->synchronize($encounter, new User)->isEmpty());
        $adapter->verifyRetained($encounter, collect());
        $this->addToAssertionCount(1);

        $this->expectException(FinanceDenied::class);
        $adapter->verifyRetained($encounter, collect([new FinanceChargeEvent]));
    }
}
