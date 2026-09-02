<?php

namespace App\Support\Pharmacy;

use DateTimeInterface;
use JsonException;

final class PharmacyCanonicalJson
{
    /** @throws JsonException */
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
