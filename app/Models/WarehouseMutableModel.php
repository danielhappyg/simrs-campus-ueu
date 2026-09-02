<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use App\Support\Warehouse\WarehouseMutationScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class WarehouseMutableModel extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    protected static function booted(): void
    {
        static::creating(static fn () => WarehouseMutationScope::assertActive());
        static::updating(static fn () => WarehouseMutationScope::assertActive());
        static::deleting(static fn () => throw new LogicException('Warehouse heads cannot be deleted.'));
    }
}
