<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('process')]
class TrustedHostStagingProcessTest extends TestCase
{
    public function test_real_staging_bootstrap_accepts_canonical_host_and_rejects_unassigned_host(): void
    {
        $environment = [
            'APP_ENV' => 'staging',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://canonical.example',
            'APP_MODE' => 'SIMULATION',
            'APP_SYNTHETIC_ONLY' => 'true',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'LOG_CHANNEL' => 'stderr',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];

        $canonical = $this->probeHost('canonical.example', $environment);
        $this->assertSame(0, $canonical['exit_code'], $canonical['stderr']);
        $this->assertSame(['status' => 200], json_decode($canonical['stdout'], true));

        $unassigned = $this->probeHost('attacker.invalid', $environment);
        $this->assertSame(0, $unassigned['exit_code'], $unassigned['stderr']);
        $this->assertSame(['status' => 400], json_decode($unassigned['stdout'], true));
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function probeHost(string $host, array $environment): array
    {
        $probe = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('http://'.$argv[2].'/up', 'GET');
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $probe, base_path(), $host],
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
}
