<?php

namespace App\Support\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientWard;
use App\Models\Patient;
use Illuminate\Support\Collection;

final readonly class InpatientAdmissionLockContext
{
    /** @param Collection<int, Encounter> $encounters */
    public function __construct(
        public Patient $patient,
        public InpatientWard $ward,
        public InpatientBed $bed,
        public Collection $encounters,
    ) {}
}
