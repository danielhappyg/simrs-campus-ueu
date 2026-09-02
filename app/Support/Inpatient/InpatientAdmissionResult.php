<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientLocationEvent;

final readonly class InpatientAdmissionResult
{
    public function __construct(
        public Encounter $encounter,
        public InpatientLocationEvent $location,
    ) {}
}
