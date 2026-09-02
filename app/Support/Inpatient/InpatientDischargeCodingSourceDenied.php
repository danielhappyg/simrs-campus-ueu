<?php

namespace App\Support\Inpatient;

use RuntimeException;

final class InpatientDischargeCodingSourceDenied extends RuntimeException
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409, public readonly array $metadata = [])
    {
        parent::__construct($message);
    }
}
