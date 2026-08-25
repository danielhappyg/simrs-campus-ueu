<?php

namespace App\Support\PrivilegedAccess;

use App\Support\CanonicalJson;

final class SecurityLedgerIntegrity
{
    /** @param array<string, mixed> $payload */
    public function payloadDigest(array $payload): string
    {
        return hash('sha256', CanonicalJson::encode($payload));
    }

    /** @param array<string, mixed> $envelope */
    public function integrityDigest(array $envelope): string
    {
        return hash('sha256', CanonicalJson::encode($envelope));
    }
}
