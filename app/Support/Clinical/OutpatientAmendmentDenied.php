<?php

namespace App\Support\Clinical;

use RuntimeException;

final class OutpatientAmendmentDenied extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
