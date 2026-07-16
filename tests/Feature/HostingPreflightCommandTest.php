<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HostingPreflightCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/hosting-preflight-public'));

        parent::tearDown();
    }

    public function test_read_only_hosting_preflight_command_is_registered(): void
    {
        $exitCode = Artisan::call('list', ['--raw' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('ops:hosting-preflight', Artisan::output());
    }

    public function test_preflight_fails_closed_for_unsafe_hosted_configuration(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.debug' => true,
            'app.url' => 'http://simrs-staging.example.invalid',
            'app.key' => null,
            'simulation.mode' => 'CLINICAL',
            'simulation.synthetic_only' => false,
            'session.secure' => false,
            'session.encrypt' => false,
            'database.default' => 'sqlite',
            'queue.default' => 'sync',
        ]);

        $exitCode = Artisan::call('ops:hosting-preflight', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);

        foreach ([
            'app.debug_disabled',
            'app.https_url',
            'app.key_present',
            'simulation.mode',
            'simulation.synthetic_only',
            'session.secure_cookie',
            'session.encrypted',
            'database.mysql',
            'queue.database',
        ] as $checkId) {
            $this->assertStringContainsString($checkId, $output);
        }
    }

    public function test_preflight_remains_incomplete_without_account_evidence(): void
    {
        $this->configureSafeHostedRuntime();

        $exitCode = Artisan::call('ops:hosting-preflight', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"INCOMPLETE"', $output);

        foreach ([
            'runtime.php_version',
            'runtime.php_extensions',
            'database.connectivity',
            'storage.private_root',
            'storage.writable',
            'health.route',
            'public.build_manifest',
            'account.plan_version',
            'ssh.access_restrictions',
            'cron.scheduler',
            'queue.execution',
            'backup.retention_restore',
            'release.atomic_switch',
            'tls.dns_staging',
        ] as $checkId) {
            $this->assertStringContainsString($checkId, $output);
        }
    }

    public function test_preflight_is_ready_only_when_every_manual_check_has_sanitized_pass_evidence(): void
    {
        $this->configureSafeHostedRuntime();
        $path = $this->writeEvidenceFile();

        try {
            $exitCode = Artisan::call('ops:hosting-preflight', [
                '--json' => true,
                '--evidence' => $path,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('"status":"READY"', $output);
            $this->assertStringContainsString('"manualPending":0', $output);
            $this->assertStringNotContainsString('DO-NOT-ECHO', $output);
        } finally {
            File::delete($path);
        }
    }

    public function test_preflight_blocks_a_manual_failure_without_echoing_evidence(): void
    {
        $this->configureSafeHostedRuntime();
        $path = $this->writeEvidenceFile([
            'backup.retention_restore' => 'FAIL',
        ]);

        try {
            $exitCode = Artisan::call('ops:hosting-preflight', [
                '--json' => true,
                '--evidence' => $path,
            ]);
            $output = Artisan::output();

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('"status":"BLOCKED"', $output);
            $this->assertStringContainsString('backup.retention_restore', $output);
            $this->assertStringNotContainsString('DO-NOT-ECHO', $output);
        } finally {
            File::delete($path);
        }
    }

    public function test_preflight_blocks_malformed_evidence_without_echoing_its_contents(): void
    {
        $this->configureSafeHostedRuntime();
        $path = storage_path('framework/testing/hostinger-staging-invalid-evidence.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '{"secret":"DO-NOT-ECHO"');

        try {
            $exitCode = Artisan::call('ops:hosting-preflight', [
                '--json' => true,
                '--evidence' => $path,
            ]);
            $output = Artisan::output();

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('"status":"BLOCKED"', $output);
            $this->assertStringContainsString('evidence.file', $output);
            $this->assertStringNotContainsString('DO-NOT-ECHO', $output);
        } finally {
            File::delete($path);
        }
    }

    public function test_tracked_evidence_example_matches_the_command_contract_and_remains_pending(): void
    {
        $this->configureSafeHostedRuntime();

        $exitCode = Artisan::call('ops:hosting-preflight', [
            '--json' => true,
            '--evidence' => base_path('docs/operations/hostinger-staging-evidence.example.json'),
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"INCOMPLETE"', $output);
        $this->assertStringContainsString('"manualPending":14', $output);
    }

    public function test_preflight_blocks_stale_conclusive_evidence(): void
    {
        $this->configureSafeHostedRuntime();
        $path = $this->writeEvidenceFile(
            checkedAt: now()->subDays(31)->toAtomString(),
        );

        try {
            $exitCode = Artisan::call('ops:hosting-preflight', [
                '--json' => true,
                '--evidence' => $path,
            ]);
            $output = Artisan::output();

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('"status":"BLOCKED"', $output);
            $this->assertStringContainsString('evidence.file', $output);
        } finally {
            File::delete($path);
        }
    }

    private function configureSafeHostedRuntime(): void
    {
        $sqlite = config('database.connections.sqlite');
        $sqlite['url'] = null;
        $sqlite['database'] = ':memory:';
        $publicPath = storage_path('framework/testing/hosting-preflight-public');
        File::ensureDirectoryExists($publicPath.'/build');
        File::put($publicPath.'/build/manifest.json', '{}');
        app()->usePublicPath($publicPath);

        config()->set([
            'app.env' => 'staging',
            'app.debug' => false,
            'app.url' => 'https://simrs-staging.example.invalid',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'session.secure' => true,
            'session.encrypt' => true,
            'database.default' => 'mysql',
            'database.connections.mysql' => $sqlite,
            'queue.default' => 'database',
            'filesystems.default' => 'local',
        ]);

        DB::purge('mysql');
    }

    /** @return list<string> */
    private function manualCheckIds(): array
    {
        return [
            'account.plan_version',
            'ssh.access_restrictions',
            'build.composer_strategy',
            'build.node_strategy',
            'cron.scheduler',
            'queue.execution',
            'storage.private_isolation',
            'backup.retention_restore',
            'release.atomic_switch',
            'tls.dns_staging',
            'logs.retention',
            'resources.limits',
            'github.environment_gate',
            'ssh.host_key_pin',
        ];
    }

    /**
     * @param  array<string, 'PASS'|'FAIL'|'PENDING'>  $statuses
     */
    private function writeEvidenceFile(array $statuses = [], ?string $checkedAt = null): string
    {
        $path = storage_path('framework/testing/hostinger-staging-evidence.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'schemaVersion' => 1,
            'target' => 'hostinger-staging',
            'checkedAt' => $checkedAt ?? now()->toAtomString(),
            'checks' => collect($this->manualCheckIds())
                ->mapWithKeys(fn (string $id): array => [
                    $id => [
                        'status' => $statuses[$id] ?? 'PASS',
                        'evidence' => 'Verified in a sanitized record; DO-NOT-ECHO.',
                    ],
                ])
                ->all(),
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
