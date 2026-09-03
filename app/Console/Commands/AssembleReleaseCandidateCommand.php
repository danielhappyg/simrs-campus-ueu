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
            $this->assertCleanTrackedSource();
            $commit = trim($this->git(['rev-parse', 'HEAD']));

            if (! hash_equals($commit, $manifest['source']['commit'])) {
                throw new RuntimeException('The release manifest commit does not match the checked-out HEAD.');
            }

            $expectedReleaseId = 'simrs-campus-ueu-'.substr($commit, 0, 12);

            if (! hash_equals($expectedReleaseId, $manifest['releaseId'])) {
                throw new RuntimeException('The release manifest identifier does not match its commit.');
            }

            $result = $this->assembler->assemble(
                sourceRoot: base_path(),
                outputRoot: $outputPath,
                runtimeFiles: $manifest['integrity']['runtimeFiles'],
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
     *     schemaVersion: 2,
     *     releaseId: string,
     *     source: array{commit: string},
     *     integrity: array{runtimeFilesSha256: string, runtimeFiles: list<array{path: string, sha256: string, mode: int, source: 'tracked'|'generated'}>},
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
            || ($manifest['schemaVersion'] ?? null) !== 2
            || ! is_string($manifest['releaseId'] ?? null)
            || ! is_string($manifest['source']['commit'] ?? null)
            || ! is_array($manifest['integrity']['runtimeFiles'] ?? null)
            || ! array_is_list($manifest['integrity']['runtimeFiles'])
            || ! is_string($manifest['integrity']['runtimeFilesSha256'] ?? null)
            || ($manifest['deployment']['status'] ?? null) !== 'NOT_DEPLOYED'
        ) {
            throw new RuntimeException('The release manifest does not match the required non-deployed schema.');
        }

        $runtimeFiles = [];
        $paths = [];

        foreach ($manifest['integrity']['runtimeFiles'] as $runtimeFile) {
            if (! is_array($runtimeFile)
                || ! is_string($runtimeFile['path'] ?? null)
                || ! is_string($runtimeFile['sha256'] ?? null)
                || ! is_int($runtimeFile['mode'] ?? null)
                || ! in_array($runtimeFile['source'] ?? null, ['tracked', 'generated'], true)
                || ! ReleaseCandidateAssembler::isAllowedRuntimePath($runtimeFile['path'])
                || ! ReleaseCandidateAssembler::isExpectedRuntimeSource($runtimeFile['path'], $runtimeFile['source'])
                || preg_match('/\A[a-f0-9]{64}\z/', $runtimeFile['sha256']) !== 1
                || $runtimeFile['mode'] < 0
                || $runtimeFile['mode'] > 0777
                || isset($paths[$runtimeFile['path']])
            ) {
                throw new RuntimeException('The release manifest runtime file set is invalid.');
            }

            $paths[$runtimeFile['path']] = true;
            $runtimeFiles[] = [
                'path' => $runtimeFile['path'],
                'sha256' => $runtimeFile['sha256'],
                'mode' => $runtimeFile['mode'],
                'source' => $runtimeFile['source'],
            ];
        }

        if ($runtimeFiles === []
            || ! hash_equals($manifest['integrity']['runtimeFilesSha256'], ReleaseCandidateAssembler::runtimeFilesDigest($runtimeFiles))
        ) {
            throw new RuntimeException('The release manifest runtime file-set digest is invalid.');
        }

        return [
            'schemaVersion' => 2,
            'releaseId' => $manifest['releaseId'],
            'source' => ['commit' => $manifest['source']['commit']],
            'integrity' => [
                'runtimeFilesSha256' => $manifest['integrity']['runtimeFilesSha256'],
                'runtimeFiles' => $runtimeFiles,
            ],
            'deployment' => ['status' => 'NOT_DEPLOYED'],
        ];
    }

    private function assertCleanTrackedSource(): void
    {
        if (trim($this->git([
            'status',
            '--porcelain=v1',
            '--untracked-files=no',
            '--',
            '.',
            ':(exclude)public/build',
        ])) !== '') {
            throw new RuntimeException('Tracked source must be clean before assembling a release candidate.');
        }
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
