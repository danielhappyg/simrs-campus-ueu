<?php

namespace App\Support\Inpatient;

use LogicException;

final class InpatientSummaryAddendumMutationScope
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

    public static function assertActive(): void
    {
        if (self::$depth < 1) {
            throw new LogicException('Inpatient summary addendum writes require the guarded service scope.');
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
