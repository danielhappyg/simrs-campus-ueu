<?php

namespace App\Support\Laboratory;

final class LaboratoryCanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function equivalent(mixed $left, mixed $right): bool
    {
        return hash_equals(self::encode($left), self::encode($right));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::normalize(...), $value);
    }
}
