<?php

namespace App\Console\Commands;

use App\Support\ReleaseArtifactVerifier;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class VerifyReleaseArtifactCommand extends Command
{
    protected $signature = 'ops:verify-release
        {archive : Repository-relative release tar path}
        {checksum : Repository-relative SHA-256 sidecar path}
        {--expect-archive-sha256= : Trusted SHA-256 from an independent promotion record}
        {--build-only : Verify structure during artifact creation; cannot verify promotion provenance}';

    protected $description = 'Verify a simulation-only release artifact without deploying it';

    public function __construct(private readonly ReleaseArtifactVerifier $verifier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $expectedArchiveSha256 = trim((string) $this->option('expect-archive-sha256'));
            $buildOnly = (bool) $this->option('build-only');

            if ($buildOnly && $expectedArchiveSha256 !== '') {
                throw new RuntimeException('Select either build-only verification or a trusted expected archive SHA-256 digest.');
            }

            if (! $buildOnly && $expectedArchiveSha256 === '') {
                throw new RuntimeException('Promotion verification requires a trusted expected archive SHA-256 digest.');
            }

            $result = $this->verifier->verify(
                $this->resolvePath((string) $this->argument('archive')),
                $this->resolvePath((string) $this->argument('checksum')),
                $buildOnly ? null : $expectedArchiveSha256,
                $buildOnly,
            );

            $this->info(sprintf(
                ($buildOnly ? 'STRUCTURALLY_VERIFIED' : 'VERIFIED').
                ' %s (%d files, %d migrations, sha256:%s); %s',
                $result['releaseId'],
                $result['fileCount'],
                $result['migrationCount'],
                $result['archiveSha256'],
                $buildOnly
                    ? 'no trusted promotion provenance was supplied; deployment status remains NOT_DEPLOYED.'
                    : 'trusted digest matched; deployment status remains NOT_DEPLOYED.',
            ));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('The release artifact could not be verified: '.$exception::class.'.');

            return self::FAILURE;
        }
    }

    private function resolvePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $segments = explode('/', $normalized);

        if ($normalized === ''
            || str_starts_with($normalized, '/')
            || in_array('..', $segments, true)
            || str_starts_with($normalized, 'public/')
        ) {
            throw new RuntimeException('Release artifact paths must be non-public and repository-relative.');
        }

        return base_path($normalized);
    }
}
