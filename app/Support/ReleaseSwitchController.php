<?php

namespace App\Support;

use JsonException;
use RuntimeException;
use Throwable;

class ReleaseSwitchController
{
    /**
     * @param  callable(string): bool  $healthProbe
     * @return array{from: string|null, to: string}
     */
    public function switchTo(string $root, string $releaseId, callable $healthProbe): array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $releaseId) !== 1) {
            throw new RuntimeException('The release identifier is invalid.');
        }

        $target = rtrim($root, '/').'/releases/'.$releaseId;
        $manifestPath = $target.'/release-manifest.json';

        if (! is_dir($target) || is_link($target) || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('The target release and its manifest must be readable.');
        }

        $this->assertManifestRelease($manifestPath, $releaseId);
        $current = rtrim($root, '/').'/current';
        $previous = $this->currentRelease($current);

        try {
            $healthy = $healthProbe($target);
        } catch (Throwable $exception) {
            throw new RuntimeException('Health probe failed; the current release was not changed.', previous: $exception);
        }

        if ($healthy !== true) {
            throw new RuntimeException('Health probe failed; the current release was not changed.');
        }

        $temporary = rtrim($root, '/').'/.current-'.bin2hex(random_bytes(8));
        $relativeTarget = 'releases/'.$releaseId;

        if (! @symlink($relativeTarget, $temporary)) {
            throw new RuntimeException('A temporary release switch could not be created.');
        }

        if (! @rename($temporary, $current)) {
            @unlink($temporary);

            throw new RuntimeException('The release switch could not be promoted atomically.');
        }

        return ['from' => $previous, 'to' => $releaseId];
    }

    private function assertManifestRelease(string $manifestPath, string $releaseId): void
    {
        $contents = file_get_contents($manifestPath);

        if ($contents === false) {
            throw new RuntimeException('The target release manifest could not be read.');
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The target release manifest is invalid.', previous: $exception);
        }

        if (! is_array($manifest)
            || ! is_string($manifest['releaseId'] ?? null)
            || ! hash_equals($releaseId, $manifest['releaseId'])
        ) {
            throw new RuntimeException('The target release manifest does not match its directory.');
        }
    }

    private function currentRelease(string $current): ?string
    {
        if (! file_exists($current) && ! is_link($current)) {
            return null;
        }

        if (! is_link($current)) {
            throw new RuntimeException('The current release pointer must be a symbolic link.');
        }

        $target = readlink($current);

        if ($target === false || preg_match('/\Areleases\/([A-Za-z0-9][A-Za-z0-9._-]{0,127})\z/', $target, $matches) !== 1) {
            throw new RuntimeException('The current release pointer is invalid.');
        }

        return $matches[1];
    }
}
