<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('process')]
class SharedMaintenanceIndependentProcessTest extends TestCase
{
    public function test_database_marker_is_observed_across_independent_application_processes(): void
    {
        $runtimeRoot = sys_get_temp_dir().'/simrs-shared-maintenance-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($runtimeRoot, 0700));

        $database = $runtimeRoot.'/maintenance.sqlite';
        $environment = $this->isolatedEnvironment($runtimeRoot, $database);

        try {
            $this->assertProcessSucceeds(
                [PHP_BINARY, base_path('artisan'), 'migrate', '--force', '--no-interaction'],
                $environment,
            );
            $this->assertProcessSucceeds(
                [PHP_BINARY, base_path('artisan'), 'down', '--retry=60', '--no-interaction'],
                $environment,
            );

            $activeProbe = $this->runProcess($this->probeCommand(), $environment);
            $this->assertSame(0, $activeProbe['exit_code'], $activeProbe['stderr']);
            $this->assertSame(['active' => true], json_decode($activeProbe['stdout'], true));

            $this->assertProcessSucceeds(
                [PHP_BINARY, base_path('artisan'), 'up', '--no-interaction'],
                $environment,
            );

            $inactiveProbe = $this->runProcess($this->probeCommand(), $environment);
            $this->assertSame(0, $inactiveProbe['exit_code'], $inactiveProbe['stderr']);
            $this->assertSame(['active' => false], json_decode($inactiveProbe['stdout'], true));
        } finally {
            $this->removeOwnedRuntime($runtimeRoot);
        }

        $this->assertDirectoryDoesNotExist($runtimeRoot);
    }

    /** @return array<string, string> */
    private function isolatedEnvironment(string $runtimeRoot, string $database): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
            'APP_MODE' => 'SIMULATION',
            'APP_SYNTHETIC_ONLY' => 'true',
            'APP_MAINTENANCE_DRIVER' => 'cache',
            'APP_MAINTENANCE_STORE' => 'database',
            'APP_CONFIG_CACHE' => $runtimeRoot.'/config.php',
            'APP_EVENTS_CACHE' => $runtimeRoot.'/events.php',
            'APP_PACKAGES_CACHE' => $runtimeRoot.'/packages.php',
            'APP_ROUTES_CACHE' => $runtimeRoot.'/routes.php',
            'APP_SERVICES_CACHE' => $runtimeRoot.'/services.php',
            'CACHE_STORE' => 'database',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'LOG_CHANNEL' => 'stderr',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'database',
        ];
    }

    /** @return list<string> */
    private function probeCommand(): array
    {
        $probe = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['active' => $app->maintenanceMode()->active()], JSON_THROW_ON_ERROR);
PHP;

        return [PHP_BINARY, '-r', $probe, base_path()];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function assertProcessSucceeds(array $command, array $environment): void
    {
        $result = $this->runProcess($command, $environment);

        $this->assertSame(0, $result['exit_code'], $result['stderr']."\n".$result['stdout']);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path(),
            $environment,
            ['bypass_shell' => true],
        );

        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? trim($stdout) : '',
            'stderr' => is_string($stderr) ? trim($stderr) : '',
        ];
    }

    private function removeOwnedRuntime(string $runtimeRoot): void
    {
        $expectedParent = realpath(sys_get_temp_dir());
        $actualParent = realpath(dirname($runtimeRoot));

        if ($expectedParent === false
            || $actualParent !== $expectedParent
            || ! preg_match('/^simrs-shared-maintenance-[a-f0-9]{16}$/', basename($runtimeRoot))
            || is_link($runtimeRoot)
            || ! is_dir($runtimeRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($runtimeRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($runtimeRoot);
    }
}
