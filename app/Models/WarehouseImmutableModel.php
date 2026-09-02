<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use App\Support\Warehouse\WarehouseMutationScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class WarehouseImmutableModel extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(static fn () => WarehouseMutationScope::assertActive());
        static::updating(static fn () => throw new LogicException('Warehouse evidence is immutable.'));
        static::deleting(static fn () => throw new LogicException('Warehouse evidence cannot be deleted.'));
    }
}
