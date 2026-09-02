<?php

namespace App\Support\Clinical;

use App\Models\OutpatientPostClosureAmendmentRequest;

final readonly class OutpatientAmendmentRequestResult
{
    public function __construct(
        public OutpatientPostClosureAmendmentRequest $request,
        public bool $replayed,
    ) {}
}
