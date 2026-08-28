<?php

namespace Tests\Feature;

use App\Support\ReleaseCandidateAssembler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PharData;
use Tests\TestCase;

class VerifyReleaseArtifactCommandTest extends TestCase
{
    private string $root;

    private string $archiveRelative;

    private string $checksumRelative;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/verify-release-command');
        File::deleteDirectory($this->root);
        $candidate = $this->root.'/source/release-candidate';
        $commit = str_repeat('d', 40);
        $files = [
            'composer.lock' => 'composer-lock',
            'public/build/manifest.json' => '{}',
            'database/migrations/2026_01_01_000000_create_example.php' => '<?php',
            'artisan' => '#!/usr/bin/env php',
            'vendor/autoload.php' => '<?php',
        ];

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($candidate.'/'.$path));
            File::put($candidate.'/'.$path, $contents);
        }

        chmod($candidate.'/artisan', 0755);
        $migration = 'database/migrations/2026_01_01_000000_create_example.php';
        File::put($candidate.'/release-manifest.json', json_encode([
            'schemaVersion' => 2,
            'artifactKind' => 'laravel-release-candidate',
            'application' => 'simrs-campus-ueu',
            'releaseId' => 'simrs-campus-ueu-'.substr($commit, 0, 12),
            'source' => [
                'commit' => $commit,
                'tree' => str_repeat('e', 40),
                'committedAt' => '2026-07-16T12:00:00+07:00',
            ],
            'integrity' => [
                'composerLockSha256' => hash_file('sha256', $candidate.'/composer.lock'),
                'npmLockSha256' => str_repeat('f', 64),
                'assetManifestSha256' => hash_file('sha256', $candidate.'/public/build/manifest.json'),
                'runtimeFilesSha256' => ReleaseCandidateAssembler::runtimeFilesDigest($this->runtimeFiles($candidate)),
                'runtimeFiles' => $this->runtimeFiles($candidate),
            ],
            'migrations' => [[
                'path' => $migration,
                'sha256' => hash_file('sha256', $candidate.'/'.$migration),
            ]],
            'safety' => ['mode' => 'SIMULATION', 'syntheticOnly' => true],
            'deployment' => [
                'status' => 'NOT_DEPLOYED',
                'optimizeAtDeploy' => true,
                'healthRoute' => '/up',
                'promotionRequiresSeparateApproval' => true,
            ],
        ], JSON_THROW_ON_ERROR));

        $archive = $this->root.'/simrs-campus-ueu-'.$commit.'.tar';
        $phar = new PharData($archive);
        $phar->buildFromDirectory($this->root.'/source');
        unset($phar);
        File::put($archive.'.sha256', hash_file('sha256', $archive).'  '.basename($archive).PHP_EOL);

        $this->archiveRelative = str_replace(base_path().'/', '', $archive);
        $this->checksumRelative = $this->archiveRelative.'.sha256';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_command_verifies_a_non_deployed_release_artifact(): void
    {
        $exitCode = Artisan::call('ops:verify-release', [
            'archive' => $this->archiveRelative,
            'checksum' => $this->checksumRelative,
            '--expect-archive-sha256' => hash_file('sha256', $this->root.'/simrs-campus-ueu-'.str_repeat('d', 40).'.tar'),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('VERIFIED', $output);
        $this->assertStringContainsString('NOT_DEPLOYED', $output);
    }

    public function test_command_refuses_paths_outside_the_repository(): void
    {
        $exitCode = Artisan::call('ops:verify-release', [
            'archive' => '../candidate.tar',
            'checksum' => '../candidate.tar.sha256',
            '--expect-archive-sha256' => str_repeat('a', 64),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('repository-relative', Artisan::output());
    }

    public function test_command_requires_a_trusted_digest_outside_build_only_mode(): void
    {
        $exitCode = Artisan::call('ops:verify-release', [
            'archive' => $this->archiveRelative,
            'checksum' => $this->checksumRelative,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('trusted expected', Artisan::output());
    }

    public function test_command_allows_explicit_structural_build_verification_without_promotion_provenance(): void
    {
        $exitCode = Artisan::call('ops:verify-release', [
            'archive' => $this->archiveRelative,
            'checksum' => $this->checksumRelative,
            '--build-only' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('STRUCTURALLY_VERIFIED', Artisan::output());
        $this->assertStringNotContainsString('trusted digest matched', Artisan::output());
    }

    private function runtimeFiles(string $candidate): array
    {
        $paths = [
            'artisan',
            'composer.lock',
            'database/migrations/2026_01_01_000000_create_example.php',
            'public/build/manifest.json',
            'vendor/autoload.php',
        ];
        $files = [];

        foreach ($paths as $path) {
            $absolute = $candidate.'/'.$path;
            $files[] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $absolute),
                'mode' => fileperms($absolute) & 0777,
                'source' => in_array($path, ['vendor/autoload.php', 'public/build/manifest.json'], true)
                    ? 'generated'
                    : 'tracked',
            ];
        }

        return $files;
    }
}
