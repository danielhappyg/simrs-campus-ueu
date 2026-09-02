<?php

namespace App\Support\Emergency;

final class EmergencyMutationScope
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
            throw new \LogicException('Emergency mutation requires the governed service boundary.');
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
