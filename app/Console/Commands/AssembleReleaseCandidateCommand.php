<?php

namespace App\Console\Commands;

use App\Support\ReleaseCandidateAssembler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Throwable;

class AssembleReleaseCandidateCommand extends Command
{
    /** @var list<string> */
    private const GIT_PATHS = [
        'app',
        'artisan',
        'bootstrap',
        'composer.json',
        'composer.lock',
        'config',
        'database/factories',
        'database/migrations',
        'database/seeders',
        'public',
        'resources',
        'routes',
        'storage',
    ];

    protected $signature = 'ops:assemble-release
        {manifest : Repository-relative release manifest path}
        {output : Repository-relative empty output directory}';

    protected $description = 'Assemble a runtime-only release candidate without deploying it';

    public function __construct(private readonly ReleaseCandidateAssembler $assembler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $manifestPath = $this->resolvePath((string) $this->argument('manifest'));
            $outputPath = $this->resolvePath((string) $this->argument('output'));
            $manifest = $this->readManifest($manifestPath);
            $commit = trim($this->git(['rev-parse', 'HEAD']));

            if (! hash_equals($commit, $manifest['source']['commit'])) {
                throw new RuntimeException('The release manifest commit does not match the checked-out HEAD.');
            }

            $expectedReleaseId = 'simrs-campus-ueu-'.substr($commit, 0, 12);

            if (! hash_equals($expectedReleaseId, $manifest['releaseId'])) {
                throw new RuntimeException('The release manifest identifier does not match its commit.');
            }

            $trackedFiles = array_values(array_filter(
                explode("\0", $this->git(['ls-files', '-z', '--', ...self::GIT_PATHS])),
                fn (string $path): bool => $path !== '',
            ));
            $result = $this->assembler->assemble(
                sourceRoot: base_path(),
                outputRoot: $outputPath,
                trackedRuntimeFiles: $trackedFiles,
                manifestPath: $manifestPath,
            );

            $this->info(sprintf(
                'Assembled %s with %d tracked and %d generated runtime files; deployment status remains NOT_DEPLOYED.',
                $manifest['releaseId'],
                $result['trackedFiles'],
                $result['generatedFiles'],
            ));

            return self::SUCCESS;
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('The release candidate could not be assembled: '.$exception::class.'.');

            return self::FAILURE;
        }
    }

    /**
     * @return array{
     *     schemaVersion: 1,
     *     releaseId: string,
     *     source: array{commit: string},
     *     deployment: array{status: 'NOT_DEPLOYED'}
     * }
     */
    private function readManifest(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The release manifest is missing or unreadable.');
        }

        $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($manifest)
            || ($manifest['schemaVersion'] ?? null) !== 1
            || ! is_string($manifest['releaseId'] ?? null)
            || ! is_string($manifest['source']['commit'] ?? null)
            || ($manifest['deployment']['status'] ?? null) !== 'NOT_DEPLOYED'
        ) {
            throw new RuntimeException('The release manifest does not match the required non-deployed schema.');
        }

        return [
            'schemaVersion' => 1,
            'releaseId' => $manifest['releaseId'],
            'source' => ['commit' => $manifest['source']['commit']],
            'deployment' => ['status' => 'NOT_DEPLOYED'],
        ];
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $result = Process::path(base_path())->run(['git', ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException('Git release inputs could not be read.');
        }

        return $result->output();
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
            throw new RuntimeException('Release paths must be non-public and repository-relative.');
        }

        return base_path($normalized);
    }
}
