<?php

namespace App\Support\Warehouse;

use RuntimeException;

final class WarehouseDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
