<?php

namespace App\Support\Inpatient;

use App\Models\InpatientDischargeSummary;
use App\Models\InpatientDischargeSummaryVersion;

final readonly class InpatientDischargeSummaryMutationResult
{
    public function __construct(
        public InpatientDischargeSummary $summary,
        public InpatientDischargeSummaryVersion $resultVersion,
        public bool $replayed,
    ) {}
}
