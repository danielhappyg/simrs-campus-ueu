<?php

namespace App\Support\Audit;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class AuditActorAttributionManifestGenerator
{
    /** @var list<string> */
    private const RUNTIME_FILES = [
        'app/Support/Audit/AuditActorAttribution.php',
        'app/Support/Audit/AuditActorAttributionPreflight.php',
        'app/Support/Audit/AuditActorAttributionManifestGenerator.php',
        'app/Support/Audit/AuditActorAttributionManifestValidator.php',
        'app/Support/Audit/AuditActorAttributionManifestFile.php',
        'app/Console/Commands/GenerateAuditActorAttributionManifestCommand.php',
        'app/Console/Commands/VerifyAuditActorAttributionManifestCommand.php',
        'app/Support/CanonicalJson.php',
        'app/Support/Database/SchemaQualifier.php',
        'config/app.php',
        'config/database.php',
        'config/simulation.php',
    ];

    public function __construct(
        private readonly AuditActorAttributionPreflight $preflight,
        private readonly AuditActorAttributionManifestValidator $validator,
    ) {}

    /**
     * @return array{bytes: string, manifest: array<string, mixed>}
     */
    public function generate(
        string $expectedRoot,
        string $expectedSha,
        string $changeReference,
        string $manifestUlid,
        string $nonce,
        string $createdAt,
        string $expiresAt,
    ): array {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedRoot) !== 1
            || preg_match('/\A[a-f0-9]{40,64}\z/', $expectedSha) !== 1) {
            throw new RuntimeException('EXPECTED_BINDING_INVALID');
        }
        $createdTimestamp = strtotime($createdAt);
        $expiresTimestamp = strtotime($expiresAt);
        if ($createdTimestamp === false || $expiresTimestamp === false
            || abs(now('UTC')->getTimestamp() - $createdTimestamp) > 300
            || $expiresTimestamp <= $createdTimestamp
            || $expiresTimestamp - $createdTimestamp > 3600) {
            throw new RuntimeException('MANIFEST_TIME_WINDOW_INVALID');
        }

        [$commitSha, $treeSha] = $this->assertCleanExpectedGitState($expectedSha);
        $snapshot = $this->preflight->manifestSnapshot();
        $report = $snapshot['report'];

        if ($report['counts'][AuditActorAttributionPreflight::BLOCKING] !== 0
            || $report['result'] === AuditActorAttributionPreflight::BLOCKING) {
            throw new RuntimeException('PREFLIGHT_BLOCKING');
        }
        if (! hash_equals($expectedRoot, $report['root_digest'])) {
            throw new RuntimeException('PREFLIGHT_ROOT_MISMATCH');
        }
        if (count($snapshot['entries']) > AuditActorAttributionManifestValidator::MAX_ENTRIES) {
            throw new RuntimeException('MANIFEST_ENTRY_LIMIT');
        }

        $entries = $snapshot['entries'];
        usort($entries, fn (array $left, array $right): int => strcmp($left['locator_hmac'], $right['locator_hmac']));

        $runtimeHashes = $this->runtimeFileHashes($commitSha);
        $manifest = [
            'schema_version' => 1,
            'kind' => AuditActorAttributionManifestValidator::KIND,
            'manifest_ulid' => $manifestUlid,
            'nonce' => $nonce,
            'change_reference' => $changeReference,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'key_id' => $snapshot['key_id'],
            'runtime_binding' => [
                'commit_sha' => $commitSha,
                'tree_sha' => $treeSha,
                'file_hashes' => $runtimeHashes,
            ],
            'database_binding' => $snapshot['database_binding'],
            'preflight' => [
                'root_digest_algorithm' => $report['root_digest_algorithm'],
                'before_root' => $report['root_digest'],
                'rows_scanned' => $report['rows_scanned'],
                'counts' => $report['counts'],
            ],
            'expected_after_root' => $snapshot['expected_after_root'],
            'entries' => $entries,
        ];

        $this->validator->assertSchema([...$manifest, 'integrity' => [
            'digest_algorithm' => 'sha256-canonical-v1',
            'digest' => str_repeat('0', 64),
            'hmac_algorithm' => 'hmac-sha256-canonical-v1',
            'hmac' => str_repeat('0', 64),
        ]]);

        $canonical = CanonicalJson::encode($manifest);
        $digest = hash('sha256', $canonical);
        $hmac = hash_hmac(
            'sha256',
            "SIMRS-AUDIT-ATTRIBUTION-MANIFEST\0V1\0".$canonical."\0".$digest,
            (string) config('app.key'),
        );
        $manifest['integrity'] = [
            'digest_algorithm' => 'sha256-canonical-v1',
            'digest' => $digest,
            'hmac_algorithm' => 'hmac-sha256-canonical-v1',
            'hmac' => $hmac,
        ];
        $bytes = CanonicalJson::encode($manifest);
        if (strlen($bytes) > AuditActorAttributionManifestValidator::MAX_BYTES) {
            throw new RuntimeException('MANIFEST_SIZE_INVALID');
        }
        $this->validator->decodeAndValidate($bytes);
        if ($expiresTimestamp <= now('UTC')->getTimestamp()) {
            throw new RuntimeException('MANIFEST_TIME_WINDOW_INVALID');
        }
        [$finalCommitSha, $finalTreeSha] = $this->assertCleanExpectedGitState($expectedSha);
        if (! hash_equals($commitSha, $finalCommitSha) || ! hash_equals($treeSha, $finalTreeSha)
            || $runtimeHashes !== $this->runtimeFileHashes($commitSha)) {
            throw new RuntimeException('GIT_STATE_CHANGED');
        }

        return ['bytes' => $bytes, 'manifest' => $manifest];
    }

    /** @return array{string, string} */
    private function assertCleanExpectedGitState(string $expectedSha): array
    {
        $status = Process::path(base_path())->run(['git', 'status', '--porcelain=v1', '--untracked-files=no']);
        if (! $status->successful()) {
            throw new RuntimeException('GIT_STATE_UNAVAILABLE');
        }
        if (trim($status->output()) !== '') {
            throw new RuntimeException('GIT_TRACKED_DIRTY');
        }

        $commit = Process::path(base_path())->run(['git', 'rev-parse', 'HEAD']);
        $tree = Process::path(base_path())->run(['git', 'rev-parse', 'HEAD^{tree}']);
        if (! $commit->successful() || ! $tree->successful()) {
            throw new RuntimeException('GIT_STATE_UNAVAILABLE');
        }
        $commitSha = trim($commit->output());
        $treeSha = trim($tree->output());
        if (preg_match('/\A[a-f0-9]{40,64}\z/', $commitSha) !== 1
            || preg_match('/\A[a-f0-9]{40,64}\z/', $treeSha) !== 1) {
            throw new RuntimeException('GIT_STATE_INVALID');
        }
        if (! hash_equals($expectedSha, $commitSha)) {
            throw new RuntimeException('SOURCE_SHA_MISMATCH');
        }

        return [$commitSha, $treeSha];
    }

    /** @return array<string, string> */
    private function runtimeFileHashes(string $commitSha): array
    {
        $hashes = [];
        foreach (self::RUNTIME_FILES as $path) {
            $liveBytes = file_get_contents(base_path($path));
            $committed = Process::path(base_path())->run(['git', 'show', $commitSha.':'.$path]);
            if ($liveBytes === false || ! $committed->successful()) {
                throw new RuntimeException('RUNTIME_BINDING_UNAVAILABLE');
            }
            $committedBytes = $committed->output();
            if (! hash_equals(hash('sha256', $committedBytes), hash('sha256', $liveBytes))) {
                throw new RuntimeException('GIT_TRACKED_DIRTY');
            }
            $hashes[$path] = hash('sha256', $committedBytes);
        }

        return $hashes;
    }
}
