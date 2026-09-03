<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use Tests\TestCase;

final class WarehouseRosterMigrationSecurityTest extends TestCase
{
    public function test_direct_up_rejects_an_exact_engine_default_connection_name_spoof(): void
    {
        config(['database.warehouse_identities.migrator' => 'pinned_warehouse_migrator']);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
        DB::shouldReceive('connection')->twice()->withNoArgs()->andReturn($connection);
        DB::shouldReceive('getDefaultConnection')->once()->andReturn('release_default');

        $migration = require database_path('migrations/2026_09_03_000100_expand_warehouse_teaching_role_access_roster.php');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('named warehouse_migrator connection');
        $migration->up();
    }

    public function test_direct_up_rejects_a_spoofed_migrator_database_identity(): void
    {
        config(['database.warehouse_identities.migrator' => 'pinned_warehouse_migrator']);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->times(3)->andReturn('pgsql');
        $connection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT session_user AS authenticated_identity')
            ->andReturn((object) ['authenticated_identity' => 'spoofed_warehouse_migrator']);
        DB::shouldReceive('connection')->times(3)->withNoArgs()->andReturn($connection);
        DB::shouldReceive('getDefaultConnection')->once()->andReturn('warehouse_migrator');

        $migration = require database_path('migrations/2026_09_03_000100_expand_warehouse_teaching_role_access_roster.php');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Authenticated warehouse migrator identity does not match its pinned configuration');
        $migration->up();
    }
}
