<?php

namespace App\Support\Inpatient;

use Closure;

final class InpatientDischargeSchemaMutationScope
{
    private static int $depth = 0;

    public static function run(Closure $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
