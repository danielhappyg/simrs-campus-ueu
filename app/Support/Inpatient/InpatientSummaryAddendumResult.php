<?php

namespace App\Support\Inpatient;

use App\Models\InpatientSummaryAddendum;
use App\Models\InpatientSummaryAddendumReview;
use App\Models\InpatientSummaryCorrectionRequest;

final class InpatientSummaryAddendumResult
{
    public function __construct(public readonly InpatientSummaryCorrectionRequest $request, public readonly ?InpatientSummaryAddendum $addendum = null, public readonly ?InpatientSummaryAddendumReview $review = null, public readonly bool $replayed = false) {}
}
