<?php

namespace App\Support\Inpatient;

use App\Models\InpatientBed;
use App\Models\InpatientWard;

final readonly class InpatientMasterMutationResult
{
    public function __construct(public InpatientWard|InpatientBed $master, public bool $replayed) {}
}
