<?php

namespace App\Support\Inpatient;

use App\Models\InpatientDischarge;

final readonly class InpatientDischargeResult
{
    public function __construct(public InpatientDischarge $discharge, public bool $replayed) {}
}
