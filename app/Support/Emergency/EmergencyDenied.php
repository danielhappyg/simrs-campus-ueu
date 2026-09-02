<?php

namespace App\Support\Emergency;

use RuntimeException;

final class EmergencyDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
