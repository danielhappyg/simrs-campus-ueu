<?php

namespace App\Support\Registration;

use App\Models\EncounterCancellation;

final readonly class EncounterCancellationResult
{
    public function __construct(
        public EncounterCancellation $cancellation,
        public bool $replayed,
    ) {}
}
