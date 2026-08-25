<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BG-01 protects immutable facts through Eloquent lifecycle events only.
 * Database mutation guards and the strict security ledger belong to BG-02.
 */
abstract class ImmutableBreakGlassFact extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (self $record): never {
            throw new LogicException(class_basename($record).' is an append-only break-glass fact and cannot be updated.');
        });

        static::deleting(function (self $record): never {
            throw new LogicException(class_basename($record).' is an append-only break-glass fact and cannot be deleted.');
        });
    }
}
