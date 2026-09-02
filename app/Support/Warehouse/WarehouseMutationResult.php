<?php

namespace App\Support\Warehouse;

use Illuminate\Database\Eloquent\Model;

final readonly class WarehouseMutationResult
{
    public function __construct(public Model $record, public bool $replayed) {}
}
