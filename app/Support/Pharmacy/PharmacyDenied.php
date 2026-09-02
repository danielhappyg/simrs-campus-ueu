<?php

namespace App\Support\Pharmacy;

use RuntimeException;

final class PharmacyDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
