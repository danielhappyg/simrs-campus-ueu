<?php

namespace App\Support\Audit;

use RuntimeException;

final class AuditActorAttributionManifestFile
{
    public function __construct(private readonly AuditActorAttributionManifestValidator $validator) {}

    public function writeExclusive(string $filename, string $bytes): string
    {
        $path = $this->resolve($filename, true);
        $directory = dirname($path);
        $private = storage_path('app/private');

        $this->assertDirectoryChainSafe($private);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('MANIFEST_DIRECTORY_UNAVAILABLE');
        }
        $this->assertDirectoryChainSafe($directory);
        $this->assertPrivateDirectory($directory);
        $directoryStat = @stat($directory);
        if (! is_array($directoryStat)) {
            throw new RuntimeException('MANIFEST_DIRECTORY_UNSAFE');
        }

        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException(file_exists($path) ? 'MANIFEST_FILE_EXISTS' : 'MANIFEST_FILE_CREATE_FAILED');
        }

        $complete = false;
        try {
            $openedStat = fstat($handle);
            $pathStat = @lstat($path);
            $currentDirectoryStat = @stat($directory);
            if (! is_array($openedStat)) {
                throw new RuntimeException('MANIFEST_FILE_UNSAFE');
            }
            if (! is_array($pathStat)) {
                throw new RuntimeException('MANIFEST_FILE_UNSAFE');
            }
            $this->assertPrivateDirectory($directory);
            if (! $this->isRegularFile($openedStat)
                || ! $this->isPrivateOwnedFile($openedStat)
                || ($openedStat['mode'] & 0777) !== 0600
                || ! $this->sameIdentity($openedStat, $pathStat)
                || ! $this->sameIdentity($directoryStat, $currentDirectoryStat)) {
                throw new RuntimeException('MANIFEST_FILE_UNSAFE');
            }
            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('MANIFEST_FILE_WRITE_FAILED');
                }
                $offset += $written;
            }
            if (! fflush($handle)) {
                throw new RuntimeException('MANIFEST_FILE_WRITE_FAILED');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('MANIFEST_FILE_WRITE_FAILED');
            }
            $postWriteStat = fstat($handle);
            if (! is_array($postWriteStat)) {
                throw new RuntimeException('MANIFEST_FILE_UNSAFE');
            }
            if (! $this->sameIdentity($openedStat, $postWriteStat)
                || ($postWriteStat['mode'] & 0777) !== 0600) {
                throw new RuntimeException('MANIFEST_MODE_INVALID');
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (! $complete && is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }

        return $path;
    }

    /** @return array<string, mixed> */
    public function readAndValidate(string $filename): array
    {
        $path = $this->resolve($filename, false);
        $directory = dirname($path);
        $this->assertDirectoryChainSafe($directory);
        $this->assertPrivateDirectory($directory);
        $before = @lstat($path);
        if (! is_array($before) || ! $this->isRegularFile($before)) {
            throw new RuntimeException('MANIFEST_FILE_UNSAFE');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('MANIFEST_FILE_READ_FAILED');
        }
        try {
            $opened = fstat($handle);
            $after = @lstat($path);
            if (! is_array($opened) || ! $this->isRegularFile($opened)
                || ! $this->isPrivateOwnedFile($opened)
                || ! $this->sameIdentity($before, $opened) || ! $this->sameIdentity($opened, $after)) {
                throw new RuntimeException('MANIFEST_FILE_UNSAFE');
            }
            if (($opened['mode'] & 0777) !== 0600) {
                throw new RuntimeException('MANIFEST_MODE_INVALID');
            }
            if ($opened['size'] < 1 || $opened['size'] > AuditActorAttributionManifestValidator::MAX_BYTES) {
                throw new RuntimeException('MANIFEST_SIZE_INVALID');
            }

            $bytes = '';
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('MANIFEST_FILE_READ_FAILED');
                }
                $bytes .= $chunk;
                if (strlen($bytes) > AuditActorAttributionManifestValidator::MAX_BYTES) {
                    throw new RuntimeException('MANIFEST_SIZE_INVALID');
                }
            }
        } finally {
            fclose($handle);
        }

        return $this->validator->decodeAndValidate($bytes);
    }

    private function resolve(string $filename, bool $forWrite): string
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.json\z/', $filename) !== 1
            || str_contains($filename, '..') || basename($filename) !== $filename) {
            throw new RuntimeException('MANIFEST_PATH_INVALID');
        }

        $directory = storage_path('app/private/audit-attribution-manifests');
        if (! $forWrite && (! is_dir($directory) || is_link($directory))) {
            throw new RuntimeException('MANIFEST_FILE_UNSAFE');
        }

        return $directory.DIRECTORY_SEPARATOR.$filename;
    }

    private function assertDirectoryChainSafe(string $path): void
    {
        $cursor = $path;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) {
                throw new RuntimeException('MANIFEST_PATH_UNSAFE');
            }
            $cursor = dirname($cursor);
        }
    }

    private function assertPrivateDirectory(string $path): void
    {
        $mode = fileperms($path);
        $owner = fileowner($path);
        if ($mode === false || ($mode & 0077) !== 0 || $owner === false
            || (function_exists('posix_geteuid') && $owner !== posix_geteuid())) {
            throw new RuntimeException('MANIFEST_DIRECTORY_UNSAFE');
        }
    }

    /** @param array<string|int, mixed> $stat */
    private function isRegularFile(array $stat): bool
    {
        return (($stat['mode'] ?? 0) & 0170000) === 0100000;
    }

    /** @param array<string|int, mixed> $stat */
    private function isPrivateOwnedFile(array $stat): bool
    {
        return ($stat['nlink'] ?? null) === 1
            && (! function_exists('posix_geteuid') || ($stat['uid'] ?? null) === posix_geteuid());
    }

    /**
     * @param  array<string|int, mixed>  $left
     * @param  array<string|int, mixed>  $right
     */
    private function sameIdentity(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }
}
