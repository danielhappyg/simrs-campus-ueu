<?php

namespace App\Support\Inpatient;

use RuntimeException;

final class InpatientMasterDenied extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
