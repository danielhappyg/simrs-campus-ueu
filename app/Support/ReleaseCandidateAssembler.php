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
    ];

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @param  list<string>  $trackedRuntimeFiles
     * @return array{trackedFiles: int, generatedFiles: int}
     */
    public function assemble(
        string $sourceRoot,
        string $outputRoot,
        array $trackedRuntimeFiles,
        string $manifestPath,
    ): array {
        if ($this->files->isDirectory($outputRoot)
            && ! $this->files->isEmptyDirectory($outputRoot)
        ) {
            throw new RuntimeException('The release output directory must be empty.');
        }

        $this->files->ensureDirectoryExists($outputRoot);

        foreach ($trackedRuntimeFiles as $relativePath) {
            if (! $this->isAllowedTrackedPath($relativePath)) {
                throw new RuntimeException('A tracked file is outside the runtime release allowlist.');
            }

            $this->copyFile(
                $sourceRoot.'/'.$relativePath,
                $outputRoot.'/'.$relativePath,
            );
        }

        $generatedFiles = $this->copyTree(
            $sourceRoot.'/vendor',
            $outputRoot.'/vendor',
        );
        $generatedFiles += $this->copyTree(
            $sourceRoot.'/public/build',
            $outputRoot.'/public/build',
        );
        $this->copyFile($manifestPath, $outputRoot.'/release-manifest.json');

        return [
            'trackedFiles' => count($trackedRuntimeFiles),
            'generatedFiles' => $generatedFiles,
        ];
    }

    private function copyTree(string $source, string $destination): int
    {
        $files = $this->files->allFiles($source, true);

        foreach ($files as $file) {
            $this->copyFile(
                $file->getPathname(),
                $destination.'/'.$file->getRelativePathname(),
            );
        }

        return count($files);
    }

    private function isAllowedTrackedPath(string $path): bool
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
