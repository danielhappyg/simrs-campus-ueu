<?php

namespace Tests\Feature\Operations;

use App\Support\Operations\SyntheticRecoverySnapshot;
use Database\Seeders\RecoveryRehearsalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class VerifySyntheticRecoverySnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_recovery_snapshot_command_is_registered_without_target_arguments(): void
    {
        $exitCode = Artisan::call('list', ['--raw' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('ops:verify-synthetic-recovery', Artisan::output());

        $definition = Artisan::all()['ops:verify-synthetic-recovery']->getDefinition();
        $this->assertSame([], array_keys($definition->getArguments()));
        $this->assertFalse($definition->hasOption('database'));
        $this->assertFalse($definition->hasOption('root'));
        $this->assertFalse($definition->hasOption('host'));
    }

    public function test_recovery_snapshot_fails_closed_on_the_normal_test_database_without_mutation(): void
    {
        $before = [
            'users' => DB::table('users')->count(),
            'patients' => DB::table('patients')->count(),
            'audit_events' => DB::table('audit_events')->count(),
        ];

        $exitCode = Artisan::call('ops:verify-synthetic-recovery', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);
        $expectedBoundary = DB::connection()->getDriverName() === 'pgsql'
            ? 'refuses a database outside the generated disposable namespace'
            : 'requires a PostgreSQL connection';
        $this->assertStringContainsString($expectedBoundary, $output);
        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['patients'], DB::table('patients')->count());
        $this->assertSame($before['audit_events'], DB::table('audit_events')->count());
    }

    public function test_recovery_rehearsal_seeder_refuses_sqlite_before_writing_a_sentinel(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires the explicit disposable-local confirmation');

        (new RecoveryRehearsalSeeder)->run();
    }

    public function test_recovery_rehearsal_confirmation_does_not_bypass_database_engine_or_namespace_guard(): void
    {
        $original = getenv('SIMRS_RECOVERY_REHEARSAL_CONFIRM');
        putenv('SIMRS_RECOVERY_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_POSTGRES17');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                DB::connection()->getDriverName() === 'pgsql'
                    ? 'refuses a database outside the generated disposable namespace'
                    : 'requires PostgreSQL',
            );

            (new RecoveryRehearsalSeeder)->run();
        } finally {
            $original === false
                ? putenv('SIMRS_RECOVERY_REHEARSAL_CONFIRM')
                : putenv('SIMRS_RECOVERY_REHEARSAL_CONFIRM='.$original);
        }
    }

    public function test_recovery_ledger_accepts_the_governed_warehouse_migration_applied_or_absent(): void
    {
        $expected = [
            ['migration' => '2026_09_03_000100_expand_warehouse_teaching_role_access_roster', 'sha256' => str_repeat('a', 64)],
            ['migration' => '2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables', 'sha256' => str_repeat('b', 64)],
        ];
        $applied = [
            ['migration' => $expected[0]['migration'], 'batch' => 1],
            ['migration' => $expected[1]['migration'], 'batch' => 2],
        ];
        $absent = [
            ['migration' => $expected[0]['migration'], 'batch' => 1],
        ];

        $this->invokeMigrationLedgerAssertion($applied, $expected);
        $this->invokeMigrationLedgerAssertion($absent, $expected);

        $this->addToAssertionCount(2);
    }

    public function test_recovery_ledger_rejects_every_other_missing_extra_duplicate_or_reordered_migration(): void
    {
        $expected = [
            ['migration' => '2026_09_03_000100_expand_warehouse_teaching_role_access_roster', 'sha256' => str_repeat('a', 64)],
            ['migration' => '2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables', 'sha256' => str_repeat('b', 64)],
        ];
        $driftCases = [
            [],
            [['migration' => '2026_09_03_000100_unexpected', 'batch' => 1]],
            [
                ['migration' => $expected[0]['migration'], 'batch' => 1],
                ['migration' => $expected[0]['migration'], 'batch' => 2],
            ],
            [
                ['migration' => $expected[1]['migration'], 'batch' => 1],
                ['migration' => $expected[0]['migration'], 'batch' => 2],
            ],
        ];

        foreach ($driftCases as $migrationRows) {
            try {
                $this->invokeMigrationLedgerAssertion($migrationRows, $expected);
                $this->fail('Only the governed warehouse migration may be absent from the recovery ledger.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('migration ledger does not match this checkout', $exception->getMessage());
            }
        }
    }

    /**
     * @param  list<array{migration: string, batch: int}>  $migrationRows
     * @param  list<array{migration: string, sha256: string}>  $expectedMigrations
     */
    private function invokeMigrationLedgerAssertion(array $migrationRows, array $expectedMigrations): void
    {
        $method = new ReflectionMethod(SyntheticRecoverySnapshot::class, 'assertMigrationLedgerMatchesCheckout');
        $method->invoke(new SyntheticRecoverySnapshot, $migrationRows, $expectedMigrations);
    }
}
