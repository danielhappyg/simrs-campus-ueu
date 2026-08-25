<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditActorAttributionManifestFile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class VerifyAuditActorAttributionManifestCommand extends Command
{
    protected $signature = 'audit:attribution:manifest-verify
        {filename : Private manifest basename ending in .json}
        {--expect-digest= : Independently recorded exact manifest digest}
        {--json : Emit one compact safe summary}';

    protected $description = 'Offline verification of a private audit-attribution recovery manifest';

    public function handle(AuditActorAttributionManifestFile $files): int
    {
        try {
            $manifest = $files->readAndValidate((string) $this->argument('filename'));
            $expectedDigest = (string) $this->option('expect-digest');
            if (preg_match('/\A[a-f0-9]{64}\z/', $expectedDigest) !== 1) {
                throw new RuntimeException('EXPECTED_MANIFEST_DIGEST_INVALID');
            }
            if (! hash_equals($expectedDigest, (string) $manifest['integrity']['digest'])) {
                throw new RuntimeException('MANIFEST_DIGEST_MISMATCH');
            }
            if (strtotime((string) $manifest['expires_at']) <= now('UTC')->getTimestamp()) {
                throw new RuntimeException('MANIFEST_EXPIRED');
            }
        } catch (Throwable $exception) {
            return $this->failure($this->safeCode($exception));
        }

        $summary = [
            'schema_version' => 1,
            'command' => 'audit:attribution:manifest-verify',
            'mode' => 'OFFLINE_READ_ONLY',
            'result' => 'VERIFIED',
            'manifest_digest' => $manifest['integrity']['digest'],
            'manifest_ulid' => $manifest['manifest_ulid'],
            'source_sha' => $manifest['runtime_binding']['commit_sha'],
            'before_root' => $manifest['preflight']['before_root'],
            'expected_after_root' => $manifest['expected_after_root'],
            'entry_count' => count($manifest['entries']),
            'key_id' => $manifest['key_id'],
            'expires_at' => $manifest['expires_at'],
            'exit_code' => self::SUCCESS,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('AUDIT_ATTRIBUTION_MANIFEST_VERIFIED');
        }

        return self::SUCCESS;
    }

    private function safeCode(Throwable $exception): string
    {
        $allowed = [
            'MANIFEST_PATH_INVALID', 'MANIFEST_FILE_UNSAFE', 'MANIFEST_MODE_INVALID', 'MANIFEST_SIZE_INVALID',
            'MANIFEST_FILE_READ_FAILED', 'MANIFEST_JSON_INVALID', 'MANIFEST_SCHEMA_INVALID', 'MANIFEST_NOT_CANONICAL',
            'MANIFEST_ENTRY_LIMIT', 'MANIFEST_ORDER_INVALID', 'MANIFEST_INTEGRITY_INVALID', 'MANIFEST_EXPIRED',
            'DIGEST_KEY_UNAVAILABLE', 'EXPECTED_MANIFEST_DIGEST_INVALID', 'MANIFEST_DIGEST_MISMATCH',
            'MANIFEST_DIRECTORY_UNSAFE',
        ];
        $code = $exception instanceof RuntimeException ? $exception->getMessage() : '';

        return in_array($code, $allowed, true) ? $code : 'MANIFEST_VERIFICATION_FAILED';
    }

    private function failure(string $code): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'schema_version' => 1,
                'command' => 'audit:attribution:manifest-verify',
                'mode' => 'OFFLINE_READ_ONLY',
                'result' => 'FAILED',
                'operational_code' => $code,
                'exit_code' => self::FAILURE,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('AUDIT_ATTRIBUTION_MANIFEST_VERIFICATION_FAILED:'.$code);
        }

        return self::FAILURE;
    }
}
