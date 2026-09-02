<?php

namespace App\Support\Emergency;

use App\Support\CanonicalJson;

final class EmergencyCanonicalJson
{
    /** @param array<mixed> $value */
    public static function encode(array $value): string
    {
        return CanonicalJson::encode($value);
    }

    /** @param array<mixed> $value */
    public static function digest(array $value): string
    {
        return hash('sha256', self::encode($value));
    }
}
