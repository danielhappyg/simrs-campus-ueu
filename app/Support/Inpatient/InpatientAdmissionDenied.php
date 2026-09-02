<?php

namespace App\Support\Inpatient;

use RuntimeException;

final class InpatientAdmissionDenied extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
