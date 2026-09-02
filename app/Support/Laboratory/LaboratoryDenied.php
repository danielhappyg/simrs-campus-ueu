<?php

namespace App\Support\Laboratory;

use RuntimeException;

final class LaboratoryDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
