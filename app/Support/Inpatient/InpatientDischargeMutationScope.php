<?php

namespace App\Support\Inpatient;

use Closure;
use LogicException;

final class InpatientDischargeMutationScope
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

    public static function assertActive(): void
    {
        if (! self::isActive()) {
            throw new LogicException('Inpatient discharge mutations must use the governed service.');
        }
    }
}
