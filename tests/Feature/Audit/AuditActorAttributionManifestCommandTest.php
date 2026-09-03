<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Support\Audit\AuditActorAttributionManifestValidator;
use App\Support\Audit\AuditActorAttributionPreflight;
use App\Support\CanonicalJson;
use App\Support\Database\SchemaQualifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditActorAttributionManifestCommandTest extends TestCase
{
    use DatabaseTruncation;

    protected bool $recreateExactEngineDatabaseBeforeApplicationBoot = true;

    private const COMMIT_SHA = '1111111111111111111111111111111111111111';

    private const TREE_SHA = '2222222222222222222222222222222222222222';

    /** @var list<string> */
    private array $createdPaths = [];

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        config([
            'app.key' => 'base64:c3ludGhldGljLW1hbmlmZXN0LXRlc3Qta2V5',
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
        ]);
        CarbonImmutable::setTestNow('2026-08-25T10:00:00Z');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }

    public function test_generate_binds_exact_root_sha_snapshot_and_writes_private_0600_without_mutation(): void
    {
        $user = User::factory()->create();
        $secret = 'password=never-include-this';
        $this->insertAudit([
            'action' => 'patient.register',
            'actor_user_id' => $user->id,
            'reason' => $secret,
            'metadata' => json_encode(['note' => $secret], JSON_THROW_ON_ERROR),
        ]);
        $this->insertAudit(['action' => 'authorization.rebuild_admin.reconciled']);
        $before = DB::table(SchemaQualifier::table('audit_events'))->orderBy('id')->get()->toArray();
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $this->fakeCleanGit();
        $filename = $this->filename('success');

        [$exit, $summary] = $this->generate($filename, $root);

        $this->assertSame(0, $exit);
        $this->assertSame('GENERATED', $summary['result']);
        $this->assertSame(2, $summary['entry_count']);
        $path = $this->manifestPath($filename);
        $this->createdPaths[] = $path;
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $bytes = file_get_contents($path);
        $this->assertIsString($bytes);
        $this->assertStringNotContainsString($secret, $bytes);
        $this->assertStringNotContainsString($user->public_id, $bytes);
        $this->assertStringNotContainsString('patient.register', $bytes);
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains(
            'USER_FROM_RESTRICTED_FK_RECOVERY_V1',
            array_column($manifest['entries'], 'derivation_rule'),
        );
        $this->assertSame($root, $manifest['preflight']['before_root']);
        $this->assertSame(self::COMMIT_SHA, $manifest['runtime_binding']['commit_sha']);
        $this->assertArrayHasKey('database_target_hmac', $manifest['database_binding']);
        $this->assertArrayHasKey('schema_fingerprint', $manifest['database_binding']);
        $this->assertEquals($before, DB::table(SchemaQualifier::table('audit_events'))->orderBy('id')->get()->toArray());

        [$verifyExit, $verified] = $this->verify($filename);
        $this->assertSame(0, $verifyExit);
        $this->assertSame('VERIFIED', $verified['result']);
        $this->assertSame($summary['manifest_digest'], $verified['manifest_digest']);
        [$wrongDigestExit, $wrongDigest] = $this->verify($filename, str_repeat('f', 64));
        $this->assertSame(1, $wrongDigestExit);
        $this->assertSame('MANIFEST_DIGEST_MISMATCH', $wrongDigest['operational_code']);
        [$missingDigestExit, $missingDigest] = $this->verify($filename, '');
        $this->assertSame(1, $missingDigestExit);
        $this->assertSame('EXPECTED_MANIFEST_DIGEST_INVALID', $missingDigest['operational_code']);
        Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === ['git', 'status', '--porcelain=v1', '--untracked-files=no'], 2);

        $manifest['database_binding']['engine_version'] = '17.6 (Debian 17.6-1.pgdg120+1)';
        app(AuditActorAttributionManifestValidator::class)->assertSchema($manifest);
    }

    public function test_exact_root_sha_and_clean_tracked_state_fail_closed_before_file_creation(): void
    {
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $filename = $this->filename('reject');

        $this->fakeCleanGit();
        [$shaExit, $sha] = $this->generate($filename, $root, str_repeat('3', 40));
        $this->assertSame(1, $shaExit);
        $this->assertSame('SOURCE_SHA_MISMATCH', $sha['operational_code']);
        $this->assertFileDoesNotExist($this->manifestPath($filename));

        $this->fakeCleanGit(dirty: true);
        [$dirtyExit, $dirty] = $this->generate($filename, $root);
        $this->assertSame(1, $dirtyExit);
        $this->assertSame('GIT_TRACKED_DIRTY', $dirty['operational_code']);

        $this->fakeCleanGit();
        [$rootExit, $mismatch] = $this->generate($filename, str_repeat('f', 64));
        $this->assertSame(1, $rootExit);
        $this->assertSame('PREFLIGHT_ROOT_MISMATCH', $mismatch['operational_code']);
    }

    public function test_ambiguous_null_teaching_reset_blocks_manifest_generation(): void
    {
        $this->insertAudit(['action' => 'teaching.reset.started']);
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $this->fakeCleanGit();

        [$exit, $summary] = $this->generate($this->filename('blocking'), $root);

        $this->assertSame(1, $exit);
        $this->assertSame('PREFLIGHT_BLOCKING', $summary['operational_code']);
    }

    public function test_manifest_is_deterministic_and_crosses_501_row_stream_boundary(): void
    {
        $rows = [];
        for ($index = 0; $index < 501; $index++) {
            $rows[] = $this->auditRow([
                'id' => (string) Str::ulid(),
                'action' => 'authorization.rebuild_admin.reconciled',
            ]);
        }
        DB::table(SchemaQualifier::table('audit_events'))->insert(array_reverse($rows));
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $this->fakeCleanGit();
        $firstName = $this->filename('first');
        [$firstExit, $firstSummary] = $this->generate($firstName, $root);
        $this->assertSame(0, $firstExit);
        $this->createdPaths[] = $firstPath = $this->manifestPath($firstName);
        $first = file_get_contents($firstPath);

        $secondName = $this->filename('second');
        [$secondExit, $secondSummary] = $this->generate($secondName, $root);
        $this->assertSame(0, $secondExit);
        $this->createdPaths[] = $secondPath = $this->manifestPath($secondName);
        $second = file_get_contents($secondPath);

        $this->assertSame(501, $firstSummary['entry_count']);
        $this->assertSame($firstSummary['manifest_digest'], $secondSummary['manifest_digest']);
        $this->assertSame($first, $second);
    }

    public function test_private_file_policy_rejects_traversal_existing_symlink_and_wrong_mode(): void
    {
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $this->fakeCleanGit();
        [$exit, $summary] = $this->generate('../escape.json', $root);
        $this->assertSame(1, $exit);
        $this->assertSame('MANIFEST_PATH_INVALID', $summary['operational_code']);

        $filename = $this->filename('existing');
        [$firstExit] = $this->generate($filename, $root);
        $this->assertSame(0, $firstExit);
        $this->createdPaths[] = $path = $this->manifestPath($filename);
        [$againExit, $again] = $this->generate($filename, $root);
        $this->assertSame(1, $againExit);
        $this->assertSame('MANIFEST_FILE_EXISTS', $again['operational_code']);

        chmod($path, 0644);
        [$modeExit, $mode] = $this->verify($filename);
        $this->assertSame(1, $modeExit);
        $this->assertSame('MANIFEST_MODE_INVALID', $mode['operational_code']);

        $linkName = $this->filename('link');
        $linkPath = $this->manifestPath($linkName);
        symlink($path, $linkPath);
        $this->createdPaths[] = $linkPath;
        [$linkExit, $link] = $this->verify($linkName);
        $this->assertSame(1, $linkExit);
        $this->assertSame('MANIFEST_FILE_UNSAFE', $link['operational_code']);

        chmod($path, 0600);
        $directory = dirname($path);
        chmod($directory, 0755);
        try {
            [$directoryExit, $directoryResult] = $this->verify($filename);
            $this->assertSame(1, $directoryExit);
            $this->assertSame('MANIFEST_DIRECTORY_UNSAFE', $directoryResult['operational_code']);
        } finally {
            chmod($directory, 0700);
        }
    }

    public function test_runtime_file_must_match_the_immutable_commit_blob(): void
    {
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $filename = $this->filename('runtime-mismatch');
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command)
                && count($process->command) === 3
                && $process->command[0] === 'git'
                && $process->command[1] === 'show'
                && str_starts_with($process->command[2], self::COMMIT_SHA.':')) {
                return Process::result("<?php\n// reviewed blob differs\n");
            }

            return match ($process->command) {
                ['git', 'status', '--porcelain=v1', '--untracked-files=no'] => Process::result(''),
                ['git', 'rev-parse', 'HEAD'] => Process::result(self::COMMIT_SHA."\n"),
                ['git', 'rev-parse', 'HEAD^{tree}'] => Process::result(self::TREE_SHA."\n"),
                default => Process::result('', '', 1),
            };
        });
        Process::preventStrayProcesses();

        [$exit, $summary] = $this->generate($filename, $root);

        $this->assertSame(1, $exit);
        $this->assertSame('GIT_TRACKED_DIRTY', $summary['operational_code']);
        $this->assertFileDoesNotExist($this->manifestPath($filename));
    }

    public function test_partial_unique_public_id_index_does_not_satisfy_the_schema_contract(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific partial-index regression coverage.');
        }
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $this->fakeCleanGit();
        DB::statement('DROP INDEX users_public_id_unique');
        DB::statement("CREATE UNIQUE INDEX users_public_id_unique ON users(public_id) WHERE status = 'ACTIVE'");
        try {
            [$exit, $summary] = $this->generate($this->filename('partial-unique'), $root);

            $this->assertSame(1, $exit);
            $this->assertSame('SCHEMA_NOT_EXPANDED', $summary['operational_code']);
        } finally {
            DB::statement('DROP INDEX users_public_id_unique');
            DB::statement('CREATE UNIQUE INDEX users_public_id_unique ON users(public_id)');
        }
    }

    public function test_offline_verifier_rejects_canonical_and_hmac_tampering(): void
    {
        $root = app(AuditActorAttributionPreflight::class)->scan()['root_digest'];
        $this->fakeCleanGit();
        $filename = $this->filename('tamper');
        [$exit] = $this->generate($filename, $root);
        $this->assertSame(0, $exit);
        $this->createdPaths[] = $path = $this->manifestPath($filename);
        $bytes = file_get_contents($path);
        $this->assertIsString($bytes);

        file_put_contents($path, $bytes."\n");
        chmod($path, 0600);
        [$canonicalExit, $canonical] = $this->verify($filename);
        $this->assertSame(1, $canonicalExit);
        $this->assertSame('MANIFEST_NOT_CANONICAL', $canonical['operational_code']);

        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $manifest['integrity']['hmac'] = str_repeat('0', 64);
        $tampered = CanonicalJson::encode($manifest);
        file_put_contents($path, $tampered);
        chmod($path, 0600);
        [$hmacExit, $hmac] = $this->verify($filename);
        $this->assertSame(1, $hmacExit);
        $this->assertSame('MANIFEST_INTEGRITY_INVALID', $hmac['operational_code']);
    }

    private function fakeCleanGit(bool $dirty = false): void
    {
        Process::fake(function (PendingProcess $process) use ($dirty) {
            if (is_array($process->command)
                && count($process->command) === 3
                && $process->command[0] === 'git'
                && $process->command[1] === 'show'
                && str_starts_with($process->command[2], self::COMMIT_SHA.':')) {
                $path = substr($process->command[2], strlen(self::COMMIT_SHA) + 1);
                $bytes = file_get_contents(base_path($path));

                return $bytes === false ? Process::result('', '', 1) : Process::result($bytes);
            }

            return match ($process->command) {
                ['git', 'status', '--porcelain=v1', '--untracked-files=no'] => Process::result($dirty ? " M app/Tracked.php\n" : ''),
                ['git', 'rev-parse', 'HEAD'] => Process::result(self::COMMIT_SHA."\n"),
                ['git', 'rev-parse', 'HEAD^{tree}'] => Process::result(self::TREE_SHA."\n"),
                default => Process::result('', '', 1),
            };
        });
        Process::preventStrayProcesses();
    }

    /** @return array{int, array<string, mixed>} */
    private function generate(string $filename, string $root, string $sha = self::COMMIT_SHA): array
    {
        $exit = Artisan::call('audit:attribution:manifest-generate', [
            'filename' => $filename,
            '--expect-root' => $root,
            '--expect-sha' => $sha,
            '--change-reference' => 'BG-02C4A-TEST',
            '--manifest-ulid' => '01J00000000000000000000000',
            '--nonce' => str_repeat('a', 32),
            '--created-at' => '2026-08-25T10:00:00Z',
            '--expires-at' => '2026-08-25T10:30:00Z',
            '--json' => true,
        ]);

        return [$exit, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
    }

    /** @return array{int, array<string, mixed>} */
    private function verify(string $filename, ?string $expectedDigest = null): array
    {
        if ($expectedDigest === null) {
            $bytes = file_get_contents($this->manifestPath($filename));
            $manifest = is_string($bytes) ? json_decode($bytes, true, flags: JSON_THROW_ON_ERROR) : null;
            $expectedDigest = (string) ($manifest['integrity']['digest'] ?? '');
        }
        $exit = Artisan::call('audit:attribution:manifest-verify', [
            'filename' => $filename,
            '--expect-digest' => $expectedDigest,
            '--json' => true,
        ]);

        return [$exit, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function filename(string $suffix): string
    {
        return 'test-'.strtolower((string) Str::ulid()).'-'.$suffix.'.json';
    }

    private function manifestPath(string $filename): string
    {
        return storage_path('app/private/audit-attribution-manifests/'.$filename);
    }

    /** @param array<string, mixed> $overrides */
    private function insertAudit(array $overrides): void
    {
        DB::table(SchemaQualifier::table('audit_events'))->insert($this->auditRow($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function auditRow(array $overrides): array
    {
        return [
            'id' => (string) Str::ulid(), 'recorded_at' => now(), 'actor_user_id' => null,
            'actor_type' => null, 'actor_reference' => null, 'action' => 'patient.register',
            'resource_type' => 'encounter', 'resource_id' => null, 'resource_version' => null,
            'outcome' => 'SUCCESS', 'reason' => null, 'request_correlation_id' => null,
            'ip_hash' => null, 'user_agent' => null, 'metadata' => null, ...$overrides,
        ];
    }
}
