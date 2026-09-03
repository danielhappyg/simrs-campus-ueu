<?php

namespace Tests\Feature\Database;

use App\Providers\AppServiceProvider;
use App\Support\Warehouse\WarehouseAppendOnlyGuard;
use App\Support\Warehouse\WarehouseMutationScope;
use App\Support\Warehouse\WarehouseSchemaMutationScope;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

final class WarehouseGuardIntegrationTest extends TestCase
{
    public function test_provider_guards_raw_mutable_head_writes_on_current_and_future_sqlite_connections(): void
    {
        $this->requireSqliteWarehouseHarness();

        $current = DB::connection();
        WarehouseSchemaMutationScope::run(
            fn () => $current->statement('CREATE TABLE warehouse_suppliers (id INTEGER PRIMARY KEY, display_name TEXT NOT NULL)'),
        );

        foreach ([
            'DROP TABLE warehouse_suppliers',
            'ALTER TABLE warehouse_suppliers ADD COLUMN bypass TEXT',
            "CREATE TRIGGER bypass BEFORE UPDATE ON warehouse_suppliers BEGIN SELECT RAISE(ABORT, 'bypass'); END",
        ] as $ddl) {
            try {
                WarehouseMutationScope::run(fn () => $current->statement($ddl));
                $this->fail('Mutation scope must not authorize warehouse schema DDL.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue(Schema::hasTable('warehouse_suppliers'));
        $this->assertFalse(Schema::hasColumn('warehouse_suppliers', 'bypass'));

        $this->expectLogicException(
            fn () => $current->insert("INSERT INTO warehouse_suppliers (id, display_name) VALUES (1, 'outside')"),
        );
        WarehouseMutationScope::run(
            fn () => $current->insert("INSERT INTO warehouse_suppliers (id, display_name) VALUES (1, 'governed')"),
        );
        try {
            WarehouseSchemaMutationScope::run(
                fn () => $current->update("UPDATE warehouse_suppliers SET display_name = 'schema' WHERE id = 1"),
            );
            $this->fail('Warehouse schema scope must not authorize protected-table DML.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('permits only schema DDL', $exception->getMessage());
        }
        WarehouseMutationScope::run(
            fn () => $current->update("UPDATE warehouse_suppliers SET display_name = 'mutated' WHERE id = 1"),
        );
        $this->assertSame('mutated', $current->table('warehouse_suppliers')->value('display_name'));

        config()->set('database.connections.warehouse_guard_future', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $future = DB::connection('warehouse_guard_future');
        WarehouseSchemaMutationScope::run(
            fn () => $future->statement('CREATE TABLE warehouse_suppliers (id INTEGER PRIMARY KEY, display_name TEXT NOT NULL)'),
        );

        $this->expectLogicException(
            fn () => $future->insert("INSERT INTO warehouse_suppliers (id, display_name) VALUES (1, 'outside')"),
        );
        WarehouseMutationScope::run(
            fn () => $future->insert("INSERT INTO warehouse_suppliers (id, display_name) VALUES (1, 'governed')"),
        );
        $this->assertSame('governed', $future->table('warehouse_suppliers')->value('display_name'));
    }

    public function test_sqlite_reset_requires_a_transaction_and_restores_append_only_triggers_atomically(): void
    {
        $this->requireSqliteWarehouseHarness();

        WarehouseSchemaMutationScope::run(function (): void {
            DB::statement('CREATE TABLE warehouse_supplier_versions (id INTEGER PRIMARY KEY, display_name TEXT NOT NULL)');
            WarehouseAppendOnlyGuard::install();
        });
        WarehouseMutationScope::run(
            fn () => DB::insert("INSERT INTO warehouse_supplier_versions (id, display_name) VALUES (1, 'reset target')"),
        );

        $callbackCalled = false;
        try {
            WarehouseAppendOnlyGuard::runSyntheticReset(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            $this->fail('Warehouse reset must fail closed outside a transaction.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('requires an active transaction', $exception->getMessage());
        }
        $this->assertFalse($callbackCalled);

        DB::transaction(fn () => WarehouseAppendOnlyGuard::runSyntheticReset(
            fn () => WarehouseMutationScope::run(
                fn () => DB::delete('DELETE FROM warehouse_supplier_versions WHERE id = 1'),
            ),
        ));
        $this->assertSame(0, DB::table('warehouse_supplier_versions')->count());

        WarehouseMutationScope::run(
            fn () => DB::insert("INSERT INTO warehouse_supplier_versions (id, display_name) VALUES (2, 'immutable')"),
        );
        try {
            DB::connection()->getPdo()->exec("UPDATE warehouse_supplier_versions SET display_name = 'tampered' WHERE id = 2");
            $this->fail('Append-only trigger must be restored before the reset transaction commits.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('warehouse append-only evidence is immutable', $exception->getMessage());
        }
    }

    public function test_provider_registers_the_guard_on_connections_that_were_already_open(): void
    {
        Event::forget(ConnectionEstablished::class);
        config()->set('database.connections.warehouse_guard_preopened', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $preopened = DB::connection('warehouse_guard_preopened');
        $preopened->getPdo();
        $preopened->statement('CREATE TABLE warehouse_suppliers (id INTEGER PRIMARY KEY, display_name TEXT NOT NULL)');

        $provider = new AppServiceProvider(app());
        $register = new ReflectionMethod($provider, 'configureWarehouseSqlWriteGuard');
        $register->setAccessible(true);
        $register->invoke($provider);

        $this->expectLogicException(
            fn () => $preopened->insert("INSERT INTO warehouse_suppliers (id, display_name) VALUES (1, 'outside')"),
        );
    }

    public function test_exact_engine_trigger_contract_uses_split_database_identities_without_session_variable_authority(): void
    {
        $appendOnly = file_get_contents(app_path('Support/Warehouse/WarehouseAppendOnlyGuard.php'));
        $mutableHead = file_get_contents(app_path('Support/Warehouse/WarehouseMutableHeadGuard.php'));
        $mutationScope = file_get_contents(app_path('Support/Warehouse/WarehouseMutationScope.php'));
        $this->assertIsString($appendOnly);
        $this->assertIsString($mutableHead);
        $this->assertIsString($mutationScope);

        foreach ([$appendOnly, $mutableHead, $mutationScope] as $source) {
            $this->assertStringNotContainsString("current_setting('simrs", $source);
            $this->assertStringNotContainsString('@simrs_', $source);
            $this->assertStringNotContainsString('$installerIdentity', $source);
        }
        $this->assertStringContainsString("TG_OP = 'DELETE' AND current_user = {\$resetOwner} AND session_user = {\$resetExecutor}", $appendOnly);
        $this->assertStringContainsString('current_user = {$writer} AND session_user = {$writer}', $mutableHead);
        $this->assertStringContainsString("TG_OP = 'DELETE' AND current_user = {\$resetOwner} AND session_user = {\$resetExecutor}", $mutableHead);
        $this->assertStringContainsString("FOR EACH ROW SIGNAL SQLSTATE '45000'", $appendOnly);
        $this->assertStringContainsString('ENABLE ALWAYS TRIGGER', $appendOnly);
        $this->assertStringContainsString('ENABLE ALWAYS TRIGGER', $mutableHead);
        $this->assertStringContainsString('generic callback reset is prohibited', $appendOnly);
        $this->assertStringContainsString('DEFAULT_WRITER_CONNECTION', $mutationScope);
        $this->assertStringContainsString('$writer = DB::connection($writerName)', $mutationScope);
        $this->assertStringContainsString('return $callback($writer)', $mutationScope);
        $this->assertStringNotContainsString('setDefaultConnection', $mutationScope);
        $this->assertStringContainsString('transactionLevel() < 1', $mutationScope);
        $this->assertStringContainsString('inet_server_addr()', $mutationScope);
        $this->assertStringContainsString('@@server_uuid', $mutationScope);
    }

    public function test_exact_engine_authority_contract_rejects_any_shared_identity(): void
    {
        $identities = [
            'migrator' => 'warehouse_migrator',
            'runtime' => 'warehouse_runtime',
            'writer' => 'warehouse_writer',
            'reset_executor' => 'warehouse_reset_executor',
            'reset_owner' => 'warehouse_reset_owner',
        ];
        WarehouseMutationScope::assertDistinctDatabaseIdentities($identities);
        $this->addToAssertionCount(1);

        foreach (array_keys($identities) as $purpose) {
            $shared = $identities;
            $shared[$purpose] = $identities[$purpose === 'migrator' ? 'runtime' : 'migrator'];
            try {
                WarehouseMutationScope::assertDistinctDatabaseIdentities($shared);
                $this->fail("Expected shared {$purpose} database identity to be rejected.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('pairwise distinct', $exception->getMessage());
            }
        }
    }

    public function test_exact_engine_privilege_contract_is_least_privilege_and_reset_is_execute_only(): void
    {
        $contract = WarehouseMutationScope::requiredPrivilegeContract();

        $this->assertSame(['SELECT'], $contract['runtime_allow']);
        $this->assertContains('DELETE', $contract['writer_mutable_heads_allow']);
        $this->assertContains('DELETE_APPEND_ONLY', $contract['writer_deny']);
        $this->assertContains('DROP', $contract['writer_deny']);
        $this->assertSame(['EXECUTE_BOUNDED_SECURITY_DEFINER_RESET'], $contract['reset_executor_allow']);
        $this->assertContains('TABLE_DELETE', $contract['reset_executor_deny']);
        $this->assertContains('LOGIN', $contract['reset_owner_deny']);
        $this->assertContains('RUNTIME_CREDENTIAL_AVAILABILITY', $contract['migrator_deny']);
        $this->assertContains('warehouse_custody_movement_pairs', WarehouseAppendOnlyGuard::TABLES);
    }

    public function test_exact_engine_authorities_must_resolve_to_the_same_server_target(): void
    {
        WarehouseMutationScope::assertSameDatabaseTargetFingerprints([
            'runtime' => 'server-a/database-a',
            'writer' => 'server-a/database-a',
            'migrator' => 'server-a/database-a',
            'reset_executor' => 'server-a/database-a',
        ]);
        $this->addToAssertionCount(1);

        try {
            WarehouseMutationScope::assertSameDatabaseTargetFingerprints([
                'runtime' => 'server-a/database-a',
                'writer' => 'server-b/database-a',
            ]);
            $this->fail('Expected a cross-server writer target to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('same database server target', $exception->getMessage());
        }
    }

    public function test_exact_engine_named_connections_and_identity_pins_are_wired_without_defaults(): void
    {
        $this->assertSame([
            'runtime' => 'warehouse_runtime',
            'writer' => 'warehouse_writer',
            'migrator' => 'warehouse_migrator',
            'reset_executor' => 'warehouse_reset_executor',
        ], config('database.warehouse_connections'));
        foreach (['runtime', 'writer', 'migrator', 'reset_executor', 'reset_owner'] as $purpose) {
            $this->assertArrayHasKey($purpose, config('database.warehouse_identities'));
        }

        $environment = file_get_contents(base_path('.env.example'));
        $this->assertIsString($environment);
        foreach (['WAREHOUSE_RUNTIME_DB_IDENTITY', 'WAREHOUSE_WRITER_DB_IDENTITY', 'WAREHOUSE_MIGRATOR_DB_IDENTITY', 'WAREHOUSE_RESET_EXECUTOR_DB_IDENTITY', 'WAREHOUSE_RESET_OWNER_DB_IDENTITY'] as $key) {
            $this->assertStringContainsString("# {$key}=", $environment);
        }
    }

    private function expectLogicException(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected provider-registered warehouse SQL guard refusal.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Write-capable SQL against warehouse custody tables is prohibited.', $exception->getMessage());
        }
    }

    private function requireSqliteWarehouseHarness(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                'SQLite warehouse guard integration is replaced by the future governed exact-engine identity/routine harness.',
            );
        }
    }
}
