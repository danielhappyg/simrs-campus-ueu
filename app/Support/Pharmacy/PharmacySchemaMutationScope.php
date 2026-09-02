<?php

namespace App\Support\Pharmacy;

final class PharmacySchemaMutationScope
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
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
