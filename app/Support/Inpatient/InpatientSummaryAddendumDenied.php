<?php

namespace App\Support\Inpatient;

use RuntimeException;

final class InpatientSummaryAddendumDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
