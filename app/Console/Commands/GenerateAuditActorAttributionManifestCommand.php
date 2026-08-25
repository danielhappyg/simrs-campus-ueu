<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditActorAttributionManifestFile;
use App\Support\Audit\AuditActorAttributionManifestGenerator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class GenerateAuditActorAttributionManifestCommand extends Command
{
    protected $signature = 'audit:attribution:manifest-generate
        {filename : Private basename ending in .json}
        {--expect-root= : Exact BG-02c3 preflight root}
        {--expect-sha= : Exact clean tracked Git HEAD SHA}
        {--change-reference= : Reviewed change reference}
        {--manifest-ulid= : Supplied manifest ULID}
        {--nonce= : Supplied 32-64 lowercase hex nonce}
        {--created-at= : UTC timestamp YYYY-MM-DDTHH:MM:SSZ}
        {--expires-at= : UTC timestamp YYYY-MM-DDTHH:MM:SSZ}
        {--json : Emit one compact safe summary}';

    protected $description = 'Generate a private read-only audit-attribution recovery manifest';

    public function handle(
        AuditActorAttributionManifestGenerator $generator,
        AuditActorAttributionManifestFile $files,
    ): int {
        try {
            $result = $generator->generate(
                expectedRoot: (string) $this->option('expect-root'),
                expectedSha: (string) $this->option('expect-sha'),
                changeReference: (string) $this->option('change-reference'),
                manifestUlid: (string) $this->option('manifest-ulid'),
                nonce: (string) $this->option('nonce'),
                createdAt: (string) $this->option('created-at'),
                expiresAt: (string) $this->option('expires-at'),
            );
            $files->writeExclusive((string) $this->argument('filename'), $result['bytes']);
        } catch (Throwable $exception) {
            return $this->failure($this->safeCode($exception));
        }

        $manifest = $result['manifest'];
        $summary = [
            'schema_version' => 1,
            'command' => 'audit:attribution:manifest-generate',
            'mode' => 'READ_ONLY',
            'result' => 'GENERATED',
            'manifest_digest' => $manifest['integrity']['digest'],
            'manifest_ulid' => $manifest['manifest_ulid'],
            'source_sha' => $manifest['runtime_binding']['commit_sha'],
            'before_root' => $manifest['preflight']['before_root'],
            'expected_after_root' => $manifest['expected_after_root'],
            'entry_count' => count($manifest['entries']),
            'exit_code' => self::SUCCESS,
        ];
        $this->emit($summary, 'AUDIT_ATTRIBUTION_MANIFEST_GENERATED');

        return self::SUCCESS;
    }

    private function safeCode(Throwable $exception): string
    {
        $allowed = [
            'EXPECTED_BINDING_INVALID', 'MANIFEST_TIME_WINDOW_INVALID', 'GIT_STATE_UNAVAILABLE', 'GIT_TRACKED_DIRTY', 'GIT_STATE_INVALID',
            'GIT_STATE_CHANGED', 'SOURCE_SHA_MISMATCH', 'PREFLIGHT_BLOCKING', 'PREFLIGHT_ROOT_MISMATCH', 'MANIFEST_ENTRY_LIMIT',
            'MANIFEST_SIZE_INVALID', 'MANIFEST_SCHEMA_INVALID', 'MANIFEST_PATH_INVALID', 'MANIFEST_PATH_UNSAFE',
            'MANIFEST_DIRECTORY_UNAVAILABLE', 'MANIFEST_DIRECTORY_UNSAFE', 'MANIFEST_FILE_EXISTS', 'MANIFEST_FILE_CREATE_FAILED',
            'MANIFEST_FILE_UNSAFE',
            'MANIFEST_MODE_INVALID', 'MANIFEST_FILE_WRITE_FAILED', 'RUNTIME_BINDING_UNAVAILABLE',
            'UNSAFE_RUNTIME', 'UNSUPPORTED_DRIVER', 'SCHEMA_NOT_EXPANDED', 'DIGEST_KEY_UNAVAILABLE',
            'EXISTING_TRANSACTION_UNSAFE', 'DATABASE_SCAN_FAILED',
        ];
        $code = $exception instanceof RuntimeException ? $exception->getMessage() : '';

        return in_array($code, $allowed, true) ? $code : 'MANIFEST_GENERATION_FAILED';
    }

    /** @param array<string, mixed> $summary */
    private function emit(array $summary, string $code): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($code);
        }
    }

    private function failure(string $code): int
    {
        $summary = [
            'schema_version' => 1,
            'command' => 'audit:attribution:manifest-generate',
            'mode' => 'READ_ONLY',
            'result' => 'FAILED',
            'operational_code' => $code,
            'exit_code' => self::FAILURE,
        ];
        $this->emit($summary, 'AUDIT_ATTRIBUTION_MANIFEST_GENERATION_FAILED:'.$code);

        return self::FAILURE;
    }
}
