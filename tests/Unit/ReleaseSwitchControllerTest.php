<?php

namespace Tests\Unit;

use App\Support\ReleaseSwitchController;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ReleaseSwitchControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/release-switch-controller');
        File::deleteDirectory($this->root);
        $this->putRelease('previous');
        $this->putRelease('candidate');
        symlink('releases/previous', $this->root.'/current');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_failed_health_probe_cannot_change_the_current_release(): void
    {
        try {
            app(ReleaseSwitchController::class)->switchTo(
                root: $this->root,
                releaseId: 'candidate',
                healthProbe: fn (string $path): bool => false,
            );
            $this->fail('The failed health probe should block promotion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Health', $exception->getMessage());
        }

        $this->assertSame('releases/previous', readlink($this->root.'/current'));
    }

    public function test_a_healthy_release_can_be_promoted_and_then_rolled_back(): void
    {
        $controller = app(ReleaseSwitchController::class);
        $promotion = $controller->switchTo(
            root: $this->root,
            releaseId: 'candidate',
            healthProbe: fn (string $path): bool => is_file($path.'/release-manifest.json'),
        );

        $this->assertSame('previous', $promotion['from']);
        $this->assertSame('candidate', $promotion['to']);
        $this->assertSame('releases/candidate', readlink($this->root.'/current'));

        $rollback = $controller->switchTo(
            root: $this->root,
            releaseId: 'previous',
            healthProbe: fn (string $path): bool => is_file($path.'/release-manifest.json'),
        );

        $this->assertSame('candidate', $rollback['from']);
        $this->assertSame('previous', $rollback['to']);
        $this->assertSame('releases/previous', readlink($this->root.'/current'));
    }

    public function test_it_refuses_a_target_whose_manifest_does_not_match_the_release_directory(): void
    {
        File::put($this->root.'/releases/candidate/release-manifest.json', json_encode([
            'releaseId' => 'another-release',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest');

        app(ReleaseSwitchController::class)->switchTo(
            root: $this->root,
            releaseId: 'candidate',
            healthProbe: fn (string $path): bool => true,
        );
    }

    private function putRelease(string $releaseId): void
    {
        $path = $this->root.'/releases/'.$releaseId;
        File::ensureDirectoryExists($path);
        File::put($path.'/release-manifest.json', json_encode([
            'releaseId' => $releaseId,
        ], JSON_THROW_ON_ERROR));
    }
}
