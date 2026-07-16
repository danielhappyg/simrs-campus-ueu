<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Throwable;

class GenerateReleaseManifestCommand extends Command
{
    protected $signature = 'ops:release-manifest
        {output : Repository-relative destination for the JSON manifest}
        {--commit= : Expected full Git commit SHA}';

    protected $description = 'Generate a secret-free manifest for a built release candidate';

    public function handle(): int
    {
        try {
            $outputPath = $this->resolveOutputPath((string) $this->argument('output'));
            $commit = $this->git(['rev-parse', 'HEAD']);
            $expectedCommit = trim((string) $this->option('commit'));

            if ($expectedCommit !== '' && ! hash_equals($commit, $expectedCommit)) {
                throw new RuntimeException('The expected commit does not match the checked-out HEAD.');
            }

            $requiredFiles = [
                'composer.lock' => base_path('composer.lock'),
                'package-lock.json' => base_path('package-lock.json'),
                'public/build/manifest.json' => public_path('build/manifest.json'),
            ];

            foreach ($requiredFiles as $label => $path) {
                if (! is_file($path) || ! is_readable($path)) {
                    throw new RuntimeException($label.' is required and must be readable.');
                }
            }

            $migrationFiles = File::glob(database_path('migrations/*.php'));

            if ($migrationFiles === []) {
                throw new RuntimeException('At least one tracked database migration is required.');
            }

            sort($migrationFiles, SORT_STRING);
            $manifest = [
                'schemaVersion' => 1,
                'artifactKind' => 'laravel-release-candidate',
                'application' => 'simrs-campus-ueu',
                'releaseId' => 'simrs-campus-ueu-'.substr($commit, 0, 12),
                'source' => [
                    'commit' => $commit,
                    'tree' => $this->git(['rev-parse', 'HEAD^{tree}']),
                    'committedAt' => $this->git(['show', '-s', '--format=%cI', 'HEAD']),
                ],
                'integrity' => [
                    'composerLockSha256' => $this->sha256($requiredFiles['composer.lock']),
                    'npmLockSha256' => $this->sha256($requiredFiles['package-lock.json']),
                    'assetManifestSha256' => $this->sha256($requiredFiles['public/build/manifest.json']),
                ],
                'migrations' => array_map(
                    fn (string $path): array => [
                        'path' => 'database/migrations/'.basename($path),
                        'sha256' => $this->sha256($path),
                    ],
                    $migrationFiles,
                ),
                'safety' => [
                    'mode' => 'SIMULATION',
                    'syntheticOnly' => true,
                ],
                'deployment' => [
                    'status' => 'NOT_DEPLOYED',
                    'optimizeAtDeploy' => true,
                    'healthRoute' => '/up',
                    'promotionRequiresSeparateApproval' => true,
                ],
            ];
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );

            File::ensureDirectoryExists(dirname($outputPath));
            File::replace($outputPath, $json.PHP_EOL);
            $this->info('Release manifest generated for '.$manifest['releaseId'].'.');

            return self::SUCCESS;
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('The release manifest could not be generated: '.$exception::class.'.');

            return self::FAILURE;
        }
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $result = Process::path(base_path())->run(['git', ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException('Git release metadata could not be read.');
        }

        $output = trim($result->output());

        if ($output === '') {
            throw new RuntimeException('Git release metadata was empty.');
        }

        return $output;
    }

    private function resolveOutputPath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $segments = explode('/', $normalized);

        if (str_starts_with($normalized, 'public/') || in_array('..', $segments, true)) {
            throw new RuntimeException('The output must be a non-public repository-relative path.');
        }

        return base_path($normalized);
    }

    private function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException('A required release input could not be hashed.');
        }

        return $hash;
    }
}
