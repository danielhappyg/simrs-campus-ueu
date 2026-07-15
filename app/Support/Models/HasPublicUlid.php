<?php

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->getAttribute('public_id')) {
                $model->setAttribute('public_id', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
