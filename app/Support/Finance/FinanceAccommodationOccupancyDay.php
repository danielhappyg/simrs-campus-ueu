<?php

namespace App\Support\Finance;

use Carbon\CarbonInterface;

final readonly class FinanceAccommodationOccupancyDay
{
    public function __construct(
        public string $serviceDate,
        public CarbonInterface $anchorAt,
        public FinanceAccommodationOccupancyInterval $interval,
    ) {}
}
