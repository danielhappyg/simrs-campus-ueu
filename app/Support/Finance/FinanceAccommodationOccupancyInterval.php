<?php

namespace App\Support\Finance;

use App\Models\InpatientDischarge;
use App\Models\InpatientLocationEvent;
use Carbon\CarbonInterface;

final readonly class FinanceAccommodationOccupancyInterval
{
    public function __construct(
        public InpatientLocationEvent $opening,
        public InpatientLocationEvent|InpatientDischarge|null $closing,
        public CarbonInterface $startsAt,
        public ?CarbonInterface $endsAt,
    ) {}
}
