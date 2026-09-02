<?php

namespace App\Support\Inpatient;

use LogicException;

final class InpatientLocationMutationScope
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

    public static function assertActive(): void
    {
        if (! self::isActive()) {
            throw new LogicException('Inpatient location mutations require the bounded service scope.');
        }
    }
}
