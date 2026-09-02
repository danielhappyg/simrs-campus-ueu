<?php

namespace App\Support\Clinical;

use App\Models\OutpatientRmAmendmentReview;

final readonly class OutpatientRmAmendmentReviewResult
{
    public function __construct(
        public OutpatientRmAmendmentReview $review,
        public bool $replayed,
    ) {}
}
