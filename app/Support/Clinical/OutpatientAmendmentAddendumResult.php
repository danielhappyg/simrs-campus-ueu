<?php

namespace App\Support\Clinical;

use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientPostClosureAmendmentRequest;

final readonly class OutpatientAmendmentAddendumResult
{
    public function __construct(
        public OutpatientPostClosureAmendmentRequest $request,
        public OutpatientClinicalDocumentAddendum $addendum,
        public bool $replayed,
    ) {}
}
