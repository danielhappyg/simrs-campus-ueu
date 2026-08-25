<?php

namespace App\Support\PrivilegedAccess;

use App\Support\CanonicalJson;

final class SecurityLedgerPayloadGuard
{
    private const MAX_DEPTH = 8;

    private const MAX_STRING_LENGTH = 2048;

    private const MAX_CANONICAL_BYTES = 32768;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'authorization',
        'authorization_header',
        'api_key',
        'cookie',
        'credentials',
        'credential',
        'mfa_code',
        'otp',
        'password',
        'passphrase',
        'passwd',
        'private_key',
        'recovery_code',
        'recovery_material',
        'secret',
        'session_id',
        'token',
        'totp_code',
        'webauthn_assertion',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertSafe(array $payload): void
    {
        $this->inspect($payload, 0, 'payload');

        if (strlen(CanonicalJson::encode($payload)) > self::MAX_CANONICAL_BYTES) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger payload exceeds the safe size limit.');
        }
    }

    public function assertSafeText(string $value, string $field): void
    {
        if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
            throw new UnsafeSecurityLedgerPayload("Security-ledger {$field} exceeds the safe length limit.");
        }

        if (
            preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/i', $value) === 1
            || preg_match('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', $value) === 1
            || preg_match('/\bCookie\s*:/i', $value) === 1
        ) {
            throw new UnsafeSecurityLedgerPayload("Security-ledger {$field} contains prohibited secret-like content.");
        }
    }

    private function inspect(mixed $value, int $depth, string $path): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger payload nesting exceeds the safe depth limit.');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $normalizedKey = $this->normalizeKey($key);

                    if ($this->isForbiddenKey($normalizedKey)) {
                        throw new UnsafeSecurityLedgerPayload("Security-ledger payload key [{$path}.{$key}] is prohibited.");
                    }
                }

                $this->inspect($item, $depth + 1, $path.'.'.(string) $key);
            }

            return;
        }

        if (is_string($value)) {
            $this->assertSafeText($value, $path);

            return;
        }

        if (is_float($value)) {
            throw new UnsafeSecurityLedgerPayload("Security-ledger payload value [{$path}] cannot be a float.");
        }

        if (! is_null($value) && ! is_bool($value) && ! is_int($value)) {
            throw new UnsafeSecurityLedgerPayload("Security-ledger payload value [{$path}] is not a safe scalar or array.");
        }
    }

    private function isForbiddenKey(string $key): bool
    {
        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if (
                $key === $forbidden
                || str_starts_with($key, $forbidden.'_')
                || str_ends_with($key, '_'.$forbidden)
                || str_contains($key, '_'.$forbidden.'_')
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKey(string $key): string
    {
        $snakeCase = preg_replace('/(?<!^)([A-Z])/', '_$1', $key) ?? $key;
        $normalized = preg_replace('/[^a-z0-9]+/i', '_', $snakeCase) ?? $snakeCase;

        return trim(strtolower($normalized), '_');
    }
}
