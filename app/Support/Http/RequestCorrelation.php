<?php

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RequestCorrelation
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    public static function existing(Request $request): ?string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);

        return is_string($existing) && Str::isUlid($existing) ? $existing : null;
    }

    public static function ensure(Request $request): string
    {
        $existing = self::existing($request);
        if ($existing !== null) {
            return $existing;
        }

        $requestId = (string) Str::ulid();
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
