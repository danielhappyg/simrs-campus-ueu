<?php

namespace App\Support\PrivilegedAccess;

use InvalidArgumentException;

final class PrivilegedAccessSessionReference
{
    private const CONTEXT = "SIMRS-BREAK-GLASS-SESSION\0V1\0";

    public function hmac(string $sessionId, string $key): string
    {
        if ($sessionId === '') {
            throw new InvalidArgumentException('A server-side session identifier is required.');
        }

        if (strlen($key) < 32) {
            throw new InvalidArgumentException('The privileged-access session HMAC key is unavailable or too short.');
        }

        return hash_hmac('sha256', self::CONTEXT.$sessionId, $key);
    }
}
