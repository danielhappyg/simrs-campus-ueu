<?php

namespace App\Support\Finance;

final readonly class FinanceAccommodationOccupancyPlan
{
    /** @param list<FinanceAccommodationOccupancyDay> $days */
    public function __construct(public array $days, public ?string $blockingState = null) {}
}
