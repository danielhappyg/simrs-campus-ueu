<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ReleaseManifestCommandTest extends TestCase
{
    private const OUTPUT_PATH = 'storage/framework/testing/release-manifest.json';

    protected function tearDown(): void
    {
        File::delete(base_path(self::OUTPUT_PATH));
        File::delete(base_path('public/release-manifest.json'));
        File::deleteDirectory(storage_path('framework/testing/release-manifest-public'));
        File::deleteDirectory(storage_path('framework/testing/release-manifest-database'));
        app()->useDatabasePath(base_path('database'));

        parent::tearDown();
    }

    public function test_manifest_identifies_the_exact_release_inputs_without_environment_secrets(): void
    {
        $publicPath = storage_path('framework/testing/release-manifest-public');
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', '{"resources/js/app.tsx":{"file":"assets/app.js"}}');
        app()->usePublicPath($publicPath);

        $commitResult = Process::path(base_path())->run(['git', 'rev-parse', 'HEAD']);
        $this->assertTrue($commitResult->successful());
        $commit = trim($commitResult->output());
        $this->fakeCleanGit();

        $exitCode = Artisan::call('ops:release-manifest', [
            'output' => self::OUTPUT_PATH,
            '--commit' => $commit,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $contents = File::get(base_path(self::OUTPUT_PATH));
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $manifest['schemaVersion']);
        $this->assertSame('laravel-release-candidate', $manifest['artifactKind']);
        $this->assertSame('simrs-campus-ueu-'.substr($commit, 0, 12), $manifest['releaseId']);
        $this->assertSame($commit, $manifest['source']['commit']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $manifest['source']['tree']);
        $this->assertSame(hash_file('sha256', base_path('composer.lock')), $manifest['integrity']['composerLockSha256']);
        $this->assertSame(hash_file('sha256', base_path('package-lock.json')), $manifest['integrity']['npmLockSha256']);
        $this->assertSame(hash_file('sha256', $publicPath.'/build/manifest.json'), $manifest['integrity']['assetManifestSha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['integrity']['runtimeFilesSha256']);
        $this->assertNotEmpty($manifest['integrity']['runtimeFiles']);
        $this->assertCount(count(File::glob(database_path('migrations/*.php'))), $manifest['migrations']);
        $this->assertSame('SIMULATION', $manifest['safety']['mode']);
        $this->assertTrue($manifest['safety']['syntheticOnly']);
        $this->assertSame('NOT_DEPLOYED', $manifest['deployment']['status']);
        $this->assertStringNotContainsString('APP_KEY', $contents);
        $this->assertStringNotContainsString('DB_PASSWORD', $contents);
        $this->assertStringNotContainsString('.env', $contents);
    }

    public function test_manifest_refuses_a_commit_that_does_not_match_head(): void
    {
        $publicPath = storage_path('framework/testing/release-manifest-public');
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', '{}');
        app()->usePublicPath($publicPath);
        $this->fakeCleanGit();

        $exitCode = Artisan::call('ops:release-manifest', [
            'output' => self::OUTPUT_PATH,
            '--commit' => str_repeat('0', 40),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist(base_path(self::OUTPUT_PATH));
    }

    public function test_manifest_refuses_a_public_output_path(): void
    {
        $publicPath = storage_path('framework/testing/release-manifest-public');
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', '{}');
        app()->usePublicPath($publicPath);
        $this->fakeCleanGit();

        $exitCode = Artisan::call('ops:release-manifest', [
            'output' => 'public/release-manifest.json',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist(base_path('public/release-manifest.json'));
    }

    public function test_manifest_refuses_output_path_traversal(): void
    {
        $publicPath = storage_path('framework/testing/release-manifest-public');
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', '{}');
        app()->usePublicPath($publicPath);
        $this->fakeCleanGit();

        $exitCode = Artisan::call('ops:release-manifest', [
            'output' => 'storage/../public/release-manifest.json',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist(base_path('public/release-manifest.json'));
    }

    public function test_manifest_refuses_a_release_without_migrations(): void
    {
        $publicPath = storage_path('framework/testing/release-manifest-public');
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', '{}');
        app()->usePublicPath($publicPath);
        $databasePath = storage_path('framework/testing/release-manifest-database');
        File::ensureDirectoryExists($databasePath.'/migrations');
        app()->useDatabasePath($databasePath);
        $this->fakeCleanGit();

        $exitCode = Artisan::call('ops:release-manifest', [
            'output' => self::OUTPUT_PATH,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist(base_path(self::OUTPUT_PATH));
    }

    private function fakeCleanGit(): void
    {
        $commands = [
            ['git', 'rev-parse', 'HEAD'],
            ['git', 'rev-parse', 'HEAD^{tree}'],
            ['git', 'show', '-s', '--format=%cI', 'HEAD'],
            ['git', 'ls-files', '-z', '--', 'app', 'artisan', 'bootstrap', 'composer.json', 'composer.lock', 'config', 'database/factories', 'database/migrations', 'database/seeders', 'public', 'resources', 'routes', 'storage'],
        ];
        $outputs = [];

        foreach ($commands as $command) {
            $result = Process::path(base_path())->run($command);
            $this->assertTrue($result->successful(), $result->errorOutput());
            $outputs[json_encode($command, JSON_THROW_ON_ERROR)] = $result->output();
        }

        $outputs[json_encode($commands[3], JSON_THROW_ON_ERROR)] = "artisan\0composer.json\0composer.lock\0";

        Process::fake(function (PendingProcess $process) use ($outputs) {
            if ($process->command === ['git', 'status', '--porcelain=v1', '--untracked-files=no']) {
                return Process::result('');
            }

            $key = json_encode($process->command, JSON_THROW_ON_ERROR);

            return isset($outputs[$key])
                ? Process::result($outputs[$key])
                : Process::result('', '', 1);
        });
    }
}
