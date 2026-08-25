<?php

namespace App\Support\Audit;

use App\Support\CanonicalJson;

final class AuditSafeDataGuard
{
    private const MAX_DEPTH = 8;

    private const MAX_STRING_LENGTH = 2048;

    private const MAX_CANONICAL_BYTES = 32768;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'api_key',
        'authorization',
        'authorization_header',
        'client_secret',
        'cookie',
        'credential',
        'credentials',
        'mfa_code',
        'otp',
        'passphrase',
        'password',
        'passwd',
        'private_key',
        'recovery_codes',
        'recovery_code',
        'recovery_material',
        'recovery_phrase',
        'secret',
        'service_role_key',
        'session_id',
        'token',
        'totp_code',
        'two_factor',
        'webauthn_assertion',
        'webauthn_response',
        'authenticator_key',
    ];

    /** @param array<string, mixed> $metadata */
    public function assertSafeMetadata(array $metadata): void
    {
        $this->inspect($metadata, 0, 'metadata');

        if (strlen(CanonicalJson::encode($metadata)) > self::MAX_CANONICAL_BYTES) {
            throw new UnsafeAuditData('Audit metadata exceeds the safe size limit.');
        }
    }

    public function assertSafeText(?string $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new UnsafeAuditData("Audit {$field} must be valid UTF-8.");
        }

        if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
            throw new UnsafeAuditData("Audit {$field} exceeds the safe length limit.");
        }

        if (preg_match('/[\x00-\x1F\x7F-\x9F]/u', $value) === 1) {
            throw new UnsafeAuditData("Audit {$field} contains prohibited control characters.");
        }

        $secretPatterns = [
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/\bBasic\s+[A-Za-z0-9+\/=]{16,}/i',
            '/\bCookie\s*:/i',
            '/(?<![A-Za-z0-9_])["\x27]?(?:password|passwd|api[_ -]?key|client[_ -]?secret|service[_ -]?role[_ -]?key|token|passphrase|recovery[_ -]?(?:phrase|material|codes?)|two[_ -]?factor[_ -]?(?:code|secret|recovery[_ -]?codes?))["\x27]?\s*[:=]/i',
            '/(?<![A-Za-z0-9_])["\x27]?(?:clientDataJSON|authenticatorData|attestationObject|webauthn[_ -]?(?:assertion|response)|authenticator[_ -]?key)["\x27]?\s*[:=]/i',
            '/(?:[{,]\s*["\x27]?(?:assertion|response)["\x27]?|["\x27](?:assertion|response)["\x27])\s*[:=]/i',
            '/\b(?:sb_secret_|sk_live_|ghp_)[A-Za-z0-9_-]{8,}/i',
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
            '#[a-z][a-z0-9+.-]*://[^\s/:@]+:[^\s/@]+@#i',
        ];

        foreach ($secretPatterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new UnsafeAuditData("Audit {$field} contains prohibited secret-like content.");
            }
        }
    }

    private function inspect(mixed $value, int $depth, string $path): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnsafeAuditData('Audit metadata nesting exceeds the safe depth limit.');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $this->assertSafeText($key, $path.' key');

                    if ($this->isForbiddenKey($this->normalizeKey($key))) {
                        throw new UnsafeAuditData('Audit metadata contains a prohibited key.');
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
            throw new UnsafeAuditData("Audit metadata value [{$path}] cannot be a float.");
        }

        if (! is_null($value) && ! is_bool($value) && ! is_int($value)) {
            throw new UnsafeAuditData("Audit metadata value [{$path}] is not a safe scalar or array.");
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
