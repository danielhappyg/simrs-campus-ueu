<?php

namespace App\Support\Audit;

use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class AuditActorAttributionManifestValidator
{
    public const KIND = 'SIMRS_AUDIT_ACTOR_ATTRIBUTION_RECOVERY_MANIFEST';

    public const MAX_ENTRIES = AuditActorAttributionPreflight::MAX_MANIFEST_ENTRIES;

    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var list<string> */
    private const RUNTIME_FILES = [
        'app/Console/Commands/GenerateAuditActorAttributionManifestCommand.php',
        'app/Console/Commands/VerifyAuditActorAttributionManifestCommand.php',
        'app/Support/Audit/AuditActorAttribution.php',
        'app/Support/Audit/AuditActorAttributionManifestFile.php',
        'app/Support/Audit/AuditActorAttributionManifestGenerator.php',
        'app/Support/Audit/AuditActorAttributionManifestValidator.php',
        'app/Support/Audit/AuditActorAttributionPreflight.php',
        'app/Support/CanonicalJson.php',
        'app/Support/Database/SchemaQualifier.php',
        'config/app.php',
        'config/database.php',
        'config/simulation.php',
    ];

    /** @return array<string, mixed> */
    public function decodeAndValidate(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('MANIFEST_SIZE_INVALID');
        }

        try {
            $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('MANIFEST_JSON_INVALID', previous: $exception);
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }

        try {
            if (! hash_equals(CanonicalJson::encode($manifest), $bytes)) {
                throw new RuntimeException('MANIFEST_NOT_CANONICAL');
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('MANIFEST_JSON_INVALID', previous: $exception);
        }

        $this->assertSchema($manifest);
        $this->assertIntegrity($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function assertSchema(array $manifest): void
    {
        $this->assertKeys($manifest, [
            'change_reference', 'created_at', 'database_binding', 'entries', 'expected_after_root',
            'expires_at', 'integrity', 'key_id', 'kind', 'manifest_ulid', 'nonce', 'preflight',
            'runtime_binding', 'schema_version',
        ]);

        if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['kind'] ?? null) !== self::KIND) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }

        foreach (['manifest_ulid'] as $field) {
            $this->assertStringPattern($manifest[$field] ?? null, '/\A[0-9A-HJKMNP-TV-Z]{26}\z/');
        }
        $this->assertStringPattern($manifest['nonce'] ?? null, '/\A[a-f0-9]{32,64}\z/');
        $this->assertStringPattern($manifest['key_id'] ?? null, '/\A[a-f0-9]{24}\z/');
        $this->assertSafeLabel($manifest['change_reference'] ?? null, 255);
        $this->assertTimestamp($manifest['created_at'] ?? null);
        $this->assertTimestamp($manifest['expires_at'] ?? null);
        $this->assertDigest($manifest['expected_after_root'] ?? null);

        $createdTimestamp = strtotime((string) $manifest['created_at']);
        $expiresTimestamp = strtotime((string) $manifest['expires_at']);
        if ($createdTimestamp === false || $expiresTimestamp === false
            || $expiresTimestamp <= $createdTimestamp
            || $expiresTimestamp - $createdTimestamp > 3600) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }

        $preflight = $this->object($manifest['preflight'] ?? null);
        $this->assertKeys($preflight, ['before_root', 'counts', 'root_digest_algorithm', 'rows_scanned']);
        $this->assertDigest($preflight['before_root'] ?? null);
        if (($preflight['root_digest_algorithm'] ?? null) !== 'hmac-sha256-length-prefixed-v1') {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
        $this->assertBoundedInteger($preflight['rows_scanned'] ?? null, 0, PHP_INT_MAX);
        $counts = $this->object($preflight['counts'] ?? null);
        $this->assertKeys($counts, ['BACKFILLABLE_SERVICE', 'BACKFILLABLE_USER', 'BLOCKING', 'CURRENT']);
        foreach ($counts as $count) {
            $this->assertBoundedInteger($count, 0, PHP_INT_MAX);
        }
        if (array_sum($counts) !== $preflight['rows_scanned'] || $counts['BLOCKING'] !== 0) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }

        $entries = $manifest['entries'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > self::MAX_ENTRIES) {
            throw new RuntimeException('MANIFEST_ENTRY_LIMIT');
        }
        if (count($entries) !== $counts['BACKFILLABLE_USER'] + $counts['BACKFILLABLE_SERVICE']) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }

        $lastLocator = null;
        foreach ($entries as $entry) {
            $entry = $this->object($entry);
            $this->assertKeys($entry, [
                'classification', 'derivation_rule', 'locator_hmac', 'source_leaf_hmac',
                'target_reference_hmac', 'target_type',
            ]);
            foreach (['locator_hmac', 'source_leaf_hmac', 'target_reference_hmac'] as $field) {
                $this->assertDigest($entry[$field] ?? null);
            }
            $allowed = [
                AuditActorAttributionPreflight::BACKFILLABLE_USER.'|USER_FROM_RESTRICTED_FK_RECOVERY_V1|'.AuditActorAttribution::TYPE_USER,
                AuditActorAttributionPreflight::BACKFILLABLE_SERVICE.'|SERVICE_REBUILD_ADMIN_V1|'.AuditActorAttribution::TYPE_SERVICE,
            ];
            $variant = ($entry['classification'] ?? '').'|'.($entry['derivation_rule'] ?? '').'|'.($entry['target_type'] ?? '');
            if (! in_array($variant, $allowed, true)) {
                throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
            }
            if ($lastLocator !== null && strcmp($lastLocator, $entry['locator_hmac']) >= 0) {
                throw new RuntimeException('MANIFEST_ORDER_INVALID');
            }
            $lastLocator = $entry['locator_hmac'];
        }

        $database = $this->object($manifest['database_binding'] ?? null);
        $this->assertKeys($database, [
            'database_target_hmac', 'driver', 'engine_version', 'migration_count',
            'migration_fingerprint', 'schema_contract', 'schema_fingerprint',
        ]);
        if (! in_array($database['driver'] ?? null, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
        $this->assertEngineVersion($database['engine_version'] ?? null);
        if (($database['schema_contract'] ?? null) !== 'audit-attribution-expanded-v1') {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
        $this->assertBoundedInteger($database['migration_count'] ?? null, 0, 100000);
        foreach (['database_target_hmac', 'migration_fingerprint', 'schema_fingerprint'] as $field) {
            $this->assertDigest($database[$field] ?? null);
        }

        $runtime = $this->object($manifest['runtime_binding'] ?? null);
        $this->assertKeys($runtime, ['commit_sha', 'file_hashes', 'tree_sha']);
        $this->assertStringPattern($runtime['commit_sha'] ?? null, '/\A[a-f0-9]{40,64}\z/');
        $this->assertStringPattern($runtime['tree_sha'] ?? null, '/\A[a-f0-9]{40,64}\z/');
        $fileHashes = $this->object($runtime['file_hashes'] ?? null);
        $runtimePaths = array_keys($fileHashes);
        sort($runtimePaths);
        if ($runtimePaths !== self::RUNTIME_FILES) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
        foreach ($fileHashes as $path => $digest) {
            if (preg_match('/\A(?:app|config)\/[A-Za-z0-9_.\/-]+\.php\z/', $path) !== 1) {
                throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
            }
            $this->assertDigest($digest);
        }

        $integrity = $this->object($manifest['integrity'] ?? null);
        $this->assertKeys($integrity, ['digest', 'digest_algorithm', 'hmac', 'hmac_algorithm']);
        if (($integrity['digest_algorithm'] ?? null) !== 'sha256-canonical-v1'
            || ($integrity['hmac_algorithm'] ?? null) !== 'hmac-sha256-canonical-v1') {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
        $this->assertDigest($integrity['digest'] ?? null);
        $this->assertDigest($integrity['hmac'] ?? null);
    }

    /** @param array<string, mixed> $manifest */
    private function assertIntegrity(array $manifest): void
    {
        $integrity = $manifest['integrity'];
        unset($manifest['integrity']);
        $canonical = CanonicalJson::encode($manifest);
        $digest = hash('sha256', $canonical);
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('DIGEST_KEY_UNAVAILABLE');
        }
        $expectedKeyId = substr(hash_hmac(
            'sha256',
            "SIMRS-AUDIT-ACTOR-ATTRIBUTION-MANIFEST\0V1\0KEY_ID\0BG-02C4A",
            $key,
        ), 0, 24);
        if (! hash_equals($expectedKeyId, (string) $manifest['key_id'])) {
            throw new RuntimeException('MANIFEST_INTEGRITY_INVALID');
        }
        $expectedHmac = hash_hmac('sha256', "SIMRS-AUDIT-ATTRIBUTION-MANIFEST\0V1\0".$canonical."\0".$digest, $key);
        if (! hash_equals($digest, $integrity['digest']) || ! hash_equals($expectedHmac, $integrity['hmac'])) {
            throw new RuntimeException('MANIFEST_INTEGRITY_INVALID');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function assertKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }

        return $value;
    }

    private function assertDigest(mixed $value): void
    {
        $this->assertStringPattern($value, '/\A[a-f0-9]{64}\z/');
    }

    private function assertStringPattern(mixed $value, string $pattern): void
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
    }

    private function assertSafeLabel(mixed $value, int $max): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > $max
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/', $value) !== 1) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
    }

    private function assertEngineVersion(mixed $value): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 255
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._:+()\/-]*\z/', $value) !== 1) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
    }

    private function assertTimestamp(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
    }

    private function assertBoundedInteger(mixed $value, int $min, int $max): void
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new RuntimeException('MANIFEST_SCHEMA_INVALID');
        }
    }
}
