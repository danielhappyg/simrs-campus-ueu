<?php

namespace App\Support;

use JsonException;
use PharData;
use PharFileInfo;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;

class ReleaseArtifactVerifier
{
    private const ROOT = 'release-candidate/';

    /** @var list<string> */
    private const REQUIRED_FILES = [
        'artisan',
        'composer.lock',
        'public/build/manifest.json',
        'release-manifest.json',
        'vendor/autoload.php',
    ];

    /**
     * @return array{
     *     releaseId: string,
     *     commit: string,
     *     archiveSha256: string,
     *     migrationCount: int,
     *     fileCount: int
     * }
     */
    public function verify(
        string $archivePath,
        string $checksumPath,
        ?string $expectedArchiveSha256 = null,
        bool $buildOnly = false,
    ): array {
        if (! $buildOnly && $expectedArchiveSha256 === null) {
            throw new RuntimeException('Promotion verification requires a trusted expected SHA-256 digest.');
        }

        if ($buildOnly && $expectedArchiveSha256 !== null) {
            throw new RuntimeException('Build-only verification cannot claim trusted promotion provenance.');
        }

        $this->assertReadableRegularFile($archivePath, 'release archive');
        $this->assertReadableRegularFile($checksumPath, 'checksum sidecar');

        $archiveHash = $this->verifyChecksum($archivePath, $checksumPath, $expectedArchiveSha256);
        $entries = $this->readEntries($archivePath);

        foreach (self::REQUIRED_FILES as $required) {
            if (! isset($entries[$required])) {
                throw new RuntimeException($required.' is missing from the release archive.');
            }
        }

        $manifest = $this->readManifest($entries['release-manifest.json']->getContent());
        $commit = $manifest['source']['commit'];
        $expectedReleaseId = 'simrs-campus-ueu-'.substr($commit, 0, 12);

        if (! hash_equals($expectedReleaseId, $manifest['releaseId'])) {
            throw new RuntimeException('The release manifest identifier does not match its commit.');
        }

        if (! hash_equals(
            $manifest['integrity']['runtimeFilesSha256'],
            ReleaseCandidateAssembler::runtimeFilesDigest($manifest['integrity']['runtimeFiles']),
        )) {
            throw new RuntimeException('The release manifest runtime file-set digest is invalid.');
        }

        $this->assertExactRuntimeFileSet($entries, $manifest['integrity']['runtimeFiles']);

        foreach ($manifest['integrity']['runtimeFiles'] as $runtimeFile) {
            $this->assertEntryHash($entries, $runtimeFile['path'], $runtimeFile['sha256']);
            $this->assertEntryMode($entries, $runtimeFile['path'], $runtimeFile['mode']);
        }

        $this->assertEntryHash(
            $entries,
            'composer.lock',
            $manifest['integrity']['composerLockSha256'],
        );
        $this->assertEntryHash(
            $entries,
            'public/build/manifest.json',
            $manifest['integrity']['assetManifestSha256'],
        );

        $migrationPaths = [];

        foreach ($manifest['migrations'] as $migration) {
            $path = $migration['path'];

            if (! preg_match('/\Adatabase\/migrations\/[A-Za-z0-9_]+\.php\z/', $path)) {
                throw new RuntimeException('A release manifest migration path is invalid.');
            }

            if (isset($migrationPaths[$path])) {
                throw new RuntimeException('A release manifest migration path is duplicated.');
            }

            $migrationPaths[$path] = true;
            $this->assertEntryHash($entries, $path, $migration['sha256']);
        }

        return [
            'releaseId' => $manifest['releaseId'],
            'commit' => $commit,
            'archiveSha256' => $archiveHash,
            'migrationCount' => count($manifest['migrations']),
            'fileCount' => count($entries),
        ];
    }

    private function assertReadableRegularFile(string $path, string $label): void
    {
        if (! is_file($path) || ! is_readable($path) || is_link($path)) {
            throw new RuntimeException('The '.$label.' must be a readable regular file.');
        }
    }

    private function verifyChecksum(string $archivePath, string $checksumPath, ?string $expectedArchiveSha256): string
    {
        $contents = file_get_contents($checksumPath);

        if ($contents === false
            || preg_match('/\A([a-f0-9]{64})  ([^\r\n]+)\r?\n?\z/', $contents, $matches) !== 1
            || ! hash_equals(basename($archivePath), $matches[2])
        ) {
            throw new RuntimeException('The checksum sidecar is malformed or names another archive.');
        }

        $actual = hash_file('sha256', $archivePath);

        if ($actual === false || ! hash_equals($matches[1], $actual)) {
            throw new RuntimeException('The release archive checksum does not match its sidecar.');
        }

        if ($expectedArchiveSha256 !== null
            && (preg_match('/\A[a-f0-9]{64}\z/', $expectedArchiveSha256) !== 1
                || ! hash_equals($expectedArchiveSha256, $actual))
        ) {
            throw new RuntimeException('The release archive does not match the trusted expected SHA-256 digest.');
        }

        return $actual;
    }

    /** @return array<string, PharFileInfo> */
    private function readEntries(string $archivePath): array
    {
        try {
            $archive = new PharData($archivePath);
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('The release archive is not a readable tar archive.', previous: $exception);
        }

        $prefix = 'phar://'.str_replace('\\', '/', $archivePath).'/';
        $entries = [];
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY);

        /** @var PharFileInfo $entry */
        foreach ($iterator as $entry) {
            $pathname = str_replace('\\', '/', $entry->getPathname());

            if (! str_starts_with($pathname, $prefix)) {
                throw new RuntimeException('The release archive contains an unreadable path.');
            }

            $archivedPath = substr($pathname, strlen($prefix));
            $this->assertSafeArchivePath($archivedPath, $entry);
            $relativePath = substr($archivedPath, strlen(self::ROOT));

            if ($this->isForbidden($relativePath)) {
                throw new RuntimeException('The release archive contains forbidden runtime content.');
            }

            if (isset($entries[$relativePath])) {
                throw new RuntimeException('The release archive contains a duplicate runtime path.');
            }

            $entries[$relativePath] = $entry;
        }

        return $entries;
    }

    private function assertSafeArchivePath(string $path, PharFileInfo $entry): void
    {
        $segments = explode('/', $path);

        if (! str_starts_with($path, self::ROOT)
            || $path === self::ROOT
            || str_starts_with($path, '/')
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || str_contains($path, "\0")
            || $entry->isLink()
            || ! $entry->isFile()
        ) {
            throw new RuntimeException('The release archive contains an unsafe path or link.');
        }
    }

    private function isForbidden(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        $basename = basename($relativePath);

        return in_array('.git', $segments, true)
            || in_array('tests', $segments, true)
            || in_array('node_modules', $segments, true)
            || in_array('deliverables', $segments, true)
            || str_starts_with($basename, '.env')
            || $relativePath === 'database/database.sqlite'
            || preg_match('/\.(?:csv|xls|xlsx)\z/i', $basename) === 1;
    }

    /**
     * @return array{
     *     releaseId: string,
     *     source: array{commit: string},
     *     integrity: array{
     *         composerLockSha256: string,
     *         assetManifestSha256: string,
     *         runtimeFilesSha256: string,
     *         runtimeFiles: list<array{path: string, sha256: string, mode: int, source: 'tracked'|'generated'}>
     *     },
     *     migrations: list<array{path: string, sha256: string}>
     * }
     */
    private function readManifest(string $contents): array
    {
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The release manifest is not valid JSON.', previous: $exception);
        }

        $releaseId = is_array($manifest) ? ($manifest['releaseId'] ?? null) : null;
        $commit = is_array($manifest) ? ($manifest['source']['commit'] ?? null) : null;

        if (! is_array($manifest)
            || ($manifest['schemaVersion'] ?? null) !== 2
            || ($manifest['artifactKind'] ?? null) !== 'laravel-release-candidate'
            || ($manifest['application'] ?? null) !== 'simrs-campus-ueu'
            || ! is_string($releaseId)
            || ! is_string($commit)
            || preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1
            || ! is_array($manifest['migrations'] ?? null)
            || ! array_is_list($manifest['migrations'])
            || $manifest['migrations'] === []
            || ($manifest['safety']['mode'] ?? null) !== 'SIMULATION'
            || ($manifest['safety']['syntheticOnly'] ?? null) !== true
            || ($manifest['deployment']['status'] ?? null) !== 'NOT_DEPLOYED'
            || ($manifest['deployment']['healthRoute'] ?? null) !== '/up'
            || ($manifest['deployment']['promotionRequiresSeparateApproval'] ?? null) !== true
        ) {
            throw new RuntimeException('The release manifest does not preserve the required simulation-only, non-deployed contract.');
        }

        $composerHash = $this->sha256Value(
            $manifest['integrity']['composerLockSha256'] ?? null,
            'Composer lock',
        );
        $assetHash = $this->sha256Value(
            $manifest['integrity']['assetManifestSha256'] ?? null,
            'asset manifest',
        );
        $runtimeFilesDigest = $this->sha256Value(
            $manifest['integrity']['runtimeFilesSha256'] ?? null,
            'runtime file-set',
        );
        $runtimeFiles = [];
        $runtimePaths = [];

        if (! is_array($manifest['integrity']['runtimeFiles'] ?? null)
            || ! array_is_list($manifest['integrity']['runtimeFiles'])
            || $manifest['integrity']['runtimeFiles'] === []
        ) {
            throw new RuntimeException('The release manifest runtime file set is invalid.');
        }

        foreach ($manifest['integrity']['runtimeFiles'] as $runtimeFile) {
            $path = is_array($runtimeFile) ? ($runtimeFile['path'] ?? null) : null;
            $mode = is_array($runtimeFile) ? ($runtimeFile['mode'] ?? null) : null;
            $source = is_array($runtimeFile) ? ($runtimeFile['source'] ?? null) : null;

            if (! is_string($path)
                || ! is_int($mode)
                || ! in_array($source, ['tracked', 'generated'], true)
                || ! ReleaseCandidateAssembler::isAllowedRuntimePath($path)
                || ! ReleaseCandidateAssembler::isExpectedRuntimeSource($path, $source)
                || $mode < 0
                || $mode > 0777
                || isset($runtimePaths[$path])
            ) {
                throw new RuntimeException('The release manifest runtime file set is invalid.');
            }

            $runtimePaths[$path] = true;
            $runtimeFiles[] = [
                'path' => $path,
                'sha256' => $this->sha256Value($runtimeFile['sha256'] ?? null, 'runtime file'),
                'mode' => $mode,
                'source' => $source,
            ];
        }
        $migrations = [];

        foreach ($manifest['migrations'] as $migration) {
            $path = is_array($migration) ? ($migration['path'] ?? null) : null;

            if (! is_string($path)) {
                throw new RuntimeException('The release manifest migration list is invalid.');
            }

            $migrations[] = [
                'path' => $path,
                'sha256' => $this->sha256Value(
                    $migration['sha256'] ?? null,
                    'migration',
                ),
            ];
        }

        return [
            'releaseId' => $releaseId,
            'source' => ['commit' => $commit],
            'integrity' => [
                'composerLockSha256' => $composerHash,
                'assetManifestSha256' => $assetHash,
                'runtimeFilesSha256' => $runtimeFilesDigest,
                'runtimeFiles' => $runtimeFiles,
            ],
            'migrations' => $migrations,
        ];
    }

    /** @param array<string, PharFileInfo> $entries */
    private function assertEntryHash(array $entries, string $path, string $expectedHash): void
    {
        if (! isset($entries[$path])) {
            throw new RuntimeException($path.' is missing from the release archive.');
        }

        $actualHash = hash('sha256', $entries[$path]->getContent());

        if (! hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException($path.' does not match the release manifest hash.');
        }
    }

    /** @param array<string, PharFileInfo> $entries */
    private function assertEntryMode(array $entries, string $path, int $expectedMode): void
    {
        if (! isset($entries[$path]) || (($entries[$path]->getPerms() & 0777) !== $expectedMode)) {
            throw new RuntimeException($path.' does not match the release manifest mode.');
        }
    }

    /**
     * @param  array<string, PharFileInfo>  $entries
     * @param  list<array{path: string, sha256: string, mode: int, source: 'tracked'|'generated'}>  $runtimeFiles
     */
    private function assertExactRuntimeFileSet(array $entries, array $runtimeFiles): void
    {
        $expected = ['release-manifest.json' => true];

        foreach ($runtimeFiles as $runtimeFile) {
            $expected[$runtimeFile['path']] = true;
        }

        if (array_diff_key($entries, $expected) !== [] || array_diff_key($expected, $entries) !== []) {
            throw new RuntimeException('The release archive runtime file set does not exactly match its manifest.');
        }
    }

    private function sha256Value(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException('The '.$label.' SHA-256 value is invalid.');
        }

        return $value;
    }
}
