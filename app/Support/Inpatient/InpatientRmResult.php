<?php

namespace App\Support\Inpatient;

use App\Models\InpatientRmCoding;
use App\Models\InpatientRmCodingVersion;
use App\Models\InpatientRmCompletenessReview;

final readonly class InpatientRmResult
{
    public function __construct(
        public ?InpatientRmCoding $coding,
        public ?InpatientRmCodingVersion $codingVersion,
        public ?InpatientRmCompletenessReview $review,
        public bool $replayed,
    ) {}
}
