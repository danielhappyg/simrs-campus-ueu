<?php

namespace App\Support\Registration;

final readonly class DailyQueueAllocation
{
    public function __construct(
        public string $queueDate,
        public int $queueNumber,
    ) {}
}
