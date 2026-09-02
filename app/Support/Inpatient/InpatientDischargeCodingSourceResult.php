<?php

namespace App\Support\Inpatient;

use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;

final readonly class InpatientDischargeCodingSourceResult
{
    public function __construct(public InpatientDischargeCodingSource $source, public InpatientDischargeCodingSourceVersion $resultVersion, public bool $replayed) {}
}
