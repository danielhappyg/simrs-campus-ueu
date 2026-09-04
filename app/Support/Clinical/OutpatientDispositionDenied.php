<?php

namespace App\Support\Clinical;

use RuntimeException;

final class OutpatientDispositionDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
