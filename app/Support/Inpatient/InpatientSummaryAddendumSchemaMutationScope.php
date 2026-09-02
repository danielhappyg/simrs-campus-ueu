<?php

namespace App\Support\Inpatient;

final class InpatientSummaryAddendumSchemaMutationScope
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

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
