<?php

namespace Tests\Unit;

use App\Support\ReleaseCandidateAssembler;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ReleaseCandidateAssemblerTest extends TestCase
{
    private string $source;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = storage_path('framework/testing/release-assembler-source');
        $this->output = storage_path('framework/testing/release-assembler-output');
        File::deleteDirectory($this->source);
        File::deleteDirectory($this->output);

        $this->putSourceFile('app/Example.php', '<?php');
        $this->putSourceFile('artisan', '#!/usr/bin/env php');
        chmod($this->source.'/artisan', 0755);
        $this->putSourceFile('vendor/autoload.php', '<?php');
        $this->putSourceFile('public/build/manifest.json', '{}');
        $this->putSourceFile('release-manifest.json', '{"schemaVersion":1}');
        $this->putSourceFile('.env', 'APP_KEY=must-not-ship');
        $this->putSourceFile('database/database.sqlite', 'must-not-ship');
        $this->putSourceFile('tests/Feature/SecretTest.php', 'must-not-ship');
        $this->putSourceFile('node_modules/example/index.js', 'must-not-ship');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);
        File::deleteDirectory($this->output);

        parent::tearDown();
    }

    public function test_it_assembles_only_tracked_runtime_files_dependencies_assets_and_manifest(): void
    {
        $result = app(ReleaseCandidateAssembler::class)->assemble(
            sourceRoot: $this->source,
            outputRoot: $this->output,
            runtimeFiles: $this->runtimeFiles(['app/Example.php', 'artisan']),
            manifestPath: $this->source.'/release-manifest.json',
        );

        $this->assertFileExists($this->output.'/app/Example.php');
        $this->assertFileExists($this->output.'/artisan');
        $this->assertFileExists($this->output.'/vendor/autoload.php');
        $this->assertFileExists($this->output.'/public/build/manifest.json');
        $this->assertFileExists($this->output.'/release-manifest.json');
        $this->assertFileDoesNotExist($this->output.'/.env');
        $this->assertFileDoesNotExist($this->output.'/database/database.sqlite');
        $this->assertDirectoryDoesNotExist($this->output.'/tests');
        $this->assertDirectoryDoesNotExist($this->output.'/node_modules');
        $this->assertTrue(is_executable($this->output.'/artisan'));
        $this->assertSame(2, $result['trackedFiles']);
        $this->assertSame(2, $result['generatedFiles']);
    }

    public function test_it_refuses_a_tracked_file_outside_the_runtime_allowlist(): void
    {
        $this->expectException(RuntimeException::class);

        app(ReleaseCandidateAssembler::class)->assemble(
            sourceRoot: $this->source,
            outputRoot: $this->output,
            runtimeFiles: $this->runtimeFiles(['.env']),
            manifestPath: $this->source.'/release-manifest.json',
        );
    }

    public function test_it_refuses_path_traversal_inside_an_allowed_runtime_prefix(): void
    {
        $this->expectException(RuntimeException::class);

        app(ReleaseCandidateAssembler::class)->assemble(
            sourceRoot: $this->source,
            outputRoot: $this->output,
            runtimeFiles: [[
                'path' => 'app/../.env',
                'sha256' => hash_file('sha256', $this->source.'/.env'),
                'mode' => 0644,
                'source' => 'tracked',
            ]],
            manifestPath: $this->source.'/release-manifest.json',
        );
    }

    public function test_it_refuses_a_tracked_symlink_that_could_escape_the_source_tree(): void
    {
        symlink('../.env', $this->source.'/app/Leak.php');
        $this->expectException(RuntimeException::class);

        app(ReleaseCandidateAssembler::class)->assemble(
            sourceRoot: $this->source,
            outputRoot: $this->output,
            runtimeFiles: [[
                'path' => 'app/Leak.php',
                'sha256' => hash_file('sha256', $this->source.'/.env'),
                'mode' => 0644,
                'source' => 'tracked',
            ]],
            manifestPath: $this->source.'/release-manifest.json',
        );
    }

    public function test_it_refuses_a_missing_tracked_runtime_file(): void
    {
        $this->expectException(RuntimeException::class);

        app(ReleaseCandidateAssembler::class)->assemble(
            sourceRoot: $this->source,
            outputRoot: $this->output,
            runtimeFiles: [[
                'path' => 'app/Missing.php',
                'sha256' => str_repeat('0', 64),
                'mode' => 0644,
                'source' => 'tracked',
            ]],
            manifestPath: $this->source.'/release-manifest.json',
        );
    }

    public function test_it_refuses_a_manifest_bound_file_that_changes_before_copying(): void
    {
        $runtimeFiles = $this->runtimeFiles(['app/Example.php']);
        $this->putSourceFile('app/Example.php', '<?php // changed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest-bound runtime file changed');

        app(ReleaseCandidateAssembler::class)->assemble(
            sourceRoot: $this->source,
            outputRoot: $this->output,
            runtimeFiles: $runtimeFiles,
            manifestPath: $this->source.'/release-manifest.json',
        );
    }

    public function test_it_refuses_to_merge_into_a_non_empty_output_directory(): void
    {
        File::ensureDirectoryExists($this->output);
        File::put($this->output.'/unexpected.txt', 'must-not-survive');
        $this->expectException(RuntimeException::class);

        app(ReleaseCandidateAssembler::class)->assemble(
            sourceRoot: $this->source,
            outputRoot: $this->output,
            runtimeFiles: $this->runtimeFiles(['app/Example.php']),
            manifestPath: $this->source.'/release-manifest.json',
        );
    }

    private function putSourceFile(string $path, string $contents): void
    {
        $absolute = $this->source.'/'.$path;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $contents);
    }

    /** @param list<string> $trackedPaths */
    private function runtimeFiles(array $trackedPaths): array
    {
        $files = [];

        foreach ($trackedPaths as $path) {
            $absolute = $this->source.'/'.$path;
            $files[] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $absolute),
                'mode' => fileperms($absolute) & 0777,
                'source' => 'tracked',
            ];
        }

        foreach (['vendor/autoload.php', 'public/build/manifest.json'] as $path) {
            $absolute = $this->source.'/'.$path;
            $files[] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $absolute),
                'mode' => fileperms($absolute) & 0777,
                'source' => 'generated',
            ];
        }

        return $files;
    }
}
