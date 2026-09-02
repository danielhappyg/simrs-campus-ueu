<?php

namespace App\Support\Finance;

use RuntimeException;

final class FinanceDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
