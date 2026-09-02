<?php

namespace App\Support\Registration;

use RuntimeException;

final class EncounterCancellationDenied extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
