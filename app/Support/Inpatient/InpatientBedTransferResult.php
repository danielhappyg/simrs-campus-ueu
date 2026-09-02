<?php

namespace App\Support\Inpatient;

use App\Models\InpatientLocationEvent;

final readonly class InpatientBedTransferResult
{
    public function __construct(public InpatientLocationEvent $event, public bool $replayed) {}
}
