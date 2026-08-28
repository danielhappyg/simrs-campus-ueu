<?php

namespace App\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class ReleaseCandidateAssembler
{
    /** @var list<string> */
    private const TRACKED_RUNTIME_PATHS = [
        'app/',
        'artisan',
        'bootstrap/',
        'composer.json',
        'composer.lock',
        'config/',
        'database/factories/',
        'database/migrations/',
        'database/seeders/',
        'public/',
        'resources/',
        'routes/',
        'storage/',
        'vendor/',
    ];

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @param  list<array{path: string, sha256: string, mode: int, source: 'tracked'|'generated'}>  $runtimeFiles
     * @return array{trackedFiles: int, generatedFiles: int}
     */
    public function assemble(
        string $sourceRoot,
        string $outputRoot,
        array $runtimeFiles,
        string $manifestPath,
    ): array {
        if ($this->files->isDirectory($outputRoot)
            && ! $this->files->isEmptyDirectory($outputRoot)
        ) {
            throw new RuntimeException('The release output directory must be empty.');
        }

        $this->files->ensureDirectoryExists($outputRoot);

        $trackedFiles = 0;
        $generatedFiles = 0;

        foreach ($runtimeFiles as $runtimeFile) {
            $relativePath = $runtimeFile['path'];

            if (! self::isAllowedRuntimePath($relativePath)) {
                throw new RuntimeException('A runtime file is outside the release allowlist.');
            }

            $this->assertSourceMatchesManifest($sourceRoot.'/'.$relativePath, $runtimeFile);

            $this->copyFile(
                $sourceRoot.'/'.$relativePath,
                $outputRoot.'/'.$relativePath,
            );

            if ($runtimeFile['source'] === 'tracked') {
                $trackedFiles++;
            } else {
                $generatedFiles++;
            }
        }

        $this->copyFile($manifestPath, $outputRoot.'/release-manifest.json');

        return [
            'trackedFiles' => $trackedFiles,
            'generatedFiles' => $generatedFiles,
        ];
    }

    /**
     * @param  array{path: string, sha256: string, mode: int, source: 'tracked'|'generated'}  $runtimeFile
     */
    private function assertSourceMatchesManifest(string $source, array $runtimeFile): void
    {
        if (! is_file($source) || ! is_readable($source) || is_link($source)) {
            throw new RuntimeException('A manifest-bound runtime file is missing, unreadable, or a symlink.');
        }

        $hash = hash_file('sha256', $source);
        $mode = fileperms($source);

        if ($hash === false
            || $mode === false
            || ! hash_equals($runtimeFile['sha256'], $hash)
            || $runtimeFile['mode'] !== ($mode & 0777)
        ) {
            throw new RuntimeException('A manifest-bound runtime file changed before assembly.');
        }
    }

    public static function isAllowedRuntimePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        if ($normalized === '' || str_starts_with($normalized, '/') || in_array('..', $segments, true)) {
            return false;
        }

        foreach (self::TRACKED_RUNTIME_PATHS as $allowed) {
            if ($normalized === $allowed
                || (str_ends_with($allowed, '/') && str_starts_with($normalized, $allowed))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{path: string, sha256: string, mode: int, source: 'tracked'|'generated'}>  $runtimeFiles
     */
    public static function runtimeFilesDigest(array $runtimeFiles): string
    {
        $lines = [];

        foreach ($runtimeFiles as $runtimeFile) {
            $lines[] = $runtimeFile['path']."\0".sprintf('%04o', $runtimeFile['mode'])."\0".$runtimeFile['sha256']."\n";
        }

        sort($lines, SORT_STRING);

        return hash('sha256', implode('', $lines));
    }

    private function copyFile(string $source, string $destination): void
    {
        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('A required release file is missing or unreadable.');
        }

        if (is_link($source)) {
            throw new RuntimeException('Symlinks are not permitted in a release candidate.');
        }

        $this->files->ensureDirectoryExists(dirname($destination));

        if (! $this->files->copy($source, $destination)) {
            throw new RuntimeException('A required release file could not be copied.');
        }

        $mode = fileperms($source);

        if ($mode !== false) {
            chmod($destination, $mode & 0777);
        }
    }
}
