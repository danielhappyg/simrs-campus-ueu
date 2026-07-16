<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AssembleReleaseCandidateCommandTest extends TestCase
{
    private string $originalBasePath;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = app()->basePath();
        $this->source = storage_path('framework/testing/release-command-source');
        File::deleteDirectory($this->source);
        $this->putSourceFile('app/Example.php', '<?php');
        $this->putSourceFile('artisan', '#!/usr/bin/env php');
        chmod($this->source.'/artisan', 0755);
        $this->putSourceFile('vendor/autoload.php', '<?php');
        $this->putSourceFile('public/build/manifest.json', '{}');
        $this->putSourceFile('.env', 'APP_KEY=must-not-ship');
        $this->putSourceFile('database/database.sqlite', 'must-not-ship');

        foreach ([
            ['git', 'init', '--quiet'],
            ['git', 'add', 'app/Example.php', 'artisan'],
            ['git', '-c', 'user.name=SIMRS Test', '-c', 'user.email=simrs@example.invalid', 'commit', '--quiet', '-m', 'fixture'],
        ] as $command) {
            $result = Process::path($this->source)->run($command);
            $this->assertTrue($result->successful(), $result->errorOutput());
        }

        $commit = trim(Process::path($this->source)->run(['git', 'rev-parse', 'HEAD'])->output());
        $this->putSourceFile('release-manifest.json', json_encode([
            'schemaVersion' => 1,
            'releaseId' => 'simrs-campus-ueu-'.substr($commit, 0, 12),
            'source' => ['commit' => $commit],
            'deployment' => ['status' => 'NOT_DEPLOYED'],
        ], JSON_THROW_ON_ERROR));
        app()->setBasePath($this->source);
    }

    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    public function test_command_assembles_a_git_tracked_runtime_candidate_without_local_state(): void
    {
        $exitCode = Artisan::call('ops:assemble-release', [
            'manifest' => 'release-manifest.json',
            'output' => 'storage/release-candidate',
        ]);

        $output = $this->source.'/storage/release-candidate';
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($output.'/app/Example.php');
        $this->assertFileExists($output.'/artisan');
        $this->assertFileExists($output.'/vendor/autoload.php');
        $this->assertFileExists($output.'/public/build/manifest.json');
        $this->assertFileExists($output.'/release-manifest.json');
        $this->assertFileDoesNotExist($output.'/.env');
        $this->assertFileDoesNotExist($output.'/database/database.sqlite');
    }

    public function test_command_refuses_a_manifest_for_another_commit(): void
    {
        $this->putSourceFile('release-manifest.json', json_encode([
            'schemaVersion' => 1,
            'releaseId' => 'simrs-campus-ueu-invalid',
            'source' => ['commit' => str_repeat('0', 40)],
            'deployment' => ['status' => 'NOT_DEPLOYED'],
        ], JSON_THROW_ON_ERROR));

        $exitCode = Artisan::call('ops:assemble-release', [
            'manifest' => 'release-manifest.json',
            'output' => 'storage/release-candidate',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDirectoryDoesNotExist($this->source.'/storage/release-candidate');
    }

    public function test_command_refuses_a_release_id_that_does_not_match_the_commit(): void
    {
        $commit = trim(Process::path($this->source)->run(['git', 'rev-parse', 'HEAD'])->output());
        $this->putSourceFile('release-manifest.json', json_encode([
            'schemaVersion' => 1,
            'releaseId' => 'simrs-campus-ueu-wrong',
            'source' => ['commit' => $commit],
            'deployment' => ['status' => 'NOT_DEPLOYED'],
        ], JSON_THROW_ON_ERROR));

        $exitCode = Artisan::call('ops:assemble-release', [
            'manifest' => 'release-manifest.json',
            'output' => 'storage/release-candidate',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDirectoryDoesNotExist($this->source.'/storage/release-candidate');
    }

    private function putSourceFile(string $path, string $contents): void
    {
        $absolute = $this->source.'/'.$path;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $contents);
    }
}
