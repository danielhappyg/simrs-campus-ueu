<?php

namespace App\Support\Radiology;

use RuntimeException;

final class RadiologyDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
