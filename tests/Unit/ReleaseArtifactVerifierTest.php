<?php

namespace Tests\Unit;

use App\Support\ReleaseArtifactVerifier;
use App\Support\ReleaseCandidateAssembler;
use Illuminate\Support\Facades\File;
use PharData;
use RuntimeException;
use Tests\TestCase;

class ReleaseArtifactVerifierTest extends TestCase
{
    private string $root;

    private string $candidate;

    private string $archive;

    private string $checksum;

    private static int $archiveSerial = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/release-artifact-verifier');
        $this->candidate = $this->root.'/source/release-candidate';
        File::deleteDirectory($this->root);

        $this->putCandidateFile('composer.lock', 'composer-lock');
        $this->putCandidateFile('public/build/manifest.json', '{"app":"assets/app.js"}');
        $this->putCandidateFile('database/migrations/2026_01_01_000000_create_example.php', '<?php');
        $this->putCandidateFile('artisan', '#!/usr/bin/env php');
        $this->putCandidateFile('vendor/autoload.php', '<?php');
        chmod($this->candidate.'/artisan', 0755);
        $this->writeManifest();
        $this->buildArtifact();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_verifies_the_archive_checksum_layout_and_manifest_bound_files(): void
    {
        $result = app(ReleaseArtifactVerifier::class)->verify(
            $this->archive,
            $this->checksum,
            hash_file('sha256', $this->archive),
        );

        $this->assertSame('simrs-campus-ueu-aaaaaaaaaaaa', $result['releaseId']);
        $this->assertSame(str_repeat('a', 40), $result['commit']);
        $this->assertSame(hash_file('sha256', $this->archive), $result['archiveSha256']);
        $this->assertSame(1, $result['migrationCount']);
        $this->assertGreaterThanOrEqual(6, $result['fileCount']);
    }

    public function test_it_refuses_an_archive_that_does_not_match_its_checksum_sidecar(): void
    {
        File::put($this->checksum, str_repeat('0', 64).'  '.basename($this->archive).PHP_EOL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum, hash_file('sha256', $this->archive));
    }

    public function test_it_refuses_forbidden_runtime_content_even_when_the_sidecar_matches(): void
    {
        $this->putCandidateFile('.env', 'APP_KEY=must-not-ship');
        $this->buildArtifact();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum, hash_file('sha256', $this->archive));
    }

    public function test_it_refuses_a_manifest_hash_that_does_not_match_the_archived_file(): void
    {
        $this->writeManifest(composerHash: str_repeat('0', 64));
        $this->buildArtifact();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.lock');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum, hash_file('sha256', $this->archive));
    }

    public function test_it_refuses_a_manifest_that_does_not_preserve_the_simulation_boundary(): void
    {
        $this->writeManifest(mode: 'PRODUCTION');
        $this->buildArtifact();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('simulation');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum, hash_file('sha256', $this->archive));
    }

    public function test_it_refuses_an_archive_that_does_not_match_the_trusted_expected_digest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trusted expected');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum, str_repeat('0', 64));
    }

    public function test_it_refuses_implicit_build_only_verification_without_an_explicit_mode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Promotion verification requires');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum);
    }

    public function test_it_refuses_an_unlisted_runtime_file_even_when_every_supplied_hash_matches(): void
    {
        $this->putCandidateFile('app/Unexpected.php', '<?php');
        $this->buildArtifact();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file set');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum, hash_file('sha256', $this->archive));
    }

    public function test_it_refuses_generated_build_output_classified_as_tracked(): void
    {
        $runtimeFiles = $this->runtimeFiles();
        foreach ($runtimeFiles as &$runtimeFile) {
            if ($runtimeFile['path'] === 'public/build/manifest.json') {
                $runtimeFile['source'] = 'tracked';
            }
        }
        unset($runtimeFile);

        $this->writeManifest(runtimeFiles: $runtimeFiles);
        $this->buildArtifact();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('runtime file set');

        app(ReleaseArtifactVerifier::class)->verify($this->archive, $this->checksum, hash_file('sha256', $this->archive));
    }

    private function putCandidateFile(string $path, string $contents): void
    {
        $absolute = $this->candidate.'/'.$path;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $contents);
    }

    private function writeManifest(
        ?string $composerHash = null,
        string $mode = 'SIMULATION',
        ?array $runtimeFiles = null,
    ): void {
        $migration = 'database/migrations/2026_01_01_000000_create_example.php';
        $commit = str_repeat('a', 40);
        $runtimeFiles ??= $this->runtimeFiles();
        $manifest = [
            'schemaVersion' => 2,
            'artifactKind' => 'laravel-release-candidate',
            'application' => 'simrs-campus-ueu',
            'releaseId' => 'simrs-campus-ueu-'.substr($commit, 0, 12),
            'source' => [
                'commit' => $commit,
                'tree' => str_repeat('b', 40),
                'committedAt' => '2026-07-16T12:00:00+07:00',
            ],
            'integrity' => [
                'composerLockSha256' => $composerHash ?? hash_file('sha256', $this->candidate.'/composer.lock'),
                'npmLockSha256' => str_repeat('c', 64),
                'assetManifestSha256' => hash_file('sha256', $this->candidate.'/public/build/manifest.json'),
                'runtimeFilesSha256' => ReleaseCandidateAssembler::runtimeFilesDigest($runtimeFiles),
                'runtimeFiles' => $runtimeFiles,
            ],
            'migrations' => [[
                'path' => $migration,
                'sha256' => hash_file('sha256', $this->candidate.'/'.$migration),
            ]],
            'safety' => [
                'mode' => $mode,
                'syntheticOnly' => true,
            ],
            'deployment' => [
                'status' => 'NOT_DEPLOYED',
                'optimizeAtDeploy' => true,
                'healthRoute' => '/up',
                'promotionRequiresSeparateApproval' => true,
            ],
        ];

        $this->putCandidateFile(
            'release-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
        );
    }

    private function buildArtifact(): void
    {
        self::$archiveSerial++;
        $this->archive = $this->root.'/simrs-campus-ueu-'.str_repeat('a', 40).'-'.self::$archiveSerial.'.tar';
        $this->checksum = $this->archive.'.sha256';
        File::ensureDirectoryExists(dirname($this->archive));
        $archive = new PharData($this->archive);
        $archive->buildFromDirectory($this->root.'/source');
        unset($archive);

        File::put(
            $this->checksum,
            hash_file('sha256', $this->archive).'  '.basename($this->archive).PHP_EOL,
        );
    }

    private function runtimeFiles(): array
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
            $absolute = $this->candidate.'/'.$path;
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
